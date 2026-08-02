<?php
/** Advertising panel modals. Runs in modals.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
<!-- Ad form (Faza 8, admin): one modal for all three creative types; the type radio shows
     the fields that apply. Buyers use their own, image-only modal further down. -->
<div class="modal-bg" id="adFormModal">
	<div class="modal modal-md">
		<div class="modal-header">
			<h3><i class="fa-solid fa-rectangle-ad"></i> <span id="adFormTitle"><?= _h('panel.ads.form_title') ?></span></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('adFormModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="adFormMessage" class="auth-message"></div>
			<input type="hidden" id="adFormId">
			<p id="adFormOwnedNote" style="display:none; color:var(--text-muted); margin:0 0 12px;">
				<i class="fa-solid fa-lock"></i> <?= _h('panel.ads.form_owned_note') ?>
			</p>
			<div class="form-group">
				<label><?= _h('panel.ads.form_name') ?></label>
				<input type="text" id="adFormName" maxlength="120">
				<small><?= _h('panel.ads.form_name_hint') ?></small>
			</div>
			<div class="form-group" id="adFormTypeRow">
				<label><?= _h('panel.ads.th_type') ?></label>
				<div class="radio-row">
					<label class="form-check"><input type="radio" name="adFormType" value="image" checked data-fh-change="adFormTypeChanged()"><span><?= _h('panel.ads.type_image') ?></span></label>
					<label class="form-check"><input type="radio" name="adFormType" value="html" data-fh-change="adFormTypeChanged()"><span><?= _h('panel.ads.type_html') ?></span></label>
					<label class="form-check"><input type="radio" name="adFormType" value="adsense" data-fh-change="adFormTypeChanged()"><span>AdSense</span></label>
				</div>
			</div>
			<div class="form-group">
				<label><?= _h('panel.ads.th_zone') ?></label>
				<select id="adFormZone" class="input"></select>
			</div>

			<div id="adFormImageFields">
				<div class="form-group">
					<label><?= _h('panel.ads.form_upload') ?></label>
					<?= $adUploader('adForm') ?>
					<div id="adFormPreview" class="ad-form-preview"></div>
				</div>
				<div class="form-group">
					<label><?= _h('panel.ads.form_image_url') ?></label>
					<input type="url" id="adFormImageUrl" maxlength="500" placeholder="https://...">
					<small><?= _h('panel.ads.form_image_url_hint') ?></small>
				</div>
				<div class="form-group">
					<label><?= _h('panel.ads.form_target') ?></label>
					<input type="url" id="adFormTargetUrl" maxlength="500" placeholder="https://...">
				</div>
				<div class="form-group">
					<label><?= _h('panel.ads.form_alt') ?></label>
					<input type="text" id="adFormAlt" maxlength="200">
				</div>
			</div>

			<div id="adFormHtmlFields" style="display:none;">
				<div class="form-group">
					<label><?= _h('panel.ads.form_html') ?></label>
					<textarea id="adFormHtml" rows="5" class="auth-input" spellcheck="false"></textarea>
					<small style="color:var(--warning);"><i class="fa-solid fa-triangle-exclamation"></i> <?= _h('panel.ads.form_html_warn') ?></small>
				</div>
			</div>

			<div id="adFormAdsenseFields" style="display:none;">
				<div class="form-group">
					<label><?= _h('panel.ads.form_adsense_slot') ?></label>
					<input type="text" id="adFormAdsenseSlot" maxlength="20" placeholder="1234567890">
					<small id="adFormAdsenseHint"><?= _h('panel.ads.form_adsense_hint') ?></small>
				</div>
			</div>

			<div class="flt-size-row">
				<div class="form-group">
					<label><?= _h('panel.ads.th_weight') ?></label>
					<input type="number" id="adFormWeight" min="1" max="100" value="1">
				</div>
				<div class="form-group">
					<label><?= _h('panel.users.th_status') ?></label>
					<select id="adFormStatus" class="input">
						<option value="active"><?= _h('panel.ads.status_active') ?></option>
						<option value="paused"><?= _h('panel.ads.status_paused') ?></option>
					</select>
				</div>
			</div>
			<div class="flt-size-row">
				<div class="form-group">
					<label><?= _h('panel.ads.form_starts') ?></label>
					<input type="date" id="adFormStarts">
				</div>
				<div class="form-group">
					<label><?= _h('panel.ads.form_ends') ?></label>
					<input type="date" id="adFormEnds">
				</div>
			</div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('adFormModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="saveAdForm()"><?= _h('common.save') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Ad package form (Faza 8, admin) -->
<div class="modal-bg" id="packageFormModal">
	<div class="modal modal-md">
		<div class="modal-header">
			<h3><i class="fa-solid fa-tags"></i> <span id="packageFormTitle"><?= _h('panel.ads.package_form_title') ?></span></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('packageFormModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="packageFormMessage" class="auth-message"></div>
			<input type="hidden" id="packageFormId">
			<div class="form-group">
				<label><?= _h('common.name') ?></label>
				<input type="text" id="packageFormName" maxlength="120">
			</div>
			<div class="form-group">
				<label><?= _h('panel.ads.package_desc') ?></label>
				<textarea id="packageFormDesc" rows="2" class="auth-input"></textarea>
			</div>
			<div class="form-group">
				<label><?= _h('panel.ads.th_kind') ?></label>
				<div class="radio-row">
					<label class="form-check"><input type="radio" name="packageFormKind" value="placement" checked data-fh-change="packageKindChanged()"><span><?= _h('panel.ads.kind_placement') ?></span></label>
					<label class="form-check"><input type="radio" name="packageFormKind" value="boost" data-fh-change="packageKindChanged()"><span><?= _h('panel.ads.kind_boost') ?></span></label>
				</div>
				<small id="packageFormKindHint"><?= _h('panel.ads.kind_placement_hint') ?></small>
			</div>
			<div class="form-group" id="packageFormZoneRow">
				<label><?= _h('panel.ads.th_zone') ?></label>
				<select id="packageFormZone" class="input"></select>
			</div>
			<div class="flt-size-row" id="packageFormPriorityRow">
				<div class="form-group">
					<label><?= _h('panel.ads.package_priority') ?></label>
					<input type="number" id="packageFormPriority" min="1" max="100" value="10">
					<small><?= _h('panel.ads.package_priority_hint') ?></small>
				</div>
				<div class="form-group" id="packageFormBonusCol" style="display:none;">
					<label><?= _h('panel.ads.package_bonus') ?></label>
					<input type="number" id="packageFormBonus" min="1" max="500" value="20">
					<small><?= _h('panel.ads.package_bonus_hint') ?></small>
				</div>
			</div>
			<?php /* pt 7: which extra zones this placement may add, and what each costs. */ ?>
			<div class="form-group" id="packageFormAddonsRow">
				<label><?= _h('panel.ads.package_addons') ?></label>
				<small><?= _h('panel.ads.package_addons_hint') ?></small>
				<div id="packageFormAddons" class="myad-addons"></div>
			</div>
			<div class="flt-size-row">
				<div class="form-group">
					<label><?= _h('panel.ads.package_days') ?></label>
					<input type="number" id="packageFormDays" min="1" value="30">
				</div>
				<div class="form-group">
					<label><?= _h('panel.ads.package_price') ?></label>
					<div class="flt-size-pair">
						<input type="number" id="packageFormPrice" min="0" step="0.01" placeholder="10.00">
						<select id="packageFormCurrency" class="input">
							<option value="PLN">PLN</option>
							<option value="EUR">EUR</option>
							<option value="USD">USD</option>
						</select>
					</div>
				</div>
			</div>
			<div class="flt-size-row">
				<div class="form-group">
					<label><?= _h('panel.ads.package_sort') ?></label>
					<input type="number" id="packageFormSort" value="0">
				</div>
				<div class="form-group">
					<label class="form-check" style="margin-top:26px;">
						<input type="checkbox" id="packageFormEnabled" checked><span><?= _h('panel.lang.th_enabled') ?></span>
					</label>
				</div>
			</div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('packageFormModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="savePackageForm()"><?= _h('common.save') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Reject reason (Faza 8, admin queue) -->
<div class="modal-bg" id="adRejectModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-circle-xmark"></i> <?= _h('panel.ads.reject_title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('adRejectModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<input type="hidden" id="adRejectId">
			<p><?= __('panel.ads.reject_q', ['name' => $slot('adRejectName')]) ?></p>
			<div class="form-group">
				<label><?= _h('panel.ads.reject_reason') ?></label>
				<textarea id="adRejectReason" rows="3" class="auth-input" maxlength="255"></textarea>
			</div>
			<?php /* Runda 8: paid orders can be refunded through PayU straight from here. The
			         row only shows when the queue card carries a completed PayU order. */ ?>
			<div class="form-group" id="adRejectRefundRow" style="display:none;">
				<label class="form-check">
					<input type="checkbox" id="adRejectRefund"><span id="adRejectRefundLabel"></span>
				</label>
			</div>
			<p><small style="color:var(--text-muted)"><?= _h('panel.ads.reject_refund_note') ?></small></p>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('adRejectModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-danger" data-fh-click="confirmAdReject()"><?= _h('panel.ads.reject_confirm') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Zone assignment picker (Faza 8, admin): move an existing creative into this zone. -->
<div class="modal-bg" id="zoneAssignModal">
	<div class="modal">
		<div class="modal-header zone-assign-header">
			<div class="zone-assign-heading">
				<h3><i class="fa-solid fa-arrows-to-dot"></i> <?= _h('panel.ads.assign_title') ?></h3>
				<span id="zoneAssignLabel" class="zone-assign-label"></span>
			</div>
			<button class="btn-icon modal-close" data-fh-click="closeModal('zoneAssignModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<input type="hidden" id="zoneAssignZone">
			<p style="color:var(--text-secondary); margin: 0 0 12px;"><?= _h('panel.ads.assign_intro') ?></p>
			<div id="zoneAssignList" class="zone-assign-list"></div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('zoneAssignModal')"><?= _h('common.close') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="closeModal('zoneAssignModal'); openAdForm(null, document.getElementById('zoneAssignZone').value)">
					<i class="fa-solid fa-plus"></i> <?= _h('panel.ads.assign_new') ?>
				</button>
			</div>
		</div>
	</div>
</div>

<!-- Banner crop stage (Faza 8 runda 5): one modal serves every banner pick — the main
     creative and each add-on placement — with the frame locked to that slot's zone box.
     Drag to reposition, wheel or slider to zoom; Apply exports the frame at the zone's
     exact pixel size. Sits above the ad form modals (z-index in CSS). -->
<div class="modal-bg ad-crop-modal" id="adCropModal">
	<div class="modal modal-md">
		<div class="modal-header">
			<h3><i class="fa-solid fa-crop-simple"></i> <?= _h('panel.ads.crop_title') ?> <span id="adCropModalDims" style="color:var(--accent); font-size:0.85em;"></span></h3>
			<button class="btn-icon modal-close" data-fh-click="adCropCancel()"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div class="ad-crop-stage">
				<div class="ad-crop-frame" id="adCropFrame">
					<img id="adCropImg" alt="" draggable="false">
					<div class="ad-crop-grid" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
					<div class="ad-crop-hintbar"><i class="fa-solid fa-up-down-left-right"></i> <?= _h('panel.ads.crop_dragbar') ?></div>
				</div>
			</div>
			<div class="ad-cropper-controls">
				<i class="fa-solid fa-magnifying-glass-minus"></i>
				<input type="range" id="adCropZoom" min="100" max="300" value="100">
				<i class="fa-solid fa-magnifying-glass-plus"></i>
				<button type="button" class="btn btn-sm" data-fh-click="adCropCenter()"><i class="fa-solid fa-rotate-left"></i> <?= _h('panel.ads.crop_reset') ?></button>
			</div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="adCropCancel()"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="adCropApply()"><i class="fa-solid fa-check"></i> <?= _h('panel.ads.crop_apply') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Renewal configuration (Faza 8 runda 6): what the renewal includes. The base placement
     is fixed; each add-on placement can be unchecked — dropped ones are removed at
     fulfilment and leave the price immediately. -->
<div class="modal-bg" id="myAdRenewModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-rotate-right"></i> <?= _h('panel.myads.renew_title') ?> <span id="myAdRenewName" style="color:var(--accent)"></span></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('myAdRenewModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<input type="hidden" id="myAdRenewAdId">
			<p style="color:var(--text-secondary); margin: 0 0 12px;"><?= _h('panel.myads.renew_intro') ?></p>
			<div id="myAdRenewList" class="myad-addons"></div>
			<div class="myad-total" id="myAdRenewTotal"></div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('myAdRenewModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="confirmMyAdRenew()"><i class="fa-solid fa-credit-card"></i> <?= _h('panel.myads.renew_confirm') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Boost picker (Faza 8, runda 2): more rotation weight for a running ad, for a period. -->
<div class="modal-bg" id="myAdBoostModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-bolt"></i> <?= _h('panel.myads.boost_title') ?> <span id="myAdBoostName" style="color:var(--accent)"></span></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('myAdBoostModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<input type="hidden" id="myAdBoostAdId">
			<p style="color:var(--text-secondary); margin: 0 0 12px;"><?= _h('panel.myads.boost_intro') ?></p>
			<div id="myAdBoostList" class="zone-assign-list"></div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('myAdBoostModal')"><?= _h('common.close') ?></button>
			</div>
		</div>
	</div>
</div>

<!-- Buyer's creative form (Faza 8): image-only by design — the server enforces the same. -->
<div class="modal-bg" id="myAdFormModal">
	<div class="modal modal-md">
		<div class="modal-header">
			<h3><i class="fa-solid fa-rectangle-ad"></i> <span id="myAdFormTitle"><?= _h('panel.myads.form_title') ?></span></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('myAdFormModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="myAdFormMessage" class="auth-message"></div>
			<input type="hidden" id="myAdFormId">
			<input type="hidden" id="myAdFormPackage">
			<p id="myAdFormPackageInfo" style="color:var(--text-secondary); margin: 0 0 14px;"></p>
			<div class="form-group">
				<label><?= _h('panel.ads.form_name') ?></label>
				<input type="text" id="myAdFormName" maxlength="120">
				<small><?= _h('panel.ads.form_name_hint') ?></small>
			</div>
			<div class="form-group">
				<label><?= _h('panel.ads.form_upload') ?></label>
				<?php /* The crop frame doubles as the pre-purchase preview (pt 6): it is the
				         exact box, ratio and "Reklama" label the zone will render. No
				         image-URL option for buyers — a hotlinked creative could be swapped
				         remotely after approval. */ ?>
				<?= $adUploader('myAdForm') ?>
				<div id="myAdFormPreview" class="ad-form-preview"></div>
			</div>
			<div class="form-group">
				<label><?= _h('panel.ads.form_target') ?></label>
				<input type="url" id="myAdFormTargetUrl" maxlength="500" placeholder="https://...">
			</div>
			<div class="form-group">
				<label><?= _h('panel.ads.form_alt') ?></label>
				<input type="text" id="myAdFormAlt" maxlength="200">
			</div>

			<?php /* Add-on placements (pt 7): the package whitelists extra zones + their
			         surcharge; each checked zone may carry its own banner or reuse the main
			         one. Rows are rendered by JS from the package data. */ ?>
			<div class="form-group" id="myAdFormAddonsBox" style="display:none;">
				<label><i class="fa-solid fa-location-dot"></i> <?= _h('panel.myads.addons_title') ?></label>
				<small><?= _h('panel.myads.addons_hint') ?></small>
				<div id="myAdFormAddons" class="myad-addons"></div>
				<div class="myad-total" id="myAdFormTotal"></div>
			</div>

			<p><small style="color:var(--text-muted)"><?= _h('panel.myads.form_review_note') ?></small></p>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('myAdFormModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="saveMyAdForm()"><span id="myAdFormSubmitLabel"><?= _h('panel.myads.form_submit') ?></span></button>
			</div>
		</div>
	</div>
</div>
