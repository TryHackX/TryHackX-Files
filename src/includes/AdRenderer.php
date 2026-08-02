<?php
/**
 * AdRenderer (Faza 8) — the only place ad markup comes from.
 *
 * Public pages call `zone('home_top')` at fixed insertion points and `styles()` /
 * `scripts()` in their head / before </body>. Every entry point starts with the master
 * toggle: when `ads_enabled` is off, all three return '' and the page carries not one byte
 * of ad markup, styling or script — that is the contract the settings screen promises.
 *
 * Zones are a PHP constant, not rows: a zone is a location in a template, and a database
 * row cannot create an insertion point. An ad assigned to a zone that later disappears
 * from this list simply never renders again.
 *
 * Viewers with the `ads.exempt` group permission see nothing (the sales pitch for pricier
 * plans). Admins hold every permission implicitly, so they would be blind to their own
 * placements — instead they get a dashed placeholder naming the zone, so the layout can be
 * checked while logged in (`ads_admin_preview`).
 */
require_once __DIR__ . '/api/AdsController.php';

final class AdRenderer
{
	/**
	 * zone id => [page it lives on, i18n label, target creative dimensions]. The admin zone
	 * grid, the package editor and the ad form all read this; validation accepts nothing
	 * outside it. `w`×`h` is what uploads are cover-cropped to (see AdRepository::saveBanner)
	 * so every creative in a zone renders at the same, predictable size.
	 */
	public const ZONES = [
		'home_top'          => ['page' => 'home',       'label' => 'ads.zone.home_top',          'w' => 960, 'h' => 120],
		'home_below_hero'   => ['page' => 'home',       'label' => 'ads.zone.home_below_hero',   'w' => 960, 'h' => 200],
		'home_bottom'       => ['page' => 'home',       'label' => 'ads.zone.home_bottom',       'w' => 960, 'h' => 120],
		'download_top'      => ['page' => 'download',   'label' => 'ads.zone.download_top',      'w' => 960, 'h' => 120],
		'download_bottom'   => ['page' => 'download',   'label' => 'ads.zone.download_bottom',   'w' => 960, 'h' => 120],
		'collection_top'    => ['page' => 'collection', 'label' => 'ads.zone.collection_top',    'w' => 960, 'h' => 120],
		'collection_bottom' => ['page' => 'collection', 'label' => 'ads.zone.collection_bottom', 'w' => 960, 'h' => 120],
		'premium_top'       => ['page' => 'premium',    'label' => 'ads.zone.premium_top',       'w' => 960, 'h' => 120],
		'premium_bottom'    => ['page' => 'premium',    'label' => 'ads.zone.premium_bottom',    'w' => 960, 'h' => 120],
	];

	/**
	 * A zone's target creative box, with the operator's override applied. The ZONES const
	 * carries the defaults; the `ads_zone_dims` setting (JSON zone → [w, h]) lets the
	 * operator resize a box without touching code. Crop, forms and the zone grid all ask
	 * here, so the numbers cannot disagree.
	 *
	 * @return array{0: int, 1: int}
	 */
	public static function zoneDims(string $zone): array
	{
		$spec = self::ZONES[$zone] ?? null;
		if (!$spec) {
			return [0, 0];
		}
		static $overrides = null;
		if ($overrides === null) {
			$raw = json_decode((string) Database::getSetting('ads_zone_dims', ''), true);
			$overrides = is_array($raw) ? $raw : [];
		}
		$o = $overrides[$zone] ?? null;
		if (is_array($o) && (int) ($o[0] ?? 0) >= 100 && (int) ($o[1] ?? 0) >= 40) {
			return [(int) $o[0], (int) $o[1]];
		}
		return [(int) $spec['w'], (int) $spec['h']];
	}

	/** i18n labels for the pages the zone grid groups by. */
	public const PAGES = [
		'home'       => 'ads.page.home',
		'download'   => 'ads.page.download',
		'collection' => 'ads.page.collection',
		'premium'    => 'ads.page.premium',
	];

	/** Ad ids actually rendered on this response — decides whether scripts() emits anything. */
	private static array $rendered = [];

	/** Set when an AdSense unit rendered, so the loader script is emitted exactly once. */
	private static bool $adsenseNeeded = false;

	/** Render whatever is scheduled for a zone. Returns '' unless there is something to show. */
	public static function zone(string $zone): string
	{
		if (!AdsController::isEnabled() || !isset(self::ZONES[$zone])) {
			return '';
		}
		// A zone the operator switched off renders nothing for anyone — including the admin
		// preview, which is exactly the clutter the switch exists to remove.
		if (in_array($zone, AdsController::disabledZones(), true)) {
			return '';
		}
		if (self::viewerExempt()) {
			return self::adminPlaceholder($zone);
		}
		$ad = AdRepository::pickForZone($zone);
		if (!$ad) {
			return '';
		}
		$html = self::render($ad);
		if ($html !== '') {
			self::$rendered[] = (int) $ad['id'];
		}
		return $html;
	}

	/** The ads stylesheet link, for the page <head>. '' when the feature is off. */
	public static function styles(): string
	{
		if (!AdsController::isEnabled()) {
			return '';
		}
		if (self::viewerExempt() && Database::getSetting('ads_admin_preview', '1') !== '1') {
			return '';
		}
		return '<link rel="stylesheet" href="' . htmlspecialchars(APP_URL)
			. '/assets/css/ads.css?v=' . APP_VERSION . '">' . "\n";
	}

	/**
	 * Script tags for the end of <body>: the AdSense loader (once, only when a unit rendered
	 * or auto-ads is on) and the impression tracker (only when something trackable rendered).
	 *
	 * With `ads_consent_required` on (runda 8), the Google loader is consent-gated: Consent
	 * Mode v2 signals default to denied, a consent bar asks once, and the loader is injected
	 * only after "accept" (now or remembered). Self-sold image/html creatives are unaffected —
	 * they set no cookies, so they need no consent.
	 */
	public static function scripts(): string
	{
		if (!AdsController::isEnabled() || self::viewerExempt()) {
			return '';
		}
		$out = '';
		$client = trim((string) Database::getSetting('ads_adsense_client', ''));
		$auto = Database::getSetting('ads_adsense_auto', '0') === '1';
		if ($client !== '' && (self::$adsenseNeeded || $auto)) {
			$src = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . rawurlencode($client);
			// 'bar' = the built-in gate below; 'google' = the loader ships Google's own
			// certified CMP (Privacy & messaging), so it must load right away to ask; 'off'
			// loads immediately and asks nothing.
			if (Database::getSetting('ads_consent_mode', 'off') === 'bar') {
				$out .= self::consentGate($src);
			} else {
				$out .= '<script async src="' . htmlspecialchars($src) . '" crossorigin="anonymous"></script>' . "\n";
			}
			$out .= '<script src="' . htmlspecialchars(APP_URL)
				. '/assets/js/adsense-slots.js?v=' . APP_VERSION . '" defer></script>' . "\n";
		}
		if (self::$rendered !== [] && Database::getSetting('ads_track_impressions', '1') === '1') {
			$out .= '<script src="' . htmlspecialchars(APP_URL)
				. '/assets/js/ads.js?v=' . APP_VERSION . '" defer></script>' . "\n";
		}
		return $out;
	}

	/**
	 * The consent bar plus the script that enforces it. The choice lives in localStorage
	 * (`granted` / `denied`); until it is `granted`, Consent Mode v2 stays on its denied
	 * defaults, the Google loader is never fetched, and the AdSense slots are hidden so the
	 * page does not show empty framed boxes labelled "Reklama".
	 */
	private static function consentGate(string $loaderSrc): string
	{
		$bar = '<div class="ads-consent" id="fhAdsConsent" hidden role="dialog" aria-live="polite" data-loader-src="'
			. htmlspecialchars($loaderSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" aria-label="'
			. _h('ads.consent.text') . '">'
			. '<span class="ads-consent-text">' . _h('ads.consent.text') . '</span>'
			. '<span class="ads-consent-actions">'
			. '<button type="button" class="btn btn-sm" id="fhAdsConsentDecline">' . _h('ads.consent.decline') . '</button>'
			. '<button type="button" class="btn btn-sm btn-primary" id="fhAdsConsentAccept">' . _h('ads.consent.accept') . '</button>'
			. '</span></div>' . "\n";
		return $bar . '<script src="' . htmlspecialchars(APP_URL)
			. '/assets/js/ads-consent.js?v=' . APP_VERSION . '" defer></script>' . "\n";
	}

	/**
	 * Is this viewer excused from ads? Group permission only — guests have no group, so they
	 * are never exempt. Admins land here through the implicit all-permissions rule.
	 */
	private static function viewerExempt(): bool
	{
		return !empty($_SESSION['user_id']) && Permissions::has('ads.exempt');
	}

	/**
	 * What an exempt admin sees instead of the ad: the zone, named, so the layout can be
	 * verified. Everyone else exempt sees nothing at all.
	 */
	private static function adminPlaceholder(string $zone): string
	{
		if (empty($_SESSION['is_admin']) || Database::getSetting('ads_admin_preview', '1') !== '1') {
			return '';
		}
		return '<div class="ad-slot ad-slot--placeholder">'
			. '<span>' . _h('ads.preview_prefix') . ' ' . _h(self::ZONES[$zone]['label']) . '</span>'
			. '</div>' . "\n";
	}

	private static function render(array $ad): string
	{
		$id = (int) $ad['id'];
		$label = '<span class="ad-slot-label">' . _h('ads.label') . '</span>';
		$impressionToken = AdsController::issueEventToken($id, 'impression');
		$trackingAttrs = ' data-ad-id="' . $id . '" data-ad-impression="'
			. htmlspecialchars($impressionToken) . '"';

		switch ($ad['type']) {
			case 'image':
				$src = !empty($ad['image_path'])
					? AdRepository::bannerUrl($id)
					: (string) $ad['image_url'];
				if ($src === '') {
					return '';
				}
				$img = '<img src="' . htmlspecialchars($src) . '" alt="'
					. htmlspecialchars((string) $ad['alt_text']) . '" loading="lazy">';
				$body = trim((string) $ad['target_url']) !== ''
					// The click endpoint redirects to the ad's own stored URL — the link never
					// carries the destination, so there is nothing to tamper with.
					? '<a href="' . htmlspecialchars(APP_URL) . '/api.php?action=ad_click&id=' . $id
						. '&et=' . rawurlencode(AdsController::issueEventToken($id, 'click'))
						. '" target="_blank" rel="nofollow noopener sponsored">' . $img . '</a>'
					: $img;
				return '<div class="ad-slot ad-slot--image"' . $trackingAttrs . '>' . $label . $body . '</div>' . "\n";

			case 'html':
				// Admin-authored raw markup — the same trust checkout_html gets on the premium
				// page. Buyers can never reach this type (enforced at save).
				if (trim((string) $ad['html']) === '') {
					return '';
				}
				return '<div class="ad-slot ad-slot--html"' . $trackingAttrs . '>'
					. $label . $ad['html'] . '</div>' . "\n";

			case 'adsense':
				$client = trim((string) Database::getSetting('ads_adsense_client', ''));
				$slot = trim((string) $ad['adsense_slot']);
				if ($client === '' || $slot === '') {
					return '';
				}
				self::$adsenseNeeded = true;
				return '<div class="ad-slot ad-slot--adsense"' . $trackingAttrs . '>' . $label
					. '<ins class="adsbygoogle" style="display:block" data-ad-client="' . htmlspecialchars($client)
					. '" data-ad-slot="' . htmlspecialchars($slot)
					. '" data-ad-format="auto" data-full-width-responsive="true"></ins>'
					. '</div>' . "\n";
		}
		return '';
	}
}
