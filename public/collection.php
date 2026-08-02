<?php

define('FILEHOST_CSP_STRICT_SCRIPTS', true);
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/includes/Database.php';
require_once __DIR__ . '/../src/includes/file_icons.php';
require_once __DIR__ . '/../src/includes/AdRenderer.php';

$appUrl = APP_URL;
$collectionId = $_GET['id'] ?? '';
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
	<title><?= $collection ? htmlspecialchars($collection['name']) : _h('common.error') ?> - <?= APP_NAME ?></title>
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/download.css?v=<?= APP_VERSION ?>">
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/notifications.css?v=<?= APP_VERSION ?>">
	<?php require __DIR__ . '/../src/includes/site_footer_assets.php'; ?>
	<style>
		.coll-download-wrap { display: flex; justify-content: center; padding: 22px 20px 6px; }
		.coll-download-wrap .btn-download { min-width: 260px; justify-content: center; font-size: 1.02rem; padding: 14px 28px; }
		.coll-files { padding: 14px 20px 22px; display: flex; flex-direction: column; gap: 8px; }
		.coll-file { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 12px;
			background: var(--bg-secondary); border: 1px solid var(--border); color: var(--text);
			text-decoration: none; transition: border-color .15s, transform .15s; }
		.coll-file:hover { border-color: var(--accent); transform: translateY(-1px); }
		.coll-file:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
		.coll-file .ci { position: relative; width: 42px; height: 42px; flex-shrink: 0; border-radius: 9px;
			overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;
			line-height: 1; background: var(--bg-card); color: var(--accent); border: 1px solid var(--border); }
		.coll-file .cthumb { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
		.coll-file .cn { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; }
		.coll-file .cs { color: var(--text-muted); font-size: 0.85rem; white-space: nowrap; }
		.coll-empty { color: var(--text-muted); text-align: center; padding: 28px 18px; }
		.coll-count-badge { font-size: 0.82rem; color: var(--text-muted); padding: 0 20px 4px; }
		.coll-pagination { display: flex; align-items: center; justify-content: center; flex-wrap: wrap;
			gap: 6px; padding: 0 20px 22px; }
		.coll-pagination a, .coll-pagination span { min-width: 36px; height: 36px; display: inline-flex;
			align-items: center; justify-content: center; padding: 0 10px; border: 1px solid var(--border);
			border-radius: 9px; color: var(--text-secondary); text-decoration: none; background: var(--bg-secondary); }
		.coll-pagination a:hover, .coll-pagination a:focus-visible { border-color: var(--accent); color: var(--accent); }
		.coll-pagination .active { border-color: var(--accent); color: #fff; background: var(--accent); }
		/* C2: password gate for a protected collection */
		.coll-lock { text-align: center; padding: 26px 20px 24px; }
		.coll-lock-icon { font-size: 2.1rem; color: var(--accent); margin-bottom: 10px; }
		.coll-lock p { color: var(--text-secondary); margin-bottom: 16px; }
		.coll-lock form { display: flex; flex-direction: column; align-items: center; gap: 12px; }
		.coll-lock input[type="password"] { width: 100%; max-width: 320px; padding: 11px 14px; border-radius: 10px;
			background: var(--bg-secondary); border: 1px solid var(--border); color: var(--text); font-size: 0.95rem; }
		.coll-lock input[type="password"]:focus { outline: none; border-color: var(--accent); }
		.coll-pw-error { color: var(--danger); font-size: 0.87rem; }
		.coll-member-list { display: grid; gap: 12px; margin-top: 14px; max-height: 45vh; overflow-y: auto; }
		.coll-member-row { display: grid; gap: 7px; }
		.coll-member-row label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-secondary); }
		.coll-member-row input { width: 100%; padding: 11px 14px; border-radius: 10px; background: var(--bg-secondary);
			border: 1px solid var(--border); color: var(--text); font-size: .95rem; }
		.coll-member-row input:focus { outline: none; border-color: var(--accent); }
		#collMembersModal { position: fixed; inset: 0; z-index: 10020; display: none; align-items: flex-start;
			justify-content: center; overflow-y: auto; padding: 6vh 16px 24px; background: rgba(0,0,0,.72); backdrop-filter: blur(5px); }
		#collMembersModal.show { display: flex; }
		#collMembersModal .modal { width: min(560px, 100%); max-height: calc(94vh - 24px); overflow: hidden;
			border: 1px solid var(--border); border-radius: 16px; background: var(--bg-card); box-shadow: 0 24px 70px rgba(0,0,0,.42); }
		#collMembersModal .modal-header { display: flex; align-items: center; justify-content: space-between; gap: 16px;
			padding: 18px 20px; border-bottom: 1px solid var(--border); }
		#collMembersModal .modal-header h3 { margin: 0; font-size: 1.05rem; }
		#collMembersModal .modal-body { padding: 20px; overflow-y: auto; }
		#collMembersModal .modal-body > p { color: var(--text-secondary); margin: 0; }
		#collMembersModal .modal-btns { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
		#collMembersModal .modal-close { flex: 0 0 auto; }
		@media (max-width: 600px) {
			#collMembersModal { align-items: center; padding: 16px; }
			#collMembersModal .modal { max-height: calc(100dvh - 32px); }
		}
	</style>
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
					<div class="file-name"><i class="fa-solid fa-box-archive" style="color:var(--accent);margin-right:8px;"></i><?= htmlspecialchars($collection['name']) ?></div>
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
		<?php endif; ?>
		<?php require __DIR__ . '/../src/includes/site_footer.php'; ?>
	</div>
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
	<?php if ($collection && $needsPassword): ?>
		<?= AdRenderer::zone('collection_bottom') ?>
	<?php endif; ?>

	<?php require_once __DIR__ . '/../src/includes/auth_modal.php'; ?>
	<?php require_once __DIR__ . '/../src/includes/auth_scripts.php'; ?>
	<?= AdRenderer::scripts() ?>
</body>

</html>
