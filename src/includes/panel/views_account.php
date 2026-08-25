<?php
/** Account self-service panel view. Runs in views.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}

// The e-mail-change links land back here with a result in the query string. Until now nothing
// rendered it, so confirming a change looked like it had done nothing at all — and the
// two-stage flow makes that worse, because the halfway point is exactly when the user needs
// telling that a second link is waiting in the other mailbox.
$accountNotice = '';
$accountNoticeKind = 'success';
$accountMessage = (string) ($_GET['msg'] ?? '');
if ($accountMessage === 'email_changed') {
	$accountNotice = __('api.email_change_success');
} elseif ($accountMessage === 'email_change_stage_two') {
	$accountNotice = __('panel.acct.email_stage_two');
} elseif (isset($_GET['err']) && is_string($_GET['err'])) {
	$accountNotice = mb_substr((string) $_GET['err'], 0, 300);
	$accountNoticeKind = 'error';
}
?>
	<?php if ($accountNotice !== ''): ?>
		<div class="auth-message <?= $accountNoticeKind === 'error' ? 'error' : 'success' ?>" style="display:block; margin-bottom:18px;">
			<?= htmlspecialchars($accountNotice, ENT_QUOTES, 'UTF-8') ?>
		</div>
	<?php endif; ?>
	<div class="stats" id="userStatsContainer">
		<div class="stat"><h3 id="uStatFiles"><?= (int) $userStats['files_count'] ?></h3><p><?= _h('panel.my.total_files') ?></p></div>
		<div class="stat"><h3 id="uStatStorage"><?= formatSize((int) $userStats['total_size']) ?></h3><p><?= _h('panel.dash.storage') ?></p></div>
		<div class="stat"><h3 id="uStatDownloads"><?= (int) $userStats['total_downloads'] ?></h3><p><?= _h('panel.my.total_downloads') ?></p></div>
	</div>

	<div class="settings-section">
		<h3><i class="fa-solid fa-lock"></i> <?= _h('panel.acct.security') ?></h3>
		<form data-fh-submit="event.preventDefault(); changeUserPassword(this);">
			<div class="form-row">
				<div class="form-group">
					<label><?= _h('panel.set.new_pw') ?></label>
					<input type="password" name="new_password" id="panelNewPass" required minlength="<?= InputLimits::accountPasswordMin() ?>" maxlength="<?= InputLimits::accountPasswordMax() ?>" placeholder="<?= _h('panel.acct.new_pw_ph') ?>">
					<div class="pwd-meter"><div class="pwd-meter-fill" id="panelPwdBar"></div></div>
					<ul class="pwd-reqs">
						<li id="panelReqLen"><?= _h('pwd.req_len_configured', ['min' => InputLimits::accountPasswordMin()]) ?></li>
						<li id="panelReqUpper"><?= _h('pwd.req_upper') ?></li>
						<li id="panelReqDigit"><?= _h('pwd.req_digit') ?></li>
						<li id="panelReqSpec"><?= _h('pwd.req_special') ?></li>
					</ul>
				</div>
				<div class="form-group">
					<label><?= _h('panel.set.confirm_pw') ?></label>
					<input type="password" name="new_password_confirm" id="panelNewPassConfirm" required minlength="<?= InputLimits::accountPasswordMin() ?>" maxlength="<?= InputLimits::accountPasswordMax() ?>" placeholder="<?= _h('panel.acct.confirm_pw_ph') ?>">
					<div class="field-status" id="panelPassMatchStatus"></div>
				</div>
			</div>
			<button type="submit" class="btn btn-primary"><?= _h('panel.set.update_pw') ?></button>
		</form>
	</div>

	<?php
	// pt 9: what the account currently has, and where to get more. Shown only when the
	// operator turned premium on and chose to advertise it in the panel.
	$premOn = Database::getSetting('premium_enabled', '0') === '1'
		&& Database::getSetting('premium_show_panel', '1') === '1';
	if ($premOn):
		$myGroup = Database::getUserGroup((int) $currentUser['id']);
		$myExpiry = (int) ($currentUser['group_expires_at'] ?? 0);
		?>
		<div class="settings-section">
			<h3><i class="fa-solid fa-gem"></i> <?= _h('premium.nav') ?></h3>
			<p style="color: var(--text-secondary); margin-bottom: 14px;">
				<?= _h('panel.acct.plan_current') ?>
				<strong><?= htmlspecialchars($myGroup['name'] ?? __('panel.grp.default_name')) ?></strong>
				<?php if ($myExpiry > 0): ?>
					— <?= __('premium.owned_until', ['date' => date('d.m.Y', $myExpiry)]) ?>
				<?php endif; ?>
			</p>
			<a class="btn btn-primary" href="<?= $appUrl ?>/premium">
				<i class="fa-solid fa-gem"></i> <?= _h('premium.see_plans') ?>
			</a>
		</div>
	<?php endif; ?>

	<div class="settings-section">
		<h3><i class="fa-solid fa-language"></i> <?= _h('panel.acct.language') ?></h3>
		<p style="color: var(--text-secondary); margin-bottom: 14px;"><?= _h('panel.acct.language_hint') ?></p>
		<div class="form-group" style="max-width: 280px;">
			<label><?= _h('common.language') ?></label>
			<?php $userLang = (string) ($currentUser['language'] ?? ''); ?>
			<select id="acctLanguage" class="input" data-fh-change="saveUserLanguage()">
				<option value=""><?= _h('panel.acct.language_auto') ?></option>
				<?php /* pt 6: only the languages the admin marked as offerable to users. */ ?>
				<?php foreach (Lang::forUsers() as $code => $label): ?>
					<option value="<?= htmlspecialchars($code) ?>" <?= $userLang === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>

	<div class="settings-section">
		<h3><i class="fa-solid fa-key"></i> <?= _h('panel.2fa.title') ?></h3>
		<div id="twofaLoading" style="color:var(--text-muted)"><?= _h('common.loading') ?></div>

		<!-- OFF: offer to enable -->
		<div id="twofaOff" style="display:none;">
			<p style="color:var(--text-muted); margin-bottom:12px;">
				<?= _h('panel.2fa.intro') ?>
			</p>
			<div class="form-group" style="max-width:260px;">
				<label><?= _h('panel.2fa.off_label') ?></label>
				<input type="password" id="twofaSetupPass" maxlength="1024" autocomplete="current-password" placeholder="<?= _h('auth.password') ?>">
			</div>
			<button type="button" class="btn btn-primary" data-fh-click="start2faSetup(this)"><?= _h('panel.2fa.enable') ?></button>
		</div>

		<!-- Enrolling -->
		<div id="twofaEnroll" style="display:none;">
			<p><strong>1.</strong> <?= _h('panel.2fa.step1') ?></p>
			<div id="twofaQr" style="margin:12px 0; width:fit-content; max-width:100%; background:#fff; border-radius:8px; padding:6px;"><?= _h('panel.2fa.generating') ?></div>
			<p style="color:var(--text-muted); font-size:.9rem;"><?= _h('panel.2fa.manual') ?>
				<code id="twofaSecret" style="user-select:all; word-break:break-all;"></code></p>
			<div class="form-group" style="max-width:220px; margin-top:12px;">
				<label><strong>2.</strong> <?= _h('panel.2fa.step2') ?></label>
				<input type="text" id="twofaCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456">
			</div>
			<div id="twofaEnrollMsg" class="auth-message"></div>
			<button type="button" class="btn btn-primary" data-fh-click="confirm2faSetup(this)"><?= _h('panel.2fa.confirm') ?></button>
			<button type="button" class="btn" data-fh-click="cancel2faSetup()"><?= _h('common.cancel') ?></button>
		</div>

		<!-- ON: recovery codes + offer to disable -->
		<div id="twofaOn" style="display:none;">
			<p style="margin-bottom:12px;"><i class="fa-solid fa-circle-check" style="color:var(--success)"></i> <?= __('panel.2fa.on') ?></p>

			<div class="recovery-box">
				<h4><i class="fa-solid fa-life-ring"></i> <?= _h('panel.2fa.rc_title') ?></h4>
				<p><?= _h('panel.2fa.rc_intro') ?></p>
				<p class="recovery-count" id="twofaRcCount"></p>
				<button type="button" class="btn" data-fh-click="openRecoveryCodes()">
					<i class="fa-solid fa-rotate"></i> <?= _h('panel.2fa.rc_regenerate') ?>
				</button>
			</div>

			<div class="form-group" style="max-width:260px; margin-top:20px;">
				<label><?= _h('panel.2fa.off_label') ?></label>
				<input type="password" id="twofaOffPass" maxlength="1024" autocomplete="current-password" placeholder="<?= _h('auth.password') ?>">
			</div>
			<div id="twofaDisableMsg" class="auth-message"></div>
			<button type="button" class="btn btn-danger" data-fh-click="disable2fa(this)"><?= _h('panel.2fa.disable') ?></button>
		</div>
	</div>

	<?php if (RememberTokenRepository::enabled()): ?>
		<div class="settings-section">
			<h3><i class="fa-solid fa-mobile-screen"></i> <?= _h('panel.acct.devices') ?></h3>
			<p style="color: var(--text-secondary); margin-bottom: 16px;"><?= _h('panel.acct.devices_sub') ?></p>
			<div id="rememberDevices" data-empty="<?= _h('panel.acct.devices_none') ?>">
				<p style="color: var(--text-secondary);"><?= _h('panel.acct.devices_loading') ?></p>
			</div>
			<button type="button" class="btn btn-danger" style="margin-top: 14px;"
				data-fh-click="revokeRememberDevices(this)">
				<i class="fa-solid fa-right-from-bracket"></i> <?= _h('panel.acct.devices_revoke') ?>
			</button>
		</div>
	<?php endif; ?>

	<div class="settings-section">
		<h3><i class="fa-solid fa-envelope"></i> <?= _h('panel.acct.email_settings') ?></h3>
		<div style="margin-bottom: 16px;">
			<p><strong><?= _h('panel.acct.current_email') ?></strong> <?= htmlspecialchars($currentUser['email'] ?? '') ?></p>
			<?php if (!empty($currentUser['pending_email'])): ?>
				<p style="color: var(--accent);">
					<strong><?= _h('panel.acct.pending_change') ?></strong> <?= htmlspecialchars($currentUser['pending_email']) ?>
					<br><small><?= _h('panel.acct.check_inbox') ?></small>
				</p>
			<?php endif; ?>
		</div>
		<form data-fh-submit="event.preventDefault(); changeUserEmail(this);">
			<div class="form-group">
				<label><?= _h('panel.acct.new_email') ?></label>
				<input type="email" name="new_email" id="panelNewEmail" required maxlength="<?= InputLimits::emailMax() ?>" placeholder="name@example.com">
			</div>
			<div class="form-group">
				<label><?= _h('panel.acct.confirm_email') ?></label>
				<input type="email" name="confirm_new_email" id="panelConfirmEmail" required maxlength="<?= InputLimits::emailMax() ?>" placeholder="<?= _h('panel.acct.confirm_email_ph') ?>">
				<div class="field-status" id="panelEmailMatchStatus"></div>
			</div>
			<button type="submit" class="btn btn-primary"><?= _h('panel.acct.request_change') ?></button>
			<p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 8px;"><?= _h('panel.acct.email_note') ?></p>
		</form>
	</div>

	<div class="settings-section" style="border-color: #e53e3e;">
		<h3 style="color: #e53e3e;"><i class="fa-solid fa-radiation"></i> <?= _h('panel.acct.danger') ?></h3>
		<p style="color: var(--text-secondary); margin-bottom: 16px;"><?= _h('panel.acct.danger_sub') ?></p>
		<div style="display: flex; gap: 16px; flex-wrap: wrap;">
			<button class="btn btn-danger" data-fh-click="confirmDeleteAllFiles(this)"><i class="fa-solid fa-trash"></i> <?= _h('panel.acct.delete_files') ?></button>
			<?php /* pt 4: the owner account cannot be deleted — the API refuses it, so offering
			         the button here would only produce an error. Files may still be cleared. */ ?>
			<?php if (!Database::isRootAdmin((int) ($currentUser['id'] ?? 0))): ?>
				<button class="btn btn-danger" data-fh-click="confirmDeleteAccount(this)"><i class="fa-solid fa-user-slash"></i> <?= _h('panel.acct.delete_account') ?></button>
			<?php endif; ?>
		</div>
		<?php if (Database::isRootAdmin((int) ($currentUser['id'] ?? 0))): ?>
			<p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 14px;">
				<i class="fa-solid fa-shield-halved"></i> <?= _h('panel.acct.root_protected') ?>
			</p>
		<?php endif; ?>
	</div>
