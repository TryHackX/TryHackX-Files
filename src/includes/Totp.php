<?php
/**
 * Minimal RFC 6238 (TOTP) / RFC 4226 (HOTP) implementation — no external dependencies.
 *
 * Defaults match what authenticator apps (Google Authenticator, Aegis, 1Password, …)
 * expect out of the box: SHA-1, 30-second steps, 6 digits.
 */
class Totp
{
	private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	public const PERIOD = 30;
	public const DIGITS = 6;

	/** A fresh base32 secret (160 bits — the size RFC 4226 recommends for SHA-1). */
	public static function generateSecret(int $bytes = 20): string
	{
		return self::base32Encode(random_bytes($bytes));
	}

	public static function base32Encode(string $data): string
	{
		$out = '';
		$bits = 0;
		$value = 0;
		foreach (str_split($data) as $ch) {
			$value = ($value << 8) | ord($ch);
			$bits += 8;
			while ($bits >= 5) {
				$out .= self::ALPHABET[($value >> ($bits - 5)) & 31];
				$bits -= 5;
			}
		}
		if ($bits > 0) {
			$out .= self::ALPHABET[($value << (5 - $bits)) & 31];
		}
		return $out;
	}

	public static function base32Decode(string $b32): string
	{
		$b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
		$out = '';
		$bits = 0;
		$value = 0;
		foreach (str_split($b32) as $ch) {
			$idx = strpos(self::ALPHABET, $ch);
			if ($idx === false) {
				continue;
			}
			$value = ($value << 5) | $idx;
			$bits += 5;
			if ($bits >= 8) {
				$out .= chr(($value >> ($bits - 8)) & 255);
				$bits -= 8;
			}
		}
		return $out;
	}

	/** The counter-based code (HOTP) — TOTP is this with counter = time / period. */
	public static function hotp(string $secretB32, int $counter): string
	{
		$key = self::base32Decode($secretB32);
		$hash = hash_hmac('sha1', pack('J', $counter), $key, true); // J = 64-bit big-endian
		$offset = ord($hash[19]) & 0x0f;
		$code = ((ord($hash[$offset]) & 0x7f) << 24)
			| ((ord($hash[$offset + 1]) & 0xff) << 16)
			| ((ord($hash[$offset + 2]) & 0xff) << 8)
			| (ord($hash[$offset + 3]) & 0xff);
		return str_pad((string) ($code % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
	}

	public static function codeAt(string $secretB32, ?int $time = null): string
	{
		return self::hotp($secretB32, intdiv($time ?? time(), self::PERIOD));
	}

	/**
	 * Verify a user-supplied code, tolerating ±$window steps of clock drift.
	 * Compares with hash_equals so a wrong code can't be timed out digit by digit.
	 */
	public static function verify(string $secretB32, string $code, int $window = 1, ?int $time = null): bool
	{
		$code = preg_replace('/\D/', '', (string) $code);
		if (strlen($code) !== self::DIGITS) {
			return false;
		}
		$counter = intdiv($time ?? time(), self::PERIOD);
		for ($i = -$window; $i <= $window; $i++) {
			if (hash_equals(self::hotp($secretB32, $counter + $i), $code)) {
				return true;
			}
		}
		return false;
	}

	/** otpauth:// URI that authenticator apps read from the QR code. */
	public static function uri(string $secretB32, string $account, string $issuer): string
	{
		return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
			. '?secret=' . $secretB32
			. '&issuer=' . rawurlencode($issuer)
			. '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
	}
}
