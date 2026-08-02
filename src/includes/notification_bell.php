<?php
/**
 * The notification bell and its popover.
 *
 * Rendered inside the header on every page that has one, between the premium link and the theme
 * toggle. Markup only — behaviour lives in assets/js/notifications.js, which every page that
 * includes this must also load.
 *
 * Only shown to a signed-in account: a notification belongs to someone, and there is nothing to
 * poll for a visitor who is nobody.
 */
if (!isset($_SESSION['user_id'])) {
	return;
}
$__nbUrl = defined('APP_URL') ? APP_URL : '';
?>
<div class="notif-wrap" id="notifWrap">
	<button class="btn-icon notif-btn" id="notifBtn" type="button" aria-haspopup="dialog" aria-expanded="false"
		title="<?= _h('notif.title') ?>">
		<i class="fa-solid fa-bell"></i>
		<?php /* Hidden until the first count arrives, so it cannot flash a "0" on every page load. */ ?>
		<span class="notif-badge" id="notifBadge" hidden>0</span>
	</button>

	<div class="notif-pop" id="notifPop" role="dialog" aria-label="<?= _h('notif.title') ?>" hidden>
		<div class="notif-pop-head">
			<strong><?= _h('notif.title') ?></strong>
			<button type="button" class="notif-readall" id="notifReadAll"><?= _h('notif.read_all') ?></button>
		</div>
		<div class="notif-list" id="notifList">
			<div class="notif-empty"><?= _h('common.loading') ?></div>
		</div>
		<a class="notif-pop-foot" href="<?= $__nbUrl ?>/panel.php?tab=notifications">
			<?= _h('notif.see_all') ?> <i class="fa-solid fa-arrow-right"></i>
		</a>
	</div>
</div>
