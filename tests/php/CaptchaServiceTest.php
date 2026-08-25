<?php

require_once PROJECT_ROOT . '/src/includes/ContentSecurityPolicy.php';

/**
 * CaptchaService: provider selection, per-provider key storage and the answer checks.
 *
 * Nothing here talks to Google/Cloudflare/hCaptcha — the parts worth testing are the ones
 * that decide WHICH provider is asked, with which credentials, and what is done with the
 * answer once it arrives. The verdict helpers are exercised through reflection so the
 * network call stays out of the picture entirely.
 */
final class CaptchaServiceTest extends RepoTestCase
{
	/** @var array<string, string> */
	private array $saved = [];

	/** @var list<string> */
	private const TOUCHED = [
		'recaptcha_enabled',
		'captcha_provider',
		'recaptcha_site_key',
		'recaptcha_v3_site_key',
		'turnstile_site_key',
		'hcaptcha_site_key',
		'recaptcha_min_score',
		'recaptcha_expected_action',
		'recaptcha_expected_hostname',
	];

	/** @var list<string> */
	private const SECRETS = [
		'recaptcha_secret_key',
		'recaptcha_v3_secret_key',
		'turnstile_secret_key',
		'hcaptcha_secret_key',
	];

	protected function setUp(): void
	{
		foreach (array_merge(self::TOUCHED, self::SECRETS) as $key) {
			$this->saved[$key] = (string) Database::getSetting($key, '');
		}
		foreach (self::TOUCHED as $key) {
			Database::setSetting($key, '');
		}
		foreach (self::SECRETS as $key) {
			Database::setSecretSetting($key, '');
		}
		// A hostname check needs a hostname to compare against; the tests that care set it.
		Database::setSetting('recaptcha_expected_hostname', '');
		Database::invalidateSettingsCache();
	}

	protected function tearDown(): void
	{
		foreach ($this->saved as $key => $value) {
			Database::setSetting($key, $value);
		}
		Database::invalidateSettingsCache();
	}

	private function verdict(string $method, array $json, string $provider): bool
	{
		$reflection = new ReflectionMethod(CaptchaService::class, $method);

		return (bool) $reflection->invoke(null, $json, $provider);
	}

	public function testDefaultProviderIsRecaptchaV2SoUpgradesDoNotMove(): void
	{
		$this->assertSame(CaptchaService::PROVIDER_RECAPTCHA_V2, CaptchaService::provider());
		$this->assertSame('recaptcha_site_key', CaptchaService::siteKeySetting());
		$this->assertSame('recaptcha_secret_key', CaptchaService::secretKeySetting());
	}

	public function testSelectingAProviderSwitchesEndpointKeysAndWidget(): void
	{
		$expected = [
			CaptchaService::PROVIDER_TURNSTILE => ['turnstile_site_key', 'turnstile_secret_key', 'cf-turnstile'],
			CaptchaService::PROVIDER_RECAPTCHA_V3 => ['recaptcha_v3_site_key', 'recaptcha_v3_secret_key', 'g-recaptcha'],
			CaptchaService::PROVIDER_RECAPTCHA_V2 => ['recaptcha_site_key', 'recaptcha_secret_key', 'g-recaptcha'],
			CaptchaService::PROVIDER_HCAPTCHA => ['hcaptcha_site_key', 'hcaptcha_secret_key', 'h-captcha'],
		];

		foreach ($expected as $provider => [$site, $secret, $widget]) {
			Database::setSetting('captcha_provider', $provider);
			Database::invalidateSettingsCache();

			$this->assertSame($provider, CaptchaService::provider());
			$this->assertSame($site, CaptchaService::siteKeySetting());
			$this->assertSame($secret, CaptchaService::secretKeySetting());
			$this->assertSame($widget, CaptchaService::widget());
		}
	}

	public function testUnknownProviderFallsBackInsteadOfBreakingTheGate(): void
	{
		Database::setSetting('captcha_provider', 'not-a-provider');
		Database::invalidateSettingsCache();

		$this->assertSame(CaptchaService::PROVIDER_RECAPTCHA_V2, CaptchaService::provider());
		$this->assertSame(
			CaptchaService::PROVIDER_RECAPTCHA_V2,
			CaptchaService::normaliseProvider('')
		);
	}

	/** The headline promise: switching providers must not discard the previous key pair. */
	public function testSwitchingProviderKeepsEveryProvidersKeys(): void
	{
		$keys = [
			CaptchaService::PROVIDER_TURNSTILE => ['0x4AAAAAAEbsmPL5oo5-8pt1', 'turnstile-secret'],
			CaptchaService::PROVIDER_RECAPTCHA_V3 => ['6LdKVpgtAAAAAN0cf-site', 'v3-secret'],
			CaptchaService::PROVIDER_RECAPTCHA_V2 => ['6LcLU8cUAAAAAJWH8-site', 'v2-secret'],
			CaptchaService::PROVIDER_HCAPTCHA => ['366c6812-5ec6-4e14-9310-fad2cf305bb0', 'hcaptcha-secret'],
		];
		foreach ($keys as $provider => [$site, $secret]) {
			Database::setSetting(CaptchaService::siteKeySetting($provider), $site);
			Database::setSecretSetting(CaptchaService::secretKeySetting($provider), $secret);
		}
		Database::invalidateSettingsCache();

		// Walk through all four, then back to the first one.
		foreach ([...array_keys($keys), CaptchaService::PROVIDER_TURNSTILE] as $provider) {
			Database::setSetting('captcha_provider', $provider);
			Database::invalidateSettingsCache();

			$this->assertSame($keys[$provider][0], CaptchaService::siteKey());
			$this->assertSame($keys[$provider][1], CaptchaService::secretKey());
		}

		// And every other provider still has its own pair intact.
		foreach ($keys as $provider => [$site, $secret]) {
			$this->assertSame($site, CaptchaService::siteKey($provider));
			$this->assertSame($secret, CaptchaService::secretKey($provider));
		}
	}

	public function testEnabledNeedsBothKeysOfTheSelectedProvider(): void
	{
		Database::setSetting('recaptcha_enabled', '1');
		Database::setSetting('captcha_provider', CaptchaService::PROVIDER_TURNSTILE);
		Database::setSetting('recaptcha_site_key', 'v2-site');
		Database::setSecretSetting('recaptcha_secret_key', 'v2-secret');
		Database::invalidateSettingsCache();

		// v2 is fully configured, but Turnstile is the one selected and it is not.
		$this->assertFalse(CaptchaService::isEnabled());

		Database::setSetting('turnstile_site_key', 'turnstile-site');
		Database::invalidateSettingsCache();
		$this->assertFalse(CaptchaService::isEnabled(), 'site key alone is not enough');

		Database::setSecretSetting('turnstile_secret_key', 'turnstile-secret');
		Database::invalidateSettingsCache();
		$this->assertTrue(CaptchaService::isEnabled());
	}

	/**
	 * An unconfigured provider must not wave a response through while the operator has the
	 * captcha switched on; with the feature off, verification stays the historical no-op so a
	 * fresh install is usable out of the box.
	 */
	public function testResponseIsRejectedWhenTheSelectedProviderHasNoSecret(): void
	{
		Database::setSetting('captcha_provider', CaptchaService::PROVIDER_HCAPTCHA);
		Database::setSetting('recaptcha_enabled', '1');
		Database::setSetting('recaptcha_site_key', 'v2-site');
		Database::setSecretSetting('recaptcha_secret_key', 'v2-secret');
		Database::invalidateSettingsCache();

		$this->assertFalse(CaptchaService::verify('any-response', '127.0.0.1'));

		Database::setSetting('recaptcha_enabled', '0');
		Database::invalidateSettingsCache();
		$this->assertTrue(
			CaptchaService::verify('any-response', '127.0.0.1'),
			'with the captcha switched off there is nothing to verify'
		);
	}

	public function testScoreThresholdAppliesToRecaptchaV3(): void
	{
		Database::setSetting('recaptcha_min_score', '0.70');
		Database::invalidateSettingsCache();

		$this->assertFalse(
			$this->verdict('checkScore', ['score' => 0.3], CaptchaService::PROVIDER_RECAPTCHA_V3)
		);
		$this->assertTrue(
			$this->verdict('checkScore', ['score' => 0.7], CaptchaService::PROVIDER_RECAPTCHA_V3),
			'the threshold is inclusive'
		);
		$this->assertTrue(
			$this->verdict('checkScore', ['score' => 0.95], CaptchaService::PROVIDER_RECAPTCHA_V3)
		);
		$this->assertTrue(
			$this->verdict('checkScore', [], CaptchaService::PROVIDER_RECAPTCHA_V3),
			'no score in the answer is not a failure'
		);
	}

	/**
	 * hCaptcha's enterprise answer also carries a `score`, but higher means MORE risk there.
	 * Running v3's rule over it would reject the humans, so the threshold must stay v3-only.
	 */
	public function testScoreThresholdIsNotAppliedToTheOtherProviders(): void
	{
		Database::setSetting('recaptcha_min_score', '0.90');
		Database::invalidateSettingsCache();

		foreach ([
			CaptchaService::PROVIDER_HCAPTCHA,
			CaptchaService::PROVIDER_TURNSTILE,
			CaptchaService::PROVIDER_RECAPTCHA_V2,
		] as $provider) {
			$this->assertTrue(
				$this->verdict('checkScore', ['score' => 0.1], $provider),
				$provider . ' must not be judged by the v3 score rule'
			);
		}
	}

	public function testHostnameMismatchIsRejectedAndAnAbsentHostnameIsNot(): void
	{
		Database::setSetting('recaptcha_expected_hostname', 'files.example.test');
		Database::invalidateSettingsCache();

		$this->assertTrue($this->verdict(
			'checkHostname',
			['hostname' => 'files.example.test'],
			CaptchaService::PROVIDER_TURNSTILE
		));
		$this->assertFalse($this->verdict(
			'checkHostname',
			['hostname' => 'phish.example.invalid'],
			CaptchaService::PROVIDER_TURNSTILE
		));
		$this->assertTrue(
			$this->verdict('checkHostname', [], CaptchaService::PROVIDER_HCAPTCHA),
			'a provider that omits the field is a provider quirk, not an attack'
		);
	}

	public function testActionBindingCoversTheProvidersThatEchoItBack(): void
	{
		Database::setSetting('recaptcha_expected_action', 'upload');
		Database::invalidateSettingsCache();

		$this->assertTrue($this->verdict(
			'checkAction',
			['action' => 'upload'],
			CaptchaService::PROVIDER_RECAPTCHA_V3
		));
		$this->assertFalse($this->verdict(
			'checkAction',
			['action' => 'login'],
			CaptchaService::PROVIDER_RECAPTCHA_V3
		));
		$this->assertFalse($this->verdict(
			'checkAction',
			[],
			CaptchaService::PROVIDER_TURNSTILE
		));
		$this->assertTrue(
			$this->verdict('checkAction', [], CaptchaService::PROVIDER_HCAPTCHA),
			'hCaptcha does not return an action, so there is nothing to bind'
		);
	}

	public function testCspOriginsFollowTheSelectedProviderAndTheOnOffSwitch(): void
	{
		$this->assertSame(
			[],
			CaptchaService::cspOrigins(['recaptcha_enabled' => '0', 'captcha_provider' => 'turnstile']),
			'a captcha that is switched off whitelists nobody'
		);

		$turnstile = CaptchaService::cspOrigins([
			'recaptcha_enabled' => '1',
			'captcha_provider' => CaptchaService::PROVIDER_TURNSTILE,
		]);
		$this->assertContains('https://challenges.cloudflare.com', $turnstile);
		$this->assertNotContains('https://www.google.com', $turnstile);

		$hcaptcha = CaptchaService::cspOrigins([
			'recaptcha_enabled' => '1',
			'captcha_provider' => CaptchaService::PROVIDER_HCAPTCHA,
		]);
		$this->assertContains('https://js.hcaptcha.com', $hcaptcha);
		$this->assertNotContains('https://www.google.com', $hcaptcha);

		$v3 = CaptchaService::cspOrigins([
			'recaptcha_enabled' => '1',
			'captcha_provider' => CaptchaService::PROVIDER_RECAPTCHA_V3,
		]);
		$this->assertContains('https://www.google.com', $v3);
		$this->assertContains('https://www.gstatic.com', $v3);
	}

	/**
	 * The policy string the browser actually receives, not just the origin list.
	 *
	 * `emitContentSecurityPolicy()` calls `header()`, which does nothing under the CLI SAPI,
	 * so the string is built by `buildContentSecurityPolicy()` and asserted here.
	 */
	public function testThePolicyStringCarriesOnlyTheSelectedProvidersOrigins(): void
	{
		$off = buildContentSecurityPolicy(['recaptcha_enabled' => '0']);
		$this->assertStringContainsString("script-src 'self'", $off);
		$this->assertStringNotContainsString('google.com', $off);
		$this->assertStringNotContainsString('cloudflare.com', $off);
		$this->assertStringNotContainsString('hcaptcha.com', $off);

		$turnstile = buildContentSecurityPolicy([
			'recaptcha_enabled' => '1',
			'captcha_provider' => CaptchaService::PROVIDER_TURNSTILE,
		]);
		$this->assertStringContainsString('https://challenges.cloudflare.com', $turnstile);
		$this->assertStringNotContainsString('google.com', $turnstile);
		// The origin has to reach script-src AND frame-src: the widget is an iframe.
		$this->assertMatchesRegularExpression(
			'/script-src[^;]*https:\/\/challenges\.cloudflare\.com/',
			$turnstile
		);
		$this->assertMatchesRegularExpression(
			'/frame-src[^;]*https:\/\/challenges\.cloudflare\.com/',
			$turnstile
		);

		$hcaptcha = buildContentSecurityPolicy([
			'recaptcha_enabled' => '1',
			'captcha_provider' => CaptchaService::PROVIDER_HCAPTCHA,
		]);
		$this->assertStringContainsString('https://js.hcaptcha.com', $hcaptcha);
		$this->assertStringNotContainsString('google.com', $hcaptcha);

		foreach ([
			CaptchaService::PROVIDER_RECAPTCHA_V2,
			CaptchaService::PROVIDER_RECAPTCHA_V3,
		] as $provider) {
			$policy = buildContentSecurityPolicy([
				'recaptcha_enabled' => '1',
				'captcha_provider' => $provider,
			]);
			$this->assertStringContainsString('https://www.google.com', $policy);
			$this->assertStringContainsString('https://www.gstatic.com', $policy);
			$this->assertStringNotContainsString('cloudflare.com', $policy);
		}
	}

	public function testEveryProviderHasAVerificationEndpointAndLoader(): void
	{
		$endpoints = [
			CaptchaService::PROVIDER_TURNSTILE => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			CaptchaService::PROVIDER_RECAPTCHA_V3 => 'https://www.google.com/recaptcha/api/siteverify',
			CaptchaService::PROVIDER_RECAPTCHA_V2 => 'https://www.google.com/recaptcha/api/siteverify',
			CaptchaService::PROVIDER_HCAPTCHA => 'https://api.hcaptcha.com/siteverify',
		];
		$declared = CaptchaService::providers();
		sort($declared);
		$expected = array_keys($endpoints);
		sort($expected);
		$this->assertSame($expected, $declared);

		$reflection = new ReflectionClass(CaptchaService::class);
		$config = $reflection->getConstant('PROVIDERS');
		foreach ($endpoints as $provider => $verify) {
			$this->assertSame($verify, $config[$provider]['verify']);
			$this->assertStringStartsWith('https://', CaptchaService::scriptUrl($provider));
		}

		$this->assertTrue(CaptchaService::isInvisible(CaptchaService::PROVIDER_RECAPTCHA_V3));
		$this->assertFalse(CaptchaService::isInvisible(CaptchaService::PROVIDER_RECAPTCHA_V2));
		$this->assertFalse(CaptchaService::isInvisible(CaptchaService::PROVIDER_TURNSTILE));
		$this->assertFalse(CaptchaService::isInvisible(CaptchaService::PROVIDER_HCAPTCHA));
	}
}
