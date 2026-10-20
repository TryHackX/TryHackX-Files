<?php

/**
 * Maintenance mode: who still gets in, and what everyone else sees.
 *
 * While it is on, only a signed-in administrator uses the site, its pages and its APIs. What
 * signing in takes stays open (the sign-in dialog, the password and 2FA steps, the
 * configuration the dialog loads, signing out), so an administrator whose session ended can get
 * back in to switch it off again. So do the machine callers that must not wait for the work to
 * finish: the health probe and the payment providers' notifications, which would otherwise
 * bounce for the whole window and leave paid orders to the providers' retries.
 */
final class MaintenanceMode
{
	/** Browser-API actions answered normally during maintenance, whoever asks. */
	public const OPEN_API_ACTIONS = [
		'user_login',
		'user_2fa_login',
		'user_logout',
		'config',
		'captcha_config',
		'health',
		'payu_notify',
		'p24_notify',
		'p24_refund_notify',
		'premium_activate',
	];

	public static function active(): bool
	{
		return Database::getSetting('maintenance_mode', '0') === '1';
	}

	/** The operator's message, or the default one in the visitor's language. */
	public static function message(): string
	{
		$custom = trim((string) Database::getSetting('maintenance_message', ''));
		return $custom !== '' ? $custom : __('error.maintenance_message');
	}

	/**
	 * The gate of every page (home, file, collection, plans, panel): anyone but an administrator
	 * gets the maintenance notice (503) instead. `?action=login` shows the same notice with the
	 * sign-in dialog open, and the notice links there.
	 */
	public static function guardPage(): void
	{
		if (!self::active()) {
			return;
		}
		$user = getCurrentUser();
		if ($user && !empty($user['is_admin'])) {
			return;
		}

		http_response_code(503);
		header('Cache-Control: no-store');
		$appUrl = APP_URL;
		// The sign-in dialog renders the account view for a signed-in visitor, with the same
		// fields the public pages give it — never the whole session user.
		$currentUser = $user ? [
			'id' => (int) $user['id'],
			'username' => (string) ($user['username'] ?? ''),
			'is_admin' => false,
		] : null;
		$i18nScope = 'public';
		$pageTitle = __('error.maintenance_title');
		$pageIcon = 'fa-screwdriver-wrench';
		$pageHeading = __('error.maintenance_heading');
		$pageMessage = self::message();
		$pageSignIn = ($_GET['action'] ?? '') === 'login';
		$pageLink = $pageSignIn ? null : [
			'href' => APP_URL . '/?action=login',
			'label' => __('error.admin_sign_in'),
			'icon' => 'fa-right-to-bracket',
		];
		require __DIR__ . '/error_page.php';
		exit;
	}
}
