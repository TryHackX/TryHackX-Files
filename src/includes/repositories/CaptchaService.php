<?php
/**
 * CaptchaService (Faza 5 · #2; multi-provider since 2.79.0).
 *
 * Verification + enabled-state check for the captcha provider the operator picked in
 * Settings → Security. Four providers are supported and they all speak the same
 * `secret`/`response`/`remoteip` POST and answer with a JSON `success` flag, so the only
 * things that really differ are the endpoint, the widget class and which extra fields in the
 * answer are worth trusting.
 *
 * Keys are stored per provider, so switching between them does not throw away the keys of
 * the one you were using before — flip the selector back and the old pair is still there.
 * Secrets go through Database::setSecretSetting() (encrypted at rest), site keys are public
 * by definition and stay plain.
 *
 * With no secret configured and the feature switched off, verification is a no-op (returns
 * true) so a fresh install works out of the box; with the feature switched ON but the chosen
 * provider unconfigured it fails closed instead — an operator who asked for a captcha should
 * never silently get "everything passes".
 */
final class CaptchaService
{
	public const PROVIDER_RECAPTCHA_V2 = 'recaptcha_v2';
	public const PROVIDER_RECAPTCHA_V3 = 'recaptcha_v3';
	public const PROVIDER_TURNSTILE = 'turnstile';
	public const PROVIDER_HCAPTCHA = 'hcaptcha';

	/** The default keeps every pre-2.79.0 install exactly where it was. */
	public const DEFAULT_PROVIDER = self::PROVIDER_RECAPTCHA_V2;

	/**
	 * Per-provider wiring.
	 *
	 * `site`/`secret` are deliberately different setting keys per provider — that is what
	 * makes switching non-destructive. reCAPTCHA v2 keeps the historical `recaptcha_site_key`
	 * / `recaptcha_secret_key` names so an upgrade needs no migration at all.
	 *
	 * `script` is the loader the browser pulls in; `origins` are the CSP hosts that loader
	 * and its frames need (see cspOrigins()).
	 */
	private const PROVIDERS = [
		self::PROVIDER_RECAPTCHA_V2 => [
			'verify' => 'https://www.google.com/recaptcha/api/siteverify',
			'site' => 'recaptcha_site_key',
			'secret' => 'recaptcha_secret_key',
			'widget' => 'g-recaptcha',
			'script' => 'https://www.google.com/recaptcha/api.js',
			'origins' => ['https://www.google.com', 'https://www.gstatic.com'],
			'invisible' => false,
		],
		self::PROVIDER_RECAPTCHA_V3 => [
			'verify' => 'https://www.google.com/recaptcha/api/siteverify',
			'site' => 'recaptcha_v3_site_key',
			'secret' => 'recaptcha_v3_secret_key',
			'widget' => 'g-recaptcha',
			'script' => 'https://www.google.com/recaptcha/api.js',
			'origins' => ['https://www.google.com', 'https://www.gstatic.com'],
			'invisible' => true,
		],
		self::PROVIDER_TURNSTILE => [
			'verify' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			'site' => 'turnstile_site_key',
			'secret' => 'turnstile_secret_key',
			'widget' => 'cf-turnstile',
			'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
			'origins' => ['https://challenges.cloudflare.com'],
			'invisible' => false,
		],
		self::PROVIDER_HCAPTCHA => [
			'verify' => 'https://api.hcaptcha.com/siteverify',
			'site' => 'hcaptcha_site_key',
			'secret' => 'hcaptcha_secret_key',
			'widget' => 'h-captcha',
			'script' => 'https://js.hcaptcha.com/1/api.js',
			'origins' => ['https://js.hcaptcha.com', 'https://newassets.hcaptcha.com', 'https://hcaptcha.com'],
			'invisible' => false,
		],
	];

	/** @return list<string> */
	public static function providers(): array
	{
		return array_keys(self::PROVIDERS);
	}

	/** The configured provider, or the default when the stored value is unknown/empty. */
	public static function provider(): string
	{
		$stored = (string) Database::getSetting('captcha_provider', self::DEFAULT_PROVIDER);
		return isset(self::PROVIDERS[$stored]) ? $stored : self::DEFAULT_PROVIDER;
	}

	/** Normalise an operator-supplied value; anything unknown falls back to the default. */
	public static function normaliseProvider(?string $candidate): string
	{
		$candidate = trim((string) $candidate);
		return isset(self::PROVIDERS[$candidate]) ? $candidate : self::DEFAULT_PROVIDER;
	}

	/** Setting key holding the site key of $provider (defaults to the active one). */
	public static function siteKeySetting(?string $provider = null): string
	{
		return self::PROVIDERS[self::normaliseProvider($provider ?? self::provider())]['site'];
	}

	/** Setting key holding the secret of $provider (defaults to the active one). */
	public static function secretKeySetting(?string $provider = null): string
	{
		return self::PROVIDERS[self::normaliseProvider($provider ?? self::provider())]['secret'];
	}

	public static function siteKey(?string $provider = null): string
	{
		return trim((string) Database::getSetting(self::siteKeySetting($provider), ''));
	}

	public static function secretKey(?string $provider = null): string
	{
		return trim((string) Database::getSecretSetting(self::secretKeySetting($provider), ''));
	}

	/** Widget class the front end renders into: g-recaptcha / cf-turnstile / h-captcha. */
	public static function widget(?string $provider = null): string
	{
		return self::PROVIDERS[self::normaliseProvider($provider ?? self::provider())]['widget'];
	}

	/** Loader URL for the browser SDK of the active provider. */
	public static function scriptUrl(?string $provider = null): string
	{
		return self::PROVIDERS[self::normaliseProvider($provider ?? self::provider())]['script'];
	}

	/** True when the provider has no visible widget and the token comes from execute(). */
	public static function isInvisible(?string $provider = null): bool
	{
		return self::PROVIDERS[self::normaliseProvider($provider ?? self::provider())]['invisible'];
	}

	/**
	 * CSP origins the active provider needs in script-src and frame-src.
	 *
	 * Only the selected provider's hosts are admitted — picking Turnstile should not leave
	 * Google's script origins whitelisted. Returns [] while the captcha is switched off.
	 *
	 * @return list<string>
	 */
	public static function cspOrigins(?array $settings = null): array
	{
		$enabled = $settings === null
			? (string) Database::getSetting('recaptcha_enabled', '0')
			: (string) ($settings['recaptcha_enabled'] ?? '0');
		if ($enabled !== '1') {
			return [];
		}
		$provider = $settings === null
			? self::provider()
			: self::normaliseProvider((string) ($settings['captcha_provider'] ?? self::DEFAULT_PROVIDER));

		return self::PROVIDERS[$provider]['origins'];
	}

	/**
	 * Verify a challenge response against the active provider.
	 *
	 * Two attempts, not one: a saturated uplink used to lose the single verification request
	 * to a timeout and the visitor saw "captcha failed" on a challenge they had just solved.
	 */
	public static function verify(string $response, string $ip): bool
	{
		$provider = self::provider();
		$secretKey = self::secretKey($provider);
		if ($secretKey === '') {
			// Switched off entirely: nothing to check, let the caller through. Switched on but
			// unconfigured: fail closed rather than wave everything past a gate that is meant
			// to be shut.
			return (string) Database::getSetting('recaptcha_enabled', '0') !== '1';
		}
		if ($response === '') {
			return false;
		}

		$json = self::postToVerifier(self::PROVIDERS[$provider]['verify'], [
			'secret' => $secretKey,
			'response' => $response,
			'remoteip' => $ip,
		]);
		if ($json === null) {
			return false;
		}

		if (($json['success'] ?? false) !== true) {
			$codes = is_array($json['error-codes'] ?? null)
				? implode(',', array_map('strval', $json['error-codes']))
				: 'invalid-response';
			error_log('Captcha rejected (' . $provider . '): ' . substr($codes, 0, 200));
			return false;
		}

		return self::checkHostname($json, $provider)
			&& self::checkScore($json, $provider)
			&& self::checkAction($json, $provider);
	}

	public static function isEnabled(): bool
	{
		$enabled = (string) Database::getSetting('recaptcha_enabled', '0');
		if ($enabled !== '1') {
			return false;
		}
		$provider = self::provider();

		return self::siteKey($provider) !== '' && self::secretKey($provider) !== '';
	}

	/**
	 * POST to a provider's siteverify endpoint and decode the answer.
	 *
	 * @param array<string, string> $fields
	 * @return array<string, mixed>|null null on a transport-level failure
	 */
	private static function postToVerifier(string $url, array $fields): ?array
	{
		$data = http_build_query($fields);

		for ($attempt = 1; $attempt <= 2; $attempt++) {
			$ch = curl_init($url);
			if ($ch === false) {
				return null;
			}
			curl_setopt_array($ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $data,
				CURLOPT_TIMEOUT => 8,
				CURLOPT_CONNECTTIMEOUT => 5,
			]);
			$result = curl_exec($ch);
			$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$transportError = curl_error($ch);
			curl_close($ch);

			if ($result === false || $httpCode !== 200) {
				if ($attempt === 1) {
					continue;
				}
				error_log('Captcha verification failed: connection error'
					. ($transportError !== '' ? ' (' . substr($transportError, 0, 120) . ')' : '')
					. ' HTTP ' . $httpCode);
				return null;
			}

			$json = json_decode((string) $result, true);
			if (!is_array($json)) {
				error_log('Captcha verification failed: malformed response');
				return null;
			}
			return $json;
		}

		return null;
	}

	/**
	 * All four providers echo the hostname the challenge was solved on. When one omits it,
	 * the check is skipped rather than treated as a mismatch — the field comes from the
	 * provider, not the visitor, so its absence is a provider quirk and not an attack.
	 *
	 * @param array<string, mixed> $json
	 */
	private static function checkHostname(array $json, string $provider): bool
	{
		$expectedHost = strtolower(trim((string) Database::getSetting(
			'recaptcha_expected_hostname',
			(string) (parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_HOST) ?: '')
		)));
		if ($expectedHost === '' || !isset($json['hostname'])) {
			return true;
		}
		$returnedHost = strtolower(trim((string) $json['hostname']));
		if ($returnedHost === '' || hash_equals($expectedHost, $returnedHost)) {
			return true;
		}
		error_log('Captcha rejected (' . $provider . '): hostname mismatch');

		return false;
	}

	/**
	 * Score threshold — reCAPTCHA v3 only.
	 *
	 * Deliberately not applied to the others: hCaptcha's enterprise answer also carries a
	 * `score`, but it is a RISK score where higher is worse — the exact opposite of v3's
	 * "1.0 is probably human". Running v3's `>= minimum` rule over it would reject the good
	 * traffic and pass the bad.
	 *
	 * @param array<string, mixed> $json
	 */
	private static function checkScore(array $json, string $provider): bool
	{
		if ($provider !== self::PROVIDER_RECAPTCHA_V3 || !isset($json['score'])) {
			return true;
		}
		$minimum = max(0.0, min(1.0, (float) Database::getSetting('recaptcha_min_score', '0.5')));
		if ((float) $json['score'] >= $minimum) {
			return true;
		}
		error_log('Captcha rejected (' . $provider . '): score '
			. (float) $json['score'] . ' below threshold ' . $minimum);

		return false;
	}

	/**
	 * Action binding — reCAPTCHA v3 and Turnstile are the two that echo `action` back.
	 *
	 * @param array<string, mixed> $json
	 */
	private static function checkAction(array $json, string $provider): bool
	{
		if ($provider !== self::PROVIDER_RECAPTCHA_V3 && $provider !== self::PROVIDER_TURNSTILE) {
			return true;
		}
		$expectedAction = trim((string) Database::getSetting('recaptcha_expected_action', ''));
		if ($expectedAction === '') {
			return true;
		}
		if (hash_equals($expectedAction, (string) ($json['action'] ?? ''))) {
			return true;
		}
		error_log('Captcha rejected (' . $provider . '): action mismatch');

		return false;
	}
}
