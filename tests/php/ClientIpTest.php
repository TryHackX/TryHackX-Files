<?php

use PHPUnit\Framework\TestCase;

require_once PROJECT_ROOT . '/src/includes/ClientIp.php';

/**
 * Which address a request is counted, throttled and banned under.
 *
 * Behind a proxy the forwarding header is only partly the proxy's own writing; everything a
 * client put there itself must be ignored, or one header buys a fresh address per request.
 */
final class ClientIpTest extends TestCase
{
	public function testWithoutConfiguredProxiesOnlyThePeerCounts(): void
	{
		$server = [
			'REMOTE_ADDR' => '198.51.100.20',
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
			'HTTP_CF_CONNECTING_IP' => '5.6.7.8',
			'HTTP_X_REAL_IP' => '9.9.9.9',
		];
		$this->assertSame('198.51.100.20', filehostResolveClientIp($server, []));
		$this->assertSame(
			'198.51.100.20',
			filehostResolveClientIp($server, ['10.0.0.1']),
			'headers from a peer that is not a configured proxy are ignored'
		);
	}

	public function testForwardedForIsReadFromTheRightSkippingOurOwnProxies(): void
	{
		$proxies = ['10.0.0.1', '10.0.0.2'];
		// The client sent "X-Forwarded-For: 1.2.3.4"; our two proxies appended what they saw.
		$server = [
			'REMOTE_ADDR' => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.50, 10.0.0.2',
		];
		$this->assertSame('203.0.113.50', filehostResolveClientIp($server, $proxies));

		// A client-supplied CF-Connecting-IP is not believed unless the operator says so.
		$server['HTTP_CF_CONNECTING_IP'] = '5.6.7.8';
		$this->assertSame('203.0.113.50', filehostResolveClientIp($server, $proxies));
	}

	public function testGarbageInTheProxyPartFallsBackToThePeer(): void
	{
		$server = [
			'REMOTE_ADDR' => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '203.0.113.50, not-an-address',
		];
		$this->assertSame('10.0.0.1', filehostResolveClientIp($server, ['10.0.0.1']));
	}

	public function testAnExplicitlyNamedSingleValueHeaderIsUsed(): void
	{
		$server = [
			'REMOTE_ADDR' => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
			'HTTP_CF_CONNECTING_IP' => '203.0.113.77',
		];
		$this->assertSame('203.0.113.77', filehostResolveClientIp($server, ['10.0.0.1'], 'CF-Connecting-IP'));
		$server['HTTP_CF_CONNECTING_IP'] = 'junk';
		$this->assertSame('10.0.0.1', filehostResolveClientIp($server, ['10.0.0.1'], 'CF-Connecting-IP'));
		$this->assertSame('10.0.0.1', filehostResolveClientIp($server, ['10.0.0.1'], 'X-Unknown'));
	}

	public function testThrottleKeysGroupAnIpv6NetworkButKeepIpv4Addresses(): void
	{
		$this->assertSame('203.0.113.9', filehostThrottleKey('203.0.113.9'));
		$this->assertSame('2001:db8:1:2::/64', filehostThrottleKey('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
		$this->assertSame(
			filehostThrottleKey('2001:db8:1:2::1'),
			filehostThrottleKey('2001:db8:1:2:ffff::ffff'),
			'one /64 is one client'
		);
		$this->assertNotSame(filehostThrottleKey('2001:db8:1:2::1'), filehostThrottleKey('2001:db8:1:3::1'));
		$this->assertSame('192.0.2.5', filehostThrottleKey('::ffff:192.0.2.5'), 'mapped IPv4 is IPv4');
		$this->assertLessThanOrEqual(45, strlen(filehostThrottleKey('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff')));
	}
}
