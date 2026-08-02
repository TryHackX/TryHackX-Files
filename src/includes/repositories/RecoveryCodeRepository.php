<?php
/**
 * RecoveryCodeRepository (Faza 6 · #2).
 *
 * Single-use fallback codes for TOTP two-factor auth. 2FA can only be turned off from inside
 * a logged-in session, so before these existed a lost authenticator meant a lost account with
 * no self-service way back in.
 *
 * Storage mirrors how passwords are handled: only a bcrypt hash of each code is kept, so the
 * table is worthless to anyone who reads it. A spent code is marked with `used_at` rather than
 * deleted, which keeps "how many are left" answerable and leaves an audit trail.
 *
 * Not to be confused with RecoveryRepository, which handles password-reset links by email.
 */
final class RecoveryCodeRepository
{
	/** How many codes a regeneration hands out. */
	public const BATCH_SIZE = 10;

	/**
	 * Characters used to build a code. Deliberately excludes the pairs that are easy to
	 * confuse when copied off a printout: 0/O, 1/I/L, 8/B.
	 */
	private const ALPHABET = '23456789ACDEFGHJKMNPQRTUVWXYZ';

	/**
	 * Replace a user's codes with a fresh batch. Returns the plaintext codes — the only time
	 * they exist in readable form, so the caller must show them to the user there and then.
	 */
	public static function regenerate(int $userId): array
	{
		$pdo = Database::getInstance();
		if (!$pdo || $userId < 1) {
			return [];
		}

		try {
			$pdo->beginTransaction();
			$codes = self::replaceInTransaction($pdo, $userId);
			$pdo->commit();
			return $codes;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return [];
		}
	}

	/**
	 * Replace the complete batch inside the caller's transaction.
	 *
	 * The delete is not visible until commit. A failed hash or insert therefore leaves the
	 * previous printable batch valid instead of stranding the account without a fallback.
	 */
	public static function replaceInTransaction(PDO $pdo, int $userId): array
	{
		if (!$pdo->inTransaction() || $userId < 1) {
			throw new LogicException('Recovery-code replacement requires an active transaction.');
		}

		$table = Database::table('totp_recovery_codes');
		$pdo->prepare("DELETE FROM `{$table}` WHERE `user_id` = ?")->execute([$userId]);
		$stmt = $pdo->prepare(
			"INSERT INTO `{$table}` (`user_id`, `code_hash`, `created_at`) VALUES (?, ?, ?)"
		);
		$now = time();
		$codes = [];
		for ($i = 0; $i < self::BATCH_SIZE; $i++) {
			$code = self::makeCode();
			$hash = password_hash(self::canonical($code), PASSWORD_DEFAULT);
			if ($hash === false || !$stmt->execute([$userId, $hash, $now])) {
				throw new RuntimeException('Could not persist a complete recovery-code batch.');
			}
			$codes[] = $code;
		}
		return $codes;
	}

	/**
	 * Replace the batch and revoke all existing sessions/bearer credentials as one commit.
	 */
	public static function regenerateAndInvalidateAccess(int $userId): array
	{
		$pdo = Database::getInstance();
		if (!$pdo || $userId < 1) {
			return [];
		}
		try {
			$pdo->beginTransaction();
			$codes = self::replaceInTransaction($pdo, $userId);
			if (!UserRepository::invalidateAccessInTransaction($pdo, $userId)) {
				throw new RuntimeException('Could not revoke existing access.');
			}
			$pdo->commit();
			return $codes;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return [];
		}
	}

	/** How many of a user's codes are still unused. */
	public static function remaining(int $userId): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		try {
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM `" . Database::table('totp_recovery_codes') . "`
				WHERE `user_id` = ? AND `used_at` IS NULL");
			$stmt->execute([$userId]);
			return (int) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return 0;
		}
	}

	/**
	 * Spend a recovery code. Returns true only if it matched an unused code, which is then
	 * marked used so it cannot serve twice.
	 *
	 * Every unused hash has to be tried because bcrypt salts each one differently — there is
	 * no way to look a code up by value. The list is at most BATCH_SIZE long, and the whole
	 * path is already rate-limited by the `totp_fail` counter in the caller.
	 *
	 * Marking the row spends it with a conditional UPDATE (`used_at IS NULL`), so two
	 * simultaneous attempts with the same code can never both succeed.
	 */
	public static function consume(int $userId, string $code): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$canonical = self::canonical($code);
		if ($canonical === '') {
			return false;
		}
		$table = Database::table('totp_recovery_codes');

		try {
			$stmt = $pdo->prepare("SELECT `id`, `code_hash` FROM `{$table}` WHERE `user_id` = ? AND `used_at` IS NULL");
			$stmt->execute([$userId]);
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
				if (password_verify($canonical, $row['code_hash'])) {
					$upd = $pdo->prepare("UPDATE `{$table}` SET `used_at` = ? WHERE `id` = ? AND `used_at` IS NULL");
					$upd->execute([time(), (int) $row['id']]);
					return $upd->rowCount() > 0;
				}
			}
			return false;
		} catch (PDOException $e) {
			return false;
		}
	}

	/** Drop every code for a user (used when 2FA is switched off). */
	public static function clear(int $userId): void
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		try {
			$pdo->prepare("DELETE FROM `" . Database::table('totp_recovery_codes') . "` WHERE `user_id` = ?")
				->execute([$userId]);
		} catch (PDOException $e) {
		}
	}

	/** A code in its display form: two dash-separated groups, e.g. "7KFD-Q2WM". */
	private static function makeCode(): string
	{
		$alphabet = self::ALPHABET;
		$max = strlen($alphabet) - 1;
		$out = '';
		for ($i = 0; $i < 8; $i++) {
			if ($i === 4) {
				$out .= '-';
			}
			$out .= $alphabet[random_int(0, $max)];
		}
		return $out;
	}

	/**
	 * Normalise user input before hashing/comparing, so a code still works when typed in
	 * lower case, without the dash, or with stray spaces.
	 */
	private static function canonical(string $code): string
	{
		return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($code))) ?? '';
	}
}
