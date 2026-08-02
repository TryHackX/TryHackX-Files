<?php
/** Panel modals + toast container. Runs in panel.php scope. */
if (!defined('APP_ROOT')) {
	exit;
}
$csrf = htmlspecialchars(csrfToken(), ENT_QUOTES);

/** Placeholder markup for names JS fills in by id — keeps element ids out of the translations. */
$slot = function (string $id, string $style = 'color:var(--text)'): string {
	return '<strong id="' . $id . '" style="' . $style . '"></strong>';
};

/**
 * pt 2: a from/to size range, each bound with **its own** unit.
 *
 * One shared unit forced both ends into the same scale, so "anything between 2 MiB and 15 GiB"
 * — the shape of question people actually ask about file sizes — had to be typed as 2 and
 * 15360 MiB. The two bounds of a size range routinely live in different orders of magnitude,
 * which is exactly why the unit belongs to the bound and not to the row.
 *
 * A closure rather than three copies: the filter panel asks this question three times (files,
 * collections, my files) and they must not drift apart.
 */
/** Current banner cap, human-readable — the upload hints must quote the real setting. */
$adsMaxKb = max(64, (int) Database::getSetting('ads_max_banner_kb', 5120));
$adsMaxLabel = $adsMaxKb >= 1024
	? rtrim(rtrim(number_format($adsMaxKb / 1024, 1, '.', ''), '0'), '.') . ' MiB'
	: $adsMaxKb . ' KiB';

/**
 * Banner dropzone + crop stage (Faza 8 runda 4). One closure, two modals: the admin ad
 * form and the buyer's creative form get identical upload UX — a droppable field, then a
 * pan/zoom crop frame locked to the target zone's aspect ratio. IDs are prefixed so the
 * JS can wire each instance separately.
 */
$adUploader = function (string $prefix) use ($adsMaxLabel): string {
	return '<div class="ad-dropzone" id="' . $prefix . 'Drop" tabindex="0">'
		. '<input type="file" id="' . $prefix . 'File" accept="image/jpeg,image/png,image/webp,image/gif" hidden>'
		. '<div class="ad-dropzone-idle" id="' . $prefix . 'DropIdle">'
		. '<i class="fa-solid fa-cloud-arrow-up"></i>'
		. '<span>' . _h('panel.ads.drop_text') . '</span>'
		. '<small>' . __('panel.ads.drop_hint', ['max' => $adsMaxLabel]) . ' <span class="ad-drop-dims" id="' . $prefix . 'DropDims"></span></small>'
		. '</div>'
		. '<div class="ad-dropzone-done" id="' . $prefix . 'DropDone" style="display:none;">'
		. '<img id="' . $prefix . 'DropThumb" alt="">'
		. '<div class="ad-dropzone-done-actions">'
		. '<button type="button" class="btn btn-sm" data-fh-click="event.stopPropagation(); adUploaderRecrop(\'' . $prefix . '\')"><i class="fa-solid fa-crop-simple"></i> ' . _h('panel.ads.crop_again') . '</button>'
		. '<button type="button" class="btn btn-sm" data-fh-click="event.stopPropagation(); adCropClear(\'' . $prefix . '\')"><i class="fa-solid fa-xmark"></i> ' . _h('common.delete') . '</button>'
		. '</div>'
		. '</div>'
		. '</div>';
};

$sizeRange = function (string $prefix) {
	$units = '';
	foreach (['MB' => 'MiB', 'GB' => 'GiB', 'TB' => 'TiB'] as $value => $label) {
		$units .= '<option value="' . $value . '">' . $label . '</option>';
	}
	$bound = function (string $id, string $label) use ($prefix, $units): string {
		return '<div class="form-group"><label>' . $label . '</label>'
			. '<div class="flt-size-pair">'
			. '<input type="number" id="' . $prefix . 'Size' . $id . '" class="input" min="0" step="0.01">'
			. '<select id="' . $prefix . 'Size' . $id . 'Unit" class="input">' . $units . '</select>'
			. '</div></div>';
	};
	return '<div class="flt-size-row">'
		. $bound('Min', _h('panel.flt.min'))
		. $bound('Max', _h('panel.flt.max'))
		. '</div>';
};
?>
<?php
require __DIR__ . '/modals_core.php';
require __DIR__ . '/modals_groups.php';
require __DIR__ . '/modals_collection_create.php';
require __DIR__ . '/modals_premium.php';
require __DIR__ . '/modals_languages.php';
require __DIR__ . '/modals_recovery.php';
require __DIR__ . '/modals_files.php';
require __DIR__ . '/modals_integrations.php';
require __DIR__ . '/modals_ads.php';
require __DIR__ . '/modals_promo.php';
require __DIR__ . '/modals_toast.php';
?>
