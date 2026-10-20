<?php

/**
 * TokenRepository: anti-abuse upload tokens and single-use, IP-bound download tokens.
 */
final class TokenRepositoryTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('upload_tokens', 'download_tokens');
	}

	public function testUploadTokenIsIpBound(): void
	{
		$token = Database::createUploadToken('192.0.2.1', null);
		$this->assertNotEmpty($token);
		$this->assertTrue(Database::verifyUploadToken($token, '192.0.2.1'));
		$this->assertFalse(Database::verifyUploadToken($token, '192.0.2.99')); // wrong IP
		$this->assertFalse(Database::verifyUploadToken('bogus', '192.0.2.1'));
	}

	/**
	 * The upload token is what files are attributed by, so it follows whoever signs in in the
	 * tab: an anonymous one is claimed, another account's one is replaced (keeping its upload
	 * session), and signing out removes the token of the account that left.
	 */
	public function testUploadTokenFollowsTheAccountSignedInInTheTab(): void
	{
		$ids = [];
		foreach (['tokenowner', 'tokennext'] as $name) {
			$suffix = bin2hex(random_bytes(3));
			$this->assertTrue(Database::registerUser($name . $suffix, $name . $suffix . '@example.test', 'Str0ng!pass')['success']);
			$ids[] = (int) Database::getUserByEmailOrUsername($name . $suffix)['id'];
		}
		[$first, $next] = $ids;
		$owner = static function (string $token): ?int {
			$stmt = Database::getInstance()->prepare(
				"SELECT `user_id` FROM `" . Database::table('upload_tokens') . "` WHERE `token` = ?"
			);
			$stmt->execute([$token]);
			$value = $stmt->fetchColumn();
			return $value === false || $value === null ? null : (int) $value;
		};

		// An anonymous token is simply claimed: the page keeps it.
		$guest = Database::createUploadToken('192.0.2.7', null);
		$this->assertNull(TokenRepository::handOverOnLogin($guest, $first, '192.0.2.7'));
		$this->assertSame($first, $owner($guest));
		$this->assertNull(TokenRepository::handOverOnLogin($guest, $first, '192.0.2.7'), 'already this account\'s');

		// Someone else signs in in the same tab: a new token for them, same upload session.
		Database::getInstance()->exec(
			"UPDATE `" . Database::table('upload_tokens') . "` SET `files_count` = 3 WHERE `token` = '" . $guest . "'"
		);
		$handed = TokenRepository::handOverOnLogin($guest, $next, '192.0.2.7');
		$this->assertIsArray($handed);
		$this->assertNotSame($guest, $handed['token']);
		$this->assertSame($next, $owner($handed['token']));
		$this->assertSame($first, $owner($guest), 'the previous account keeps its own token');
		$this->assertSame(3, (int) Database::getTokenInfo($handed['token'], '192.0.2.7')['files_uploaded']);
		$this->assertGreaterThan(time(), $handed['expires_at']);

		// Not from this address, or unknown: left alone for the page to replace the usual way.
		$this->assertNull(TokenRepository::handOverOnLogin($guest, $next, '192.0.2.99'));
		$this->assertNull(TokenRepository::handOverOnLogin('bogus', $next, '192.0.2.7'));

		// Signing out removes only the leaving account's token.
		$this->assertFalse(TokenRepository::forgetForUser($handed['token'], $first));
		$this->assertTrue(TokenRepository::forgetForUser($handed['token'], $next));
		$this->assertNull($owner($handed['token']));
		$this->assertSame($first, $owner($guest));
	}

	public function testTokenInfoReflectsSession(): void
	{
		$token = Database::createUploadToken('192.0.2.5', null);
		$info = Database::getTokenInfo($token, '192.0.2.5');
		$this->assertIsArray($info);
		$this->assertArrayHasKey('files_remaining', $info);
	}

	/**
	 * A download token is issued here and consumed by the upload server's /download endpoint
	 * (single use, same address — covered by the Python tests), so what matters on this side is
	 * the row it finds: unused, for this file and this exact address, fresh on every request.
	 */
	public function testDownloadTokenIsIssuedUnusedForOneFileAndAddress(): void
	{
		$this->insertFile('tok_file_1');
		$token = (string) Database::createDownloadToken('tok_file_1', '198.51.100.7', null);
		$this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);
		$stmt = Database::getInstance()->prepare(
			"SELECT `file_id`, `ip_address`, `used` FROM `" . Database::table('download_tokens') . "` WHERE `token` = ?"
		);
		$stmt->execute([$token]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('tok_file_1', $row['file_id']);
		$this->assertSame('198.51.100.7', $row['ip_address']);
		$this->assertSame(0, (int) $row['used']);
		$this->assertNotSame($token, Database::createDownloadToken('tok_file_1', '198.51.100.7', null));
	}
}
