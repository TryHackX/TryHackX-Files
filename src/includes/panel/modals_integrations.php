<?php
/** API keys and webhooks panel modals. Runs in modals.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
<!-- Create API Key Modal (Faza 3.3) -->
<div class="modal-bg" id="createApiKeyModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-key"></i> <?= _h('panel.ak.title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('createApiKeyModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="createApiKeyMessage" class="auth-message"></div>
			<div id="akFormView">
				<div class="form-group">
					<label><?= _h('panel.ak.label') ?></label>
					<input type="text" id="akLabel" maxlength="100" placeholder="<?= _h('panel.ak.label_ph') ?>">
					<small><?= _h('panel.ak.label_hint') ?></small>
				</div>
				<div class="modal-btns">
					<button type="button" class="btn" data-fh-click="closeModal('createApiKeyModal')"><?= _h('common.cancel') ?></button>
					<button type="button" class="btn btn-primary" data-fh-click="submitCreateApiKey()"><?= _h('panel.ak.generate') ?></button>
				</div>
			</div>
			<div id="akResultView" style="display:none;">
				<p style="margin-bottom:8px;"><i class="fa-solid fa-circle-check" style="color:var(--success)"></i> <?= __('panel.ak.created') ?></p>
				<div style="display:flex; gap:8px;">
					<input type="text" id="akKey" readonly style="flex:1; font-family:monospace;" data-fh-click="this.select()">
					<button type="button" class="btn btn-primary" data-fh-click="copyApiKey()"><i class="fa-solid fa-copy"></i> <?= _h('common.copy') ?></button>
				</div>
				<div class="modal-btns">
					<button type="button" class="btn btn-primary" data-fh-click="downloadSharexConfig()"><i class="fa-solid fa-download"></i> <?= _h('panel.ak.download_sharex') ?></button>
					<button type="button" class="btn" data-fh-click="closeModal('createApiKeyModal')"><?= _h('common.close') ?></button>
				</div>
				<p style="color:var(--text-muted); font-size:.85rem; margin-top:10px;">
					<?= __('panel.ak.import_hint') ?>
				</p>
			</div>
		</div>
	</div>
</div>

<!-- Revoke API Key Modal (Faza 3.3) -->
<div class="modal-bg" id="revokeApiKeyModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-trash"></i> <?= _h('panel.ak.revoke_title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('revokeApiKeyModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<input type="hidden" id="revokeKeyId">
			<p><?= __('panel.ak.revoke_q', ['name' => $slot('revokeKeyLabel')]) ?></p>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('revokeApiKeyModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-danger" data-fh-click="confirmRevokeApiKey()"><?= _h('panel.ak.revoke') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Create Webhook Modal (Faza 4.1) -->
<div class="modal-bg" id="createWebhookModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-bell"></i> <?= _h('panel.whm.title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('createWebhookModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="createWebhookMessage" class="auth-message"></div>
			<div id="whFormView">
				<div class="form-group">
					<label><?= _h('panel.whm.url') ?></label>
					<input type="url" id="whUrl" maxlength="500" placeholder="https://example.com/hook">
					<small><?= _h('panel.whm.url_hint') ?></small>
				</div>
				<div class="form-group">
					<label><?= _h('panel.whm.events') ?></label>
					<label class="form-check"><input type="checkbox" id="whEvUpload" checked><span>upload</span></label>
					<label class="form-check"><input type="checkbox" id="whEvDownload" checked><span>download</span></label>
					<label class="form-check"><input type="checkbox" id="whEvDelete" checked><span>delete</span></label>
				</div>
				<div class="modal-btns">
					<button type="button" class="btn" data-fh-click="closeModal('createWebhookModal')"><?= _h('common.cancel') ?></button>
					<button type="button" class="btn btn-primary" data-fh-click="submitCreateWebhook()"><?= _h('panel.whm.create') ?></button>
				</div>
			</div>
			<div id="whResultView" style="display:none;">
				<p style="margin-bottom:8px;"><i class="fa-solid fa-circle-check" style="color:var(--success)"></i> <?= __('panel.whm.created') ?></p>
				<div style="display:flex; gap:8px;">
					<input type="text" id="whSecret" readonly style="flex:1; font-family:monospace;" data-fh-click="this.select()">
					<button type="button" class="btn btn-primary" data-fh-click="copyWebhookSecret()"><i class="fa-solid fa-copy"></i> <?= _h('common.copy') ?></button>
				</div>
				<p style="color:var(--text-muted); font-size:.85rem; margin-top:10px;">
					<?= __('panel.whm.verify_hint') ?>
				</p>
				<div class="modal-btns">
					<button type="button" class="btn" data-fh-click="closeModal('createWebhookModal')"><?= _h('common.close') ?></button>
				</div>
			</div>
		</div>
	</div>
</div>

<!-- Delete Webhook Modal (Faza 4.1) -->
<div class="modal-bg" id="deleteWebhookModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-trash"></i> <?= _h('panel.whm.del_title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('deleteWebhookModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<input type="hidden" id="delWebhookId">
			<p><?= __('panel.whm.del_q', ['url' => $slot('delWebhookUrl', 'color:var(--text); word-break:break-all;')]) ?></p>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('deleteWebhookModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-danger" data-fh-click="confirmDeleteWebhook()"><?= _h('common.delete') ?></button>
			</div>
		</div>
	</div>
</div>

