<?php
/** Promo codes panel modals. Runs in modals.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
<!-- Promo code form (runda 9) -->
<div class="modal-bg" id="promoFormModal">
	<div class="modal modal-md">
		<div class="modal-header">
			<h3><i class="fa-solid fa-ticket"></i> <span id="promoFormTitle"><?= _h('panel.promo.form_title') ?></span></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('promoFormModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="promoFormMessage" class="auth-message"></div>
			<input type="hidden" id="promoFormId">
			<div class="form-group">
				<label><?= _h('panel.promo.th_code') ?></label>
				<input type="text" id="promoFormCode" maxlength="40" placeholder="LATO2026" style="text-transform:uppercase;">
				<small><?= _h('panel.promo.code_hint') ?></small>
			</div>
			<div class="promo-form-grid">
				<div class="form-group">
					<label><?= _h('panel.promo.form_scope') ?></label>
					<select id="promoFormScope" data-fh-change="onPromoScopeChange()">
						<option value="all"><?= _h('panel.promo.scope_all') ?></option>
						<option value="plan"><?= _h('panel.promo.scope_plan') ?></option>
					</select>
				</div>
				<div class="form-group" id="promoFormPlanGroup" style="display:none;">
					<label><?= _h('panel.promo.form_plan') ?></label>
					<select id="promoFormPlan"></select>
				</div>
			</div>
			<div class="promo-form-grid">
				<div class="form-group">
					<label><?= _h('panel.promo.form_percent') ?></label>
					<input type="number" id="promoFormPercent" min="1" max="90" value="10">
				</div>
				<div class="form-group">
					<label><?= _h('panel.promo.form_max_uses') ?></label>
					<input type="number" id="promoFormMaxUses" min="0" value="0">
					<small><?= _h('panel.promo.max_uses_hint') ?></small>
				</div>
			</div>
			<div class="promo-form-grid">
				<div class="form-group">
					<label><?= _h('panel.promo.form_expires') ?></label>
					<input type="date" id="promoFormExpires">
				</div>
				<div class="promo-enabled-card">
					<label class="form-check">
						<input type="checkbox" id="promoFormEnabled" checked><span><?= _h('panel.lang.th_enabled') ?></span>
					</label>
					<small><?= _h('panel.promo.enabled_hint') ?></small>
				</div>
			</div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('promoFormModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="savePromoForm()"><?= _h('common.save') ?></button>
			</div>
		</div>
	</div>
</div>
