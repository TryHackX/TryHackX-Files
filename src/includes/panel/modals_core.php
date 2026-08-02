<?php
/** Core and confirmation panel modals. Runs in modals.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
<!-- MODALS -->

<!-- Delete File Modal -->
<div class="modal-bg" id="deleteModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-trash"></i> <?= _h('panel.modal.del_file') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('deleteModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="deleteFileMessage" class="auth-message"></div>
			<input type="hidden" id="deleteFileId">
			<p><?= __('panel.modal.del_file_q', ['name' => $slot('deleteFileName')]) ?></p>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('deleteModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-danger" data-fh-click="executeFileDelete()"><?= _h('common.delete') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Create User Modal -->
<div class="modal-bg" id="createUserModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-user-plus"></i> <?= _h('panel.modal.create_user') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('createUserModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="createUserMessage" class="auth-message"></div>
			<div class="form-group">
				<label><?= _h('panel.modal.username') ?></label>
				<input type="text" id="newUsername" required minlength="<?= InputLimits::usernameMin() ?>" maxlength="<?= InputLimits::usernameMax() ?>" pattern="[A-Za-z0-9_.-]{<?= InputLimits::usernameMin() ?>,<?= InputLimits::usernameMax() ?>}">
			</div>
			<div class="form-group">
				<label><?= _h('panel.users.th_email') ?></label>
				<input type="email" id="newEmail" required maxlength="<?= InputLimits::emailMax() ?>" autocomplete="off">
				<div class="field-status" id="newEmailStatus"></div>
			</div>
			<div class="form-group">
				<label><?= _h('panel.modal.password') ?></label>
				<input type="password" id="newPassword" required minlength="<?= InputLimits::accountPasswordMin() ?>" maxlength="<?= InputLimits::accountPasswordMax() ?>" autocomplete="new-password">
				<div class="pwd-meter"><div class="pwd-meter-fill" id="newPwdBar"></div></div>
				<ul class="pwd-reqs">
					<li id="newReqLen"><?= _h('pwd.req_len_configured', ['min' => InputLimits::accountPasswordMin()]) ?></li>
					<li id="newReqUpper"><?= _h('pwd.req_upper') ?></li>
					<li id="newReqDigit"><?= _h('pwd.req_digit') ?></li>
					<li id="newReqSpec"><?= _h('pwd.req_special') ?></li>
				</ul>
				<input type="password" id="newPassword2" maxlength="<?= InputLimits::accountPasswordMax() ?>" placeholder="<?= _h('pwd.repeat') ?>" style="margin-top:8px;" autocomplete="new-password">
				<div class="field-status" id="newPassMatch"></div>
			</div>
			<div class="form-group">
				<label><?= _h('panel.modal.role') ?></label>
				<select id="newRole">
					<option value="user"><?= _h('panel.users.role_user') ?></option>
					<option value="moderator"><?= _h('panel.users.role_moderator') ?></option>
					<option value="admin"><?= _h('panel.users.role_admin') ?></option>
				</select>
			</div>
			<div class="form-group">
				<label class="form-check">
					<input type="checkbox" id="newAutoActivate" checked>
					<span><?= _h('panel.modal.autoactivate') ?></span>
				</label>
			</div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('createUserModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="createUser()"><?= _h('panel.modal.create_user_btn') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Advanced Ban Modal -->
<div class="modal-bg" id="advancedBanModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-ban"></i> <?= _h('panel.modal.ban_user') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('advancedBanModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<p><?= __('panel.modal.ban_opts', ['name' => $slot('banTargetUser')]) ?></p>
			<input type="hidden" id="banTargetId">
			<div class="form-group">
				<label class="form-check"><input type="checkbox" id="banEmail" checked><span><?= _h('panel.modal.ban_email') ?></span></label>
			</div>
			<div class="form-group">
				<label class="form-check"><input type="checkbox" id="banName"><span><?= _h('panel.modal.ban_name') ?></span></label>
			</div>
			<div class="form-group">
				<label class="form-check"><input type="checkbox" id="banIP"><span id="banIPLabel"><?= _h('panel.modal.ban_ip') ?></span></label>
			</div>
			<div class="form-group">
				<label><?= _h('panel.modal.reason') ?></label>
				<input type="text" id="advBanReason" maxlength="255" placeholder="<?= _h('panel.modal.reason_ph') ?>" class="auth-input" style="margin-top:5px;">
			</div>
			<div class="form-group">
				<label><?= _h('panel.modal.duration') ?></label>
				<select id="advBanDuration" class="auth-input">
					<option value="0"><?= _h('panel.modal.permanent') ?></option>
					<option value="3600"><?= _h('panel.modal.1h') ?></option>
					<option value="86400"><?= _h('panel.modal.24h') ?></option>
					<option value="604800"><?= _h('panel.modal.7d') ?></option>
					<option value="2592000"><?= _h('panel.modal.30d') ?></option>
				</select>
			</div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('advancedBanModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-danger" data-fh-click="executeAdvancedBan()"><?= _h('panel.modal.confirm_ban') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- User Action Modal -->
<div class="modal-bg" id="userActionModal">
	<div class="modal">
		<div class="modal-header">
			<h3 id="userActionTitle"><?= _h('panel.modal.user_action') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('userActionModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="userActionMessage" class="auth-message"></div>
			<p id="userActionMessageText"></p>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('userActionModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-danger" id="userActionBtn" data-fh-click="executeUserAction()"><?= _h('panel.modal.confirm') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Report Details Modal (pt 8: a case file, not a label/value dump — the summary of what was
     reported sits on top, the evidence below it, and the moderation actions are right here
     instead of forcing a trip back to the row.) -->
<div class="modal-bg" id="reportDetailsModal">
	<div class="modal modal-lg">
		<div class="modal-header">
			<h3><i class="fa-solid fa-flag"></i> <?= _h('panel.modal.report_details') ?> <span class="report-id" id="reportDetailsId"></span></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('reportDetailsModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="reportDetailsContent"></div>
			<div class="modal-btns report-actions">
				<button type="button" class="btn" data-fh-click="closeModal('reportDetailsModal')"><?= _h('common.close') ?></button>
				<a href="#" target="_blank" class="btn" id="reportDetailsOpen"><i class="fa-solid fa-eye"></i> <?= _h('panel.mod.view_file') ?></a>
				<?php if (Permissions::has('moderation.reports.resolve')): ?>
					<button type="button" class="btn" data-fh-click="rejectFromDetails()"><i class="fa-solid fa-shield-halved"></i> <?= _h('panel.modal.reject_btn') ?></button>
				<?php endif; ?>
				<?php if (Permissions::has('moderation.files.delete')): ?>
					<button type="button" class="btn btn-danger" data-fh-click="deleteFromDetails()"><i class="fa-solid fa-trash"></i> <?= _h('panel.modal.del_file_btn') ?></button>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>

<!-- Reject Report Modal -->
<div class="modal-bg" id="rejectReportModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-shield-halved"></i> <?= _h('panel.modal.reject_report') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('rejectReportModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<p><?= _h('panel.modal.reject_q') ?></p>
			<div class="form-group">
				<label><?= _h('panel.modal.reject_reason') ?></label>
				<textarea id="rejectReason" rows="3" class="auth-input" placeholder="<?= _h('panel.modal.reject_reason_ph') ?>"></textarea>
			</div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('rejectReportModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-danger" data-fh-click="confirmRejectReport()"><?= _h('panel.modal.reject_btn') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Delete Reported Modal -->
<div class="modal-bg" id="deleteReportedModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-trash"></i> <?= _h('panel.modal.del_reported') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('deleteReportedModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<p><?= _h('panel.modal.del_reported_q') ?></p>
			<p><small><?= _h('panel.modal.del_reported_note') ?></small></p>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('deleteReportedModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-danger" data-fh-click="confirmDeleteReported()"><?= _h('panel.modal.del_file_btn') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Password Confirmation Modal -->
<div class="modal-bg" id="passwordConfirmModal">
	<div class="modal">
		<div class="modal-header">
			<h3 id="pwdConfirmTitle"><i class="fa-solid fa-lock"></i> <?= _h('panel.modal.pw_required') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('passwordConfirmModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<p id="pwdConfirmMessage"><?= _h('panel.modal.pw_required_msg') ?></p>
			<form id="pwdConfirmForm" data-fh-submit="event.preventDefault(); submitPasswordConfirm();">
				<div class="form-group">
					<label><?= _h('panel.modal.password') ?></label>
					<input type="password" id="confirmPasswordInput" class="auth-input" required maxlength="1024">
				</div>
				<div class="modal-btns">
					<button type="button" class="btn" data-fh-click="closeModal('passwordConfirmModal')"><?= _h('common.cancel') ?></button>
					<button type="submit" class="btn btn-primary" id="pwdConfirmBtn"><?= _h('panel.modal.confirm') ?></button>
				</div>
			</form>
		</div>
	</div>
</div>

<!-- IP Bans Modal -->
<div class="modal-bg" id="ipBansModal">
	<div class="modal" style="max-width: 800px; width: 90%;">
		<div class="modal-header">
			<h3><i class="fa-solid fa-ban"></i> <?= _h('panel.modal.bans_title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('ipBansModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="ipBansMessage" class="auth-message"></div>
			<div id="ipBansContent">
				<div style="margin-bottom: 16px;">
					<button class="btn btn-sm btn-primary" data-fh-click="showAddBanForm()"><i class="fa-solid fa-plus"></i> <?= _h('panel.modal.add_ban') ?></button>
				</div>
				<div class="table-wrap" style="max-height: 400px; overflow-y: auto;">
					<table>
						<thead>
							<tr>
								<th><?= _h('panel.modal.th_value') ?></th>
								<th><?= _h('panel.modal.th_type') ?></th>
								<th><?= _h('panel.modal.reason') ?></th>
								<th><?= _h('panel.modal.th_expires') ?></th>
								<th><?= _h('common.actions') ?></th>
							</tr>
						</thead>
						<tbody id="ipBansBody">
							<tr><td colspan="5" class="loading"><?= _h('common.loading') ?></td></tr>
						</tbody>
					</table>
				</div>
			</div>
			<div id="ipBansConfirmView" style="display:none; text-align:center; padding: 30px 20px;">
				<div style="font-size: 3rem; margin-bottom: 1rem; color: var(--danger);"><i class="fa-solid fa-trash"></i></div>
				<h3 style="text-align: center; width: 100%; display: block;"><?= _h('panel.modal.remove_ban_q') ?></h3>
				<p id="unbanConfirmMessage" style="color: var(--text-secondary); margin-bottom: 20px;"><?= _h('panel.modal.unban_msg') ?></p>
				<div style="display:flex; gap:10px; justify-content:center;">
					<button class="btn" data-fh-click="cancelUnban()"><?= _h('common.cancel') ?></button>
					<button class="btn btn-danger" data-fh-click="confirmUnban()"><?= _h('panel.modal.unban_yes') ?></button>
				</div>
			</div>
		</div>
	</div>
</div>

<!-- Add Ban Modal -->
<div class="modal-bg" id="addBanModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-ban"></i> <?= _h('panel.modal.add_ban_title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('addBanModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="addBanMessage" class="auth-message"></div>
			<div class="form-group">
				<label><?= _h('panel.modal.ban_type') ?></label>
				<select id="banTypeInput" class="auth-input" data-fh-change="updateBanInputPlaceholder()">
					<option value="ip"><?= _h('panel.modal.type_ip') ?></option>
					<option value="email"><?= _h('panel.modal.type_email') ?></option>
					<option value="username"><?= _h('panel.modal.type_username') ?></option>
				</select>
			</div>
			<div class="form-group">
				<label id="banValueLabel"><?= _h('panel.modal.type_ip') ?></label>
				<input type="text" id="banValueInput" placeholder="192.168.1.1">
			</div>
			<div class="form-group">
				<label><?= _h('panel.modal.reason') ?></label>
				<input type="text" id="banReasonInput" placeholder="<?= _h('panel.modal.ban_reason_ph') ?>">
			</div>
			<div class="form-group">
				<label><?= _h('panel.modal.duration') ?></label>
				<select id="banDurationInput" class="auth-input">
					<option value="0"><?= _h('panel.modal.permanent') ?></option>
					<option value="3600"><?= _h('panel.modal.1h') ?></option>
					<option value="86400"><?= _h('panel.modal.24h') ?></option>
					<option value="604800"><?= _h('panel.modal.7d') ?></option>
					<option value="2592000"><?= _h('panel.modal.30d') ?></option>
				</select>
			</div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('addBanModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-danger" data-fh-click="executeAddBan()"><?= _h('panel.modal.confirm_ban') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Cleanup Modal -->
<div class="modal-bg" id="cleanupModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-broom"></i> <?= _h('panel.modal.cleanup') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('cleanupModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<p style="color:var(--text-muted); margin-bottom:15px;"><?= _h('panel.modal.cleanup_intro') ?></p>
			<form method="POST" id="cleanupForm" data-fh-submit="previewCleanup(event); return false;">
				<input type="hidden" name="_csrf" value="<?= $csrf ?>">
				<input type="hidden" name="action" value="cleanup_custom">
				<div class="form-group">
					<label><?= _h('panel.modal.cleanup_days') ?></label>
					<input type="number" name="cleanup_days" class="form-control" placeholder="<?= _h('panel.modal.cleanup_days_ph') ?>" min="1">
				</div>
				<div class="form-group">
					<label><?= _h('panel.modal.cleanup_size') ?></label>
					<div style="display:flex; gap:10px;">
						<input type="number" name="cleanup_size" class="form-control" placeholder="<?= _h('panel.modal.cleanup_size_ph') ?>" min="0.1" step="0.1" style="flex:1;">
						<select name="cleanup_unit" class="form-control" style="width:80px;">
							<option value="MB">MB</option>
							<option value="GB">GB</option>
						</select>
					</div>
				</div>
				<div class="alert alert-warning" style="margin-top:15px; font-size: 0.9rem;"><i class="fa-solid fa-triangle-exclamation"></i> <?= _h('panel.modal.cleanup_warn') ?></div>
				<div class="modal-btns">
					<button type="button" class="btn" data-fh-click="closeModal('cleanupModal')"><?= _h('common.cancel') ?></button>
					<button type="submit" class="btn btn-danger"><?= _h('panel.modal.cleanup') ?></button>
				</div>
			</form>
		</div>
	</div>
</div>

<!-- Global Confirm Modal. Used by every showConfirm() in the panel, so it has to carry the same
     header/body structure as the rest — without it the title and text sat flush against the
     edges of a `padding: 0` box and the dialog looked broken (pkt 4). -->
<div class="modal-bg" id="confirmModal">
	<div class="modal modal-sm">
		<div class="modal-header">
			<h3><i class="fa-solid fa-circle-question" id="confirmIcon"></i> <span id="confirmTitle"><?= _h('panel.modal.confirm') ?></span></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('confirmModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<p id="confirmMessage"><?= _h('panel.modal.are_you_sure') ?></p>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('confirmModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-danger" id="confirmBtn" data-fh-click="confirmAction()"><?= _h('panel.modal.confirm') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- My File Delete Modal -->
<div class="modal-bg" id="myFileDeleteModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-trash"></i> <?= _h('panel.modal.del_file') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('myFileDeleteModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="myFileDeleteMessage" class="auth-message"></div>
			<p><?= __('panel.modal.del_file_q', ['name' => $slot('myFileDeleteName', '')]) ?></p>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('myFileDeleteModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-danger" data-fh-click="executeMyFileDelete()"><?= _h('common.delete') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- File Sharing Options Modal -->
<div class="modal-bg" id="fileOptionsModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-gear"></i> <?= _h('panel.fo.title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('fileOptionsModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="fileOptionsMessage" class="auth-message"></div>
			<input type="hidden" id="foFileId">
			<div class="form-group">
				<label><?= _h('panel.fo.expiry') ?></label>
				<input type="number" id="foExpiry" min="0" placeholder="<?= _h('panel.fo.expiry_ph') ?>">
				<small><?= _h('panel.fo.expiry_hint') ?></small>
			</div>
			<div class="form-group">
				<label><?= _h('panel.fo.max_dl') ?></label>
				<input type="number" id="foMaxDl" min="0" placeholder="<?= _h('panel.fo.expiry_ph') ?>">
			</div>
			<div class="form-group" id="foLimitActionRow">
				<label><?= _h('panel.fo.on_limit') ?></label>
				<select id="foLimitAction" class="input">
					<option value="keep"><?= _h('panel.fo.on_limit_keep') ?></option>
					<option value="delete"><?= _h('panel.fo.on_limit_delete') ?></option>
				</select>
				<small><?= _h('panel.fo.on_limit_hint') ?></small>
			</div>
			<div class="form-group">
				<label class="form-check">
					<input type="checkbox" id="foOneTime"><span><i class="fa-solid fa-fire" style="color:#f97316"></i> <?= _h('panel.fo.onetime') ?></span>
				</label>
				<small><?= _h('panel.fo.onetime_hint') ?></small>
				<div id="foOneTimeUsed" class="field-status status-bad" style="display:none;">
					<?= _h('panel.fo.onetime_used') ?>
				</div>
			</div>
			<div class="form-group">
				<label><?= _h('panel.fo.password') ?></label>
				<input type="password" id="foPassword" placeholder="<?= _h('panel.fo.password_ph') ?>" minlength="8" maxlength="1024"
					autocomplete="new-password">
				<div class="pwd-meter"><div class="pwd-meter-fill" id="foPwdBar"></div></div>
				<ul class="pwd-reqs">
					<li id="foReqLen"><?= _h('pwd.req_len') ?></li>
					<li id="foReqUpper"><?= _h('pwd.req_upper') ?></li>
					<li id="foReqDigit"><?= _h('pwd.req_digit') ?></li>
					<li id="foReqSpec"><?= _h('pwd.req_special') ?></li>
				</ul>
				<input type="password" id="foPassword2" placeholder="<?= _h('pwd.repeat') ?>" maxlength="1024" style="margin-top:8px;"
					autocomplete="new-password">
				<div class="field-status" id="foPassMatchStatus"></div>
				<!-- Shown only when the file actually has a password to remove (see openFileOptions). -->
				<label class="form-check" id="foClearPwRow" style="margin-top:8px;">
					<input type="checkbox" id="foClearPw"><span><?= _h('panel.fo.clear_pw') ?></span>
				</label>
			</div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('fileOptionsModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="saveFileOptions()"><?= _h('common.save') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Command Palette (Faza 2.3) -->
<div class="modal-bg cmd-palette-bg" id="cmdPalette" aria-label="<?= _h('panel.cmd.aria') ?>">
	<div class="cmd-palette">
		<input type="text" id="cmdInput" class="cmd-input" placeholder="<?= _h('panel.cmd.placeholder') ?>" autocomplete="off" spellcheck="false">
		<ul class="cmd-results" id="cmdResults"></ul>
		<div class="cmd-hint"><kbd>↑</kbd><kbd>↓</kbd> <?= _h('panel.cmd.nav') ?> · <kbd>↵</kbd> <?= _h('panel.cmd.select') ?> · <kbd>esc</kbd> <?= _h('panel.cmd.close') ?></div>
	</div>
</div>

<!-- Keyboard shortcuts help (Faza 2.3) -->
<div class="modal-bg" id="shortcutsModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-keyboard"></i> <?= _h('panel.sc.title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('shortcutsModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<table class="shortcuts-table">
				<tr><td><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>K</kbd></td><td><?= _h('panel.sc.palette') ?></td></tr>
				<tr><td><kbd>/</kbd></td><td><?= _h('panel.sc.search') ?></td></tr>
				<tr><td><kbd>g</kbd> <?= _h('panel.sc.then') ?> <kbd>d</kbd></td><td><?= _h('panel.nav.dashboard') ?></td></tr>
				<tr><td><kbd>g</kbd> <?= _h('panel.sc.then') ?> <kbd>f</kbd></td><td><?= _h('panel.nav.files') ?></td></tr>
				<tr><td><kbd>g</kbd> <?= _h('panel.sc.then') ?> <kbd>u</kbd></td><td><?= _h('panel.nav.users') ?></td></tr>
				<tr><td><kbd>g</kbd> <?= _h('panel.sc.then') ?> <kbd>m</kbd></td><td><?= _h('panel.nav.moderate') ?></td></tr>
				<tr><td><kbd>g</kbd> <?= _h('panel.sc.then') ?> <kbd>s</kbd></td><td><?= _h('panel.nav.settings') ?></td></tr>
				<tr><td><kbd>g</kbd> <?= _h('panel.sc.then') ?> <kbd>a</kbd></td><td><?= _h('panel.nav.audit') ?></td></tr>
				<tr><td><kbd>?</kbd></td><td><?= _h('panel.sc.help') ?></td></tr>
				<tr><td><kbd>Esc</kbd></td><td><?= _h('panel.sc.close') ?></td></tr>
			</table>
		</div>
	</div>
</div>
