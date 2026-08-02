<?php
/**
 * RegistrationGuard — keeps one person from turning into fifty accounts (pkt C).
 *
 * Storage is per account, so accounts are the thing worth faking. Three doors are closed here,
 * and each is off by default so an existing install does not suddenly start refusing people:
 *
 *   1. **Which mail domains may sign up** — an allow-list (only these) or a block-list (anything
 *      but these). The usual use is blocking throwaway-inbox providers, or restricting a private
 *      instance to one company domain.
 *   2. **How many accounts one IP may create** in a rolling window. `users.registered_ip` is
 *      recorded at sign-up for exactly this.
 *   3. **Re-using an address that was changed away from.** Without this the cap is trivially
 *      dodged: register with A, move the account to B, register again with A. When a change is
 *      confirmed the old address is held for a while and counts as taken.
 *
 * Every rule applies to an email *change* as well as to registration, since otherwise the change
 * form is simply the way around them.
 *
 * None of this is a substitute for the rate limiter — it is about identity, not about traffic.
 */
final class RegistrationGuard
{
	/** 'off' | 'whitelist' | 'blacklist' */
	public static function domainMode(): string
	{
		$mode = (string) Database::getSetting('email_domain_mode', 'off');
		return in_array($mode, ['whitelist', 'blacklist'], true) ? $mode : 'off';
	}

	/** The configured domains, lower-cased, split on commas/newlines/spaces. */
	public static function domainList(): array
	{
		$raw = (string) Database::getSetting('email_domain_list', '');
		$parts = preg_split('/[\s,;]+/', mb_strtolower($raw)) ?: [];
		return array_values(array_filter(array_map(
			// Accept "@example.com" and "example.com" alike.
			fn($d) => ltrim(trim($d), '@'),
			$parts
		)));
	}

	/**
	 * Is this address allowed to hold an account? Returns null when fine, else the reason.
	 *
	 * Sub-domains count as the domain: blocking `example.com` also blocks `mail.example.com`,
	 * which is what an operator means by "block this provider".
	 */
	public static function checkDomain(string $email): ?string
	{
		$mode = self::domainMode();
		if ($mode === 'off') {
			return null;
		}
		$list = self::domainList();
		if (!$list) {
			return null; // nothing configured — an empty allow-list must not lock everyone out
		}

		$at = strrpos($email, '@');
		if ($at === false) {
			return __('api.bad_email');
		}
		$domain = mb_strtolower(substr($email, $at + 1));

		$matches = false;
		foreach ($list as $d) {
			if ($domain === $d || str_ends_with($domain, '.' . $d)) {
				$matches = true;
				break;
			}
		}

		if ($mode === 'whitelist' && !$matches) {
			return __('api.domain_not_allowed');
		}
		if ($mode === 'blacklist' && $matches) {
			return __('api.domain_blocked');
		}
		return null;
	}

	/** Accounts one IP may create, and over what window. 0 = no cap. */
	public static function ipLimit(): int
	{
		return max(0, (int) Database::getSetting('reg_ip_limit', 0));
	}

	public static function ipWindowDays(): int
	{
		return max(1, (int) Database::getSetting('reg_ip_window_days', 90));
	}

	/** Has this IP already used up its account allowance? Returns null when fine. */
	public static function checkIp(string $ip): ?string
	{
		$limit = self::ipLimit();
		if ($limit <= 0 || $ip === '') {
			return null;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$since = time() - (self::ipWindowDays() * 86400);
		try {
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM `" . Database::table('users')
				. "` WHERE `registered_ip` = ? AND `created_at` > ?");
			$stmt->execute([$ip, $since]);
			$count = (int) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return null; // never block sign-up because a count failed
		}

		if ($count >= $limit) {
			return __('api.ip_account_limit', ['n' => $limit, 'days' => self::ipWindowDays()]);
		}
		return null;
	}

	/** How long a released address stays reserved. 0 = release immediately. */
	public static function releaseDays(): int
	{
		return max(0, (int) Database::getSetting('email_release_days', 0));
	}

	/** Is this address still held from a previous account? Returns null when free. */
	public static function checkReserved(string $email): ?string
	{
		$days = self::releaseDays();
		if ($days <= 0) {
			return null;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		try {
			$stmt = $pdo->prepare("SELECT `released_at` FROM `" . Database::table('email_reservations')
				. "` WHERE `email` = ?");
			$stmt->execute([mb_strtolower($email)]);
			$releasedAt = $stmt->fetchColumn();
		} catch (PDOException $e) {
			return null;
		}

		if ($releasedAt && (time() - (int) $releasedAt) < ($days * 86400)) {
			$left = (int) ceil((($days * 86400) - (time() - (int) $releasedAt)) / 86400);
			return __('api.email_reserved', ['days' => $left]);
		}
		return null;
	}

	/** Hold an address that an account has just moved away from. */
	public static function reserve(string $email, ?int $userId = null): void
	{
		if (self::releaseDays() <= 0 || trim($email) === '') {
			return;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		try {
			$stmt = $pdo->prepare("INSERT INTO `" . Database::table('email_reservations')
				. "` (`email`, `user_id`, `released_at`) VALUES (?, ?, ?)
				ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`), `released_at` = VALUES(`released_at`)");
			$stmt->execute([mb_strtolower(trim($email)), $userId, time()]);
		} catch (PDOException $e) {
			// A missed reservation is not worth failing the change the user asked for.
		}
	}

	/**
	 * Everything that has to hold before an address may be attached to an account, whether at
	 * registration or on a change. Returns null when fine, else the first reason it is not.
	 */
	public static function checkEmail(string $email): ?string
	{
		return self::checkDomain($email) ?? self::checkReserved($email);
	}
}
