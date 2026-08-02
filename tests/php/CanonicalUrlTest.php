<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/includes/CanonicalUrl.php';

final class CanonicalUrlTest extends TestCase
{
	public function testCanonicalUrlIsNormalizedDeterministically(): void
	{
		$this->assertSame(
			'https://example.com/files',
			filehostNormalizeCanonicalUrl(' HTTPS://Example.COM:443/files/ ')
		);
		$this->assertSame(
			'http://[2001:db8::1]:8080/base',
			filehostNormalizeCanonicalUrl('http://[2001:DB8::1]:8080/base')
		);
	}

	public function testUnsafeOrAmbiguousCanonicalUrlIsRejected(): void
	{
		$invalid = [
			'empty' => '',
			'unsupported scheme' => 'ftp://example.com',
			'credentials' => 'https://user@example.com',
			'query' => 'https://example.com/?next=evil',
			'fragment' => 'https://example.com/#fragment',
			'traversal' => 'https://example.com/base/../admin',
			'encoded slash' => 'https://example.com/base%2fadmin',
			'backslash' => 'https://example.com\\@evil.test/',
			'html attribute delimiter' => "https://example.com/path'quoted",
			'invalid hostname' => 'https://bad_host.example/',
		];
		foreach ($invalid as $case => $url) {
			try {
				filehostNormalizeCanonicalUrl($url);
				$this->fail("Canonical URL case '{$case}' should be rejected.");
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testRequestOriginIncludesSchemeAndNormalizedPort(): void
	{
		$canonical = 'https://files.example.com/app';
		$this->assertTrue(filehostRequestMatchesCanonicalOrigin(true, 'FILES.EXAMPLE.COM:443', $canonical));
		$this->assertFalse(filehostRequestMatchesCanonicalOrigin(false, 'files.example.com', $canonical));
		$this->assertFalse(filehostRequestMatchesCanonicalOrigin(true, 'files.example.com:444', $canonical));
		$this->assertFalse(filehostRequestMatchesCanonicalOrigin(true, 'attacker.example', $canonical));
		$this->assertFalse(filehostRequestMatchesCanonicalOrigin(true, 'files.example.com, attacker.example', $canonical));
	}

	public function testOriginAndRefererAreComparedAgainstTheCanonicalOrigin(): void
	{
		$this->assertSame(
			'https://files.example.com',
			filehostOriginOrNull('https://files.example.com/panel.php?tab=user')
		);
		$this->assertNull(filehostOriginOrNull('not a URL'));
	}
}
