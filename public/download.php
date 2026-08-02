<?php

define('FILEHOST_CSP_STRICT_SCRIPTS', true);
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/includes/FileManager.php';
require_once __DIR__ . '/../src/includes/Database.php';
require_once __DIR__ . '/../src/includes/file_icons.php';
require_once __DIR__ . '/../src/includes/AdRenderer.php';

$currentUser = null;
if (isset($_SESSION['user_id'])) {
	$currentUser = [
		'id' => $_SESSION['user_id'],
		'username' => $_SESSION['user_name'] ?? __('error.user_fallback'),
		'is_admin' => $_SESSION['is_admin'] ?? false
	];
}

$appUrl = APP_URL;
$fileId = $_GET['id'] ?? '';
$file = null;
$error = null;
$isProtected = false;

$recaptchaEnabled = Database::isRecaptchaEnabled();
$recaptchaOnReport = $recaptchaEnabled ? Database::getSetting('recaptcha_on_report', '0') : '0';
$recaptchaSiteKey = $recaptchaEnabled ? Database::getSetting('recaptcha_site_key', '') : '';
$recaptchaReportRequired = false; // Recomputed below when the file exists.

if (empty($fileId)) {
	$error = __('download.err_missing_id');
} elseif (!preg_match('/^[a-zA-Z0-9]+$/', $fileId)) {
	$error = __('download.err_bad_id');
} else {
	$file = FileManager::getFile($fileId);
	if (!$file) {
		$error = __('download.err_gone');
	} else {
		$file['name'] = urldecode($file['name']);
		$isProtected = Database::fileIsProtected($fileId);
	}
}
?>
<?php
$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="<?= Lang::current() ?>">

<head>
	<?php require_once __DIR__ . '/../src/includes/head_meta.php'; ?>
	<?php require_once __DIR__ . '/../src/includes/csrf_head.php'; ?>
	<?php require_once __DIR__ . '/../src/includes/i18n_head.php'; ?>
	<script src="<?= $appUrl ?>/assets/js/api.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/ui.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/notifications.js?v=<?= APP_VERSION ?>" defer></script>
	<title><?= $file ? htmlspecialchars($file['name']) : _h('common.error') ?> - <?= APP_NAME ?></title>
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/download.css?v=<?= APP_VERSION ?>">
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/notifications.css?v=<?= APP_VERSION ?>">
	<?php require __DIR__ . '/../src/includes/site_footer_assets.php'; ?>
	<?= AdRenderer::styles() ?>
</head>

<body class="<?= $theme === 'light' ? 'light' : '' ?>">
	<div class="bg-wrap">
		<div class="orb orb-1" id="orb1"></div>
		<div class="orb orb-2" id="orb2"></div>
		<div class="bg-grid"></div>
	</div>
	<div class="container">
		<?php require_once __DIR__ . '/../src/includes/header_ui.php'; ?>

		<?php if ($error): ?>
			<div class="error-box">
				<div class="icon"><i class="fa-regular fa-face-frown"></i></div>
				<h2><?= _h('download.not_found_title') ?></h2>
				<p><?= htmlspecialchars($error) ?></p>
				<a href="<?= $appUrl ?>/" class="btn btn-primary">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
						<polyline points="9 22 9 12 15 12 15 22" />
					</svg>
					<?= _h('common.back_home') ?>
				</a>
			</div>
		<?php else: ?>
			<?= AdRenderer::zone('download_top') ?>
			<div class="file-card">
				<div class="file-header">
					<div class="file-name"><?= htmlspecialchars($file['name']) ?></div>
					<div class="file-meta-grid">
						<div class="meta-card">
							<div class="meta-icon">
								<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
									stroke-width="2">
									<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
									<polyline points="14 2 14 8 20 8" />
								</svg>
							</div>
							<?php /* The friendly name ("Word"), with the full MIME on hover — see
							         fileTypeShort(). Printing the raw type let a 65-character
							         Office MIME push straight out of the card. */ ?>
							<?php $fileType = fileTypeShort($file['name'], $file['mimeType']); ?>
							<div class="meta-content">
								<span class="meta-label"><?= _h('download.file_type') ?></span>
								<span class="meta-value" title="<?= htmlspecialchars($fileType['full']) ?>"><?= htmlspecialchars($fileType['short']) ?></span>
							</div>
						</div>
						<div class="meta-card">
							<div class="meta-icon">
								<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
									stroke-width="2">
									<circle cx="12" cy="12" r="10" />
									<polyline points="12 6 12 12 16 14" />
								</svg>
							</div>
							<div class="meta-content">
								<span class="meta-label"><?= _h('common.size') ?></span>
								<span class="meta-value"><?= formatSize($file['size']) ?></span>
							</div>
						</div>
						<div class="meta-card">
							<div class="meta-icon">
								<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
									stroke-width="2">
									<rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
									<line x1="16" y1="2" x2="16" y2="6" />
									<line x1="8" y1="2" x2="8" y2="6" />
									<line x1="3" y1="10" x2="21" y2="10" />
								</svg>
							</div>
							<div class="meta-content">
								<span class="meta-label"><?= _h('download.uploaded') ?></span>
								<span class="meta-value"><?= date('d.m.Y', $file['uploadedAt']) ?></span>
							</div>
						</div>
						<div class="meta-card">
							<div class="meta-icon">
								<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
									stroke-width="2">
									<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
									<polyline points="7 10 12 15 17 10" />
									<line x1="12" y1="15" x2="12" y2="3" />
								</svg>
							</div>
							<div class="meta-content">
								<span class="meta-label"><?= _h('download.downloads') ?></span>
								<span class="meta-value" id="downloadCount"><?= $file['downloads'] ?></span>
							</div>
						</div>
					</div>
				</div>

				<div class="preview">
					<?php
					$type = $file['previewType'] ?? 'file';
					$previewUrl = $appUrl . '/api.php?action=preview&id=' . $fileId;
					// Embed/hotlink URL: renders inline like the preview but counts as a download,
					// so images embedded on other sites increment the counter (the on-page preview
					// via $previewUrl does not).
					$embedUrl = $appUrl . '/api.php?action=embed&id=' . $fileId;

					$isPreviewable = in_array($type, ['image', 'video', 'audio', 'pdf'], true);
					if ($isProtected && $isPreviewable) {
						// Password-protected: never reveal the content up-front. Show a locked
						// placeholder; the media is rendered client-side only after the password
						// is verified (see unlockPreview()).
						echo '<div class="preview-icon" id="previewLocked">'
							. '<div class="icon"><i class="fa-solid fa-lock"></i></div>'
							. '<p>' . _h('download.preview_locked') . '</p>'
							. '<button type="button" class="btn" data-download-action="unlock-preview" style="margin-top:12px;">' . _h('download.preview_show') . '</button>'
							. '</div>';
						echo '<div id="previewSlot" data-type="' . htmlspecialchars($type) . '" data-mime="' . htmlspecialchars($file['mimeType']) . '"></div>';
					} else switch ($type) {
						case 'image':
							echo '<img src="' . $previewUrl . '" alt="' . htmlspecialchars($file['name']) . '">';
							break;
						case 'video':
							echo '<video controls poster="' . $appUrl . '/api/thumb?id=' . urlencode($fileId) . '"><source src="' . $previewUrl . '" type="' . htmlspecialchars($file['mimeType']) . '"></video>';
							break;
						case 'audio':
							echo '<audio controls><source src="' . $previewUrl . '" type="' . htmlspecialchars($file['mimeType']) . '"></audio>';
							break;
						case 'pdf':
							echo '<iframe src="' . $previewUrl . '"></iframe>';
							break;
						default:
							$icon = fileIconHtml($file['name'], $file['mimeType']);
							echo '<div class="preview-icon"><div class="icon">' . $icon . '</div><p>' . _h('download.preview_unavailable') . '</p></div>';
					}

					// Check thresholds
					$reportThresholdCount = (int) Database::getSetting('recaptcha_report_threshold_count', 5);
					$reportThresholdTime = (int) Database::getSetting('recaptcha_security_window', 60);
					$userReportCount = Database::getReportCount(getClientIP(), $reportThresholdTime);
					// Dynamic visibility: Enabled in settings AND threshold reached
					$recaptchaReportRequired = ($recaptchaOnReport === '1' && $userReportCount >= $reportThresholdCount);
					?>
				</div>

				<div class="actions">
					<button class="btn" type="button" data-download-action="open-report" title="<?= _h('download.report_tooltip') ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" color="#ff4444">
							<path
								d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
							<line x1="12" y1="9" x2="12" y2="13" />
							<line x1="12" y1="17" x2="12.01" y2="17" />
						</svg>
					</button>

					<button class="btn btn-primary" type="button" data-download-action="download">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
							<polyline points="7 10 12 15 17 10" />
							<line x1="12" y1="15" x2="12" y2="3" />
						</svg>
						<?= _h('download.download_btn') ?>
					</button>
					<button class="btn" type="button" data-download-action="copy-link">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<rect x="9" y="9" width="13" height="13" rx="2" />
							<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
						</svg>
						<span id="copyText"><?= _h('download.copy_link') ?></span>
					</button>
					<?php if ($type === 'image' && !$isProtected): ?>
						<button class="btn" type="button" data-download-action="toggle-embed" id="embedToggleBtn" aria-expanded="false"
							aria-controls="embedSection" title="<?= _h('download.embed_tooltip') ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<polyline points="16 18 22 12 16 6" />
								<polyline points="8 6 2 12 8 18" />
							</svg>
							<?= _h('download.embed_btn') ?>
						</button>
					<?php endif; ?>
				</div>

				<?php if ($type === 'image' && !$isProtected): ?>
					<div class="embed-section" id="embedSection" hidden>
						<h3>
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<polyline points="16 18 22 12 16 6" />
								<polyline points="8 6 2 12 8 18" />
							</svg>
							<?= _h('download.embed_title') ?>
						</h3>
						<div class="embed-list">
							<div class="embed-row">
								<span class="embed-tag">Link</span>
								<code class="embed-code" id="embDirect"></code>
								<button class="btn btn-copy" type="button" data-embed-key="direct"><?= _h('common.copy') ?></button>
							</div>
							<div class="embed-row">
								<span class="embed-tag">BBCode</span>
								<code class="embed-code" id="embBb"></code>
								<button class="btn btn-copy" type="button" data-embed-key="bbcode"><?= _h('common.copy') ?></button>
							</div>
							<div class="embed-row">
								<span class="embed-tag">HTML</span>
								<code class="embed-code" id="embHtml"></code>
								<button class="btn btn-copy" type="button" data-embed-key="html"><?= _h('common.copy') ?></button>
							</div>
							<div class="embed-row">
								<span class="embed-tag">Markdown</span>
								<code class="embed-code" id="embMd"></code>
								<button class="btn btn-copy" type="button" data-embed-key="markdown"><?= _h('common.copy') ?></button>
							</div>
						</div>
						<p class="embed-note"><?= _h('download.embed_note') ?></p>
					</div>
				<?php endif; ?>
			</div>

			<div class="delete-section">
				<h3>
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<polyline points="3 6 5 6 21 6" />
						<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
					</svg>
					<?= _h('download.delete_title') ?>
				</h3>
				<div class="delete-form">
					<input type="text" id="deleteToken" maxlength="255" placeholder="<?= _h('download.delete_token_ph') ?>">
					<button class="btn btn-danger" type="button" data-download-action="delete"><?= _h('common.delete') ?></button>
				</div>
				<div class="delete-result" id="deleteResult"></div>
			</div>

			<?= AdRenderer::zone('download_bottom') ?>
		<?php endif; ?>

		<?php require __DIR__ . '/../src/includes/site_footer.php'; ?>
	</div>
	<div class="toast" id="dlToast"><span id="dlToastText"></span></div>

	<!-- Report Modal -->
	<div class="auth-modal" id="reportModal" style="z-index: 2000;">
		<div class="auth-box">
			<button class="auth-close" type="button" data-download-action="close-report">
				<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
					<line x1="18" y1="6" x2="6" y2="18" />
					<line x1="6" y1="6" x2="18" y2="18" />
				</svg>
			</button>
			<h3><?= _h('report.title') ?></h3>
			<p><?= _h('report.subtitle') ?></p>

			<form id="reportForm">
				<div class="form-group">
					<label><?= _h('report.name') ?></label>
					<input type="text" name="reporter_name" class="auth-input" required maxlength="100" placeholder="<?= _h('report.name_ph') ?>">
				</div>
				<div class="form-group">
					<label><?= _h('report.email') ?></label>
					<input type="email" name="reporter_email" id="reporterEmail" class="auth-input" required maxlength="254"
						placeholder="jan@example.com">
					<div class="field-status" id="reportEmailStatus"></div>
				</div>
				<div class="form-group">
					<label><?= _h('report.entity') ?></label>
					<input type="text" name="reporter_entity" class="auth-input" maxlength="255" placeholder="<?= _h('report.entity_ph') ?>">
				</div>
				<div class="form-group">
					<label><?= _h('report.org') ?></label>
					<input type="text" name="reporter_org" class="auth-input" maxlength="255" placeholder="<?= _h('report.org_ph') ?>">
				</div>
				<div class="form-group">
					<label><?= _h('report.obj_title') ?></label>
					<input type="text" name="report_title" class="auth-input" required readonly maxlength="255"
						value="<?= htmlspecialchars($file['name'] ?? '') ?>"
						style="background: var(--bg-hover) !important; color: var(--text-muted); cursor: not-allowed; opacity: 1;">
				</div>
				<div class="form-group">
					<label><?= _h('report.link') ?></label>
					<input type="url" name="report_link" class="auth-input" maxlength="255" placeholder="https://...">
				</div>
				<div class="form-group">
					<label><?= _h('report.info') ?></label>
					<textarea name="additional_info" class="auth-input" rows="4" maxlength="20000"
						placeholder="<?= _h('report.info_ph') ?>"></textarea>
				</div>

				<div id="reportMessage" class="report-message"
					style="display:none; padding:10px; margin-bottom:10px; border-radius:4px;"></div>

				<div style="display: flex; align-items: center; justify-content: center;">
					<div style="display: inline-grid; justify-items: center;">
						<div id="reportCaptchaContainer" style="margin-bottom: 20px; display: none;"></div>

						<div class="form-actions"
							style="display: flex;gap: 0.6vw;justify-content: center;align-items: center;">
							<button type="button" class="btn" data-download-action="close-report"><?= _h('common.cancel') ?></button>
							<button type="submit" class="btn btn-danger"><?= _h('report.submit') ?></button>
						</div>
					</div>
				</div>
			</form>
		</div>
	</div>

	<div class="captcha-modal" id="pwPromptOverlay">
			<div class="captcha-box">
				<h3><?= _h('pwprompt.title') ?></h3>
				<p><?= _h('pwprompt.subtitle') ?></p>
				<div id="pwPromptError" style="display:none; color:#ff6b6b; margin-bottom:10px; font-size:0.9rem;"><?= _h('pwprompt.wrong') ?></div>
				<form id="pwPromptForm">
					<input type="password" id="pwPromptInput" maxlength="1024" placeholder="<?= _h('pwprompt.placeholder') ?>" style="width:100%; padding:12px 14px; border:1px solid var(--border); border-radius:8px; background:var(--bg-secondary); color:var(--text); font-size:0.95rem;">
					<div style="display:flex; gap:10px; justify-content:center; margin-top:16px;">
						<button type="button" class="btn" data-download-action="close-password"><?= _h('common.cancel') ?></button>
						<button type="submit" class="btn btn-primary"><?= _h('pwprompt.submit') ?></button>
					</div>
				</form>
			</div>
		</div>

		<div class="captcha-modal" id="captchaModal">
		<div class="captcha-box">
			<h3><?= _h('captcha.title') ?></h3>
			<p><?= _h('captcha.subtitle_short') ?></p>
			<div class="captcha-widget" id="captchaWidget"></div>
			<div class="captcha-error" id="captchaError"><?= _h('captcha.failed') ?></div>
			<div class="captcha-loading" id="captchaLoading"><?= _h('common.loading') ?></div>
			<button class="btn" type="button" data-download-action="close-captcha" style="margin-top: 16px;"><?= _h('common.cancel') ?></button>
		</div>
	</div>
	<?php
	$embedCodes = [];
	if ($file && isset($type, $embedUrl) && $type === 'image' && !$isProtected) {
		$embedName = htmlspecialchars(
			(string) $file['name'],
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		);
		$embedCodes = [
			'direct' => $embedUrl,
			'bbcode' => '[img]' . $embedUrl . '[/img]',
			'html' => '<img src="' . $embedUrl . '" alt="' . $embedName . '">',
			'markdown' => '![' . $file['name'] . '](' . $embedUrl . ')',
		];
	}
	$downloadBootstrap = json_encode([
		'fileId' => $fileId,
		'homeUrl' => $appUrl . '/',
		'downloadUrl' => $appUrl . '/api/download?token=',
		'previewUrl' => $appUrl . '/api.php?action=preview&id=' . urlencode($fileId),
		'thumbnailUrl' => $appUrl . '/api/thumb?id=' . urlencode($fileId),
		'captchaSiteKey' => $recaptchaSiteKey,
		'reportCaptchaRequired' => $recaptchaReportRequired,
		'embedCodes' => $embedCodes,
	], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	if ($downloadBootstrap === false) {
		$downloadBootstrap = '{}';
	}
	?>
	<div id="downloadBootstrap" hidden
		data-config="<?= htmlspecialchars(
			$downloadBootstrap,
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		) ?>"></div>
	<script src="<?= $appUrl ?>/assets/js/download.js?v=<?= APP_VERSION ?>"></script>
	<?php require_once __DIR__ . '/../src/includes/auth_modal.php'; ?>
	<?php require_once __DIR__ . '/../src/includes/auth_scripts.php'; ?>
	<?= AdRenderer::scripts() ?>
</body>

</html>
