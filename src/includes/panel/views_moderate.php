<?php
/** Moderation panel view. Runs in views.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
	<?php
	/**
	 * Sub-tabs, the same pattern Ustawienia uses. Each is gated on what it actually shows:
	 * reports and traffic are a moderator's job, the audit log is the administrator's, and
	 * the ads manager (Faza 8, runda 2 — it used to be a top-level tab, which wrapped the
	 * bar to a second line) opens for whoever holds any ads.* manager permission. A session
	 * that may not see a view does not get its link — and `panel.php` has already corrected
	 * `mstab` to something it may see, so this only draws what it is told.
	 */
	$adsQueueBadge = $canAdsPanel ? AdRepository::pendingCount() : 0;
	$modTabs = [
		'reports' => ['perm' => $canReports, 'icon' => 'fa-flag', 'label' => __('panel.nav.mod_general')],
		'tracking' => ['perm' => $canTraffic, 'icon' => 'fa-chart-column', 'label' => __('panel.nav.tracking')],
		'audit' => ['perm' => $canAudit, 'icon' => 'fa-scroll', 'label' => __('panel.nav.audit')],
		'premium' => ['perm' => $canPremiumPanel, 'icon' => 'fa-gem', 'label' => __('premium.nav')],
		'ads' => ['perm' => $canAdsPanel, 'icon' => 'fa-rectangle-ad', 'label' => __('panel.ads.nav'), 'badge' => $adsQueueBadge],
	];
	?>
	<div class="sub-tabs">
		<?php foreach ($modTabs as $key => $meta): ?>
			<?php if ($meta['perm']): ?>
				<a href="?tab=moderate&mstab=<?= $key ?>" class="sub-tab <?= $modTab === $key ? 'active' : '' ?>">
					<i class="fa-solid <?= $meta['icon'] ?>"></i> <?= htmlspecialchars($meta['label']) ?><?= !empty($meta['badge']) ? ' <span class="tab-badge">' . (int) $meta['badge'] . '</span>' : '' ?>
				</a>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>

<?php if ($modTab === 'ads' && $canAdsPanel): ?>
	<?php require __DIR__ . '/views_ads.php'; ?>
<?php elseif ($modTab === 'premium' && $canPremiumPanel): ?>
	<?php require __DIR__ . '/views_premium.php'; ?>
<?php elseif ($modTab === 'reports' && $canReports): ?>
	<div class="filters">
		<h3><?= _h('panel.mod.title') ?></h3>
		<button class="btn" data-fh-click="loadReports()"><i class="fa-solid fa-arrows-rotate"></i> <?= _h('common.refresh') ?></button>
	</div>

	<div class="table-wrap">
		<table>
			<thead>
				<tr>
					<th><?= _h('panel.mod.th_file') ?></th>
					<th><?= _h('panel.mod.th_reason') ?></th>
					<th><?= _h('panel.mod.th_reporter') ?></th>
					<th><?= _h('common.date') ?></th>
					<th><?= _h('common.actions') ?></th>
				</tr>
			</thead>
			<tbody id="reportsBody"><tr><td colspan="5" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
		</table>
		<div class="pagination" id="reportsPagination"></div>
	</div>

<?php elseif ($modTab === 'tracking' && $canTraffic): ?>
	<?php
	$trafficDay = Database::getTrafficStats('day');
	$trafficMonth = Database::getTrafficStats('month');
	$trafficYear = Database::getTrafficStats('year');

	$threshold = isset($_GET['threshold']) ? (float) $_GET['threshold'] : 0;
	$period = isset($_GET['period']) ? (int) $_GET['period'] : 24;
	// B5: threshold can be given in MiB / GiB / TiB (default GiB, matching the old behaviour).
	// Read once, then validate — testing `$_GET['unit'] ?? 'GB'` but assigning `$_GET['unit']`
	// warned ("Undefined array key") whenever the parameter was absent.
	$thresholdUnit = (string) ($_GET['unit'] ?? 'GB');
	if (!in_array($thresholdUnit, ['MB', 'GB', 'TB'], true)) {
		$thresholdUnit = 'GB';
	}
	$unitMult = ['MB' => 1024 * 1024, 'GB' => 1024 * 1024 * 1024, 'TB' => 1024 * 1024 * 1024 * 1024];
	$unitLabel = ['MB' => 'MiB', 'GB' => 'GiB', 'TB' => 'TiB'][$thresholdUnit];

	if ($threshold > 0) {
		$bytes = (int) round($threshold * $unitMult[$thresholdUnit]);
		$trackingIPs = Database::getSuspiciousIPs($bytes, $period);
		// Trim trailing zeros so "5.00" shows as "5"; keep the chosen unit in the title.
		$thrLabel = rtrim(rtrim(number_format($threshold, 2, '.', ''), '0'), '.') . ' ' . $unitLabel;
		$listTitle = __('panel.track.suspicious', ['n' => $thrLabel, 'h' => $period]);
	} else {
		$trackingIPs = Database::getTopTrafficIPs(24, 50);
		$listTitle = __('panel.track.top');
	}
	?>
	<div class="stats">
		<div class="stat"><h3><?= formatSize($trafficDay) ?></h3><p><?= _h('panel.track.today') ?></p></div>
		<div class="stat"><h3><?= formatSize($trafficMonth) ?></h3><p><?= _h('panel.track.month') ?></p></div>
		<div class="stat"><h3><?= formatSize($trafficYear) ?></h3><p><?= _h('panel.track.year') ?></p></div>
	</div>

	<div class="settings-section">
		<h3><i class="fa-solid fa-magnifying-glass"></i> <?= _h('panel.track.monitor') ?></h3>
		<form method="GET" class="filters" style="margin-bottom: 0;">
			<?php /* Śledzenie jest teraz podzakładką Moderacji, więc formularz musi oddać oba
			         parametry — inaczej sprawdzenie progu wyrzucałoby z powrotem na zgłoszenia. */ ?>
			<input type="hidden" name="tab" value="moderate">
			<input type="hidden" name="mstab" value="tracking">
			<div style="display: flex; gap: 10px; align-items: center;">
				<input type="number" name="threshold" placeholder="<?= _h('panel.track.threshold_ph') ?>" value="<?= $threshold ? rtrim(rtrim(number_format($threshold, 2, '.', ''), '0'), '.') : '' ?>" min="0" step="0.1" class="search" style="width: 130px; flex: none;">
					<select name="unit" class="input" style="width: 80px; flex: none;">
						<option value="MB" <?= $thresholdUnit === 'MB' ? 'selected' : '' ?>>MiB</option>
						<option value="GB" <?= $thresholdUnit === 'GB' ? 'selected' : '' ?>>GiB</option>
						<option value="TB" <?= $thresholdUnit === 'TB' ? 'selected' : '' ?>>TiB</option>
					</select>
				<input type="number" name="period" placeholder="<?= _h('panel.track.hours_ph') ?>" value="<?= $period ?>" class="search" style="width: 100px; flex: none;">
				<button type="submit" class="btn btn-primary"><?= _h('panel.track.check') ?></button>
			</div>
			<?php if ($threshold > 0): ?>
				<a href="?tab=moderate&mstab=tracking" class="btn btn-danger"><?= _h('panel.track.clear') ?></a>
			<?php endif; ?>
		</form>
	</div>

	<div class="settings-section">
		<h3><?= htmlspecialchars($listTitle) ?></h3>
		<div class="table-wrap">
			<table>
				<thead>
					<tr>
						<th><?= _h('panel.track.th_ip') ?></th>
						<th><?= _h('panel.track.th_traffic') ?></th>
						<th><?= _h('panel.track.th_requests') ?></th>
						<th><?= _h('common.actions') ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($trackingIPs)): ?>
						<tr><td colspan="4" class="empty"><?= _h('panel.track.none') ?></td></tr>
					<?php else: ?>
						<?php foreach ($trackingIPs as $row): ?>
							<tr>
								<td>
									<div class="file-cell">
										<div class="file-icon" style="font-size: 1rem; background: var(--bg-secondary); width: 32px; height: 32px;"><i class="fa-solid fa-globe"></i></div>
										<div class="file-info"><strong><?= htmlspecialchars($row['ip_address']) ?></strong></div>
									</div>
								</td>
								<td style="font-weight: 500; color: var(--accent);"><?= formatSize($row['total_traffic']) ?></td>
								<td><?= $row['request_count'] ?> <?= _h('panel.track.requests') ?></td>
								<td><a href="?tab=users&search=<?= urlencode($row['ip_address']) ?>" class="btn btn-sm"><i class="fa-solid fa-user"></i> <?= _h('panel.track.lookup') ?></a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>

<?php elseif ($modTab === 'audit' && $canAudit): ?>
	<?php $auditRetention = (int) ($settings['audit_retention_days'] ?? 30); ?>
	<div class="filters">
		<h3><i class="fa-solid fa-scroll"></i> <?= _h('panel.audit.title') ?></h3>
		<button class="btn" data-fh-click="loadAuditLog(1)"><i class="fa-solid fa-arrows-rotate"></i> <?= _h('common.refresh') ?></button>
	</div>
	<?php if ($auditRetention > 0): ?>
		<p style="color: var(--text-muted); font-size: 0.85rem; margin: -4px 0 12px;">
			<i class="fa-solid fa-clock-rotate-left"></i> <?= __('panel.audit.retention_note', ['n' => $auditRetention]) ?>
		</p>
	<?php endif; ?>
	<div class="table-wrap">
		<table>
			<thead>
				<tr>
					<th><?= _h('panel.audit.th_when') ?></th>
					<th><?= _h('panel.audit.th_user') ?></th>
					<th><?= _h('panel.audit.th_action') ?></th>
					<th><?= _h('panel.audit.th_details') ?></th>
					<th>IP</th>
				</tr>
			</thead>
			<tbody id="auditBody"><tr><td colspan="5" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
		</table>
		<div class="pagination" id="auditPagination"></div>
	</div>
<?php endif; /* moderation sub-tab */ ?>
