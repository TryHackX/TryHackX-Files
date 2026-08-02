<?php
/**
 * Result page for the ShareX delete link (FileController::handleDeleteLink).
 *
 * ShareX opens `DeletionURL` in a browser, so that endpoint has to answer with HTML rather
 * than JSON. Deliberately self-contained (inline CSS, no app assets): it is reached straight
 * from an external client, often long after the upload, and should render even if nothing
 * else about the session is intact.
 *
 * Expects, from the caller's scope: $title, $heading, $message, $icon, $state,
 * and $deleteForm when $state === 'ask'.
 */
if (!defined('APP_ROOT')) {
	exit;
}
$accent = ['ok' => '#22c55e', 'ask' => '#f59e0b', 'bad' => '#ef4444'][$state] ?? '#6366f1';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Lang::current()) ?>">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title><?= htmlspecialchars($title) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
	<link rel="stylesheet" href="<?= htmlspecialchars(APP_URL) ?>/assets/fontawesome/css/all.min.css?v=<?= APP_VERSION ?>">
	<style>
		:root { color-scheme: dark; }
		* { box-sizing: border-box; }
		body {
			margin: 0;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 24px;
			background: #0f1117;
			color: #e2e8f0;
			font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
		}
		.card {
			max-width: 460px;
			width: 100%;
			padding: 34px 30px;
			text-align: center;
			background: #171a23;
			border: 1px solid #242836;
			border-radius: 16px;
		}
		.icon { font-size: 2.6rem; color: <?= $accent ?>; margin-bottom: 16px; }
		h1 { margin: 0 0 10px; font-size: 1.28rem; }
		p { margin: 0 0 22px; color: #94a3b8; line-height: 1.6; font-size: 0.95rem; word-break: break-word; }
		.btn {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			padding: 11px 22px;
			border: 1px solid #2c3142;
			border-radius: 9px;
			background: #1e2230;
			color: #e2e8f0;
			text-decoration: none;
			font-size: 0.92rem;
			font-family: inherit;
			cursor: pointer;
			transition: background .15s, border-color .15s;
		}
		.btn:hover { background: #242938; border-color: #3a4055; }
		.btn-danger { background: #ef4444; border-color: #ef4444; color: #fff; }
		.btn-danger:hover { background: #dc2626; border-color: #dc2626; }
		.actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
	</style>
</head>

<body>
	<div class="card">
		<div class="icon"><i class="fa-solid <?= htmlspecialchars($icon) ?>"></i></div>
		<h1><?= htmlspecialchars($heading) ?></h1>
		<p><?= htmlspecialchars($message) ?></p>
		<div class="actions">
			<?php if ($state === 'ask' && !empty($deleteForm)): ?>
				<form method="post" action="<?= htmlspecialchars((string) $deleteForm['action'], ENT_QUOTES) ?>">
					<input type="hidden" name="id" value="<?= htmlspecialchars((string) $deleteForm['id'], ENT_QUOTES) ?>">
					<input type="hidden" name="token" value="<?= htmlspecialchars((string) $deleteForm['token'], ENT_QUOTES) ?>">
					<input type="hidden" name="nonce" value="<?= htmlspecialchars((string) $deleteForm['nonce'], ENT_QUOTES) ?>">
					<button class="btn btn-danger" type="submit">
						<i class="fa-solid fa-trash"></i> <?= htmlspecialchars(__('common.delete')) ?>
					</button>
				</form>
			<?php endif; ?>
			<a class="btn" href="<?= htmlspecialchars(APP_URL) ?>/">
				<i class="fa-solid fa-house"></i> <?= htmlspecialchars(__('common.back_home')) ?>
			</a>
		</div>
	</div>
</body>

</html>
