<?php
/** Dashboard panel view. Runs in views.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
	<div class="stats">
		<div class="stat"><h3 id="dashFiles">—</h3><p><?= _h('panel.dash.files') ?></p></div>
		<div class="stat"><h3 id="dashStorage">—</h3><p><?= _h('panel.dash.storage') ?></p></div>
		<div class="stat"><h3 id="dashDownloads">—</h3><p><?= _h('panel.dash.downloads') ?></p></div>
		<div class="stat"><h3 id="dashActive">—</h3><p><?= _h('panel.dash.active') ?></p></div>
	</div>

	<div class="dash-grid">
		<div class="settings-section">
			<div class="widget-head">
				<h3><i class="fa-solid fa-chart-line"></i> <?= _h('panel.dash.traffic') ?></h3>
				<!-- pt 5: selectable spans, the way a price chart offers them. The resolution
				     (hours / days / months) follows from the span — see handleAdminTraffic. -->
				<div class="range-picker" id="trafficRanges">
					<?php foreach (['24h' => '24h', '7d' => '7D', '30d' => '30D', '90d' => '90D', '1y' => '1Y'] as $key => $label): ?>
						<button type="button" class="range-btn<?= $key === '7d' ? ' active' : '' ?>" data-range="<?= $key ?>"
							data-fh-click="setTrafficRange('<?= $key ?>')"><?= $label ?></button>
					<?php endforeach; ?>
					<button type="button" class="range-btn" data-range="custom" data-fh-click="openTrafficRange()" title="<?= _h('panel.dash.range_custom') ?>">
						<i class="fa-solid fa-calendar-days"></i>
					</button>
				</div>
			</div>
			<p class="widget-period" id="trafficPeriod"></p>
			<div id="trafficChart" class="chart-holder"><?= _h('common.loading') ?></div>
			<div class="chart-legend">
				<span><i style="background:var(--accent)"></i> Upload</span>
				<span><i style="background:#22c55e"></i> Download</span>
			</div>
		</div>
		<div class="settings-section">
			<div class="widget-head">
				<h3><i class="fa-solid fa-trophy"></i> <?= _h('panel.dash.top_files') ?></h3>
				<button type="button" class="btn-icon widget-gear" data-fh-click="openTopFilesSettings()" title="<?= _h('panel.top.settings') ?>">
					<i class="fa-solid fa-gear"></i>
				</button>
			</div>
			<p class="widget-period" id="topFilesPeriod"></p>
			<div id="topFiles" class="top-files"><?= _h('common.loading') ?></div>
		</div>
	</div>

	<div class="settings-section" style="margin-top:20px;">
		<h3><i class="fa-solid fa-bolt"></i> <?= _h('panel.dash.active_title') ?> <span class="live-dot" title="<?= _h('panel.dash.live') ?>"></span></h3>
		<div class="table-wrap">
			<table>
				<thead>
					<tr>
						<th><?= _h('panel.dash.files') ?></th>
						<th>IP</th>
						<th><?= _h('common.size') ?></th>
						<th><?= _h('panel.dash.duration') ?></th>
						<th><?= _h('common.actions') ?></th>
					</tr>
				</thead>
				<tbody id="activeDownloadsBody"><tr><td colspan="5" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
			</table>
		</div>
		<p style="margin-top:8px;"><small style="color:var(--text-muted)"><?= _h('panel.dash.kill_note') ?></small></p>
	</div>

	<?php /* pt 8: the other direction. Written by the upload server as it streams, so this is
	         the only place that shows what is arriving *before* it becomes a file — including
	         how far along it is, which is the question actually being asked when someone looks
	         at this widget during a slow transfer. */ ?>
	<div class="settings-section" style="margin-top:20px;">
		<h3><i class="fa-solid fa-cloud-arrow-up"></i> <?= _h('panel.dash.uploads_title') ?> <span class="live-dot" title="<?= _h('panel.dash.live') ?>"></span></h3>
		<div class="table-wrap">
			<table>
				<thead>
					<tr>
						<th><?= _h('panel.dash.files') ?></th>
						<th><?= _h('panel.users.th_user') ?></th>
						<th>IP</th>
						<th><?= _h('panel.dash.progress') ?></th>
						<th><?= _h('panel.dash.duration') ?></th>
						<th><?= _h('common.actions') ?></th>
					</tr>
				</thead>
				<tbody id="activeUploadsBody"><tr><td colspan="6" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
			</table>
		</div>
		<p style="margin-top:8px;"><small style="color:var(--text-muted)"><?= _h('panel.dash.uploads_note') ?></small></p>
	</div>

