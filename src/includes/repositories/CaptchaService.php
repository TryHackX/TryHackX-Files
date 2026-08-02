<?php
/**
 * CaptchaService (Faza 5 · #2).
 *
 * reCAPTCHA verification + enabled-state check. Extracted from the Database
 * god-object; Database::verifyRecaptcha / isRecaptchaEnabled delegate here.
 * With no secret configured, verification is a no-op (returns true) so the app
 * works out of the box.
 */
final class CaptchaService
{
	public static function verify(string $response, string $ip): bool
	{
		$secretKey = Database::getSecretSetting('recaptcha_secret_key', '');
		if (empty($secretKey)) {
			return true;
		}

		$data = http_build_query([
			'secret' => $secretKey,
			'response' => $response,
			'remoteip' => $ip,
		]);

		$ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $data,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_CONNECTTIMEOUT => 3,
		]);
		$result = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($result === false || $httpCode !== 200) {
			error_log("reCAPTCHA verification failed: connection error");
			return false;
		}

		$json = json_decode($result, true);
		if (!is_array($json) || ($json['success'] ?? false) !== true) {
			$codes = is_array($json['error-codes'] ?? null)
				? implode(',', array_map('strval', $json['error-codes']))
				: 'invalid-response';
			error_log('reCAPTCHA rejected: ' . substr($codes, 0, 200));
			return false;
		}

		$expectedHost = strtolower(trim((string) Database::getSetting(
			'recaptcha_expected_hostname',
			(string) (parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_HOST) ?: '')
		)));
		$returnedHost = strtolower(trim((string) ($json['hostname'] ?? '')));
		if ($expectedHost !== '' && !hash_equals($expectedHost, $returnedHost)) {
			error_log('reCAPTCHA rejected: hostname mismatch');
			return false;
		}

		if (isset($json['score'])) {
			$minimum = max(0.0, min(1.0, (float) Database::getSetting('recaptcha_min_score', '0.5')));
			if ((float) $json['score'] < $minimum) {
				error_log('reCAPTCHA rejected: score below threshold');
				return false;
			}
		}
		$expectedAction = trim((string) Database::getSetting('recaptcha_expected_action', ''));
		if ($expectedAction !== '' && !hash_equals($expectedAction, (string) ($json['action'] ?? ''))) {
			error_log('reCAPTCHA rejected: action mismatch');
			return false;
		}
		return true;
	}

	public static function isEnabled(): bool
	{
		$enabled = Database::getSetting('recaptcha_enabled', '0');
		$siteKey = Database::getSetting('recaptcha_site_key', '');
		$secretKey = Database::getSecretSetting('recaptcha_secret_key', '');

		return $enabled === '1' && !empty($siteKey) && !empty($secretKey);
	}
}
