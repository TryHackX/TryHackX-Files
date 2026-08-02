<?php
/** Advertising buyer panel view. Runs in views.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
?>
	<?php /* Faza 8: the buyer's side — packages on offer, then one's own placements with
	         their status and numbers. Both lists fill over AJAX. */ ?>
	<div id="myAdsNotice">
		<?php if ($myAdsNoticeText !== ''): ?>
			<div class="alert alert-<?= $myAdsNoticeKind === 'error' ? 'error' : 'success' ?>" style="margin-bottom:16px;">
				<i class="fa-solid <?= $myAdsNoticeKind === 'error' ? 'fa-circle-exclamation' : ($myAdsNoticeKind === 'success' ? 'fa-circle-check' : 'fa-circle-info') ?>"></i>
				<?= htmlspecialchars($myAdsNoticeText) ?>
			</div>
		<?php endif; ?>
	</div>

	<?php /* Faza 8 pt 10: the operator's marketing-contact note, straight from settings. */ ?>
	<?php $adsContact = trim((string) Database::getSetting('ads_contact', '')); ?>
	<?php if ($adsContact !== ''): ?>
		<div class="settings-section myads-contact">
			<h3><i class="fa-solid fa-envelope-open-text"></i> <?= _h('panel.myads.contact_title') ?></h3>
			<div class="md-body"><?= Markdown::render($adsContact) ?></div>
		</div>
	<?php endif; ?>

	<div class="settings-section">
		<h3><i class="fa-solid fa-tags"></i> <?= _h('panel.myads.packages_title') ?></h3>
		<p style="color: var(--text-secondary); margin: 0 0 18px;"><?= _h('panel.myads.packages_intro') ?></p>
		<div class="myads-packages" id="myAdsPackages"><p class="empty"><?= _h('common.loading') ?></p></div>
	</div>

	<div class="settings-section">
		<h3><i class="fa-solid fa-rectangle-ad"></i> <?= _h('panel.myads.mine_title') ?></h3>
		<p style="color: var(--text-secondary); margin: 0 0 18px;"><?= _h('panel.myads.mine_intro') ?></p>
		<div id="myAdsList"><p class="empty"><?= _h('common.loading') ?></p></div>
	</div>

