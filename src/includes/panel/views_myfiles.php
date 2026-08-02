<?php
/** Account files and collections panel view. Runs in views.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
	<?php
	// pkt B: the account may be over its limits because the group shrank under it. Checking on
	// render (and letting enforce() run the clock) is what makes the warning appear promptly
	// even on an install with no cron — the sweep in scripts/cleanup.php does the rest.
	$limitState = StorageEnforcer::enforce((int) $currentUser['id']);
	$limitStatus = StorageEnforcer::status((int) $currentUser['id']);
	if ($limitStatus['over']):
		?>
		<div class="limit-warning">
			<div class="limit-warning-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
			<div>
				<strong><?= _h('panel.my.over_limit_title') ?></strong>
				<ul>
					<?php if ($limitStatus['overBy'] > 0): ?>
						<li><?= __('panel.my.over_quota', [
							'used' => formatSize($limitStatus['used']),
							'quota' => formatSize($limitStatus['quota']),
							'over' => formatSize($limitStatus['overBy']),
						]) ?></li>
					<?php endif; ?>
					<?php if ($limitStatus['oversize']): ?>
						<li><?= __('panel.my.over_filesize', [
							'n' => count($limitStatus['oversize']),
							'max' => formatSize($limitStatus['maxFile']),
						]) ?></li>
					<?php endif; ?>
				</ul>
				<p>
					<?php if (!StorageEnforcer::enabled()): ?>
						<?= _h('panel.my.over_limit_off') ?>
					<?php elseif ($limitStatus['deadline']): ?>
						<?= __('panel.my.over_limit_deadline', ['date' => date('d.m.Y', $limitStatus['deadline'])]) ?>
					<?php else: ?>
						<?= _h('panel.my.over_limit_soon') ?>
					<?php endif; ?>
				</p>
			</div>
		</div>
	<?php endif; ?>

	<div class="stats">
		<div class="stat"><h3 id="myTotalFiles"><?= (int) $userStats['files_count'] ?></h3><p><?= _h('panel.my.total_files') ?></p></div>
		<div class="stat"><h3 id="myTotalSize"><?= formatSize((int) $userStats['total_size']) ?></h3><p><?= _h('panel.my.total_size') ?></p></div>
		<div class="stat"><h3 id="myTotalDownloads"><?= (int) $userStats['total_downloads'] ?></h3><p><?= _h('panel.my.total_downloads') ?></p></div>
	</div>

	<?php
	/**
	 * pt 7: how full the account is.
	 *
	 * "Zajęte miejsce" above is a number with nothing to compare it to — 542 MiB is either
	 * nothing or almost everything depending on a quota the tile never mentions. The bar is
	 * that comparison, and it is the same figure the enforcement uses (quota from the group,
	 * or the per-account override), so what is shown is what will actually be enforced.
	 *
	 * An account with no quota gets no bar: a progress bar against infinity is a decoration.
	 */
	$quotaBytes = (int) $limitStatus['quota'];
	$usedBytes = (int) $limitStatus['used'];
	if ($quotaBytes > 0):
		$pct = min(100, ($usedBytes / $quotaBytes) * 100);
		$level = $pct >= 100 ? 'is-full' : ($pct >= 85 ? 'is-high' : ($pct >= 60 ? 'is-warm' : ''));
		?>
		<div class="quota-bar <?= $level ?>">
			<div class="quota-bar-head">
				<span class="quota-bar-label"><i class="fa-solid fa-hard-drive"></i> <?= _h('panel.my.quota_title') ?></span>
				<span class="quota-bar-value">
					<strong><?= formatSize($usedBytes) ?></strong>
					<span class="quota-bar-of">/ <?= formatSize($quotaBytes) ?></span>
					<span class="quota-bar-pct"><?= number_format($pct, $pct < 10 ? 1 : 0, ',', ' ') ?>%</span>
				</span>
			</div>
			<div class="quota-bar-track" role="progressbar" aria-valuenow="<?= (int) round($pct) ?>"
				aria-valuemin="0" aria-valuemax="100"
				aria-label="<?= _h('panel.my.quota_title') ?>">
				<i style="width: <?= number_format($pct, 2, '.', '') ?>%"></i>
			</div>
			<small class="quota-bar-foot">
				<?php if ($usedBytes >= $quotaBytes): ?>
					<?= _h('panel.my.quota_full') ?>
				<?php else: ?>
					<?= __('panel.my.quota_left', ['left' => formatSize($quotaBytes - $usedBytes)]) ?>
				<?php endif; ?>
			</small>
		</div>
	<?php endif; ?>

	<?php
	// pt 4: the own-collections half of this tab is configurable per group now.
	$pMyFilters = Permissions::has('myfiles.filters');
	$pMyColl = Permissions::has('myfiles.collections');
	$pMyCollCreate = $pMyColl && Permissions::has('myfiles.coll_create');
	$pMyCollDelete = $pMyColl && Permissions::has('myfiles.coll_delete');
	?>
	<div class="filters">
		<input type="text" class="search" id="mySearch" placeholder="<?= _h('panel.my.search_ph') ?>">
		<button class="btn" data-fh-click="loadMyFiles()"><i class="fa-solid fa-arrows-rotate"></i> <?= _h('common.refresh') ?></button>
		<?php /* pt 8: the same filter panel the all-files list has, cut down to the criteria
		         that mean anything on one's own uploads, behind its own group permission. */ ?>
		<?php if ($pMyFilters): ?>
			<button type="button" class="btn" id="myFiltersBtn" data-fh-click="openMyFiltersModal()">
				<i class="fa-solid fa-filter"></i> <?= _h('panel.flt.button') ?>
				<span class="filter-count" id="myFilterCount" style="display:none;">0</span>
			</button>
			<button type="button" class="btn btn-clear-filters" id="myClearFiltersBtn" data-fh-click="clearAllMyFilters()"
				title="<?= _h('panel.flt.clear_all') ?>" style="display:none;">
				<i class="fa-solid fa-xmark"></i>
			</button>
		<?php endif; ?>
		<?php /* pt 7 (this round): the button is not there until there is something to press it
		         for. Below two selected files it collapses to nothing and the search box takes
		         the room back; at two it slides out from the right. The counter in the label is
		         then always a real number, which is why it no longer needs the muted state it
		         used to carry. */ ?>
		<?php if ($pMyCollCreate): ?>
			<button class="btn btn-primary collect-slide" id="createCollectionBtn" data-fh-click="openCreateCollection()"
				tabindex="-1" aria-hidden="true">
				<i class="fa-solid fa-box-archive"></i> <span class="collect-slide-label"><?= _h('panel.my.create_collection') ?></span>
				<span class="collect-count" id="collSelCount">0</span>
			</button>
		<?php endif; ?>
		<?php /* Deleting rides the same slide-out as collecting, and appears one file earlier:
		         removing a single upload is an ordinary thing to want, bundling one is not. */ ?>
		<button class="btn btn-danger collect-slide" id="deleteMyFilesBtn" data-fh-click="bulkDeleteMyFiles()"
			tabindex="-1" aria-hidden="true">
			<i class="fa-solid fa-trash"></i> <span class="collect-slide-label"><?= _h('panel.files.delete_selected') ?></span>
			<span class="collect-count" id="myDelSelCount">0</span>
		</button>
	</div>

	<?php if ($pMyFilters): ?>
		<div class="active-filters" id="myActiveFilters" style="display:none;"></div>
	<?php endif; ?>

	<?php /* pt 3: the two lists are wrapped so the filter panel's scope can show one, the other,
	         or both — "wszystko" being the state this tab is normally in. */ ?>
	<div id="myFilesBlock">
	<div class="table-wrap">
		<table class="my-files-table">
			<thead>
				<tr>
					<?php /* Unconditional: selection drives deleting as well as collecting, and
					         deleting one's own upload needs no permission. The rows always emitted
					         this cell, so a group without `coll_create` used to get a header one
					         column short of its body. */ ?>
					<th class="col-check col-select"><input type="checkbox" id="myFilesSelectAll" data-fh-click="toggleSelectAllMyFiles(this)" title="<?= _h('panel.my.select_all') ?>"></th>
					<th class="col-primary" data-sort="name" data-fh-click="sortMyFiles('name', event)"><?= _h('panel.dash.files') ?><span class="sort-icon"></span></th>
					<th data-sort="size" data-fh-click="sortMyFiles('size', event)"><?= _h('common.size') ?><span class="sort-icon"></span></th>
					<th class="col-date" data-sort="uploadedAt" data-fh-click="sortMyFiles('uploadedAt', event)"><?= _h('common.date') ?><span class="sort-icon"></span></th>
					<th class="col-downloads" data-sort="downloads" data-fh-click="sortMyFiles('downloads', event)"><?= _h('common.downloads') ?><span class="sort-icon"></span></th>
					<th class="col-actions"><?= _h('common.actions') ?></th>
				</tr>
			</thead>
			<tbody id="myFilesBody"><tr><td colspan="6" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
		</table>
		<div class="pagination" id="myFilesPagination"></div>
	</div>
	</div>

	<div id="myCollectionsBlock"<?= $pMyColl ? '' : ' style="display:none;"' ?>>
	<div class="section-head" style="margin-top:28px;">
		<h3 style="margin:0;"><i class="fa-solid fa-box-archive"></i> <?= _h('panel.coll.title') ?></h3>
		<?php /* Same slide-out as the file toolbar above, so the two lists in this tab are
		         operated the same way — including the direction it comes from: out of the right
		         edge, after Refresh. Only a group allowed to delete its own collections gets the
		         checkboxes at all, since there would be nothing to do with them. */ ?>
		<div class="section-head-actions">
			<button class="btn" data-fh-click="loadCollections()"><i class="fa-solid fa-arrows-rotate"></i> <?= _h('common.refresh') ?></button>
			<?php if ($pMyCollDelete): ?>
				<button class="btn btn-danger collect-slide" id="deleteMyCollectionsBtn" data-fh-click="bulkDeleteMyCollections()"
					tabindex="-1" aria-hidden="true">
					<i class="fa-solid fa-trash"></i> <span class="collect-slide-label"><?= _h('panel.files.delete_selected') ?></span>
					<span class="collect-count" id="myCollSelCount">0</span>
				</button>
			<?php endif; ?>
		</div>
	</div>
	<div class="table-wrap">
		<table class="collections-table">
			<thead>
				<tr>
					<?php if ($pMyCollDelete): ?>
						<th class="col-check col-select"><input type="checkbox" id="myCollectionsSelectAll" data-fh-click="toggleSelectAllMyCollections(this)" title="<?= _h('panel.my.select_all') ?>"></th>
					<?php endif; ?>
					<th class="col-primary"><?= _h('panel.coll.th_name') ?></th>
					<th><?= _h('panel.coll.th_files') ?></th>
					<th><?= _h('common.size') ?></th>
					<th class="col-downloads"><?= _h('common.downloads') ?></th>
					<th class="col-actions"><?= _h('common.actions') ?></th>
				</tr>
			</thead>
			<tbody id="collectionsBody"><tr><td colspan="<?= $pMyCollDelete ? 6 : 5 ?>" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
		</table>
		<div class="pagination" id="collectionsPagination"></div>
	</div>
	</div>

	<div class="section-head" style="margin-top:28px;">
		<h3 style="margin:0;"><i class="fa-solid fa-key"></i> <?= _h('panel.apikey.title') ?></h3>
		<button class="btn btn-primary" data-fh-click="openCreateApiKey()"><i class="fa-solid fa-plus"></i> <?= _h('panel.apikey.new') ?></button>
	</div>
	<p style="color:var(--text-muted); margin:-6px 0 12px;">
		<?= __('panel.apikey.intro') ?>
	</p>
	<div class="table-wrap">
		<table>
			<thead>
				<tr>
					<th><?= _h('panel.apikey.th_label') ?></th>
					<th><?= _h('panel.apikey.th_key') ?></th>
					<th><?= _h('panel.apikey.th_created') ?></th>
					<th><?= _h('panel.apikey.th_last_used') ?></th>
					<th><?= _h('common.actions') ?></th>
				</tr>
			</thead>
			<tbody id="apiKeysBody"><tr><td colspan="5" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
		</table>
	</div>

	<div class="section-head" style="margin-top:28px;">
		<h3 style="margin:0;"><i class="fa-solid fa-bell"></i> <?= _h('panel.wh.title') ?></h3>
		<button class="btn btn-primary" data-fh-click="openCreateWebhook()"><i class="fa-solid fa-plus"></i> <?= _h('panel.wh.new') ?></button>
	</div>
	<p style="color:var(--text-muted); margin:-6px 0 12px;">
		<?= __('panel.wh.intro') ?>
	</p>
	<div class="table-wrap">
		<table>
			<thead>
				<tr>
					<th>URL</th>
					<th><?= _h('panel.wh.th_events') ?></th>
					<th><?= _h('panel.wh.th_last') ?></th>
					<th><?= _h('panel.wh.th_status') ?></th>
					<th><?= _h('common.actions') ?></th>
				</tr>
			</thead>
			<tbody id="webhooksBody"><tr><td colspan="5" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
		</table>
	</div>
