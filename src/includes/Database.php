<?php

/*
 * Repository layer (Faza 5 · #2). Domain logic is being extracted from this
 * god-object into focused repositories under repositories/. The public
 * Database::* methods remain as thin delegators, so existing callers are
 * unaffected while the internals get organised.
 */
require_once __DIR__ . '/InputLimits.php';
require_once __DIR__ . '/PasswordPolicy.php';
require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/exceptions/AccountAlreadyExistsException.php';
require_once __DIR__ . '/repositories/SettingsRepository.php';
require_once __DIR__ . '/repositories/AuditService.php';
require_once __DIR__ . '/repositories/CaptchaService.php';
require_once __DIR__ . '/repositories/MailService.php';
require_once __DIR__ . '/repositories/TokenRepository.php';
require_once __DIR__ . '/repositories/BanRepository.php';
require_once __DIR__ . '/repositories/ActiveDownloadRepository.php';
require_once __DIR__ . '/repositories/CollectionRepository.php';
require_once __DIR__ . '/repositories/GroupRepository.php';
require_once __DIR__ . '/repositories/ApiKeyRepository.php';
require_once __DIR__ . '/repositories/WebhookRepository.php';
require_once __DIR__ . '/repositories/TrafficRepository.php';
require_once __DIR__ . '/repositories/TransferQuotaRepository.php';
require_once __DIR__ . '/repositories/ReportRepository.php';
require_once __DIR__ . '/repositories/FileRepository.php';
require_once __DIR__ . '/repositories/UserRepository.php';
require_once __DIR__ . '/repositories/RecoveryRepository.php';
require_once __DIR__ . '/repositories/RecoveryCodeRepository.php';
require_once __DIR__ . '/repositories/RememberTokenRepository.php';
require_once __DIR__ . '/repositories/PlanRepository.php';
require_once __DIR__ . '/repositories/PromoCodeRepository.php';
require_once __DIR__ . '/repositories/PaymentPlugins.php';
require_once __DIR__ . '/repositories/PaymentRepository.php';
require_once __DIR__ . '/repositories/NotificationRepository.php';
// The catalogue and the emit/render rules on top of that repository. Everything that can
// announce anything already has Database.php in scope, so this is where it belongs.
require_once __DIR__ . '/Notifications.php';
require_once __DIR__ . '/repositories/PayU.php';
require_once __DIR__ . '/repositories/P24.php';
require_once __DIR__ . '/repositories/PaymentReconciler.php';
require_once __DIR__ . '/repositories/AdRepository.php';
require_once __DIR__ . '/repositories/StorageEnforcer.php';
require_once __DIR__ . '/repositories/RegistrationGuard.php';
require_once __DIR__ . '/Permissions.php';

class Database
{
	public const CURRENT_SCHEMA_VERSION = 64;
	private const SCHEMA_CONTRACT_CACHE_SECONDS = 300;
	private static bool $migrationJournalActive = false;
	private static string $migrationJournalPrefix = '';
	private static int $migrationJournalStep = 0;
	/** @var array<int, bool> */
	private static array $injectedMigrationPublicationFaults = [];
	private static ?PDO $instance = null;

	public static function resetInstance(): void
	{
		self::$instance = null;
	}

	public static function getInstance(): ?PDO
	{
		if (self::$instance === null) {
			if (!defined('DB_HOST') || !defined('DB_NAME') || empty(DB_HOST) || empty(DB_NAME)) {
				return null;
			}

			try {
				$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
				self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
					PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
					PDO::ATTR_EMULATE_PREPARES => false,
				]);
			} catch (PDOException $e) {
				error_log("Database connection failed: " . $e->getMessage());
				return null;
			}
		}

		return self::$instance;
	}

	public static function connectWith(string $host, string $user, string $pass, string $name): ?PDO
	{
		try {
			$dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
			self::$instance = new PDO($dsn, $user, $pass, [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES => false,
			]);
			return self::$instance;
		} catch (PDOException $e) {
			error_log("Database connection failed: " . $e->getMessage());
			return null;
		}
	}

	public static function createTables(string $prefix = '', bool $dropExisting = false): array
	{
		$pdo = self::getInstance();
		if (!$pdo)
			return ['success' => false, 'error' => 'Brak połączenia z bazą danych'];

		if (empty($prefix) && defined('DB_PREFIX')) {
			$prefix = DB_PREFIX;
		}

		try {
			if ($dropExisting) {
				// Reinstall/test reset means a genuinely empty application schema. The old
				// seven-table list left most modern tables and their data behind, producing a
				// hybrid "fresh" install. The caller-side destructive DB guard remains
				// mandatory; this only makes an already-authorized reset complete.
				$tables = [
					'payment_events', 'promo_reservations', 'webhook_deliveries',
					'notification_prefs', 'notifications', 'ad_stats_daily', 'ads',
					'ad_packages', 'payments', 'plans', 'totp_recovery_codes',
					'collection_files', 'collections', 'api_keys', 'webhooks', 'groups',
					'download_reservation_effects', 'download_reservations',
					'transfer_quota_usage',
					'active_downloads', 'active_uploads',
					'download_tokens', 'upload_tokens', 'upload_storage_reservations',
					'file_quarantine', 'file_deletion_queue', 'ad_file_deletion_queue', 'reports', 'files',
					'recovery_tokens', 'recovery_attempts', 'email_reservations',
					'remember_tokens',
					'security_events', 'rate_limits', 'traffic_daily', 'traffic_logs',
					'audit_log', 'blacklists', 'users', 'promo_codes',
					'migration_journal', 'settings', 'admins',
				];
				$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
				try {
					foreach ($tables as $table) {
						$pdo->exec("DROP TABLE IF EXISTS `{$prefix}{$table}`");
					}
				} finally {
					$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
				}
			}

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}files` (
				`id` VARCHAR(32) NOT NULL PRIMARY KEY,
				`original_name` VARCHAR(255) NOT NULL,
				`mime_type` VARCHAR(100) DEFAULT 'application/octet-stream',
				`size` BIGINT UNSIGNED DEFAULT 0,
				`delete_token` VARCHAR(255) NOT NULL,
				`uploaded_at` INT UNSIGNED NOT NULL,
				`uploaded_ip` VARCHAR(45) DEFAULT '',
				`downloads` INT UNSIGNED DEFAULT 0,
				`user_id` INT UNSIGNED DEFAULT NULL,
				INDEX `idx_uploaded_at` (`uploaded_at`),
				INDEX `idx_size` (`size`),
				INDEX `idx_user_id` (`user_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}settings` (
				`setting_key` VARCHAR(50) NOT NULL PRIMARY KEY,
				`setting_value` TEXT
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}file_deletion_queue` (
				`file_id` VARCHAR(32) NOT NULL PRIMARY KEY,
				`attempts` INT UNSIGNED NOT NULL DEFAULT 0,
				`next_attempt_at` INT UNSIGNED NOT NULL DEFAULT 0,
				`last_error` VARCHAR(1000) DEFAULT NULL,
				`created_at` INT UNSIGNED NOT NULL,
				INDEX `idx_deletion_due` (`next_attempt_at`, `created_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}file_quarantine` (
				`file_id` VARCHAR(32) NOT NULL PRIMARY KEY,
				`manifest_json` MEDIUMTEXT NOT NULL,
				`reason` VARCHAR(64) NOT NULL,
				`actor_type` VARCHAR(32) NOT NULL,
				`actor_id` VARCHAR(64) DEFAULT NULL,
				`size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
				`checksum` CHAR(64) DEFAULT NULL,
				`state` VARCHAR(16) NOT NULL DEFAULT 'pending',
				`quarantine_until` INT UNSIGNED NOT NULL,
				`attempts` INT UNSIGNED NOT NULL DEFAULT 0,
				`next_attempt_at` INT UNSIGNED NOT NULL DEFAULT 0,
				`last_error` VARCHAR(1000) DEFAULT NULL,
				`created_at` INT UNSIGNED NOT NULL,
				`updated_at` INT UNSIGNED NOT NULL,
				INDEX `idx_quarantine_due` (`state`, `next_attempt_at`, `quarantine_until`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}ad_file_deletion_queue` (
				`filename` VARCHAR(120) NOT NULL PRIMARY KEY,
				`attempts` INT UNSIGNED NOT NULL DEFAULT 0,
				`next_attempt_at` INT UNSIGNED NOT NULL DEFAULT 0,
				`last_error` VARCHAR(1000) DEFAULT NULL,
				`created_at` INT UNSIGNED NOT NULL,
				INDEX `idx_ad_deletion_due` (`next_attempt_at`, `created_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}upload_storage_reservations` (
				`id` CHAR(32) NOT NULL PRIMARY KEY,
				`user_id` INT UNSIGNED DEFAULT NULL,
				`size` BIGINT UNSIGNED NOT NULL,
				`expires_at` INT UNSIGNED NOT NULL,
				`created_at` INT UNSIGNED NOT NULL,
				INDEX `idx_upload_reservation_expiry` (`expires_at`),
				INDEX `idx_upload_reservation_user` (`user_id`, `expires_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");



			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}users` (
				`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				`username` VARCHAR(50) NOT NULL UNIQUE,
				`email` VARCHAR(255) NOT NULL UNIQUE,
				`password_hash` VARCHAR(255) NOT NULL,
				`role` VARCHAR(20) DEFAULT 'user',
				`is_active` TINYINT(1) DEFAULT 1,
				`session_version` INT UNSIGNED NOT NULL DEFAULT 1,
				-- 0 = no per-account override; the account's group quota applies (pt 5). This
				-- used to default to 500 MiB, which every new account then carried as a silent
				-- override of whatever its group allowed.
				`storage_limit` BIGINT UNSIGNED DEFAULT 0,
				`pending_email` VARCHAR(255) DEFAULT NULL,
				`email_change_token` VARCHAR(64) DEFAULT NULL,
				`email_change_expires_at` INT UNSIGNED DEFAULT NULL,
				`email_change_stage` VARCHAR(8) DEFAULT NULL,
				`last_email_change_at` INT UNSIGNED DEFAULT 0,
				`activation_token` VARCHAR(64) DEFAULT NULL,
				`activation_expires_at` INT UNSIGNED DEFAULT NULL,
				`last_activation_email_at` INT UNSIGNED DEFAULT 0,
				`group_payment_ext_order_id` VARCHAR(64) DEFAULT NULL,
				`registered_ip` VARCHAR(45) DEFAULT NULL,
				`created_at` INT UNSIGNED NOT NULL,
				INDEX `idx_email` (`email`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}upload_tokens` (
				`token` VARCHAR(64) NOT NULL PRIMARY KEY,
				`ip_address` VARCHAR(45) NOT NULL,
				`user_id` INT UNSIGNED DEFAULT NULL,
				`created_at` INT UNSIGNED NOT NULL,
				`files_count` INT UNSIGNED DEFAULT 0,
				INDEX `idx_created` (`created_at`),
				INDEX `idx_ip` (`ip_address`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}blacklists` (
				`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				`type` ENUM('ip', 'email', 'username') NOT NULL,
				`value` VARCHAR(255) NOT NULL,
				`created_at` INT UNSIGNED NOT NULL,
				`expires_at` INT UNSIGNED DEFAULT NULL,
				`reason` VARCHAR(255) DEFAULT '',
				UNIQUE KEY `unique_entry` (`type`, `value`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}reports` (
				`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				`file_id` VARCHAR(32) NOT NULL,
				`reporter_name` VARCHAR(100) NOT NULL,
				`reporter_email` VARCHAR(255) NOT NULL,
				`reporter_entity` VARCHAR(255) DEFAULT '',
				`reporter_org` VARCHAR(255) DEFAULT '',
				`report_title` VARCHAR(255) NOT NULL,
				`report_link` VARCHAR(255) DEFAULT '',
				`additional_info` TEXT,
				`created_at` INT UNSIGNED NOT NULL,
				`ip_address` VARCHAR(45) NOT NULL,
				INDEX `idx_file_id` (`file_id`),
				INDEX `idx_created` (`created_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}download_tokens` (
				`token` VARCHAR(64) NOT NULL PRIMARY KEY,
				`file_id` VARCHAR(32) NOT NULL,
				`ip_address` VARCHAR(45) NOT NULL,
				`user_id` INT UNSIGNED DEFAULT NULL,
				`created_at` INT UNSIGNED NOT NULL,
				`used` TINYINT(1) DEFAULT 0,
				INDEX `idx_created` (`created_at`),
				INDEX `idx_file` (`file_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}active_downloads` (
				`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				`ip_address` VARCHAR(45) NOT NULL,
				`file_id` VARCHAR(32) NOT NULL,
				`started_at` INT UNSIGNED NOT NULL,
				`instance_id` CHAR(32) NOT NULL DEFAULT '',
				`heartbeat_at` INT UNSIGNED NOT NULL DEFAULT 0,
				INDEX `idx_ip` (`ip_address`),
				INDEX `idx_started` (`started_at`),
				INDEX `idx_active_heartbeat` (`heartbeat_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}download_reservations` (
				`id` CHAR(32) NOT NULL PRIMARY KEY,
				`resource_type` VARCHAR(16) NOT NULL,
				`resource_id` VARCHAR(64) NOT NULL,
				`token_fingerprint` CHAR(64) NOT NULL,
				`active_download_id` INT UNSIGNED DEFAULT NULL,
				`user_id` INT UNSIGNED DEFAULT NULL,
				`ip_address` VARCHAR(45) NOT NULL,
				`state` VARCHAR(12) NOT NULL DEFAULT 'reserved',
				`bytes_sent` BIGINT UNSIGNED NOT NULL DEFAULT 0,
				`lease_until` INT UNSIGNED NOT NULL,
				`created_at` INT UNSIGNED NOT NULL,
				`started_at` INT UNSIGNED DEFAULT NULL,
				`finished_at` INT UNSIGNED DEFAULT NULL,
				`updated_at` INT UNSIGNED NOT NULL,
				INDEX `idx_download_reservation_lease` (`state`, `lease_until`),
				INDEX `idx_download_reservation_resource` (`resource_type`, `resource_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}recovery_tokens` (
                `token` VARCHAR(64) NOT NULL PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `created_at` INT UNSIGNED NOT NULL,
                `expires_at` INT UNSIGNED NOT NULL,
                UNIQUE INDEX `uniq_recovery_user` (`user_id`),
                INDEX `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}recovery_attempts` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `ip_address` VARCHAR(45) NOT NULL,
                `attempted_at` INT UNSIGNED NOT NULL,
                INDEX `idx_ip_time` (`ip_address`, `attempted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}security_events` (
				`ip_address` VARCHAR(45) NOT NULL,
				`event_type` VARCHAR(32) NOT NULL,
				`counter` INT UNSIGNED DEFAULT 0,
				`last_updated_at` INT UNSIGNED NOT NULL,
				PRIMARY KEY (`ip_address`, `event_type`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}traffic_logs` (
				`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				`ip_address` VARCHAR(45) NOT NULL,
				`transfer_size` BIGINT UNSIGNED DEFAULT 0,
				`transfer_type` ENUM('upload', 'download') NOT NULL,
				`file_id` VARCHAR(32) DEFAULT NULL,
				`user_id` INT UNSIGNED DEFAULT NULL,
				`created_at` INT UNSIGNED NOT NULL,
				INDEX `idx_ip` (`ip_address`),
				INDEX `idx_created` (`created_at`),
				INDEX `idx_type` (`transfer_type`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}traffic_daily` (
				`day` DATE NOT NULL,
				`transfer_type` ENUM('upload', 'download') NOT NULL,
				`transfer_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
				`transfer_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (`day`, `transfer_type`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			// Ensure columns exist for updates
			try {
				$pdo->exec("ALTER TABLE `{$prefix}blacklists` ADD COLUMN `expires_at` INT UNSIGNED DEFAULT NULL AFTER `created_at`");
			} catch (Exception $e) {
			}
			try {
				$pdo->exec("ALTER TABLE `{$prefix}blacklists` ADD COLUMN `reason` VARCHAR(255) DEFAULT '' AFTER `expires_at`");
			} catch (Exception $e) {
			}
			try {
				$pdo->exec("ALTER TABLE `{$prefix}download_tokens` ADD COLUMN `user_id` INT UNSIGNED DEFAULT NULL AFTER `ip_address`");
				$pdo->exec("ALTER TABLE `{$prefix}download_tokens` ADD INDEX `idx_user_id` (`user_id`)");
			} catch (Exception $e) {
			}
			try {
				$pdo->exec("ALTER TABLE `{$prefix}users` ADD COLUMN `activation_token` VARCHAR(64) DEFAULT NULL AFTER `is_active`");
			} catch (Exception $e) {
			}
			try {
				$pdo->exec("ALTER TABLE `{$prefix}users` ADD COLUMN `last_activation_email_at` INT UNSIGNED DEFAULT 0 AFTER `activation_token`");
			} catch (Exception $e) {
			}
			try {
				$pdo->exec("ALTER TABLE `{$prefix}users` ADD COLUMN `pending_email` VARCHAR(255) DEFAULT NULL AFTER `email`");
			} catch (Exception $e) {
			}
			try {
				$pdo->exec("ALTER TABLE `{$prefix}users` ADD COLUMN `email_change_token` VARCHAR(64) DEFAULT NULL AFTER `pending_email`");
			} catch (Exception $e) {
			}
			try {
				$pdo->exec("ALTER TABLE `{$prefix}users` ADD COLUMN `last_email_change_at` INT UNSIGNED DEFAULT 0 AFTER `email_change_token`");
			} catch (Exception $e) {
			}

			self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}audit_log` (
				`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				`user_id` INT UNSIGNED DEFAULT NULL,
				`username` VARCHAR(64) DEFAULT NULL,
				`action` VARCHAR(64) NOT NULL,
				`details` VARCHAR(512) DEFAULT '',
				`ip_address` VARCHAR(45) DEFAULT '',
				`created_at` INT UNSIGNED NOT NULL,
				INDEX `idx_created` (`created_at`),
				INDEX `idx_action` (`action`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

			// Per-file sharing controls: expiry, download cap, optional password, one-time link.
			foreach ([
				"ADD COLUMN `expires_at` INT UNSIGNED DEFAULT NULL",
				"ADD COLUMN `max_downloads` INT UNSIGNED DEFAULT NULL",
				"ADD COLUMN `password_hash` VARCHAR(255) DEFAULT NULL",
				"ADD COLUMN `one_time` TINYINT(1) DEFAULT 0",
				"ADD COLUMN `consumed_at` INT UNSIGNED DEFAULT NULL",
			] as $col) {
				try {
					$pdo->exec("ALTER TABLE `{$prefix}files` $col");
				} catch (Exception $e) {
				}
			}

			return ['success' => true];
		} catch (PDOException $e) {
			error_log("Failed to create tables: " . $e->getMessage());
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	public static function table(string $name): string
	{
		$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
		return $prefix . $name;
	}

	public static function insertDefaultSettings(array $settings, string $prefix = ''): bool
	{
		$pdo = self::getInstance();
		if (!$pdo)
			return false;

		if (empty($prefix) && defined('DB_PREFIX')) {
			$prefix = DB_PREFIX;
		}
		$table = $prefix . 'settings';

		try {
			$stmt = $pdo->prepare("INSERT INTO `{$table}` (`setting_key`, `setting_value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)");

			foreach ($settings as $key => $value) {
				$stmt->execute([$key, $value]);
			}

			self::invalidateSettingsCache();
			return true;
		} catch (PDOException $e) {
			error_log("Failed to insert settings: " . $e->getMessage());
			return false;
		}
	}

	/* Admin bootstrap — delegated to UserRepository (Faza 5 · #2). */
	public static function createAdmin(string $username, string $password, string $email, string $prefix = ''): array
	{
		return UserRepository::createAdmin($username, $password, $email, $prefix);
	}

	/* Settings — delegated to SettingsRepository (Faza 5 · #2). */

	/** Drop both the in-process and on-disk settings caches. */
	public static function invalidateSettingsCache(): void
	{
		SettingsRepository::invalidateCache();
	}

	/** Re-read settings on the next access without discarding the shared on-disk cache. */
	public static function forgetLocalSettingsCache(): void
	{
		SettingsRepository::forgetLocalCache();
	}

	public static function getSetting(string $key, $default = null)
	{
		return SettingsRepository::get($key, $default);
	}

	/**
	 * Read a secret setting, transparently decrypting it (S12). Use for values stored
	 * via setSecretSetting() (e.g. `smtp_pass`); legacy plaintext is returned as-is.
	 */
	public static function getSecretSetting(string $key, $default = null)
	{
		return SettingsRepository::getSecret($key, $default);
	}

	/** Store a secret setting encrypted at rest (S12). Empty value clears it. */
	public static function setSecretSetting(string $key, string $value): bool
	{
		return SettingsRepository::setSecret($key, $value);
	}

	public static function setSetting(string $key, $value): bool
	{
		$saved = SettingsRepository::set($key, $value);
		if ($saved && $key === 'schema_version' && self::$migrationJournalActive) {
			$version = (int) $value;
			self::completeMigrationJournalStep($version);
			if ($version < self::CURRENT_SCHEMA_VERSION) {
				self::startMigrationJournalStep($version + 1);
			}
		}
		return $saved;
	}

	private static function migrationJournalTable(): string
	{
		return self::$migrationJournalPrefix . 'migration_journal';
	}

	private static function initialiseMigrationJournal(PDO $pdo, string $prefix, int $version): void
	{
		self::$migrationJournalPrefix = $prefix;
		$table = self::migrationJournalTable();
		self::createOrRepairTable(
			$pdo,
			"CREATE TABLE IF NOT EXISTS `{$table}` (
			 `version` INT UNSIGNED NOT NULL PRIMARY KEY,
			 `status` VARCHAR(16) NOT NULL,
			 `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
			 `started_at` INT UNSIGNED DEFAULT NULL,
			 `finished_at` INT UNSIGNED DEFAULT NULL,
			 `last_error` VARCHAR(1000) DEFAULT NULL
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);
		$reconcile = $pdo->prepare(
			"UPDATE `{$table}` SET `status` = 'completed',
			 `finished_at` = COALESCE(`finished_at`, ?), `last_error` = NULL
			 WHERE `version` <= ? AND `status` <> 'completed'"
		);
		$reconcile->execute([time(), $version]);
		self::$migrationJournalActive = true;
		$target = $version < self::CURRENT_SCHEMA_VERSION ? $version + 1 : $version;
		self::startMigrationJournalStep($target);
	}

	private static function startMigrationJournalStep(int $version, bool $force = false): void
	{
		if (!self::$migrationJournalActive || $version < 1 || $version > self::CURRENT_SCHEMA_VERSION) {
			return;
		}
		self::$migrationJournalStep = $version;
		$pdo = self::getInstance();
		if (!$pdo) {
			throw new RuntimeException('Migration journal database is unavailable.');
		}
		$table = self::migrationJournalTable();
		$read = $pdo->prepare("SELECT `status` FROM `{$table}` WHERE `version` = ?");
		$read->execute([$version]);
		if ($read->fetchColumn() === 'completed' && !$force) {
			return;
		}
		$stmt = $pdo->prepare(
			"INSERT INTO `{$table}`
			 (`version`, `status`, `attempts`, `started_at`, `finished_at`, `last_error`)
			 VALUES (?, 'running', 1, ?, NULL, NULL)
			 ON DUPLICATE KEY UPDATE `status` = 'running', `attempts` = `attempts` + 1,
			  `started_at` = VALUES(`started_at`), `finished_at` = NULL, `last_error` = NULL"
		);
		$stmt->execute([$version, time()]);
	}

	private static function completeMigrationJournalStep(int $version): void
	{
		if (!self::$migrationJournalActive || $version < 1) {
			return;
		}
		$pdo = self::getInstance();
		if (!$pdo) {
			throw new RuntimeException('Migration journal database is unavailable.');
		}
		$table = self::migrationJournalTable();
		$stmt = $pdo->prepare(
			"INSERT INTO `{$table}`
			 (`version`, `status`, `attempts`, `started_at`, `finished_at`, `last_error`)
			 VALUES (?, 'completed', 1, ?, ?, NULL)
			 ON DUPLICATE KEY UPDATE `status` = 'completed',
			  `finished_at` = VALUES(`finished_at`), `last_error` = NULL"
		);
		$now = time();
		$stmt->execute([$version, $now, $now]);
	}

	private static function failMigrationJournalStep(int $version, Throwable $error): void
	{
		if (!self::$migrationJournalActive || $version < 1) {
			return;
		}
		try {
			$pdo = self::getInstance();
			if (!$pdo) {
				return;
			}
			$table = self::migrationJournalTable();
			$stmt = $pdo->prepare(
				"INSERT INTO `{$table}`
				 (`version`, `status`, `attempts`, `started_at`, `finished_at`, `last_error`)
				 VALUES (?, 'failed', 1, ?, ?, ?)
				 ON DUPLICATE KEY UPDATE `status` = 'failed',
				  `finished_at` = VALUES(`finished_at`), `last_error` = VALUES(`last_error`)"
			);
			$now = time();
			$stmt->execute([
				$version,
				$now,
				$now,
				mb_substr($error->getMessage(), 0, 1000),
			]);
		} catch (Throwable $journalError) {
			error_log('Could not record migration failure: ' . $journalError->getMessage());
		}
	}

	/**
	 * Legacy migrations were intentionally idempotent, but their empty catch blocks also hid
	 * disk, permission, syntax and connection failures. Only errors that mean an ALTER/CREATE/
	 * DROP already reached the requested shape are safe to continue past.
	 */
	private static function tolerateIdempotentDdlError(PDOException $error): void
	{
		$driverCode = (int) ($error->errorInfo[1] ?? 0);
		$knownIdempotentCodes = [
			1050, // table already exists
			1060, // duplicate column
			1061, // duplicate key/index name
			1091, // cannot drop missing column/key
		];
		if (in_array($driverCode, $knownIdempotentCodes, true)) {
			return;
		}
		throw $error;
	}

	/**
	 * Execute a historical CREATE TABLE idempotently and repair an interrupted, partially
	 * created table from that statement. CREATE TABLE IF NOT EXISTS alone only checks the
	 * table name, so it silently leaves missing columns and indexes behind.
	 *
	 * The desired definition is materialised under a short random name, read back through
	 * SHOW CREATE TABLE (which normalises MariaDB/MySQL syntax), then only missing artifacts
	 * are appended to the real table. Existing columns and indexes are never rewritten by an
	 * older migration; later steps remain responsible for their own intentional changes.
	 */
	private static function createOrRepairTable(PDO $pdo, string $createSql): void
	{
		if (!preg_match(
			'/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`([^`]+)`/i',
			$createSql,
			$match
		)) {
			throw new InvalidArgumentException(
				'Repairable table definition must use CREATE TABLE IF NOT EXISTS with a quoted name.'
			);
		}

		$table = (string) $match[1];
		$exists = $pdo->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES '
			. 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
		);
		$exists->execute([$table]);
		if ((int) $exists->fetchColumn() === 0) {
			$pdo->exec($createSql);
			return;
		}

		$shadow = '__fh_repair_' . bin2hex(random_bytes(8));
		$quotedTable = '`' . $table . '`';
		$tablePosition = strpos($createSql, $quotedTable);
		if ($tablePosition === false) {
			throw new RuntimeException("Could not locate table {$table} in its CREATE statement.");
		}
		$shadowSql = substr_replace(
			$createSql,
			'`' . $shadow . '`',
			$tablePosition,
			strlen($quotedTable)
		);

		try {
			$pdo->exec($shadowSql);
			$shadowCreate = $pdo->query("SHOW CREATE TABLE `{$shadow}`")->fetch(PDO::FETCH_NUM);
			if (!is_array($shadowCreate) || !isset($shadowCreate[1])) {
				throw new RuntimeException("Could not inspect repair definition for {$table}.");
			}

			$targetColumns = $pdo->query("SHOW COLUMNS FROM `{$table}`")
				->fetchAll(PDO::FETCH_COLUMN);
			$targetColumnSet = array_fill_keys(array_map('strtolower', $targetColumns), true);
			$targetIndexes = $pdo->query("SHOW INDEX FROM `{$table}`")
				->fetchAll(PDO::FETCH_ASSOC);
			$targetIndexSet = [];
			foreach ($targetIndexes as $index) {
				$targetIndexSet[strtolower((string) $index['Key_name'])] = true;
			}

			$missingColumns = [];
			$missingIndexes = [];
			foreach (preg_split('/\R/', (string) $shadowCreate[1]) ?: [] as $line) {
				$definition = rtrim(trim($line), ',');
				if (preg_match('/^`([^`]+)`\s+.+$/', $definition, $columnMatch)) {
					$name = strtolower((string) $columnMatch[1]);
					if (!isset($targetColumnSet[$name])) {
						$missingColumns[] = $definition;
						$targetColumnSet[$name] = true;
					}
					continue;
				}
				if (!preg_match(
					'/^(PRIMARY KEY|UNIQUE KEY `([^`]+)`|KEY `([^`]+)`|FULLTEXT KEY `([^`]+)`|SPATIAL KEY `([^`]+)`)\s+/i',
					$definition,
					$indexMatch
				)) {
					continue;
				}
				$name = strcasecmp((string) $indexMatch[1], 'PRIMARY KEY') === 0
					? 'primary'
					: strtolower((string) ($indexMatch[2] ?: $indexMatch[3]
						?: $indexMatch[4] ?: $indexMatch[5]));
				if (!isset($targetIndexSet[$name])) {
					$missingIndexes[] = $definition;
					$targetIndexSet[$name] = true;
				}
			}

			foreach ($missingColumns as $definition) {
				$pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
			}
			foreach ($missingIndexes as $definition) {
				$pdo->exec("ALTER TABLE `{$table}` ADD {$definition}");
			}
		} finally {
			$pdo->exec("DROP TABLE IF EXISTS `{$shadow}`");
		}
	}

	private static function persistMigrationVersion(int $version): void
	{
		$pdo = self::getInstance();
		if (!$pdo) {
			throw new RuntimeException('Migration contract database is unavailable.');
		}
		$current = (int) self::getSetting('schema_version', 0);
		self::repairMigrationContractIndexes(
			$pdo,
			self::$migrationJournalPrefix,
			$version
		);
		self::assertMigrationStepContract($pdo, self::$migrationJournalPrefix, $version);
		if ($current < $version) {
			$postconditionProblems = self::migrationStepPostconditionProblems(
				$pdo,
				self::$migrationJournalPrefix,
				$version
			);
			if ($postconditionProblems !== []) {
				throw new RuntimeException(
					"Migration {$version} data postcondition failed: "
					. implode('; ', $postconditionProblems)
				);
			}
		}
		if ($current < $version) {
			self::injectMigrationPublicationFaultForTests($version);
			if (!self::setSetting('schema_version', (string) $version)) {
				throw new RuntimeException("Could not persist schema_version={$version}.");
			}
		} else {
			// Forward-repair of an already published version must never downgrade the schema.
			self::completeMigrationJournalStep($version);
		}
	}

	/**
	 * Rebuild only indexes owned by the current migration contract. This covers interrupted
	 * ADD INDEX operations and an index that exists under the right name but with wrong
	 * columns or without its required UNIQUE property.
	 */
	private static function repairMigrationContractIndexes(
		PDO $pdo,
		string $prefix,
		int $version
	): void {
		$contract = self::migrationStepContracts()[$version] ?? [];
		$uniqueIndexes = $contract['unique_indexes'] ?? [];
		foreach (($contract['indexes'] ?? []) as $logicalTable => $requiredIndexes) {
			$table = $prefix . $logicalTable;
			$rows = $pdo->prepare(
				'SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE '
				. 'FROM information_schema.STATISTICS '
				. 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
				. 'ORDER BY INDEX_NAME, SEQ_IN_INDEX'
			);
			$rows->execute([$table]);
			$actualColumns = [];
			$actualUnique = [];
			foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
				$name = (string) $row['INDEX_NAME'];
				$actualColumns[$name][] = (string) $row['COLUMN_NAME'];
				$actualUnique[$name] = (int) $row['NON_UNIQUE'] === 0;
			}

			$availableColumns = $pdo->prepare(
				'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
				. 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
			);
			$availableColumns->execute([$table]);
			$availableColumnSet = array_fill_keys(
				$availableColumns->fetchAll(PDO::FETCH_COLUMN),
				true
			);

			foreach ($requiredIndexes as $name => $columns) {
				$mustBeUnique = $name === 'PRIMARY'
					|| in_array($name, $uniqueIndexes[$logicalTable] ?? [], true);
				$matches = ($actualColumns[$name] ?? null) === $columns
					&& (!$mustBeUnique || ($actualUnique[$name] ?? false));
				if ($matches) {
					continue;
				}
				foreach ($columns as $column) {
					if (!isset($availableColumnSet[$column])) {
						// The step body/contract reports the more useful missing-column error.
						continue 2;
					}
				}

				if (isset($actualColumns[$name])) {
					$pdo->exec(
						$name === 'PRIMARY'
							? "ALTER TABLE `{$table}` DROP PRIMARY KEY"
							: "ALTER TABLE `{$table}` DROP INDEX `{$name}`"
					);
				}
				$quotedColumns = implode(
					', ',
					array_map(static fn(string $column): string => "`{$column}`", $columns)
				);
				if ($name === 'PRIMARY') {
					$pdo->exec(
						"ALTER TABLE `{$table}` ADD PRIMARY KEY ({$quotedColumns})"
					);
				} else {
					$unique = $mustBeUnique ? 'UNIQUE ' : '';
					$pdo->exec(
						"ALTER TABLE `{$table}` ADD {$unique}INDEX `{$name}` "
						. "({$quotedColumns})"
					);
				}
			}
		}
	}

	/**
	 * PHPUnit-only failure point at the exact boundary between a verified migration and
	 * publishing its number. An environment variable alone can never enable this in the app.
	 */
	private static function injectMigrationPublicationFaultForTests(int $version): void
	{
		if (!defined('FILEHOST_TESTING') || FILEHOST_TESTING !== true) {
			return;
		}
		$requested = getenv('FILEHOST_TEST_FAIL_MIGRATION_BEFORE_PUBLISH');
		if ($requested === false || !ctype_digit($requested)
			|| (int) $requested !== $version
			|| isset(self::$injectedMigrationPublicationFaults[$version])) {
			return;
		}
		self::$injectedMigrationPublicationFaults[$version] = true;
		throw new RuntimeException(
			"Injected migration {$version} failure before schema-version publication."
		);
	}

	/**
	 * One-time data transitions may initialize operator-editable values. They are verified
	 * while upgrading, but deliberately excluded from later drift/forward-repair checks.
	 *
	 * @return string[]
	 */
	private static function migrationStepPostconditionProblems(
		PDO $pdo,
		string $prefix,
		int $version
	): array {
		$problems = [];
		switch ($version) {
			case 21:
				$count = (int) $pdo->query(
					"SELECT COUNT(*) FROM `{$prefix}users` "
					. "WHERE `storage_limit` = 524288000"
				)->fetchColumn();
				if ($count !== 0) {
					$problems[] = 'legacy per-user storage defaults remain';
				}
				break;
			case 24:
				$required = [
					'myfiles.collections',
					'myfiles.coll_create',
					'myfiles.coll_add',
					'myfiles.coll_delete',
				];
				foreach ($pdo->query(
					"SELECT `id`, `permissions` FROM `{$prefix}groups` "
					. "WHERE COALESCE(`slug`, '') <> 'guest'"
				)->fetchAll(PDO::FETCH_ASSOC) as $row) {
					$permissions = array_filter(array_map(
						'trim',
						explode(',', (string) $row['permissions'])
					));
					if (array_diff($required, $permissions) !== []) {
						$problems[] = "collection permissions missing for group {$row['id']}";
					}
				}
				break;
			case 28:
				$count = (int) $pdo->query(
					"SELECT COUNT(DISTINCT `kind`) FROM `{$prefix}plans` "
					. "WHERE `kind` IN ('free', 'guest') AND `is_system` = 1"
				)->fetchColumn();
				if ($count !== 2) {
					$problems[] = 'free/guest system plans were not initialized';
				}
				break;
			case 31:
				foreach ($pdo->query(
					"SELECT `id`, `permissions` FROM `{$prefix}groups` "
					. "WHERE COALESCE(`slug`, '') <> 'guest'"
				)->fetchAll(PDO::FETCH_ASSOC) as $row) {
					$permissions = array_filter(array_map(
						'trim',
						explode(',', (string) $row['permissions'])
					));
					if (!in_array('ads.buy', $permissions, true)) {
						$problems[] = "ads.buy permission missing for group {$row['id']}";
					}
				}
				break;
		}
		return $problems;
	}

	/**
	 * Structural postconditions introduced by each migration. Data-only transitions have
	 * explicit checks below when their invariant is immutable; operator-editable data is not
	 * mistaken for schema damage.
	 *
	 * @return array<int, array<string, array<string, array<int|string, mixed>>>>
	 */
	private static function migrationStepContracts(): array
	{
		return [
			2 => ['columns' => [
				'audit_log' => ['id', 'action', 'created_at'],
				'files' => ['expires_at', 'max_downloads', 'password_hash'],
			], 'indexes' => [
				'audit_log' => [
					'PRIMARY' => ['id'],
					'idx_created' => ['created_at'],
					'idx_action' => ['action'],
				],
			]],
			3 => [],
			4 => [
				'columns' => [
					'groups' => [
						'id', 'name', 'max_file_size_mb', 'max_files_per_session',
						'storage_quota_mb', 'limit_upload', 'limit_download',
						'concurrent_downloads', 'concurrent_connections_per_file',
						'is_default', 'created_at',
					],
					'users' => ['group_id'],
				],
				'indexes' => [
					'groups' => [
						'PRIMARY' => ['id'],
						'name' => ['name'],
						'idx_is_default' => ['is_default'],
					],
					'users' => ['idx_group_id' => ['group_id']],
				],
				'unique_indexes' => ['groups' => ['name']],
			],
			5 => ['columns' => ['files' => ['one_time', 'consumed_at']]],
			6 => [
				'columns' => [
					'collections' => [
						'id', 'name', 'user_id', 'delete_token', 'downloads', 'created_at',
					],
					'collection_files' => ['collection_id', 'file_id', 'position'],
				],
				'indexes' => [
					'collections' => [
						'PRIMARY' => ['id'],
						'idx_user_id' => ['user_id'],
						'idx_created' => ['created_at'],
					],
					'collection_files' => [
						'PRIMARY' => ['collection_id', 'file_id'],
						'idx_file' => ['file_id'],
					],
				],
			],
			7 => [
				'columns' => [
					'api_keys' => ['id', 'user_id', 'key_hash', 'key_prefix', 'created_at'],
				],
				'indexes' => ['api_keys' => [
					'PRIMARY' => ['id'],
					'uniq_hash' => ['key_hash'],
					'idx_user' => ['user_id'],
				]],
				'unique_indexes' => ['api_keys' => ['uniq_hash']],
			],
			8 => [
				'columns' => [
					'webhooks' => ['id', 'user_id', 'url', 'secret', 'events', 'is_active'],
					'webhook_deliveries' => [
						'id', 'webhook_id', 'event', 'payload', 'attempts', 'status',
						'next_attempt_at', 'created_at',
					],
				],
				'indexes' => [
					'webhooks' => [
						'PRIMARY' => ['id'],
						'idx_user' => ['user_id'],
					],
					'webhook_deliveries' => [
						'PRIMARY' => ['id'],
						'idx_pending' => ['status', 'next_attempt_at'],
						'idx_webhook' => ['webhook_id'],
					],
				],
			],
			9 => ['columns' => ['users' => ['totp_secret', 'totp_enabled']]],
			10 => [
				'columns' => ['rate_limits' => ['bucket', 'window_start', 'hits']],
				'indexes' => ['rate_limits' => [
					'PRIMARY' => ['bucket'],
					'idx_window' => ['window_start'],
				]],
			],
			11 => [],
			12 => [],
			13 => ['columns' => [
				'collections' => [
					'password_hash', 'expires_at', 'max_downloads', 'one_time', 'consumed_at',
				],
			]],
			14 => [
				'columns' => ['groups' => ['slug', 'is_system', 'permissions']],
				'indexes' => ['groups' => ['uniq_slug' => ['slug']]],
			],
			15 => [
				'columns' => ['totp_recovery_codes' => [
					'id', 'user_id', 'code_hash', 'created_at', 'used_at',
				]],
				'indexes' => ['totp_recovery_codes' => [
					'PRIMARY' => ['id'],
					'idx_user' => ['user_id'],
					'idx_user_unused' => ['user_id', 'used_at'],
				]],
			],
			16 => ['columns' => ['users' => ['language']]],
			17 => ['columns' => [
				'files' => ['on_limit_action'],
				'collections' => ['on_limit_action'],
			]],
			18 => ['columns' => [
				'plans' => [
					'id', 'name', 'group_id', 'price', 'duration_days', 'description',
					'features', 'enabled', 'sort_order', 'created_at',
				],
				'users' => ['group_expires_at'],
			], 'indexes' => ['plans' => [
				'PRIMARY' => ['id'],
				'idx_enabled' => ['enabled'],
			]]],
			19 => ['columns' => ['users' => ['limits_over_since']]],
			20 => [
				'columns' => [
					'users' => ['registered_ip'],
					'email_reservations' => ['id', 'email', 'user_id', 'released_at'],
				],
				'indexes' => [
					'users' => ['idx_registered_ip' => ['registered_ip']],
					'email_reservations' => [
						'PRIMARY' => ['id'],
						'uniq_email' => ['email'],
						'idx_released' => ['released_at'],
					],
				],
				'unique_indexes' => ['email_reservations' => ['uniq_email']],
			],
			21 => [],
			22 => ['columns' => [
				'groups' => ['auto_delete_days'],
				'users' => ['group_changed_at'],
			]],
			23 => ['columns' => [
				'plans' => ['amount_minor', 'currency'],
				'payments' => [
					'id', 'ext_order_id', 'provider', 'plan_id', 'user_id', 'amount_minor',
					'currency', 'status', 'granted_at', 'created_at', 'updated_at',
				],
			], 'indexes' => ['payments' => [
				'PRIMARY' => ['id'],
				'uniq_ext_order' => ['ext_order_id'],
				'idx_user' => ['user_id'],
				'idx_status' => ['status'],
			]], 'unique_indexes' => ['payments' => ['uniq_ext_order']]],
			24 => [],
			25 => [
				'columns' => ['payments' => ['kind', 'actor_id']],
				'indexes' => ['payments' => ['idx_kind' => ['kind']]],
			],
			26 => ['columns' => ['plans' => ['kind', 'show_limits']]],
			27 => ['columns' => [
				'notifications' => [
					'id', 'user_id', 'type', 'group_key', 'subject', 'link', 'data',
					'count', 'created_at', 'updated_at', 'read_at',
				],
				'notification_prefs' => ['user_id', 'prefs', 'updated_at'],
			], 'indexes' => [
				'notifications' => [
					'PRIMARY' => ['id'],
					'idx_user_time' => ['user_id', 'updated_at'],
					'idx_user_unread' => ['user_id', 'read_at'],
					'idx_stack' => ['user_id', 'group_key', 'read_at'],
				],
				'notification_prefs' => ['PRIMARY' => ['user_id']],
			]],
			28 => ['columns' => ['plans' => ['auto_content', 'is_system']]],
			29 => [
				'columns' => [
					'active_uploads' => ['id', 'ip_address', 'user_id', 'filename', 'started_at'],
				],
				'indexes' => ['active_uploads' => [
					'PRIMARY' => ['id'],
					'idx_started' => ['started_at'],
					'idx_updated' => ['updated_at'],
				]],
			],
			30 => [
				'columns' => [
					'ads' => ['id', 'owner_id', 'status', 'package_id', 'image_path', 'created_at'],
					'ad_packages' => ['id', 'name', 'amount_minor', 'currency', 'duration_days'],
					'ad_stats_daily' => ['day', 'ad_id', 'impressions', 'clicks'],
					'payments' => ['ad_id'],
				],
				'indexes' => [
					'ads' => [
						'PRIMARY' => ['id'],
						'idx_zone_status' => ['zone', 'status'],
						'idx_owner' => ['owner_id'],
						'idx_status' => ['status'],
					],
					'ad_packages' => ['PRIMARY' => ['id']],
					'ad_stats_daily' => ['PRIMARY' => ['ad_id', 'day']],
					'payments' => ['idx_ad' => ['ad_id']],
				],
			],
			31 => ['columns' => [
				'ad_packages' => ['kind', 'priority', 'weight_bonus'],
				'ads' => ['boost_weight', 'boost_until'],
				'payments' => ['package_id'],
			]],
			32 => ['columns' => [
				'ads' => ['parent_ad_id', 'self_paused', 'resubmitted_at'],
				'ad_packages' => ['addon_zones'],
				'users' => ['notification_seen_at'],
			], 'indexes' => ['ads' => ['idx_parent' => ['parent_ad_id']]]],
			33 => ['columns' => ['payments' => ['meta']]],
			34 => [],
			35 => [],
			36 => [
				'columns' => [
					'promo_codes' => [
						'id', 'code', 'percent_off', 'max_uses', 'used_count',
						'expires_at', 'enabled', 'created_at',
					],
				],
				'indexes' => ['promo_codes' => [
					'PRIMARY' => ['id'],
					'uniq_code' => ['code'],
				]],
				'unique_indexes' => ['promo_codes' => ['uniq_code']],
			],
			37 => [
				'columns' => ['payments' => [
					'processing_token', 'processing_started_at', 'processing_expires_at',
					'fulfillment_attempts', 'fulfillment_last_error',
				]],
				'indexes' => ['payments' => [
					'idx_fulfillment_lease' => ['status', 'processing_expires_at', 'granted_at'],
				]],
			],
			38 => ['columns' => ['users' => ['session_version']]],
			39 => [],
			40 => [
				'columns' => [
					'users' => [
						'activation_token', 'activation_expires_at',
						'last_activation_email_at', 'email_change_expires_at',
					],
					'recovery_tokens' => ['token', 'user_id', 'created_at', 'expires_at'],
				],
				'indexes' => ['recovery_tokens' => ['uniq_recovery_user' => ['user_id']]],
				'unique_indexes' => ['recovery_tokens' => ['uniq_recovery_user']],
			],
			41 => ['columns' => ['users' => ['totp_secret']]],
			42 => [
				'columns' => ['file_deletion_queue' => [
					'file_id', 'attempts', 'next_attempt_at', 'last_error', 'created_at',
				]],
				'indexes' => ['file_deletion_queue' => [
					'PRIMARY' => ['file_id'],
					'idx_deletion_due' => ['next_attempt_at', 'created_at'],
				]],
			],
			43 => [
				'columns' => [
					'payments' => ['product_snapshot'],
					'promo_codes' => ['reserved_count'],
					'promo_reservations' => [
						'ext_order_id', 'promo_id', 'status', 'created_at', 'updated_at',
					],
					'payment_events' => [
						'provider', 'event_id', 'ext_order_id', 'provider_status', 'received_at',
					],
					'ad_file_deletion_queue' => [
						'filename', 'attempts', 'next_attempt_at', 'last_error', 'created_at',
					],
				],
				'indexes' => [
					'promo_reservations' => [
						'PRIMARY' => ['ext_order_id'],
						'idx_promo_reservation' => ['promo_id', 'status'],
					],
					'payment_events' => [
						'PRIMARY' => ['provider', 'event_id'],
						'idx_payment_event_order' => ['ext_order_id', 'received_at'],
					],
					'ad_file_deletion_queue' => [
						'PRIMARY' => ['filename'],
						'idx_ad_deletion_due' => ['next_attempt_at', 'created_at'],
					],
				],
			],
			44 => [
				'columns' => ['upload_storage_reservations' => [
					'id', 'user_id', 'size', 'expires_at', 'created_at',
				]],
				'indexes' => ['upload_storage_reservations' => [
					'PRIMARY' => ['id'],
					'idx_upload_reservation_expiry' => ['expires_at'],
					'idx_upload_reservation_user' => ['user_id', 'expires_at'],
				]],
			],
			45 => ['columns' => ['ads' => ['purchase_duration_days']]],
			46 => ['columns' => ['users' => ['group_payment_ext_order_id']]],
			47 => [
				'columns' => ['migration_journal' => [
					'version', 'status', 'attempts', 'started_at', 'finished_at', 'last_error',
				]],
				'indexes' => [
					'migration_journal' => ['PRIMARY' => ['version']],
					'files' => [
						'idx_user_uploaded_id' => ['user_id', 'uploaded_at', 'id'],
					],
				],
			],
			48 => [
				'columns' => ['active_downloads' => ['instance_id', 'heartbeat_at']],
				'indexes' => ['active_downloads' => [
					'idx_active_heartbeat' => ['heartbeat_at'],
				]],
			],
			49 => [
				'columns' => ['webhook_deliveries' => [
					'event_id', 'lease_owner', 'lease_until', 'delivered_at',
				]],
				'indexes' => ['webhook_deliveries' => [
					'uniq_event_id' => ['event_id'],
					'idx_webhook_lease' => ['status', 'lease_until'],
				]],
				'unique_indexes' => ['webhook_deliveries' => ['uniq_event_id']],
			],
			50 => [
				'columns' => ['notifications' => ['open_stack_key', 'dedupe_key']],
				'indexes' => ['notifications' => [
					'uniq_open_stack' => ['user_id', 'open_stack_key'],
					'uniq_notification_dedupe' => ['user_id', 'dedupe_key'],
				]],
				'unique_indexes' => ['notifications' => [
					'uniq_open_stack', 'uniq_notification_dedupe',
				]],
			],
			51 => [
				'columns' => ['traffic_daily' => [
					'day', 'transfer_type', 'transfer_size', 'transfer_count',
				]],
				'indexes' => ['traffic_daily' => [
					'PRIMARY' => ['day', 'transfer_type'],
				]],
			],
			52 => [
				'columns' => ['download_reservations' => [
					'id', 'resource_type', 'resource_id', 'token_fingerprint',
					'active_download_id', 'user_id', 'ip_address', 'state', 'bytes_sent',
					'lease_until', 'created_at', 'started_at', 'finished_at', 'updated_at',
				]],
				'indexes' => ['download_reservations' => [
					'PRIMARY' => ['id'],
					'idx_download_reservation_lease' => ['state', 'lease_until'],
					'idx_download_reservation_resource' => ['resource_type', 'resource_id'],
				]],
			],
			53 => [
				'columns' => [
					'files' => ['consume_reservation_id'],
					'collections' => ['consume_reservation_id'],
					'download_tokens' => ['reservation_id', 'reserved_until', 'used_at'],
					'download_reservation_effects' => [
						'reservation_id', 'effect_type', 'resource_type', 'resource_id',
						'applied_at',
					],
				],
				'indexes' => [
					'download_tokens' => [
						'idx_download_token_reservation' => ['reservation_id', 'reserved_until'],
					],
					'download_reservation_effects' => [
						'PRIMARY' => [
							'reservation_id', 'effect_type', 'resource_type', 'resource_id',
						],
						'idx_download_effect_resource' => [
							'effect_type', 'resource_type', 'resource_id', 'reservation_id',
						],
					],
				],
			],
			54 => [],
			55 => [],
			56 => [
				'columns' => ['mail_outbox' => [
					'id', 'idempotency_key', 'content_hash', 'to_email', 'from_email',
					'subject', 'subject_header', 'headers', 'html_body', 'status',
					'attempts', 'max_attempts', 'next_attempt_at', 'lease_owner',
					'lease_until', 'last_error', 'created_at', 'updated_at', 'sent_at',
				]],
				'indexes' => ['mail_outbox' => [
					'PRIMARY' => ['id'],
					'uq_mail_outbox_idempotency' => ['idempotency_key'],
					'idx_mail_outbox_ready' => ['status', 'next_attempt_at', 'id'],
					'idx_mail_outbox_lease' => ['status', 'lease_until'],
				]],
				'unique_indexes' => ['mail_outbox' => ['uq_mail_outbox_idempotency']],
			],
			57 => ['indexes' => ['mail_outbox' => [
				'idx_mail_outbox_sent' => ['status', 'sent_at'],
			]]],
			58 => [
				'columns' => ['file_quarantine' => [
					'file_id', 'manifest_json', 'reason', 'actor_type', 'actor_id', 'size',
					'checksum', 'state', 'quarantine_until', 'attempts', 'next_attempt_at',
					'last_error', 'created_at', 'updated_at',
				]],
				'indexes' => ['file_quarantine' => [
					'PRIMARY' => ['file_id'],
					'idx_quarantine_due' => [
						'state', 'next_attempt_at', 'quarantine_until',
					],
				]],
			],
			59 => [
				'columns' => [
					'users' => ['staff_group_id'],
					'promo_codes' => ['scope', 'plan_id'],
				],
				'indexes' => [
					'users' => ['idx_staff_group_id' => ['staff_group_id']],
					'promo_codes' => ['idx_promo_scope_plan' => ['scope', 'plan_id']],
				],
			],
			60 => [],
			61 => [
				'columns' => [
					'groups' => ['transfer_quota_bytes', 'transfer_quota_period'],
					'plans' => ['limit_fields'],
					'active_uploads' => ['client_id', 'status'],
					'download_reservations' => [
						'quota_subject_type', 'quota_subject_key', 'quota_period',
						'quota_period_start', 'quota_reserved_bytes',
					],
					'transfer_quota_usage' => [
						'subject_type', 'subject_key', 'period', 'period_start',
						'used_bytes', 'reserved_bytes', 'updated_at',
					],
				],
				'indexes' => [
					'active_uploads' => [
						'idx_upload_client_status' => ['client_id', 'status', 'updated_at'],
					],
					'transfer_quota_usage' => [
						'PRIMARY' => ['subject_type', 'subject_key', 'period', 'period_start'],
						'idx_transfer_quota_updated' => ['updated_at'],
					],
				],
			],
			62 => [
				'indexes' => ['files' => [
					'idx_uploaded_ip' => ['uploaded_ip'],
				]],
			],
			63 => [
				'columns' => [
					'remember_tokens' => [
						'id', 'user_id', 'series', 'token_hash', 'expires_at',
						'created_at', 'last_used_at', 'last_ip', 'user_agent',
					],
				],
				'indexes' => [
					'remember_tokens' => [
						'PRIMARY' => ['id'],
						'uniq_remember_series' => ['series'],
						'idx_remember_user' => ['user_id'],
						'idx_remember_expires' => ['expires_at'],
					],
				],
			],
			64 => [
				'columns' => ['users' => ['email_change_stage']],
			],
		];
	}

	/** @return array<string, string> */
	private static function migrationForeignKeyRules(): array
	{
		return [
			'user_group' => 'SET NULL',
			'file_owner' => 'RESTRICT',
			'collection_owner' => 'CASCADE',
			'member_collection' => 'CASCADE',
			'member_file' => 'CASCADE',
			'report_file' => 'CASCADE',
			'api_user' => 'CASCADE',
			'webhook_user' => 'CASCADE',
			'delivery_hook' => 'CASCADE',
			'notification_user' => 'CASCADE',
			'preference_user' => 'CASCADE',
			'recovery_user' => 'CASCADE',
			'totp_user' => 'CASCADE',
			'upload_token_user' => 'CASCADE',
			'download_token_user' => 'CASCADE',
			'active_upload_user' => 'CASCADE',
			'storage_res_user' => 'CASCADE',
			'traffic_user' => 'SET NULL',
			'traffic_file' => 'SET NULL',
			'audit_user' => 'SET NULL',
			'plan_group' => 'SET NULL',
			'payment_user' => 'SET NULL',
			'payment_actor' => 'SET NULL',
			'payment_plan' => 'SET NULL',
			'payment_ad' => 'SET NULL',
			'payment_package' => 'SET NULL',
			'ad_owner' => 'SET NULL',
			'ad_approver' => 'SET NULL',
			'ad_package' => 'SET NULL',
			'ad_stat' => 'CASCADE',
			'reservation_user' => 'SET NULL',
			'effect_reservation' => 'CASCADE',
			'email_user' => 'SET NULL',
			'promo_res_code' => 'RESTRICT',
			'promo_res_order' => 'CASCADE',
		];
	}

	/** @return string[] */
	private static function migrationStepProblems(PDO $pdo, string $prefix, int $version): array
	{
		$contract = self::migrationStepContracts()[$version] ?? null;
		if ($contract === null) {
			return ["Migration {$version} has no declared contract."];
		}
		$problems = [];
		$columnQuery = $pdo->prepare(
			'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
			. 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
		);
		foreach (($contract['columns'] ?? []) as $table => $requiredColumns) {
			$tableName = $prefix . $table;
			$columnQuery->execute([$tableName]);
			$actualColumns = $columnQuery->fetchAll(PDO::FETCH_COLUMN);
			if ($actualColumns === []) {
				$problems[] = "table {$tableName} is missing";
				continue;
			}
			$missingColumns = array_values(array_diff($requiredColumns, $actualColumns));
			if ($missingColumns !== []) {
				$problems[] = "columns missing from {$tableName}: "
					. implode(', ', $missingColumns);
			}
		}

		$indexQuery = $pdo->prepare(
			'SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE '
			. 'FROM information_schema.STATISTICS '
			. 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
			. 'ORDER BY INDEX_NAME, SEQ_IN_INDEX'
		);
		foreach (($contract['indexes'] ?? []) as $table => $requiredIndexes) {
			$tableName = $prefix . $table;
			$indexQuery->execute([$tableName]);
			$actualIndexes = [];
			$actualUnique = [];
			foreach ($indexQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
				$name = (string) $row['INDEX_NAME'];
				$actualIndexes[$name][] = (string) $row['COLUMN_NAME'];
				$actualUnique[$name] = (int) $row['NON_UNIQUE'] === 0;
			}
			foreach ($requiredIndexes as $index => $columns) {
				if (($actualIndexes[$index] ?? null) !== $columns) {
					$problems[] = "index {$tableName}.{$index} is missing or malformed";
				}
			}
			foreach (($contract['unique_indexes'][$table] ?? []) as $index) {
				if (!($actualUnique[$index] ?? false)) {
					$problems[] = "index {$tableName}.{$index} is not unique";
				}
			}
		}

		switch ($version) {
			case 3:
				$count = (int) $pdo->query(
					"SELECT COUNT(*) FROM `{$prefix}reports` r "
					. "LEFT JOIN `{$prefix}files` f ON f.`id` = r.`file_id` "
					. "WHERE f.`id` IS NULL"
				)->fetchColumn();
				if ($count !== 0) {
					$problems[] = 'orphaned reports remain';
				}
				break;
			case 4:
				$count = (int) $pdo->query(
					"SELECT COUNT(*) FROM `{$prefix}groups` WHERE `is_default` = 1"
				)->fetchColumn();
				if ($count < 1) {
					$problems[] = 'default user group is missing';
				}
				break;
			case 11:
				$stmt = $pdo->prepare(
					"SELECT COUNT(*) FROM `{$prefix}settings` WHERE `setting_key` IN (?, ?)"
				);
				$stmt->execute(['recaptcha_report_threshold_time', 'recaptcha_on_download']);
				if ((int) $stmt->fetchColumn() !== 0) {
					$problems[] = 'legacy reCAPTCHA aliases remain';
				}
				break;
			case 12:
				$stmt = $pdo->prepare(
					"SELECT `setting_value` FROM `{$prefix}settings` WHERE `setting_key` = ?"
				);
				$stmt->execute(['smtp_pass']);
				$value = $stmt->fetchColumn();
				if ($value !== false && $value !== '' && !Crypto::isEncrypted((string) $value)) {
					$problems[] = 'SMTP password remains plaintext';
				}
				break;
			case 14:
				$count = (int) $pdo->query(
					"SELECT COUNT(DISTINCT `slug`) FROM `{$prefix}groups` "
					. "WHERE `slug` IN ('guest', 'user') AND `is_system` = 1"
				)->fetchColumn();
				if ($count !== 2) {
					$problems[] = 'guest/user system groups are incomplete';
				}
				break;
			case 21:
				$stmt = $pdo->prepare(
					'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS '
					. 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
					. "AND COLUMN_NAME = 'storage_limit'"
				);
				$stmt->execute([$prefix . 'users']);
				if ((string) $stmt->fetchColumn() !== '0') {
					$problems[] = 'users.storage_limit default is not zero';
				}
				break;
			case 34:
				$stmt = $pdo->prepare(
					"SELECT COUNT(*) FROM `{$prefix}settings` WHERE `setting_key` = ?"
				);
				$stmt->execute(['ads_max_banner_mb']);
				if ((int) $stmt->fetchColumn() !== 0) {
					$problems[] = 'legacy ads_max_banner_mb setting remains';
				}
				break;
			case 35:
				$stmt = $pdo->prepare(
					"SELECT COUNT(*) FROM `{$prefix}settings` WHERE `setting_key` = ?"
				);
				$stmt->execute(['ads_consent_required']);
				if ((int) $stmt->fetchColumn() !== 0) {
					$problems[] = 'legacy ads_consent_required setting remains';
				}
				break;
			case 39:
				$blocked = array_filter(array_map(
					static fn(string $ext): string => strtolower(ltrim(trim($ext), '.')),
					explode(',', (string) self::getSetting('blocked_extensions', ''))
				));
				$missing = array_diff(
					['html', 'htm', 'xhtml', 'svg', 'shtml', 'xml'],
					array_unique($blocked)
				);
				if ($missing !== []) {
					$problems[] = 'active-document extension blocklist is incomplete';
				}
				break;
			case 41:
				$stmt = $pdo->prepare(
					'SELECT DATA_TYPE FROM information_schema.COLUMNS '
					. 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
					. "AND COLUMN_NAME = 'totp_secret'"
				);
				$stmt->execute([$prefix . 'users']);
				if (strtolower((string) $stmt->fetchColumn()) !== 'text') {
					$problems[] = 'users.totp_secret is not TEXT';
				}
				$count = (int) $pdo->query(
					"SELECT COUNT(*) FROM `{$prefix}users` "
					. "WHERE `totp_secret` IS NOT NULL AND `totp_secret` <> '' "
					. "AND `totp_secret` NOT LIKE 'enc:v1:%'"
				)->fetchColumn();
				if ($count !== 0) {
					$problems[] = 'plaintext TOTP secrets remain';
				}
				break;
			case 54:
			case 55:
				$constraintPrefix = 'fk_' . substr(hash('sha256', $prefix), 0, 8) . '_';
				$query = $pdo->prepare(
					"SELECT `DELETE_RULE` FROM information_schema.REFERENTIAL_CONSTRAINTS "
					. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?"
				);
				foreach (self::migrationForeignKeyRules() as $suffix => $expectedRule) {
					$name = substr($constraintPrefix . $suffix, 0, 64);
					$query->execute([$name]);
					$actualRule = $query->fetchColumn();
					if ($version === 54 && $suffix === 'plan_group') {
						if ($actualRule === false) {
							$problems[] = "foreign key {$name} is missing";
						}
					} elseif ($actualRule !== $expectedRule) {
						$problems[] = "foreign key {$name} is missing or malformed";
					}
				}
				break;
			case 59:
				$constraintPrefix = 'fk_' . substr(hash('sha256', $prefix), 0, 8) . '_';
				$query = $pdo->prepare(
					"SELECT `DELETE_RULE` FROM information_schema.REFERENTIAL_CONSTRAINTS "
					. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?"
				);
				foreach ([
					'staff_group' => 'SET NULL',
					'promo_plan' => 'SET NULL',
				] as $suffix => $expectedRule) {
					$name = substr($constraintPrefix . $suffix, 0, 64);
					$query->execute([$name]);
					if ($query->fetchColumn() !== $expectedRule) {
						$problems[] = "foreign key {$name} is missing or malformed";
					}
				}
				break;
			case 63:
				$constraintPrefix = 'fk_' . substr(hash('sha256', $prefix), 0, 8) . '_';
				$name = substr($constraintPrefix . 'remember_user', 0, 64);
				$query = $pdo->prepare(
					"SELECT `DELETE_RULE` FROM information_schema.REFERENTIAL_CONSTRAINTS "
					. "WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?"
				);
				$query->execute([$name]);
				if ($query->fetchColumn() !== 'CASCADE') {
					$problems[] = "foreign key {$name} is missing or malformed";
				}
				break;
			case 60:
				$moderator = $pdo->query(
					"SELECT `id`, `is_system`, `is_default`, `max_file_size_mb`,
					        `max_files_per_session`, `storage_quota_mb`, `limit_upload`,
					        `limit_download`, `concurrent_downloads`,
					        `concurrent_connections_per_file`, `auto_delete_days`
					 FROM `{$prefix}groups` WHERE `slug` = 'moderator'"
				)->fetchAll(PDO::FETCH_ASSOC);
				if (count($moderator) !== 1) {
					$problems[] = 'Moderator system group is missing or duplicated';
					break;
				}
				$moderatorGroup = $moderator[0];
				if ((int) $moderatorGroup['is_system'] !== 1
					|| (int) $moderatorGroup['is_default'] !== 0) {
					$problems[] = 'Moderator group is not a non-default system group';
				}
				$moderatorId = (int) $moderatorGroup['id'];
				$assignment = $pdo->prepare(
					"SELECT COUNT(*) FROM `{$prefix}users`
					 WHERE (`role` = 'moderator' AND COALESCE(`staff_group_id`, 0) <> ?)
					    OR (`role` <> 'moderator' AND `staff_group_id` IS NOT NULL)"
				);
				$assignment->execute([$moderatorId]);
				if ((int) $assignment->fetchColumn() !== 0) {
					$problems[] = 'role-bound Moderator group assignments are inconsistent';
				}
				break;
		}
		return $problems;
	}

	private static function assertMigrationStepContract(
		PDO $pdo,
		string $prefix,
		int $version
	): void {
		$problems = self::migrationStepProblems($pdo, $prefix, $version);
		if ($problems !== []) {
			throw new RuntimeException(
				"Migration {$version} contract failed: " . implode('; ', $problems)
			);
		}
	}

	private static function shouldRunMigrationStep(
		PDO $pdo,
		string $prefix,
		int $currentVersion,
		int $targetVersion,
		bool $repairPublishedSchema
	): bool {
		if ($currentVersion < $targetVersion) {
			return true;
		}
		if (!$repairPublishedSchema) {
			return false;
		}
		$problems = self::migrationStepProblems($pdo, $prefix, $targetVersion);
		if ($problems === []) {
			return false;
		}
		self::startMigrationJournalStep($targetVersion, true);
		error_log(
			"Forward-repairing migration {$targetVersion}: " . implode('; ', $problems)
		);
		return true;
	}

	private static function schemaContractHash(): string
	{
		return hash('sha256', serialize([
			'format' => 1,
			'version' => self::CURRENT_SCHEMA_VERSION,
			'steps' => self::migrationStepContracts(),
			'foreign_keys' => self::migrationForeignKeyRules(),
		]));
	}

	private static function publishSchemaVerification(int $timestamp): void
	{
		if (!self::setSetting('schema_contract_hash', self::schemaContractHash())
			|| !self::setSetting('schema_contract_checked_at', (string) $timestamp)
			|| !self::setSetting('schema_ready', '1')) {
			throw new RuntimeException('Could not publish schema verification state.');
		}
	}

	public static function getAllSettings(): array
	{
		return SettingsRepository::all();
	}

	/**
	 * Lightweight schema migration for installs created before audit-log / file-sharing
	 * columns existed. Runs the idempotent DDL once, then records a schema version so it
	 * never runs again (cheap: gated by a single cached setting).
	 */
	public static function migrate(): void
	{
		$pdo = self::getInstance();
		if (!$pdo) {
			return;
		}

		$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
		$now = time();
		$version = (int) self::getSetting('schema_version', 1);
		$lastContractCheck = (int) self::getSetting('schema_contract_checked_at', 0);
		$publishedContractHash = (string) self::getSetting('schema_contract_hash', '');
		if ($version === self::CURRENT_SCHEMA_VERSION
			&& (string) self::getSetting('schema_ready', '0') === '1'
			&& $lastContractCheck > 0
			&& $lastContractCheck >= $now - self::SCHEMA_CONTRACT_CACHE_SECONDS
			&& hash_equals(self::schemaContractHash(), $publishedContractHash)) {
			return;
		}

		$migrationLockName = 'filehost:migrate:all:'
			. sha1((defined('DB_NAME') ? DB_NAME : '') . ':' . $prefix);
		$lockStatement = $pdo->prepare('SELECT GET_LOCK(?, 15)');
		$lockStatement->execute([$migrationLockName]);
		if ((int) $lockStatement->fetchColumn() !== 1) {
			throw new RuntimeException('Could not acquire the database migration lock.');
		}
		$releaseMigrationLock = static function () use ($pdo, $migrationLockName): void {
			try {
				$release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
				$release->execute([$migrationLockName]);
			} catch (\Throwable $e) {
				error_log('Could not release migration lock: ' . $e->getMessage());
			}
		};
		$version = (int) self::getSetting('schema_version', 1);
		try {
			if (!self::setSetting('schema_ready', '0')) {
				throw new RuntimeException('Could not clear schema readiness before migration.');
			}
			self::initialiseMigrationJournal($pdo, $prefix, $version);
		} catch (Throwable $e) {
			$releaseMigrationLock();
			throw new RuntimeException('Could not initialize migration journal.', 0, $e);
		}

		try {
			$repairPublishedSchema = false;
			if ($version >= self::CURRENT_SCHEMA_VERSION) {
				try {
					self::assertSupportedSchema($pdo, $prefix);
					self::publishSchemaVerification(time());
					self::completeMigrationJournalStep($version);
					return;
				} catch (PDOException $e) {
					throw $e;
				} catch (RuntimeException $e) {
					if ($version > self::CURRENT_SCHEMA_VERSION) {
						throw $e;
					}
					$repairPublishedSchema = true;
					error_log(
						'Published schema requires forward repair: ' . $e->getMessage()
					);
				}
			}
			$runStep = static fn(int $targetVersion): bool =>
				self::shouldRunMigrationStep(
					$pdo,
					$prefix,
					$version,
					$targetVersion,
					$repairPublishedSchema
				);

			// --- v2: audit_log table + per-file sharing columns ---
			if ($runStep(2)) {
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}audit_log` (
					`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`user_id` INT UNSIGNED DEFAULT NULL,
					`username` VARCHAR(64) DEFAULT NULL,
					`action` VARCHAR(64) NOT NULL,
					`details` VARCHAR(512) DEFAULT '',
					`ip_address` VARCHAR(45) DEFAULT '',
					`created_at` INT UNSIGNED NOT NULL,
					INDEX `idx_created` (`created_at`),
					INDEX `idx_action` (`action`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}
			foreach ([
				"ADD COLUMN `expires_at` INT UNSIGNED DEFAULT NULL",
				"ADD COLUMN `max_downloads` INT UNSIGNED DEFAULT NULL",
				"ADD COLUMN `password_hash` VARCHAR(255) DEFAULT NULL",
			] as $col) {
				try {
					$pdo->exec("ALTER TABLE `{$prefix}files` $col");
				} catch (PDOException $e) {
					self::tolerateIdempotentDdlError($e);
				}
			}

			self::persistMigrationVersion(2);
			$version = max($version, 2);
		}

		// --- v3: purge reports orphaned by file deletions made before A7 cleanup existed ---
		if ($runStep(3)) {
			try {
				$pdo->exec("DELETE r FROM `{$prefix}reports` r
					LEFT JOIN `{$prefix}files` f ON r.`file_id` = f.`id`
					WHERE f.`id` IS NULL");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			self::persistMigrationVersion(3);
			$version = max($version, 3);
		}

		// --- v4: user groups with their own limits (A8) ---
		if ($runStep(4)) {
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}groups` (
					`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`name` VARCHAR(50) NOT NULL UNIQUE,
					`max_file_size_mb` INT UNSIGNED DEFAULT 0,
					`max_files_per_session` INT UNSIGNED DEFAULT 0,
					`storage_quota_mb` INT UNSIGNED DEFAULT 0,
					`limit_upload` BIGINT UNSIGNED DEFAULT 0,
					`limit_download` BIGINT UNSIGNED DEFAULT 0,
					`concurrent_downloads` INT UNSIGNED DEFAULT 0,
					`concurrent_connections_per_file` INT UNSIGNED DEFAULT 0,
					`is_default` TINYINT(1) DEFAULT 0,
					`created_at` INT UNSIGNED NOT NULL,
					INDEX `idx_is_default` (`is_default`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			foreach ([
				"ADD COLUMN `group_id` INT UNSIGNED DEFAULT NULL AFTER `role`",
				"ADD INDEX `idx_group_id` (`group_id`)",
			] as $alter) {
				try {
					$pdo->exec("ALTER TABLE `{$prefix}users` {$alter}");
				} catch (PDOException $e) {
					self::tolerateIdempotentDdlError($e);
				}
			}

			// Seed the default "Użytkownik" group from the current flat user-tier settings so
			// behaviour is identical right after migration: existing users keep group_id = NULL,
			// which resolves to this default group everywhere.
			try {
				$cnt = (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}groups`")->fetchColumn();
				if ($cnt === 0) {
					$stmt = $pdo->prepare("INSERT INTO `{$prefix}groups`
						(`name`, `max_file_size_mb`, `max_files_per_session`, `storage_quota_mb`,
						 `limit_upload`, `limit_download`, `concurrent_downloads`, `concurrent_connections_per_file`,
						 `is_default`, `created_at`)
						VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
					$stmt->execute([
						'Użytkownik',
						(int) self::getSetting('user_max_file_size_mb', 5120),
						(int) self::getSetting('user_max_files', 15),
						(int) self::getSetting('default_storage_limit_mb', 0),
						(int) self::getSetting('limit_upload_user', 0),
						(int) self::getSetting('limit_download_user', 0),
						(int) self::getSetting('concurrent_downloads_user', 0),
						(int) self::getSetting('concurrent_connections_file_user', 32),
						time(),
					]);
				}
			} catch (PDOException $e) {
				// A failed seed is not an idempotent DDL condition. Without the default group
				// the next migration would derive permissions from an invalid state.
				throw $e;
			}

			self::persistMigrationVersion(4);
			$version = max($version, 4);
		}

		// --- v5: one-time links ("burn after reading") — invalidate a share after 1 download ---
		if ($runStep(5)) {
			foreach ([
				"ADD COLUMN `one_time` TINYINT(1) DEFAULT 0",
				"ADD COLUMN `consumed_at` INT UNSIGNED DEFAULT NULL",
			] as $col) {
				try {
					$pdo->exec("ALTER TABLE `{$prefix}files` $col");
				} catch (PDOException $e) {
					self::tolerateIdempotentDdlError($e);
				}
			}

			self::persistMigrationVersion(5);
			$version = max($version, 5);
		}

		// --- v6: collections — group files under one shareable link (ZIP on the fly) ---
		if ($runStep(6)) {
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}collections` (
					`id` VARCHAR(32) NOT NULL PRIMARY KEY,
					`name` VARCHAR(255) NOT NULL,
					`user_id` INT UNSIGNED DEFAULT NULL,
					`delete_token` VARCHAR(255) NOT NULL,
					`downloads` INT UNSIGNED DEFAULT 0,
					`created_at` INT UNSIGNED NOT NULL,
					INDEX `idx_user_id` (`user_id`),
					INDEX `idx_created` (`created_at`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}collection_files` (
					`collection_id` VARCHAR(32) NOT NULL,
					`file_id` VARCHAR(32) NOT NULL,
					`position` INT UNSIGNED DEFAULT 0,
					PRIMARY KEY (`collection_id`, `file_id`),
					INDEX `idx_file` (`file_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			self::persistMigrationVersion(6);
			$version = max($version, 6);
		}

		// --- v7: per-user API keys (ShareX / programmatic upload) ---
		if ($runStep(7)) {
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}api_keys` (
					`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`user_id` INT UNSIGNED NOT NULL,
					`key_hash` CHAR(64) NOT NULL,
					`key_prefix` VARCHAR(16) NOT NULL,
					`label` VARCHAR(100) DEFAULT '',
					`created_at` INT UNSIGNED NOT NULL,
					`last_used_at` INT UNSIGNED DEFAULT NULL,
					UNIQUE KEY `uniq_hash` (`key_hash`),
					INDEX `idx_user` (`user_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			self::persistMigrationVersion(7);
			$version = max($version, 7);
		}

		// --- v8: webhooks — user-registered endpoints + a delivery queue (worker in the upload server) ---
		if ($runStep(8)) {
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}webhooks` (
					`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`user_id` INT UNSIGNED NOT NULL,
					`url` VARCHAR(500) NOT NULL,
					`secret` VARCHAR(64) NOT NULL,
					`events` VARCHAR(100) NOT NULL DEFAULT 'upload,download,delete',
					`is_active` TINYINT(1) DEFAULT 1,
					`created_at` INT UNSIGNED NOT NULL,
					`last_status` VARCHAR(64) DEFAULT NULL,
					`last_delivery_at` INT UNSIGNED DEFAULT NULL,
					INDEX `idx_user` (`user_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}webhook_deliveries` (
					`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`webhook_id` INT UNSIGNED NOT NULL,
					`event_id` CHAR(32) NOT NULL,
					`event` VARCHAR(20) NOT NULL,
					`payload` TEXT NOT NULL,
					`attempts` INT UNSIGNED DEFAULT 0,
					`status` VARCHAR(20) DEFAULT 'pending',
					`next_attempt_at` INT UNSIGNED NOT NULL,
					`lease_owner` CHAR(32) DEFAULT NULL,
					`lease_until` INT UNSIGNED DEFAULT NULL,
					`delivered_at` INT UNSIGNED DEFAULT NULL,
					`created_at` INT UNSIGNED NOT NULL,
					UNIQUE KEY `uniq_event_id` (`event_id`),
					INDEX `idx_pending` (`status`, `next_attempt_at`),
					INDEX `idx_webhook_lease` (`status`, `lease_until`),
					INDEX `idx_webhook` (`webhook_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			self::persistMigrationVersion(8);
			$version = max($version, 8);
		}

		// --- v9: 2FA (TOTP) per user ---
		if ($runStep(9)) {
			foreach ([
				"ADD COLUMN `totp_secret` VARCHAR(64) DEFAULT NULL",
				"ADD COLUMN `totp_enabled` TINYINT(1) DEFAULT 0",
			] as $col) {
				try {
					$pdo->exec("ALTER TABLE `{$prefix}users` $col");
				} catch (PDOException $e) {
					self::tolerateIdempotentDdlError($e);
				}
			}

			self::persistMigrationVersion(9);
			$version = max($version, 9);
		}

		// --- v10: rate limiting — one fixed-window counter per bucket (see RateLimiter.php) ---
		if ($runStep(10)) {
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}rate_limits` (
					`bucket` VARCHAR(190) NOT NULL PRIMARY KEY,
					`window_start` INT UNSIGNED NOT NULL,
					`hits` INT UNSIGNED NOT NULL DEFAULT 0,
					INDEX `idx_window` (`window_start`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			self::persistMigrationVersion(10);
			$version = max($version, 10);
		}

		// --- v11: retire duplicated reCAPTCHA settings aliases (Q10) ---
		// `recaptcha_report_threshold_time` was renamed to `recaptcha_security_window`
		// (both were kept in sync "for compatibility"); `recaptcha_on_download` was a
		// dead 0/1/2 setting with no UI and no reader. Carry the old value over once,
		// then delete both legacy keys so a single canonical key remains.
		if ($runStep(11)) {
			$settingsTable = "{$prefix}settings";
			try {
				$pdo->exec("INSERT IGNORE INTO `{$settingsTable}` (`setting_key`, `setting_value`)
					SELECT 'recaptcha_security_window', `setting_value`
					FROM `{$settingsTable}` WHERE `setting_key` = 'recaptcha_report_threshold_time'");
			} catch (PDOException $e) {
				// INSERT IGNORE already handles the only expected idempotent condition.
				throw $e;
			}
			try {
				$pdo->exec("DELETE FROM `{$settingsTable}`
					WHERE `setting_key` IN ('recaptcha_report_threshold_time', 'recaptcha_on_download')");
			} catch (PDOException $e) {
				throw $e;
			}

			self::invalidateSettingsCache();
			self::persistMigrationVersion(11);
			$version = max($version, 11);
		}

		// --- v12: encrypt secrets stored in `settings` at rest (S12) ---
		// The SMTP password was saved in cleartext. Encrypt any existing plaintext
		// value once; new saves go through setSecretSetting().
		if ($runStep(12)) {
			require_once __DIR__ . '/Crypto.php';
			$cur = (string) self::getSetting('smtp_pass', '');
			if ($cur !== '' && !Crypto::isEncrypted($cur)) {
				if (!self::setSetting('smtp_pass', Crypto::encrypt($cur))) {
					throw new RuntimeException('Could not migrate the SMTP secret.');
				}
			}

			self::persistMigrationVersion(12);
			$version = max($version, 12);
		}

		// --- v13: per-collection sharing controls — password / expiry / one-time (C2) ---
		if ($runStep(13)) {
			$cTable = "{$prefix}collections";
			foreach ([
				"ADD COLUMN `password_hash` VARCHAR(255) DEFAULT NULL",
				"ADD COLUMN `expires_at` INT UNSIGNED DEFAULT NULL",
				"ADD COLUMN `max_downloads` INT UNSIGNED DEFAULT NULL",
				"ADD COLUMN `one_time` TINYINT(1) NOT NULL DEFAULT 0",
				"ADD COLUMN `consumed_at` INT UNSIGNED DEFAULT NULL",
			] as $alter) {
				try {
					$pdo->exec("ALTER TABLE `{$cTable}` {$alter}");
				} catch (PDOException $e) {
					self::tolerateIdempotentDdlError($e);
				}
			}

			self::persistMigrationVersion(13);
			$version = max($version, 13);
		}

		// --- v14: group permissions + non-deletable system groups (Guest / User) ---
		// Groups stop being "limit profiles only": they now also carry a permission set, and
		// two of them are structural — `guest` (everyone without an account) and `user` (the
		// default for registered users). Both are flagged is_system so they can't be deleted.
		// The old Settings → Limity tab edited flat guest_*/user_* settings; those settings
		// stay the source of truth for PHP constants and the Python upload server, and are
		// now written from the matching system group (see GroupRepository::save).
		if ($runStep(14)) {
			$gTable = "{$prefix}groups";
			foreach ([
				"ADD COLUMN `slug` VARCHAR(20) DEFAULT NULL",
				"ADD COLUMN `is_system` TINYINT(1) NOT NULL DEFAULT 0",
				"ADD COLUMN `permissions` TEXT DEFAULT NULL",
				"ADD UNIQUE KEY `uniq_slug` (`slug`)",
			] as $alter) {
				try {
					$pdo->exec("ALTER TABLE `{$gTable}` {$alter}");
				} catch (PDOException $e) {
					self::tolerateIdempotentDdlError($e);
				}
			}

			try {
				// The group seeded by v4 from the user-tier settings becomes the `user` system
				// group, whatever the operator has since renamed it to.
				$existing = $pdo->query("SELECT `id` FROM `{$gTable}` WHERE `is_default` = 1 ORDER BY `id` ASC LIMIT 1")->fetchColumn();
				if (!$existing) {
					$existing = $pdo->query("SELECT `id` FROM `{$gTable}` ORDER BY `id` ASC LIMIT 1")->fetchColumn();
				}
				if ($existing) {
					$pdo->prepare("UPDATE `{$gTable}` SET `slug` = 'user', `is_system` = 1, `is_default` = 1 WHERE `id` = ?")
						->execute([(int) $existing]);
					// Adopt the canonical "User" name, but only if it still carries the name v4
					// seeded it with — an operator who renamed the group meant it.
					$pdo->prepare("UPDATE `{$gTable}` SET `name` = 'User' WHERE `id` = ? AND `name` = 'Użytkownik'")
						->execute([(int) $existing]);
				}

				// Guest group — seeded from the flat guest-tier settings so behaviour is identical
				// right after migration. Guests have no account, so it never has members.
				$hasGuest = $pdo->query("SELECT `id` FROM `{$gTable}` WHERE `slug` = 'guest'")->fetchColumn();
				if (!$hasGuest) {
					$stmt = $pdo->prepare("INSERT INTO `{$gTable}`
						(`name`, `slug`, `is_system`, `max_file_size_mb`, `max_files_per_session`, `storage_quota_mb`,
						 `limit_upload`, `limit_download`, `concurrent_downloads`, `concurrent_connections_per_file`,
						 `permissions`, `is_default`, `created_at`)
						VALUES (?, 'guest', 1, ?, ?, 0, ?, ?, ?, ?, '', 0, ?)");
					$stmt->execute([
						'Guest',
						(int) self::getSetting('guest_max_file_size_mb', 250),
						(int) self::getSetting('guest_max_files', 5),
						(int) self::getSetting('limit_upload_guest', 0),
						(int) self::getSetting('limit_download_guest', 0),
						(int) self::getSetting('concurrent_downloads_guest', 1),
						(int) self::getSetting('concurrent_connections_file_guest', 8),
						time(),
					]);
				}
			} catch (PDOException $e) {
				throw $e;
			}

			self::persistMigrationVersion(14);
			$version = max($version, 14);
		}

		// --- v15: 2FA recovery codes — single-use fallbacks for a lost authenticator ---
		// Without these, losing the phone meant losing the account (2FA can only be turned off
		// from inside a logged-in session). Only a hash of each code is stored; `used_at`
		// marks one as spent rather than deleting the row, so the panel can show how many are
		// left and an audit trail survives.
		if ($runStep(15)) {
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}totp_recovery_codes` (
					`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`user_id` INT UNSIGNED NOT NULL,
					`code_hash` VARCHAR(255) NOT NULL,
					`created_at` INT UNSIGNED NOT NULL,
					`used_at` INT UNSIGNED DEFAULT NULL,
					INDEX `idx_user` (`user_id`),
					INDEX `idx_user_unused` (`user_id`, `used_at`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			self::persistMigrationVersion(15);
			$version = max($version, 15);
		}

		// --- v16: per-user interface language ---
		// Language used to live only in a cookie, so it was lost on a new device and could not
		// be honoured when writing *to* a user (emails) outside their own request. NULL keeps
		// the previous behaviour: resolve from cookie → site default → Accept-Language.
		if ($runStep(16)) {
			try {
				$pdo->exec("ALTER TABLE `{$prefix}users` ADD COLUMN `language` VARCHAR(5) DEFAULT NULL");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			self::persistMigrationVersion(16);
			$version = max($version, 16);
		}

		// --- v17: what happens once a download cap is reached ---
		// Until now hitting `max_downloads` just made the link answer 410 forever, leaving the
		// bytes on disk with no way to say "and then delete it". `keep` preserves that exact
		// behaviour, so existing shares are unaffected.
		if ($runStep(17)) {
			foreach (["{$prefix}files", "{$prefix}collections"] as $tbl) {
				try {
					$pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `on_limit_action` VARCHAR(10) NOT NULL DEFAULT 'keep'");
				} catch (PDOException $e) {
					self::tolerateIdempotentDdlError($e);
				}
			}

			self::persistMigrationVersion(17);
			$version = max($version, 17);
		}

		// --- v18: paid access plans (pt 9) ---
		// A plan is "buy this, get that group". How it is bought stays entirely with the
		// operator (a checkout link or an embedded snippet), so nothing here assumes a
		// provider; what the app owns is the group assignment and when it lapses.
		if ($runStep(18)) {
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}plans` (
					`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`name` VARCHAR(100) NOT NULL,
					`group_id` INT UNSIGNED DEFAULT NULL,
					`price` VARCHAR(50) NOT NULL DEFAULT '',
					`period` VARCHAR(50) NOT NULL DEFAULT '',
					`duration_days` INT UNSIGNED NOT NULL DEFAULT 0,
					`description` MEDIUMTEXT,
					`description_format` VARCHAR(10) NOT NULL DEFAULT 'markdown',
					`features` TEXT,
					`badge` VARCHAR(50) NOT NULL DEFAULT '',
					`checkout_type` VARCHAR(10) NOT NULL DEFAULT 'link',
					`checkout_url` VARCHAR(500) NOT NULL DEFAULT '',
					`checkout_html` MEDIUMTEXT,
					`button_label` VARCHAR(80) NOT NULL DEFAULT '',
					`highlighted` TINYINT(1) NOT NULL DEFAULT 0,
					`enabled` TINYINT(1) NOT NULL DEFAULT 0,
					`sort_order` INT NOT NULL DEFAULT 0,
					`created_at` INT UNSIGNED NOT NULL,
					INDEX `idx_enabled` (`enabled`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}
			// When a paid group assignment runs out. NULL = no expiry, which is what every
			// existing assignment is.
			try {
				$pdo->exec("ALTER TABLE `{$prefix}users` ADD COLUMN `group_expires_at` INT UNSIGNED DEFAULT NULL");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			self::persistMigrationVersion(18);
			$version = max($version, 18);
		}

		// --- v19: grace clock for accounts over their storage limits ---
		// A group can shrink under a user (an admin moves them, or a paid plan lapses), which
		// leaves files that would no longer be allowed. Deleting them the same second would be
		// hostile; ignoring them forever means the limit is a suggestion. So: remember when the
		// account first went over, and act only once the configured grace has passed.
		if ($runStep(19)) {
			try {
				$pdo->exec("ALTER TABLE `{$prefix}users` ADD COLUMN `limits_over_since` INT UNSIGNED DEFAULT NULL");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			self::persistMigrationVersion(19);
			$version = max($version, 19);
		}

		// --- v20: anti-abuse around registration (pkt C) ---
		// Someone farming accounts to multiply their quota needs two things: a supply of
		// addresses and a way to keep making accounts. `registered_ip` is what makes the
		// per-IP account cap possible at all (nothing recorded where an account came from),
		// and `email_reservations` closes the "register, change the address, register again
		// with the old one" loop by holding a released address for a while.
		if ($runStep(20)) {
			try {
				$pdo->exec("ALTER TABLE `{$prefix}users` ADD COLUMN `registered_ip` VARCHAR(45) DEFAULT NULL");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}
			try {
				$pdo->exec("ALTER TABLE `{$prefix}users` ADD INDEX `idx_registered_ip` (`registered_ip`)");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}email_reservations` (
					`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`email` VARCHAR(255) NOT NULL,
					`user_id` INT UNSIGNED DEFAULT NULL,
					`released_at` INT UNSIGNED NOT NULL,
					UNIQUE KEY `uniq_email` (`email`),
					INDEX `idx_released` (`released_at`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			self::persistMigrationVersion(20);
			$version = max($version, 20);
		}

		// --- v21: groups really do own the storage quota (pt 5) ---
		// `users.storage_limit` is the *per-account override* — 0 means "use the group's".
		// The column defaulted to 524288000 (500 MiB), so every account created since has
		// carried an override nobody chose, quietly beating whatever its group allowed (a
		// 500 GiB group quota was capped at 500 MiB). New rows now default to 0, and rows
		// still holding exactly that legacy default are cleared: that value was handed out
		// by the schema, never entered by an admin. A deliberate 500 MiB is indistinguishable
		// from it and is reset too — re-enter it in Użytkownicy → Zarządzaj if it was meant.
		if ($runStep(21)) {
			try {
				$pdo->exec("ALTER TABLE `{$prefix}users` ALTER COLUMN `storage_limit` SET DEFAULT 0");
			} catch (PDOException $e) {
				try {
					// MySQL < 8 / older MariaDB: no ALTER COLUMN … SET DEFAULT on this type.
					$pdo->exec("ALTER TABLE `{$prefix}users` MODIFY `storage_limit` BIGINT UNSIGNED DEFAULT 0");
				} catch (PDOException $e2) {
					throw $e2;
				}
			}
			if ($version < 21) {
				try {
					$pdo->exec(
						"UPDATE `{$prefix}users` SET `storage_limit` = 0 "
						. "WHERE `storage_limit` = 524288000"
					);
				} catch (PDOException $e) {
					throw $e;
				}
			}

			self::persistMigrationVersion(21);
			$version = max($version, 21);
		}

		// --- v22: retention per group (pt 6) ---
		// `groups.auto_delete_days`: 0 = follow the installation default from Settings →
		// Storage, N = this group's own retention, -1 = never delete. `users.group_changed_at`
		// is what makes a group change fair: retention counts from the later of the upload and
		// that moment, so files do not vanish the instant a plan lapses.
		if ($runStep(22)) {
			try {
				$pdo->exec("ALTER TABLE `{$prefix}groups` ADD COLUMN `auto_delete_days` INT NOT NULL DEFAULT 0");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}
			try {
				$pdo->exec("ALTER TABLE `{$prefix}users` ADD COLUMN `group_changed_at` INT UNSIGNED DEFAULT NULL");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			self::persistMigrationVersion(22);
			$version = max($version, 22);
		}

		// --- v23: real payments (pt 10) ---
		// A plan's `price` is display copy ("19 zł / mies."), which a payment provider cannot
		// charge. `amount_minor` + `currency` are the machine-readable half of the same thing.
		// The `payments` table is what makes a provider notification actionable at all: it maps
		// the order id we invented back to (plan, buyer) and records that the grant already
		// happened, so a retried notification — which PayU does send — cannot pay out twice.
		if ($runStep(23)) {
			foreach ([
				"ADD COLUMN `amount_minor` INT UNSIGNED NOT NULL DEFAULT 0",
				"ADD COLUMN `currency` VARCHAR(3) NOT NULL DEFAULT 'PLN'",
			] as $col) {
				try {
					$pdo->exec("ALTER TABLE `{$prefix}plans` {$col}");
				} catch (PDOException $e) {
					self::tolerateIdempotentDdlError($e);
				}
			}
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}payments` (
					`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`ext_order_id` VARCHAR(64) NOT NULL,
					`provider` VARCHAR(20) NOT NULL,
					`provider_order_id` VARCHAR(64) DEFAULT NULL,
					`plan_id` INT UNSIGNED NOT NULL,
					`user_id` INT UNSIGNED NOT NULL,
					`amount_minor` INT UNSIGNED NOT NULL DEFAULT 0,
					`currency` VARCHAR(3) NOT NULL DEFAULT 'PLN',
					`status` VARCHAR(20) NOT NULL DEFAULT 'NEW',
					`granted_at` INT UNSIGNED DEFAULT NULL,
					`processing_token` CHAR(64) DEFAULT NULL,
					`processing_started_at` INT UNSIGNED DEFAULT NULL,
					`processing_expires_at` INT UNSIGNED DEFAULT NULL,
					`fulfillment_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
					`fulfillment_last_error` VARCHAR(1000) DEFAULT NULL,
					`created_at` INT UNSIGNED NOT NULL,
					`updated_at` INT UNSIGNED NOT NULL,
					UNIQUE KEY `uniq_ext_order` (`ext_order_id`),
					INDEX `idx_user` (`user_id`),
					INDEX `idx_status` (`status`),
					INDEX `idx_fulfillment_lease` (`status`, `processing_expires_at`, `granted_at`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			self::persistMigrationVersion(23);
			$version = max($version, 23);
		}

		// --- v24: own-collection permissions (pt 4) ---
		// "Moje pliki" grew a collections block in the group editor. Everything it now gates —
		// having a collections list, creating one, adding to it, deleting it — worked for
		// everybody before this existed, so every current group is granted the lot. A permission
		// system arriving is not a reason for anyone to lose a feature they were already using;
		// from here on it is the admin's choice, but the starting point is what they had.
		//
		// `mfilter.coll_files` (one round old) is superseded by `mcfilter.files` +
		// `mcfilter.empty`, so a group holding it gets both.
		if ($runStep(24)) {
			try {
				$table = $prefix . 'groups';
				$rows = $pdo->query("SELECT `id`, `slug`, `permissions` FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
				$upd = $pdo->prepare("UPDATE `{$table}` SET `permissions` = ? WHERE `id` = ?");
				foreach ($rows as $row) {
					// The guest group never carries permissions — guests have no account.
					if (($row['slug'] ?? null) === 'guest') {
						continue;
					}
					$perms = array_filter(array_map('trim', explode(',', (string) $row['permissions'])));
					$perms = array_merge($perms, [
						'myfiles.collections',
						'myfiles.coll_create',
						'myfiles.coll_add',
						'myfiles.coll_delete',
					]);
					if (in_array('mfilter.coll_files', $perms, true)) {
						$perms = array_diff($perms, ['mfilter.coll_files']);
						$perms = array_merge($perms, ['myfiles.coll_filters', 'mcfilter.files', 'mcfilter.empty']);
					}
					$upd->execute([Permissions::serialize($perms), (int) $row['id']]);
				}
			} catch (PDOException $e) {
				throw $e;
			}

			self::persistMigrationVersion(24);
			$version = max($version, 24);
		}

		// --- v25: the payments table becomes a plan ledger (pt 2 / pt 5) ---
		// A plan granted by an administrator is as real an event in an account's history as one
		// that was paid for — and so is having it taken away. Both were happening with no trace
		// anywhere a user or an operator would look. `kind` separates them from purchases so
		// they can appear in the same list without being counted as revenue.
		if ($runStep(25)) {
			try {
				$pdo->exec("ALTER TABLE `{$prefix}payments`
					ADD COLUMN `kind` VARCHAR(20) NOT NULL DEFAULT 'purchase'");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}
			try {
				$pdo->exec("ALTER TABLE `{$prefix}payments` ADD INDEX `idx_kind` (`kind`)");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}
			// Who did it, for the admin-side entries. NULL for a purchase.
			try {
				$pdo->exec("ALTER TABLE `{$prefix}payments` ADD COLUMN `actor_id` INT UNSIGNED DEFAULT NULL");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}
			// An admin grant has no amount, so `amount_minor` may legitimately be 0 — and a
			// provider order id it never had.
			try {
				$pdo->exec("ALTER TABLE `{$prefix}payments` MODIFY `provider` VARCHAR(20) NOT NULL DEFAULT 'payu'");
			} catch (PDOException $e) {
				throw $e;
			}

			self::persistMigrationVersion(25);
			$version = max($version, 25);
		}

		// --- v26: plans that are not for sale ---
		// A pricing page that only lists what costs money answers "what do I get if I pay" and
		// leaves "what do I have right now" to guesswork. `kind` marks the two cards that exist
		// to answer the second question: `free` (the tier a registered account is on) and
		// `guest` (what someone gets without an account). Neither can be bought — `checkout_type`
		// is forced to `none` on save. `show_limits` renders the bound group's actual numbers,
		// so the card cannot drift from the limits the server enforces.
		if ($runStep(26)) {
			foreach ([
				"ADD COLUMN `kind` VARCHAR(10) NOT NULL DEFAULT 'paid'",
				"ADD COLUMN `show_limits` TINYINT(1) NOT NULL DEFAULT 0",
			] as $alter) {
				try {
					$pdo->exec("ALTER TABLE `{$prefix}plans` {$alter}");
				} catch (PDOException $e) {
					self::tolerateIdempotentDdlError($e);
				}
			}

			self::persistMigrationVersion(26);
			$version = max($version, 26);
		}

		// --- v27: in-app notifications ---
		// The app had plenty to tell an account — a plan running out, files about to be swept,
		// a quota filling up — and exactly one way to say it: an e-mail, if SMTP happened to be
		// configured. This is the other half.
		//
		// `group_key` is what makes a hundred downloads one line instead of a hundred: events
		// that describe the same thing about the same object merge into the live row and raise
		// its `count`. Reading a row closes the stack, so the next download starts a fresh
		// "someone downloaded X" rather than resurrecting one already dealt with.
		if ($runStep(27)) {
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}notifications` (
					`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`user_id` INT UNSIGNED NOT NULL,
					`type` VARCHAR(40) NOT NULL,
					`group_key` VARCHAR(80) NOT NULL DEFAULT '',
					`open_stack_key` VARCHAR(80) DEFAULT NULL,
					`dedupe_key` VARCHAR(80) DEFAULT NULL,
					`subject` VARCHAR(255) NOT NULL DEFAULT '',
					`link` VARCHAR(255) NOT NULL DEFAULT '',
					`data` TEXT DEFAULT NULL,
					`count` INT UNSIGNED NOT NULL DEFAULT 1,
					`created_at` INT UNSIGNED NOT NULL,
					`updated_at` INT UNSIGNED NOT NULL,
					`read_at` INT UNSIGNED DEFAULT NULL,
					INDEX `idx_user_time` (`user_id`, `updated_at`),
					INDEX `idx_user_unread` (`user_id`, `read_at`),
					INDEX `idx_stack` (`user_id`, `group_key`, `read_at`),
					UNIQUE KEY `uniq_open_stack` (`user_id`, `open_stack_key`),
					UNIQUE KEY `uniq_notification_dedupe` (`user_id`, `dedupe_key`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			// One row per account, holding the whole per-type on/off map as JSON. A row per
			// (user, type, channel) would be three joins to answer "may I tell this person
			// this", which is a question asked on the hot path of every download.
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}notification_prefs` (
					`user_id` INT UNSIGNED NOT NULL PRIMARY KEY,
					`prefs` TEXT DEFAULT NULL,
					`updated_at` INT UNSIGNED NOT NULL
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			self::persistMigrationVersion(27);
			$version = max($version, 27);
		}

		// --- v28: the two showcase plans become part of the installation ---
		// `free` and `guest` describe tiers that exist whether or not anybody configured them,
		// so they are seeded rather than left as something to remember to create — and marked
		// `is_system` so they can be switched off but not deleted. `auto_content` is what makes
		// them worth having: the card is generated from the bound group's limits and
		// permissions, so it cannot drift from what the server actually enforces.
		if ($runStep(28)) {
			foreach ([
				"ADD COLUMN `auto_content` TINYINT(1) NOT NULL DEFAULT 0",
				"ADD COLUMN `is_system` TINYINT(1) NOT NULL DEFAULT 0",
			] as $alter) {
				try {
					$pdo->exec("ALTER TABLE `{$prefix}plans` {$alter}");
				} catch (PDOException $e) {
					self::tolerateIdempotentDdlError($e);
				}
			}

			try {
				$plans = "{$prefix}plans";
				$groups = "{$prefix}groups";
				foreach (['free' => 'user', 'guest' => 'guest'] as $kind => $slug) {
					$groupId = $pdo->prepare("SELECT `id` FROM `{$groups}` WHERE `slug` = ?");
					$groupId->execute([$slug]);
					$gid = (int) $groupId->fetchColumn();

					// An operator who already made one of these by hand keeps it — it is adopted
					// rather than duplicated, so the upgrade does not leave two "Gość" cards.
					$existing = $pdo->prepare("SELECT `id` FROM `{$plans}` WHERE `kind` = ? ORDER BY `id` ASC LIMIT 1");
					$existing->execute([$kind]);
					$id = (int) $existing->fetchColumn();

					if ($id) {
						$pdo->prepare("UPDATE `{$plans}` SET `is_system` = 1, `show_limits` = 1,
								`group_id` = COALESCE(`group_id`, ?) WHERE `id` = ?")
							->execute([$gid ?: null, $id]);
						continue;
					}

					// Seeded switched off: a card appearing on a live pricing page because an
					// update ran is not the operator's decision.
					$pdo->prepare("INSERT INTO `{$plans}`
						(`name`, `kind`, `group_id`, `checkout_type`, `auto_content`, `show_limits`,
						 `is_system`, `enabled`, `sort_order`, `created_at`)
						VALUES (?, ?, ?, 'none', 1, 1, 1, 0, ?, ?)")
						->execute([ucfirst($kind), $kind, $gid ?: null, $kind === 'guest' ? -2 : -1, time()]);
				}
			} catch (PDOException $e) {
				throw $e;
			}

			self::persistMigrationVersion(28);
			$version = max($version, 28);
		}

		// --- v29: uploads in flight, the counterpart of `active_downloads` ---
		// The dashboard could show what was being pulled off the server and nothing at all about
		// what was being pushed onto it — which is the half that fills the disk. Rows are written
		// and updated by the upload server as it streams, and removed when the transfer ends
		// (successfully or not); a crash leaves a stale row, so readers ignore anything that has
		// not been touched for a while, exactly as the download side does.
		if ($runStep(29)) {
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}active_uploads` (
					`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`ip_address` VARCHAR(45) NOT NULL,
					`user_id` INT UNSIGNED DEFAULT NULL,
					`filename` VARCHAR(255) NOT NULL DEFAULT '',
					`size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
					`received` BIGINT UNSIGNED NOT NULL DEFAULT 0,
					`started_at` INT UNSIGNED NOT NULL,
					`updated_at` INT UNSIGNED NOT NULL,
					INDEX `idx_started` (`started_at`),
					INDEX `idx_updated` (`updated_at`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}

			self::persistMigrationVersion(29);
			$version = max($version, 29);
		}

		// --- v30: advertising system (Faza 8) ---
		// Creatives, purchasable placements and a bounded daily metrics aggregate. The
		// `payments` ledger gains `ad_id` so an ad purchase rides the existing idempotent
		// fulfilment machinery (ext_order_id / granted_at / claimForGrant) unchanged —
		// `kind` = 'ad_purchase' keeps it out of the premium revenue figures.
		if ($runStep(30)) {
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}ads` (
					`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`name` VARCHAR(120) NOT NULL,
					`type` VARCHAR(10) NOT NULL DEFAULT 'image',
					`zone` VARCHAR(48) NOT NULL,
					`owner_id` INT UNSIGNED DEFAULT NULL,
					`status` VARCHAR(10) NOT NULL DEFAULT 'draft',
					`image_path` VARCHAR(120) DEFAULT NULL,
					`image_url` VARCHAR(500) DEFAULT NULL,
					`image_mime` VARCHAR(40) DEFAULT NULL,
					`target_url` VARCHAR(500) DEFAULT NULL,
					`alt_text` VARCHAR(200) DEFAULT NULL,
					`html` MEDIUMTEXT,
					`adsense_slot` VARCHAR(32) DEFAULT NULL,
					`weight` INT UNSIGNED NOT NULL DEFAULT 1,
					`package_id` INT UNSIGNED DEFAULT NULL,
					`purchase_duration_days` INT UNSIGNED NOT NULL DEFAULT 0,
					`starts_at` INT UNSIGNED DEFAULT NULL,
					`ends_at` INT UNSIGNED DEFAULT NULL,
					`reject_reason` VARCHAR(255) DEFAULT NULL,
					`approved_at` INT UNSIGNED DEFAULT NULL,
					`approved_by` INT UNSIGNED DEFAULT NULL,
					`created_at` INT UNSIGNED NOT NULL,
					`updated_at` INT UNSIGNED NOT NULL,
					INDEX `idx_zone_status` (`zone`, `status`),
					INDEX `idx_owner` (`owner_id`),
					INDEX `idx_status` (`status`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}ad_packages` (
					`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`name` VARCHAR(120) NOT NULL,
					`description` TEXT,
					`zone` VARCHAR(48) NOT NULL,
					`duration_days` INT UNSIGNED NOT NULL DEFAULT 30,
					`amount_minor` INT UNSIGNED NOT NULL DEFAULT 0,
					`currency` VARCHAR(3) NOT NULL DEFAULT 'PLN',
					`enabled` TINYINT(1) NOT NULL DEFAULT 1,
					`sort` INT NOT NULL DEFAULT 0,
					`created_at` INT UNSIGNED NOT NULL
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}
			// One row per ad per day, incremented atomically — bounded at ads × days, which is
			// what lets an ad sit on every page view without growing a log to sweep.
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}ad_stats_daily` (
					`ad_id` INT UNSIGNED NOT NULL,
					`day` DATE NOT NULL,
					`impressions` INT UNSIGNED NOT NULL DEFAULT 0,
					`clicks` INT UNSIGNED NOT NULL DEFAULT 0,
					PRIMARY KEY (`ad_id`, `day`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}
			foreach ([
				"ADD COLUMN `ad_id` INT UNSIGNED DEFAULT NULL",
				"ADD INDEX `idx_ad` (`ad_id`)",
			] as $alter) {
				try {
					$pdo->exec("ALTER TABLE `{$prefix}payments` {$alter}");
				} catch (PDOException $e) {
					self::tolerateIdempotentDdlError($e);
				}
			}

			self::persistMigrationVersion(30);
			$version = max($version, 30);
		}

		// --- v31: ad priorities, boosts, and granular ad permissions (Faza 8, runda 2) ---
		// Packages split into placements (a zone for a period, entering rotation with the
		// package's priority as weight) and boosts (extra weight on an already-running ad,
		// for a period). `payments.package_id` records which product an order actually bought,
		// so fulfilment can tell the two apart. Every existing non-guest group is granted
		// `ads.buy` — buying was open to all logged-in users before permissions existed for
		// it, and a permission system arriving must not switch a feature off (v24 precedent).
		if ($runStep(31)) {
			foreach ([
				"ALTER TABLE `{$prefix}ad_packages` ADD COLUMN `kind` VARCHAR(10) NOT NULL DEFAULT 'placement'",
				"ALTER TABLE `{$prefix}ad_packages` ADD COLUMN `priority` INT UNSIGNED NOT NULL DEFAULT 10",
				"ALTER TABLE `{$prefix}ad_packages` ADD COLUMN `weight_bonus` INT UNSIGNED NOT NULL DEFAULT 0",
				"ALTER TABLE `{$prefix}ads` ADD COLUMN `boost_weight` INT UNSIGNED NOT NULL DEFAULT 0",
				"ALTER TABLE `{$prefix}ads` ADD COLUMN `boost_until` INT UNSIGNED DEFAULT NULL",
				"ALTER TABLE `{$prefix}payments` ADD COLUMN `package_id` INT UNSIGNED DEFAULT NULL",
			] as $ddl) {
				try {
					$pdo->exec($ddl);
				} catch (PDOException $e) {
					self::tolerateIdempotentDdlError($e);
				}
			}
			if ($version < 31) {
				try {
					$table = $prefix . 'groups';
					$rows = $pdo->query(
						"SELECT `id`, `slug`, `permissions` FROM `{$table}`"
					)->fetchAll(PDO::FETCH_ASSOC);
					$upd = $pdo->prepare(
						"UPDATE `{$table}` SET `permissions` = ? WHERE `id` = ?"
					);
					foreach ($rows as $row) {
						if (($row['slug'] ?? null) === 'guest') {
							continue; // guests have no account to buy with
						}
						$perms = array_filter(array_map(
							'trim',
							explode(',', (string) $row['permissions'])
						));
						$perms[] = 'ads.buy';
						$upd->execute([
							Permissions::serialize($perms),
							(int) $row['id'],
						]);
					}
				} catch (PDOException $e) {
					throw $e;
				}
			}

			self::persistMigrationVersion(31);
			$version = max($version, 31);
		}

		// --- v32: multi-zone ad purchases, post-expiry lifecycle, bell "seen" marker ---
		// A placement package can now sell add-on zones (surcharge per zone, JSON in
		// `addon_zones`); the extra placements are child ads linked by `parent_ad_id`.
		// `self_paused` is the owner's own kill-switch (paid time keeps running);
		// `resubmitted_at` timestamps an edit of a live ad so a slow re-review (past the
		// configured grace) can be compensated by extending `ends_at`. The banner size limit
		// moves from whole MB to KiB so the settings UI can offer KiB/MiB units.
		if ($runStep(32)) {
			foreach ([
				"ALTER TABLE `{$prefix}ads` ADD COLUMN `parent_ad_id` INT UNSIGNED DEFAULT NULL",
				"ALTER TABLE `{$prefix}ads` ADD INDEX `idx_parent` (`parent_ad_id`)",
				"ALTER TABLE `{$prefix}ads` ADD COLUMN `self_paused` TINYINT(1) NOT NULL DEFAULT 0",
				"ALTER TABLE `{$prefix}ads` ADD COLUMN `resubmitted_at` INT UNSIGNED DEFAULT NULL",
				"ALTER TABLE `{$prefix}ad_packages` ADD COLUMN `addon_zones` TEXT",
				"ALTER TABLE `{$prefix}users` ADD COLUMN `notification_seen_at` INT UNSIGNED NOT NULL DEFAULT 0",
			] as $ddl) {
				try {
					$pdo->exec($ddl);
				} catch (PDOException $e) {
					self::tolerateIdempotentDdlError($e);
				}
			}
			try {
				$mb = self::getSetting('ads_max_banner_mb', null);
				if ($mb !== null && self::getSetting('ads_max_banner_kb', null) === null) {
					self::setSetting('ads_max_banner_kb', (string) (max(1, (int) $mb) * 1024));
				}
			} catch (PDOException $e) {
				throw $e;
			}

			self::persistMigrationVersion(32);
			$version = max($version, 32);
		}

		// --- v33: an order's own metadata ---
		// A renewal may drop some of the purchase's add-on placements; which ones is a fact
		// about THIS order, decided at checkout but applied only at fulfilment (the payment
		// can still fail). JSON in `meta` — the ledger's schema stays put next time an
		// order needs to remember something.
		if ($runStep(33)) {
			try {
				$pdo->exec("ALTER TABLE `{$prefix}payments` ADD COLUMN `meta` TEXT");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}
			self::persistMigrationVersion(33);
			$version = max($version, 33);
		}

		// --- v34: housekeeping ---
		// `ads_max_banner_mb` was the banner cap's original unit; v32 converted it into
		// `ads_max_banner_kb` and the old row has been dead weight since. Dropping it ends
		// the "which of these is real" question for anyone reading the settings table.
		if ($runStep(34)) {
			try {
				$pdo->prepare("DELETE FROM `{$prefix}settings` WHERE `setting_key` = ?")
					->execute(['ads_max_banner_mb']);
				self::invalidateSettingsCache();
			} catch (PDOException $e) {
				throw $e;
			}
			self::persistMigrationVersion(34);
			$version = max($version, 34);
		}

		// --- v35: consent switch grew a third state ---
		// 2.64.0's boolean `ads_consent_required` became `ads_consent_mode` (off|bar|google)
		// when the Google-CMP option arrived; '1' meant the built-in bar.
		if ($runStep(35)) {
			try {
				if (self::getSetting('ads_consent_required', null) === '1'
					&& self::getSetting('ads_consent_mode', null) === null) {
					self::setSetting('ads_consent_mode', 'bar');
				}
				$pdo->prepare("DELETE FROM `{$prefix}settings` WHERE `setting_key` = ?")
					->execute(['ads_consent_required']);
				self::invalidateSettingsCache();
			} catch (PDOException $e) {
				throw $e;
			}
			self::persistMigrationVersion(35);
			$version = max($version, 35);
		}

		// --- v36: promo codes for the built-in premium checkout (runda 9) ---
		// Percent-only on purpose: one code works for every plan whatever its currency.
		if ($runStep(36)) {
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}promo_codes` (
					`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					`code` VARCHAR(40) NOT NULL,
					`percent_off` TINYINT UNSIGNED NOT NULL DEFAULT 10,
					`max_uses` INT UNSIGNED NOT NULL DEFAULT 0,
					`used_count` INT UNSIGNED NOT NULL DEFAULT 0,
					`expires_at` INT UNSIGNED NULL,
					`enabled` TINYINT(1) NOT NULL DEFAULT 1,
					`created_at` INT UNSIGNED NOT NULL,
					UNIQUE KEY `uniq_code` (`code`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
			} catch (PDOException $e) {
				self::tolerateIdempotentDdlError($e);
			}
			self::persistMigrationVersion(36);
			$version = max($version, 36);
		}

		// --- v37: recoverable payment-fulfilment leases (AUD-P0-06) ---
		//
		// `granted_at` used to double as the concurrency claim: it was written before the
		// product was granted. A crash or a downstream failure therefore made an unfulfilled
		// payment look permanently complete. Keep the terminal marker for actual success and
		// give in-flight work its own owner token, lease and diagnostics instead.
		if ($runStep(37)) {
			$payments = "{$prefix}payments";
			$lockName = 'filehost:migrate:v37:' . sha1((defined('DB_NAME') ? DB_NAME : '') . ':' . $prefix);
			$locked = false;
			$migrated = false;

			try {
				$lockStmt = $pdo->prepare("SELECT GET_LOCK(?, 10)");
				$lockStmt->execute([$lockName]);
				$locked = (int) $lockStmt->fetchColumn() === 1;
				if (!$locked) {
					throw new RuntimeException('could not acquire the v37 migration lock');
				}

				$columns = $pdo->query("SHOW COLUMNS FROM `{$payments}`")->fetchAll(PDO::FETCH_COLUMN);
				$definitions = [
					'processing_token' => "CHAR(64) DEFAULT NULL AFTER `granted_at`",
					'processing_started_at' => "INT UNSIGNED DEFAULT NULL AFTER `processing_token`",
					'processing_expires_at' => "INT UNSIGNED DEFAULT NULL AFTER `processing_started_at`",
					'fulfillment_attempts' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER `processing_expires_at`",
					'fulfillment_last_error' => "VARCHAR(1000) DEFAULT NULL AFTER `fulfillment_attempts`",
				];
				foreach ($definitions as $column => $definition) {
					if (!in_array($column, $columns, true)) {
						$pdo->exec("ALTER TABLE `{$payments}` ADD COLUMN `{$column}` {$definition}");
						$columns[] = $column;
					}
				}

				$indexes = $pdo->query("SHOW INDEX FROM `{$payments}`")->fetchAll(PDO::FETCH_ASSOC);
				$indexNames = array_column($indexes, 'Key_name');
				if (!in_array('idx_fulfillment_lease', $indexNames, true)) {
					$pdo->exec("ALTER TABLE `{$payments}`
						ADD INDEX `idx_fulfillment_lease` (`status`, `processing_expires_at`, `granted_at`)");
				}

				self::persistMigrationVersion(37);
				$version = max($version, 37);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v37 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(37, $e);
				throw $e;
			} finally {
				if ($locked) {
					try {
						$release = $pdo->prepare("SELECT RELEASE_LOCK(?)");
						$release->execute([$lockName]);
					} catch (\Throwable $e) {
					}
				}
			}

			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v38: versioned browser sessions and immediate credential revocation (AUD-P0-03) ---
		//
		// A session carries the value observed at login. Password, role, account-status and
		// 2FA mutations advance this counter, so every already-open browser is rejected on its
		// next request. The advisory lock keeps concurrent PHP workers from racing the DDL.
		if ($runStep(38)) {
			$users = "{$prefix}users";
			$lockName = 'filehost:migrate:v38:' . sha1((defined('DB_NAME') ? DB_NAME : '') . ':' . $prefix);
			$locked = false;
			$migrated = false;

			try {
				$lockStmt = $pdo->prepare("SELECT GET_LOCK(?, 10)");
				$lockStmt->execute([$lockName]);
				$locked = (int) $lockStmt->fetchColumn() === 1;
				if (!$locked) {
					throw new RuntimeException('could not acquire the v38 migration lock');
				}

				$columns = $pdo->query("SHOW COLUMNS FROM `{$users}`")->fetchAll(PDO::FETCH_COLUMN);
				if (!in_array('session_version', $columns, true)) {
					$pdo->exec(
						"ALTER TABLE `{$users}`
						 ADD COLUMN `session_version` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `is_active`"
					);
				}

				$verify = $pdo->prepare(
					"SELECT COUNT(*) FROM information_schema.COLUMNS
					 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'session_version'"
				);
				$verify->execute([$users]);
				if ((int) $verify->fetchColumn() !== 1) {
					throw new RuntimeException('users.session_version is missing after migration');
				}

				self::persistMigrationVersion(38);
				$version = max($version, 38);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v38 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(38, $e);
				throw $e;
			} finally {
				if ($locked) {
					try {
						$release = $pdo->prepare("SELECT RELEASE_LOCK(?)");
						$release->execute([$lockName]);
					} catch (\Throwable $e) {
					}
				}
			}

			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v39: active browser-document extensions are never uploadable by default ---
		//
		// The installer protects new deployments; this migration closes the same gap for an
		// existing settings row instead of relying on an operator to discover and edit it.
		if ($runStep(39)) {
			$lockName = 'filehost:migrate:v39:' . sha1((defined('DB_NAME') ? DB_NAME : '') . ':' . $prefix);
			$locked = false;
			$migrated = false;

			try {
				$lockStmt = $pdo->prepare("SELECT GET_LOCK(?, 10)");
				$lockStmt->execute([$lockName]);
				$locked = (int) $lockStmt->fetchColumn() === 1;
				if (!$locked) {
					throw new RuntimeException('could not acquire the v39 migration lock');
				}

				$blocked = array_values(array_unique(array_filter(array_map(
					static fn(string $ext): string => strtolower(ltrim(trim($ext), '.')),
					explode(',', (string) self::getSetting('blocked_extensions', ''))
				))));
				foreach (['html', 'htm', 'xhtml', 'svg', 'shtml', 'xml'] as $activeExtension) {
					if (!in_array($activeExtension, $blocked, true)) {
						$blocked[] = $activeExtension;
					}
				}
				sort($blocked, SORT_STRING);

				if (!self::setSetting('blocked_extensions', implode(',', $blocked))) {
					throw new RuntimeException('could not persist the active-document blocklist');
				}
				self::persistMigrationVersion(39);
				$version = max($version, 39);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v39 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(39, $e);
				throw $e;
			} finally {
				if ($locked) {
					try {
						$release = $pdo->prepare("SELECT RELEASE_LOCK(?)");
						$release->execute([$lockName]);
					} catch (\Throwable $e) {
					}
				}
			}

			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v40: hashed, expiring, one-time account lifecycle capabilities (AUD-P1-09) ---
		if ($runStep(40)) {
			$users = "{$prefix}users";
			$recovery = "{$prefix}recovery_tokens";
			$migrated = false;
			try {
				$userColumns = $pdo->query("SHOW COLUMNS FROM `{$users}`")->fetchAll(PDO::FETCH_COLUMN);
				$userDefinitions = [
					'activation_token' => 'VARCHAR(64) DEFAULT NULL',
					'activation_expires_at' => 'INT UNSIGNED DEFAULT NULL',
					'last_activation_email_at' => 'INT UNSIGNED NOT NULL DEFAULT 0',
					'email_change_expires_at' => 'INT UNSIGNED DEFAULT NULL',
				];
				foreach ($userDefinitions as $column => $definition) {
					if (!in_array($column, $userColumns, true)) {
						$pdo->exec("ALTER TABLE `{$users}` ADD COLUMN `{$column}` {$definition}");
					}
				}

				// Keep the newest row if an older installation somehow accumulated duplicates.
				$pdo->exec(
					"DELETE old_row FROM `{$recovery}` old_row
					 JOIN `{$recovery}` new_row
					   ON new_row.`user_id` = old_row.`user_id`
					  AND (
					  	new_row.`created_at` > old_row.`created_at`
					  	OR (
					  		new_row.`created_at` = old_row.`created_at`
					  		AND new_row.`token` > old_row.`token`
					  	)
					  )"
				);
				$indexes = $pdo->query("SHOW INDEX FROM `{$recovery}`")->fetchAll(PDO::FETCH_ASSOC);
				if (!in_array('uniq_recovery_user', array_column($indexes, 'Key_name'), true)) {
					$pdo->exec(
						"ALTER TABLE `{$recovery}`
						 ADD UNIQUE INDEX `uniq_recovery_user` (`user_id`)"
					);
				}

				if ($version < 40) {
					// Hashing is indistinguishable from hashing an already-hashed 64-hex
					// capability. Commit all three data transforms together with the version
					// marker, after every implicit-commit DDL operation has finished.
					$pdo->beginTransaction();
					try {
						$pdo->exec(
							"UPDATE `{$users}` SET
							 `activation_token` = SHA2(`activation_token`, 256),
							 `activation_expires_at` = COALESCE(
							 	`activation_expires_at`,
							 	`last_activation_email_at` + 86400
							 )
							 WHERE `activation_token` IS NOT NULL"
						);
						$pdo->exec(
							"UPDATE `{$users}` SET
							 `email_change_token` = SHA2(`email_change_token`, 256),
							 `email_change_expires_at` = COALESCE(
							 	`email_change_expires_at`,
							 	UNIX_TIMESTAMP() + 900
							 )
							 WHERE `email_change_token` IS NOT NULL"
						);
						$pdo->exec(
							"UPDATE `{$recovery}` SET `token` = SHA2(`token`, 256)"
						);
						self::persistMigrationVersion(40);
						$pdo->commit();
					} catch (\Throwable $e) {
						if ($pdo->inTransaction()) {
							$pdo->rollBack();
						}
						self::invalidateSettingsCache();
						throw $e;
					}
				} else {
					self::persistMigrationVersion(40);
				}
				$version = max($version, 40);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v40 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(40, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v41: encrypt TOTP seeds at rest and make room for authenticated ciphertext ---
		if ($runStep(41)) {
			$users = "{$prefix}users";
			$migrated = false;
			try {
				$pdo->exec("ALTER TABLE `{$users}` MODIFY COLUMN `totp_secret` TEXT DEFAULT NULL");
				$stmt = $pdo->query(
					"SELECT `id`, `totp_secret` FROM `{$users}`
					 WHERE `totp_secret` IS NOT NULL AND `totp_secret` <> ''"
				);
				$save = $pdo->prepare("UPDATE `{$users}` SET `totp_secret` = ? WHERE `id` = ?");
				foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
					$stored = (string) $row['totp_secret'];
					if (!Crypto::isEncrypted($stored)) {
						$save->execute([Crypto::encrypt($stored), (int) $row['id']]);
					}
				}
				self::persistMigrationVersion(41);
				$version = max($version, 41);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v41 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(41, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v42: durable filesystem deletion outbox (AUD-P1-15) ---
		//
		// Database rows become unreachable in the business transaction. Physical upload and
		// thumbnail removal is retried from this queue, so a transient filesystem error cannot
		// resurrect metadata or leave account deletion half-complete.
		if ($runStep(42)) {
			$queue = "{$prefix}file_deletion_queue";
			$migrated = false;
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$queue}` (
					`file_id` VARCHAR(32) NOT NULL PRIMARY KEY,
					`attempts` INT UNSIGNED NOT NULL DEFAULT 0,
					`next_attempt_at` INT UNSIGNED NOT NULL DEFAULT 0,
					`last_error` VARCHAR(1000) DEFAULT NULL,
					`created_at` INT UNSIGNED NOT NULL,
					INDEX `idx_deletion_due` (`next_attempt_at`, `created_at`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

				$columns = $pdo->query("SHOW COLUMNS FROM `{$queue}`")->fetchAll(PDO::FETCH_COLUMN);
				foreach (['file_id', 'attempts', 'next_attempt_at', 'last_error', 'created_at'] as $column) {
					if (!in_array($column, $columns, true)) {
						throw new RuntimeException("file deletion queue is missing {$column}");
					}
				}
				self::persistMigrationVersion(42);
				$version = max($version, 42);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v42 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(42, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v43: immutable payment products, promo reservations and ad-file outbox ---
		if ($runStep(43)) {
			$payments = "{$prefix}payments";
			$promos = "{$prefix}promo_codes";
			$migrated = false;
			try {
				$paymentColumns = $pdo->query(
					"SHOW COLUMNS FROM `{$payments}`"
				)->fetchAll(PDO::FETCH_COLUMN);
				if (!in_array('product_snapshot', $paymentColumns, true)) {
					$pdo->exec(
						"ALTER TABLE `{$payments}` ADD COLUMN `product_snapshot` MEDIUMTEXT DEFAULT NULL"
					);
				}

				$promoColumns = $pdo->query(
					"SHOW COLUMNS FROM `{$promos}`"
				)->fetchAll(PDO::FETCH_COLUMN);
				if (!in_array('reserved_count', $promoColumns, true)) {
					$pdo->exec(
						"ALTER TABLE `{$promos}` ADD COLUMN `reserved_count`
						 INT UNSIGNED NOT NULL DEFAULT 0 AFTER `used_count`"
					);
				}

				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}promo_reservations` (
					`ext_order_id` VARCHAR(64) NOT NULL PRIMARY KEY,
					`promo_id` INT UNSIGNED NOT NULL,
					`status` VARCHAR(12) NOT NULL DEFAULT 'reserved',
					`created_at` INT UNSIGNED NOT NULL,
					`updated_at` INT UNSIGNED NOT NULL,
					INDEX `idx_promo_reservation` (`promo_id`, `status`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}payment_events` (
					`provider` VARCHAR(20) NOT NULL,
					`event_id` CHAR(64) NOT NULL,
					`ext_order_id` VARCHAR(64) NOT NULL DEFAULT '',
					`provider_status` VARCHAR(32) NOT NULL DEFAULT '',
					`received_at` INT UNSIGNED NOT NULL,
					PRIMARY KEY (`provider`, `event_id`),
					INDEX `idx_payment_event_order` (`ext_order_id`, `received_at`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}ad_file_deletion_queue` (
					`filename` VARCHAR(120) NOT NULL PRIMARY KEY,
					`attempts` INT UNSIGNED NOT NULL DEFAULT 0,
					`next_attempt_at` INT UNSIGNED NOT NULL DEFAULT 0,
					`last_error` VARCHAR(1000) DEFAULT NULL,
					`created_at` INT UNSIGNED NOT NULL,
					INDEX `idx_ad_deletion_due` (`next_attempt_at`, `created_at`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

				self::persistMigrationVersion(43);
				$version = max($version, 43);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v43 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(43, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v44: atomic byte reservations for concurrent uploads (AUD-P1-11) ---
		if ($runStep(44)) {
			$reservations = "{$prefix}upload_storage_reservations";
			$migrated = false;
			try {
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$reservations}` (
					`id` CHAR(32) NOT NULL PRIMARY KEY,
					`user_id` INT UNSIGNED DEFAULT NULL,
					`size` BIGINT UNSIGNED NOT NULL,
					`expires_at` INT UNSIGNED NOT NULL,
					`created_at` INT UNSIGNED NOT NULL,
					INDEX `idx_upload_reservation_expiry` (`expires_at`),
					INDEX `idx_upload_reservation_user` (`user_id`, `expires_at`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

				$columns = $pdo->query(
					"SHOW COLUMNS FROM `{$reservations}`"
				)->fetchAll(PDO::FETCH_COLUMN);
				$columnDefinitions = [
					'id' => 'CHAR(32) NOT NULL',
					'user_id' => 'INT UNSIGNED DEFAULT NULL',
					'size' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
					'expires_at' => 'INT UNSIGNED NOT NULL DEFAULT 0',
					'created_at' => 'INT UNSIGNED NOT NULL DEFAULT 0',
				];
				foreach ($columnDefinitions as $column => $definition) {
					if (!in_array($column, $columns, true)) {
						$pdo->exec(
							"ALTER TABLE `{$reservations}` "
							. "ADD COLUMN `{$column}` {$definition}"
						);
						$columns[] = $column;
					}
				}
				$indexes = $pdo->query(
					"SHOW INDEX FROM `{$reservations}`"
				)->fetchAll(PDO::FETCH_ASSOC);
				$indexNames = array_column($indexes, 'Key_name');
				if (!in_array('PRIMARY', $indexNames, true)) {
					$pdo->exec(
						"ALTER TABLE `{$reservations}` ADD PRIMARY KEY (`id`)"
					);
				}
				if (!in_array('idx_upload_reservation_expiry', $indexNames, true)) {
					$pdo->exec(
						"ALTER TABLE `{$reservations}` "
						. "ADD INDEX `idx_upload_reservation_expiry` (`expires_at`)"
					);
				}
				if (!in_array('idx_upload_reservation_user', $indexNames, true)) {
					$pdo->exec(
						"ALTER TABLE `{$reservations}` "
						. "ADD INDEX `idx_upload_reservation_user` (`user_id`, `expires_at`)"
					);
				}
				self::persistMigrationVersion(44);
				$version = max($version, 44);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v44 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(44, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v45: retain the paid ad duration independently of mutable package rows ---
		if ($runStep(45)) {
			$ads = "{$prefix}ads";
			$migrated = false;
			try {
				$columns = $pdo->query("SHOW COLUMNS FROM `{$ads}`")->fetchAll(PDO::FETCH_COLUMN);
				if (!in_array('purchase_duration_days', $columns, true)) {
					$pdo->exec(
						"ALTER TABLE `{$ads}` ADD COLUMN `purchase_duration_days`
						 INT UNSIGNED NOT NULL DEFAULT 0 AFTER `package_id`"
					);
				}
				$pdo->exec(
					"UPDATE `{$ads}` a
					 JOIN `{$prefix}ad_packages` p ON p.`id` = a.`package_id`
					 SET a.`purchase_duration_days` = p.`duration_days`
					 WHERE a.`purchase_duration_days` = 0"
				);
				self::persistMigrationVersion(45);
				$version = max($version, 45);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v45 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(45, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v46: bind a paid entitlement to the order that currently owns it ---
		if ($runStep(46)) {
			$users = "{$prefix}users";
			$migrated = false;
			try {
				$columns = $pdo->query("SHOW COLUMNS FROM `{$users}`")->fetchAll(PDO::FETCH_COLUMN);
				if (!in_array('group_payment_ext_order_id', $columns, true)) {
					$pdo->exec(
						"ALTER TABLE `{$users}` ADD COLUMN `group_payment_ext_order_id`
						 VARCHAR(64) DEFAULT NULL AFTER `group_expires_at`"
					);
				}
				self::persistMigrationVersion(46);
				$version = max($version, 46);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v46 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(46, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v47: indexes for bounded API traversal + durable migration journal ---
		if ($runStep(47)) {
			$files = "{$prefix}files";
			$migrated = false;
			try {
				$indexes = $pdo->query("SHOW INDEX FROM `{$files}`")->fetchAll(PDO::FETCH_ASSOC);
				$indexNames = array_column($indexes, 'Key_name');
				if (!in_array('idx_user_uploaded_id', $indexNames, true)) {
					$pdo->exec(
						"ALTER TABLE `{$files}`
						 ADD INDEX `idx_user_uploaded_id` (`user_id`, `uploaded_at`, `id`)"
					);
				}
				self::persistMigrationVersion(47);
				$version = max($version, 47);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v47 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(47, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v48: multi-worker ownership and leases for active downloads ---
		if ($runStep(48)) {
			$active = "{$prefix}active_downloads";
			$migrated = false;
			try {
				$columns = $pdo->query("SHOW COLUMNS FROM `{$active}`")->fetchAll(PDO::FETCH_COLUMN);
				if (!in_array('instance_id', $columns, true)) {
					$pdo->exec(
						"ALTER TABLE `{$active}` ADD COLUMN `instance_id`
						 CHAR(32) NOT NULL DEFAULT '' AFTER `started_at`"
					);
				}
				if (!in_array('heartbeat_at', $columns, true)) {
					$pdo->exec(
						"ALTER TABLE `{$active}` ADD COLUMN `heartbeat_at`
						 INT UNSIGNED NOT NULL DEFAULT 0 AFTER `instance_id`"
					);
				}
				$pdo->exec(
					"UPDATE `{$active}` SET `heartbeat_at` = `started_at`
					 WHERE `heartbeat_at` = 0"
				);
				$indexes = $pdo->query("SHOW INDEX FROM `{$active}`")->fetchAll(PDO::FETCH_ASSOC);
				if (!in_array('idx_active_heartbeat', array_column($indexes, 'Key_name'), true)) {
					$pdo->exec(
						"ALTER TABLE `{$active}` ADD INDEX `idx_active_heartbeat` (`heartbeat_at`)"
					);
				}
				self::persistMigrationVersion(48);
				$version = max($version, 48);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v48 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(48, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v49: exclusive webhook delivery leases and receiver-visible event IDs ---
		if ($runStep(49)) {
			$deliveries = "{$prefix}webhook_deliveries";
			$migrated = false;
			try {
				$columns = $pdo->query(
					"SHOW COLUMNS FROM `{$deliveries}`"
				)->fetchAll(PDO::FETCH_COLUMN);
				foreach ([
					'event_id' => "ADD COLUMN `event_id` CHAR(32) DEFAULT NULL AFTER `webhook_id`",
					'lease_owner' => "ADD COLUMN `lease_owner` CHAR(32) DEFAULT NULL AFTER `next_attempt_at`",
					'lease_until' => "ADD COLUMN `lease_until` INT UNSIGNED DEFAULT NULL AFTER `lease_owner`",
					'delivered_at' => "ADD COLUMN `delivered_at` INT UNSIGNED DEFAULT NULL AFTER `lease_until`",
				] as $column => $ddl) {
					if (!in_array($column, $columns, true)) {
						$pdo->exec("ALTER TABLE `{$deliveries}` {$ddl}");
					}
				}
				$pdo->exec(
					"UPDATE `{$deliveries}` SET `event_id` = MD5(CONCAT('legacy:', `id`))
					 WHERE `event_id` IS NULL OR `event_id` = ''"
				);
				$pdo->exec(
					"ALTER TABLE `{$deliveries}` MODIFY `event_id` CHAR(32) NOT NULL"
				);
				$indexes = $pdo->query(
					"SHOW INDEX FROM `{$deliveries}`"
				)->fetchAll(PDO::FETCH_ASSOC);
				$indexNames = array_column($indexes, 'Key_name');
				if (!in_array('uniq_event_id', $indexNames, true)) {
					$pdo->exec(
						"ALTER TABLE `{$deliveries}` ADD UNIQUE INDEX `uniq_event_id` (`event_id`)"
					);
				}
				if (!in_array('idx_webhook_lease', $indexNames, true)) {
					$pdo->exec(
						"ALTER TABLE `{$deliveries}`
						 ADD INDEX `idx_webhook_lease` (`status`, `lease_until`)"
					);
				}
				self::persistMigrationVersion(49);
				$version = max($version, 49);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v49 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(49, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v50: atomic notification stacking and once-only deduplication ---
		if ($runStep(50)) {
			$notifications = "{$prefix}notifications";
			$migrated = false;
			try {
				$columns = $pdo->query(
					"SHOW COLUMNS FROM `{$notifications}`"
				)->fetchAll(PDO::FETCH_COLUMN);
				if (!in_array('open_stack_key', $columns, true)) {
					$pdo->exec(
						"ALTER TABLE `{$notifications}` ADD COLUMN `open_stack_key`
						 VARCHAR(80) DEFAULT NULL AFTER `group_key`"
					);
				}
				if (!in_array('dedupe_key', $columns, true)) {
					$pdo->exec(
						"ALTER TABLE `{$notifications}` ADD COLUMN `dedupe_key`
						 VARCHAR(80) DEFAULT NULL AFTER `open_stack_key`"
					);
				}
				$indexes = $pdo->query(
					"SHOW INDEX FROM `{$notifications}`"
				)->fetchAll(PDO::FETCH_ASSOC);
				$indexNames = array_column($indexes, 'Key_name');
				if (!in_array('uniq_open_stack', $indexNames, true)) {
					$pdo->exec(
						"ALTER TABLE `{$notifications}`
						 ADD UNIQUE INDEX `uniq_open_stack` (`user_id`, `open_stack_key`)"
					);
				}
				if (!in_array('uniq_notification_dedupe', $indexNames, true)) {
					$pdo->exec(
						"ALTER TABLE `{$notifications}`
						 ADD UNIQUE INDEX `uniq_notification_dedupe` (`user_id`, `dedupe_key`)"
					);
				}
				self::persistMigrationVersion(50);
				$version = max($version, 50);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v50 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(50, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v51: durable daily traffic aggregates and bounded detail retention ---
		if ($runStep(51)) {
			$daily = "{$prefix}traffic_daily";
			$migrated = false;
			try {
				self::createOrRepairTable(
					$pdo,
					"CREATE TABLE IF NOT EXISTS `{$daily}` (
					 `day` DATE NOT NULL,
					 `transfer_type` ENUM('upload', 'download') NOT NULL,
					 `transfer_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
					 `transfer_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
					 PRIMARY KEY (`day`, `transfer_type`)
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
				);
				self::persistMigrationVersion(51);
				$version = max($version, 51);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v51 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(51, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v52: durable transfer lifecycle (reserved → started → completed/released) ---
		if ($runStep(52)) {
			$reservations = "{$prefix}download_reservations";
			$migrated = false;
			try {
				self::createOrRepairTable(
					$pdo,
					"CREATE TABLE IF NOT EXISTS `{$reservations}` (
					 `id` CHAR(32) NOT NULL PRIMARY KEY,
					 `resource_type` VARCHAR(16) NOT NULL,
					 `resource_id` VARCHAR(64) NOT NULL,
					 `token_fingerprint` CHAR(64) NOT NULL,
					 `active_download_id` INT UNSIGNED DEFAULT NULL,
					 `user_id` INT UNSIGNED DEFAULT NULL,
					 `ip_address` VARCHAR(45) NOT NULL,
					 `state` VARCHAR(12) NOT NULL DEFAULT 'reserved',
					 `bytes_sent` BIGINT UNSIGNED NOT NULL DEFAULT 0,
					 `lease_until` INT UNSIGNED NOT NULL,
					 `created_at` INT UNSIGNED NOT NULL,
					 `started_at` INT UNSIGNED DEFAULT NULL,
					 `finished_at` INT UNSIGNED DEFAULT NULL,
					 `updated_at` INT UNSIGNED NOT NULL,
					 INDEX `idx_download_reservation_lease` (`state`, `lease_until`),
					 INDEX `idx_download_reservation_resource` (`resource_type`, `resource_id`)
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
				);
				self::persistMigrationVersion(52);
				$version = max($version, 52);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v52 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(52, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v53: compensatable download claims and completion-time counters ---
		if ($runStep(53)) {
			$files = "{$prefix}files";
			$collections = "{$prefix}collections";
			$tokens = "{$prefix}download_tokens";
			$reservations = "{$prefix}download_reservations";
			$effects = "{$prefix}download_reservation_effects";
			$migrated = false;
			try {
				$columnSets = [
					$files => [
						'consume_reservation_id' => 'CHAR(32) DEFAULT NULL',
					],
					$collections => [
						'consume_reservation_id' => 'CHAR(32) DEFAULT NULL',
					],
					$tokens => [
						'reservation_id' => 'CHAR(32) DEFAULT NULL',
						'reserved_until' => 'INT UNSIGNED DEFAULT NULL',
						'used_at' => 'INT UNSIGNED DEFAULT NULL',
					],
				];
				foreach ($columnSets as $table => $definitions) {
					$columns = $pdo->query(
						"SHOW COLUMNS FROM `{$table}`"
					)->fetchAll(PDO::FETCH_COLUMN);
					foreach ($definitions as $column => $definition) {
						if (!in_array($column, $columns, true)) {
							$pdo->exec(
								"ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}"
							);
						}
					}
				}

				$tokenIndexes = $pdo->query(
					"SHOW INDEX FROM `{$tokens}`"
				)->fetchAll(PDO::FETCH_ASSOC);
				if (!in_array(
					'idx_download_token_reservation',
					array_column($tokenIndexes, 'Key_name'),
					true
				)) {
					$pdo->exec(
						"ALTER TABLE `{$tokens}` ADD INDEX `idx_download_token_reservation` "
						. "(`reservation_id`, `reserved_until`)"
					);
				}

				self::createOrRepairTable(
					$pdo,
					"CREATE TABLE IF NOT EXISTS `{$effects}` (
					 `reservation_id` CHAR(32) NOT NULL,
					 `effect_type` VARCHAR(16) NOT NULL,
					 `resource_type` VARCHAR(16) NOT NULL,
					 `resource_id` VARCHAR(64) NOT NULL,
					 `applied_at` INT UNSIGNED DEFAULT NULL,
					 PRIMARY KEY (`reservation_id`, `effect_type`, `resource_type`, `resource_id`),
					 INDEX `idx_download_effect_resource`
					  (`effect_type`, `resource_type`, `resource_id`, `reservation_id`)
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
				);

				if ($version < 53) {
					// Reservations created by schema 52 already spent their counters and
					// tokens. They have no effect manifest, so the historical transition
					// publishes them as terminal. A structural forward-repair at v53+ must
					// leave current live reservations untouched.
					$now = time();
					$stmt = $pdo->prepare(
						"UPDATE `{$reservations}` SET `state` = 'completed',
						 `finished_at` = COALESCE(`finished_at`, ?), `lease_until` = ?,
						 `updated_at` = ?
						 WHERE `state` IN ('reserved', 'started')"
					);
					$stmt->execute([$now, $now, $now]);
				}

				self::persistMigrationVersion(53);
				$version = max($version, 53);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v53 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(53, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v54: relational integrity with explicit retention policies ---
		if ($runStep(54)) {
			$migrated = false;
			try {
				$users = "{$prefix}users";
				$groups = "{$prefix}groups";
				$files = "{$prefix}files";
				$collections = "{$prefix}collections";
				$payments = "{$prefix}payments";

				// Financial history survives catalog/account deletion, so its foreign
				// identifiers must be nullable before the SET NULL constraints are installed.
				$pdo->exec(
					"ALTER TABLE `{$payments}`
					 MODIFY COLUMN `plan_id` INT UNSIGNED DEFAULT NULL,
					 MODIFY COLUMN `user_id` INT UNSIGNED DEFAULT NULL"
				);

				// Destructive relations: rows have no meaning without their parent.
				foreach ([
					"DELETE d FROM `{$prefix}webhook_deliveries` d
					 LEFT JOIN `{$prefix}webhooks` w ON w.`id` = d.`webhook_id`
					 WHERE w.`id` IS NULL",
					"DELETE w FROM `{$prefix}webhooks` w
					 LEFT JOIN `{$users}` u ON u.`id` = w.`user_id`
					 WHERE u.`id` IS NULL",
					"DELETE cf FROM `{$prefix}collection_files` cf
					 LEFT JOIN `{$collections}` c ON c.`id` = cf.`collection_id`
					 LEFT JOIN `{$files}` f ON f.`id` = cf.`file_id`
					 WHERE c.`id` IS NULL OR f.`id` IS NULL",
					"DELETE c FROM `{$collections}` c
					 LEFT JOIN `{$users}` u ON u.`id` = c.`user_id`
					 WHERE c.`user_id` IS NOT NULL AND u.`id` IS NULL",
					"DELETE r FROM `{$prefix}reports` r
					 LEFT JOIN `{$files}` f ON f.`id` = r.`file_id`
					 WHERE f.`id` IS NULL",
					"DELETE k FROM `{$prefix}api_keys` k
					 LEFT JOIN `{$users}` u ON u.`id` = k.`user_id`
					 WHERE u.`id` IS NULL",
					"DELETE n FROM `{$prefix}notifications` n
					 LEFT JOIN `{$users}` u ON u.`id` = n.`user_id`
					 WHERE u.`id` IS NULL",
					"DELETE p FROM `{$prefix}notification_prefs` p
					 LEFT JOIN `{$users}` u ON u.`id` = p.`user_id`
					 WHERE u.`id` IS NULL",
					"DELETE r FROM `{$prefix}recovery_tokens` r
					 LEFT JOIN `{$users}` u ON u.`id` = r.`user_id`
					 WHERE u.`id` IS NULL",
					"DELETE r FROM `{$prefix}totp_recovery_codes` r
					 LEFT JOIN `{$users}` u ON u.`id` = r.`user_id`
					 WHERE u.`id` IS NULL",
					"DELETE t FROM `{$prefix}upload_tokens` t
					 LEFT JOIN `{$users}` u ON u.`id` = t.`user_id`
					 WHERE t.`user_id` IS NOT NULL AND u.`id` IS NULL",
					"DELETE t FROM `{$prefix}download_tokens` t
					 LEFT JOIN `{$users}` u ON u.`id` = t.`user_id`
					 WHERE t.`user_id` IS NOT NULL AND u.`id` IS NULL",
					"DELETE a FROM `{$prefix}active_uploads` a
					 LEFT JOIN `{$users}` u ON u.`id` = a.`user_id`
					 WHERE a.`user_id` IS NOT NULL AND u.`id` IS NULL",
					"DELETE r FROM `{$prefix}upload_storage_reservations` r
					 LEFT JOIN `{$users}` u ON u.`id` = r.`user_id`
					 WHERE r.`user_id` IS NOT NULL AND u.`id` IS NULL",
					"DELETE s FROM `{$prefix}ad_stats_daily` s
					 LEFT JOIN `{$prefix}ads` a ON a.`id` = s.`ad_id`
					 WHERE a.`id` IS NULL",
					"DELETE e FROM `{$prefix}download_reservation_effects` e
					 LEFT JOIN `{$prefix}download_reservations` r
					  ON r.`id` = e.`reservation_id`
					 WHERE r.`id` IS NULL",
					"DELETE pr FROM `{$prefix}promo_reservations` pr
					 LEFT JOIN `{$prefix}promo_codes` pc ON pc.`id` = pr.`promo_id`
					 LEFT JOIN `{$payments}` p ON p.`ext_order_id` = pr.`ext_order_id`
					 WHERE pc.`id` IS NULL OR p.`ext_order_id` IS NULL",
				] as $cleanupSql) {
					$pdo->exec($cleanupSql);
				}

				// Historical/optional ownership: preserve the row and remove only a dangling id.
				foreach ([
					"UPDATE `{$files}` f LEFT JOIN `{$users}` u ON u.`id` = f.`user_id`
					 SET f.`user_id` = NULL
					 WHERE f.`user_id` IS NOT NULL AND u.`id` IS NULL",
					"UPDATE `{$users}` u LEFT JOIN `{$groups}` g ON g.`id` = u.`group_id`
					 SET u.`group_id` = NULL, u.`group_expires_at` = NULL
					 WHERE u.`group_id` IS NOT NULL AND g.`id` IS NULL",
					"UPDATE `{$prefix}plans` p LEFT JOIN `{$groups}` g ON g.`id` = p.`group_id`
					 SET p.`group_id` = NULL
					 WHERE p.`group_id` IS NOT NULL AND g.`id` IS NULL",
					"UPDATE `{$payments}` p LEFT JOIN `{$users}` u ON u.`id` = p.`user_id`
					 SET p.`user_id` = NULL
					 WHERE p.`user_id` IS NOT NULL AND u.`id` IS NULL",
					"UPDATE `{$payments}` p LEFT JOIN `{$users}` u ON u.`id` = p.`actor_id`
					 SET p.`actor_id` = NULL
					 WHERE p.`actor_id` IS NOT NULL AND u.`id` IS NULL",
					"UPDATE `{$payments}` p LEFT JOIN `{$prefix}plans` pl ON pl.`id` = p.`plan_id`
					 SET p.`plan_id` = NULL
					 WHERE p.`plan_id` IS NOT NULL AND pl.`id` IS NULL",
					"UPDATE `{$payments}` p LEFT JOIN `{$prefix}ads` a ON a.`id` = p.`ad_id`
					 SET p.`ad_id` = NULL
					 WHERE p.`ad_id` IS NOT NULL AND a.`id` IS NULL",
					"UPDATE `{$payments}` p LEFT JOIN `{$prefix}ad_packages` ap
					  ON ap.`id` = p.`package_id`
					 SET p.`package_id` = NULL
					 WHERE p.`package_id` IS NOT NULL AND ap.`id` IS NULL",
					"UPDATE `{$prefix}ads` a LEFT JOIN `{$users}` u ON u.`id` = a.`owner_id`
					 SET a.`owner_id` = NULL
					 WHERE a.`owner_id` IS NOT NULL AND u.`id` IS NULL",
					"UPDATE `{$prefix}ads` a LEFT JOIN `{$users}` u ON u.`id` = a.`approved_by`
					 SET a.`approved_by` = NULL
					 WHERE a.`approved_by` IS NOT NULL AND u.`id` IS NULL",
					"UPDATE `{$prefix}ads` a LEFT JOIN `{$prefix}ad_packages` ap
					  ON ap.`id` = a.`package_id`
					 SET a.`package_id` = NULL
					 WHERE a.`package_id` IS NOT NULL AND ap.`id` IS NULL",
					"UPDATE `{$prefix}traffic_logs` t LEFT JOIN `{$users}` u ON u.`id` = t.`user_id`
					 SET t.`user_id` = NULL
					 WHERE t.`user_id` IS NOT NULL AND u.`id` IS NULL",
					"UPDATE `{$prefix}traffic_logs` t LEFT JOIN `{$files}` f ON f.`id` = t.`file_id`
					 SET t.`file_id` = NULL
					 WHERE t.`file_id` IS NOT NULL AND f.`id` IS NULL",
					"UPDATE `{$prefix}audit_log` a LEFT JOIN `{$users}` u ON u.`id` = a.`user_id`
					 SET a.`user_id` = NULL
					 WHERE a.`user_id` IS NOT NULL AND u.`id` IS NULL",
					"UPDATE `{$prefix}email_reservations` e LEFT JOIN `{$users}` u
					  ON u.`id` = e.`user_id`
					 SET e.`user_id` = NULL
					 WHERE e.`user_id` IS NOT NULL AND u.`id` IS NULL",
					"UPDATE `{$prefix}download_reservations` r LEFT JOIN `{$users}` u
					  ON u.`id` = r.`user_id`
					 SET r.`user_id` = NULL
					 WHERE r.`user_id` IS NOT NULL AND u.`id` IS NULL",
				] as $repairSql) {
					$pdo->exec($repairSql);
				}

				// Reconcile the denormalized reservation count after dropping impossible rows.
				$pdo->exec(
					"UPDATE `{$prefix}promo_codes` pc SET `reserved_count` = (
					 SELECT COUNT(*) FROM `{$prefix}promo_reservations` pr
					 WHERE pr.`promo_id` = pc.`id` AND pr.`status` = 'reserved'
					)"
				);

				$constraintPrefix = 'fk_' . substr(hash('sha256', $prefix), 0, 8) . '_';
				$foreignKeys = [
					['users', 'group_id', 'groups', 'id', 'SET NULL', 'user_group'],
					['files', 'user_id', 'users', 'id', 'RESTRICT', 'file_owner'],
					['collections', 'user_id', 'users', 'id', 'CASCADE', 'collection_owner'],
					['collection_files', 'collection_id', 'collections', 'id', 'CASCADE', 'member_collection'],
					['collection_files', 'file_id', 'files', 'id', 'CASCADE', 'member_file'],
					['reports', 'file_id', 'files', 'id', 'CASCADE', 'report_file'],
					['api_keys', 'user_id', 'users', 'id', 'CASCADE', 'api_user'],
					['webhooks', 'user_id', 'users', 'id', 'CASCADE', 'webhook_user'],
					['webhook_deliveries', 'webhook_id', 'webhooks', 'id', 'CASCADE', 'delivery_hook'],
					['notifications', 'user_id', 'users', 'id', 'CASCADE', 'notification_user'],
					['notification_prefs', 'user_id', 'users', 'id', 'CASCADE', 'preference_user'],
					['recovery_tokens', 'user_id', 'users', 'id', 'CASCADE', 'recovery_user'],
					['totp_recovery_codes', 'user_id', 'users', 'id', 'CASCADE', 'totp_user'],
					['upload_tokens', 'user_id', 'users', 'id', 'CASCADE', 'upload_token_user'],
					['download_tokens', 'user_id', 'users', 'id', 'CASCADE', 'download_token_user'],
					['active_uploads', 'user_id', 'users', 'id', 'CASCADE', 'active_upload_user'],
					['upload_storage_reservations', 'user_id', 'users', 'id', 'CASCADE', 'storage_res_user'],
					['traffic_logs', 'user_id', 'users', 'id', 'SET NULL', 'traffic_user'],
					['traffic_logs', 'file_id', 'files', 'id', 'SET NULL', 'traffic_file'],
					['audit_log', 'user_id', 'users', 'id', 'SET NULL', 'audit_user'],
					['plans', 'group_id', 'groups', 'id', 'SET NULL', 'plan_group'],
					['payments', 'user_id', 'users', 'id', 'SET NULL', 'payment_user'],
					['payments', 'actor_id', 'users', 'id', 'SET NULL', 'payment_actor'],
					['payments', 'plan_id', 'plans', 'id', 'SET NULL', 'payment_plan'],
					['payments', 'ad_id', 'ads', 'id', 'SET NULL', 'payment_ad'],
					['payments', 'package_id', 'ad_packages', 'id', 'SET NULL', 'payment_package'],
					['ads', 'owner_id', 'users', 'id', 'SET NULL', 'ad_owner'],
					['ads', 'approved_by', 'users', 'id', 'SET NULL', 'ad_approver'],
					['ads', 'package_id', 'ad_packages', 'id', 'SET NULL', 'ad_package'],
					['ad_stats_daily', 'ad_id', 'ads', 'id', 'CASCADE', 'ad_stat'],
					['download_reservations', 'user_id', 'users', 'id', 'SET NULL', 'reservation_user'],
					['download_reservation_effects', 'reservation_id', 'download_reservations', 'id', 'CASCADE', 'effect_reservation'],
					['email_reservations', 'user_id', 'users', 'id', 'SET NULL', 'email_user'],
					['promo_reservations', 'promo_id', 'promo_codes', 'id', 'RESTRICT', 'promo_res_code'],
					['promo_reservations', 'ext_order_id', 'payments', 'ext_order_id', 'CASCADE', 'promo_res_order'],
				];
				$constraintExists = $pdo->prepare(
					"SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
					 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?"
				);
				foreach ($foreignKeys as [$child, $column, $parent, $parentColumn, $onDelete, $suffix]) {
					$name = substr($constraintPrefix . $suffix, 0, 64);
					$constraintExists->execute([$name]);
					if ((int) $constraintExists->fetchColumn() > 0) {
						continue;
					}
					$pdo->exec(
						"ALTER TABLE `{$prefix}{$child}`
						 ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`)
						 REFERENCES `{$prefix}{$parent}` (`{$parentColumn}`)
						 ON DELETE {$onDelete}"
					);
				}

				self::persistMigrationVersion(54);
				$version = max($version, 54);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v54 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(54, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v55: repair the plan/group delete policy published briefly by schema 54 ---
		if ($runStep(55)) {
			$migrated = false;
			try {
				$constraintName = substr(
					'fk_' . substr(hash('sha256', $prefix), 0, 8) . '_plan_group',
					0,
					64
				);
				$ruleQuery = $pdo->prepare(
					"SELECT `DELETE_RULE` FROM information_schema.REFERENTIAL_CONSTRAINTS
					 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?"
				);
				$ruleQuery->execute([$constraintName]);
				$deleteRule = $ruleQuery->fetchColumn();
				if ($deleteRule !== 'SET NULL') {
					if ($deleteRule !== false) {
						$pdo->exec(
							"ALTER TABLE `{$prefix}plans` DROP FOREIGN KEY `{$constraintName}`"
						);
					}
					$pdo->exec(
						"ALTER TABLE `{$prefix}plans`
						 ADD CONSTRAINT `{$constraintName}` FOREIGN KEY (`group_id`)
						 REFERENCES `{$prefix}groups` (`id`) ON DELETE SET NULL"
					);
				}
				self::persistMigrationVersion(55);
				$version = max($version, 55);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v55 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(55, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v56: durable transactional e-mail outbox ---
		if ($runStep(56)) {
			$migrated = false;
			try {
				self::createOrRepairTable(
					$pdo,
					"CREATE TABLE IF NOT EXISTS `{$prefix}mail_outbox` (
					 `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					 `idempotency_key` CHAR(64) NOT NULL,
					 `content_hash` CHAR(64) NOT NULL,
					 `to_email` VARCHAR(255) NOT NULL,
					 `from_email` VARCHAR(255) NOT NULL,
					 `subject` VARCHAR(255) NOT NULL,
					 `subject_header` VARCHAR(512) NOT NULL,
					 `headers` TEXT NOT NULL,
					 `html_body` MEDIUMTEXT NOT NULL,
					 `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
					 `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
					 `max_attempts` INT UNSIGNED NOT NULL DEFAULT 8,
					 `next_attempt_at` INT UNSIGNED NOT NULL,
					 `lease_owner` CHAR(64) DEFAULT NULL,
					 `lease_until` INT UNSIGNED DEFAULT NULL,
					 `last_error` VARCHAR(1000) DEFAULT NULL,
					 `created_at` INT UNSIGNED NOT NULL,
					 `updated_at` INT UNSIGNED NOT NULL,
					 `sent_at` INT UNSIGNED DEFAULT NULL,
					 UNIQUE KEY `uq_mail_outbox_idempotency` (`idempotency_key`),
					 INDEX `idx_mail_outbox_ready` (`status`, `next_attempt_at`, `id`),
					 INDEX `idx_mail_outbox_lease` (`status`, `lease_until`),
					 INDEX `idx_mail_outbox_sent` (`status`, `sent_at`)
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
				);
				self::persistMigrationVersion(56);
				$version = max($version, 56);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v56 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(56, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v57: forward-repair the sent-mail retention index for early schema 56 ---
		if ($runStep(57)) {
			$migrated = false;
			try {
				$outbox = "{$prefix}mail_outbox";
				$indexes = $pdo->query(
					"SHOW INDEX FROM `{$outbox}` WHERE `Key_name` = 'idx_mail_outbox_sent'"
				)->fetchAll(PDO::FETCH_ASSOC);
				if ($indexes === []) {
					$pdo->exec(
						"ALTER TABLE `{$outbox}`
						 ADD INDEX `idx_mail_outbox_sent` (`status`, `sent_at`)"
					);
				}
				self::persistMigrationVersion(57);
				$version = max($version, 57);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v57 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(57, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v58: recoverable file-deletion quarantine (AUD-OPEN-03) ---
		if ($runStep(58)) {
			$migrated = false;
			try {
				self::createOrRepairTable(
					$pdo,
					"CREATE TABLE IF NOT EXISTS `{$prefix}file_quarantine` (
					 `file_id` VARCHAR(32) NOT NULL PRIMARY KEY,
					 `manifest_json` MEDIUMTEXT NOT NULL,
					 `reason` VARCHAR(64) NOT NULL,
					 `actor_type` VARCHAR(32) NOT NULL,
					 `actor_id` VARCHAR(64) DEFAULT NULL,
					 `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
					 `checksum` CHAR(64) DEFAULT NULL,
					 `state` VARCHAR(16) NOT NULL DEFAULT 'pending',
					 `quarantine_until` INT UNSIGNED NOT NULL,
					 `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
					 `next_attempt_at` INT UNSIGNED NOT NULL DEFAULT 0,
					 `last_error` VARCHAR(1000) DEFAULT NULL,
					 `created_at` INT UNSIGNED NOT NULL,
					 `updated_at` INT UNSIGNED NOT NULL,
					 INDEX `idx_quarantine_due`
					  (`state`, `next_attempt_at`, `quarantine_until`)
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
				);
				$setting = $pdo->prepare(
					"INSERT IGNORE INTO `{$prefix}settings`
					 (`setting_key`, `setting_value`) VALUES ('file_quarantine_days', '0')"
				);
				$setting->execute();
				self::persistMigrationVersion(58);
				$version = max($version, 58);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v58 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(58, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v59: independent moderator permission profiles and plan-scoped promo codes ---
		if ($runStep(59)) {
			$migrated = false;
			try {
				$users = "{$prefix}users";
				$promos = "{$prefix}promo_codes";
				$groups = "{$prefix}groups";
				$plans = "{$prefix}plans";

				$userColumns = $pdo->query("SHOW COLUMNS FROM `{$users}`")
					->fetchAll(PDO::FETCH_COLUMN);
				if (!in_array('staff_group_id', $userColumns, true)) {
					$pdo->exec(
						"ALTER TABLE `{$users}`
						 ADD COLUMN `staff_group_id` INT UNSIGNED DEFAULT NULL AFTER `group_id`"
					);
				}
				$userIndexes = $pdo->query("SHOW INDEX FROM `{$users}`")
					->fetchAll(PDO::FETCH_ASSOC);
				if (!in_array('idx_staff_group_id', array_column($userIndexes, 'Key_name'), true)) {
					$pdo->exec(
						"ALTER TABLE `{$users}`
						 ADD INDEX `idx_staff_group_id` (`staff_group_id`)"
					);
				}

				$promoColumns = $pdo->query("SHOW COLUMNS FROM `{$promos}`")
					->fetchAll(PDO::FETCH_COLUMN);
				if (!in_array('scope', $promoColumns, true)) {
					$pdo->exec(
						"ALTER TABLE `{$promos}`
						 ADD COLUMN `scope` VARCHAR(16) NOT NULL DEFAULT 'all' AFTER `code`"
					);
				}
				if (!in_array('plan_id', $promoColumns, true)) {
					$pdo->exec(
						"ALTER TABLE `{$promos}`
						 ADD COLUMN `plan_id` INT UNSIGNED DEFAULT NULL AFTER `scope`"
					);
				}
				$promoIndexes = $pdo->query("SHOW INDEX FROM `{$promos}`")
					->fetchAll(PDO::FETCH_ASSOC);
				if (!in_array('idx_promo_scope_plan', array_column($promoIndexes, 'Key_name'), true)) {
					$pdo->exec(
						"ALTER TABLE `{$promos}`
						 ADD INDEX `idx_promo_scope_plan` (`scope`, `plan_id`)"
					);
				}

				// Preserve existing moderators exactly: their old group becomes the permission
				// profile while the plan/limit group remains free to change independently.
				$pdo->exec(
					"UPDATE `{$users}` u
					 JOIN (
					  SELECT `id` FROM `{$groups}`
					  WHERE `is_default` = 1 AND (`slug` IS NULL OR `slug` <> 'guest')
					  ORDER BY `id` ASC LIMIT 1
					 ) d
					 SET u.`staff_group_id` = COALESCE(u.`group_id`, d.`id`)
					 WHERE u.`role` = 'moderator' AND u.`staff_group_id` IS NULL"
				);

				$constraintPrefix = 'fk_' . substr(hash('sha256', $prefix), 0, 8) . '_';
				$constraintExists = $pdo->prepare(
					"SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
					 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?"
				);
				foreach ([
					['staff_group', $users, 'staff_group_id', $groups],
					['promo_plan', $promos, 'plan_id', $plans],
				] as [$suffix, $childTable, $column, $parentTable]) {
					$name = substr($constraintPrefix . $suffix, 0, 64);
					$constraintExists->execute([$name]);
					if ((int) $constraintExists->fetchColumn() > 0) {
						continue;
					}
					$pdo->exec(
						"ALTER TABLE `{$childTable}`
						 ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`)
						 REFERENCES `{$parentTable}` (`id`) ON DELETE SET NULL"
					);
				}

				self::persistMigrationVersion(59);
				$version = max($version, 59);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v59 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(59, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v60: one role-bound, permissions-only Moderator system group ---
		if ($runStep(60)) {
			$migrated = false;
			try {
				$users = "{$prefix}users";
				$groups = "{$prefix}groups";

				// A single shared group necessarily replaces the former per-account profiles.
				// Preserve their union and add a conservative triage baseline; sensitive
				// finance, advertising and audit permissions still require an explicit admin
				// decision in Settings -> Groups.
				$permissions = Permissions::DEFAULT_MODERATOR_PERMS;
				$legacyPermissions = $pdo->query(
					"SELECT DISTINCT g.`permissions`
					 FROM `{$users}` u
					 JOIN `{$groups}` g ON g.`id` = u.`staff_group_id`
					 WHERE u.`role` = 'moderator'"
				)->fetchAll(PDO::FETCH_COLUMN);
				foreach ($legacyPermissions as $storedPermissions) {
					$permissions = array_merge(
						$permissions,
						Permissions::parse((string) $storedPermissions)
					);
				}

				$moderatorQuery = $pdo->query(
					"SELECT * FROM `{$groups}` WHERE `slug` = 'moderator' LIMIT 1"
				);
				$moderator = $moderatorQuery->fetch(PDO::FETCH_ASSOC);
				if ($moderator) {
					$permissions = array_merge(
						$permissions,
						Permissions::parse((string) ($moderator['permissions'] ?? ''))
					);
					$moderatorId = (int) $moderator['id'];
					$pdo->prepare(
						"UPDATE `{$groups}` SET
						 `is_system` = 1, `is_default` = 0,
						 `max_file_size_mb` = 0, `max_files_per_session` = 0,
						 `storage_quota_mb` = 0, `limit_upload` = 0, `limit_download` = 0,
						 `concurrent_downloads` = 0, `concurrent_connections_per_file` = 0,
						 `auto_delete_days` = 0, `permissions` = ?
						 WHERE `id` = ?"
					)->execute([Permissions::serialize($permissions), $moderatorId]);
				} else {
					// Names are operator-editable and unique. In the unlikely event an older
					// custom plan is already called "Moderator", keep it intact and give the
					// structural group an unambiguous display name.
					$name = 'Moderator';
					$nameCheck = $pdo->prepare(
						"SELECT COUNT(*) FROM `{$groups}` WHERE `name` = ?"
					);
					$nameCheck->execute([$name]);
					if ((int) $nameCheck->fetchColumn() > 0) {
						$name = 'Moderator (system)';
						$suffix = 2;
						$nameCheck->execute([$name]);
						while ((int) $nameCheck->fetchColumn() > 0) {
							$name = "Moderator (system {$suffix})";
							$suffix++;
							$nameCheck->execute([$name]);
						}
					}
					$insert = $pdo->prepare(
						"INSERT INTO `{$groups}`
						 (`name`, `slug`, `is_system`, `max_file_size_mb`,
						  `max_files_per_session`, `storage_quota_mb`, `limit_upload`,
						  `limit_download`, `concurrent_downloads`,
						  `concurrent_connections_per_file`, `auto_delete_days`,
						  `permissions`, `is_default`, `created_at`)
						 VALUES (?, 'moderator', 1, 0, 0, 0, 0, 0, 0, 0, 0, ?, 0, ?)"
					);
					$insert->execute([
						$name,
						Permissions::serialize($permissions),
						time(),
					]);
					$moderatorId = (int) $pdo->lastInsertId();
				}

				// Role and plan are independent axes: every moderator gets the structural
				// permission group, while group_id/Premium is untouched. No other role keeps a
				// dormant delegation that could spring back after a later role edit.
				$pdo->prepare(
					"UPDATE `{$users}` SET `staff_group_id` = ?
					 WHERE `role` = 'moderator'
					   AND (`staff_group_id` IS NULL OR `staff_group_id` <> ?)"
				)->execute([$moderatorId, $moderatorId]);
				$pdo->exec(
					"UPDATE `{$users}` SET `staff_group_id` = NULL
					 WHERE `role` <> 'moderator' AND `staff_group_id` IS NOT NULL"
				);

				self::persistMigrationVersion(60);
				$version = max($version, 60);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v60 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(60, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v61: periodic transfer quotas, configurable plan cards and reliable upload cancel ---
		if ($runStep(61)) {
			$migrated = false;
			try {
				foreach ([
					"ALTER TABLE `{$prefix}groups`
					 ADD COLUMN `transfer_quota_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0",
					"ALTER TABLE `{$prefix}groups`
					 ADD COLUMN `transfer_quota_period` VARCHAR(10) NOT NULL DEFAULT 'week'",
					"ALTER TABLE `{$prefix}plans`
					 ADD COLUMN `limit_fields` VARCHAR(255) NOT NULL
					 DEFAULT 'quota,file,files,concurrent,retention,transfer'",
					"ALTER TABLE `{$prefix}active_uploads`
					 ADD COLUMN `client_id` CHAR(32) DEFAULT NULL",
					"ALTER TABLE `{$prefix}active_uploads`
					 ADD COLUMN `status` VARCHAR(12) NOT NULL DEFAULT 'active'",
					"ALTER TABLE `{$prefix}download_reservations`
					 ADD COLUMN `quota_subject_type` VARCHAR(8) DEFAULT NULL",
					"ALTER TABLE `{$prefix}download_reservations`
					 ADD COLUMN `quota_subject_key` VARCHAR(64) DEFAULT NULL",
					"ALTER TABLE `{$prefix}download_reservations`
					 ADD COLUMN `quota_period` VARCHAR(10) DEFAULT NULL",
					"ALTER TABLE `{$prefix}download_reservations`
					 ADD COLUMN `quota_period_start` INT UNSIGNED DEFAULT NULL",
					"ALTER TABLE `{$prefix}download_reservations`
					 ADD COLUMN `quota_reserved_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0",
				] as $ddl) {
					try {
						$pdo->exec($ddl);
					} catch (PDOException $e) {
						self::tolerateIdempotentDdlError($e);
					}
				}

				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}transfer_quota_usage` (
					`subject_type` VARCHAR(8) NOT NULL,
					`subject_key` VARCHAR(64) NOT NULL,
					`period` VARCHAR(10) NOT NULL,
					`period_start` INT UNSIGNED NOT NULL,
					`used_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
					`reserved_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
					`updated_at` INT UNSIGNED NOT NULL,
					PRIMARY KEY (`subject_type`, `subject_key`, `period`, `period_start`),
					INDEX `idx_transfer_quota_updated` (`updated_at`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

				$indexes = $pdo->query("SHOW INDEX FROM `{$prefix}active_uploads`")
					->fetchAll(PDO::FETCH_ASSOC);
				if (!in_array('idx_upload_client_status', array_column($indexes, 'Key_name'), true)) {
					$pdo->exec(
						"ALTER TABLE `{$prefix}active_uploads`
						 ADD INDEX `idx_upload_client_status` (`client_id`, `status`, `updated_at`)"
					);
				}

				self::persistMigrationVersion(61);
				$version = max($version, 61);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v61 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(61, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v62: sortable upload IP + Moderator as a complete additive limit profile ---
		if ($runStep(62)) {
			$migrated = false;
			try {
				$fileIndexes = $pdo->query("SHOW INDEX FROM `{$prefix}files`")
					->fetchAll(PDO::FETCH_ASSOC);
				if (!in_array('idx_uploaded_ip', array_column($fileIndexes, 'Key_name'), true)) {
					$pdo->exec(
						"ALTER TABLE `{$prefix}files` ADD INDEX `idx_uploaded_ip` (`uploaded_ip`)"
					);
				}

				// v60 deliberately zeroed every Moderator limit because that group used to be
				// permissions-only. Seed only that unmistakable legacy state from the default
				// user profile; later operator edits, including intentional zeroes, are preserved.
				$pdo->exec(
					"UPDATE `{$prefix}groups` m
					 JOIN `{$prefix}groups` d ON d.`is_default` = 1
					   AND (d.`slug` IS NULL OR d.`slug` NOT IN ('guest', 'moderator'))
					 SET m.`max_file_size_mb` = d.`max_file_size_mb`,
					     m.`max_files_per_session` = d.`max_files_per_session`,
					     m.`storage_quota_mb` = d.`storage_quota_mb`,
					     m.`limit_upload` = d.`limit_upload`,
					     m.`limit_download` = d.`limit_download`,
					     m.`concurrent_downloads` = d.`concurrent_downloads`,
					     m.`concurrent_connections_per_file` = d.`concurrent_connections_per_file`,
					     m.`transfer_quota_bytes` = d.`transfer_quota_bytes`,
					     m.`transfer_quota_period` = d.`transfer_quota_period`,
					     m.`auto_delete_days` = d.`auto_delete_days`
					 WHERE m.`slug` = 'moderator' AND m.`is_system` = 1
					   AND m.`max_file_size_mb` = 0 AND m.`max_files_per_session` = 0
					   AND m.`storage_quota_mb` = 0 AND m.`limit_upload` = 0
					   AND m.`limit_download` = 0 AND m.`concurrent_downloads` = 0
					   AND m.`concurrent_connections_per_file` = 0
					   AND m.`transfer_quota_bytes` = 0 AND m.`auto_delete_days` = 0"
				);

				self::persistMigrationVersion(62);
				$version = max($version, 62);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v62 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(62, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v63: persistent sign-in, one rotating token family per device ---
		if ($runStep(63)) {
			$migrated = false;
			try {
				// A browser session dies with the browser, so "stay signed in" cannot be one.
				// Each device gets its own series; the secret inside it is replaced on every
				// use, and only the hashes are stored — a stolen database yields nothing that
				// can be presented as a cookie. Seeing an old secret for a live series is the
				// signal that a cookie was copied, and the whole family is dropped.
				self::createOrRepairTable($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}remember_tokens` (
					`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
					`user_id` INT UNSIGNED NOT NULL,
					`series` CHAR(64) NOT NULL,
					`token_hash` CHAR(64) NOT NULL,
					`expires_at` INT UNSIGNED NOT NULL,
					`created_at` INT UNSIGNED NOT NULL,
					`last_used_at` INT UNSIGNED DEFAULT NULL,
					`last_ip` VARCHAR(45) DEFAULT NULL,
					`user_agent` VARCHAR(255) DEFAULT NULL,
					UNIQUE INDEX `uniq_remember_series` (`series`),
					INDEX `idx_remember_user` (`user_id`),
					INDEX `idx_remember_expires` (`expires_at`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

				$constraintPrefix = 'fk_' . substr(hash('sha256', $prefix), 0, 8) . '_';
				$constraintName = substr($constraintPrefix . 'remember_user', 0, 64);
				$constraintExists = $pdo->prepare(
					"SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
					 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?"
				);
				$constraintExists->execute([$constraintName]);
				if ((int) $constraintExists->fetchColumn() === 0) {
					// A deleted account must not leave a live credential behind, and the
					// database is the only place that can promise it.
					$pdo->exec(
						"DELETE r FROM `{$prefix}remember_tokens` r
						 LEFT JOIN `{$prefix}users` u ON u.`id` = r.`user_id`
						 WHERE u.`id` IS NULL"
					);
					$pdo->exec(
						"ALTER TABLE `{$prefix}remember_tokens`
						 ADD CONSTRAINT `{$constraintName}` FOREIGN KEY (`user_id`)
						 REFERENCES `{$prefix}users` (`id`) ON DELETE CASCADE"
					);
				}

				self::persistMigrationVersion(63);
				$version = max($version, 63);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v63 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(63, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

		// --- v64: e-mail changes are confirmed from both addresses, so they need a stage ---
		if ($runStep(64)) {
			$migrated = false;
			try {
				// Changing the address used to be confirmed from the new one alone, which meant
				// a stolen session could move an account away without the owner ever hearing
				// about it. The old address now has to agree first, and one column is enough to
				// say which half of that is outstanding.
				$columns = $pdo->query("SHOW COLUMNS FROM `{$prefix}users`")
					->fetchAll(PDO::FETCH_COLUMN);
				if (!in_array('email_change_stage', $columns, true)) {
					$pdo->exec(
						"ALTER TABLE `{$prefix}users`
						 ADD COLUMN `email_change_stage` VARCHAR(8) DEFAULT NULL
						 AFTER `email_change_expires_at`"
					);
				}
				// A change already in flight was issued under the old, one-sided rule. Finishing
				// it would skip the confirmation the new rule exists to require, so the pending
				// ones are dropped and have to be requested again.
				$pdo->exec(
					"UPDATE `{$prefix}users`
					 SET `pending_email` = NULL,
					     `email_change_token` = NULL,
					     `email_change_expires_at` = NULL,
					     `email_change_stage` = NULL
					 WHERE `email_change_token` IS NOT NULL"
				);

				self::persistMigrationVersion(64);
				$version = max($version, 64);
				$migrated = true;
			} catch (\Throwable $e) {
				error_log('Database migration v64 failed: ' . $e->getMessage());
				self::failMigrationJournalStep(64, $e);
				throw $e;
			}
			if (!$migrated) {
				self::$migrationJournalActive = false;
				$releaseMigrationLock();
				return;
			}
		}

			self::assertSupportedSchema($pdo, $prefix);
			self::publishSchemaVerification(time());
			self::completeMigrationJournalStep($version);
		} catch (\Throwable $e) {
			self::setSetting('schema_contract_hash', '');
			self::setSetting('schema_contract_checked_at', '0');
			self::setSetting('schema_ready', '0');
			$failedVersion = self::$migrationJournalStep > 0
				? self::$migrationJournalStep
				: ($version < self::CURRENT_SCHEMA_VERSION ? $version + 1 : $version);
			self::failMigrationJournalStep($failedVersion, $e);
			throw new RuntimeException('Database schema verification failed: ' . $e->getMessage(), 0, $e);
		} finally {
			self::$migrationJournalActive = false;
			self::$migrationJournalStep = 0;
			$releaseMigrationLock();
		}
	}

	/**
	 * Verify the contracts relied on by security-sensitive and money-moving paths.
	 * A version number alone is not proof: older migrations could previously swallow DDL errors.
	 */
	private static function assertSupportedSchema(PDO $pdo, string $prefix): void
	{
		$requirements = [
			'files' => [
				'id', 'user_id', 'password_hash', 'expires_at', 'max_downloads',
				'downloads', 'one_time', 'consumed_at', 'consume_reservation_id',
				'on_limit_action',
			],
			'users' => [
				'id', 'is_active', 'session_version', 'group_id', 'staff_group_id', 'storage_limit',
				'totp_enabled', 'totp_secret', 'activation_token', 'activation_expires_at',
				'email_change_token', 'email_change_expires_at', 'email_change_stage',
				'group_payment_ext_order_id',
			],
			'recovery_tokens' => ['token', 'user_id', 'created_at', 'expires_at'],
			'upload_tokens' => ['token', 'user_id', 'ip_address', 'files_count', 'created_at'],
			'download_tokens' => [
				'token', 'file_id', 'user_id', 'ip_address', 'used', 'created_at',
				'reservation_id', 'reserved_until', 'used_at',
			],
			'groups' => [
				'id', 'max_file_size_mb', 'max_files_per_session', 'storage_quota_mb',
				'concurrent_downloads', 'concurrent_connections_per_file',
			],
			'collections' => [
				'id', 'user_id', 'password_hash', 'expires_at', 'max_downloads',
				'downloads', 'one_time', 'consumed_at', 'consume_reservation_id',
				'on_limit_action',
			],
			'collection_files' => ['collection_id', 'file_id', 'position'],
			'api_keys' => ['id', 'user_id', 'key_hash'],
			'webhooks' => ['id', 'user_id', 'url', 'secret', 'is_active'],
			'webhook_deliveries' => [
				'id', 'webhook_id', 'event_id', 'status', 'next_attempt_at',
				'lease_owner', 'lease_until', 'delivered_at',
			],
			'payments' => [
				'id', 'status', 'granted_at', 'processing_token', 'processing_started_at',
				'processing_expires_at', 'fulfillment_attempts', 'fulfillment_last_error',
				'product_snapshot',
			],
			'plans' => ['id', 'group_id', 'price', 'currency', 'duration_days'],
			'promo_codes' => [
				'id', 'code', 'scope', 'plan_id', 'max_uses', 'used_count',
				'reserved_count', 'enabled',
			],
			'promo_reservations' => ['ext_order_id', 'promo_id', 'status'],
			'payment_events' => [
				'provider', 'event_id', 'ext_order_id', 'provider_status', 'received_at',
			],
			'ads' => [
				'id', 'owner_id', 'status', 'package_id', 'purchase_duration_days',
				'starts_at', 'ends_at', 'image_path',
			],
			'active_downloads' => [
				'id', 'ip_address', 'file_id', 'instance_id', 'heartbeat_at',
			],
			'download_reservations' => [
				'id', 'resource_type', 'resource_id', 'token_fingerprint',
				'active_download_id', 'user_id', 'ip_address', 'state', 'bytes_sent',
				'lease_until', 'created_at', 'started_at', 'finished_at', 'updated_at',
				'quota_subject_type', 'quota_subject_key', 'quota_period',
				'quota_period_start', 'quota_reserved_bytes',
			],
			'download_reservation_effects' => [
				'reservation_id', 'effect_type', 'resource_type', 'resource_id',
				'applied_at',
			],
			'notifications' => [
				'id', 'user_id', 'type', 'group_key', 'open_stack_key', 'dedupe_key',
				'created_at', 'updated_at', 'read_at',
			],
			'active_uploads' => ['id', 'ip_address', 'filename', 'client_id', 'status'],
			'transfer_quota_usage' => [
				'subject_type', 'subject_key', 'period', 'period_start',
				'used_bytes', 'reserved_bytes', 'updated_at',
			],
			'blacklists' => ['id', 'type', 'value', 'expires_at'],
			'traffic_logs' => ['id', 'transfer_type', 'transfer_size', 'created_at'],
			'traffic_daily' => ['day', 'transfer_type', 'transfer_size', 'transfer_count'],
			'file_deletion_queue' => [
				'file_id', 'attempts', 'next_attempt_at', 'last_error', 'created_at',
			],
			'file_quarantine' => [
				'file_id', 'manifest_json', 'reason', 'actor_type', 'actor_id', 'size',
				'checksum', 'state', 'quarantine_until', 'attempts', 'next_attempt_at',
				'last_error', 'created_at', 'updated_at',
			],
			'ad_file_deletion_queue' => [
				'filename', 'attempts', 'next_attempt_at', 'last_error', 'created_at',
			],
			'upload_storage_reservations' => [
				'id', 'user_id', 'size', 'expires_at', 'created_at',
			],
			'migration_journal' => [
				'version', 'status', 'attempts', 'started_at', 'finished_at', 'last_error',
			],
			'mail_outbox' => [
				'id', 'idempotency_key', 'content_hash', 'to_email', 'from_email',
				'subject', 'subject_header', 'headers', 'html_body', 'status', 'attempts',
				'max_attempts', 'next_attempt_at', 'lease_owner', 'lease_until',
				'last_error', 'created_at', 'updated_at', 'sent_at',
			],
			'settings' => ['setting_key', 'setting_value'],
		];

		$query = $pdo->prepare(
			'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
			. 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
		);
		foreach ($requirements as $table => $columns) {
			$tableName = $prefix . $table;
			$query->execute([$tableName]);
			$actual = $query->fetchAll(PDO::FETCH_COLUMN);
			if ($actual === []) {
				throw new RuntimeException("Required table {$tableName} is missing.");
			}
			$missing = array_values(array_diff($columns, $actual));
			if ($missing !== []) {
				throw new RuntimeException(
					"Required columns missing from {$tableName}: " . implode(', ', $missing)
				);
			}
		}

		$indexQuery = $pdo->prepare(
			'SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS '
			. 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX'
		);
		$indexQuery->execute([$prefix . 'files']);
		$fileIndexes = [];
		foreach ($indexQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$fileIndexes[(string) $row['INDEX_NAME']][] = (string) $row['COLUMN_NAME'];
		}
		if (($fileIndexes['idx_user_uploaded_id'] ?? null) !== ['user_id', 'uploaded_at', 'id']) {
			throw new RuntimeException('Required files cursor index is missing or malformed.');
		}
		$indexQuery->execute([$prefix . 'mail_outbox']);
		$mailIndexes = [];
		foreach ($indexQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$mailIndexes[(string) $row['INDEX_NAME']][] = (string) $row['COLUMN_NAME'];
		}
		if (($mailIndexes['uq_mail_outbox_idempotency'] ?? null) !== ['idempotency_key']
			|| ($mailIndexes['idx_mail_outbox_ready'] ?? null)
				!== ['status', 'next_attempt_at', 'id']
			|| ($mailIndexes['idx_mail_outbox_lease'] ?? null) !== ['status', 'lease_until']
			|| ($mailIndexes['idx_mail_outbox_sent'] ?? null) !== ['status', 'sent_at']) {
			throw new RuntimeException('Required mail outbox indexes are missing or malformed.');
		}

		$constraintPrefix = 'fk_' . substr(hash('sha256', $prefix), 0, 8) . '_';
		$expectedDeleteRules = [
			'user_group' => 'SET NULL',
			'file_owner' => 'RESTRICT',
			'collection_owner' => 'CASCADE',
			'member_collection' => 'CASCADE',
			'member_file' => 'CASCADE',
			'report_file' => 'CASCADE',
			'api_user' => 'CASCADE',
			'webhook_user' => 'CASCADE',
			'delivery_hook' => 'CASCADE',
			'notification_user' => 'CASCADE',
			'preference_user' => 'CASCADE',
			'recovery_user' => 'CASCADE',
			'totp_user' => 'CASCADE',
			'upload_token_user' => 'CASCADE',
			'download_token_user' => 'CASCADE',
			'active_upload_user' => 'CASCADE',
			'storage_res_user' => 'CASCADE',
			'traffic_user' => 'SET NULL',
			'traffic_file' => 'SET NULL',
			'audit_user' => 'SET NULL',
			'plan_group' => 'SET NULL',
			'payment_user' => 'SET NULL',
			'payment_actor' => 'SET NULL',
			'payment_plan' => 'SET NULL',
			'payment_ad' => 'SET NULL',
			'payment_package' => 'SET NULL',
			'ad_owner' => 'SET NULL',
			'ad_approver' => 'SET NULL',
			'ad_package' => 'SET NULL',
			'ad_stat' => 'CASCADE',
			'reservation_user' => 'SET NULL',
			'effect_reservation' => 'CASCADE',
			'email_user' => 'SET NULL',
			'promo_res_code' => 'RESTRICT',
			'promo_res_order' => 'CASCADE',
			'remember_user' => 'CASCADE',
		];
		$constraintQuery = $pdo->prepare(
			"SELECT `DELETE_RULE` FROM information_schema.REFERENTIAL_CONSTRAINTS
			 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?"
		);
		foreach ($expectedDeleteRules as $suffix => $deleteRule) {
			$name = substr($constraintPrefix . $suffix, 0, 64);
			$constraintQuery->execute([$name]);
			$actualDeleteRule = $constraintQuery->fetchColumn();
			if ($actualDeleteRule !== $deleteRule) {
				throw new RuntimeException(
					"Required foreign key {$name} is missing or has an invalid delete policy."
				);
			}
		}

		for ($step = 2; $step <= self::CURRENT_SCHEMA_VERSION; $step++) {
			$problems = self::migrationStepProblems($pdo, $prefix, $step);
			if ($problems !== []) {
				throw new RuntimeException(
					"Migration {$step} contract failed: " . implode('; ', $problems)
				);
			}
		}

		$version = (int) self::getSetting('schema_version', 0);
		if ($version !== self::CURRENT_SCHEMA_VERSION) {
			throw new RuntimeException(
				"Unsupported schema version {$version}; expected " . self::CURRENT_SCHEMA_VERSION
			);
		}
	}

	/**
	 * Periodic housekeeping (P5): delete rows past their useful life. These DELETEs
	 * used to run inline in hot paths (token creation, concurrency checks); they now
	 * belong to a cron job (scripts/cleanup.php). Read paths filter by timestamp, so
	 * their results stay correct between runs regardless of when this last ran.
	 * Returns a map of table label → rows deleted (-1 marks a per-table error).
	 */
	public static function cleanupExpired(): array
	{
		$pdo = self::getInstance();
		if (!$pdo) {
			return [];
		}

		$now = time();
		$uploadTtl   = (int) self::getSetting('recaptcha_token_lifetime', 120) * 60; // minutes → s
		$downloadTtl = (int) self::getSetting('download_token_ttl', 900);            // seconds
		$activeStale = 4 * 3600;                                                     // 4 h
		$recoveryTtl = 72 * 3600;                                                    // 72 h
		$downloadReservationCutoff = $now - (30 * 86400);

		$jobs = [
			'upload_tokens'     => ['upload_tokens',     'created_at',   $now - $uploadTtl],
			'download_tokens'   => ['download_tokens',   'created_at',   $now - $downloadTtl],
			'active_downloads'  => ['active_downloads',  'heartbeat_at', $now - $activeStale],
			'active_uploads'    => ['active_uploads',    'updated_at',   $now - $activeStale],
			'recovery_attempts' => ['recovery_attempts', 'attempted_at', $now - $recoveryTtl],
			'upload_storage_reservations' => [
				'upload_storage_reservations', 'expires_at', $now,
			],
			'download_reservations' => [
				'download_reservations', 'finished_at', $downloadReservationCutoff,
			],
			'transfer_quota_usage' => [
				'transfer_quota_usage', 'updated_at', $now - (2 * 366 * 86400),
			],
		];

		$deleted = [];
		try {
			$effects = self::table('download_reservation_effects');
			$reservations = self::table('download_reservations');
			$stmt = $pdo->prepare(
				"DELETE e FROM `{$effects}` e
				 JOIN `{$reservations}` r ON r.`id` = e.`reservation_id`
				 WHERE r.`finished_at` IS NOT NULL AND r.`finished_at` < ?"
			);
			$stmt->execute([$downloadReservationCutoff]);
			$deleted['download_reservation_effects'] = $stmt->rowCount();
		} catch (PDOException $e) {
			$deleted['download_reservation_effects'] = -1;
		}
		foreach ($jobs as $label => [$tbl, $col, $cutoff]) {
			try {
				$table = self::table($tbl);
				$stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$col}` < ?");
				$stmt->execute([$cutoff]);
				$deleted[$label] = $stmt->rowCount();
			} catch (PDOException $e) {
				$deleted[$label] = -1; // signal error, keep going with the rest
			}
		}
		return $deleted;
	}

	/* ------------------------------------------------------------------ *
	 * User groups (A8) — named limit profiles for registered users.
	 * Guests keep using the flat guest-tier settings (they have no account).
	 * A user with group_id = NULL resolves to the default group.
	 * ------------------------------------------------------------------ */

	/* Groups — delegated to GroupRepository (Faza 5 · #2). */

	public static function getGroups(): array
	{
		return GroupRepository::all();
	}

	public static function getGroupById(int $id): ?array
	{
		return GroupRepository::getById($id);
	}

	public static function getDefaultGroup(): ?array
	{
		return GroupRepository::getDefault();
	}

	public static function getUserGroup(int $userId): ?array
	{
		return GroupRepository::forUser($userId);
	}

	public static function getUserEffectiveGroup(int $userId): ?array
	{
		return GroupRepository::effectiveForUser($userId);
	}

	public static function getUserStaffGroup(int $userId): ?array
	{
		return GroupRepository::staffForUser($userId);
	}

	public static function saveGroup(?int $id, array $data): array
	{
		return GroupRepository::save($id, $data);
	}

	public static function deleteGroup(int $id): array
	{
		return GroupRepository::delete($id);
	}

	public static function setUserGroup(int $userId, ?int $groupId): bool
	{
		return GroupRepository::assignUser($userId, $groupId);
	}

	/* Per-file options / passwords / one-time — delegated to FileRepository (Faza 5 · #2). */

	public static function setFileOptions(string $fileId, ?int $ownerId, ?int $expiresAt, ?int $maxDownloads, ?string $password, bool $clearPassword = false, bool $oneTime = false, string $onLimitAction = 'keep'): bool
	{
		return FileRepository::setOptions($fileId, $ownerId, $expiresAt, $maxDownloads, $password, $clearPassword, $oneTime, $onLimitAction);
	}

	public static function claimOneTime(string $fileId): bool
	{
		return FileRepository::claimOneTime($fileId);
	}

	public static function oneTimeConsumed(string $fileId): bool
	{
		return FileRepository::oneTimeConsumed($fileId);
	}

	public static function setFilePassword(string $fileId, ?string $password, bool $clear = false): bool
	{
		return FileRepository::setPassword($fileId, $password, $clear);
	}

	public static function getFileSharingState(string $fileId): array
	{
		return FileRepository::sharingState($fileId);
	}

	public static function fileIsProtected(string $fileId): bool
	{
		return FileRepository::isProtected($fileId);
	}

	public static function verifyFilePassword(string $fileId, string $password): bool
	{
		return FileRepository::verifyPassword($fileId, $password);
	}

	public static function verifyFileDeleteToken(string $fileId, string $token): bool
	{
		return FileRepository::verifyDeleteToken($fileId, $token);
	}

	/** Record an administrative/security event. */
	/* Audit trail — delegated to AuditService (Faza 5 · #2). */

	public static function logAudit(string $action, string $details = '', ?int $userId = null, ?string $username = null): void
	{
		AuditService::log($action, $details, $userId, $username);
	}

	public static function getAuditLog(int $page = 1, int $perPage = 30): array
	{
		return AuditService::getLog($page, $perPage);
	}

	/* Upload/download tokens — delegated to TokenRepository (Faza 5 · #2). */

	public static function createUploadToken(string $ip, ?int $userId = null): ?string
	{
		return TokenRepository::createUpload($ip, $userId);
	}

	public static function verifyUploadToken(string $token, string $ip): bool
	{
		return TokenRepository::verifyUpload($token, $ip);
	}

	public static function incrementTokenFileCount(string $token): bool
	{
		return TokenRepository::incrementFileCount($token);
	}

	public static function deleteUploadToken(string $token): bool
	{
		return TokenRepository::deleteUpload($token);
	}

	public static function createDownloadToken(string $fileId, string $ip, ?int $userId = null): ?string
	{
		return TokenRepository::createDownload($fileId, $ip, $userId);
	}

	public static function verifyUseDownloadToken(string $token, string $ip): ?string
	{
		return TokenRepository::verifyUseDownload($token, $ip);
	}

	public static function claimUploadToken(string $token, int $userId): bool
	{
		return TokenRepository::claimUpload($token, $userId);
	}

	public static function getTokenInfo(string $token, string $ip): ?array
	{
		return TokenRepository::uploadInfo($token, $ip);
	}

	/* reCAPTCHA — delegated to CaptchaService (Faza 5 · #2). */

	public static function verifyRecaptcha(string $response, string $ip): bool
	{
		return CaptchaService::verify($response, $ip);
	}

	public static function isRecaptchaEnabled(): bool
	{
		return CaptchaService::isEnabled();
	}

	/* Active downloads — delegated to ActiveDownloadRepository (Faza 5 · #2). */

	public static function addActiveDownload(string $ip, string $fileId): ?int
	{
		return ActiveDownloadRepository::add($ip, $fileId);
	}

	public static function removeActiveDownload(int $id): void
	{
		ActiveDownloadRepository::remove($id);
	}

	public static function getConcurrentDownloads(string $ip): int
	{
		return ActiveDownloadRepository::concurrentFor($ip);
	}

	/* User accounts / auth — delegated to UserRepository (Faza 5 · #2). */

	public static function registerUser(string $username, string $email, string $password): array
	{
		return UserRepository::register($username, $email, $password);
	}

	public static function verifyUserPassword(int $userId, string $password): bool
	{
		return UserRepository::verifyPassword($userId, $password);
	}

	public static function updateUserPassword(int $userId, string $newPassword): bool
	{
		return UserRepository::updatePassword($userId, $newPassword);
	}

	public static function requestEmailChange(int $userId, string $newEmail): array
	{
		return UserRepository::requestEmailChange($userId, $newEmail);
	}

	public static function confirmEmailChange(string $token): array
	{
		return UserRepository::confirmEmailChange($token);
	}

	public static function deleteUser(int $userId): bool
	{
		return UserRepository::delete($userId);
	}

	/**
	 * Seconds to add to a stored timestamp so MySQL formats it in *PHP's* timezone.
	 *
	 * Every chart in this project builds its buckets twice: PHP invents the labels with
	 * `date()`, and SQL groups the rows with `FROM_UNIXTIME()`. Those two agree only while PHP
	 * and MySQL sit in the same timezone — and on this machine they do not (PHP is UTC, MySQL
	 * follows the system clock). For the two hours a night when the offset puts them on
	 * different calendar days, the newest bucket was grouped under a label the chart never
	 * drew, so today's data silently vanished from the graph.
	 *
	 * Rather than repointing either clock — both are legitimate choices an operator may have
	 * made deliberately — the difference is measured once per request and folded into the
	 * query, so the grouping lands on the same day boundaries the labels use.
	 */
	public static function tzShiftSeconds(): int
	{
		static $shift = null;
		if ($shift !== null) {
			return $shift;
		}
		$pdo = self::getInstance();
		if (!$pdo) {
			return $shift = 0;
		}
		try {
			$dbOffset = (int) $pdo->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
			$phpOffset = (new DateTimeZone(date_default_timezone_get()))->getOffset(new DateTime('now', new DateTimeZone('UTC')));
			return $shift = $phpOffset - $dbOffset;
		} catch (Throwable $e) {
			return $shift = 0;
		}
	}

	/** The owner account (oldest user) — never deletable, see pt 4. */
	public static function rootAdminId(): int
	{
		return UserRepository::rootAdminId();
	}

	public static function isRootAdmin(int $userId): bool
	{
		return UserRepository::isRootAdmin($userId);
	}

	public static function loginUser(string $username, string $password): array
	{
		return UserRepository::login($username, $password);
	}

	public static function verifyUserByToken(string $token): array
	{
		return UserRepository::activateByToken($token);
	}

	public static function resendActivationEmailById(int $userId): array
	{
		return UserRepository::resendActivationById($userId);
	}

	public static function resendActivationEmail(string $email): array
	{
		return UserRepository::resendActivation($email);
	}



	/* A user's own files — delegated to FileRepository (Faza 5 · #2). */

	public static function getUserFiles(int $userId): array
	{
		return FileRepository::forUser($userId);
	}

	public static function getUserFilesPage(
		int $userId,
		int $limit,
		?int $beforeUploadedAt = null,
		?string $beforeId = null
	): array {
		return FileRepository::pageForUser(
			$userId,
			$limit,
			$beforeUploadedAt,
			$beforeId
		);
	}

	public static function getUserFileById(int $userId, string $fileId): ?array
	{
		return FileRepository::oneForUser($userId, $fileId);
	}

	public static function getUserById(int $id): ?array
	{
		return UserRepository::getById($id);
	}

	/* 2FA / TOTP (Faza 4.4) — delegated to UserRepository (Faza 5 · #2). */

	public static function setTotpSecret(int $userId, string $secretB32): bool
	{
		return UserRepository::setTotpSecret($userId, $secretB32);
	}

	public static function getTotpState(int $userId): array
	{
		return UserRepository::getTotpState($userId);
	}

	public static function setTotpEnabled(int $userId, bool $enabled): bool
	{
		return UserRepository::setTotpEnabled($userId, $enabled);
	}

	public static function enableTotpWithRecoveryCodes(int $userId): array
	{
		return UserRepository::enableTotpWithRecoveryCodes($userId);
	}

	/* 2FA recovery codes — delegated to RecoveryCodeRepository (Faza 6 · #2). */

	public static function regenerateRecoveryCodes(int $userId): array
	{
		return RecoveryCodeRepository::regenerate($userId);
	}

	public static function regenerateRecoveryCodesAndInvalidateAccess(int $userId): array
	{
		return RecoveryCodeRepository::regenerateAndInvalidateAccess($userId);
	}

	public static function countRecoveryCodes(int $userId): int
	{
		return RecoveryCodeRepository::remaining($userId);
	}

	public static function consumeRecoveryCode(int $userId, string $code): bool
	{
		return RecoveryCodeRepository::consume($userId, $code);
	}

	public static function clearRecoveryCodes(int $userId): void
	{
		RecoveryCodeRepository::clear($userId);
	}

	/* ------------------------------------------------------------------ *
	 * Collections (Faza 3.2) — group existing files under one shareable
	 * link. Files stay independent; a join table links them. Downloading a
	 * collection streams a ZIP built on the fly (see upload_server.py).
	 * Orphan links (a member file later deleted) are simply skipped when the
	 * collection is read, so no delete-path bookkeeping is required.
	 * ------------------------------------------------------------------ */

	/** Create an empty collection owned by $userId (delete_token is already hashed). */
	/* Collections — delegated to CollectionRepository (Faza 5 · #2). */

	public static function createCollection(string $id, string $name, ?int $userId, string $deleteTokenHash): bool
	{
		return CollectionRepository::create($id, $name, $userId, $deleteTokenHash);
	}

	public static function createCollectionWithFiles(
		string $id,
		string $name,
		int $userId,
		string $deleteTokenHash,
		array $fileIds,
		?int $fileOwnerId,
		array $options,
		int $minimumFiles = 2
	): array {
		return CollectionRepository::createWithFiles(
			$id,
			$name,
			$userId,
			$deleteTokenHash,
			$fileIds,
			$fileOwnerId,
			$options,
			$minimumFiles
		);
	}

	public static function addFilesToCollection(string $collectionId, array $fileIds, ?int $ownerId): int
	{
		return CollectionRepository::addFiles($collectionId, $fileIds, $ownerId);
	}

	public static function getCollection(string $id): ?array
	{
		return CollectionRepository::get($id);
	}

	public static function getUserCollections(int $userId): array
	{
		return CollectionRepository::forUser($userId);
	}

	public static function browseCollections(array $opts): array
	{
		return CollectionRepository::browse($opts);
	}

	public static function collectionOwnerFacets(): array
	{
		return CollectionRepository::ownerFacets();
	}

	public static function deleteCollections(array $ids, ?int $ownerId): int
	{
		return CollectionRepository::deleteMany($ids, $ownerId);
	}

	public static function deleteCollection(string $id, ?int $ownerId): bool
	{
		return CollectionRepository::delete($id, $ownerId);
	}

	public static function renameCollection(string $id, string $name, ?int $ownerId): bool
	{
		return CollectionRepository::rename($id, $name, $ownerId);
	}

	public static function setCollectionOptions(string $id, ?int $ownerId, array $opts): bool
	{
		return CollectionRepository::setOptions($id, $ownerId, $opts);
	}

	/* ------------------------------------------------------------------ *
	 * API keys (Faza 3.3) — per-user secrets for programmatic / ShareX
	 * uploads. Only a SHA-256 hash of the key is stored (keys are high-entropy,
	 * so a fast deterministic hash is enough and lets the upload server look a
	 * key up by hashing what the client presents). Revoking = deleting the row.
	 * ------------------------------------------------------------------ */

	/* API keys — delegated to ApiKeyRepository (Faza 5 · #2). */

	public static function createApiKey(int $userId, string $keyHash, string $prefix, string $label): int|false
	{
		return ApiKeyRepository::create($userId, $keyHash, $prefix, $label);
	}

	public static function getUserApiKeys(int $userId): array
	{
		return ApiKeyRepository::forUser($userId);
	}

	public static function countUserApiKeys(int $userId): int
	{
		return ApiKeyRepository::countForUser($userId);
	}

	public static function revokeApiKey(int $id, int $userId): bool
	{
		return ApiKeyRepository::revoke($id, $userId);
	}

	public static function resolveApiKey(string $raw): ?int
	{
		return ApiKeyRepository::resolve($raw);
	}

	public static function resolveApiKeyIdentity(string $raw): ?array
	{
		return ApiKeyRepository::resolveIdentity($raw);
	}

	/* ------------------------------------------------------------------ *
	 * Webhooks (Faza 4.1) — user-registered endpoints notified on their
	 * own file events. Firing just enqueues a delivery row per matching
	 * webhook (cheap, non-blocking); the upload server's worker POSTs them
	 * with an HMAC-SHA256 signature and retries.
	 * ------------------------------------------------------------------ */

	/* Webhooks — delegated to WebhookRepository (Faza 5 · #2). */

	public static function createWebhook(int $userId, string $url, string $secret, string $events): int|false
	{
		return WebhookRepository::create($userId, $url, $secret, $events);
	}

	public static function getUserWebhooks(int $userId): array
	{
		return WebhookRepository::forUser($userId);
	}

	public static function countUserWebhooks(int $userId): int
	{
		return WebhookRepository::countForUser($userId);
	}

	public static function deleteWebhook(int $id, int $userId): bool
	{
		return WebhookRepository::delete($id, $userId);
	}

	public static function enqueueWebhookEvent(int $userId, string $event, array $payload): int
	{
		return WebhookRepository::enqueueEvent($userId, $event, $payload);
	}

	/* Availability + admin user management — delegated to UserRepository (Faza 5 · #2). */

	public static function isUsernameAvailable(string $username): bool
	{
		return UserRepository::usernameAvailable($username);
	}

	public static function isEmailAvailable(string $email): bool
	{
		return UserRepository::emailAvailable($email);
	}

	public static function getAllUsers(
		int $page = 1,
		int $limit = 50,
		string $sortBy = 'created_at',
		string $order = 'desc',
		array $sorts = []
	): array
	{
		return UserRepository::all($page, $limit, $sortBy, $order, $sorts);
	}

	public static function updateUserStatus(int $id, int $active): bool
	{
		return UserRepository::setActive($id, $active);
	}

	public static function invalidateUserAccess(int $id): bool
	{
		return UserRepository::invalidateAccess($id);
	}

	/** Delete all of a user's file rows — delegated to FileRepository (Faza 5 · #2). */
	public static function cleanUserFiles(int $id): bool
	{
		return FileRepository::deleteAllForUser($id);
	}

	public static function adminUpdateUser(int $id, array $data): array
	{
		return UserRepository::adminUpdate($id, $data);
	}

	public static function getUserForAdmin(int $id): ?array
	{
		return UserRepository::getForAdmin($id);
	}

	/* Blacklist / bans — delegated to BanRepository (Faza 5 · #2). */

	public static function addToBlacklist(string $type, string $value, ?int $expiresAt = null, string $reason = ''): bool
	{
		return BanRepository::add($type, $value, $expiresAt, $reason);
	}

	public static function removeFromBlacklist(int $id): bool
	{
		return BanRepository::remove($id);
	}

	public static function isBlacklisted(string $type, string $value): bool
	{
		return BanRepository::isBanned($type, $value);
	}

	public static function getBanDetails(string $type, string $value): ?array
	{
		return BanRepository::details($type, $value);
	}

	public static function getBlacklists(int $page = 1, int $limit = 20): array
	{
		return BanRepository::list($page, $limit);
	}

	/** File stats for a user — delegated to FileRepository (Faza 5 · #2). */
	public static function getUserStats(int $userId): array
	{
		return FileRepository::statsForUser($userId);
	}


	/* Abuse reports (Report → Moderate) — delegated to ReportRepository (Faza 5 · #2). */

	public static function addReport(string $fileId, array $data): array
	{
		return ReportRepository::add($fileId, $data);
	}

	public static function getReportedFiles(int $page = 1, int $limit = 20): array
	{
		return ReportRepository::listReported($page, $limit);
	}

	public static function getReportDetails(int $reportId): ?array
	{
		return ReportRepository::details($reportId);
	}

	public static function rejectReport(int $reportId): bool
	{
		return ReportRepository::reject($reportId);
	}

	public static function deleteReportsByFileIds(array $fileIds): int
	{
		return ReportRepository::deleteByFileIds($fileIds);
	}

	/** Report-spam check — delegated to ReportRepository (Faza 5 · #2). */
	public static function checkSpam(string $email): bool
	{
		return ReportRepository::isSpammer($email);
	}

	/* Email — delegated to MailService (Faza 5 · #2). */

	public static function sendEmail(
		string $to,
		string $subject,
		string $message,
		?string $idempotencyKey = null
	): bool
	{
		return MailService::send($to, $subject, $message, $idempotencyKey);
	}

	/** An HTML body this project wrote itself; see MailService::sendTemplate(). */
	public static function sendHtmlEmail(
		string $to,
		string $subject,
		string $html,
		?string $idempotencyKey = null
	): bool {
		return MailService::sendTemplate($to, $subject, $html, $idempotencyKey);
	}
	// --- Password Recovery Methods ---

	/* Password recovery — delegated to RecoveryRepository (Faza 5 · #2). */

	public static function logRecoveryAttempt(string $ip): void
	{
		RecoveryRepository::logAttempt($ip);
	}

	public static function getRecoveryAttemptsCount(string $ip, int $hours = 48): int
	{
		return RecoveryRepository::attemptsCount($ip, $hours);
	}

	public static function createRecoveryToken(int $userId): ?string
	{
		return RecoveryRepository::createToken($userId);
	}

	public static function verifyRecoveryToken(string $token): ?int
	{
		return RecoveryRepository::verifyToken($token);
	}

	public static function deleteRecoveryToken(string $token): void
	{
		RecoveryRepository::deleteToken($token);
	}

	public static function consumeRecoveryTokenAndResetPassword(
		string $token,
		string $newPassword
	): bool {
		return RecoveryRepository::consumeAndResetPassword($token, $newPassword);
	}

	public static function getUserByEmailOrUsername(string $input): ?array
	{
		return UserRepository::getByEmailOrUsername($input);
	}

	public static function getSecurityEvent(string $ip, string $type): int
	{
		$pdo = self::getInstance();
		if (!$pdo)
			return 0;

		$table = self::table('security_events');
		try {
			$stmt = $pdo->prepare("SELECT `counter`, `last_updated_at` FROM `{$table}` WHERE `ip_address` = ? AND `event_type` = ?");
			$stmt->execute([$ip, $type]);
			$row = $stmt->fetch();

			if (!$row)
				return 0;

			// Time window (minutes) for counting security events per IP.
			$window = (int) self::getSetting('recaptcha_security_window', 60);
			$expirationTime = $row['last_updated_at'] + ($window * 60);

			if (time() > $expirationTime) {
				// Period expired, reset counter
				self::clearSecurityEvent($ip, $type);
				return 0;
			}

			return (int) $row['counter'];
		} catch (PDOException $e) {
			return 0;
		}
	}

	public static function incrementSecurityEvent(string $ip, string $type): void
	{
		$pdo = self::getInstance();
		if (!$pdo)
			return;

		// First check if we need to reset/start fresh due to timeout (lazy check)
		// Actually, upsert below handles update. If it was old, we should effectively "reset" it but keep 1.
		// Use getLogic to clean up first? 
		// Simpler: Just rely on getSecurityEvent doing the check. 
		// BUT: if we blind insert/update, we prolong the window.
		// If window expired, we should technically set counter = 1, not counter + 1.

		$current = self::getSecurityEvent($ip, $type); // This will clear it if expired

		$table = self::table('security_events');
		try {
			$stmt = $pdo->prepare("INSERT INTO `{$table}` (`ip_address`, `event_type`, `counter`, `last_updated_at`) 
				VALUES (?, ?, 1, ?) 
				ON DUPLICATE KEY UPDATE `counter` = `counter` + 1, `last_updated_at` = ?");
			$stmt->execute([$ip, $type, time(), time()]);
		} catch (PDOException $e) {
		}
	}

	public static function clearSecurityEvent(string $ip, string $type): void
	{
		$pdo = self::getInstance();
		if (!$pdo)
			return;

		$table = self::table('security_events');
		try {
			$stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `ip_address` = ? AND `event_type` = ?");
			$stmt->execute([$ip, $type]);
		} catch (PDOException $e) {
		}
	}

	/* Report throttle (per-IP) — delegated to ReportRepository (Faza 5 · #2). */

	public static function markReportVerified(string $ip): void
	{
		ReportRepository::markVerified($ip);
	}

	public static function getReportCount(string $ip, int $minutes): int
	{
		return ReportRepository::countForIP($ip, $minutes);
	}

	/* Traffic accounting / dashboard metrics — delegated to TrafficRepository (Faza 5 · #2). */

	public static function logTraffic(string $ip, int $size, string $type, ?string $fileId = null, ?int $userId = null): bool
	{
		return TrafficRepository::log($ip, $size, $type, $fileId, $userId);
	}

	public static function getTrafficStats(string $period = 'day'): int
	{
		return TrafficRepository::stats($period);
	}

	public static function getTopTrafficIPs(int $hours = 24, int $limit = 50): array
	{
		return TrafficRepository::topIPs($hours, $limit);
	}

	public static function getTrafficSeries(int $days = 7): array
	{
		return TrafficRepository::series($days);
	}

	public static function getTrafficSeriesRange(int $from, int $to, string $bucket = 'day'): array
	{
		return TrafficRepository::seriesRange($from, $to, $bucket);
	}

	public static function getTopFiles(int $limit = 5, ?int $from = null, ?int $to = null): array
	{
		return TrafficRepository::topFiles($limit, $from, $to);
	}

	public static function getActiveDownloadCount(): int
	{
		return ActiveDownloadRepository::count();
	}

	/** Live list of in-progress downloads (Faza 2.1), joined with the file name. */
	public static function getActiveDownloads(int $limit = 100): array
	{
		return ActiveDownloadRepository::listActive($limit);
	}

	/** Sever an in-progress download (Faza 2.1). See ActiveDownloadRepository::kill(). */
	public static function killActiveDownload(int $id): bool
	{
		return ActiveDownloadRepository::kill($id);
	}

	public static function getSuspiciousIPs(int $thresholdBytes, int $hours = 24): array
	{
		return TrafficRepository::suspiciousIPs($thresholdBytes, $hours);
	}
}
