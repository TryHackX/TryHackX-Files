<?php
// This page has no executable inline scripts or HTML event handlers. Keep it as the first
// enforced CSP island while the larger panel/download templates are migrated incrementally.
define('FILEHOST_CSP_STRICT_SCRIPTS', true);
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/includes/AdRenderer.php';
$appUrl = APP_URL;

// Maintenance Mode Check
$maintenanceMode = Database::getSetting('maintenance_mode', '0') === '1';
if ($maintenanceMode) {
	$currentUser = getCurrentUser();
	if (!$currentUser || !$currentUser['is_admin']) {
		http_response_code(503);
		$pageTitle = __('error.maintenance_title');
		$pageIcon = '<i class="fa-solid fa-screwdriver-wrench"></i>';
		$pageHeading = __('error.maintenance_heading');
		// B8: an empty custom message falls back to the visitor's-language default.
			$maintMsg = trim((string) Database::getSetting('maintenance_message', ''));
			$pageMessage = $maintMsg !== '' ? $maintMsg : __('error.maintenance_message');
		require __DIR__ . '/../src/includes/error_page.php';
		exit;
	}
}

// Ban Logic
if (Database::getInstance()) {
	$ban = Database::getBanDetails('ip', getClientIP());
	if ($ban) {
		http_response_code(403);
		$pageTitle = __('error.denied_title');
		$pageIcon = '<i class="fa-solid fa-ban"></i>';
		$pageHeading = __('error.banned_heading');
		$pageMessage = __('error.banned_message');
		$pageMeta = [
			__('error.ban_reason') => $ban['reason'] ?: __('error.ban_reason_none'),
			__('error.ban_expires') => $ban['expires_at'] ? date('d.m.Y H:i', $ban['expires_at']) : __('error.ban_never'),
		];
		require __DIR__ . '/../src/includes/error_page.php';
		exit;
	}
}

$currentUser = null;
if (isset($_SESSION['user_id'])) {
	$currentUser = [
		'id' => $_SESSION['user_id'],
		'username' => $_SESSION['user_name'] ?? __('error.user_fallback'),
		'is_admin' => $_SESSION['is_admin'] ?? false
	];
}

$theme = $_COOKIE['theme'] ?? 'dark';

// Effective per-user limits come from the user's group (A8), falling back to the flat
// user-tier settings when a group leaves a field at 0. Guests use the guest-tier settings.
$userLimitMB = USER_MAX_FILE_SIZE_MB;
$userLimitFiles = USER_MAX_FILES;
if ($currentUser) {
	$grp = Database::getUserEffectiveGroup((int) $currentUser['id']);
	if ($grp) {
		if ((int) $grp['max_file_size_mb'] > 0) {
			$userLimitMB = (int) $grp['max_file_size_mb'];
		}
		if ((int) $grp['max_files_per_session'] > 0) {
			$userLimitFiles = (int) $grp['max_files_per_session'];
		}
	}
}
$maxSizeMB = $currentUser ? $userLimitMB : GUEST_MAX_FILE_SIZE_MB;
$maxFiles = $currentUser ? $userLimitFiles : GUEST_MAX_FILES;

// Single source of truth for the blocked-extension list (DB), shared with the Python server.
$blockedExtList = array_values(array_filter(array_map(
	'trim',
	explode(',', strtolower(Database::getSetting('blocked_extensions', '')))
)));
?>
<!DOCTYPE html>
<html lang="<?= Lang::current() ?>">

<head>
	<?php require_once __DIR__ . '/../src/includes/head_meta.php'; ?>
	<?php require_once __DIR__ . '/../src/includes/csrf_head.php'; ?>
	<?php require_once __DIR__ . '/../src/includes/i18n_head.php'; ?>
	<title><?= APP_NAME ?> - <?= _h('home.tagline') ?></title>
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/index.css?v=<?= APP_VERSION ?>">
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/notifications.css?v=<?= APP_VERSION ?>">
	<?php require __DIR__ . '/../src/includes/site_footer_assets.php'; ?>
	<?= AdRenderer::styles() ?>
</head>

<body class="<?= $theme === 'light' ? 'light' : '' ?>">

	<div class="container">
		<?php require_once __DIR__ . '/../src/includes/header_ui.php'; ?>

		<?= AdRenderer::zone('home_top') ?>

		<div class="hero">
			<h1><?= _h('home.hero_1') ?><br><span><?= _h('home.hero_2') ?></span></h1>
			<p><?= _h('home.hero_sub') ?></p>
		</div>

		<?= AdRenderer::zone('home_below_hero') ?>

		<div class="upload-card">
			<div class="upload-header">
				<h2>
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
						<polyline points="17 8 12 3 7 8" />
						<line x1="12" y1="3" x2="12" y2="15" />
					</svg>
					<?= _h('home.upload_title') ?>
				</h2>
				<span><?= _h('home.limit_prefix') ?>
					<span
						id="limitSize"><?= $maxSizeMB >= 1024 ? round($maxSizeMB / 1024, 2) . ' GiB' : $maxSizeMB . ' MiB' ?></span>
					/ <span id="limitCount"><?= $maxFiles ?></span> <?= _h('home.limit_files_suffix') ?></span>
			</div>
			<div class="upload-body">
				<div class="drop-zone" id="dropZone">
					<svg class="drop-zone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
						stroke-width="1.5">
						<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
						<polyline points="17 8 12 3 7 8" />
						<line x1="12" y1="3" x2="12" y2="15" />
					</svg>
					<p class="drop-zone-text"><?= _h('home.drop_text') ?> <span id="browseBtn"><?= _h('home.drop_browse') ?></span></p>
					<input type="file" id="fileInput" multiple>
				</div>
				<div class="file-list" id="fileList"></div>
				<div class="session-info" id="sessionInfo"></div>

				<div class="login-prompt" id="loginPrompt" style="display: none;">
					<p><?= _h('home.login_prompt') ?></p>
					<button class="login-prompt-btn" id="homeAuthOpen" type="button">
						<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
							stroke-width="2">
							<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
							<polyline points="10 17 15 12 10 7" />
							<line x1="15" y1="12" x2="3" y2="12" />
						</svg>
						<?= _h('nav.login') ?>
					</button>
				</div>
			</div>
		</div>

		<div class="results" id="results">
			<div class="results-header">
				<h3>
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
						<polyline points="22 4 12 14.01 9 11.01" />
					</svg>
					<?= _h('home.results_title') ?>
				</h3>
				<div class="results-actions">
					<?php if ($currentUser): ?>
						<button class="copy-all" id="createCollectionHome" type="button" style="display:none;"><i class="fa-solid fa-box-archive"></i> <?= _h('home.create_collection') ?></button>
					<?php endif; ?>
					<button class="copy-all" id="copyAll" type="button"><?= _h('home.copy_all') ?></button>
				</div>
			</div>
			<div id="resultList"></div>
		</div>

		<?php
		// pt 9: the upload page is where a visitor meets the limits, so it is where a bigger
		// plan is worth mentioning — as a teaser that links to the full page, not a second
		// pricing table to keep in sync.
		$premiumTeaser = Database::getSetting('premium_enabled', '0') === '1'
			&& Database::getSetting('premium_show_home', '1') === '1';
		?>
		<?php if ($premiumTeaser): ?>
			<a class="premium-teaser" href="<?= $appUrl ?>/premium">
				<i class="fa-solid fa-gem"></i>
				<span>
					<strong><?= _h('premium.teaser_title') ?></strong>
					<small><?= _h('premium.teaser_sub') ?></small>
				</span>
				<i class="fa-solid fa-arrow-right"></i>
			</a>
		<?php endif; ?>

		<?= AdRenderer::zone('home_bottom') ?>

		<div class="captcha-overlay" id="captchaOverlay">
			<div class="captcha-box">
				<h3><?= _h('captcha.title') ?></h3>
				<p><?= _h('captcha.subtitle') ?></p>
				<div class="captcha-widget" id="captchaWidget"></div>
				<div class="captcha-error" id="captchaError"><?= _h('captcha.failed') ?></div>
				<div class="captcha-loading" id="captchaLoading"><?= _h('common.loading') ?></div>
			</div>
		</div>

		<?php require __DIR__ . '/../src/includes/site_footer.php'; ?>
	</div>

	<?php require_once __DIR__ . '/../src/includes/auth_modal.php'; ?>
	<?php require_once __DIR__ . '/../src/includes/auth_scripts.php'; ?>

	<div class="toast" id="toast">
		<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
			<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
			<polyline points="22 4 12 14.01 9 11.01" />
		</svg>
		<span id="toastText"><?= _h('home.toast_copied') ?></span>
	</div>

	<!-- QR overlay. Shared by the per-file QR and the "collection created" result; the extra
	     actions below only appear for the latter (see showCollectionResult). -->
	<div class="captcha-overlay" id="qrOverlay">
		<div class="captcha-box qr-box">
			<h3><?= _h('qr.title') ?></h3>
			<p><?= _h('qr.subtitle') ?></p>
			<div id="qrHolder" class="qr-holder"></div>
			<div id="qrLink" class="qr-link"></div>
			<div class="qr-actions" id="qrActions">
				<a class="btn" id="qrOpenBtn" href="#" target="_blank" style="display:none;">
					<i class="fa-solid fa-up-right-from-square"></i> <?= _h('qr.open') ?>
				</a>
				<button class="btn" id="qrCopyBtn" type="button" style="display:none;">
					<i class="fa-solid fa-copy"></i> <?= _h('common.copy') ?>
				</button>
				<a class="btn" id="qrEditBtn" href="#" style="display:none;">
					<i class="fa-solid fa-gear"></i> <?= _h('qr.edit') ?>
				</a>
				<button class="btn" id="qrCloseBtn" type="button"><?= _h('common.close') ?></button>
			</div>
		</div>
	</div>

		<!-- Per-file password overlay (set from the upload result) -->
		<div class="captcha-overlay" id="pwSetOverlay">
			<div class="captcha-box pw-box">
				<h3 id="pwSetTitle"><?= _h('pwset.title') ?></h3>
				<p><?= _h('pwset.file_label') ?> <strong id="pwSetFileName"></strong></p>
				<form id="pwSetForm">
					<input type="password" id="pwSetInput" class="pw-set-input" maxlength="1024" placeholder="<?= _h('pwset.placeholder') ?>"
						autocomplete="new-password" minlength="8">
					<div class="pwd-meter"><div class="pwd-meter-fill" id="pwSetBar"></div></div>
					<ul class="pwd-reqs pw-set-reqs">
						<li id="pwSetReqLen"><?= _h('pwd.req_len') ?></li>
						<li id="pwSetReqUpper"><?= _h('pwd.req_upper') ?></li>
						<li id="pwSetReqDigit"><?= _h('pwd.req_digit') ?></li>
						<li id="pwSetReqSpec"><?= _h('pwd.req_special') ?></li>
					</ul>
					<input type="password" id="pwSetInput2" class="pw-set-input" maxlength="1024" placeholder="<?= _h('pwd.repeat') ?>"
						autocomplete="new-password">
					<div id="pwSetMatch" class="field-status"></div>
					<!-- Shown only when more than one file was uploaded (pt 9). -->
					<label class="coll-check pw-apply-all" id="pwSetApplyAllRow" style="display:none;">
						<input type="checkbox" id="pwSetApplyAll">
						<span><?= _h('pwset.apply_all') ?></span>
					</label>
					<div id="pwSetError" class="pw-set-error" style="display:none;"></div>
					<div class="pw-set-actions">
						<button type="button" class="btn" id="pwSetCancelBtn"><?= _h('common.cancel') ?></button>
						<button type="button" class="btn btn-clear" id="pwSetClearBtn"
							style="display:none;"><?= _h('pwset.clear') ?></button>
						<button type="button" class="btn btn-clear" id="pwSetClearAllBtn"
							style="display:none;"><?= _h('pwset.clear_all') ?></button>
						<button type="submit" class="btn btn-primary"><?= _h('common.save') ?></button>
					</div>
				</form>
			</div>
		</div>

		<!-- Collection overlay (pt 16): the upload page used to create a collection the instant
		     the button was pressed, with an auto-generated name and no sharing options. It now
		     asks for the same details the panel does before creating anything. -->
		<div class="captcha-overlay" id="collOverlay">
			<div class="captcha-box coll-box">
				<h3><?= _h('panel.cc.title') ?></h3>
				<p><?= _h('panel.cc.selected') ?> <strong id="collCount">0</strong></p>
				<form id="collForm">
					<div class="coll-field">
						<label for="collName"><?= _h('panel.cc.name') ?></label>
						<input type="text" id="collName" class="pw-set-input" placeholder="<?= _h('panel.cc.name_ph') ?>" maxlength="255">
					</div>
					<!-- pt 1: password prompts for protected files, shown only when the install does
					     not exempt files uploaded in this very session (collection_upload_exempt). -->
					<div id="collLocked" class="coll-locked" style="display:none;">
						<strong><i class="fa-solid fa-lock"></i> <?= _h('panel.cc.locked_title') ?></strong>
						<p><?= _h('panel.cc.locked_intro') ?></p>
						<div id="collLockedList"></div>
					</div>
					<div class="coll-row">
						<div class="coll-field">
							<label for="collExpiry"><?= _h('panel.fo.expiry') ?></label>
							<input type="number" id="collExpiry" class="pw-set-input" min="0" placeholder="<?= _h('panel.fo.expiry_ph') ?>">
						</div>
						<div class="coll-field">
							<label for="collMaxDl"><?= _h('panel.fo.max_dl') ?></label>
							<input type="number" id="collMaxDl" class="pw-set-input" min="0" placeholder="<?= _h('panel.fo.expiry_ph') ?>">
						</div>
					</div>
					<div class="coll-field">
						<label for="collLimitAction"><?= _h('panel.fo.on_limit') ?></label>
						<select id="collLimitAction" class="pw-set-input">
							<option value="keep"><?= _h('panel.fo.on_limit_keep') ?></option>
							<option value="delete"><?= _h('panel.fo.on_limit_coll_delete') ?></option>
						</select>
					</div>
					<label class="coll-check">
						<input type="checkbox" id="collOneTime">
						<span><i class="fa-solid fa-fire" style="color:#f97316"></i> <?= _h('panel.fo.onetime') ?></span>
					</label>
					<div class="coll-field">
						<label for="collPassword"><?= _h('panel.cc.password') ?></label>
						<input type="password" id="collPassword" class="pw-set-input" maxlength="1024" placeholder="<?= _h('panel.fo.password_ph') ?>"
							autocomplete="new-password" minlength="8">
					</div>
					<div class="pwd-meter"><div class="pwd-meter-fill" id="collPwBar"></div></div>
					<ul class="pwd-reqs pw-set-reqs">
						<li id="collReqLen"><?= _h('pwd.req_len') ?></li>
						<li id="collReqUpper"><?= _h('pwd.req_upper') ?></li>
						<li id="collReqDigit"><?= _h('pwd.req_digit') ?></li>
						<li id="collReqSpec"><?= _h('pwd.req_special') ?></li>
					</ul>
					<input type="password" id="collPassword2" class="pw-set-input" maxlength="1024" placeholder="<?= _h('pwd.repeat') ?>"
						autocomplete="new-password">
					<div id="collMatch" class="field-status"></div>
					<div id="collError" class="pw-set-error" style="display:none;"></div>
					<div class="pw-set-actions">
						<button type="button" class="btn" id="collCancelBtn"><?= _h('common.cancel') ?></button>
						<button type="submit" class="btn btn-primary"><?= _h('panel.cc.create') ?></button>
					</div>
				</form>
			</div>
		</div>

	<?php
	$indexBootstrap = json_encode([
		'appUrl' => $appUrl,
		'apiUrl' => $appUrl . '/api.php',
		'uploadUrl' => $appUrl . '/api/upload',
		'guestLimitMB' => GUEST_MAX_FILE_SIZE_MB,
		'userLimitMB' => $userLimitMB,
		'guestLimitFiles' => GUEST_MAX_FILES,
		'userLimitFiles' => $userLimitFiles,
		'maxSizeMB' => $maxSizeMB,
		'maxFiles' => $maxFiles,
		'blockedExtensions' => $blockedExtList,
		'loggedIn' => (bool) $currentUser,
		// Enforced server-side; this only decides whether the form asks up front.
		'collUploadExempt' =>
			Database::getSetting('collection_upload_exempt', '1') === '1',
	], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	if ($indexBootstrap === false) {
		$indexBootstrap = '{}';
	}
	?>
	<div id="indexBootstrap" hidden
		data-config="<?= htmlspecialchars(
			$indexBootstrap,
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		) ?>"></div>
	<script src="<?= $appUrl ?>/assets/js/util.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/api.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/ui.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/notifications.js?v=<?= APP_VERSION ?>" defer></script>
	<script src="<?= $appUrl ?>/assets/js/file-icons.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/index.js?v=<?= APP_VERSION ?>"></script>
	<?= AdRenderer::scripts() ?>

</body>

</html>
