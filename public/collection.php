<?php
// Only the translation keys this page's scripts use are inlined for the browser (see Lang::forBrowser).
$i18nScope = 'public';
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/includes/Database.php';
require_once __DIR__ . '/../src/includes/file_icons.php';
require_once __DIR__ . '/../src/includes/AdRenderer.php';

MaintenanceMode::guardPage();

$appUrl = APP_URL;
$collectionId = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$collection = null;
$error = null;
$collectionPage = max(1, (int) ($_GET['page'] ?? 1));
$collectionPerPage = 25;
$collectionPages = 1;
$protectedCollectionFiles = [];

// Needed by the shared account modal (header person icon) so it can render the
// logged-in state on first paint, like index.php / download.php do.
$currentUser = null;
if (isset($_SESSION['user_id'])) {
	$currentUser = [
		'id' => $_SESSION['user_id'],
		'username' => $_SESSION['user_name'] ?? __('error.user_fallback'),
		'is_admin' => $_SESSION['is_admin'] ?? false,
	];
}

if (empty($collectionId)) {
	$error = __('collection.err_missing_id');
} elseif (!preg_match('/^[a-zA-Z0-9]+$/', $collectionId)) {
	$error = __('collection.err_bad_id');
} else {
	$collection = Database::getCollection($collectionId);
	if (!$collection) {
		$error = __('collection.err_gone');
	} else {
		$protectedCollectionFiles = array_values(array_filter(
			$collection['files'],
			static fn(array $file): bool => !empty($file['is_protected'])
		));
		$collectionPages = max(1, (int) ceil(((int) $collection['file_count']) / $collectionPerPage));
		$collectionPage = min($collectionPage, $collectionPages);
		$collection['files'] = array_slice(
			$collection['files'],
			($collectionPage - 1) * $collectionPerPage,
			$collectionPerPage
		);
	}
}

// C2: per-collection sharing controls — expiry, one-time, password. A protected collection
// renders only its meta + a password prompt; file names stay hidden until it is unlocked.
$needsPassword = false;
if ($collection) {
	if (!empty($collection['expires_at']) && (int) $collection['expires_at'] < time()) {
		$error = __('collection.err_expired');
		$collection = null;
	} elseif (!empty($collection['one_time']) && !empty($collection['consumed_at'])) {
		$error = __('collection.err_used');
		$collection = null;
	} elseif (!empty($collection['password_hash'])) {
		$needsPassword = true;
	}
}

$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="<?= Lang::current() ?>">

<head>
	<?php require_once __DIR__ . '/../src/includes/head_meta.php'; ?>
	<?php require_once __DIR__ . '/../src/includes/csrf_head.php'; ?>
	<?php require_once __DIR__ . '/../src/includes/i18n_head.php'; ?>
	<script src="<?= APP_URL ?>/assets/js/api.js?v=<?= APP_VERSION ?>"></script>
	<?php /* ui.js also carries the live language switch used by the header (pkt 1). */ ?>
	<script src="<?= APP_URL ?>/assets/js/ui.js?v=<?= APP_VERSION ?>" defer></script>
	<script src="<?= APP_URL ?>/assets/js/notifications.js?v=<?= APP_VERSION ?>" defer></script>
	<title><?= $collection ? htmlspecialchars($collection['name']) : _h('common.error') ?> - <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/base.css?v=<?= APP_VERSION ?>">
	<?php /* download.css also carries this page's own rules (the collection section). */ ?>
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/download.css?v=<?= APP_VERSION ?>">
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/background.css?v=<?= APP_VERSION ?>">
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/notifications.css?v=<?= APP_VERSION ?>">
	<?php require __DIR__ . '/../src/includes/site_footer_assets.php'; ?>
	<?= AdRenderer::styles() ?>
</head>

<body class="<?= $theme === 'light' ? 'light' : '' ?>">
	<div class="container">
		<?php require_once __DIR__ . '/../src/includes/header_ui.php'; ?>

		<?php if ($error): ?>
			<div class="error-box">
				<div class="icon"><i class="fa-regular fa-face-frown"></i></div>
				<h2><?= _h('collection.not_found_title') ?></h2>
				<p><?= htmlspecialchars($error) ?></p>
				<a href="<?= $appUrl ?>/" class="btn btn-primary">
					<i class="fa-solid fa-house"></i>
					<?= _h('common.back_home') ?>
				</a>
			</div>
		<?php else: ?>
			<?= AdRenderer::zone('collection_top') ?>
			<div class="file-card">
				<div class="file-header">
					<div class="file-name"><i class="fa-solid fa-box-archive coll-title-icon"></i><?= htmlspecialchars($collection['name']) ?></div>
					<div class="file-meta-grid">
						<div class="meta-card">
							<div class="meta-icon"><i class="fa-solid fa-folder-open"></i></div>
							<div class="meta-content">
								<span class="meta-label"><?= _h('collection.files_count') ?></span>
								<span class="meta-value"><?= (int) $collection['file_count'] ?></span>
							</div>
						</div>
						<div class="meta-card">
							<div class="meta-icon"><i class="fa-solid fa-database"></i></div>
							<div class="meta-content">
								<span class="meta-label"><?= _h('collection.total_size') ?></span>
								<span class="meta-value"><?= formatSize($collection['total_size']) ?></span>
							</div>
						</div>
						<div class="meta-card">
							<div class="meta-icon"><i class="fa-solid fa-download"></i></div>
							<div class="meta-content">
								<span class="meta-label"><?= _h('common.downloads') ?></span>
								<span class="meta-value"><?= (int) $collection['downloads'] ?></span>
							</div>
						</div>
					</div>
				</div>

				<?php if ($needsPassword): ?>
					<div class="coll-lock">
						<div class="coll-lock-icon"><i class="fa-solid fa-lock"></i></div>
						<p><?= _h('collection.protected') ?></p>
						<form id="collPwForm">
							<input type="password" id="collPwInput" maxlength="1024" placeholder="<?= _h('collection.password_ph') ?>"
								autocomplete="current-password" required>
							<div class="coll-pw-error" id="collPwError" hidden></div>
							<button type="submit" class="btn btn-primary" id="collPwBtn">
								<i class="fa-solid fa-unlock"></i> <?= _h('collection.unlock') ?>
							</button>
						</form>
					</div>
					<div class="coll-download-wrap" id="collUnlocked" hidden>
						<a href="#" id="collDownloadLink" data-collection-download class="btn btn-primary btn-download">
							<i class="fa-solid fa-file-zipper"></i>
							<?= _h('collection.download_all') ?>
						</a>
					</div>

				<?php elseif ((int) $collection['file_count'] > 0): ?>
					<div class="coll-download-wrap">
						<?php /* Runda 10: the ZIP needs a short-lived token now — the id alone is
						         no longer a download. The click fetches one and follows it. */ ?>
						<a href="#" data-collection-download class="btn btn-primary btn-download">
							<i class="fa-solid fa-file-zipper"></i>
							<?= _h('collection.download_all') ?>
						</a>
					</div>

					<div class="coll-files">
						<?php foreach ($collection['files'] as $f): ?>
							<?php $isMedia = strpos($f['mime_type'], 'image/') === 0 || strpos($f['mime_type'], 'video/') === 0; ?>
							<a class="coll-file" href="<?= htmlspecialchars(
								$appUrl . '/download.php?id=' . rawurlencode((string) $f['id']),
								ENT_QUOTES | ENT_SUBSTITUTE,
								'UTF-8'
							) ?>" target="_blank" rel="noopener noreferrer">
								<span class="ci"><?php if ($isMedia): ?><img class="cthumb"
										src="<?= $appUrl ?>/api/thumb?id=<?= htmlspecialchars($f['id']) ?>" alt="" loading="lazy"
										><?php endif; ?><?= fileIconHtml($f['original_name'], $f['mime_type']) ?></span>
								<span class="cn" title="<?= htmlspecialchars($f['original_name']) ?>"><?= htmlspecialchars($f['original_name']) ?></span>
								<span class="cs"><?= formatSize((int) $f['size']) ?></span>
							</a>
						<?php endforeach; ?>
					</div>
					<?php if ($collectionPages > 1): ?>
						<nav class="coll-pagination" aria-label="<?= _h('collection.pagination') ?>">
							<?php if ($collectionPage > 1): ?>
								<a href="?id=<?= rawurlencode($collectionId) ?>&amp;page=<?= $collectionPage - 1 ?>" rel="prev" aria-label="<?= _h('collection.previous_page') ?>">‹</a>
							<?php endif; ?>
							<?php for ($page = 1; $page <= $collectionPages; $page++): ?>
								<?php if ($page === $collectionPage): ?>
									<span class="active" aria-current="page"><?= $page ?></span>
								<?php else: ?>
									<a href="?id=<?= rawurlencode($collectionId) ?>&amp;page=<?= $page ?>"><?= $page ?></a>
								<?php endif; ?>
							<?php endfor; ?>
							<?php if ($collectionPage < $collectionPages): ?>
								<a href="?id=<?= rawurlencode($collectionId) ?>&amp;page=<?= $collectionPage + 1 ?>" rel="next" aria-label="<?= _h('collection.next_page') ?>">›</a>
							<?php endif; ?>
						</nav>
					<?php endif; ?>
				<?php else: ?>
					<div class="coll-empty"><?= _h('collection.empty') ?></div>
				<?php endif; ?>
			</div>

			<?= AdRenderer::zone('collection_bottom') ?>
		<?php endif; ?>
		<?php require __DIR__ . '/../src/includes/site_footer.php'; ?>
	</div>
	<?php /* The toast template ui.js clones for errors and notices (same markup as download.php). */ ?>
	<div class="toast" id="dlToast"><span id="dlToastText"></span></div>
	<?php if ($collection): ?>
		<div class="modal-bg" id="collMembersModal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="collMembersTitle">
			<div class="modal">
				<div class="modal-header">
					<h3 id="collMembersTitle"><i class="fa-solid fa-lock"></i> <?= _h('collection.member_passwords_title') ?></h3>
					<button type="button" class="btn-icon modal-close" data-member-password-close aria-label="<?= _h('common.close') ?>"><i class="fa-solid fa-xmark"></i></button>
				</div>
				<div class="modal-body">
					<p><?= _h('collection.member_passwords_intro') ?></p>
					<form id="collMembersForm">
						<div class="coll-member-list" id="collMemberPasswordList"></div>
						<div class="modal-btns">
							<button type="button" class="btn" data-member-password-close><?= _h('common.cancel') ?></button>
							<button type="submit" class="btn btn-primary"><i class="fa-solid fa-file-zipper"></i> <?= _h('collection.download_available') ?></button>
						</div>
					</form>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<?php if ($collection): ?>
		<?php
		$collectionBootstrap = json_encode([
			'collectionId' => $collectionId,
			'zipUrl' => $appUrl . '/api/collection?id=' . urlencode($collectionId),
			'connectionError' => __('common.connection_error'),
			'skippedNotice' => __('collection.skipped_protected'),
		], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		if ($collectionBootstrap === false) {
			$collectionBootstrap = '{}';
		}
		?>
		<div id="collectionBootstrap" hidden
			data-config="<?= htmlspecialchars(
				$collectionBootstrap,
				ENT_QUOTES | ENT_SUBSTITUTE,
				'UTF-8'
			) ?>"></div>
		<script src="<?= $appUrl ?>/assets/js/collection.js?v=<?= APP_VERSION ?>"></script>
	<?php endif; ?>

	<?php require_once __DIR__ . '/../src/includes/auth_modal.php'; ?>
	<?php require_once __DIR__ . '/../src/includes/auth_scripts.php'; ?>
	<?= AdRenderer::scripts() ?>
</body>

</html>
