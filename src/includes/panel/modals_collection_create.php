<?php
/** Collection creation panel modals. Runs in modals.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
<!-- Create Collection Modal (Faza 3.2) -->
<div class="modal-bg" id="createCollectionModal">
	<div class="modal">
		<div class="modal-header" id="ccModalHeader">
			<h3><i class="fa-solid fa-box-archive"></i> <?= _h('panel.cc.title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('createCollectionModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="createCollectionMessage" class="auth-message"></div>
			<div id="ccFormView">
				<div class="form-group">
					<label><?= _h('panel.cc.name') ?></label>
					<input type="text" id="ccName" maxlength="255" placeholder="<?= _h('panel.cc.name_ph') ?>">
				</div>
				<p style="color:var(--text-muted);font-size:.9rem;"><?= _h('panel.cc.selected') ?> <strong id="ccCount" style="color:var(--text)">0</strong></p>

				<!-- C2: same sharing controls as a single file -->
				<div class="form-row">
					<div class="form-group">
						<label><?= _h('panel.fo.expiry') ?></label>
						<input type="number" id="ccExpiry" min="0" placeholder="<?= _h('panel.fo.expiry_ph') ?>">
						<small><?= _h('panel.fo.expiry_hint') ?></small>
					</div>
					<div class="form-group">
						<label><?= _h('panel.fo.max_dl') ?></label>
						<input type="number" id="ccMaxDl" min="0" placeholder="<?= _h('panel.fo.expiry_ph') ?>">
					</div>
				</div>
				<div class="form-group">
					<label class="form-check">
						<input type="checkbox" id="ccOneTime"><span><i class="fa-solid fa-fire" style="color:#f97316"></i> <?= _h('panel.fo.onetime') ?></span>
					</label>
					<small><?= _h('panel.fo.onetime_hint') ?></small>
				</div>
				<div class="form-group">
					<label><?= _h('panel.fo.password') ?></label>
					<input type="password" id="ccPassword" placeholder="<?= _h('panel.fo.password_ph') ?>" minlength="8" maxlength="1024"
						autocomplete="new-password">
					<div class="pwd-meter"><div class="pwd-meter-fill" id="ccPwdBar"></div></div>
					<ul class="pwd-reqs">
						<li id="ccReqLen"><?= _h('pwd.req_len') ?></li>
						<li id="ccReqUpper"><?= _h('pwd.req_upper') ?></li>
						<li id="ccReqDigit"><?= _h('pwd.req_digit') ?></li>
						<li id="ccReqSpec"><?= _h('pwd.req_special') ?></li>
					</ul>
					<input type="password" id="ccPassword2" placeholder="<?= _h('pwd.repeat') ?>" maxlength="1024" style="margin-top:8px;"
						autocomplete="new-password">
					<div class="field-status" id="ccPassMatch"></div>
				</div>
				<div class="modal-btns">
					<button type="button" class="btn" data-fh-click="closeModal('createCollectionModal')"><?= _h('common.cancel') ?></button>
					<button type="button" class="btn btn-primary" data-fh-click="submitCreateCollection()"><?= _h('panel.cc.create') ?></button>
				</div>
			</div>
			<!-- Result of a successful create (pkt 1).
			     Deliberately the same layout, wording and action order as the upload page's
			     dialog (see index.php #qrOverlay) — the two used to be visibly different screens
			     for the same event. The header bar above is hidden while this view is up, so what
			     is left is a centred title, the QR, the link and one row of actions, exactly as on
			     the home page. The skipped-files block is the one addition the panel needs. -->
			<div id="ccResultView" class="qr-result" style="display:none;">
				<h3><?= _h('home.collection_created') ?></h3>
				<p><?= _h('home.collection_hint') ?></p>
				<div id="ccRejected" class="cc-rejected" style="display:none;">
					<strong><i class="fa-solid fa-triangle-exclamation"></i> <?= _h('panel.cc.rejected_title') ?></strong>
					<p id="ccRejectedNames"></p>
					<small><?= _h('panel.cc.rejected_hint') ?></small>
				</div>
				<div class="qr-holder" id="ccQr"></div>
				<div class="qr-link" id="ccUrl"></div>
				<div class="qr-actions">
					<a href="#" target="_blank" class="btn" id="ccOpenBtn"><i class="fa-solid fa-up-right-from-square"></i> <?= _h('qr.open') ?></a>
					<button type="button" class="btn" data-fh-click="copyCollectionResult(event)"><i class="fa-solid fa-copy"></i> <?= _h('common.copy') ?></button>
					<button type="button" class="btn" data-fh-click="editCreatedCollection()"><i class="fa-solid fa-gear"></i> <?= _h('qr.edit') ?></button>
					<button type="button" class="btn" data-fh-click="closeModal('createCollectionModal')"><?= _h('common.close') ?></button>
				</div>
			</div>
		</div>
	</div>
</div>
