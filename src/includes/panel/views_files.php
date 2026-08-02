<?php
/** All-files browser panel view. Runs in views.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
	<?php
	// The all-files browser is no longer admin-only: a group can be granted `files.view_all`
	// plus the individual capabilities around it. Everything below is gated on what this
	// session actually holds — the API enforces the same rules, this only avoids offering
	// controls that would be refused.
	$pCanSearch  = Permissions::has('files.search_all');
	$pCanSort    = Permissions::has('files.sort_all');
	$pCanCollect = Permissions::has('files.collection_all');
	$pSeeOwner   = Permissions::has('files.see_owner');
	$pSeeIp      = Permissions::has('files.see_ip');
	$pFilters    = Permissions::has('files.advanced_filters');
	$colspan     = 5 + ($pSeeOwner ? 1 : 0) + ($pSeeIp ? 1 : 0) + ($isAdmin ? 1 : 0);
	$sortAttr    = fn(string $col) => $pCanSort ? ' data-sort="' . $col . '" data-fh-click="sortBy(\'' . $col . '\', event)"' : '';
	$sortIcon    = $pCanSort ? '<span class="sort-icon"></span>' : '';
	?>
	<?php if ($isAdmin): ?>
		<div class="stats">
			<div class="stat"><h3><?= $stats['total_files'] ?? 0 ?></h3><p><?= _h('panel.dash.files') ?></p></div>
			<div class="stat"><h3><?= formatSize($stats['total_size'] ?? 0) ?></h3><p><?= _h('panel.files.total_size') ?></p></div>
			<div class="stat"><h3><?= $stats['total_downloads'] ?? 0 ?></h3><p><?= _h('common.downloads') ?></p></div>
		</div>
	<?php endif; ?>

	<div class="filters">
		<?php /* One search box for the tab, whichever scope the filter panel is on: the second
		         input that used to sit above the collections table left the visible one dead the
		         moment "Kolekcje" was picked. The placeholder follows the scope (see
		         applyScopeToLayout), and so does what Odśwież reloads. */ ?>
		<?php if ($pCanSearch): ?>
			<input type="text" class="search" id="search" placeholder="<?= _h('panel.files.search_ph') ?>">
		<?php endif; ?>
		<button class="btn" data-fh-click="reloadScopedList()"><i class="fa-solid fa-arrows-rotate"></i> <?= _h('common.refresh') ?></button>
		<?php if ($pFilters): ?>
			<button type="button" class="btn" id="filtersBtn" data-fh-click="openFiltersModal()">
				<i class="fa-solid fa-filter"></i> <?= _h('panel.flt.button') ?>
				<span class="filter-count" id="filterCount" style="display:none;">0</span>
			</button>
			<button type="button" class="btn btn-clear-filters" id="clearFiltersBtn" data-fh-click="clearAllFilters()"
				title="<?= _h('panel.flt.clear_all') ?>" style="display:none;">
				<i class="fa-solid fa-xmark"></i>
			</button>
		<?php endif; ?>
		<?php /* pt 6: shown when *anything* expires by age — the installation default or a
		         single group's own retention. */ ?>
		<?php if ($isAdmin && FileManager::autoDeleteConfigured()): ?>
			<button type="button" class="btn" data-scope-only="files" data-fh-click="confirmCleanup()"><i class="fa-solid fa-trash"></i> <?= _h('panel.files.cleanup') ?></button>
		<?php endif; ?>
		<?php /* The selection actions live in the toolbar and slide out of its right edge, the
		         same gesture "My files" uses — one mechanism for both lists instead of a bar
		         that pushed the table down the moment a row was ticked. Collecting still needs
		         two files, deleting appears at one. */ ?>
		<?php if ($pCanCollect): ?>
			<button class="btn btn-primary collect-slide" id="bulkCollectionBtn" data-fh-click="openCollectionFromAll()"
				tabindex="-1" aria-hidden="true">
				<i class="fa-solid fa-box-archive"></i> <span class="collect-slide-label"><?= _h('panel.my.create_collection') ?></span>
				<span class="collect-count" id="bulkCollCount">0</span>
			</button>
		<?php endif; ?>
		<?php if ($isAdmin): ?>
			<button class="btn btn-danger collect-slide" id="bulkDeleteBtn" data-fh-click="bulkDeleteFiles()"
				tabindex="-1" aria-hidden="true">
				<i class="fa-solid fa-trash"></i> <span class="collect-slide-label"><?= _h('panel.files.delete_selected') ?></span>
				<span class="collect-count" id="bulkCount">0</span>
			</button>
		<?php endif; ?>
	</div>

	<?php if ($pFilters): ?>
		<div class="active-filters" id="activeFilters" style="display:none;"></div>
	<?php endif; ?>

	<!-- pt 4: the two lists are alternatives, not neighbours — the filter panel's scope picks
	     which one is on screen, so filtering collections does not leave a full, unrelated file
	     table sitting above the result. -->
	<div id="filesBlock">
	<div class="table-wrap">
		<table class="files-table">
			<thead>
				<tr>
					<?php if ($isAdmin || $pCanCollect): ?>
						<th class="col-select"><input type="checkbox" class="file-check" id="selectAllFiles" title="<?= _h('panel.files.select_all') ?>"></th>
					<?php endif; ?>
					<th class="col-primary"<?= $sortAttr('name') ?>><?= _h('panel.dash.files') ?><?= $sortIcon ?></th>
					<?php if ($pSeeOwner): ?>
						<th class="col-text"<?= $sortAttr('owner') ?>><?= _h('panel.users.th_user') ?><?= $sortIcon ?></th>
					<?php endif; ?>
					<th<?= $sortAttr('size') ?>><?= _h('common.size') ?><?= $sortIcon ?></th>
					<th class="col-date"<?= $sortAttr('uploadedAt') ?>><?= _h('common.date') ?><?= $sortIcon ?></th>
					<?php if ($pSeeIp): ?>
						<th<?= $sortAttr('ip') ?>>IP<?= $sortIcon ?></th>
					<?php endif; ?>
					<th class="col-downloads"<?= $sortAttr('downloads') ?>><?= _h('common.downloads') ?><?= $sortIcon ?></th>
					<th class="col-actions"><?= _h('common.actions') ?></th>
				</tr>
			</thead>
			<tbody id="filesBody"><tr><td colspan="<?= $colspan ?>" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
		</table>
		<div class="pagination" id="filesPagination"></div>
	</div>
	</div>

	<?php if (Permissions::has('collections.view_all')): ?>
		<?php $pCollDelete = Permissions::has('collections.delete_all'); ?>
		<div id="collectionsBlock" style="display:none;">
			<div class="section-head" style="margin-top:28px;">
				<h3 style="margin:0;"><i class="fa-solid fa-box-archive"></i> <?= _h('panel.coll.all_title') ?></h3>
				<?php /* The action belongs to this list, so it lives on this list's heading. In
				         the tab toolbar it sat above the *file* table, which in the "wszystko"
				         scope is a different table entirely. */ ?>
				<?php /* Refresh first, the slide-out after it: the button grows out of the right
				         edge of the row, exactly as it does in the file toolbar. Put before
				         Refresh it appeared to push that button sideways instead. */ ?>
				<div class="section-head-actions">
					<button class="btn" data-fh-click="loadAdminCollections(1)"><i class="fa-solid fa-arrows-rotate"></i> <?= _h('common.refresh') ?></button>
					<?php if ($pCollDelete): ?>
						<button class="btn btn-danger collect-slide" id="collBulkDeleteBtn" data-fh-click="bulkDeleteCollections()"
							tabindex="-1" aria-hidden="true">
							<i class="fa-solid fa-trash"></i> <span class="collect-slide-label"><?= _h('panel.coll.delete_selected') ?></span>
							<span class="collect-count" id="collBulkCount">0</span>
						</button>
					<?php endif; ?>
				</div>
			</div>
			<div class="table-wrap">
				<table class="collections-table">
					<thead>
						<tr>
							<?php if ($pCollDelete): ?>
								<th class="col-select"><input type="checkbox" class="file-check" id="selectAllCollections"
									data-fh-change="toggleSelectAllCollections(this)" title="<?= _h('panel.files.select_all') ?>"></th>
							<?php endif; ?>
							<th class="col-primary"><?= _h('panel.coll.th_name') ?></th>
							<th class="col-text"><?= _h('panel.users.th_user') ?></th>
							<th><?= _h('panel.coll.th_files') ?></th>
							<th><?= _h('common.size') ?></th>
							<th class="col-downloads"><?= _h('common.downloads') ?></th>
							<th class="col-actions"><?= _h('common.actions') ?></th>
						</tr>
					</thead>
					<tbody id="adminCollectionsBody"><tr><td colspan="7" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
				</table>
				<div class="pagination" id="adminCollectionsPagination"></div>
			</div>
		</div>
	<?php endif; ?>
