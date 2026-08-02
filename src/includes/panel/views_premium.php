<?php
/**
 * The Premium tab body (pt 6 / Faza 8 runda 3). Runs in panel.php scope.
 *
 * Included from two places: the user-facing top-level Premium tab (mine/history), and —
 * for administrators — Moderacja → Premium (overview/payments/subs + their own plan),
 * after the top bar outgrew its one line. $premiumBase keeps the sub-tab links pointing
 * back at whichever surface is hosting the view.
 */
if (!defined('APP_ROOT')) {
	exit;
}
$premiumBase = ($tab === 'moderate') ? '?tab=moderate&mstab=premium' : '?tab=premium';
?>
	<?php
	/**
	 * pt 6: the Premium tab.
	 *
	 * The same tab means two different things depending on who opens it, which is the point:
	 * an operator wants to know whether this is making money and who is subscribed; a user
	 * wants to know what they are on, how much of it they are using and when it runs out.
	 * Sub-tabs, like Settings, because both halves have more than one question.
	 *
	 * The tables and figures are filled over AJAX (see panel.js) so this file stays layout.
	 */
	$premiumStaffHost = $tab === 'moderate';
	$pTab = $_GET['ptab'] ?? ($premiumStaffHost ? 'overview' : 'mine');
	$adminTabs = ['overview' => 'panel.prem.tab_overview', 'payments' => 'panel.prem.tab_payments', 'subs' => 'panel.prem.tab_subs'];
	$userTabs = ['mine' => 'panel.prem.tab_mine', 'history' => 'panel.prem.tab_history'];
	$tabsForMe = [];
	if ($premiumStaffHost) {
		if (Permissions::has('premium.metrics')) $tabsForMe['overview'] = $adminTabs['overview'];
		if (Permissions::has('premium.payments')) $tabsForMe['payments'] = $adminTabs['payments'];
		if (Permissions::has('premium.subscribers')) $tabsForMe['subs'] = $adminTabs['subs'];
	}
	$tabsForMe += $userTabs;
	if (!isset($tabsForMe[$pTab])) {
		$pTab = array_key_first($tabsForMe);
	}
	?>
	<div class="sub-tabs">
		<?php foreach ($tabsForMe as $key => $label): ?>
			<a href="<?= $premiumBase ?>&ptab=<?= $key ?>" class="sub-tab <?= $pTab === $key ? 'active' : '' ?>"><?= _h($label) ?></a>
		<?php endforeach; ?>
	</div>

	<?php if ($pTab === 'overview' && Permissions::has('premium.metrics')): ?>
		<div class="filters" style="margin-bottom:16px;">
			<span class="detail-label"><?= _h('panel.prem.range') ?></span>
			<div class="scope-picker" id="premRange">
				<?php foreach ([7 => '7D', 30 => '30D', 90 => '90D', 365 => '1Y'] as $d => $label): ?>
					<button type="button" class="scope-btn <?= $d === 30 ? 'active' : '' ?>" data-days="<?= $d ?>" data-fh-click="setPremiumRange(<?= $d ?>)"><?= $label ?></button>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="stats">
			<div class="stat"><h3 id="premRevenue">—</h3><p><?= _h('panel.prem.stat_revenue') ?></p></div>
			<div class="stat"><h3 id="premOrders">—</h3><p><?= _h('panel.prem.stat_orders') ?></p></div>
			<div class="stat"><h3 id="premBuyers">—</h3><p><?= _h('panel.prem.stat_buyers') ?></p></div>
			<div class="stat"><h3 id="premActive">—</h3><p><?= _h('panel.prem.stat_active') ?></p></div>
		</div>

		<div class="section-head">
			<h3 style="margin:0;"><i class="fa-solid fa-chart-line"></i> <?= _h('panel.prem.chart_title') ?></h3>
			<span style="color:var(--text-muted); font-size:0.85rem;" id="premChartMeta"></span>
		</div>
		<div class="chart-card">
			<div id="premChart" class="prem-chart"></div>
		</div>

		<?php /* An unpaid attempt is not a failure to hide: it is the difference between
		         "nobody came" and "they came and the checkout lost them". */ ?>
		<div class="stats" style="margin-top:18px;">
			<div class="stat"><h3 id="premPending">—</h3><p><?= _h('panel.prem.stat_pending') ?></p></div>
			<div class="stat"><h3 id="premCanceled">—</h3><p><?= _h('panel.prem.stat_canceled') ?></p></div>
			<div class="stat"><h3 id="premAvg">—</h3><p><?= _h('panel.prem.stat_avg') ?></p></div>
			<div class="stat"><h3 id="premConv">—</h3><p><?= _h('panel.prem.stat_conv') ?></p></div>
		</div>

		<div class="section-head" style="margin-top:28px;">
			<h3 style="margin:0;"><i class="fa-solid fa-tags"></i> <?= _h('panel.prem.plans_title') ?></h3>
			<?php if ($isAdmin): ?>
				<a href="?tab=settings&stab=premium" class="btn btn-sm"><i class="fa-solid fa-gear"></i> <?= _h('panel.prem.manage_plans') ?></a>
			<?php endif; ?>
		</div>
		<div class="table-wrap">
			<table>
				<thead>
					<tr>
						<th><?= _h('panel.prem.th_plan') ?></th>
						<th><?= _h('panel.prem.th_amount') ?></th>
						<th><?= _h('panel.prem.th_duration') ?></th>
						<th><?= _h('panel.users.th_status') ?></th>
					</tr>
				</thead>
				<tbody id="premPlansBody"><tr><td colspan="4" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
			</table>
		</div>

	<?php elseif ($pTab === 'payments' && Permissions::has('premium.payments')): ?>
		<div class="filters">
			<input type="text" class="search" id="premSearch" placeholder="<?= _h('panel.prem.search_ph') ?>">
			<select class="input" id="premStatus" style="max-width:200px;" data-fh-change="loadPremiumPayments(1)">
				<option value=""><?= _h('panel.prem.status_any') ?></option>
				<option value="COMPLETED"><?= _h('panel.prem.status_completed') ?></option>
				<option value="PENDING"><?= _h('panel.prem.status_pending') ?></option>
				<option value="CANCELED"><?= _h('panel.prem.status_canceled') ?></option>
				<option value="REFUNDING"><?= _h('panel.prem.status_refunding') ?></option>
				<option value="REFUNDED"><?= _h('panel.prem.status_refunded') ?></option>
			</select>
			<button class="btn" data-fh-click="loadPremiumPayments(1)"><i class="fa-solid fa-arrows-rotate"></i> <?= _h('common.refresh') ?></button>
		</div>
		<div class="table-wrap">
			<table>
				<thead>
					<tr>
						<th><?= _h('common.date') ?></th>
						<th><?= _h('panel.users.th_user') ?></th>
						<th><?= _h('panel.prem.th_plan') ?></th>
						<th><?= _h('panel.prem.th_amount') ?></th>
						<th><?= _h('panel.users.th_status') ?></th>
						<th><?= _h('panel.prem.th_order') ?></th>
					</tr>
				</thead>
				<tbody id="premPaymentsBody"><tr><td colspan="6" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
			</table>
			<div class="pagination" id="premPaymentsPagination"></div>
		</div>

	<?php elseif ($pTab === 'subs' && Permissions::has('premium.subscribers')): ?>
		<?php if (Permissions::has('premium.bulk_grants')): ?>
			<div class="premium-subs-head">
				<button type="button" class="btn btn-primary" data-fh-click="openBulkPlanGrant()">
					<i class="fa-solid fa-users-gear"></i> <?= _h('panel.prem.bulk_open') ?>
				</button>
			</div>
		<?php endif; ?>
		<p style="color:var(--text-muted); margin:0 0 14px;"><?= _h('panel.prem.subs_intro') ?></p>
		<div class="table-wrap">
			<table>
				<thead>
					<tr>
						<th><?= _h('panel.users.th_user') ?></th>
						<th><?= _h('panel.grp.group') ?></th>
						<th><?= _h('panel.prem.th_expires') ?></th>
						<th><?= _h('panel.prem.th_purchases') ?></th>
						<th><?= _h('panel.prem.th_last_paid') ?></th>
						<th><?= _h('common.actions') ?></th>
					</tr>
				</thead>
				<tbody id="premSubsBody"><tr><td colspan="6" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
			</table>
		</div>

	<?php elseif ($pTab === 'history'): ?>
		<div class="table-wrap">
			<table>
				<thead>
					<tr>
						<th><?= _h('common.date') ?></th>
						<th><?= _h('panel.prem.th_plan') ?></th>
						<th><?= _h('panel.prem.th_amount') ?></th>
						<th><?= _h('panel.users.th_status') ?></th>
					</tr>
				</thead>
				<tbody id="premHistoryBody"><tr><td colspan="4" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
			</table>
		</div>

	<?php else: ?>
		<?php /* "Mój plan" — what this account is on, what it gets, and how much of it is used. */ ?>
		<div id="myPremiumCard" class="prem-mine">
			<p class="empty"><?= _h('common.loading') ?></p>
		</div>
	<?php endif;
