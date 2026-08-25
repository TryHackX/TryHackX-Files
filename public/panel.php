<?php
/**
 * TryHackX Files — unified panel (admin / moderator / user).
 *
 * Thin orchestrator: bootstrap → auth → CSRF-protected POST controller → layout.
 * Views live in includes/panel/views.php, modals in includes/panel/modals.php,
 * behaviour in assets/js/panel.js.
 */
// Like api.php: whatever goes wrong mid-request (an unreachable mailserver, a deprecation)
// must land in the log, not inside the panel markup.
ini_set('display_errors', 0);

define('FILEHOST_CSP_STRICT_SCRIPTS', true);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/includes/FileManager.php';
// pt 6: the tab bar asks whether premium is on, and the premium views read its settings.
require_once __DIR__ . '/../src/includes/Markdown.php';
require_once __DIR__ . '/../src/includes/api/PremiumController.php';
// Faza 8: the tab bar asks whether ads are on; the ads views read the zone catalogue.
require_once __DIR__ . '/../src/includes/AdRenderer.php';

$appUrl = APP_URL;
$error = '';
$success = '';
$tab = $_GET['tab'] ?? 'dashboard';
$settingsTab = $_GET['stab'] ?? 'general';
$modTab = $_GET['mstab'] ?? 'reports';

// Śledzenie i Audyt są teraz podzakładkami Moderacji. Stare adresy nadal działają — ktoś ma je
// w zakładkach przeglądarki, a link „Wyczyść próg" w samym śledzeniu też ich używał.
if ($tab === 'tracking' || $tab === 'audit') {
	$modTab = $tab;
	$tab = 'moderate';
}

$currentUser = getCurrentUser();
$loggedIn = $currentUser !== null;
$isAdmin = $currentUser && $currentUser['is_admin'];

if (!$loggedIn) {
	header('Location: ' . $appUrl . '/?action=login');
	exit;
}

if (isset($_GET['logout'])) {
	// Destroying the session is not enough: a "stay signed in" cookie would restore it on the
	// next request, and the user would watch themselves get signed back in.
	$rememberCookie = RememberTokenRepository::presentedCookie();
	if ($rememberCookie !== '') {
		RememberTokenRepository::forget($rememberCookie);
		RememberTokenRepository::sendCookie('', 0);
	}
	session_destroy();
	header('Location: ' . $appUrl . '/');
	exit;
}

// --- Second gate: the panel wants a password, not just a session ---------------------------
// A valid session says the browser signed in once. It does not say the person here now is the
// account owner: the machine may be shared, the tab may be weeks old, the session may have
// been restored from a device cookie. Staff pay for that with one password per idle window.
require_once __DIR__ . '/../src/includes/api/ApiSupport.php';
require_once __DIR__ . '/../src/includes/RateLimiter.php';
$reauthError = '';
$reauthLocked = false;

// Two counters, because they answer different questions.
//
// The session counter decides when to add a captcha. It cannot be cheaply reset: clearing the
// session is what gets you sent back to the sign-in form, and that path is already throttled
// and captcha'd. The rate limiter is the durable backstop — keyed on account plus address, it
// survives a dropped session and caps the attempt rate no matter what the browser does.
$reauthFails = (int) ($_SESSION['panel_reauth_fails'] ?? 0);
$reauthCaptchaRequired = $reauthFails >= 3 && Database::isRecaptchaEnabled();

if (($_POST['action'] ?? '') === 'panel_reauth') {
	$quota = RateLimiter::hit(
		'reauth:' . (int) $currentUser['id'] . ':' . getClientIP(),
		'auth'
	);
	if (!$quota['allowed']) {
		$reauthLocked = true;
		$reauthError = __('panel.reauth.throttled', [
			'minutes' => (string) max(1, (int) ceil(($quota['reset'] - time()) / 60)),
		]);
		Database::logAudit(
			'panel_reauth_throttled',
			'attempt rate exceeded at the panel gate',
			(int) $currentUser['id'],
			(string) ($currentUser['username'] ?? '')
		);
	} elseif (!csrfValidate()) {
		$reauthError = __('api.csrf');
	} elseif ($reauthCaptchaRequired
		&& !Database::verifyRecaptcha((string) ($_POST['captcha_response'] ?? ''), getClientIP())) {
		$reauthError = __('panel.reauth.captcha_failed');
	} elseif (!Database::verifyUserPassword((int) $currentUser['id'], (string) ($_POST['password'] ?? ''))) {
		$reauthFails++;
		$_SESSION['panel_reauth_fails'] = $reauthFails;
		$reauthCaptchaRequired = $reauthFails >= 3 && Database::isRecaptchaEnabled();
		$reauthError = __('api.bad_password');
		Database::logAudit(
			'panel_reauth_failed',
			'wrong password at the panel gate (attempt ' . $reauthFails . ')',
			(int) $currentUser['id'],
			(string) ($currentUser['username'] ?? '')
		);
	} else {
		unset($_SESSION['panel_reauth_fails']);
		$_SESSION['panel_auth_at'] = time();
		$_SESSION['recent_auth_at'] = time();
		header('Location: ' . $appUrl . '/panel.php');
		exit;
	}
}
if (!panelAuthorizationValid($currentUser)) {
	$reauthUsername = (string) ($currentUser['username'] ?? '');
	require __DIR__ . '/../src/includes/panel_reauth_page.php';
	exit;
}
// Idle window: working in the panel keeps it open, walking away closes it.
touchPanelAuthorization();

$userRole = $currentUser['role'] ?? 'user';
$isMod = $isAdmin || $userRole === 'moderator';
$canReports = Permissions::has('moderation.reports.view');
$canTraffic = Permissions::has('moderation.traffic.view');
$canAudit = Permissions::has('moderation.audit.view');
$canPremiumPanel = PremiumController::isEnabled() && (
	Permissions::has('premium.metrics')
	|| Permissions::has('premium.payments')
	|| Permissions::has('premium.subscribers')
);

// Faza 8 (runda 2): the ads manager lives under Moderacja now — the top bar was wrapping
// to a second line. It opens for the admin and for any group holding one of the manager
// permissions, each of which unlocks its own slice of the view.
$canAdsPanel = $isAdmin || Permissions::has('ads.manage') || Permissions::has('ads.approve')
	|| Permissions::has('ads.metrics') || Permissions::has('ads.packages');
$canModerationPanel = $canReports || $canTraffic || $canAudit || $canPremiumPanel || $canAdsPanel;
$adsTab = $_GET['astab'] ?? '';

// Legacy address: ?tab=ads was the manager's home for one release.
if ($tab === 'ads') {
	$modTab = 'ads';
	$tab = 'moderate';
}

// Faza 8 runda 3: the admin's Premium views (sales/subscribers) moved under Moderacja for
// the same reason the ads manager did — the top bar was wrapping. A regular user keeps the
// top-level Premium tab: their menu has room, and "Mój plan" is not a moderation job.
if ($tab === 'premium' && $isAdmin && PremiumController::isEnabled()) {
	$modTab = 'premium';
	$tab = 'moderate';
}

// Moderation sub-tab: the audit log is the administrator's, the other two a moderator's. An
// address for a view this session may not see resolves to the first one it may — asked here
// rather than in the view, so the sub-tab bar and the body cannot disagree.
$modTabAllowed = [
	'reports' => $canReports,
	'tracking' => $canTraffic,
	'audit' => $canAudit,
	'premium' => $canPremiumPanel,
	'ads' => $canAdsPanel,
];
if (empty($modTabAllowed[$modTab])) {
	// First view this session may see — for an ads-only group that is the ads manager.
	$modTab = 'reports';
	foreach ($modTabAllowed as $key => $allowed) {
		if ($allowed) {
			$modTab = $key;
			break;
		}
	}
}

// The all-files browser is opened by a group permission now, not by the admin flag alone
// (Faza 6 · #1). The API enforces the same check; this only decides what the panel offers.
$canBrowseAll = $isAdmin || Permissions::has('files.view_all');

// Handle POST actions (validates CSRF, admin-guards mutations, may set $error/$success).
require __DIR__ . '/../src/includes/panel/controller.php';

// Non-privileged users only get their personal tabs — plus the all-files browser when their
// group grants it, and the ads manager when an ads permission opens it.
if (
	!$isAdmin
	&& $tab !== 'myfiles' && $tab !== 'user' && $tab !== 'premium' && $tab !== 'notifications'
	&& $tab !== 'myads'
	&& !($tab === 'files' && $canBrowseAll)
	&& !($tab === 'moderate' && $canModerationPanel)
) {
	$tab = 'myfiles';
}

// pt 6: the Premium tab is everyone's, but only while there is a premium to be on.
if ($tab === 'premium' && !PremiumController::isEnabled()) {
	$tab = $isAdmin ? 'dashboard' : 'myfiles';
}

// Faza 8: "Moje reklamy" exists while ad sales are on, for groups allowed to buy. The ads
// manager stays reachable even when ads are off, so placements can be prepared before the
// switch is flipped (public rendering is hard-gated regardless).
if ($tab === 'myads' && (!AdsController::sellingEnabled() || (!$isAdmin && !Permissions::has('ads.buy')))) {
	$tab = $isAdmin ? 'dashboard' : 'myfiles';
}

// Faza 8: the buyer returning from PayU lands on ?tab=myads&order=… — same contract as
// premium.php: ask the provider outright instead of waiting for a webhook that may never
// reach this installation. `aderr` carries AdsController's checkout bounce codes.
$myAdsNoticeText = '';
$myAdsNoticeKind = '';
if ($tab === 'myads') {
	$adErr = preg_replace('/[^a-z_]/', '', (string) ($_GET['aderr'] ?? ''));
	if ($adErr !== '' && Lang::has('myads.err_' . $adErr)) {
		$myAdsNoticeText = __('myads.err_' . $adErr);
		$myAdsNoticeKind = 'error';
	} elseif (!empty($_GET['order'])) {
		$adOrder = PaymentRepository::byExtOrderId((string) $_GET['order']);
		if ($adOrder && (int) $adOrder['user_id'] === (int) $currentUser['id']
			&& ($adOrder['kind'] ?? '') === PaymentRepository::KIND_AD) {
			// GET rendering is read-only; webhooks and the scheduled worker own reconciliation.
			$adPaid = (string) $adOrder['status'] === PaymentRepository::COMPLETED;
			$myAdsNoticeText = $adPaid ? __('myads.order_done') : __('myads.order_pending');
			$myAdsNoticeKind = $adPaid ? 'success' : 'info';
		}
	}
}

$stats = $isAdmin ? FileManager::getStats() : [];
$settings = $isAdmin ? Database::getAllSettings() : [];

// "My files" and "Account" show the same three personal counters. They used to render
// placeholders ("0" / "…") and fill in over AJAX, so every switch between the two tabs
// flashed the placeholder before the real number. Computing them here means the first paint
// already carries the right values; the AJAX refresh then only confirms them.
$userStats = ($tab === 'myfiles' || $tab === 'user')
	? Database::getUserStats((int) $currentUser['id'])
	: ['files_count' => 0, 'total_size' => 0, 'total_downloads' => 0];
$theme = $_COOKIE['theme'] ?? 'dark';

?>
<!DOCTYPE html>
<html lang="<?= Lang::current() ?>">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?= _h('panel.title') ?> - <?= APP_NAME ?></title>
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/fontawesome/css/all.min.css?v=<?= APP_VERSION ?>">
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/panel.css?v=<?= APP_VERSION ?>">
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/background.css?v=<?= APP_VERSION ?>">
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/panel-forms.css?v=<?= APP_VERSION ?>">
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/panel-shell.css?v=<?= APP_VERSION ?>">
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/panel-features.css?v=<?= APP_VERSION ?>">
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/panel-premium.css?v=<?= APP_VERSION ?>">
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/panel-ads-admin.css?v=<?= APP_VERSION ?>">
	<?php /* Shared with the public pages so the bell looks the same wherever the header is. */ ?>
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/notifications.css?v=<?= APP_VERSION ?>">
	<?php require __DIR__ . '/../src/includes/site_footer_assets.php'; ?>
	<?php require_once __DIR__ . '/../src/includes/csrf_head.php'; ?>
	<?php require_once __DIR__ . '/../src/includes/i18n_head.php'; ?>
</head>

<body class="<?= $theme === 'light' ? 'light' : '' ?>">

	<div class="container">
		<?php require __DIR__ . '/../src/includes/header_ui.php'; ?>

		<?php if ($success): ?>
			<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
		<?php endif; ?>
		<?php if ($error): ?>
			<div class="alert alert-error"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error) ?></div>
		<?php endif; ?>

		<?php require __DIR__ . '/../src/includes/panel/views.php'; ?>
		<?php require __DIR__ . '/../src/includes/site_footer.php'; ?>
	</div>

	<!-- Logged-in account modal (opened from the header person icon) -->
	<div class="auth-modal" id="authModal">
		<div class="auth-box">
			<button class="auth-close" type="button" data-panel-action="close-auth">
				<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
					<line x1="18" y1="6" x2="6" y2="18" />
					<line x1="6" y1="6" x2="18" y2="18" />
				</svg>
			</button>
			<div id="authUser" style="display: block;">
				<h3><?= _h('auth.logged_in') ?></h3>
				<div class="auth-user">
					<div class="auth-user-icon"><?= strtoupper(substr($currentUser['username'] ?? '?', 0, 1)) ?></div>
					<div class="auth-user-info">
						<div class="auth-user-name"><?= htmlspecialchars($currentUser['username'] ?? _h('auth.role_user')) ?></div>
						<div class="auth-user-role"><?= $isAdmin ? _h('auth.role_admin') : _h('auth.role_user') ?></div>
					</div>
				</div>
				<div class="session-info" id="sessionInfo" style="margin-bottom: 12px; display:none;">
					<?= _h('panel.session_left') ?> <span id="sessionTime">-</span>
				</div>
				<div class="user-stats-grid" id="userStats" style="display:none;">
					<div class="user-stat-card"><span><?= _h('auth.stat_files') ?></span><strong id="statFiles">0</strong></div>
					<div class="user-stat-card"><span><?= _h('auth.stat_size') ?></span><strong id="statSize">0 MB</strong></div>
					<div class="user-stat-card"><span><?= _h('auth.stat_downloads') ?></span><strong id="statDownloads">0</strong></div>
				</div>
				<div class="auth-links">
					<a href="<?= $appUrl ?>/" class="auth-link">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
							<polyline points="9 22 9 12 15 12 15 22"></polyline>
						</svg>
						<?= _h('auth.home') ?>
					</a>
					<a href="<?= $appUrl ?>/panel.php?tab=myfiles" class="auth-link">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
						</svg>
						<?= _h('panel.title') ?>
					</a>
					<a href="#" class="auth-link danger" data-panel-action="logout">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
							<polyline points="16 17 21 12 16 7" />
							<line x1="21" y1="12" x2="9" y2="12" />
						</svg>
						<?= _h('auth.logout') ?>
					</a>
				</div>
			</div>
		</div>
	</div>

	<?php require __DIR__ . '/../src/includes/panel/modals.php'; ?>

	<?php
	$panelBootstrap = json_encode([
		'appUrl' => $appUrl,
		'apiUrl' => $appUrl . '/api.php',
		'host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
		'tab' => $tab,
		'subTab' => $settingsTab,
		'modTab' => $modTab,
		'adsTab' => $adsTab,
		'isAdmin' => (bool) $isAdmin,
		'isMod' => (bool) $isMod,
		'loggedIn' => true,
		'inputLimits' => [
			'usernameMin' => InputLimits::usernameMin(),
			'usernameMax' => InputLimits::usernameMax(),
			'emailMax' => InputLimits::emailMax(),
			'passwordMin' => InputLimits::accountPasswordMin(),
			'passwordMax' => InputLimits::accountPasswordMax(),
		],
		// UI hints only. Every API endpoint enforces the permission again.
		'perms' => Permissions::forCurrentUser(),
	], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	if ($panelBootstrap === false) {
		$panelBootstrap = '{}';
	}
	?>
	<div id="panelBootstrap" hidden
		data-config="<?= htmlspecialchars(
			$panelBootstrap,
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		) ?>"></div>
	<script src="<?= $appUrl ?>/assets/js/util.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/api.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/ui.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/file-icons.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/notifications.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/panel-notifications.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/panel-docs.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/panel-account-tools.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/panel-files.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/panel-users.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/panel-settings.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/panel-languages.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/panel-groups.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/panel-moderation.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/panel-dashboard.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/panel-premium.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/panel-ads.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/panel.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/panel-table-sort.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/panel-events.js?v=<?= APP_VERSION ?>"></script>
</body>

</html>
