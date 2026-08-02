<?php
/**
 * Shared product footer. The installation name (APP_NAME) is deliberately not used here:
 * operators may customise it, while authorship and product identity must remain accurate.
 */

$footerCurrentYear = max(PRODUCT_START_YEAR, (int) date('Y'));
$footerLicenseUrl = rtrim(PRODUCT_REPOSITORY_URL, '/') . '/blob/main/LICENSING.md';
?>
<footer class="site-footer" aria-label="<?= _h('footer.project_information') ?>">
	<div class="site-footer-inner">
		<div class="site-footer-line">
			<span class="site-footer-item">&copy; <?= PRODUCT_START_YEAR ?><?php if ($footerCurrentYear > PRODUCT_START_YEAR): ?> &ndash; <?= $footerCurrentYear ?><?php endif; ?> <strong><?= htmlspecialchars(PRODUCT_NAME, ENT_QUOTES, 'UTF-8') ?></strong></span>
			<span class="site-footer-item"><span class="site-footer-separator" aria-hidden="true">&bull;</span><span><?= _h('footer.created_by') ?> <a href="<?= htmlspecialchars(PRODUCT_AUTHOR_URL, ENT_QUOTES, 'UTF-8') ?>" data-footer-contact data-contact-key="73" data-contact-bytes="42.38.39.61.40.42.61.9.61.59.48.33.40.42.34.49.103.38.59.46" aria-label="<?= _h('footer.contact_aria', ['author' => PRODUCT_AUTHOR]) ?>"><?= htmlspecialchars(PRODUCT_AUTHOR, ENT_QUOTES, 'UTF-8') ?></a></span></span>
		</div>
		<div class="site-footer-line">
			<span class="site-footer-item"><span class="site-footer-version">v<?= htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') ?></span></span>
			<span class="site-footer-item"><span class="site-footer-separator" aria-hidden="true">&bull;</span><a href="<?= htmlspecialchars(PRODUCT_REPOSITORY_URL, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">GitHub</a></span>
			<span class="site-footer-item"><span class="site-footer-separator" aria-hidden="true">&bull;</span><a href="<?= htmlspecialchars($footerLicenseUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= _h('footer.licensing') ?></a></span>
		</div>
	</div>
</footer>
<?php
unset($footerCurrentYear, $footerLicenseUrl);
?>
