<?php
/**
 * Panel POST controller.
 *
 * Runs in the panel.php scope. Expects: $currentUser, $isAdmin (bool).
 * Writes results into $error / $success and may adjust $tab / $settingsTab.
 * Admin-only actions are guarded; all POSTs require a valid CSRF token.
 */
if (!defined('APP_ROOT')) {
	exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
	return;
}

$isAjax = isset($_POST['ajax']);

if (!csrfValidate()) {
	if ($isAjax) {
		header('Content-Type: application/json');
		die(json_encode(['success' => false, 'error' => __('api.csrf')]));
	}
	$error = __('api.csrf');
	return;
}

$action = $_POST['action'];

// Only administrators may run panel mutations.
if (!$isAdmin) {
	return;
}

/**
 * Write (or replace) a string constant in config/config.local.php.
 *
 * The upload location lives there — not in the DB — because it is the one file that both PHP
 * and the Python upload server read, and PHP needs it before any DB connection exists. Editing
 * it from the panel therefore means rewriting that constant.
 */
function filehostWriteLocalConstant(string $name, string $value): bool
{
	$file = PROJECT_ROOT . '/config/config.local.php';
	$current = @file_get_contents($file);
	if ($current === false) {
		return false;
	}
	$line = "define('" . $name . "', '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "');";
	$pattern = "/^[ \t]*define\s*\(\s*['\"]" . preg_quote($name, '/') . "['\"].*$/m";
	$updated = preg_match($pattern, $current)
		? preg_replace($pattern, $line, $current, 1)
		: rtrim($current, "\r\n") . "\n" . $line . "\n";
	return @file_put_contents($file, $updated, LOCK_EX) !== false;
}

if ($action === 'save_settings') {
	$group = $_POST['setting_group'] ?? 'general';
	$settingsToUpdate = [];

	if ($group === 'general') {
		$lang = $_POST['default_language'] ?? 'pl';
		$settingsToUpdate = [
			'app_name' => trim($_POST['app_name'] ?? (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files')),
			'default_language' => Lang::supported($lang) ? $lang : 'pl',
			'maintenance_mode' => isset($_POST['maintenance_mode']) ? '1' : '0',
			// Empty = fall back to the per-language default on the maintenance page (B8).
			'maintenance_message' => trim($_POST['maintenance_message'] ?? ''),
			// Audit-log retention in days; 0 = keep forever (B7).
			'audit_retention_days' => max(0, (int) ($_POST['audit_retention_days'] ?? 30)),
		];
	} elseif ($group === 'storage') {
		// Upload location: written to config.local.php, never to the DB. Skipped entirely when
		// the operator locked it there (UPLOADS_PATH_LOCKED) — then only that file can change it.
		// NOTE: this only repoints the app; existing files are not moved (see docs/STORAGE.md).
		if (!(defined('UPLOADS_PATH_LOCKED') && UPLOADS_PATH_LOCKED) && isset($_POST['uploads_path'])) {
			$newPath = rtrim(str_replace('\\', '/', trim((string) $_POST['uploads_path'])), '/');
			$curPath = rtrim(str_replace('\\', '/', UPLOADS_DIR), '/');
			if ($newPath !== '' && $newPath !== $curPath) {
				if (!is_dir($newPath) && !@mkdir($newPath, 0755, true) && !is_dir($newPath)) {
					$error = __('panel.ctl.uploads_path_mkdir', ['path' => $newPath]);
				} elseif (!is_writable($newPath)) {
					$error = __('panel.ctl.uploads_path_write', ['path' => $newPath]);
				} elseif (!filehostWriteLocalConstant('UPLOADS_PATH', $newPath)) {
					$error = __('panel.ctl.uploads_path_save');
				} else {
					Database::logAudit('uploads_path_changed', $newPath);
					$success = __('panel.ctl.uploads_path_ok');
				}
			}
		}

		// Convert a value + MiB/GiB/TiB unit selector into whole MiB (values are stored in MiB).
		$toMb = function ($val, $unit) {
			$mb = (float) $val;
			if ($unit === 'GB') {
				$mb *= 1024;
			} elseif ($unit === 'TB') {
				$mb *= 1024 * 1024;
			}
			return (int) round($mb);
		};

		$maxUploadFolderVal = max(0, $toMb($_POST['max_upload_folder_mb'] ?? 0, $_POST['upload_folder_unit'] ?? 'MB'));
		$systemMaxVal = max(1, $toMb($_POST['system_max_file_size_mb'] ?? 5120, $_POST['system_max_unit'] ?? 'MB'));

		if ($maxUploadFolderVal > 0 && $maxUploadFolderVal < $systemMaxVal) {
			$error = __('panel.ctl.folder_lt_system');
		} else {
			$guestMax = (int) Database::getSetting('guest_max_file_size_mb', 250);
			$userMax = (int) Database::getSetting('user_max_file_size_mb', 5120);

			if ($systemMaxVal < $guestMax || $systemMaxVal < $userMax) {
				$error = __('panel.ctl.system_lt_limits', ['system' => $systemMaxVal, 'user' => $userMax, 'guest' => $guestMax]);
			} else {
				// Thumbnail size, clamped to the same range the upload server accepts.
				$thumbPx = max(64, min(2000, (int) ($_POST['thumbnail_max_px'] ?? 400)));
				$thumbPxOld = (int) Database::getSetting('thumbnail_max_px', 400);

				$settingsToUpdate = [
					'max_upload_folder_mb' => $maxUploadFolderVal,
					'auto_delete_days' => max(0, (int) ($_POST['auto_delete_days'] ?? 0)),
					'file_quarantine_days' => max(
						0,
						min(3650, (int) ($_POST['file_quarantine_days'] ?? 0))
					),
					'blocked_extensions' => trim($_POST['blocked_extensions'] ?? ''),
					'system_max_file_size_mb' => $systemMaxVal,
					'thumbnail_max_px' => $thumbPx,
					// pt 1: read by the Python server (settings_cache), which is what streams
					// collection ZIPs and therefore what does the counting.
					'collection_counts_file_downloads' => isset($_POST['collection_counts_file_downloads']) ? '1' : '0',
				];

				// Cached thumbnails were rendered at the previous size, so they no longer match
				// the setting. Drop them; /thumb regenerates each one lazily on next request.
				if ($thumbPx !== $thumbPxOld) {
					$removed = 0;
					foreach (glob(DATA_DIR . '/thumbs/*.jpg') ?: [] as $thumb) {
						if (@unlink($thumb)) {
							$removed++;
						}
					}
					Database::logAudit('thumbs_resized', "size {$thumbPxOld}px → {$thumbPx}px, cache cleared ({$removed})");
				}
			}
		}
	} elseif ($group === 'security') {
		$usernameMin = max(1, min(InputLimits::HARD_USERNAME_MAX, (int) ($_POST['input_username_min'] ?? InputLimits::USERNAME_MIN)));
		$usernameMax = max($usernameMin, min(InputLimits::HARD_USERNAME_MAX, (int) ($_POST['input_username_max'] ?? InputLimits::USERNAME_MAX)));
		$emailMax = max(64, min(InputLimits::HARD_EMAIL_MAX, (int) ($_POST['input_email_max'] ?? InputLimits::EMAIL_MAX)));
		$passwordMin = max(8, min(InputLimits::ACCOUNT_PASSWORD_MAX, (int) ($_POST['input_password_min'] ?? InputLimits::ACCOUNT_PASSWORD_MIN)));
		$passwordMax = max($passwordMin, min(InputLimits::ACCOUNT_PASSWORD_MAX, (int) ($_POST['input_password_max'] ?? InputLimits::ACCOUNT_PASSWORD_MAX)));
		$settingsToUpdate = [
			'registration_enabled' => isset($_POST['registration_enabled']) ? '1' : '0',
			'user_activation_mode' => in_array($_POST['user_activation_mode'] ?? 'auto', ['auto', 'email', 'admin']) ? $_POST['user_activation_mode'] : 'auto',
			'email_verification_required' => ($_POST['user_activation_mode'] ?? 'auto') === 'email' ? '1' : '0',
			'email_verification_lifetime' => max(1, (int) ($_POST['email_verification_lifetime'] ?? 24)),
			'input_username_min' => $usernameMin,
			'input_username_max' => $usernameMax,
			'input_email_max' => $emailMax,
			'input_password_min' => $passwordMin,
			'input_password_max' => $passwordMax,
			'recaptcha_enabled' => isset($_POST['recaptcha_enabled']) ? '1' : '0',
			'recaptcha_site_key' => trim($_POST['recaptcha_site_key'] ?? ''),
			'recaptcha_token_lifetime' => max(1, min(1440, (int) ($_POST['recaptcha_token_lifetime'] ?? 120))),
			'recaptcha_max_files_per_session_guest' => max(0, (int) ($_POST['recaptcha_max_files_per_session_guest'] ?? 0)),
			'recaptcha_max_files_per_session_auth' => max(0, (int) ($_POST['recaptcha_max_files_per_session_auth'] ?? 0)),
			'recaptcha_on_admin' => isset($_POST['recaptcha_on_admin']) ? '1' : '0',
			'recaptcha_login_attempt_threshold' => max(-1, (int) ($_POST['recaptcha_login_attempt_threshold'] ?? 3)),
			'recaptcha_delete_token_threshold' => max(-1, (int) ($_POST['recaptcha_delete_token_threshold'] ?? 3)),
			'recaptcha_file_password_threshold' => max(-1, (int) ($_POST['recaptcha_file_password_threshold'] ?? 3)),
			'recaptcha_download_threshold' => max(-1, (int) ($_POST['recaptcha_download_threshold'] ?? 0)),
			'recaptcha_register_always' => ($_POST['recaptcha_register_always'] ?? '0') === '1' ? '1' : '0',
			'recaptcha_report_threshold_count' => max(-1, (int) ($_POST['recaptcha_report_threshold_count'] ?? 5)),
			'recaptcha_security_window' => max(1, (int) ($_POST['recaptcha_security_window'] ?? 60)),
			'recovery_attempts_limit' => max(1, (int) ($_POST['recovery_attempts_limit'] ?? 5)),
			'recovery_window_hours' => max(1, (int) ($_POST['recovery_window_hours'] ?? 48)),
			// pt 1: whether a file uploaded in the current session may join a collection on the
			// strength of its delete token, instead of its password being retyped.
			'collection_upload_exempt' => isset($_POST['collection_upload_exempt']) ? '1' : '0',
			'collection_protected_file_policy' => in_array(
				$_POST['collection_protected_file_policy'] ?? 'prompt_skip',
				['prompt_skip', 'remember_access', 'require_collection_password'],
				true
			) ? $_POST['collection_protected_file_policy'] : 'prompt_skip',
			// pkt B: reclaiming storage from accounts a group change left over their limits.
			'storage_enforce' => isset($_POST['storage_enforce']) ? '1' : '0',
			'storage_grace_days' => max(0, min(365, (int) ($_POST['storage_grace_days'] ?? 15))),
			// pkt C: who may open an account, and how many.
			'email_domain_mode' => in_array($_POST['email_domain_mode'] ?? 'off', ['off', 'whitelist', 'blacklist'], true)
				? $_POST['email_domain_mode'] : 'off',
			'email_domain_list' => trim((string) ($_POST['email_domain_list'] ?? '')),
			'reg_ip_limit' => max(0, (int) ($_POST['reg_ip_limit'] ?? 0)),
			'reg_ip_window_days' => max(1, min(3650, (int) ($_POST['reg_ip_window_days'] ?? 90))),
			'email_release_days' => max(0, min(3650, (int) ($_POST['email_release_days'] ?? 0))),
			'login_remember_enabled' => isset($_POST['login_remember_enabled']) ? '1' : '0',
			'login_remember_max_days' => max(1, min(365, (int) ($_POST['login_remember_max_days'] ?? 30))),
			// 0 disables the panel gate entirely; anything else is clamped to a window that is
			// long enough to work in and short enough to matter.
			'panel_reauth_minutes' => (int) ($_POST['panel_reauth_minutes'] ?? 30) <= 0
				? 0
				: max(5, min(1440, (int) $_POST['panel_reauth_minutes'])),
			'panel_reauth_scope' => in_array($_POST['panel_reauth_scope'] ?? 'staff', ['staff', 'all'], true)
				? $_POST['panel_reauth_scope']
				: 'staff',
		];
		// Switching persistent sign-in off has to take the outstanding cookies with it. Left
		// alone, the setting would only stop new ones and every device already holding a token
		// would stay signed in for the rest of its month.
		if ((string) Database::getSetting('login_remember_enabled', '1') === '1'
			&& $settingsToUpdate['login_remember_enabled'] === '0') {
			$revoked = RememberTokenRepository::forgetAll();
			if ($revoked > 0) {
				Database::logAudit(
					'remember_tokens_revoked',
					"persistent sign-in disabled; {$revoked} device(s) signed out"
				);
			}
		}

		$recaptchaSecret = trim((string) ($_POST['recaptcha_secret_key'] ?? ''));
		if ($recaptchaSecret !== '') {
			if (strlen($recaptchaSecret) > InputLimits::PASSWORD_MAX) {
				$error = __('api.password_too_long');
			} else {
				Database::setSecretSetting('recaptcha_secret_key', $recaptchaSecret);
			}
		}
	} elseif ($group === 'email') {
		$settingsToUpdate = [
			'email_method' => in_array($_POST['email_method'] ?? 'php', ['php', 'local', 'smtp']) ? $_POST['email_method'] : 'php',
			'email_from' => trim($_POST['email_from'] ?? ''),
			'email_from_name' => trim($_POST['email_from_name'] ?? (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files')),
			'email_resend_cooldown' => max(1, (int) ($_POST['email_resend_cooldown'] ?? 30)),
			'smtp_host' => trim($_POST['smtp_host'] ?? ''),
			'smtp_port' => max(1, (int) ($_POST['smtp_port'] ?? 587)),
			'smtp_user' => trim($_POST['smtp_user'] ?? ''),
			'smtp_encryption' => in_array($_POST['smtp_encryption'] ?? 'tls', ['', 'tls', 'ssl']) ? $_POST['smtp_encryption'] : 'tls',
			'email_php_mail_guard' => in_array($_POST['email_php_mail_guard'] ?? 'fail', ['fail', 'local', 'off'], true) ? $_POST['email_php_mail_guard'] : 'fail',
		];

		// SMTP password (S12): stored encrypted at rest, never prefilled into the form.
		// A submitted non-empty value replaces it; a blank field keeps the current one.
		$smtpPass = $_POST['smtp_pass'] ?? '';
		if ($smtpPass !== '') {
			if (!is_string($smtpPass) || strlen($smtpPass) > InputLimits::PASSWORD_MAX) {
				$error = __('api.password_too_long');
			} else {
				Database::setSecretSetting('smtp_pass', $smtpPass);
			}
		}
	}

	foreach ($settingsToUpdate as $key => $value) {
		Database::setSetting($key, $value);
	}

	if (!$error) {
		if ($settingsToUpdate) {
			Database::logAudit('settings_saved', 'group: ' . $group);
		}
		$success = __('panel.ctl.saved');
		$tab = 'settings';
	}
} elseif ($action === 'save_cron') {
	// Who runs the housekeeping. The PHP path is stored as typed but only ever handed to the
	// upload server, which checks it exists before it tries to execute anything — a value that
	// is not a real file simply leaves the scheduler reporting "no PHP binary".
	if (!$isAdmin) {
		$error = __('api.unauthorized');
	} else {
		Database::setSetting('cron_enabled', empty($_POST['cron_enabled']) ? '0' : '1');
		Database::setSetting('cron_interval', (string) max(1, min(1440, (int) ($_POST['cron_interval'] ?? 15))));
		Database::setSetting('cron_php_binary', trim((string) ($_POST['cron_php_binary'] ?? '')));
		Database::logAudit('cron_settings_saved', empty($_POST['cron_enabled']) ? 'off' : 'on');
		$success = __('panel.ctl.saved');
		$tab = 'settings';
	}
} elseif ($action === 'save_notification_timing') {
	// How much notice the warning sweeps give. Two plain numbers, saved like any other setting;
	// 0 switches that warning off entirely (see scripts/cleanup.php).
	if (!$isAdmin) {
		$error = __('api.unauthorized');
	} else {
		Database::setSetting('notify_expiry_days', (string) max(0, min(90, (int) ($_POST['notify_expiry_days'] ?? 3))));
		Database::setSetting('notify_plan_days', (string) max(0, min(90, (int) ($_POST['notify_plan_days'] ?? 7))));
		Database::logAudit('notification_timing_saved', '');
		$success = __('panel.ctl.saved');
		// The sub-tab looks after itself: the form posts to the current URL, `?stab=` and all.
		$tab = 'settings';
	}
} elseif ($action === 'delete_file') {
	$fileId = $_POST['file_id'] ?? '';
	if ($fileId && FileManager::deleteFileAdmin($fileId)) {
		Database::logAudit('file_deleted', 'id: ' . $fileId);
		if ($isAjax) {
			header('Content-Type: application/json');
			die(json_encode(['success' => true]));
		}
		$success = __('panel.ctl.file_deleted');
	} else {
		if ($isAjax) {
			header('Content-Type: application/json');
			die(json_encode(['success' => false, 'error' => __('panel.ctl.file_delete_failed')]));
		}
		$error = __('panel.ctl.file_delete_failed');
	}
} elseif ($action === 'cleanup_old') {
	// pt 6: retention is per group now, so there is no single number to check — ask whether
	// anything on this install expires by age at all.
	if (FileManager::autoDeleteConfigured()) {
		$deleted = FileManager::deleteExpiredFiles();
		$success = __('panel.ctl.cleanup_old_done', ['n' => $deleted]);
	} else {
		$error = __('panel.ctl.autodelete_off');
	}
} elseif ($action === 'cleanup_custom') {
	// Legacy direct POST intentionally cannot bypass the preview + one-time confirmation API.
	$error = __('api.cleanup_preview_expired');
}
