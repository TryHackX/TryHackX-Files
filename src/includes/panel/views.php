<?php
/** Panel tab views. Runs in panel.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
$csrf = htmlspecialchars(csrfToken(), ENT_QUOTES);
?>
<div class="tabs">
	<?php if ($isAdmin): ?>
		<a href="?tab=dashboard" class="tab <?= $tab === 'dashboard' ? 'active' : '' ?>"><i class="fa-solid fa-gauge-high"></i> <?= _h('panel.nav.dashboard') ?></a>
	<?php endif; ?>
	<?php if ($canBrowseAll): ?>
		<a href="?tab=files" class="tab <?= $tab === 'files' ? 'active' : '' ?>"><i class="fa-solid fa-folder"></i> <?= _h('panel.nav.files') ?></a>
	<?php endif; ?>
	<?php if ($isAdmin): ?>
		<a href="?tab=users" class="tab <?= $tab === 'users' ? 'active' : '' ?>"><i class="fa-solid fa-users"></i> <?= _h('panel.nav.users') ?></a>
	<?php endif; ?>
	<?php /* Zgłoszenia, Śledzenie i Audyt są trzema widokami jednej pracy — nadzoru — więc
	         dzielą jedną zakładkę i mają własne podmenu, jak Ustawienia. Główny pasek zsuwał
	         się przez nie do drugiej linii. */ ?>
	<?php /* Also for non-mod groups holding an ads.* manager permission — their slice of
	         Moderacja is the ads manager (Faza 8 runda 2). */ ?>
	<?php if ($canModerationPanel): ?>
		<a href="?tab=moderate" class="tab <?= $tab === 'moderate' ? 'active' : '' ?>"><i class="fa-solid fa-shield-halved"></i> <?= _h('panel.nav.moderate') ?></a>
	<?php endif; ?>
	<?php if ($isAdmin): ?>
		<a href="?tab=settings" class="tab <?= $tab === 'settings' ? 'active' : '' ?>"><i class="fa-solid fa-gear"></i> <?= _h('panel.nav.settings') ?></a>
	<?php endif; ?>
	<a href="?tab=myfiles" class="tab <?= $tab === 'myfiles' ? 'active' : '' ?>"><i class="fa-solid fa-folder-open"></i> <?= _h('panel.nav.myfiles') ?></a>
	<?php /* pt 6 / Faza 8 runda 3: the user's Premium tab. The admin's half (sales,
	         subscribers) lives under Moderacja → Premium now, so the top bar stays one
	         line; a regular user has the menu room and "Mój plan" is theirs, not staff work.
	         Hidden entirely when premium is switched off. */ ?>
	<?php if (!$isAdmin && PremiumController::isEnabled()): ?>
		<a href="?tab=premium" class="tab <?= $tab === 'premium' ? 'active' : '' ?>"><i class="fa-solid fa-gem"></i> <?= _h('premium.nav') ?></a>
	<?php endif; ?>
	<?php /* Faza 8: buying a placement is a user activity gated by `ads.buy`; the admin
	         manages ads under Moderacja instead. */ ?>
	<?php if (!$isAdmin && AdsController::sellingEnabled() && Permissions::has('ads.buy')): ?>
		<a href="?tab=myads" class="tab <?= $tab === 'myads' ? 'active' : '' ?>"><i class="fa-solid fa-rectangle-ad"></i> <?= _h('panel.myads.nav') ?></a>
	<?php endif; ?>
	<?php /* Everyone's, always: an account with nothing in it still needs somewhere to turn
	         things off, and the bell's "zobacz wszystkie" has to land somewhere. */ ?>
	<a href="?tab=notifications" class="tab <?= $tab === 'notifications' ? 'active' : '' ?>"><i class="fa-solid fa-bell"></i> <?= _h('notif.title') ?></a>
	<a href="?tab=user" class="tab <?= $tab === 'user' ? 'active' : '' ?>"><i class="fa-solid fa-user"></i> <?= _h('panel.nav.account') ?></a>
</div>

<?php
if ($tab === 'dashboard' && $isAdmin) {
	require __DIR__ . '/views_dashboard.php';
} elseif ($tab === 'files' && $canBrowseAll) {
	require __DIR__ . '/views_files.php';
} elseif ($tab === 'users' && $isAdmin) {
	require __DIR__ . '/views_users.php';
} elseif ($tab === 'moderate' && $canModerationPanel) {
	require __DIR__ . '/views_moderate.php';
} elseif ($tab === 'myfiles') {
	require __DIR__ . '/views_myfiles.php';
} elseif ($tab === 'settings' && $isAdmin) {
	require __DIR__ . '/views_settings.php';
} elseif ($tab === 'premium') {
	require __DIR__ . '/views_premium.php';
} elseif ($tab === 'myads') {
	require __DIR__ . '/views_myads.php';
} elseif ($tab === 'notifications') {
	require __DIR__ . '/views_notifications.php';
} elseif ($tab === 'user') {
	require __DIR__ . '/views_account.php';
}
?>
