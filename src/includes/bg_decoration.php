<?php
/**
 * The site's background layer: three drifting orbs behind a faint grid.
 *
 * Pulled out of header_ui.php so a page that needs the backdrop without the navigation —
 * the panel's re-authentication gate — renders the same thing rather than a hand-copied
 * approximation that drifts out of step the first time the palette changes.
 *
 * Styles live in assets/css/index.css (.bg-wrap, .orb, .bg-grid); the whole layer is
 * pointer-events:none and z-index:-1, so it never intercepts a click.
 */
if (!defined('APP_ROOT')) {
	exit;
}
?>
<div class="bg-wrap">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    <div class="bg-grid"></div>
</div>
