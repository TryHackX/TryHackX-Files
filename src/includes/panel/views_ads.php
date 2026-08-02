<?php
/**
 * The advertising manager (Faza 8) — rendered as Moderacja → Reklamy. Runs in panel.php
 * scope ($isAdmin, $canAdsPanel, $adsTab, $settings...).
 *
 * Five views of one feature, each behind its own permission so an operator can hand the
 * queue to one group and the metrics to another: the numbers (ads.metrics), the creatives
 * and where they sit in the layout (ads.manage), what awaits review (ads.approve), and
 * what is for sale (ads.packages). Tables and figures fill over AJAX (panel.js).
 */
if (!defined('APP_ROOT')) {
	exit;
}

$adsCan = [
	'overview' => $isAdmin || Permissions::has('ads.metrics'),
	'ads' => $isAdmin || Permissions::has('ads.manage'),
	'zones' => $isAdmin || Permissions::has('ads.manage'),
	'queue' => $isAdmin || Permissions::has('ads.approve'),
	'packages' => $isAdmin || Permissions::has('ads.packages') || Permissions::has('ads.manage'),
];
$aTab = (isset($adsCan[$adsTab]) && $adsCan[$adsTab]) ? $adsTab : '';
if ($aTab === '') {
	foreach ($adsCan as $key => $allowed) {
		if ($allowed) {
			$aTab = $key;
			break;
		}
	}
}
$adsQueueCount = $adsCan['queue'] ? AdRepository::pendingCount() : 0;
$adsDisabledZones = AdsController::disabledZones();
?>
<?php if (!AdsController::isEnabled()): ?>
	<div class="alert alert-error" style="margin-bottom:16px;">
		<i class="fa-solid fa-eye-slash"></i> <?= _h('panel.ads.disabled_banner') ?>
		<?php if ($isAdmin): ?>
			<a href="?tab=settings&stab=ads"><?= _h('panel.ads.disabled_banner_link') ?></a>
		<?php endif; ?>
	</div>
<?php endif; ?>
<div class="sub-tabs">
	<?php foreach ([
		'overview' => 'panel.ads.tab_overview',
		'ads' => 'panel.ads.tab_ads',
		'zones' => 'panel.ads.tab_zones',
		'queue' => 'panel.ads.tab_queue',
		'packages' => 'panel.ads.tab_packages',
	] as $key => $label): ?>
		<?php if ($adsCan[$key]): ?>
			<a href="?tab=moderate&mstab=ads&astab=<?= $key ?>" class="sub-tab <?= $aTab === $key ? 'active' : '' ?>"><?= _h($label) ?><?= $key === 'queue' && $adsQueueCount > 0 ? ' <span class="tab-badge">' . $adsQueueCount . '</span>' : '' ?></a>
		<?php endif; ?>
	<?php endforeach; ?>
</div>

<?php if ($aTab === 'overview'): ?>
	<div class="filters" style="margin-bottom:16px;">
		<span class="detail-label"><?= _h('panel.prem.range') ?></span>
		<div class="scope-picker" id="adsRange">
			<?php foreach ([7 => '7D', 30 => '30D', 90 => '90D', 365 => '1Y'] as $d => $label): ?>
				<button type="button" class="scope-btn <?= $d === 30 ? 'active' : '' ?>" data-days="<?= $d ?>" data-fh-click="setAdsRange(<?= $d ?>)"><?= $label ?></button>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="stats">
		<div class="stat"><h3 id="adsStatImpressions">—</h3><p><?= _h('panel.ads.stat_impressions') ?></p></div>
		<div class="stat"><h3 id="adsStatClicks">—</h3><p><?= _h('panel.ads.stat_clicks') ?></p></div>
		<div class="stat"><h3 id="adsStatCtr">—</h3><p>CTR</p></div>
		<div class="stat"><h3 id="adsStatActive">—</h3><p><?= _h('panel.ads.stat_active') ?></p></div>
	</div>

	<div class="section-head">
		<h3 style="margin:0;"><i class="fa-solid fa-chart-line"></i> <?= _h('panel.ads.chart_title') ?></h3>
		<span style="color:var(--text-muted); font-size:0.85rem;" id="adsChartMeta"></span>
	</div>
	<div class="chart-card">
		<div id="adsChart" class="prem-chart"></div>
	</div>

	<div class="section-head" style="margin-top:28px;">
		<h3 style="margin:0;"><i class="fa-solid fa-table-list"></i> <?= _h('panel.ads.per_ad_title') ?></h3>
	</div>
	<div class="table-wrap">
		<table>
			<thead>
				<tr>
					<th><?= _h('common.name') ?></th>
					<th><?= _h('panel.ads.th_zone') ?></th>
					<th><?= _h('panel.users.th_status') ?></th>
					<th><?= _h('panel.ads.stat_impressions') ?></th>
					<th><?= _h('panel.ads.stat_clicks') ?></th>
					<th>CTR</th>
				</tr>
			</thead>
			<tbody id="adsPerAdBody"><tr><td colspan="6" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
		</table>
	</div>

<?php elseif ($aTab === 'ads'): ?>
	<div class="settings-section">
		<div class="section-head" style="margin-bottom: 10px;">
			<h3 style="margin:0;"><i class="fa-solid fa-rectangle-ad"></i> <?= _h('panel.ads.list_title') ?></h3>
			<button type="button" class="btn btn-primary" data-fh-click="openAdForm()">
				<i class="fa-solid fa-plus"></i> <?= _h('panel.ads.add') ?>
			</button>
		</div>
		<p style="color: var(--text-secondary); margin: 0 0 18px;"><?= _h('panel.ads.list_intro') ?></p>
		<div class="table-wrap">
			<table>
				<thead>
					<tr>
						<th><?= _h('common.name') ?></th>
						<th><?= _h('panel.ads.th_type') ?></th>
						<th><?= _h('panel.ads.th_zone') ?></th>
						<th><?= _h('panel.ads.th_owner') ?></th>
						<th><?= _h('panel.users.th_status') ?></th>
						<th><?= _h('panel.ads.th_weight') ?></th>
						<th><?= _h('panel.ads.th_schedule') ?></th>
						<th><?= _h('common.actions') ?></th>
					</tr>
				</thead>
				<tbody id="adsListBody"><tr><td colspan="8" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
			</table>
		</div>
	</div>

<?php elseif ($aTab === 'zones'): ?>
	<!-- The layout, drawn: one mock per public page with its zones as drop-targets, the
	     FoF-Widgets interaction translated to this app's stacked single-column pages. -->
	<p style="color: var(--text-secondary); margin: 0 0 16px;"><?= _h('panel.ads.zones_intro') ?></p>
	<div class="zones-grid">
		<?php foreach (AdRenderer::PAGES as $pageId => $pageLabel): ?>
			<div class="zone-page">
				<h4><i class="fa-solid fa-window-maximize"></i> <?= _h($pageLabel) ?></h4>
				<div class="zone-page-mock">
					<?php foreach (AdRenderer::ZONES as $zoneId => $zoneMeta): ?>
						<?php if ($zoneMeta['page'] !== $pageId) continue; ?>
						<?php $zoneOff = in_array($zoneId, $adsDisabledZones, true); ?>
						<div class="zone-card<?= $zoneOff ? ' zone-card--off' : '' ?>" data-zone="<?= $zoneId ?>">
							<div class="zone-card-head">
								<span class="zone-card-label"><?= _h($zoneMeta['label']) ?>
									<small style="text-transform:none; letter-spacing:0;"><?= (int) $zoneMeta['w'] ?>×<?= (int) $zoneMeta['h'] ?></small>
									<?php if ($zoneOff): ?><span class="badge badge-muted"><?= _h('panel.ads.zone_off') ?></span><?php endif; ?>
								</span>
								<button type="button" class="btn btn-sm" data-fh-click="openZoneAssign('<?= $zoneId ?>')">
									<i class="fa-solid fa-plus"></i> <?= _h('panel.ads.assign') ?>
								</button>
							</div>
							<div class="zone-card-ads" id="zoneAds-<?= $zoneId ?>"><span class="zone-empty"><?= _h('common.loading') ?></span></div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

<?php elseif ($aTab === 'queue'): ?>
	<p style="color: var(--text-secondary); margin: 0 0 16px;"><?= _h('panel.ads.queue_intro') ?></p>
	<div id="adsQueueList"><p class="empty"><?= _h('common.loading') ?></p></div>

<?php elseif ($aTab === 'packages'): ?>
	<div class="settings-section">
		<div class="section-head" style="margin-bottom: 10px;">
			<h3 style="margin:0;"><i class="fa-solid fa-tags"></i> <?= _h('panel.ads.packages_title') ?></h3>
			<button type="button" class="btn btn-primary" data-fh-click="openPackageForm()">
				<i class="fa-solid fa-plus"></i> <?= _h('panel.ads.package_add') ?>
			</button>
		</div>
		<p style="color: var(--text-secondary); margin: 0 0 18px;"><?= _h('panel.ads.packages_intro') ?></p>
		<div class="table-wrap">
			<table>
				<thead>
					<tr>
						<th><?= _h('common.name') ?></th>
						<th><?= _h('panel.ads.th_kind') ?></th>
						<th><?= _h('panel.ads.th_zone') ?></th>
						<th><?= _h('panel.prem.th_duration') ?></th>
						<th><?= _h('panel.ads.th_priority') ?></th>
						<th><?= _h('panel.prem.th_price') ?></th>
						<th><?= _h('panel.lang.th_enabled') ?></th>
						<th><?= _h('common.actions') ?></th>
					</tr>
				</thead>
				<tbody id="adsPackagesBody"><tr><td colspan="8" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
			</table>
		</div>
	</div>
<?php endif; ?>
