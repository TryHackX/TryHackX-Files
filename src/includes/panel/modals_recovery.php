<?php
/** Recovery codes panel modals. Runs in modals.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
<!-- 2FA recovery codes: shown once at enrolment and on every regeneration -->
<div class="modal-bg" id="recoveryCodesModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-life-ring"></i> <?= _h('panel.2fa.rc_title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('recoveryCodesModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="recoveryCodesMessage" class="auth-message"></div>

			<!-- Ask for the password before replacing an existing set -->
			<div id="rcConfirmView">
				<p style="color:var(--text-secondary); margin-bottom:14px;"><?= _h('panel.2fa.rc_regen_warn') ?></p>
				<div class="form-group">
					<label><?= _h('panel.2fa.off_label') ?></label>
					<input type="password" id="rcPassword" maxlength="1024" autocomplete="current-password" placeholder="<?= _h('auth.password') ?>">
				</div>
				<div class="modal-btns">
					<button type="button" class="btn" data-fh-click="closeModal('recoveryCodesModal')"><?= _h('common.cancel') ?></button>
					<button type="button" class="btn btn-primary" data-fh-click="submitRecoveryCodes()"><?= _h('panel.2fa.rc_regenerate') ?></button>
				</div>
			</div>

			<!-- The codes themselves — the only time they are readable -->
			<div id="rcListView" style="display:none;">
				<p style="color:var(--text-secondary); margin-bottom:12px;"><?= _h('panel.2fa.rc_save_now') ?></p>
				<div class="recovery-codes" id="rcCodes"></div>
				<div class="modal-btns">
					<button type="button" class="btn" data-fh-click="copyRecoveryCodes()"><i class="fa-solid fa-copy"></i> <?= _h('common.copy') ?></button>
					<button type="button" class="btn" data-fh-click="downloadRecoveryCodes()"><i class="fa-solid fa-download"></i> <?= _h('panel.2fa.rc_download') ?></button>
					<button type="button" class="btn btn-primary" data-fh-click="closeModal('recoveryCodesModal'); load2faStatus();"><?= _h('common.close') ?></button>
				</div>
			</div>
		</div>
	</div>
</div>
