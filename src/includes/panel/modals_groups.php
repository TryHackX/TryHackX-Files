<?php
/** User and group administration panel modals. Runs in modals.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
<!-- Manage User Modal (Faza 2.2: role / storage limit / password reset) -->
<div class="modal-bg" id="manageUserModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-pen"></i> <?= _h('panel.mu.title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('manageUserModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="manageUserMessage" class="auth-message"></div>
			<input type="hidden" id="muUserId">
			<p><?= __('panel.mu.user', ['name' => $slot('muUserName')]) ?></p>
			<div class="form-group">
				<label><?= _h('panel.mu.role') ?></label>
				<select id="muRole" data-fh-change="onManageRoleChange()">
					<option value="user"><?= _h('panel.users.role_user') ?></option>
					<option value="moderator"><?= _h('panel.users.role_moderator') ?></option>
					<option value="admin"><?= _h('panel.users.role_admin') ?></option>
				</select>
			</div>
			<p class="prem-csp-note" id="muModeratorGroupNote" style="display:none;">
				<i class="fa-solid fa-user-shield"></i> <?= _h('panel.mu.moderator_group_note') ?>
			</p>
			<p class="prem-csp-note" id="muAdminPermissionsNote" style="display:none;">
				<i class="fa-solid fa-shield-halved"></i> <?= _h('panel.mu.staff_profile_admin') ?>
			</p>
			<div class="form-group">
				<label><?= _h('panel.mu.storage') ?></label>
				<input type="number" id="muStorage" min="0" placeholder="<?= _h('panel.mu.storage_ph') ?>">
				<small><?= _h('panel.mu.storage_hint') ?></small>
			</div>
			<div class="form-group">
				<label><?= _h('panel.mu.reset_pw') ?></label>
				<input type="password" id="muPassword" placeholder="<?= _h('panel.mu.reset_pw_ph') ?>" minlength="<?= InputLimits::accountPasswordMin() ?>" maxlength="<?= InputLimits::accountPasswordMax() ?>"
					autocomplete="new-password">
				<div class="pwd-meter"><div class="pwd-meter-fill" id="muPwdBar"></div></div>
				<ul class="pwd-reqs">
					<li id="muReqLen"><?= _h('pwd.req_len_configured', ['min' => InputLimits::accountPasswordMin()]) ?></li>
					<li id="muReqUpper"><?= _h('pwd.req_upper') ?></li>
					<li id="muReqDigit"><?= _h('pwd.req_digit') ?></li>
					<li id="muReqSpec"><?= _h('pwd.req_special') ?></li>
				</ul>
				<input type="password" id="muPassword2" placeholder="<?= _h('panel.mu.reset_pw2_ph') ?>" maxlength="<?= InputLimits::accountPasswordMax() ?>" style="margin-top:8px;"
					autocomplete="new-password">
				<div class="field-status" id="muPassMatch"></div>
				<small><?= _h('panel.mu.reset_pw_hint') ?></small>
			</div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('manageUserModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="saveManageUser()"><?= _h('common.save') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Set User Group Modal (A8) -->
<div class="modal-bg" id="setUserGroupModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-users"></i> <?= _h('panel.grp.set_title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('setUserGroupModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="setUserGroupMessage" class="auth-message"></div>
			<input type="hidden" id="sugUserId">
			<p><?= __('panel.mu.user', ['name' => $slot('sugUserName')]) ?></p>
			<div class="form-group">
				<label><?= _h('panel.grp.group') ?></label>
				<select id="sugGroupSelect" data-fh-change="renderGroupPreview()"></select>
				<small><?= _h('panel.grp.plan_group_hint') ?></small>
			</div>

			<!-- pt 11: what the picked group actually grants, before it is applied. -->
			<div class="perm-preview" id="sugPreview">
				<h4><i class="fa-solid fa-shield-halved"></i> <?= _h('panel.grp.preview_title') ?></h4>
				<div id="sugPreviewBody"></div>
			</div>

			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('setUserGroupModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="confirmSetUserGroup()"><?= _h('common.save') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Group add/edit form (A8; the list lives on Settings → Groups, this is the single editor) -->
<div class="modal-bg" id="groupsModal">
	<div class="modal modal-lg">
		<div class="modal-header">
			<h3><i class="fa-solid fa-users"></i> <span id="grpFormTitle"><?= _h('panel.grp.title') ?></span></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('groupsModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="groupsMessage" class="auth-message"></div>

			<div id="groupsFormView">
				<input type="hidden" id="grpId">
				<div class="form-group">
					<label><?= _h('panel.grp.name') ?></label>
					<input type="text" id="grpName" maxlength="50" required placeholder="<?= _h('panel.grp.name_ph') ?>">
				</div>
				<p class="prem-csp-note" id="grpModeratorGroupNote" style="display:none;">
					<i class="fa-solid fa-user-shield"></i> <?= _h('panel.grp.moderator_group_note') ?>
				</p>
				<div id="grpLimitsSection">
				<div class="form-row">
					<div class="form-group">
						<label><?= _h('panel.grp.transfer_quota') ?></label>
						<div style="display:flex; gap:8px;">
							<input type="number" id="grpTransferQuota" min="0" step="0.1" style="flex:1" placeholder="<?= _h('panel.grp.unlimited_ph') ?>">
							<select id="grpTransferQuotaUnit" class="input" style="width:80px;">
								<option value="GB">GiB</option>
								<option value="TB">TiB</option>
							</select>
						</div>
						<small><?= _h('panel.grp.transfer_quota_hint') ?></small>
					</div>
					<div class="form-group">
						<label><?= _h('panel.grp.transfer_period') ?></label>
						<select id="grpTransferPeriod" class="input">
							<option value="day"><?= _h('panel.grp.period_day') ?></option>
							<option value="week"><?= _h('panel.grp.period_week') ?></option>
							<option value="month"><?= _h('panel.grp.period_month') ?></option>
							<option value="year"><?= _h('panel.grp.period_year') ?></option>
						</select>
					</div>
				</div>
				<div class="form-row">
					<div class="form-group">
						<label><?= _h('panel.grp.max_file_size') ?></label>
						<div style="display:flex; gap:8px;">
							<input type="number" id="grpMaxFileSize" min="0" step="0.1" style="flex:1" placeholder="<?= _h('panel.grp.max_file_size_ph') ?>">
							<select id="grpMaxFileUnit" class="input" style="width:80px;">
								<option value="MB">MiB</option>
								<option value="GB">GiB</option>
								<option value="TB">TiB</option>
							</select>
						</div>
					</div>
					<div class="form-group">
						<label><?= _h('panel.grp.files_session') ?></label>
						<input type="number" id="grpMaxFiles" min="0" placeholder="<?= _h('panel.grp.unlimited_ph') ?>">
					</div>
				</div>
				<div class="form-row">
					<div class="form-group">
						<label><?= _h('panel.grp.quota') ?></label>
						<div style="display:flex; gap:8px;">
							<input type="number" id="grpStorageQuota" min="0" step="0.1" style="flex:1" placeholder="<?= _h('panel.grp.unlimited_ph') ?>">
							<select id="grpQuotaUnit" class="input" style="width:80px;">
								<option value="MB">MiB</option>
								<option value="GB">GiB</option>
								<option value="TB">TiB</option>
							</select>
						</div>
					</div>
					<div class="form-group">
						<label><?= _h('panel.grp.concurrent') ?></label>
						<input type="number" id="grpConcDl" min="0" placeholder="<?= _h('panel.grp.unlimited_ph') ?>">
					</div>
				</div>
				<div class="form-row">
					<div class="form-group">
						<label><?= _h('panel.grp.up_limit') ?></label>
						<div style="display:flex; gap:8px;">
							<input type="number" id="grpUpLimit" min="0" step="0.1" style="flex:1" placeholder="<?= _h('panel.grp.unlimited_ph') ?>">
							<select id="grpUpUnit" class="input" style="width:80px;">
								<option value="Kb">Kb</option>
								<option value="Mb" selected>Mb</option>
								<option value="Gb">Gb</option>
							</select>
						</div>
					</div>
					<div class="form-group">
						<label><?= _h('panel.grp.down_limit') ?></label>
						<div style="display:flex; gap:8px;">
							<input type="number" id="grpDownLimit" min="0" step="0.1" style="flex:1" placeholder="<?= _h('panel.grp.unlimited_ph') ?>">
							<select id="grpDownUnit" class="input" style="width:80px;">
								<option value="Kb">Kb</option>
								<option value="Mb" selected>Mb</option>
								<option value="Gb">Gb</option>
							</select>
						</div>
					</div>
				</div>
				<div class="form-row">
					<div class="form-group">
						<label><?= _h('panel.grp.conn_per_file') ?></label>
						<input type="number" id="grpConnPerFile" min="0" placeholder="<?= _h('panel.grp.unlimited_ph') ?>">
					</div>
					<?php /* pt 6: how long this group's members keep their files. Three states in
					         one field: empty/0 = the installation default, N = N days, and the
					         checkbox = never — which is what a premium tier actually sells. */ ?>
					<div class="form-group">
						<label><?= _h('panel.grp.autodelete') ?></label>
						<input type="number" id="grpAutoDelete" min="0" placeholder="<?= _h('panel.grp.autodelete_ph') ?>">
						<label class="form-check" style="margin-top:8px;">
							<input type="checkbox" id="grpAutoDeleteNever" data-fh-change="onGroupRetentionToggle()">
							<span><?= _h('panel.grp.autodelete_never') ?></span>
						</label>
						<small><?= _h('panel.grp.autodelete_hint') ?></small>
					</div>
				</div>
				<div class="form-group">
					<label class="form-check">
						<input type="checkbox" id="grpIsDefault">
						<span><?= _h('panel.grp.is_default') ?></span>
					</label>
				</div>
				</div>

				<!-- Permissions: rendered from the catalogue the API ships, so the list can't
				     drift from Permissions.php. Hidden entirely for the guest group. -->
				<div id="grpPermsSection">
					<hr style="border-color: var(--border); margin: 22px 0 18px;">
					<h4 style="margin: 0 0 6px;"><i class="fa-solid fa-shield-halved"></i> <?= _h('panel.grp.perms_title') ?></h4>
					<p style="color: var(--text-muted); font-size: 0.86rem; margin: 0 0 14px;"><?= _h('panel.grp.perms_hint') ?></p>
					<div class="perm-grid" id="grpPermList"></div>

					<div id="grpFilterBlock" style="display:none;">
						<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-filter"></i> <?= _h('panel.grp.filters_title') ?></h4>
						<div class="perm-grid perm-grid-sub" id="grpFilterList"></div>
					</div>

					<!-- Collections surface (pt 4): browsing, filtering and clearing them out. -->
					<div id="grpCollBlock" style="display:none;">
						<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-box-archive"></i> <?= _h('panel.grp.coll_title') ?></h4>
						<div class="perm-grid" id="grpCollList"></div>
						<div id="grpCFilterBlock" style="display:none;">
							<h4 style="margin: 16px 0 10px;"><i class="fa-solid fa-filter"></i> <?= _h('panel.grp.cfilters_title') ?></h4>
							<div class="perm-grid perm-grid-sub" id="grpCFilterList"></div>
						</div>
					</div>

					<!-- pt 8: the account's own files. Always shown — unlike everything above it,
					     this surface does not depend on "can browse all files": every account has
					     a "Moje pliki" tab, the question is only how well it can search it. -->
					<div id="grpMyBlock">
						<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-folder-open"></i> <?= _h('panel.grp.my_title') ?></h4>
						<p style="color: var(--text-muted); font-size: 0.86rem; margin: 0 0 12px;"><?= _h('panel.grp.my_hint') ?></p>
						<div class="perm-grid" id="grpMyList"></div>
						<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-arrow-down-wide-short"></i> <?= _h('panel.grp.ui_title') ?></h4>
						<div class="perm-grid" id="grpUiList"></div>
						<div id="grpMFilterBlock" style="display:none;">
							<h4 style="margin: 16px 0 10px;"><i class="fa-solid fa-filter"></i> <?= _h('panel.grp.mfilters_title') ?></h4>
							<div class="perm-grid perm-grid-sub" id="grpMFilterList"></div>
						</div>

						<?php /* pt 4: the account's own collections — the same questions the block
						         above asks about everyone's collections, asked about your own. */ ?>
						<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-box-archive"></i> <?= _h('panel.grp.mycoll_title') ?></h4>
						<div class="perm-grid" id="grpMyCollList"></div>
						<div id="grpMCFilterBlock" style="display:none;">
							<h4 style="margin: 16px 0 10px;"><i class="fa-solid fa-filter"></i> <?= _h('panel.grp.mcfilters_title') ?></h4>
							<div class="perm-grid perm-grid-sub" id="grpMCFilterList"></div>
						</div>

						<?php /* Faza 8: ad exemption — what makes "no ads" a sellable plan feature. */ ?>
						<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-rectangle-ad"></i> <?= _h('panel.grp.ads_title') ?></h4>
						<p style="color: var(--text-muted); font-size: 0.86rem; margin: 0 0 12px;"><?= _h('panel.grp.ads_hint') ?></p>
						<div class="perm-grid" id="grpAdsList"></div>

						<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-shield-halved"></i> <?= _h('panel.grp.moderation_title') ?></h4>
						<p style="color: var(--text-muted); font-size: 0.86rem; margin: 0 0 12px;"><?= _h('panel.grp.staff_hint') ?></p>
						<div class="perm-grid" id="grpModerationList"></div>

						<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-money-check-dollar"></i> <?= _h('panel.grp.premium_staff_title') ?></h4>
						<p style="color: var(--text-muted); font-size: 0.86rem; margin: 0 0 12px;"><?= _h('panel.grp.staff_hint') ?></p>
						<div class="perm-grid" id="grpPremiumStaffList"></div>
					</div>
				</div>
				<p id="grpGuestPermNote" style="display:none; color: var(--text-muted); font-size: 0.88rem; margin-top: 18px;">
					<i class="fa-solid fa-circle-info"></i> <?= _h('panel.grp.guest_perms_note') ?>
				</p>

				<div class="modal-btns">
					<button type="button" class="btn" data-fh-click="closeModal('groupsModal')"><?= _h('common.cancel') ?></button>
					<button type="button" class="btn btn-primary" data-fh-click="saveGroup()"><?= _h('panel.grp.save') ?></button>
				</div>
			</div>
		</div>
	</div>
</div>

<!-- Custom traffic range (pt 5): the dates and the resolution the chart is read at -->
<div class="modal-bg" id="trafficRangeModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-chart-line"></i> <?= _h('panel.dash.range_custom') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('trafficRangeModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div class="form-row">
				<div class="form-group">
					<label><?= _h('panel.top.from') ?></label>
					<input type="date" id="trFrom" class="input">
				</div>
				<div class="form-group">
					<label><?= _h('panel.top.to') ?></label>
					<input type="date" id="trTo" class="input">
				</div>
			</div>
			<div class="form-group">
				<label><?= _h('panel.dash.bucket') ?></label>
				<select id="trBucket" class="input">
					<option value=""><?= _h('panel.dash.bucket_auto') ?></option>
					<option value="hour"><?= _h('panel.dash.bucket_hour') ?></option>
					<option value="day"><?= _h('panel.dash.bucket_day') ?></option>
					<option value="month"><?= _h('panel.dash.bucket_month') ?></option>
				</select>
				<small><?= _h('panel.dash.bucket_hint') ?></small>
			</div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('trafficRangeModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="applyTrafficRange()"><?= _h('panel.flt.apply') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Top-downloaded widget settings (pt 5): pick the period the ranking covers -->
<div class="modal-bg" id="topFilesModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-trophy"></i> <?= _h('panel.top.settings') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('topFilesModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div class="form-group">
				<label><?= _h('panel.top.period') ?></label>
				<select id="tfPeriod" class="input" data-fh-change="onTopFilesPeriodChange()">
					<option value="all"><?= _h('panel.top.all') ?></option>
					<option value="week"><?= _h('panel.top.week') ?></option>
					<option value="month"><?= _h('panel.top.month') ?></option>
					<option value="3months"><?= _h('panel.top.3months') ?></option>
					<option value="6months"><?= _h('panel.top.6months') ?></option>
					<option value="year"><?= _h('panel.top.year') ?></option>
					<option value="custom"><?= _h('panel.top.custom') ?></option>
				</select>
				<small><?= _h('panel.top.period_hint') ?></small>
			</div>
			<div class="form-row" id="tfCustomRange" style="display:none;">
				<div class="form-group">
					<label><?= _h('panel.top.from') ?></label>
					<input type="date" id="tfFrom" class="input">
				</div>
				<div class="form-group">
					<label><?= _h('panel.top.to') ?></label>
					<input type="date" id="tfTo" class="input">
				</div>
			</div>
			<div class="form-group">
				<label><?= _h('panel.top.count') ?></label>
				<input type="number" id="tfLimit" class="input" min="1" max="20" value="5">
			</div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('topFilesModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="applyTopFilesSettings()"><?= _h('common.save') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Project documentation viewer — renders a shipped .md file so it is readable in place -->
<div class="modal-bg" id="docModal">
	<div class="modal modal-doc">
		<div class="modal-header">
			<h3><i class="fa-solid fa-book"></i> <span id="docModalTitle"></span></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('docModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div class="doc-view" id="docModalBody"><?= _h('common.loading') ?></div>
		</div>
	</div>
</div>
