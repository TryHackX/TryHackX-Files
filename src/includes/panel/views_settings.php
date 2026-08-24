<?php
/** Settings panel view. Runs in views.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
	<?php $subTab = $_GET['stab'] ?? 'general'; ?>
	<div class="sub-tabs">
		<a href="?tab=settings&stab=general" class="sub-tab <?= $subTab === 'general' ? 'active' : '' ?>"><?= _h('panel.set.general') ?></a>
		<a href="?tab=settings&stab=storage" class="sub-tab <?= $subTab === 'storage' ? 'active' : '' ?>"><?= _h('panel.set.storage') ?></a>
		<a href="?tab=settings&stab=security" class="sub-tab <?= $subTab === 'security' ? 'active' : '' ?>"><?= _h('panel.set.security') ?></a>
		<a href="?tab=settings&stab=email" class="sub-tab <?= $subTab === 'email' ? 'active' : '' ?>"><?= _h('panel.set.email') ?></a>
		<a href="?tab=settings&stab=groups" class="sub-tab <?= $subTab === 'groups' ? 'active' : '' ?>"><?= _h('panel.users.groups') ?></a>
		<a href="?tab=settings&stab=languages" class="sub-tab <?= $subTab === 'languages' ? 'active' : '' ?>"><?= _h('panel.lang.tab') ?></a>
		<a href="?tab=settings&stab=premium" class="sub-tab <?= $subTab === 'premium' ? 'active' : '' ?>"><?= _h('panel.prem.tab') ?></a>
		<a href="?tab=settings&stab=ads" class="sub-tab <?= $subTab === 'ads' ? 'active' : '' ?>"><?= _h('panel.ads.tab') ?></a>
		<a href="?tab=settings&stab=notifications" class="sub-tab <?= $subTab === 'notifications' ? 'active' : '' ?>"><?= _h('notif.title') ?></a>
		<a href="?tab=settings&stab=system" class="sub-tab <?= $subTab === 'system' ? 'active' : '' ?>"><?= _h('panel.set.system') ?></a>
	</div>

	<?php if ($subTab === 'groups'): ?>
		<div class="settings-section">
			<div class="section-head" style="margin-bottom: 10px;">
				<h3 style="margin:0;"><i class="fa-solid fa-users"></i> <?= _h('panel.users.groups') ?></h3>
				<button type="button" class="btn btn-primary" data-fh-click="openGroupForm()">
					<i class="fa-solid fa-plus"></i> <?= _h('panel.grp.add') ?>
				</button>
			</div>
			<p style="color: var(--text-secondary); margin: 0 0 18px;"><?= _h('panel.set.groups_intro') ?></p>

			<div class="table-wrap">
				<table class="groups-table">
					<thead>
						<tr>
							<th class="col-primary"><?= _h('common.name') ?></th>
							<th class="col-center"><?= _h('panel.grp.th_max_file') ?></th>
							<th class="col-center"><?= _h('panel.grp.th_files_session') ?></th>
							<th class="col-center"><?= _h('panel.grp.th_updown') ?></th>
							<th class="col-center"><?= _h('panel.grp.th_perms') ?></th>
							<th class="col-center"><?= _h('panel.grp.th_members') ?></th>
							<th class="col-actions"><?= _h('common.actions') ?></th>
						</tr>
					</thead>
					<tbody id="settingsGroupsBody"><tr><td colspan="7" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
				</table>
				<div class="pagination" id="groupsPagination"></div>
			</div>
			<p style="margin-top:12px;"><small style="color:var(--text-muted)"><?= _h('panel.grp.guest_note') ?></small></p>
		</div>

	<?php elseif ($subTab === 'notifications'): ?>
		<?php /* Two decisions per type, and they are different questions: `enabled` is whether
		         this installation ever mentions the thing at all (a veto no account can lift),
		         the other two are only what a fresh account starts with. */ ?>
		<div class="settings-section">
			<h3><i class="fa-solid fa-bell"></i> <?= _h('notif.admin_title') ?></h3>
			<p style="color: var(--text-secondary); margin: 0 0 18px;"><?= _h('notif.admin_intro') ?></p>
			<div class="table-wrap">
				<table class="notif-prefs">
					<thead>
						<tr>
							<th><?= _h('notif.th_type') ?></th>
							<th title="<?= _h('notif.th_enabled_hint') ?>"><?= _h('notif.th_enabled') ?></th>
							<th><?= _h('notif.th_default_app') ?></th>
							<th><?= _h('notif.th_default_mail') ?></th>
						</tr>
					</thead>
					<tbody id="notifDefaultsBody"><tr><td colspan="4" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
				</table>
			</div>
			<div style="margin-top:14px; display:flex; justify-content:center;">
				<button class="btn btn-primary" style="padding: 12px 28px;" data-notification-action="saveDefaults"><i class="fa-solid fa-floppy-disk"></i> <?= _h('common.save') ?></button>
			</div>
		</div>

		<div class="settings-section">
			<h3><i class="fa-solid fa-clock"></i> <?= _h('notif.warn_title') ?></h3>
			<p style="color: var(--text-secondary); margin: 0 0 14px;"><?= _h('notif.warn_intro') ?></p>
			<form method="POST">
				<input type="hidden" name="_csrf" value="<?= $csrf ?>">
				<input type="hidden" name="action" value="save_notification_timing">
				<div class="form-row">
					<div class="form-group">
						<label><?= _h('notif.warn_files') ?></label>
						<input type="number" name="notify_expiry_days" min="0" max="90"
							value="<?= (int) ($settings['notify_expiry_days'] ?? 3) ?>">
						<small><?= _h('notif.warn_files_hint') ?></small>
					</div>
					<div class="form-group">
						<label><?= _h('notif.warn_plan') ?></label>
						<input type="number" name="notify_plan_days" min="0" max="90"
							value="<?= (int) ($settings['notify_plan_days'] ?? 7) ?>">
						<small><?= _h('notif.warn_plan_hint') ?></small>
					</div>
				</div>
				<div style="margin-top: 6px; display:flex; justify-content:center;">
					<button type="submit" class="btn btn-primary" style="padding: 12px 28px;"><i class="fa-solid fa-floppy-disk"></i> <?= _h('common.save') ?></button>
				</div>
			</form>
		</div>

		<div class="settings-section">
			<h3><i class="fa-solid fa-bullhorn"></i> <?= _h('notif.broadcast_title') ?></h3>
			<p style="color: var(--text-secondary); margin: 0 0 14px;"><?= _h('notif.broadcast_intro') ?></p>
			<div class="form-row">
				<div class="form-group">
					<label><?= _h('notif.broadcast_channel') ?></label>
					<select id="notifBroadcastChannel">
						<option value="app"><?= _h('notif.broadcast_channel_app') ?></option>
						<option value="email"><?= _h('notif.broadcast_channel_email') ?></option>
						<option value="both"><?= _h('notif.broadcast_channel_both') ?></option>
					</select>
				</div>
				<div class="form-group" id="notifBroadcastFormatGroup" hidden>
					<label><?= _h('notif.broadcast_format') ?></label>
					<select id="notifBroadcastFormat">
						<option value="standard"><?= _h('notif.broadcast_format_standard') ?></option>
						<option value="html"><?= _h('notif.broadcast_format_html') ?></option>
					</select>
				</div>
			</div>
			<div class="form-group" id="notifBroadcastAppGroup">
				<label><?= _h('notif.broadcast_label') ?></label>
				<input type="text" id="notifBroadcastText" maxlength="255" placeholder="<?= _h('notif.broadcast_ph') ?>">
			</div>
			<div id="notifBroadcastEmailFields" hidden>
				<div class="form-group">
					<label><?= _h('notif.broadcast_subject') ?></label>
					<input type="text" id="notifBroadcastSubject" maxlength="180" placeholder="<?= _h('notif.broadcast_subject_ph') ?>">
				</div>
				<div class="form-group">
					<label><?= _h('notif.broadcast_email_body') ?></label>
					<textarea id="notifBroadcastEmailBody" maxlength="20000" rows="9" placeholder="<?= _h('notif.broadcast_email_body_ph') ?>"></textarea>
					<small id="notifBroadcastFormatHint"><?= _h('notif.broadcast_standard_hint') ?></small>
				</div>
				<p style="color:var(--text-muted);font-size:.84rem;margin:-4px 0 14px;">
					<?= _h('notif.broadcast_preferences_hint') ?>
				</p>
			</div>
			<div style="display:flex; justify-content:center;">
				<button class="btn btn-primary" style="padding: 12px 28px;" data-notification-action="broadcast">
					<i class="fa-solid fa-paper-plane"></i> <?= _h('notif.broadcast_send') ?>
				</button>
			</div>
		</div>

	<?php elseif ($subTab === 'languages'): ?>
		<div class="settings-section">
			<div class="section-head" style="margin-bottom: 10px;">
				<h3 style="margin:0;"><i class="fa-solid fa-language"></i> <?= _h('panel.lang.tab') ?></h3>
				<button type="button" class="btn btn-primary" data-fh-click="openLanguageUpload()">
					<i class="fa-solid fa-upload"></i> <?= _h('panel.lang.add') ?>
				</button>
			</div>
			<p style="color: var(--text-secondary); margin: 0 0 18px;"><?= _h('panel.lang.intro') ?></p>

			<div class="table-wrap">
				<table>
					<thead>
						<tr>
							<th><?= _h('common.language') ?></th>
							<th><?= _h('panel.lang.th_code') ?></th>
							<th><?= _h('panel.lang.th_coverage') ?></th>
							<th><?= _h('panel.lang.th_enabled') ?></th>
							<th title="<?= _h('panel.lang.th_switcher_hint') ?>"><?= _h('panel.lang.th_switcher') ?></th>
							<th title="<?= _h('panel.lang.th_users_hint') ?>"><?= _h('panel.lang.th_users') ?></th>
							<th><?= _h('common.actions') ?></th>
						</tr>
					</thead>
					<tbody id="languagesBody"><tr><td colspan="7" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
				</table>
			</div>
			<p style="margin-top:12px;"><small style="color:var(--text-muted)"><?= _h('panel.lang.builtin_note') ?></small></p>
		</div>

	<?php elseif ($subTab === 'premium'): ?>
		<!-- pt 9: plans that sell access to a group. The app never handles money — each plan
		     carries the operator's own checkout (a link or an embedded snippet), and the group
		     is granted afterwards through the activation endpoint or by hand. -->
		<div class="settings-section">
			<h3><i class="fa-solid fa-gem"></i> <?= _h('panel.prem.settings_title') ?></h3>
			<p style="color: var(--text-secondary); margin: 0 0 18px;"><?= _h('panel.prem.intro') ?></p>
			<div id="premiumMessage" class="auth-message"></div>

			<div class="form-group">
				<label class="form-check">
					<input type="checkbox" id="premEnabled"><span><?= _h('panel.prem.enabled') ?></span>
				</label>
				<small><?= _h('panel.prem.enabled_hint') ?></small>
			</div>
			<div class="form-group">
				<label><?= _h('panel.prem.page_title') ?></label>
				<input type="text" id="premTitle" maxlength="120" placeholder="<?= _h('premium.title') ?>">
			</div>
			<div class="form-group">
				<label><?= _h('panel.prem.page_intro') ?></label>
				<textarea id="premIntro" rows="4" class="auth-input" placeholder="<?= _h('panel.prem.markdown_ph') ?>"></textarea>
				<small><?= _h('panel.prem.markdown_hint') ?></small>
			</div>
			<div class="form-group">
				<label><?= _h('panel.prem.page_footer') ?></label>
				<textarea id="premFooter" rows="3" class="auth-input" placeholder="<?= _h('panel.prem.markdown_ph') ?>"></textarea>
			</div>

			<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-eye"></i> <?= _h('panel.prem.where') ?></h4>
			<div class="form-group">
				<label class="form-check"><input type="checkbox" id="premShowHeader"><span><?= _h('panel.prem.where_header') ?></span></label>
			</div>
			<div class="form-group">
				<label class="form-check"><input type="checkbox" id="premShowHome"><span><?= _h('panel.prem.where_home') ?></span></label>
			</div>
			<div class="form-group">
				<label class="form-check"><input type="checkbox" id="premShowPanel"><span><?= _h('panel.prem.where_panel') ?></span></label>
			</div>

			<!-- Runda 10: optional printable receipts. Freeform seller block — the operator
			     fills whatever their legal form requires; off by default. -->
			<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-file-invoice"></i> <?= _h('panel.prem.invoice_section') ?></h4>
			<p class="prem-csp-note"><i class="fa-solid fa-link"></i> <?= _h('panel.prem.invoice_shared_hint') ?></p>
			<div class="form-group">
				<label class="form-check"><input type="checkbox" id="premInvoiceEnabled"><span><?= _h('panel.prem.invoice_enabled') ?></span></label>
				<small><?= _h('panel.prem.invoice_enabled_hint') ?></small>
			</div>
			<div class="form-group">
				<label><?= _h('panel.prem.invoice_seller') ?></label>
				<textarea id="premInvoiceSeller" rows="3" class="auth-input" placeholder="<?= _h('panel.prem.invoice_seller_ph') ?>"></textarea>
			</div>
			<div class="flt-size-row">
				<div class="form-group">
					<label><?= _h('panel.prem.invoice_prefix') ?></label>
					<input type="text" id="premInvoicePrefix" maxlength="12" placeholder="FH" style="max-width:140px;">
					<small><?= _h('panel.prem.invoice_prefix_hint') ?></small>
				</div>
			</div>
			<div class="form-group">
				<label><?= _h('panel.prem.invoice_footer') ?></label>
				<textarea id="premInvoiceFooter" rows="2" class="auth-input" placeholder="<?= _h('panel.prem.invoice_footer_ph') ?>"></textarea>
			</div>
			<button type="button" class="btn btn-primary" data-fh-click="savePremiumSettings()"><?= _h('common.save') ?></button>
		</div>

		<div class="settings-section">
			<div class="section-head" style="margin-bottom: 10px;">
				<h3 style="margin:0;"><i class="fa-solid fa-list"></i> <?= _h('panel.prem.plans') ?></h3>
				<button type="button" class="btn btn-primary" data-fh-click="openPlanForm()">
					<i class="fa-solid fa-plus"></i> <?= _h('panel.prem.add') ?>
				</button>
			</div>
			<div class="table-wrap">
				<table>
					<thead>
						<tr>
							<th><?= _h('common.name') ?></th>
							<th><?= _h('panel.prem.th_group') ?></th>
							<th><?= _h('panel.prem.th_price') ?></th>
							<th><?= _h('panel.prem.th_duration') ?></th>
							<th><?= _h('panel.prem.th_checkout') ?></th>
							<th><?= _h('panel.lang.th_enabled') ?></th>
							<th><?= _h('common.actions') ?></th>
						</tr>
					</thead>
					<tbody id="plansBody"><tr><td colspan="7" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
				</table>
			</div>
		</div>

		<!-- Runda 9: percent-off codes for the built-in checkout. Validated at purchase,
		     spent at fulfilment — an abandoned cart never eats a use. -->
		<div class="settings-section">
			<div class="section-head" style="margin-bottom: 10px;">
				<h3 style="margin:0;"><i class="fa-solid fa-ticket"></i> <?= _h('panel.promo.title') ?></h3>
				<button type="button" class="btn btn-primary" data-fh-click="openPromoForm()">
					<i class="fa-solid fa-plus"></i> <?= _h('panel.promo.add') ?>
				</button>
			</div>
			<p style="color: var(--text-secondary); margin: 0 0 18px;"><?= _h('panel.promo.intro') ?></p>
			<div class="table-wrap">
				<table>
					<thead>
						<tr>
							<th><?= _h('panel.promo.th_code') ?></th>
							<th><?= _h('panel.promo.th_scope') ?></th>
							<th><?= _h('panel.promo.th_discount') ?></th>
							<th><?= _h('panel.promo.th_uses') ?></th>
							<th><?= _h('panel.promo.th_expires') ?></th>
							<th><?= _h('panel.lang.th_enabled') ?></th>
							<th><?= _h('common.actions') ?></th>
						</tr>
					</thead>
					<tbody id="promoBody"><tr><td colspan="7" class="empty"><?= _h('common.loading') ?></td></tr></tbody>
				</table>
			</div>
		</div>

		<!-- pkt 5: presets for the usual payment providers. They fill a plan's checkout fields
		     and say what each provider actually needs — they are not integrations, and the cards
		     for BLIK / Apple Pay / Google Pay say plainly that those are methods switched on
		     inside a provider rather than something to integrate here. -->
		<div class="settings-section">
			<h3><i class="fa-solid fa-puzzle-piece"></i> <?= _h('panel.plug.title') ?></h3>
			<p style="color: var(--text-secondary); margin: 0 0 18px;"><?= _h('panel.plug.intro') ?></p>
			<div class="plugin-grid" id="pluginGrid"><?= _h('common.loading') ?></div>
		</div>

		<div class="settings-section">
			<h3><i class="fa-solid fa-plug"></i> <?= _h('panel.prem.api_title') ?></h3>
			<p style="color: var(--text-secondary); margin: 0 0 14px;"><?= _h('panel.prem.api_intro') ?></p>
			<div class="form-group">
				<label><?= _h('panel.prem.api_url') ?></label>
				<input type="text" id="premApiUrl" readonly data-fh-click="this.select()">
			</div>
			<pre class="prem-sample"><code>curl -X POST "<span id="premApiUrlSample">…</span>" \
  -H "Authorization: Bearer &lt;token&gt;" \
  -H "Content-Type: application/json" \
  -d '{"plan_id": 1, "email": "buyer@example.com", "reference": "provider-txn-id"}'</code></pre>
			<p><small style="color:var(--text-muted)"><?= _h('panel.prem.api_fields') ?></small></p>
			<p class="prem-csp-note">
				<i class="fa-solid fa-triangle-exclamation"></i> <?= _h('panel.prem.csp_note') ?>
			</p>
			<div id="premTokenBox" class="prem-token" style="display:none;"></div>
			<button type="button" class="btn" data-fh-click="regeneratePremiumToken()">
				<i class="fa-solid fa-key"></i> <span id="premTokenBtnLabel"><?= _h('panel.prem.api_generate') ?></span>
			</button>
		</div>

	<?php elseif ($subTab === 'ads'): ?>
		<!-- Faza 8: everything the advertising feature stores globally. Creatives, zones,
		     packages and the queue live under the "Reklamy" tab; this is the switchboard. -->
		<div class="settings-section">
			<h3><i class="fa-solid fa-rectangle-ad"></i> <?= _h('panel.ads.settings_title') ?></h3>
			<p style="color: var(--text-secondary); margin: 0 0 18px;"><?= _h('panel.ads.settings_intro') ?></p>
			<div id="adsSettingsMessage" class="auth-message"></div>

			<div class="form-group">
				<label class="form-check">
					<input type="checkbox" id="adsEnabled"><span><?= _h('panel.ads.enabled') ?></span>
				</label>
				<small><?= _h('panel.ads.enabled_hint') ?></small>
			</div>
			<div class="form-group">
				<label class="form-check">
					<input type="checkbox" id="adsAdminPreview"><span><?= _h('panel.ads.admin_preview') ?></span>
				</label>
				<small><?= _h('panel.ads.admin_preview_hint') ?></small>
			</div>

			<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-chart-simple"></i> <?= _h('panel.ads.tracking') ?></h4>
			<div class="form-group">
				<label class="form-check"><input type="checkbox" id="adsTrackImpressions"><span><?= _h('panel.ads.track_impressions') ?></span></label>
			</div>
			<div class="form-group">
				<label class="form-check"><input type="checkbox" id="adsTrackClicks"><span><?= _h('panel.ads.track_clicks') ?></span></label>
			</div>

			<h4 style="margin: 20px 0 10px;"><i class="fa-brands fa-google"></i> <?= _h('panel.ads.adsense') ?></h4>
			<div class="form-group">
				<label><?= _h('panel.ads.adsense_client') ?></label>
				<input type="text" id="adsAdsenseClient" maxlength="30" placeholder="ca-pub-0000000000000000">
				<small><?= _h('panel.ads.adsense_client_hint') ?></small>
			</div>
			<div class="form-group">
				<label class="form-check"><input type="checkbox" id="adsAdsenseAuto"><span><?= _h('panel.ads.adsense_auto') ?></span></label>
				<small><?= _h('panel.ads.adsense_auto_hint') ?></small>
			</div>
			<div class="form-group">
				<label><?= _h('panel.ads.consent_mode') ?></label>
				<select id="adsConsentMode" class="input" style="max-width:420px;">
					<option value="off"><?= _h('panel.ads.consent_mode_off') ?></option>
					<option value="bar"><?= _h('panel.ads.consent_mode_bar') ?></option>
					<option value="google"><?= _h('panel.ads.consent_mode_google') ?></option>
				</select>
				<small><?= _h('panel.ads.consent_mode_hint') ?></small>
			</div>
			<p class="prem-csp-note">
				<i class="fa-solid fa-triangle-exclamation"></i> <?= _h('panel.ads.csp_note') ?>
			</p>
			<p class="prem-csp-note">
				<i class="fa-solid fa-circle-info"></i> <?= _h('panel.ads.consent_note') ?>
			</p>

			<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-cart-shopping"></i> <?= _h('panel.ads.selling') ?></h4>
			<div class="form-group">
				<label class="form-check"><input type="checkbox" id="adsSellingEnabled"><span><?= _h('panel.ads.selling_enabled') ?></span></label>
				<small><?= _h('panel.ads.selling_hint') ?></small>
			</div>
			<div class="form-group">
				<label><?= _h('panel.ads.warn_days') ?></label>
				<input type="number" id="adsWarnDays" min="0" max="30" style="max-width:120px;">
				<small><?= _h('panel.ads.warn_days_hint') ?></small>
			</div>

			<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-file-invoice"></i> <?= _h('panel.prem.invoice_section') ?></h4>
			<p class="prem-csp-note"><i class="fa-solid fa-link"></i> <?= _h('panel.prem.invoice_shared_hint') ?></p>
			<div class="form-group">
				<label class="form-check"><input type="checkbox" id="adsInvoiceEnabled"><span><?= _h('panel.prem.invoice_enabled') ?></span></label>
				<small><?= _h('panel.prem.invoice_enabled_hint') ?></small>
			</div>
			<div class="form-group">
				<label><?= _h('panel.prem.invoice_seller') ?></label>
				<textarea id="adsInvoiceSeller" rows="3" class="auth-input" placeholder="<?= _h('panel.prem.invoice_seller_ph') ?>"></textarea>
			</div>
			<div class="form-group">
				<label><?= _h('panel.prem.invoice_prefix') ?></label>
				<input type="text" id="adsInvoicePrefix" maxlength="12" placeholder="FH" style="max-width:140px;">
			</div>
			<div class="form-group">
				<label><?= _h('panel.prem.invoice_footer') ?></label>
				<textarea id="adsInvoiceFooter" rows="2" class="auth-input" placeholder="<?= _h('panel.prem.invoice_footer_ph') ?>"></textarea>
			</div>

			<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-sliders"></i> <?= _h('panel.ads.limits') ?></h4>
			<div class="form-group">
				<label><?= _h('panel.ads.max_banner') ?></label>
				<div class="flt-size-pair" style="max-width:220px;">
					<input type="number" id="adsMaxBanner" min="1" step="1">
					<select id="adsMaxBannerUnit" class="input">
						<option value="KB">KiB</option>
						<option value="MB">MiB</option>
					</select>
				</div>
				<small><?= _h('panel.ads.max_banner_hint') ?></small>
			</div>
			<div class="form-group">
				<label><?= _h('panel.ads.zone_max') ?></label>
				<input type="number" id="adsZoneMax" min="0" max="100" style="max-width:120px;">
				<small><?= _h('panel.ads.zone_max_hint') ?></small>
			</div>
			<div class="flt-size-row">
				<div class="form-group">
					<label><?= _h('panel.ads.grace_days') ?></label>
					<input type="number" id="adsGraceDays" min="0" max="365" style="max-width:120px;">
					<small><?= _h('panel.ads.grace_days_hint') ?></small>
				</div>
				<div class="form-group">
					<label><?= _h('panel.ads.review_comp') ?></label>
					<input type="number" id="adsReviewComp" min="0" max="30" style="max-width:120px;">
					<small><?= _h('panel.ads.review_comp_hint') ?></small>
				</div>
			</div>

			<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-location-dot"></i> <?= _h('panel.ads.zones_toggle') ?></h4>
			<p style="color: var(--text-secondary); margin: 0 0 10px;"><?= _h('panel.ads.zones_toggle_hint') ?> <?= _h('panel.ads.zone_dims_hint') ?></p>
			<div class="ads-zone-config" id="adsZonesList">
				<?php foreach (AdRenderer::ZONES as $zoneId => $zoneMeta): ?>
					<div class="ads-zone-row">
						<label class="perm-item" style="flex:1; min-width:0;">
							<input type="checkbox" class="ads-zone-check" value="<?= $zoneId ?>">
							<span><?= _h(AdRenderer::PAGES[$zoneMeta['page']]) ?> · <?= _h($zoneMeta['label']) ?></span>
						</label>
						<span class="ads-zone-dims">
							<input type="number" class="ads-zone-w" data-zone="<?= $zoneId ?>" min="100" max="2000"
								placeholder="<?= (int) $zoneMeta['w'] ?>" title="px">
							×
							<input type="number" class="ads-zone-h" data-zone="<?= $zoneId ?>" min="40" max="1200"
								placeholder="<?= (int) $zoneMeta['h'] ?>" title="px">
							px
						</span>
					</div>
				<?php endforeach; ?>
			</div>

			<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-envelope-open-text"></i> <?= _h('panel.ads.contact') ?></h4>
			<div class="form-group">
				<textarea id="adsContact" rows="3" class="auth-input" placeholder="<?= _h('panel.ads.contact_ph') ?>"></textarea>
				<small><?= _h('panel.ads.contact_hint') ?></small>
			</div>

			<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-file-lines"></i> ads.txt</h4>
			<div class="form-group">
				<label><?= _h('panel.ads.adstxt') ?></label>
				<textarea id="adsTxtContent" rows="3" class="auth-input" placeholder="google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0"></textarea>
				<small><?= _h('panel.ads.adstxt_hint') ?> <code><?= htmlspecialchars(APP_URL) ?>/ads.txt</code></small>
			</div>

			<h4 style="margin: 20px 0 10px;"><i class="fa-solid fa-shield-halved"></i> <?= _h('panel.ads.csp_extra') ?></h4>
			<div class="form-group">
				<textarea id="adsCspExtra" rows="2" class="auth-input" placeholder="https://cdn.example-ads.com https://*.example-ads.com"></textarea>
				<small><?= _h('panel.ads.csp_extra_hint') ?></small>
			</div>

			<p class="prem-csp-note">
				<i class="fa-solid fa-user-shield"></i> <?= __('panel.ads.exempt_note') ?>
				<a href="?tab=settings&stab=groups"><?= _h('panel.users.groups') ?></a>
			</p>

			<div style="margin-top: 20px; display:flex; justify-content:center;">
				<button type="button" class="btn btn-primary" style="padding: 12px 28px;" data-fh-click="saveAdsSettings(this)"><i class="fa-solid fa-floppy-disk"></i> <?= _h('common.save') ?></button>
			</div>
		</div>

	<?php elseif ($subTab === 'system'): ?>
		<?php
		// B6: admin password change lives only on the Account tab now (unified UX with a
		// strength meter). This tab shows read-only system/overview info for the admin.
		$sysUsers = 0;
		try {
			$sysUsers = (int) Database::getInstance()->query('SELECT COUNT(*) FROM `' . Database::table('users') . '`')->fetchColumn();
		} catch (Throwable $e) {
		}
		$sysMaintenance = (($settings['maintenance_mode'] ?? '0') === '1');
		$sysRetention = (int) ($settings['audit_retention_days'] ?? 30);
		?>
		<div class="settings-section">
			<h3><i class="fa-solid fa-chart-simple"></i> <?= _h('panel.set.overview') ?></h3>
			<div class="stats">
				<div class="stat"><h3><?= number_format($sysUsers) ?></h3><p><?= _h('panel.set.stat_users') ?></p></div>
				<div class="stat"><h3><?= number_format((int) ($stats['total_files'] ?? 0)) ?></h3><p><?= _h('panel.set.stat_files') ?></p></div>
				<div class="stat"><h3><?= formatSize($stats['total_size'] ?? 0) ?></h3><p><?= _h('panel.set.stat_storage') ?></p></div>
				<div class="stat"><h3><?= number_format((int) ($stats['total_downloads'] ?? 0)) ?></h3><p><?= _h('panel.set.stat_downloads') ?></p></div>
			</div>
		</div>

		<div class="settings-section">
			<h3><i class="fa-solid fa-circle-info"></i> <?= _h('panel.set.sysinfo') ?></h3>
			<div style="background: var(--bg-secondary); padding: 16px; border-radius: 8px;">
				<p style="color: var(--text-secondary); line-height: 1.8; margin: 0;">
					<strong><?= _h('panel.set.version') ?>:</strong> <?= APP_VERSION ?><br>
					<strong><?= _h('panel.set.php_version') ?>:</strong> <?= phpversion() ?><br>
					<strong><?= _h('panel.set.server') ?>:</strong> <?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? __('panel.set.unknown')) ?><br>
					<strong><?= _h('panel.set.db_host') ?>:</strong> <?= htmlspecialchars(DB_HOST) ?><br>
					<strong><?= _h('panel.set.uploads_path') ?>:</strong> <?= htmlspecialchars(UPLOADS_DIR) ?><br>
					<strong><?= _h('panel.set.maintenance') ?>:</strong>
					<?php if ($sysMaintenance): ?>
						<span class="badge badge-danger"><?= _h('panel.set.maint_on') ?></span>
					<?php else: ?>
						<span class="badge badge-success"><?= _h('panel.set.maint_off') ?></span>
					<?php endif; ?><br>
					<strong><?= _h('panel.audit.title') ?>:</strong>
					<?= $sysRetention > 0 ? __('panel.set.audit_retention_info', ['n' => $sysRetention]) : _h('panel.set.audit_retention_off') ?>
				</p>
			</div>
		</div>

		<?php
		/**
		 * Housekeeping without a system cron.
		 *
		 * `scripts/cleanup.php` has to run on a schedule for retention, storage limits, payment
		 * reconciliation and the expiry warnings to happen at all — and it is exactly the step
		 * an operator forgets, because nothing complains when it is missing. The upload server
		 * is already a long-running process on the same machine, so it can hold the timer and
		 * run the very same script a cron entry would.
		 */
		$cronOn = (($settings['cron_enabled'] ?? '0') === '1');
		$cronLast = (int) ($settings['cron_last_run'] ?? 0);
		?>
		<div class="settings-section">
			<h3><i class="fa-solid fa-clock-rotate-left"></i> <?= _h('panel.set.cron_title') ?></h3>
			<p style="color: var(--text-secondary); margin: 0 0 14px;"><?= __('panel.set.cron_intro') ?></p>
			<form method="POST">
				<input type="hidden" name="_csrf" value="<?= $csrf ?>">
				<input type="hidden" name="action" value="save_cron">
				<div class="form-group">
					<label class="form-check">
						<input type="checkbox" name="cron_enabled" value="1" <?= $cronOn ? 'checked' : '' ?>>
						<span><?= _h('panel.set.cron_enabled') ?></span>
					</label>
					<small><?= _h('panel.set.cron_enabled_hint') ?></small>
				</div>
				<div class="form-row">
					<div class="form-group">
						<label><?= _h('panel.set.cron_interval') ?></label>
						<input type="number" name="cron_interval" min="1" max="1440"
							value="<?= (int) ($settings['cron_interval'] ?? 15) ?>">
						<small><?= _h('panel.set.cron_interval_hint') ?></small>
					</div>
					<div class="form-group">
						<label><?= _h('panel.set.cron_php') ?></label>
						<input type="text" name="cron_php_binary" placeholder="<?= htmlspecialchars(PHP_BINARY) ?>"
							value="<?= htmlspecialchars((string) ($settings['cron_php_binary'] ?? '')) ?>">
						<small><?= _h('panel.set.cron_php_hint') ?></small>
					</div>
				</div>
				<div style="margin-top:20px; display:flex; justify-content:center;">
					<button type="submit" class="btn btn-primary" style="padding:12px 28px;">
						<i class="fa-solid fa-floppy-disk"></i> <?= _h('common.save') ?>
					</button>
				</div>
			</form>
			<p style="color: var(--text-muted); font-size: 0.9rem; margin: 14px 0 0;">
				<i class="fa-solid fa-<?= $cronLast ? 'circle-check' : 'circle-info' ?>"></i>
				<?= $cronLast
					? __('panel.set.cron_last', ['when' => date('d.m.Y H:i', $cronLast)])
					: _h('panel.set.cron_never') ?>
			</p>
		</div>

		<div class="settings-section">
			<p style="color: var(--text-muted); font-size: 0.9rem; margin: 0;">
				<i class="fa-solid fa-circle-info"></i>
				<?= __('panel.set.pw_moved', ['tab' => '<strong>' . _h('panel.nav.account') . '</strong>']) ?>
			</p>
		</div>

	<?php else: ?>
		<form method="POST" id="settingsForm">
			<input type="hidden" name="_csrf" value="<?= $csrf ?>">
			<input type="hidden" name="action" value="save_settings">
			<input type="hidden" name="setting_group" value="<?= htmlspecialchars($subTab) ?>">

			<?php if ($subTab === 'general'): ?>
				<div class="settings-section">
					<h3><i class="fa-solid fa-tag"></i> <?= _h('panel.set.identity') ?></h3>
					<div class="form-group">
						<label><?= _h('panel.set.app_name') ?></label>
						<input type="text" name="app_name" value="<?= htmlspecialchars($settings['app_name'] ?? (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files')) ?>" placeholder="TryHackX Files">
						<small><?= _h('panel.set.app_name_hint') ?></small>
					</div>
					<div class="form-group">
						<label><?= _h('panel.set.language') ?></label>
						<select name="default_language" class="input">
							<?php foreach (Lang::available() as $code => $label): ?>
								<option value="<?= htmlspecialchars($code) ?>" <?= ($settings['default_language'] ?? 'pl') === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
							<?php endforeach; ?>
						</select>
						<small><?= _h('panel.set.language_hint') ?></small>
					</div>
				</div>
				<div class="settings-section">
					<h3><i class="fa-solid fa-triangle-exclamation"></i> <?= _h('panel.set.maintenance') ?></h3>
					<div class="form-group">
						<label class="form-check" style="padding: 12px 14px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center;">
							<input type="checkbox" name="maintenance_mode" style="width: 18px; height: 18px; accent-color: var(--accent);" <?= ($settings['maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
							<div style="margin-left: 12px; display: flex; flex-direction: column;">
								<span style="font-weight: 500; font-size: 0.95rem; color: var(--text);"><?= _h('panel.set.maintenance_on') ?></span>
								<small style="margin: 2px 0 0 0; font-size: 0.85rem; color: var(--text-muted);"><?= _h('panel.set.maintenance_hint') ?></small>
							</div>
						</label>
					</div>
					<div class="form-group">
						<label><?= _h('panel.set.maintenance_msg') ?></label>
						<input type="text" name="maintenance_message" value="<?= htmlspecialchars($settings['maintenance_message'] ?? '') ?>" placeholder="<?= _h('panel.set.maintenance_msg_ph') ?>">
						<small><?= _h('panel.set.maintenance_msg_hint') ?></small>
					</div>
				</div>
				<div class="settings-section">
					<h3><i class="fa-solid fa-scroll"></i> <?= _h('panel.audit.title') ?></h3>
					<div class="form-group">
						<label><?= _h('panel.set.audit_retention') ?></label>
						<input type="number" name="audit_retention_days" value="<?= (int) ($settings['audit_retention_days'] ?? 30) ?>" min="0">
						<small><?= _h('panel.set.audit_retention_hint') ?></small>
					</div>
				</div>

			<?php elseif ($subTab === 'storage'): ?>
				<?php
				// Upload location + a live check of everything the app needs from that path.
				// The value lives in config/config.local.php (the one place PHP *and* the Python
				// upload server both read), so saving it here rewrites that constant.
				$upDir = UPLOADS_DIR;
				$upLocked = defined('UPLOADS_PATH_LOCKED') && UPLOADS_PATH_LOCKED;
				$upExists = @is_dir($upDir);
				$upWritable = $upExists && @is_writable($upDir);
				$upReadable = $upExists && @is_readable($upDir);
				$probe = null;
				if ($upWritable) {
					$probeFile = rtrim($upDir, '/\\') . '/.perm-check-' . bin2hex(random_bytes(4));
					$probe = @file_put_contents($probeFile, 'x') !== false;
					if ($probe) {
						@unlink($probeFile);
					}
				}
				$upFree = $upExists ? @disk_free_space($upDir) : false;
				$upTotal = $upExists ? @disk_total_space($upDir) : false;
				$okIcon = '<span class="badge badge-success"><i class="fa-solid fa-check"></i> ' . _h('panel.set.path_ok') . '</span>';
				$badIcon = '<span class="badge badge-danger"><i class="fa-solid fa-xmark"></i> ' . _h('panel.set.path_bad') . '</span>';
				?>
				<div class="settings-section">
					<h3><i class="fa-solid fa-folder-tree"></i> <?= _h('panel.set.uploads_location') ?></h3>
					<div class="form-group">
						<label><?= _h('panel.set.uploads_path') ?></label>
						<input type="text" name="uploads_path" value="<?= htmlspecialchars($upDir) ?>"
							placeholder="<?= htmlspecialchars(PROJECT_ROOT . '/uploads') ?>" <?= $upLocked ? 'readonly' : '' ?>>
						<small>
							<?= _h('panel.set.uploads_path_hint') ?>
							<?php if ($upLocked): ?>
								<br><strong><i class="fa-solid fa-lock"></i> <?= _h('panel.set.uploads_path_locked') ?></strong>
							<?php endif; ?>
						</small>
					</div>

					<div class="table-wrap" style="margin-top:14px;">
						<table>
							<thead>
								<tr>
									<th><?= _h('panel.set.path_check') ?></th>
									<th><?= _h('panel.set.path_status') ?></th>
								</tr>
							</thead>
							<tbody>
								<tr><td><?= _h('panel.set.chk_exists') ?></td><td><?= $upExists ? $okIcon : $badIcon ?></td></tr>
								<tr><td><?= _h('panel.set.chk_readable') ?></td><td><?= $upReadable ? $okIcon : $badIcon ?></td></tr>
								<tr><td><?= _h('panel.set.chk_writable') ?></td><td><?= $upWritable ? $okIcon : $badIcon ?></td></tr>
								<tr><td><?= _h('panel.set.chk_create') ?></td><td><?= $probe ? $okIcon : $badIcon ?></td></tr>
								<tr>
									<td><?= _h('panel.set.chk_free') ?></td>
									<td>
										<?php if ($upFree !== false): ?>
											<strong><?= formatSize((int) $upFree) ?></strong>
											<?php if ($upTotal) : ?>
												<span style="color:var(--text-muted)"> / <?= formatSize((int) $upTotal) ?></span>
											<?php endif; ?>
										<?php else: ?>
											<?= $badIcon ?>
										<?php endif; ?>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<small style="display:block;margin-top:10px;"><?= __('panel.set.uploads_path_docs') ?></small>
				</div>

				<div class="settings-section">
					<h3><i class="fa-solid fa-hard-drive"></i> <?= _h('panel.set.storage_title') ?></h3>
					<div class="form-row">
						<div class="form-group">
							<label><?= _h('panel.set.folder_limit') ?></label>
							<?php
							$val = $settings['max_upload_folder_mb'] ?? 0;
							$unit = 'MB';
							if ($val > 0) {
								if ($val >= 1024 * 1024 && $val % (1024 * 1024) == 0) {
									$unit = 'TB';
									$val /= (1024 * 1024);
								} elseif ($val >= 1024 && $val % 1024 == 0) {
									$unit = 'GB';
									$val /= 1024;
								}
							}
							?>
							<div style="display: flex; gap: 8px;">
								<input type="number" name="max_upload_folder_mb" value="<?= $val ?>" min="0" step="0.1" style="flex:1">
								<select name="upload_folder_unit" class="input" style="width: 80px;">
									<option value="MB" <?= $unit === 'MB' ? 'selected' : '' ?>>MiB</option>
									<option value="GB" <?= $unit === 'GB' ? 'selected' : '' ?>>GiB</option>
									<option value="TB" <?= $unit === 'TB' ? 'selected' : '' ?>>TiB</option>
								</select>
							</div>
							<small><?= _h('panel.set.folder_limit_hint') ?></small>
						</div>
						<div class="form-group">
							<label><?= _h('panel.set.autodelete') ?></label>
							<input type="number" name="auto_delete_days" value="<?= $settings['auto_delete_days'] ?? 0 ?>" min="0">
							<small><?= _h('panel.set.autodelete_hint') ?></small>
						</div>
					</div>
					<div class="form-group">
						<label><?= _h('panel.set.quarantine_days') ?></label>
						<input type="number" name="file_quarantine_days" min="0" max="3650"
							value="<?= (int) ($settings['file_quarantine_days'] ?? 0) ?>">
						<small><?= _h('panel.set.quarantine_days_hint') ?></small>
					</div>
					<div class="form-group">
						<label><?= _h('panel.set.thumb_size') ?></label>
						<input type="number" name="thumbnail_max_px" value="<?= (int) ($settings['thumbnail_max_px'] ?? 400) ?>" min="64" max="2000" step="1">
						<small><?= _h('panel.set.thumb_size_hint') ?></small>
					</div>
				</div>
				<div class="settings-section">
					<h3><i class="fa-solid fa-ban"></i> <?= _h('panel.set.restrictions') ?></h3>
					<div class="form-group">
						<label><?= _h('panel.set.blocked_ext') ?></label>
						<textarea name="blocked_extensions" class="input" rows="3" style="font-family: monospace;" placeholder="exe, php, ..."><?= htmlspecialchars($settings['blocked_extensions'] ?? '') ?></textarea>
						<small><?= __('panel.set.blocked_ext_hint') ?></small>
					</div>
					<div class="form-group">
						<label><?= _h('panel.set.system_max') ?></label>
						<?php
						$sysVal = $settings['system_max_file_size_mb'] ?? 5120;
						$sysUnit = 'MB';
						if ($sysVal > 0) {
							if ($sysVal >= 1024 * 1024 && $sysVal % (1024 * 1024) == 0) {
								$sysUnit = 'TB';
								$sysVal /= (1024 * 1024);
							} elseif ($sysVal >= 1024 && $sysVal % 1024 == 0) {
								$sysUnit = 'GB';
								$sysVal /= 1024;
							}
						}
						?>
						<div style="display: flex; gap: 8px;">
							<input type="number" name="system_max_file_size_mb" value="<?= $sysVal ?>" min="1" step="0.1" style="flex:1">
							<select name="system_max_unit" class="input" style="width: 80px;">
								<option value="MB" <?= $sysUnit === 'MB' ? 'selected' : '' ?>>MiB</option>
								<option value="GB" <?= $sysUnit === 'GB' ? 'selected' : '' ?>>GiB</option>
								<option value="TB" <?= $sysUnit === 'TB' ? 'selected' : '' ?>>TiB</option>
							</select>
						</div>
						<small><?= _h('panel.set.system_max_hint') ?></small>
					</div>
				</div>
				<div class="settings-section">
					<h3><i class="fa-solid fa-box-archive"></i> <?= _h('panel.set.collections_title') ?></h3>
					<?php /* pt 1: both readings of "downloads" are defensible — the bytes did leave
					         the server, but nobody opened that file's page — so the operator picks.
					         Off by default: that is what every existing install already does. */ ?>
					<div class="form-group">
						<label class="form-check">
							<input type="checkbox" name="collection_counts_file_downloads" <?= ($settings['collection_counts_file_downloads'] ?? '0') === '1' ? 'checked' : '' ?>>
							<span><?= _h('panel.set.coll_counts') ?></span>
						</label>
						<small><?= _h('panel.set.coll_counts_hint') ?></small>
					</div>
				</div>

				<?php /* pt 5: "Default user storage limit" used to live here. It is gone — a
				         registered account's quota comes from its group (Settings → Grupy), with
				         the per-account field in Zarządzaj as the only override. The field here
				         had not fed anything since groups took over, so it was a setting that
				         looked authoritative and changed nothing. */ ?>

			<?php elseif ($subTab === 'security'): ?>
				<div class="settings-section">
					<h3><i class="fa-solid fa-lock"></i> <?= _h('panel.set.access') ?></h3>
					<div class="form-group">
						<label class="form-check">
							<input type="checkbox" name="registration_enabled" <?= ($settings['registration_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
							<span><?= _h('panel.set.registration') ?></span>
						</label>
					</div>
					<div class="form-group">
						<label><?= _h('panel.set.activation_mode') ?></label>
						<select name="user_activation_mode" class="input">
							<option value="auto" <?= ($settings['user_activation_mode'] ?? 'auto') === 'auto' ? 'selected' : '' ?>><?= _h('panel.set.activation_auto') ?></option>
							<option value="email" <?= ($settings['user_activation_mode'] ?? 'auto') === 'email' ? 'selected' : '' ?>><?= _h('panel.set.activation_email') ?></option>
							<option value="admin" <?= ($settings['user_activation_mode'] ?? 'auto') === 'admin' ? 'selected' : '' ?>><?= _h('panel.set.activation_admin') ?></option>
						</select>
					</div>
					<div class="form-group">
						<label><?= _h('panel.set.verify_lifetime') ?></label>
						<input type="number" name="email_verification_lifetime" value="<?= $settings['email_verification_lifetime'] ?? 24 ?>" min="1">
					</div>
					<h4 style="margin:24px 0 8px;"><?= _h('panel.set.input_limits') ?></h4>
					<p class="text-muted" style="margin:0 0 16px;"><?= _h('panel.set.input_limits_hint') ?></p>
					<div class="form-row">
						<div class="form-group">
							<label><?= _h('panel.set.username_min') ?></label>
							<input type="number" name="input_username_min" min="1" max="50" value="<?= (int) ($settings['input_username_min'] ?? InputLimits::USERNAME_MIN) ?>">
						</div>
						<div class="form-group">
							<label><?= _h('panel.set.username_max') ?></label>
							<input type="number" name="input_username_max" min="1" max="50" value="<?= (int) ($settings['input_username_max'] ?? InputLimits::USERNAME_MAX) ?>">
						</div>
						<div class="form-group">
							<label><?= _h('panel.set.email_max') ?></label>
							<input type="number" name="input_email_max" min="64" max="254" value="<?= (int) ($settings['input_email_max'] ?? InputLimits::EMAIL_MAX) ?>">
						</div>
					</div>
					<div class="form-row">
						<div class="form-group">
							<label><?= _h('panel.set.password_min') ?></label>
							<input type="number" name="input_password_min" min="8" max="72" value="<?= (int) ($settings['input_password_min'] ?? InputLimits::ACCOUNT_PASSWORD_MIN) ?>">
						</div>
						<div class="form-group">
							<label><?= _h('panel.set.password_max') ?></label>
							<input type="number" name="input_password_max" min="8" max="72" value="<?= (int) ($settings['input_password_max'] ?? InputLimits::ACCOUNT_PASSWORD_MAX) ?>">
						</div>
					</div>
					<small><?= _h('panel.set.password_max_hint') ?></small>
				</div>
				<!-- pkt C: storage is per account, so accounts are what is worth faking. All three
				     rules are off by default so an existing install does not start refusing people. -->
				<div class="settings-section">
					<h3><i class="fa-solid fa-user-shield"></i> <?= _h('panel.set.abuse') ?></h3>
					<p style="color: var(--text-secondary); margin: 0 0 16px;"><?= _h('panel.set.abuse_intro') ?></p>

					<div class="form-group">
						<label><?= _h('panel.set.domain_mode') ?></label>
						<select name="email_domain_mode" class="input">
							<?php foreach (['off', 'whitelist', 'blacklist'] as $mode): ?>
								<option value="<?= $mode ?>" <?= ($settings['email_domain_mode'] ?? 'off') === $mode ? 'selected' : '' ?>>
									<?= _h('panel.set.domain_mode_' . $mode) ?>
								</option>
							<?php endforeach; ?>
						</select>
						<small><?= _h('panel.set.domain_mode_hint') ?></small>
					</div>
					<div class="form-group">
						<label><?= _h('panel.set.domain_list') ?></label>
						<textarea name="email_domain_list" rows="3" class="auth-input"
							placeholder="gmail.com, example.org"><?= htmlspecialchars($settings['email_domain_list'] ?? '') ?></textarea>
						<small><?= _h('panel.set.domain_list_hint') ?></small>
					</div>

					<div class="form-row">
						<div class="form-group">
							<label><?= _h('panel.set.ip_limit') ?></label>
							<input type="number" name="reg_ip_limit" min="0" value="<?= (int) ($settings['reg_ip_limit'] ?? 0) ?>">
							<small><?= _h('panel.set.ip_limit_hint') ?></small>
						</div>
						<div class="form-group">
							<label><?= _h('panel.set.ip_window') ?></label>
							<input type="number" name="reg_ip_window_days" min="1" max="3650"
								value="<?= (int) ($settings['reg_ip_window_days'] ?? 90) ?>">
							<small><?= _h('panel.set.ip_window_hint') ?></small>
						</div>
					</div>

					<div class="form-group">
						<label><?= _h('panel.set.email_release') ?></label>
						<input type="number" name="email_release_days" min="0" max="3650"
							value="<?= (int) ($settings['email_release_days'] ?? 0) ?>">
						<small><?= _h('panel.set.email_release_hint') ?></small>
					</div>
				</div>

				<div class="settings-section">
					<h3><i class="fa-solid fa-hand"></i> <?= _h('panel.set.flood') ?></h3>
					<div class="form-row">
						<div class="form-group">
							<label><?= _h('panel.set.recovery_limit') ?></label>
							<input type="number" name="recovery_attempts_limit" value="<?= $settings['recovery_attempts_limit'] ?? 5 ?>" min="1">
							<small><?= _h('panel.set.recovery_limit_hint') ?></small>
						</div>
						<div class="form-group">
							<label><?= _h('panel.set.recovery_window') ?></label>
							<input type="number" name="recovery_window_hours" value="<?= $settings['recovery_window_hours'] ?? 48 ?>" min="1">
							<small><?= _h('panel.set.recovery_window_hint') ?></small>
						</div>
					</div>
				</div>
				<!-- pkt B: a group can shrink under an account (an admin moves someone, a paid plan
				     lapses), leaving files that would no longer be allowed. -->
				<div class="settings-section">
					<h3><i class="fa-solid fa-hard-drive"></i> <?= _h('panel.set.over_limit') ?></h3>
					<p style="color: var(--text-secondary); margin: 0 0 16px;"><?= _h('panel.set.over_limit_intro') ?></p>
					<div class="form-group">
						<label class="form-check">
							<input type="checkbox" name="storage_enforce" <?= ($settings['storage_enforce'] ?? '1') === '1' ? 'checked' : '' ?>>
							<span><?= _h('panel.set.storage_enforce') ?></span>
						</label>
						<small><?= _h('panel.set.storage_enforce_hint') ?></small>
					</div>
					<div class="form-group">
						<label><?= _h('panel.set.storage_grace') ?></label>
						<input type="number" name="storage_grace_days" min="0" max="365"
							value="<?= (int) ($settings['storage_grace_days'] ?? 15) ?>">
						<small><?= _h('panel.set.storage_grace_hint') ?></small>
					</div>
					<p class="prem-csp-note">
						<i class="fa-solid fa-circle-info"></i> <?= _h('panel.set.storage_other_limits') ?>
					</p>
				</div>

				<div class="settings-section">
					<h3><i class="fa-solid fa-box-archive"></i> <?= _h('panel.set.collections') ?></h3>
					<div class="form-group">
						<label class="form-check">
							<input type="checkbox" name="collection_upload_exempt" <?= ($settings['collection_upload_exempt'] ?? '1') === '1' ? 'checked' : '' ?>>
							<span><?= _h('panel.set.coll_exempt') ?></span>
						</label>
						<small><?= _h('panel.set.coll_exempt_hint') ?></small>
					</div>
					<div class="form-group">
						<label><?= _h('panel.set.coll_protected_policy') ?></label>
						<select name="collection_protected_file_policy" class="input">
							<?php $collectionProtectedPolicy = $settings['collection_protected_file_policy'] ?? 'prompt_skip'; ?>
							<option value="prompt_skip" <?= $collectionProtectedPolicy === 'prompt_skip' ? 'selected' : '' ?>><?= _h('panel.set.coll_policy_prompt') ?></option>
							<option value="remember_access" <?= $collectionProtectedPolicy === 'remember_access' ? 'selected' : '' ?>><?= _h('panel.set.coll_policy_remember') ?></option>
							<option value="require_collection_password" <?= $collectionProtectedPolicy === 'require_collection_password' ? 'selected' : '' ?>><?= _h('panel.set.coll_policy_require') ?></option>
						</select>
						<small><?= _h('panel.set.coll_protected_policy_hint') ?></small>
					</div>
				</div>
				<div class="settings-section">
					<h3><i class="fa-solid fa-robot"></i> <?= _h('panel.set.recaptcha') ?></h3>
					<p style="color: var(--text-secondary); margin-bottom: 16px; font-size: 0.9rem;">
						<?= __('panel.set.recaptcha_intro', ['url' => 'https://www.google.com/recaptcha/admin']) ?>
					</p>
					<div class="form-group">
						<label class="form-check">
							<input type="checkbox" name="recaptcha_enabled" id="recaptchaEnabled" data-fh-change="toggleRecaptchaFields()" <?= ($settings['recaptcha_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
							<span><?= _h('panel.set.recaptcha_on') ?></span>
						</label>
					</div>
					<div id="recaptchaFields" style="<?= ($settings['recaptcha_enabled'] ?? '0') !== '1' ? 'display:none' : '' ?>">
						<div class="form-row">
							<div class="form-group">
								<label><?= _h('panel.set.site_key') ?></label>
								<input type="text" name="recaptcha_site_key" value="<?= htmlspecialchars($settings['recaptcha_site_key'] ?? '') ?>">
							</div>
							<div class="form-group">
								<label><?= _h('panel.set.secret_key') ?></label>
								<input type="password" name="recaptcha_secret_key" value="" maxlength="1024" autocomplete="new-password">
							</div>
						</div>
						<hr style="border-color: var(--border); margin: 24px 0;">
						<h4 style="margin: 24px 0 16px 0;"><?= _h('panel.set.session_settings') ?></h4>
						<div class="form-row">
							<div class="form-group">
								<label><?= _h('panel.set.token_lifetime') ?></label>
								<input type="number" name="recaptcha_token_lifetime" value="<?= $settings['recaptcha_token_lifetime'] ?? 120 ?>" min="1">
							</div>
							<div class="form-group">
								<label><?= _h('panel.set.max_files_session_guest') ?></label>
								<input type="number" name="recaptcha_max_files_per_session_guest" value="<?= $settings['recaptcha_max_files_per_session_guest'] ?? 0 ?>" min="0">
								<small><?= _h('panel.set.unlimited_0') ?></small>
							</div>
							<div class="form-group">
								<label><?= _h('panel.set.max_files_session_user') ?></label>
								<input type="number" name="recaptcha_max_files_per_session_auth" value="<?= $settings['recaptcha_max_files_per_session_auth'] ?? 0 ?>" min="0">
								<small><?= _h('panel.set.unlimited_0') ?></small>
							</div>
						</div>
						<hr style="border-color: var(--border); margin: 24px 0;">
						<h4 style="margin: 24px 0 8px 0;"><?= _h('panel.set.thresholds') ?></h4>
						<p class="text-muted" style="font-size: 0.9em; margin-bottom: 16px;"><?= __('panel.set.thresholds_hint') ?></p>
						<div class="form-group recaptcha-surface-toggle">
							<label class="form-check">
								<input type="checkbox" name="recaptcha_on_admin" <?= ($settings['recaptcha_on_admin'] ?? '0') === '1' ? 'checked' : '' ?>>
								<span><?= _h('panel.set.recaptcha_login_on') ?></span>
							</label>
							<small><?= _h('panel.set.recaptcha_login_on_hint') ?></small>
						</div>
						<div class="form-row">
							<div class="form-group">
								<label><?= _h('panel.set.login_failed') ?></label>
								<input type="number" name="recaptcha_login_attempt_threshold" value="<?= $settings['recaptcha_login_attempt_threshold'] ?? 1 ?>" min="-1">
								<small><?= __('panel.set.threshold_hint') ?></small>
							</div>
							<div class="form-group">
								<label><?= _h('panel.set.delete_failed') ?></label>
								<input type="number" name="recaptcha_delete_token_threshold" value="<?= $settings['recaptcha_delete_token_threshold'] ?? 1 ?>" min="-1">
								<small><?= __('panel.set.threshold_hint') ?></small>
							</div>
							<div class="form-group">
								<label><?= _h('panel.set.file_password_failed') ?></label>
								<input type="number" name="recaptcha_file_password_threshold" value="<?= $settings['recaptcha_file_password_threshold'] ?? 3 ?>" min="-1">
								<small><?= __('panel.set.threshold_hint') ?></small>
							</div>
						</div>
						<div class="form-row">
							<div class="form-group">
								<label><?= _h('panel.set.before_download') ?></label>
								<input type="number" name="recaptcha_download_threshold" value="<?= $settings['recaptcha_download_threshold'] ?? 0 ?>" min="-1">
								<small><?= __('panel.set.before_download_hint') ?></small>
							</div>
						</div>
						<div class="form-row recaptcha-surface-grid">
							<div class="form-group">
								<label><?= _h('panel.set.reg_captcha') ?></label>
								<select name="recaptcha_register_always" class="input">
									<option value="1" <?= ($settings['recaptcha_register_always'] ?? '1') === '1' ? 'selected' : '' ?>><?= _h('panel.set.reg_always') ?></option>
									<option value="0" <?= ($settings['recaptcha_register_always'] ?? '1') === '0' ? 'selected' : '' ?>><?= _h('panel.set.reg_never') ?></option>
								</select>
							</div>
							<div class="form-group">
								<label><?= _h('panel.set.report_threshold') ?></label>
								<input type="number" name="recaptcha_report_threshold_count" value="<?= $settings['recaptcha_report_threshold_count'] ?? 5 ?>" min="-1">
								<small><?= __('panel.set.report_threshold_hint') ?></small>
							</div>
							<div class="form-group">
								<label><?= _h('panel.set.security_window') ?></label>
								<input type="number" name="recaptcha_security_window" value="<?= $settings['recaptcha_security_window'] ?? 60 ?>" min="1">
								<small><?= _h('panel.set.security_window_hint') ?></small>
							</div>
						</div>
					</div>
				</div>

			<?php elseif ($subTab === 'email'): ?>
				<div class="settings-section">
					<h3><i class="fa-solid fa-envelope"></i> <?= _h('panel.set.mail_config') ?></h3>
					<div class="form-group">
						<label><?= _h('panel.set.mail_method') ?></label>
						<select name="email_method" id="emailMethod" data-fh-change="toggleEmailFields()" class="input">
							<option value="php" <?= ($settings['email_method'] ?? 'php') === 'php' ? 'selected' : '' ?>><?= _h('panel.set.mail_php') ?></option>
							<option value="local" <?= ($settings['email_method'] ?? 'php') === 'local' ? 'selected' : '' ?>><?= _h('panel.set.mail_local') ?></option>
							<option value="smtp" <?= ($settings['email_method'] ?? 'php') === 'smtp' ? 'selected' : '' ?>><?= _h('panel.set.mail_smtp') ?></option>
						</select>
					</div>
					<div class="form-row">
						<div class="form-group">
							<label><?= _h('panel.set.mail_from') ?></label>
							<input type="email" id="emailFromFull" class="input" value="<?= htmlspecialchars($settings['email_from'] ?? '') ?>" placeholder="noreply@example.com">
							<div id="emailFromPrefixGroup" style="display:none; align-items: stretch;">
								<input type="text" id="emailFromPrefix" class="input" style="border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; text-align: right; flex: 1; min-width: 0;" placeholder="noreply">
								<div style="background: var(--bg-card); border: 1px solid var(--border); border-left: none; padding: 12px 14px; border-top-right-radius: 8px; border-bottom-right-radius: 8px; color: var(--text-muted); font-size: 0.95rem; display: flex; align-items: center; white-space: nowrap;">@<?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost') ?></div>
							</div>
							<input type="hidden" name="email_from" id="emailFromReal" value="<?= htmlspecialchars($settings['email_from'] ?? '') ?>">
						</div>
						<div class="form-group">
							<label><?= _h('panel.set.mail_from_name') ?></label>
							<input type="text" name="email_from_name" value="<?= htmlspecialchars($settings['email_from_name'] ?? (defined('APP_NAME') ? APP_NAME : (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files'))) ?>" placeholder="TryHackX Files">
						</div>
						<div class="form-group">
							<label><?= _h('panel.set.resend_cooldown') ?></label>
							<input type="number" name="email_resend_cooldown" value="<?= htmlspecialchars($settings['email_resend_cooldown'] ?? '30') ?>" min="1">
							<small><?= _h('panel.set.resend_cooldown_hint') ?></small>
						</div>
					</div>
					<div id="smtpFields" style="<?= ($settings['email_method'] ?? 'php') !== 'smtp' ? 'display:none' : '' ?>">
						<hr style="border-color: var(--border); margin: 24px 0;">
						<h4 style="margin-bottom: 20px;"><?= _h('panel.set.smtp_details') ?></h4>
						<div class="form-row">
							<div class="form-group">
								<label><?= _h('panel.set.smtp_host') ?></label>
								<input type="text" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com">
							</div>
							<div class="form-group">
								<label><?= _h('panel.set.smtp_port') ?></label>
								<input type="number" name="smtp_port" value="<?= $settings['smtp_port'] ?? 587 ?>" min="1">
							</div>
						</div>
						<div class="form-row">
							<div class="form-group">
								<label><?= _h('panel.set.smtp_user') ?></label>
								<input type="text" name="smtp_user" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>">
							</div>
							<div class="form-group">
								<label><?= _h('panel.set.smtp_pass') ?></label>
								<?php $smtpPassSet = !empty($settings['smtp_pass']); ?>
								<input type="password" name="smtp_pass" value="" maxlength="1024" autocomplete="new-password"
									placeholder="<?= $smtpPassSet ? _h('panel.set.smtp_pass_set') : _h('panel.set.smtp_pass_none') ?>">
								<small><?= _h('panel.set.smtp_pass_hint') ?></small>
							</div>
						</div>
						<div class="form-group">
							<label><?= _h('panel.set.smtp_enc') ?></label>
							<select name="smtp_encryption" class="input">
								<option value="tls" <?= ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS</option>
								<option value="ssl" <?= ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
								<option value="" <?= ($settings['smtp_encryption'] ?? '') === '' ? 'selected' : '' ?>><?= _h('panel.set.smtp_none') ?></option>
							</select>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<div style="margin-top: 20px; display: flex; justify-content: center;">
				<button type="submit" class="btn btn-primary" style="padding: 12px 28px;"><i class="fa-solid fa-floppy-disk"></i> <?= _h('panel.set.save') ?></button>
			</div>
		</form>
	<?php endif; ?>
