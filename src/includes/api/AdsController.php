<?php
/**
 * AdsController (Faza 8) — the advertising feature's API surface.
 *
 * Mirrors PremiumController's shape on purpose: a SETTINGS constant that is the single
 * list of what the feature stores, `isEnabled()` as the master toggle every public entry
 * point checks first, admin handlers gated by the shared session flag, and audit entries
 * on every mutation.
 *
 * Trust levels are enforced here, not in the UI: `handleMyAdSave` writes image creatives
 * only — whatever the request claims — while raw HTML and AdSense units exist solely
 * through the admin save. The renderer can then trust the row it reads.
 */
final class AdsController
{
	/** Settings that make up the advertising feature, with their defaults. */
	public const SETTINGS = [
		'ads_enabled' => '0',
		'ads_adsense_client' => '',
		'ads_adsense_auto' => '0',
		// Consent handling for AdSense (runda 8/9): 'off' = load immediately, 'bar' = the
		// built-in consent bar gates the loader (Consent Mode v2 defaults to denied),
		// 'google' = load immediately and let Google's certified CMP (Privacy & messaging,
		// configured in the AdSense account) collect the consent inside the loader.
		'ads_consent_mode' => 'off',
		'ads_track_impressions' => '1',
		'ads_track_clicks' => '1',
		'ads_txt_content' => '',
		'ads_csp_extra' => '',
		'ads_admin_preview' => '1',
		'ads_selling_enabled' => '0',
		'ads_warn_days' => '3',
		// Upload cap for banner files, in KiB (the settings UI offers KiB/MiB units).
		'ads_max_banner_kb' => '5120',
		// Max ads simultaneously live in one zone (0 = no cap). Enforced at approval and at
		// admin activation — a zone crowded with creatives dilutes every one of them.
		'ads_zone_max' => '0',
		// CSV of zone ids the operator switched off site-wide.
		'ads_zones_disabled' => '',
		// Per-zone creative box overrides, JSON zone => [w, h] (defaults in AdRenderer::ZONES).
		'ads_zone_dims' => '',
		// Markdown shown at the top of "Moje reklamy" — marketing contact, terms, anything.
		'ads_contact' => '',
		// How long an expired ad stays renewable (and keeps its files) before the cron
		// purges it from "Moje reklamy".
		'ads_grace_days' => '14',
		// A re-review of an edited live ad slower than this many days credits the whole
		// delay back to the buyer's ends_at. 0 disables the compensation.
		'ads_review_comp_days' => '3',
	];

	/** How long one session's view/click of one ad counts once, in seconds. */
	private const IMPRESSION_TTL = 1800;
	private const CLICK_TTL = 60;
	private const EVENT_TOKEN_TTL = 900;
	private const EVENT_TOKEN_LIMIT = 100;

	/**
	 * User-Agents whose views and clicks never count (runda 8). Crawlers, previews, uptime
	 * monitors, headless browsers and CLI clients inflate the numbers a buyer pays attention
	 * to; a UA match is cheap and catches the well-behaved bulk. This is damping, not
	 * fraud-proofing — the panel keeps describing the stats as orientacyjne.
	 */
	private const BOT_UA = '/bot|crawl|spider|slurp|preview|headless|lighthouse|pingdom|uptime|monitor|scan'
		. '|curl|wget|python|httpclient|okhttp|libwww|scrapy|phantomjs|selenium|playwright|puppeteer/i';

	/** Should this request's view/click be ignored for metrics? Empty UA counts as a bot. */
	private static function isLikelyBot(): bool
	{
		$ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
		return $ua === '' || preg_match(self::BOT_UA, $ua) === 1;
	}

	/** Issue one short-lived, one-use token bound to this session, creative and event type. */
	public static function issueEventToken(int $adId, string $event): string
	{
		if ($adId < 1 || !in_array($event, ['impression', 'click'], true)) {
			return '';
		}
		$now = time();
		$tokens = is_array($_SESSION['ad_event_tokens'] ?? null)
			? $_SESSION['ad_event_tokens']
			: [];
		foreach ($tokens as $hash => $entry) {
			if (!is_array($entry) || (int) ($entry['expires_at'] ?? 0) < $now) {
				unset($tokens[$hash]);
			}
		}
		while (count($tokens) >= self::EVENT_TOKEN_LIMIT) {
			array_shift($tokens);
		}
		$token = bin2hex(random_bytes(24));
		$tokens[hash('sha256', $token)] = [
			'ad_id' => $adId,
			'event' => $event,
			'expires_at' => $now + self::EVENT_TOKEN_TTL,
		];
		$_SESSION['ad_event_tokens'] = $tokens;
		return $token;
	}

	/** @return int 0 invalid, 1 freshly consumed, 2 valid click-token replay */
	private static function consumeEventToken(string $token, int $adId, string $event): int
	{
		if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
			return 0;
		}
		$tokens = is_array($_SESSION['ad_event_tokens'] ?? null)
			? $_SESSION['ad_event_tokens']
			: [];
		$key = hash('sha256', $token);
		$entry = $tokens[$key] ?? null;
		if (
			!is_array($entry)
			|| (int) ($entry['expires_at'] ?? 0) < time()
			|| (int) ($entry['ad_id'] ?? 0) !== $adId
			|| (string) ($entry['event'] ?? '') !== $event
		) {
			unset($tokens[$key]);
			$_SESSION['ad_event_tokens'] = $tokens;
			return 0;
		}
		if ($event === 'click') {
			$replay = !empty($entry['consumed']);
			$entry['consumed'] = true;
			$tokens[$key] = $entry;
			$_SESSION['ad_event_tokens'] = $tokens;
			return $replay ? 2 : 1;
		}
		unset($tokens[$key]);
		$_SESSION['ad_event_tokens'] = $tokens;
		return 1;
	}

	private static function isPubliclyActiveAd(array $ad): bool
	{
		$now = time();
		return self::isEnabled()
			&& ($ad['status'] ?? '') === 'active'
			&& empty($ad['self_paused'])
			&& (empty($ad['starts_at']) || (int) $ad['starts_at'] <= $now)
			&& (empty($ad['ends_at']) || (int) $ad['ends_at'] > $now);
	}

	public static function settings(): array
	{
		$out = [];
		foreach (self::SETTINGS as $key => $default) {
			$out[$key] = (string) Database::getSetting($key, $default);
		}
		return $out;
	}

	public static function isEnabled(): bool
	{
		return Database::getSetting('ads_enabled', '0') === '1';
	}

	public static function sellingEnabled(): bool
	{
		return self::isEnabled() && Database::getSetting('ads_selling_enabled', '0') === '1';
	}

	/** Zone ids the operator disabled site-wide. */
	public static function disabledZones(): array
	{
		return array_values(array_filter(array_map('trim',
			explode(',', (string) Database::getSetting('ads_zones_disabled', '')))));
	}

	/** The zone catalogue with labels resolved, as the panel forms need it. */
	public static function zoneCatalog(): array
	{
		$disabled = self::disabledZones();
		$out = [];
		foreach (AdRenderer::ZONES as $id => $meta) {
			[$w, $h] = AdRenderer::zoneDims($id);
			$out[] = [
				'id' => $id,
				'page' => $meta['page'],
				'pageLabel' => __(AdRenderer::PAGES[$meta['page']]),
				'label' => __($meta['label']),
				'dims' => $w ? $w . '×' . $h : '',
				'w' => $w,
				'h' => $h,
				'defaultW' => (int) $meta['w'],
				'defaultH' => (int) $meta['h'],
				'disabled' => in_array($id, $disabled, true),
			];
		}
		return $out;
	}

	/* ---------------- Settings ---------------- */

	/** Save the advertising settings block (AJAX JSON, the premium settings pattern). */
	public static function handleAdminAdsSettings()
	{
		if (!self::requireAdmin()) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		PremiumController::saveInvoiceSettings($input);

		// The AdSense client id gates a CSP relaxation, so a malformed one is refused rather
		// than stored: whatever ends up in this setting will be echoed into script markup.
		if (array_key_exists('ads_adsense_client', $input)) {
			$client = trim((string) $input['ads_adsense_client']);
			if ($client !== '' && !preg_match('/^ca-pub-\d{6,20}$/', $client)) {
				echo json_encode(['success' => false, 'error' => __('ads.err_adsense_client')]);
				return;
			}
			$input['ads_adsense_client'] = $client;
		}
		if (array_key_exists('ads_csp_extra', $input)) {
			$input['ads_csp_extra'] = self::sanitizeCspExtra((string) $input['ads_csp_extra']);
		}
		if (array_key_exists('ads_consent_mode', $input)) {
			$input['ads_consent_mode'] = in_array($input['ads_consent_mode'], ['off', 'bar', 'google'], true)
				? $input['ads_consent_mode'] : 'off';
		}
		if (array_key_exists('ads_warn_days', $input)) {
			$input['ads_warn_days'] = (string) max(0, min(30, (int) $input['ads_warn_days']));
		}
		if (array_key_exists('ads_max_banner_kb', $input)) {
			$input['ads_max_banner_kb'] = (string) max(64, min(20480, (int) $input['ads_max_banner_kb']));
		}
		if (array_key_exists('ads_zone_max', $input)) {
			$input['ads_zone_max'] = (string) max(0, min(100, (int) $input['ads_zone_max']));
		}
		if (array_key_exists('ads_grace_days', $input)) {
			$input['ads_grace_days'] = (string) max(0, min(365, (int) $input['ads_grace_days']));
		}
		if (array_key_exists('ads_review_comp_days', $input)) {
			$input['ads_review_comp_days'] = (string) max(0, min(30, (int) $input['ads_review_comp_days']));
		}
		if (array_key_exists('ads_zone_dims', $input)) {
			// Only known zones with sane boxes survive — this JSON feeds the server-side crop.
			$raw = json_decode((string) $input['ads_zone_dims'], true);
			$clean = [];
			foreach (is_array($raw) ? $raw : [] as $zone => $dims) {
				if (isset(AdRenderer::ZONES[$zone]) && is_array($dims)) {
					$w = max(100, min(2000, (int) ($dims[0] ?? 0)));
					$h = max(40, min(1200, (int) ($dims[1] ?? 0)));
					$clean[$zone] = [$w, $h];
				}
			}
			$input['ads_zone_dims'] = $clean ? json_encode($clean) : '';
		}
		if (array_key_exists('ads_zones_disabled', $input)) {
			// Only known zone ids survive — this string feeds the render kill-switch.
			$known = array_keys(AdRenderer::ZONES);
			$ids = array_filter(array_map('trim', explode(',', (string) $input['ads_zones_disabled'])));
			$input['ads_zones_disabled'] = implode(',', array_values(array_intersect($ids, $known)));
		}

		foreach (self::SETTINGS as $key => $default) {
			if (!array_key_exists($key, $input)) {
				continue;
			}
			$value = $input[$key];
			Database::setSetting($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
		}
		// Auto-ads toggling changes whether the CSP must admit Google without any ad row
		// changing, so the denormalized flag is recomputed on every settings save.
		AdRepository::refreshAdsenseActive();
		Database::logAudit('ads_settings_saved', '');
		echo json_encode(['success' => true, 'settings' => self::settings() + PremiumController::invoiceSettings()]);
	}

	/**
	 * Only https origins survive; anything that could smuggle a directive or a scheme into
	 * the CSP header is dropped token by token.
	 */
	private static function sanitizeCspExtra(string $raw): string
	{
		$kept = [];
		foreach (preg_split('/\s+/', trim($raw)) ?: [] as $token) {
			if ($token !== '' && preg_match('~^https://[a-z0-9*][a-z0-9.*-]*(:\d{1,5})?$~i', $token)) {
				$kept[] = $token;
			}
		}
		return implode(' ', array_unique($kept));
	}

	/* ---------------- Admin: creatives ---------------- */

	public static function handleAdminAds()
	{
		// Read-only list + zone catalogue: every ads-manager audience needs it for labels.
		if (!self::requireAnyPerm(['ads.manage', 'ads.approve', 'ads.metrics', 'ads.packages'])) {
			return;
		}
		$out = [
			'success' => true,
			'ads' => array_map([self::class, 'adminView'], AdRepository::all()),
			'zones' => self::zoneCatalog(),
			'pendingCount' => AdRepository::pendingCount(),
		];
		// The raw settings block (CSP extra, ads.txt, AdSense client) is operator
		// configuration — metrics- or queue-only delegates get the lists, not the switchboard.
		if (Permissions::has('ads.manage')) {
			$out['settings'] = self::settings() + PremiumController::invoiceSettings();
		}
		echo json_encode($out);
	}

	public static function handleAdminAdSave()
	{
		if (!self::requirePerm('ads.manage')) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (int) ($input['id'] ?? 0);

		$data = self::validateCreative($input, true);
		if (isset($data['error'])) {
			echo json_encode(['success' => false, 'error' => $data['error']]);
			return;
		}
		if (($data['status'] ?? '') === 'active' && !self::zoneHasRoom((string) $data['zone'], $id)) {
			echo json_encode(['success' => false, 'error' => __('ads.err_zone_full')]);
			return;
		}
		// The admin form edits operator placements; a buyer's ad keeps its owner, package and
		// lifecycle and is managed through the queue actions instead.
		if ($id) {
			$existing = AdRepository::get($id);
			if (!$existing) {
				echo json_encode(['success' => false, 'error' => __('ads.err_not_found')]);
				return;
			}
			if (!empty($existing['owner_id'])) {
				// Content-only edit (runda 8): everything the buyer's package fixed is re-pinned
				// to the stored row, whatever the form sent. PINNED, not unset — save() writes
				// every column, so a missing status used to default to 'paused' and an admin
				// touching an active purchase silently took it off the air.
				foreach (['type', 'zone', 'status', 'weight', 'starts_at', 'ends_at', 'html', 'adsense_slot'] as $fixed) {
					$data[$fixed] = $existing[$fixed] ?? null;
				}
			}
		}
		$res = AdRepository::save($id ?: null, $data);
		if (!empty($res['success'])) {
			Database::logAudit($id ? 'ad_updated' : 'ad_created', (string) ($data['name'] ?? ''));
		}
		echo json_encode($res);
	}

	public static function handleAdminAdDelete()
	{
		if (!self::requirePerm('ads.manage')) {
			return;
		}
		if (!requireRecentAuthentication()) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (int) ($input['id'] ?? 0);
		if (!$id || !AdRepository::delete($id)) {
			echo json_encode(['success' => false, 'error' => __('ads.err_not_found')]);
			return;
		}
		Database::logAudit('ad_deleted', '#' . $id);
		echo json_encode(['success' => true]);
	}

	/** approve / reject / pause / resume — the queue and list actions. */
	public static function handleAdminAdAction()
	{
		// Reviewing the queue and running the creatives are separate mandates: the entry
		// gate takes either, each action re-checks the one it actually needs.
		if (!self::requireAnyPerm(['ads.approve', 'ads.manage'])) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (int) ($input['id'] ?? 0);
		$action = (string) ($input['do'] ?? '');
		$needs = in_array($action, ['approve', 'reject'], true) ? 'ads.approve' : 'ads.manage';
		if (!Permissions::has($needs)) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		if ($action === 'reject' && !empty($input['refund']) && !Permissions::has('ads.refund')) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		$ad = AdRepository::get($id);
		if (!$ad) {
			echo json_encode(['success' => false, 'error' => __('ads.err_not_found')]);
			return;
		}

		// A queue card always points at the purchase's ROOT ad. Usually the root itself is
		// pending; after an addon top-up it is live and only its children await review —
		// approve/reject then applies to those children (runda 7).
		$addonTopUp = $ad['status'] !== 'pending'
			&& array_filter(AdRepository::childrenOf($id), fn($c) => $c['status'] === 'pending');

		switch ($action) {
			case 'approve':
				// The cap guards every zone the approval would fill: the root's own, and each
				// pending child's — an addon top-up fills only the latter.
				$zonesToFill = $addonTopUp ? [] : [(string) $ad['zone']];
				foreach (AdRepository::childrenOf($id) as $child) {
					if ($child['status'] === 'pending') {
						$zonesToFill[] = (string) $child['zone'];
					}
				}
				foreach ($zonesToFill as $zoneToFill) {
					if (!self::zoneHasRoom($zoneToFill, $id)) {
						echo json_encode(['success' => false, 'error' => __('ads.err_zone_full')]);
						return;
					}
				}
				$updated = $addonTopUp
					? AdRepository::approveChildren($id, (int) $_SESSION['user_id'])
					: AdRepository::approve($id, (int) $_SESSION['user_id']);
				if (!$updated) {
					echo json_encode(['success' => false, 'error' => __('ads.err_not_pending')]);
					return;
				}
				Database::logAudit('ad_approved', '#' . $id . ' ' . $ad['name'], (int) ($ad['owner_id'] ?? 0) ?: null);
				if (!empty($ad['owner_id'])) {
					Notifications::send((int) $ad['owner_id'], 'ad.approved', [
						'subject' => (string) $ad['name'],
						'data' => [
							'until' => !empty($updated['ends_at'])
								? date('d.m.Y', (int) $updated['ends_at'])
								: __('mail.plan_no_expiry'),
						],
						'link' => APP_URL . '/panel.php?tab=myads',
					]);
				}
				echo json_encode(['success' => true]);
				return;

			case 'reject':
				$reason = trim((string) ($input['reason'] ?? ''));
				$rejected = $addonTopUp
					? AdRepository::rejectChildren($id, $reason)
					: AdRepository::reject($id, $reason);
				if (!$rejected) {
					echo json_encode(['success' => false, 'error' => __('ads.err_not_pending')]);
					return;
				}
				Database::logAudit('ad_rejected', '#' . $id . ' ' . $ad['name'] . ($reason !== '' ? ' — ' . $reason : ''), (int) ($ad['owner_id'] ?? 0) ?: null);
				// Optional give-back (runda 8): the reviewer may bounce the money with the ad.
				// The refund is attempted AFTER the reject succeeded — a failed provider call
				// leaves a rejected ad and an audit trail, never a refunded-but-live one.
				$refundState = null;
				$refundError = '';
				if (!empty($input['refund'])) {
					[$refundState, $refundError] = self::refundAdPayment($id, (string) $ad['name']);
				}
				if (!empty($ad['owner_id'])) {
					Notifications::send((int) $ad['owner_id'], 'ad.rejected', [
						'subject' => (string) $ad['name'],
						'data' => ['reason' => $reason],
						'link' => APP_URL . '/panel.php?tab=myads',
					]);
				}
				echo json_encode(['success' => true, 'refund' => $refundState, 'refundError' => $refundError]);
				return;

			case 'pause':
			case 'resume':
				$from = $action === 'pause' ? 'active' : 'paused';
				if ($ad['status'] !== $from) {
					echo json_encode(['success' => false, 'error' => __('ads.err_bad_status')]);
					return;
				}
				AdRepository::setStatus($id, $action === 'pause' ? 'paused' : 'active');
				Database::logAudit($action === 'pause' ? 'ad_paused' : 'ad_resumed', '#' . $id);
				echo json_encode(['success' => true]);
				return;
		}
		echo json_encode(['success' => false, 'error' => __('ads.err_bad_action')]);
	}

	/**
	 * The approval queue with buyer, package and paid order attached. One purchase is ONE
	 * card (runda 7): child placements fold into their root instead of appearing as their
	 * own cards — per-child approve buttons were how an add-on ended up approved before its
	 * parent and inherited a NULL end date, i.e. ran forever on a 30-day purchase.
	 */
	public static function handleAdminAdQueue()
	{
		if (!self::requirePerm('ads.approve')) {
			return;
		}
		$groups = [];
		foreach (AdRepository::pending() as $row) {
			$groups[(int) (($row['parent_ad_id'] ?? null) ?: $row['id'])][] = $row;
		}
		$queue = [];
		foreach ($groups as $rootId => $members) {
			$primary = null;
			$children = [];
			foreach ($members as $m) {
				if ((int) $m['id'] === $rootId) {
					$primary = $m;
				} else {
					$children[] = $m;
				}
			}
			// Root not pending → an addon top-up: the card describes the running root, the
			// review applies to its freshly paid children.
			$addonOnly = $primary === null;
			$card = self::adminView($primary ?? (AdRepository::get($rootId) ?: []) + [
				'owner_name' => $members[0]['owner_name'] ?? '',
				'package_name' => $members[0]['package_name'] ?? '',
				'amount_minor' => $members[0]['amount_minor'] ?? null,
				'currency' => $members[0]['currency'] ?? '',
				'order_id' => $members[0]['order_id'] ?? '',
				'order_amount_minor' => $members[0]['order_amount_minor'] ?? null,
				'order_currency' => $members[0]['order_currency'] ?? '',
			]);
			$card['addonOnly'] = $addonOnly;
			$card['canRefund'] = Permissions::has('ads.refund');
			$card['children'] = array_map(fn($c) => [
				'id' => (int) $c['id'],
				'zone' => (string) $c['zone'],
				'zoneLabel' => isset(AdRenderer::ZONES[$c['zone']]) ? __(AdRenderer::ZONES[$c['zone']]['label']) : (string) $c['zone'],
				'imageUrl' => !empty($c['image_path'])
					? APP_URL . '/api.php?action=ad_banner&id=' . (int) $c['id'] : '',
			], $children);
			$queue[] = $card;
		}
		echo json_encode(['success' => true, 'queue' => $queue]);
	}

	/* ---------------- Banner upload / serving ---------------- */

	/**
	 * Multipart banner upload. Admins may set any ad's creative; a signed-in buyer only their
	 * own, and only while it is still editable (draft / pending / rejected).
	 */
	public static function handleAdUpload()
	{
		header('Content-Type: application/json');
		if (empty($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		$id = (int) ($_POST['id'] ?? 0);
		$ad = AdRepository::get($id);
		if (!$ad) {
			echo json_encode(['success' => false, 'error' => __('ads.err_not_found')]);
			return;
		}
		$isManager = Permissions::has('ads.manage');
		// The owner may (re)upload to a primary or to one of their add-on placements while
		// the purchase is still editable.
		$editableStates = ['draft', 'pending', 'rejected'];
		$editable = in_array($ad['status'], $editableStates, true);
		if (!empty($ad['parent_ad_id'])) {
			$parent = AdRepository::get((int) $ad['parent_ad_id']);
			$editable = $parent && in_array($parent['status'], $editableStates, true);
		}
		$ownsEditable = (int) ($ad['owner_id'] ?? 0) === (int) $_SESSION['user_id']
			&& $editable && Permissions::has('ads.buy');
		if (!$isManager && !$ownsEditable) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		$res = AdRepository::saveBanner($id, $_FILES['file'] ?? []);
		if (empty($res['success'])) {
			echo json_encode(['success' => false, 'error' => __('ads.err_upload_' . ($res['error'] ?? 'upload'))]);
			return;
		}
		echo json_encode(['success' => true, 'url' => APP_URL . '/api.php?action=ad_banner&id=' . $id . '&t=' . time()]);
	}

	/**
	 * Stream a stored banner. The only URL under which uploaded creatives are reachable —
	 * the files live outside the webroot.
	 */
	public static function handleAdBanner()
	{
		$ad = AdRepository::get((int) ($_GET['id'] ?? 0));
		if (!$ad || empty($ad['image_path'])) {
			http_response_code(404);
			exit;
		}
		$isPublic = self::isPubliclyActiveAd($ad)
			&& Crypto::verify(
				'ad-banner',
				(string) (int) $ad['id'],
				(string) ($_GET['sig'] ?? '')
			);
		$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
		$canPreview = $sessionUserId > 0
			&& ((int) ($ad['owner_id'] ?? 0) === $sessionUserId || Permissions::has('ads.manage'));
		if (!$isPublic && !$canPreview) {
			http_response_code(404);
			exit;
		}
		$file = AdRepository::bannerDir() . '/' . $ad['image_path'];
		if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif)$/', (string) $ad['image_path']) || !is_file($file)) {
			http_response_code(404);
			exit;
		}
		header('Content-Type: ' . ($ad['image_mime'] ?: 'application/octet-stream'));
		header('Content-Length: ' . filesize($file));
		header('Cache-Control: ' . ($isPublic ? 'public, max-age=86400, immutable' : 'private, no-store'));
		header('X-Content-Type-Options: nosniff');
		readfile($file);
		exit;
	}

	/* ---------------- Tracking ---------------- */

	/**
	 * The page's one impression beacon: `{ids: [..]}` for everything rendered. A session
	 * counts each ad once per half hour — matching the client-side throttle, but enforced
	 * here where it cannot be skipped.
	 */
	public static function handleAdTrack()
	{
		header('Content-Type: application/json');
		if (!self::isEnabled() || Database::getSetting('ads_track_impressions', '1') !== '1') {
			echo json_encode(['success' => false]);
			return;
		}
		if (self::isLikelyBot()) {
			// Answer success so the client marks the ids as sent and stays quiet — the point
			// is to not count, not to teach a crawler that retrying might.
			echo json_encode(['success' => true]);
			return;
		}
		try {
			$input = readBoundedJsonBody(65536);
		} catch (Throwable $e) {
			http_response_code($e instanceof LengthException ? 413 : 400);
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		$events = is_array($input['events'] ?? null) ? $input['events'] : [];
		if (count($events) > 10) {
			http_response_code(413);
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		$now = time();
		$seen = $_SESSION['ad_imp'] ?? [];
		foreach ($events as $event) {
			if (!is_array($event)) {
				continue;
			}
			$id = (int) ($event['id'] ?? 0);
			$token = (string) ($event['token'] ?? '');
			if ($id < 1 || self::consumeEventToken($token, $id, 'impression') !== 1) {
				continue;
			}
			$ad = AdRepository::get($id);
			if (!$ad || !self::isPubliclyActiveAd($ad)) {
				continue;
			}
			if (($seen[$id] ?? 0) > $now - self::IMPRESSION_TTL) {
				continue;
			}
			AdRepository::bumpStats($id, 1, 0);
			$seen[$id] = $now;
		}
		// Prune so a long session does not grow the array forever.
		$_SESSION['ad_imp'] = array_filter($seen, fn($t) => $t > $now - self::IMPRESSION_TTL);
		echo json_encode(['success' => true]);
	}

	/**
	 * Click-through redirect. The destination is the stored URL of the ad the id names —
	 * never a request parameter — so this cannot be turned into an open redirect.
	 */
	public static function handleAdClick()
	{
		if (!self::isEnabled()) {
			http_response_code(404);
			exit;
		}
		$ad = AdRepository::get((int) ($_GET['id'] ?? 0));
		$target = $ad ? trim((string) $ad['target_url']) : '';
		$clickTokenState = $ad
			? self::consumeEventToken(
				(string) ($_GET['et'] ?? ''),
				(int) $ad['id'],
				'click'
			)
			: 0;
		if (
			!$ad
			|| $ad['type'] !== 'image'
			|| !self::isPubliclyActiveAd($ad)
			|| $target === ''
			|| $clickTokenState === 0
		) {
			http_response_code(404);
			exit;
		}
		if (
			$clickTokenState === 1
			&& Database::getSetting('ads_track_clicks', '1') === '1'
			&& !self::isLikelyBot()
		) {
			$id = (int) $ad['id'];
			$now = time();
			// One counted click per ad per minute per session — cheap damping, not fraud-proof.
			if (($_SESSION['ad_clk'][$id] ?? 0) <= $now - self::CLICK_TTL) {
				AdRepository::bumpStats($id, 0, 1);
				$_SESSION['ad_clk'][$id] = $now;
			}
		}
		header('X-Robots-Tag: noindex');
		header('Referrer-Policy: no-referrer');
		header('Cache-Control: private, no-store');
		header('Location: ' . $target, true, 302);
		exit;
	}

	/* ---------------- Metrics ---------------- */

	public static function handleAdminAdsStats()
	{
		if (!self::requirePerm('ads.metrics')) {
			return;
		}
		$days = max(1, min(365, (int) ($_GET['days'] ?? 30)));
		// Zone labels resolved here: the client's own catalogue may not have arrived yet
		// when this response renders (runda 5, pt 2 — the raw-id flicker).
		$perAd = array_map(function ($row) {
			$row['zoneLabel'] = isset(AdRenderer::ZONES[$row['zone']])
				? __(AdRenderer::PAGES[AdRenderer::ZONES[$row['zone']]['page']]) . ' · ' . __(AdRenderer::ZONES[$row['zone']]['label'])
				: (string) $row['zone'];
			return $row;
		}, AdRepository::statsPerAd($days));
		sendCachedJson([
			'success' => true,
			'days' => $days,
			'totals' => AdRepository::statsTotals($days),
			'series' => AdRepository::statsSeries($days),
			'perAd' => $perAd,
		]);
	}

	/* ---------------- Packages ---------------- */

	public static function handleAdminAdPackages()
	{
		if (!self::requireAnyPerm(['ads.packages', 'ads.manage'])) {
			return;
		}
		echo json_encode([
			'success' => true,
			'packages' => AdRepository::packagesAll(),
			'zones' => self::zoneCatalog(),
		]);
	}

	public static function handleAdminAdPackageSave()
	{
		if (!self::requirePerm('ads.packages')) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (int) ($input['id'] ?? 0);
		if (trim((string) ($input['name'] ?? '')) === '') {
			echo json_encode(['success' => false, 'error' => __('ads.err_name')]);
			return;
		}
		// A boost is zone-independent; a placement must name a real zone.
		if (($input['kind'] ?? 'placement') !== 'boost' && !isset(AdRenderer::ZONES[(string) ($input['zone'] ?? '')])) {
			echo json_encode(['success' => false, 'error' => __('ads.err_zone')]);
			return;
		}
		$res = AdRepository::packageSave($id ?: null, $input);
		if (!empty($res['success'])) {
			Database::logAudit($id ? 'ad_package_updated' : 'ad_package_created', (string) $input['name']);
		}
		echo json_encode($res);
	}

	public static function handleAdminAdPackageDelete()
	{
		if (!self::requirePerm('ads.packages')) {
			return;
		}
		if (!requireRecentAuthentication()) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (int) ($input['id'] ?? 0);
		if (!$id || !AdRepository::packageDelete($id)) {
			echo json_encode(['success' => false, 'error' => __('ads.err_not_found')]);
			return;
		}
		Database::logAudit('ad_package_deleted', '#' . $id);
		echo json_encode(['success' => true]);
	}

	/** The enabled packages — placements and boosts — for the buyer's picker. */
	public static function handleAdPackages()
	{
		if (!self::requireBuyer()) {
			return;
		}
		$disabledForView = self::disabledZones();
		$view = function ($p) use ($disabledForView) {
			$zone = (string) $p['zone'];
			$spec = AdRenderer::ZONES[$zone] ?? null;
			[$w, $h] = $spec ? AdRenderer::zoneDims($zone) : [0, 0];
			// Add-on zones on offer, minus anything the operator has switched off.
			$addons = [];
			foreach (json_decode((string) ($p['addon_zones'] ?? ''), true) ?: [] as $az => $price) {
				if (!isset(AdRenderer::ZONES[$az]) || in_array($az, $disabledForView, true)) {
					continue;
				}
				[$aw, $ah] = AdRenderer::zoneDims($az);
				$addons[] = [
					'zone' => $az,
					'label' => __(AdRenderer::ZONES[$az]['label']),
					'pageLabel' => __(AdRenderer::PAGES[AdRenderer::ZONES[$az]['page']]),
					'dims' => $aw . '×' . $ah,
					'w' => $aw,
					'h' => $ah,
					'amountMinor' => max(0, (int) $price),
				];
			}
			return [
				'id' => (int) $p['id'],
				'name' => (string) $p['name'],
				'description' => (string) $p['description'],
				'kind' => (string) ($p['kind'] ?? 'placement'),
				'zone' => $zone,
				'zoneLabel' => $spec ? __($spec['label']) : $zone,
				'zoneDims' => $w ? $w . '×' . $h : '',
				'zoneW' => $w,
				'zoneH' => $h,
				'addonZones' => $addons,
				'durationDays' => (int) $p['duration_days'],
				'amountMinor' => (int) $p['amount_minor'],
				'currency' => (string) $p['currency'],
				'priority' => (int) ($p['priority'] ?? 10),
				'weightBonus' => (int) ($p['weight_bonus'] ?? 0),
			];
		};
		// A placement whose zone the operator disabled is not for sale — the buyer would be
		// paying for a spot that never renders.
		$disabled = self::disabledZones();
		$placements = array_filter(
			AdRepository::packagesEnabled('placement'),
			fn($p) => !in_array((string) $p['zone'], $disabled, true)
		);
		echo json_encode([
			'success' => true,
			'packages' => array_map($view, array_values($placements)),
			'boosts' => array_map($view, AdRepository::packagesEnabled('boost')),
		]);
	}

	/* ---------------- Buyer: my ads ---------------- */

	public static function handleMyAds()
	{
		if (!self::requireAdOwner()) {
			return;
		}
		$now = time();
		$grace = max(0, (int) Database::getSetting('ads_grace_days', 14));
		$ads = array_map(function ($ad) use ($now, $grace) {
			$addonPrices = json_decode((string) ($ad['addon_zones'] ?? ''), true) ?: [];
			$children = array_map(function ($c) {
				[$w, $h] = AdRenderer::zoneDims((string) $c['zone']);
				return [
					'id' => (int) $c['id'],
					'zone' => (string) $c['zone'],
					'zoneLabel' => isset(AdRenderer::ZONES[$c['zone']]) ? __(AdRenderer::ZONES[$c['zone']]['label']) : $c['zone'],
					'zoneDims' => $w ? $w . '×' . $h : '',
					'hasOwnBanner' => !empty($c['image_path']),
					'imageUrl' => !empty($c['image_path'])
						? APP_URL . '/api.php?action=ad_banner&id=' . (int) $c['id'] : '',
				];
			}, $ad['children'] ?? []);
			return [
				'id' => (int) $ad['id'],
				'name' => (string) $ad['name'],
				'status' => (string) $ad['status'],
				'zone' => (string) $ad['zone'],
				'zoneLabel' => isset(AdRenderer::ZONES[$ad['zone']]) ? __(AdRenderer::ZONES[$ad['zone']]['label']) : $ad['zone'],
				'package' => (string) ($ad['package_name'] ?? ''),
				'packageId' => (int) ($ad['package_id'] ?? 0),
				'amountMinor' => (int) ($ad['amount_minor'] ?? 0),
				'currency' => (string) ($ad['currency'] ?? 'PLN'),
				'orderId' => (string) ($ad['order_id'] ?? ''),
				'paymentStatus' => (string) ($ad['payment_status'] ?? ''),
				'imageUrl' => !empty($ad['image_path'])
					? APP_URL . '/api.php?action=ad_banner&id=' . (int) $ad['id']
					: (string) $ad['image_url'],
				'targetUrl' => (string) $ad['target_url'],
				'altText' => (string) $ad['alt_text'],
				'endsAt' => $ad['ends_at'] !== null ? (int) $ad['ends_at'] : null,
				'rejectReason' => (string) ($ad['reject_reason'] ?? ''),
				'boostUntil' => (!empty($ad['boost_until']) && (int) $ad['boost_until'] > $now) ? (int) $ad['boost_until'] : null,
				'boostWeight' => (!empty($ad['boost_until']) && (int) $ad['boost_until'] > $now) ? (int) $ad['boost_weight'] : 0,
				'selfPaused' => !empty($ad['self_paused']),
				// How long an expired purchase can still be renewed before the cron removes it.
				'graceUntil' => ($ad['status'] === 'expired' && $ad['ends_at'] !== null && $grace > 0)
					? (int) $ad['ends_at'] + $grace * 86400 : null,
				'children' => $children,
				'addonPrices' => $addonPrices,
				'createdAt' => (int) $ad['created_at'],
			];
		}, AdRepository::forOwner((int) $_SESSION['user_id']));
		echo json_encode([
			'success' => true,
			'ads' => $ads,
			'invoicesEnabled' => Database::getSetting('invoice_enabled', '0') === '1',
		]);
	}

	/**
	 * The owner's pause/resume switch (runda 4, pt 8). The client shows the no-refund
	 * warning BEFORE calling; this only flips the flag — the clock never stops.
	 */
	public static function handleMyAdToggle()
	{
		if (!self::requireAdOwner()) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (int) ($input['id'] ?? 0);
		$paused = !empty($input['paused']);
		if (!AdRepository::setSelfPaused($id, (int) $_SESSION['user_id'], $paused)) {
			echo json_encode(['success' => false, 'error' => __('ads.err_not_found')]);
			return;
		}
		Database::logAudit($paused ? 'ad_self_paused' : 'ad_self_resumed', '#' . $id, (int) $_SESSION['user_id']);
		echo json_encode(['success' => true]);
	}

	/**
	 * Create (with a package) or update one's own creative. Image-only by construction —
	 * the type, zone, owner and weight fields of the request are ignored.
	 */
	public static function handleMyAdSave()
	{
		if (!self::requireAdOwner()) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$data = self::validateCreative($input, false);
		if (isset($data['error'])) {
			echo json_encode(['success' => false, 'error' => $data['error']]);
			return;
		}
		// Buyers upload their creative; a hotlinked URL could be swapped remotely after
		// approval, so the field is ignored no matter what the request carries.
		$data['image_url'] = '';
		$userId = (int) $_SESSION['user_id'];
		$id = (int) ($input['id'] ?? 0);
		if (!$id && !self::sellingEnabled()) {
			echo json_encode(['success' => false, 'error' => __('ads.err_disabled')]);
			return;
		}
		if (!self::sellingEnabled()) {
			$input['addons'] = [];
		}
		if ($id) {
			$existing = AdRepository::get($id);
			$res = AdRepository::updateOwn($id, $userId, $data);
			if (empty($res['success'])) {
				echo json_encode(['success' => false, 'error' => __('ads.err_not_found')]);
				return;
			}
			// A paid creative went back to review — the queue must not fill up silently
			// (runda 6). Drafts stay quiet: nothing was bought yet, the payment will knock.
			if (!empty($res['pending']) && $existing && $existing['status'] !== 'draft') {
				Notifications::sendMany(Notifications::staffIds(), 'ad.resubmitted', [
					'subject' => (string) ($data['name'] ?? $existing['name']),
					'link' => APP_URL . '/panel.php?tab=moderate&mstab=ads&astab=queue',
				]);
			}
			// Extra placements picked during the edit (runda 5): draft children now, the
			// "dopłać" checkout right after — unless the whole purchase is still unpaid,
			// in which case the base checkout will price them in. Disabled zones are dropped
			// here like the picker drops them — a crafted request must not buy a zone that
			// never renders.
			$zones = array_values(array_diff(
				array_filter(array_map('strval', (array) ($input['addons'] ?? []))),
				self::disabledZones()
			));
			if ($zones && $existing) {
				$package = AdRepository::packageGet((int) ($existing['package_id'] ?? 0));
				if ($package && !empty($package['enabled'])) {
					$created = AdRepository::addPlacements($id, $package, $zones);
					if ($created) {
						$res['newChildren'] = array_map(
							fn($cid, $zone) => ['id' => $cid, 'zone' => $zone],
							array_keys($created), array_values($created)
						);
						$res['needPay'] = $existing['status'] !== 'draft';
					}
				}
			}
			echo json_encode($res);
			return;
		}
		$package = AdRepository::packageGet((int) ($input['package_id'] ?? 0));
		if (!$package || empty($package['enabled']) || ($package['kind'] ?? 'placement') !== 'placement'
			|| in_array((string) $package['zone'], self::disabledZones(), true)) {
			echo json_encode(['success' => false, 'error' => __('ads.err_package')]);
			return;
		}
		// Add-on zones the buyer picked — validated against the package inside createDraft;
		// operator-disabled zones are refused here like everywhere else they are offered.
		$data['addons'] = array_values(array_diff(
			array_filter(array_map('strval', (array) ($input['addons'] ?? []))),
			self::disabledZones()
		));
		$res = AdRepository::createDraft($userId, $package, $data);
		if (!empty($res['success'])) {
			// The client uploads each add-on's own banner straight after — it needs the ids.
			$res['children'] = array_map(fn($c) => [
				'id' => (int) $c['id'],
				'zone' => (string) $c['zone'],
			], AdRepository::childrenOf((int) $res['id']));
		}
		echo json_encode($res);
	}

	/** A buyer's numbers for one of their own ads. */
	public static function handleMyAdMetrics()
	{
		if (!self::requireAdOwner()) {
			return;
		}
		$ad = AdRepository::get((int) ($_GET['id'] ?? 0));
		if (!$ad || (int) ($ad['owner_id'] ?? 0) !== (int) $_SESSION['user_id']) {
			echo json_encode(['success' => false, 'error' => __('ads.err_not_found')]);
			return;
		}
		$days = max(1, min(90, (int) ($_GET['days'] ?? 30)));
		sendCachedJson(['success' => true] + AdRepository::statsForAd((int) $ad['id'], $days));
	}

	/* ---------------- Checkout ---------------- */

	/**
	 * Start a checkout: `?ad=<id>` pays for one's own draft placement; `?ad=<id>&pkg=<id>`
	 * buys a boost for one's own already-running ad. A browser navigation, like the premium
	 * `checkout` action — it redirects to the provider on success and back to the "My ads"
	 * tab with an error code otherwise.
	 */
	public static function handleAdCheckout()
	{
		$back = APP_URL . '/panel.php?tab=myads';

		if (!self::sellingEnabled()) {
			self::bounce($back, 'disabled');
		}
		if (empty($_SESSION['user_id'])) {
			self::bounce($back, 'login');
		}
		if (!Permissions::has('ads.buy')) {
			self::bounce($back, 'denied');
		}
		$ad = AdRepository::get((int) ($_GET['ad'] ?? 0));
		if (!$ad || (int) ($ad['owner_id'] ?? 0) !== (int) $_SESSION['user_id']) {
			self::bounce($back, 'ad');
		}

		$boostPkgId = (int) ($_GET['pkg'] ?? 0);
		$renewal = false;
		$addonTopUp = !empty($_GET['extra']);
		if ($addonTopUp) {
			// Surcharge-only checkout for placements added to an existing purchase (runda 5).
			$package = AdRepository::packageGet((int) ($ad['package_id'] ?? 0));
			if (!$package || empty($package['enabled']) || ($package['kind'] ?? 'placement') !== 'placement') {
				self::bounce($back, 'package');
			}
			if (!in_array($ad['status'], ['active', 'pending', 'rejected'], true)) {
				self::bounce($back, 'ad');
			}
			$amount = AdRepository::draftAddonAmount((int) $ad['id'], $package);
		} elseif ($boostPkgId > 0) {
			// Boost purchase: the ad must already be live — extra weight on a paused or
			// unreviewed creative would be buying nothing.
			$package = AdRepository::packageGet($boostPkgId);
			if (!$package || empty($package['enabled']) || ($package['kind'] ?? '') !== 'boost') {
				self::bounce($back, 'package');
			}
			if ($ad['status'] !== 'active') {
				self::bounce($back, 'ad');
			}
			$amount = (int) $package['amount_minor'];
		} else {
			$package = AdRepository::packageGet((int) ($ad['package_id'] ?? 0));
			if (!$package || empty($package['enabled']) || ($package['kind'] ?? 'placement') !== 'placement') {
				self::bounce($back, 'package');
			}
			// Renewal (runda 4): the same package bought again for a live or graced-expired
			// purchase. First purchase stays the draft path.
			$renewal = in_array($ad['status'], ['active', 'expired'], true);
			if (!$renewal && $ad['status'] !== 'draft') {
				self::bounce($back, 'ad');
			}
			if (empty($ad['image_path'])) {
				// Nothing to review after payment — the creative upload must precede checkout.
				self::bounce($back, 'creative');
			}
			if (!$renewal) {
				// Add-on placements without their own creative inherit the primary's now, so
				// the queue shows every zone exactly as it will render.
				AdRepository::copyBannerToChildren((int) $ad['id']);
			}
			// A renewal may drop add-on placements (runda 6): the modal's unchecked ones
			// arrive as `?drop=`. Validated against the actual children; applied only at
			// fulfilment (the payment can still fail), so here they just leave the price.
			$dropIds = [];
			if ($renewal && !empty($_GET['drop'])) {
				$childIds = array_map(fn($c) => (int) $c['id'], AdRepository::childrenOf((int) $ad['id']));
				$dropIds = array_values(array_intersect(
					array_filter(array_map('intval', explode(',', (string) $_GET['drop']))),
					$childIds
				));
			}
			// The price is the base plus each KEPT add-on zone's surcharge — priced from the
			// package's CURRENT config, whether this is the first purchase or a renewal.
			$amount = (int) $package['amount_minor'];
			$allowed = json_decode((string) ($package['addon_zones'] ?? ''), true) ?: [];
			foreach (AdRepository::childrenOf((int) $ad['id']) as $child) {
				if (in_array((int) $child['id'], $dropIds, true)) {
					continue;
				}
				$amount += max(0, (int) ($allowed[(string) $child['zone']] ?? 0));
			}
		}
		if ($amount <= 0) {
			self::bounce($back, 'amount');
		}
		$provider = PaymentPlugins::builtinProvider();
		if (($provider === 'przelewy24' && !P24::isConfigured())
			|| ($provider === 'payu' && !PayU::isConfigured())) {
			self::bounce($back, 'unconfigured');
		}
		$user = Database::getUserById((int) $_SESSION['user_id']);
		if (!$user) {
			self::bounce($back, 'login');
		}

		$currency = strtoupper((string) ($package['currency'] ?? 'PLN'));
		$extOrderId = PaymentRepository::startAd(
			$provider, (int) $ad['id'], (int) $package['id'], (int) $user['id'], $amount, $currency,
			$addonTopUp ? PaymentRepository::KIND_AD_ADDON : PaymentRepository::KIND_AD,
			!empty($dropIds) ? ['drop' => $dropIds] : []
		);
		if ($extOrderId === null) {
			self::bounce($back, 'internal');
		}

		$order = [
			'ext_order_id' => $extOrderId,
			'amount_minor' => $amount,
			'currency' => $currency,
			'description' => (string) $package['name'],
			'customer_ip' => getClientIP(),
			'buyer_email' => (string) $user['email'],
			'buyer_name' => (string) $user['username'],
			'language' => Lang::current(),
			'notify_url' => APP_URL . '/api.php?action=' . ($provider === 'przelewy24' ? 'p24_notify' : 'payu_notify'),
			'continue_url' => $back . '&order=' . urlencode($extOrderId),
		];
		$res = $provider === 'przelewy24' ? P24::createOrder($order) : PayU::createOrder($order);

		if (empty($res['success'])) {
			PaymentRepository::setStatus($extOrderId, PaymentRepository::CANCELED);
			Database::logAudit('ad_payment_start_failed', $provider . ', ad #' . $ad['id'] . ', ' . ($res['error'] ?? '?'), (int) $user['id']);
			self::bounce($back, 'provider');
		}
		if (!empty($res['orderId'])) {
			PaymentRepository::setProviderOrderId($extOrderId, (string) $res['orderId']);
		}
		PaymentRepository::setStatus($extOrderId, PaymentRepository::PENDING);
		Database::logAudit('ad_payment_started', $provider . ', ad #' . $ad['id'] . ', order ' . $extOrderId, (int) $user['id']);

		header('Location: ' . $res['redirectUri']);
		exit;
	}

	/* ---------------- Shared ---------------- */

	/**
	 * Validate creative fields from either form. $adminForm opens the html/adsense types and
	 * free zone/status/weight/schedule choice; the buyer form is image + copy only.
	 *
	 * @return array field map, or ['error' => message]
	 */
	private static function validateCreative(array $input, bool $adminForm): array
	{
		$name = trim((string) ($input['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 120) {
			return ['error' => __('ads.err_name')];
		}
		$data = ['name' => $name];

		foreach (['image_url', 'target_url'] as $field) {
			$url = trim((string) ($input[$field] ?? ''));
			if ($url !== '') {
				if (mb_strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL)
					|| !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
					return ['error' => __('ads.err_url')];
				}
			}
			$data[$field] = $url;
		}
		$data['alt_text'] = mb_substr(trim((string) ($input['alt_text'] ?? '')), 0, 200);

		if (!$adminForm) {
			return $data; // type/zone/status/schedule come from the package and the lifecycle
		}

		$type = (string) ($input['type'] ?? 'image');
		if (!in_array($type, AdRepository::TYPES, true)) {
			return ['error' => __('ads.err_type')];
		}
		$data['type'] = $type;
		if (!isset(AdRenderer::ZONES[(string) ($input['zone'] ?? '')])) {
			return ['error' => __('ads.err_zone')];
		}
		$data['zone'] = (string) $input['zone'];
		$data['status'] = in_array($input['status'] ?? '', ['active', 'paused'], true) ? $input['status'] : 'paused';
		$data['weight'] = max(1, min(100, (int) ($input['weight'] ?? 1)));
		$data['html'] = $type === 'html' ? (string) ($input['html'] ?? '') : '';
		$slot = trim((string) ($input['adsense_slot'] ?? ''));
		if ($type === 'adsense' && !preg_match('/^\d{4,20}$/', $slot)) {
			return ['error' => __('ads.err_adsense_slot')];
		}
		$data['adsense_slot'] = $type === 'adsense' ? $slot : '';
		foreach (['starts_at', 'ends_at'] as $field) {
			$raw = $input[$field] ?? null;
			$data[$field] = null;
			if (is_numeric($raw) && (int) $raw > 0) {
				$data[$field] = (int) $raw;
			} elseif (is_string($raw) && trim($raw) !== '') {
				$ts = strtotime(trim($raw));
				$data[$field] = $ts !== false ? $ts : null;
			}
		}
		return $data;
	}

	/** A creative as the admin screens need it (owner and package names resolved). */
	private static function adminView(array $ad): array
	{
		return [
			'id' => (int) $ad['id'],
			'name' => (string) $ad['name'],
			'type' => (string) $ad['type'],
			'zone' => (string) $ad['zone'],
			'zoneLabel' => isset(AdRenderer::ZONES[$ad['zone']]) ? __(AdRenderer::ZONES[$ad['zone']]['label']) : $ad['zone'],
			'status' => (string) $ad['status'],
			'ownerId' => (int) ($ad['owner_id'] ?? 0),
			'ownerName' => (string) ($ad['owner_name'] ?? ''),
			'packageName' => (string) ($ad['package_name'] ?? ''),
			'amountMinor' => isset($ad['amount_minor']) ? (int) $ad['amount_minor'] : null,
			'currency' => (string) ($ad['currency'] ?? ''),
			// What the order actually charged (base + surcharges) — the package price alone
			// under-reports a purchase with add-on placements.
			'orderAmountMinor' => isset($ad['order_amount_minor']) ? (int) $ad['order_amount_minor'] : null,
			'orderCurrency' => (string) ($ad['order_currency'] ?? ''),
			'orderId' => (string) ($ad['order_id'] ?? ''),
			'imageUrl' => !empty($ad['image_path'])
				? APP_URL . '/api.php?action=ad_banner&id=' . (int) $ad['id']
				: (string) $ad['image_url'],
			'hasUpload' => !empty($ad['image_path']),
			'targetUrl' => (string) $ad['target_url'],
			'altText' => (string) $ad['alt_text'],
			'html' => (string) ($ad['html'] ?? ''),
			'adsenseSlot' => (string) ($ad['adsense_slot'] ?? ''),
			'weight' => (int) $ad['weight'],
			'boostWeight' => (!empty($ad['boost_until']) && (int) $ad['boost_until'] > time()) ? (int) ($ad['boost_weight'] ?? 0) : 0,
			'boostUntil' => (!empty($ad['boost_until']) && (int) $ad['boost_until'] > time()) ? (int) $ad['boost_until'] : null,
			'startsAt' => $ad['starts_at'] !== null ? (int) $ad['starts_at'] : null,
			'endsAt' => $ad['ends_at'] !== null ? (int) $ad['ends_at'] : null,
			'rejectReason' => (string) ($ad['reject_reason'] ?? ''),
			'createdAt' => (int) $ad['created_at'],
		];
	}

	/**
	 * Give the money of an ad's most recent completed order back through the provider.
	 *
	 * @return array{0: string, 1: string} ['ok'|'failed', error detail for the reviewer]
	 */
	private static function refundAdPayment(int $adId, string $adName): array
	{
		$payment = PaymentRepository::latestCompletedForAd($adId);
		if (!$payment || !in_array((string) $payment['provider'], ['payu', 'przelewy24'], true)
			|| empty($payment['provider_order_id'])) {
			return ['failed', 'no_refundable_payment'];
		}
		$extOrderId = (string) $payment['ext_order_id'];
		$isP24 = (string) $payment['provider'] === 'przelewy24';
		$refundToken = PaymentRepository::claimRefund($extOrderId, $isP24 ? 604800 : 900);
		if ($refundToken === null) {
			return ['failed', 'refund_in_progress'];
		}
		$description = mb_substr(__('panel.ads.refund_desc', ['name' => $adName]), 0, 255);
		if ($isP24) {
			$requestId = bin2hex(random_bytes(16));
			$refundsUuid = bin2hex(random_bytes(16));
			if (!PaymentRepository::setRefundContext($extOrderId, $refundToken, [
				'requestId' => $requestId,
				'refundsUuid' => $refundsUuid,
				'actorId' => (int) ($_SESSION['user_id'] ?? 0),
			])) {
				PaymentRepository::releaseRefund($extOrderId, $refundToken);
				return ['failed', 'refund_context_failed'];
			}
			$res = P24::refund(
				(int) $payment['provider_order_id'],
				$extOrderId,
				(int) $payment['amount_minor'],
				$description,
				APP_URL . '/api.php?action=p24_refund_notify',
				$requestId,
				$refundsUuid
			);
		} else {
			$res = PayU::refund((string) $payment['provider_order_id'], $description);
		}
		if (empty($res['success'])) {
			PaymentRepository::releaseRefund($extOrderId, $refundToken);
			Database::logAudit('ad_refund_failed', '#' . $adId . ' ' . $extOrderId . ' — ' . ($res['error'] ?? '?'));
			return ['failed', (string) ($res['error'] ?? '?')];
		}
		if ($isP24) {
			PaymentRepository::recordProviderEvent(
				'p24-refund-request',
				hash('sha256', (string) ($res['refundsUuid'] ?? '')),
				$extOrderId,
				'accepted'
			);
			Database::logAudit('ad_refund_pending', '#' . $adId . ' ' . $extOrderId);
			return ['pending', ''];
		}
		$finalized = PaymentRepository::finalizeRefund($extOrderId, $refundToken, 0);
		if (empty($finalized['success'])) {
			Database::logAudit('ad_refund_finalize_failed', '#' . $adId . ' ' . $extOrderId);
			return ['failed', 'refund_finalize_failed'];
		}
		$refundId = trim((string) ($res['refundId'] ?? ''));
		if ($refundId !== '') {
			PaymentRepository::recordProviderEvent(
				'payu-refund',
				hash('sha256', $refundId),
				$extOrderId,
				(string) ($res['status'] ?? 'accepted')
			);
		}
		Database::logAudit('ad_refunded', '#' . $adId . ' ' . $extOrderId
			. ' ' . $payment['amount_minor'] . ' ' . $payment['currency'], (int) ($payment['user_id'] ?? 0) ?: null);
		Notifications::send((int) $payment['user_id'], 'payment.refunded', [
			'subject' => $adName,
			'data' => ['amount' => number_format(((int) $payment['amount_minor']) / 100, 2, ',', ' ') . ' ' . $payment['currency']],
			'link' => APP_URL . '/panel.php?tab=myads',
		]);
		return ['ok', ''];
	}

	/** Browser-navigation error path, mirroring PaymentController::bounce. */
	private static function bounce(string $back, string $code): void
	{
		header('Location: ' . $back . (strpos($back, '?') !== false ? '&' : '?') . 'aderr=' . rawurlencode($code));
		exit;
	}

	private static function requireAdmin(): bool
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return false;
		}
		return true;
	}

	/**
	 * Session + one specific ads permission. Admins pass implicitly (Permissions::has);
	 * everyone else needs the key granted to their group — this is what lets an operator
	 * hand the queue to one group and the metrics to another (Faza 8, runda 2).
	 */
	private static function requirePerm(string $perm): bool
	{
		header('Content-Type: application/json');
		if (empty($_SESSION['user_id']) || !Permissions::has($perm)) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return false;
		}
		return true;
	}

	/** Session + at least one of the given ads permissions (shared read endpoints). */
	private static function requireAnyPerm(array $perms): bool
	{
		header('Content-Type: application/json');
		if (!empty($_SESSION['user_id'])) {
			foreach ($perms as $perm) {
				if (Permissions::has($perm)) {
					return true;
				}
			}
		}
		http_response_code(403);
		echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
		return false;
	}

	/** The buyer gate: sales on, session present, `ads.buy` granted. */
	private static function requireBuyer(): bool
	{
		header('Content-Type: application/json');
		if (!self::sellingEnabled()) {
			echo json_encode(['success' => false, 'error' => __('ads.err_disabled')]);
			return false;
		}
		if (empty($_SESSION['user_id']) || !Permissions::has('ads.buy')) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return false;
		}
		return true;
	}

	/** Existing buyers may manage paid ads even while sales are temporarily switched off. */
	private static function requireAdOwner(): bool
	{
		header('Content-Type: application/json');
		if (empty($_SESSION['user_id']) || !Permissions::has('ads.buy')) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return false;
		}
		return true;
	}

	/** The per-zone cap, enforced wherever an ad can become live. 0 = unlimited. */
	private static function zoneHasRoom(string $zone, int $excludeId = 0): bool
	{
		$cap = (int) Database::getSetting('ads_zone_max', 0);
		return $cap <= 0 || AdRepository::activeCountInZone($zone, $excludeId) < $cap;
	}
}
