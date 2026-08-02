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

	public function testTokenInfoReflectsSession(): void
	{
		$token = Database::createUploadToken('192.0.2.5', null);
		$info = Database::getTokenInfo($token, '192.0.2.5');
		$this->assertIsArray($info);
		$this->assertArrayHasKey('files_remaining', $info);
	}

	public function testDownloadTokenIsSingleUseAndIpBound(): void
	{
		$this->insertFile('tok_file_1');
		$token = Database::createDownloadToken('tok_file_1', '198.51.100.7', null);
		$this->assertNotEmpty($token);

		// Wrong IP is refused and does NOT consume the token.
		$this->assertNull(Database::verifyUseDownloadToken($token, '198.51.100.8'));

		// Correct IP resolves to the file id, then the token is burned.
		$this->assertSame('tok_file_1', Database::verifyUseDownloadToken($token, '198.51.100.7'));
		$this->assertNull(Database::verifyUseDownloadToken($token, '198.51.100.7')); // reuse refused
	}
}
