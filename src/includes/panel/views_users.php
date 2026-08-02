<?php
/** User administration panel view. Runs in views.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
	<div class="filters">
		<input type="text" class="search" id="userSearch" placeholder="<?= _h('panel.users.search_ph') ?>">
		<button class="btn" data-fh-click="loadUsers()"><i class="fa-solid fa-arrows-rotate"></i> <?= _h('common.refresh') ?></button>
		<button class="btn" data-fh-click="showCreateUserModal()"><i class="fa-solid fa-user-plus"></i> <?= _h('panel.users.add') ?></button>
		<a class="btn" href="?tab=settings&stab=groups"><i class="fa-solid fa-users"></i> <?= _h('panel.users.groups') ?></a>
		<button class="btn" data-fh-click="loadIPBans()"><i class="fa-solid fa-ban"></i> <?= _h('panel.users.ip_bans') ?></button>
	</div>

	<div class="table-wrap">
		<table class="users-table">
			<thead>
				<tr>
					<th class="col-primary" data-sort="username" data-fh-click="sortUsers('username', event)"><?= _h('panel.users.th_user') ?><span class="sort-icon"></span></th>
					<th class="col-text col-email" data-sort="email" data-fh-click="sortUsers('email', event)"><?= _h('panel.users.th_email') ?><span class="sort-icon"></span></th>
					<th data-sort="role" data-fh-click="sortUsers('role', event)"><?= _h('panel.users.th_role') ?><span class="sort-icon"></span></th>
					<th><?= _h('panel.users.th_group') ?></th>
					<th data-sort="is_active" data-fh-click="sortUsers('is_active', event)"><?= _h('panel.users.th_status') ?><span class="sort-icon"></span></th>
					<th data-sort="files_count" data-fh-click="sortUsers('files_count', event)"><?= _h('panel.users.th_files') ?><span class="sort-icon"></span></th>
					<th data-sort="storage_used" data-fh-click="sortUsers('storage_used', event)"><?= _h('panel.users.th_storage') ?><span class="sort-icon"></span></th>
					<th class="col-date" data-sort="created_at" data-fh-click="sortUsers('created_at', event)"><?= _h('panel.users.th_created') ?><span class="sort-icon"></span></th>
					<th class="col-actions"><?= _h('common.actions') ?></th>
				</tr>
			</thead>
			<tbody id="usersBody"><tr><td colspan="9" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
		</table>
		<div class="pagination" id="userPagination"></div>
	</div>
