<?php
/** Premium plans and payment plugins panel modals. Runs in modals.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
<!-- Plan editor (pt 9). Everything about how the plan is *sold* lives here as free text or a
     snippet, so the app stays independent of any payment provider. -->
<div class="modal-bg" id="planModal">
	<div class="modal modal-lg">
		<div class="modal-header">
			<h3><i class="fa-solid fa-gem"></i> <span id="planModalTitle"><?= _h('panel.prem.add') ?></span></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('planModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="planMessage" class="auth-message"></div>
			<input type="hidden" id="planId">

			<div class="form-row">
				<div class="form-group" data-plan-auto>
					<label><?= _h('common.name') ?></label>
					<input type="text" id="planName" maxlength="100" placeholder="<?= _h('panel.prem.name_ph') ?>">
				</div>
				<div class="form-group">
					<label><?= _h('panel.prem.group') ?></label>
					<select id="planGroup" class="input"></select>
					<small><?= _h('panel.prem.group_hint') ?></small>
				</div>
			</div>

			<?php /* What the card is for. "Darmowy" and "Gość" are not products — they describe
			         the tier the reader is already on, so everything about selling (price fields,
			         duration, checkout) is put away for them and the server refuses a checkout
			         even if the URL is typed by hand. */ ?>
			<div class="form-row">
				<div class="form-group">
					<label><?= _h('panel.prem.kind') ?></label>
					<select id="planKind" class="input" data-fh-change="onPlanKindChange()">
						<option value="paid"><?= _h('panel.prem.kind_paid') ?></option>
						<option value="free"><?= _h('panel.prem.kind_free') ?></option>
						<option value="guest"><?= _h('panel.prem.kind_guest') ?></option>
					</select>
					<small id="planKindHint"><?= _h('panel.prem.kind_hint_paid') ?></small>
				</div>
				<div class="form-group">
					<label class="form-check" style="margin-top:26px;">
						<input type="checkbox" id="planShowLimits" data-fh-change="onPlanLimitsToggle()"><span><?= _h('panel.prem.show_limits') ?></span>
					</label>
					<small><?= _h('panel.prem.show_limits_hint') ?></small>
				</div>
			</div>
			<div class="form-group" id="planLimitFields">
				<label><?= _h('panel.prem.limit_fields') ?></label>
				<div class="perm-grid">
					<?php foreach ([
						'quota' => 'premium.limit_quota',
						'file' => 'premium.limit_file',
						'files' => 'premium.limit_files',
						'concurrent' => 'premium.limit_concurrent',
						'retention' => 'premium.limit_retention',
						'transfer' => 'premium.limit_transfer',
					] as $field => $label): ?>
						<label class="perm-item">
							<input type="checkbox" class="plan-limit-field" value="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>">
							<span><?= _h($label) ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<small><?= _h('panel.prem.limit_fields_hint') ?></small>
			</div>

			<?php /* The switch the whole showcase idea hangs on: on, and the card is generated
			         from the group; off, and every field below is the operator's again. */ ?>
			<div class="form-group" id="planAutoRow" style="display:none;">
				<label class="form-check">
					<input type="checkbox" id="planAutoContent" data-fh-change="onPlanKindChange()">
					<span><?= _h('panel.prem.auto') ?></span>
				</label>
				<small><?= _h('panel.prem.auto_hint') ?></small>
			</div>
			<div class="alert alert-info" id="planAutoNote" style="display:none;">
				<i class="fa-solid fa-wand-magic-sparkles"></i> <?= _h('panel.prem.auto_on_note') ?>
			</div>

			<div class="form-row" data-plan-auto>
				<div class="form-group">
					<label><?= _h('panel.prem.price') ?></label>
					<input type="text" id="planPrice" maxlength="50" placeholder="<?= _h('panel.prem.price_ph') ?>">
					<small><?= _h('panel.prem.price_hint') ?></small>
				</div>
				<div class="form-group">
					<label><?= _h('panel.prem.period') ?></label>
					<input type="text" id="planPeriod" maxlength="50" placeholder="<?= _h('panel.prem.period_ph') ?>">
				</div>
			</div>

			<?php /* pt 10: the chargeable amount, next to the copy that describes it. Two fields
			         because "19 zł / mies." is for the reader and 1900 PLN-grosze is for the
			         provider, and guessing one from the other is how a plan ends up costing 19
			         grosze. Only the built-in payment checkout uses these. */ ?>
			<div class="form-row" data-plan-sale>
				<div class="form-group">
					<label><?= _h('panel.prem.amount') ?></label>
					<input type="number" id="planAmountMinor" min="0" step="1" placeholder="0">
					<small><?= _h('panel.prem.amount_hint') ?></small>
				</div>
				<div class="form-group">
					<label><?= _h('panel.prem.currency') ?></label>
					<input type="text" id="planCurrency" maxlength="3" placeholder="PLN" style="text-transform:uppercase;">
					<small><?= _h('panel.prem.currency_hint') ?></small>
				</div>
			</div>

			<div class="form-row">
				<div class="form-group" data-plan-sale>
					<label><?= _h('panel.prem.duration') ?></label>
					<input type="number" id="planDuration" min="0" placeholder="0">
					<small><?= _h('panel.prem.duration_hint') ?></small>
				</div>
				<div class="form-group">
					<label><?= _h('panel.prem.badge') ?></label>
					<input type="text" id="planBadge" maxlength="50" placeholder="<?= _h('panel.prem.badge_ph') ?>">
				</div>
			</div>

			<div class="form-group" data-plan-auto>
				<label><?= _h('panel.prem.description') ?></label>
				<select id="planDescFormat" class="input" style="max-width:200px; margin-bottom:8px;">
					<option value="markdown">Markdown</option>
					<option value="html">HTML</option>
				</select>
				<textarea id="planDescription" rows="5" maxlength="10000" class="auth-input" placeholder="<?= _h('panel.prem.markdown_ph') ?>"></textarea>
				<small><?= _h('panel.prem.description_hint') ?></small>
			</div>

			<div class="form-group" data-plan-auto>
				<label><?= _h('panel.prem.features') ?></label>
				<textarea id="planFeatures" rows="4" maxlength="10000" class="auth-input" placeholder="<?= _h('panel.prem.features_ph') ?>"></textarea>
				<small><?= _h('panel.prem.features_hint') ?></small>
			</div>

			<h4 style="margin: 18px 0 10px;" data-plan-sale><i class="fa-solid fa-cart-shopping"></i> <?= _h('panel.prem.checkout') ?></h4>
			<div class="form-group" data-plan-sale>
				<select id="planCheckoutType" class="input" data-fh-change="onPlanCheckoutTypeChange()">
					<option value="builtin"><?= _h('panel.prem.checkout_builtin') ?></option>
					<option value="link"><?= _h('panel.prem.checkout_link') ?></option>
					<option value="html"><?= _h('panel.prem.checkout_html') ?></option>
					<option value="none"><?= _h('panel.prem.checkout_none') ?></option>
				</select>
				<small><?= _h('panel.prem.checkout_hint') ?></small>
				<small id="planBuiltinHint" style="display:none; color:var(--accent);"><?= _h('panel.prem.checkout_builtin_hint') ?></small>
			</div>
			<div class="form-group" id="planCheckoutUrlRow" data-plan-sale>
				<label><?= _h('panel.prem.checkout_url') ?></label>
				<input type="text" id="planCheckoutUrl" maxlength="500" placeholder="https://...">
			</div>
			<div class="form-group" id="planCheckoutHtmlRow" style="display:none;" data-plan-sale>
				<label><?= _h('panel.prem.checkout_snippet') ?></label>
				<textarea id="planCheckoutHtml" rows="5" maxlength="100000" class="auth-input" placeholder="&lt;form action=&quot;...&quot;&gt;…&lt;/form&gt;"></textarea>
				<small><?= _h('panel.prem.checkout_snippet_hint') ?></small>
			</div>
			<div class="form-group">
				<label><?= _h('panel.prem.button_label') ?></label>
				<input type="text" id="planButtonLabel" maxlength="80" placeholder="<?= _h('premium.buy') ?>">
			</div>

			<div class="form-row">
				<div class="form-group">
					<label class="form-check"><input type="checkbox" id="planHighlighted"><span><?= _h('panel.prem.highlighted') ?></span></label>
				</div>
				<div class="form-group">
					<label class="form-check"><input type="checkbox" id="planEnabled"><span><?= _h('panel.prem.plan_enabled') ?></span></label>
				</div>
			</div>
			<div class="form-group">
				<label><?= _h('panel.prem.sort_order') ?></label>
				<input type="number" id="planSortOrder" value="0" style="max-width:140px;">
			</div>

			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('planModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="savePlan()"><?= _h('common.save') ?></button>
			</div>
		</div>
	</div>
</div>

<?php if (Permissions::has('premium.bulk_grants')): ?>
<div class="modal-bg" id="bulkPlanModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-users-gear"></i> <?= _h('panel.prem.bulk_title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('bulkPlanModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="bulkPlanMessage" class="auth-message"></div>
			<p class="bulk-plan-warning"><i class="fa-solid fa-shield-halved"></i> <?= _h('panel.prem.bulk_intro') ?></p>
			<div class="form-row">
				<div class="form-group">
					<label><?= _h('panel.prem.bulk_source') ?></label>
					<select id="bulkSource" class="input" data-fh-change="updateBulkSourceFields()">
						<option value="active_subscribers"><?= _h('panel.prem.bulk_active') ?></option>
						<option value="group"><?= _h('panel.prem.bulk_group') ?></option>
						<option value="buyers"><?= _h('panel.prem.bulk_buyers') ?></option>
					</select>
				</div>
				<div class="form-group" id="bulkGroupWrap" hidden>
					<label><?= _h('panel.grp.group') ?></label>
					<select id="bulkGroup" class="input"></select>
				</div>
			</div>
			<div id="bulkBuyerFields" hidden>
				<div class="form-row">
					<div class="form-group"><label><?= _h('panel.prem.bulk_bought_plan') ?></label><select id="bulkBoughtPlan" class="input"></select></div>
					<div class="form-group"><label><?= _h('panel.prem.bulk_from') ?></label><input type="date" id="bulkFrom"></div>
					<div class="form-group"><label><?= _h('panel.prem.bulk_to') ?></label><input type="date" id="bulkTo"></div>
				</div>
			</div>
			<div class="form-row">
				<div class="form-group"><label><?= _h('panel.prem.bulk_target_plan') ?></label><select id="bulkTargetPlan" class="input"></select></div>
				<div class="form-group"><label><?= _h('panel.prem.grant_days') ?></label><input type="number" id="bulkDays" min="1" max="3650" value="30"></div>
			</div>
			<label class="form-check"><input type="checkbox" id="bulkNotify" checked><span><?= _h('panel.prem.grant_notify') ?></span></label>
			<div id="bulkPreview" class="bulk-plan-preview" hidden></div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('bulkPlanModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn" data-fh-click="previewBulkPlanGrant()"><i class="fa-solid fa-magnifying-glass"></i> <?= _h('panel.prem.bulk_preview') ?></button>
				<button type="button" class="btn btn-primary" id="bulkExecuteBtn" data-fh-click="executeBulkPlanGrant()" hidden><i class="fa-solid fa-gift"></i> <?= _h('panel.prem.bulk_execute') ?></button>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- Payment plugin setup (pkt 5): credentials + what this provider actually requires. -->
<div class="modal-bg" id="pluginModal">
	<div class="modal">
		<div class="modal-header">
			<h3><i class="fa-solid fa-puzzle-piece" id="pluginIcon"></i> <span id="pluginName">—</span></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('pluginModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="pluginMessage" class="auth-message"></div>
			<!-- .doc-view is the panel's existing rendered-Markdown styling (docs viewer). -->
			<div class="plugin-notes doc-view" id="pluginNotes"></div>
			<a href="#" target="_blank" rel="noopener noreferrer" class="detail-link" id="pluginDocs" style="display:none;">
				<i class="fa-solid fa-book"></i> <?= _h('panel.plug.docs') ?>
			</a>
			<div id="pluginFields" style="margin-top:16px;"></div>
			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('pluginModal')"><?= _h('common.close') ?></button>
				<button type="button" class="btn" id="pluginTestBtn" data-fh-click="testPaymentPlugin()" style="display:none">
					<i class="fa-solid fa-plug-circle-check"></i> <?= _h('panel.plug.test') ?>
				</button>
				<button type="button" class="btn" id="pluginSaveBtn" data-fh-click="savePlugin(false)"><?= _h('common.save') ?></button>
				<button type="button" class="btn btn-primary" id="pluginUseBtn" data-fh-click="savePlugin(true)">
					<i class="fa-solid fa-arrow-right"></i> <?= _h('panel.plug.use') ?>
				</button>
			</div>
		</div>
	</div>
</div>

<!-- Grant a plan to a user by hand (pt 9) — for payments settled outside any provider. -->
<div class="modal-bg" id="planGrantModal">
	<div class="modal modal-sm">
		<div class="modal-header">
			<h3><i class="fa-solid fa-gem"></i> <?= _h('panel.prem.grant_title') ?></h3>
			<button class="btn-icon modal-close" data-fh-click="closeModal('planGrantModal')"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<div class="modal-body">
			<div id="planGrantMessage" class="auth-message"></div>
			<p style="color:var(--text-secondary); margin-bottom:14px;">
				<?= _h('panel.prem.grant_intro') ?> <strong id="pgUserName">—</strong>
			</p>
			<div class="form-group">
				<label><?= _h('panel.prem.grant_plan') ?></label>
				<select id="pgPlan" class="input" data-fh-change="onGrantPlanChange()"></select>
			</div>

			<?php /* pt 3: a grant by hand is rarely for exactly the length the plan sells — it is
			         a week as an apology or a year as a prize. Empty = the plan's own duration,
			         0 = no expiry; the hint below spells both out because they are different
			         answers and the field cannot show both at once. */ ?>
			<div class="form-group">
				<label><?= _h('panel.prem.grant_days') ?></label>
				<input type="number" id="pgDays" min="0" max="3650" placeholder="">
				<small id="pgDaysHint"></small>
			</div>

			<div class="form-group">
				<label class="form-check">
					<input type="checkbox" id="pgNotify" checked>
					<span><?= _h('panel.prem.grant_notify') ?></span>
				</label>
				<small><?= _h('panel.prem.grant_notify_hint') ?></small>
			</div>

			<div class="modal-btns">
				<button type="button" class="btn" data-fh-click="closeModal('planGrantModal')"><?= _h('common.cancel') ?></button>
				<button type="button" class="btn btn-danger" data-fh-click="revokePlan()"><?= _h('panel.prem.grant_revoke') ?></button>
				<button type="button" class="btn btn-primary" data-fh-click="grantPlan()"><?= _h('panel.prem.grant_btn') ?></button>
			</div>
		</div>
	</div>
</div>
