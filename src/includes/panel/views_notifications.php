<?php
/** Notifications panel view. Runs in views.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
	<?php /* Two halves of one subject: what has been said, and what may be said. Keeping the
	         preferences on this tab rather than in Account means the row you just muted is
	         directly under the notification that made you want to. */ ?>
	<div class="section-head">
		<h3 style="margin:0;"><i class="fa-solid fa-bell"></i> <?= _h('notif.history') ?>
			<span class="badge badge-muted" id="notifUnreadBadge" style="display:none;">0</span>
		</h3>
		<div class="section-head-actions">
			<div class="range-picker" id="notifFilter">
				<button type="button" class="range-btn active" data-filter="all" data-notification-action="filter"><?= _h('notif.filter_all') ?></button>
				<button type="button" class="range-btn" data-filter="unread" data-notification-action="filter"><?= _h('notif.filter_unread') ?></button>
			</div>
			<button class="btn" data-notification-action="readAll"><i class="fa-solid fa-check-double"></i> <?= _h('notif.read_all') ?></button>
			<button class="btn btn-danger" data-notification-action="clear"><i class="fa-solid fa-trash"></i> <?= _h('notif.clear') ?></button>
		</div>
	</div>

	<div class="notif-page-list" id="notifPageList">
		<div class="notif-empty"><?= _h('common.loading') ?></div>
	</div>
	<div class="pagination" id="notifPagination"></div>

	<div class="settings-section" style="margin-top:28px;">
		<h3><i class="fa-solid fa-sliders"></i> <?= _h('notif.prefs_title') ?></h3>
		<p style="color:var(--text-muted); margin:-6px 0 14px;"><?= _h('notif.prefs_hint') ?></p>
		<div class="table-wrap">
			<table class="notif-prefs">
				<thead>
					<tr>
						<th><?= _h('notif.th_type') ?></th>
						<th><?= _h('notif.th_app') ?></th>
						<th><?= _h('notif.th_mail') ?></th>
					</tr>
				</thead>
				<tbody id="notifPrefsBody"><tr><td colspan="3" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
			</table>
		</div>
		<div style="margin-top:14px; display:flex; justify-content:center;">
			<button class="btn btn-primary" style="padding: 12px 28px;" data-notification-action="savePrefs"><i class="fa-solid fa-floppy-disk"></i> <?= _h('common.save') ?></button>
		</div>
	</div>

