<?php
/**
 * i18n bridge for client-side code (Faza 4.3).
 *
 * Emits the active language's strings plus a `t(key, params)` helper mirroring Lang::t(),
 * so JS translates from the same key set as PHP. Include in <head>, next to csrf_head.php.
 *
 * The strings sit in their own `application/json` block rather than inside the assignment.
 * That is what lets the live language switch (FHLang in ui.js) read the new dictionary
 * straight out of the page it fetched — one `JSON.parse` of an exact element, with no
 * scraping of script source.
 */
require_once __DIR__ . '/Lang.php';
?>
<script type="application/json" id="i18n-data"><?= json_encode(Lang::all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="<?= htmlspecialchars(
	APP_URL . '/assets/js/bootstrap.js?v=' . APP_VERSION,
	ENT_QUOTES | ENT_SUBSTITUTE,
	'UTF-8'
) ?>"></script>
