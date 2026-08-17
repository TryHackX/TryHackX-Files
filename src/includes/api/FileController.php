<?php
/**
 * FileController (Faza 5 · #1).
 *
 * Everything about the stored files and the upload/download plumbing: streaming
 * (download/preview/embed), download-token issuance, upload-token/CAPTCHA verification,
 * the admin file list, public info/stats/health, delete + revert, per-file options and
 * passwords, a user's own files, collections, API keys and webhooks. Handler bodies are
 * the former api.php handleXxx functions, moved verbatim; the router dispatches here.
 */
final class FileController
{
	private const DELETE_LINK_NONCE_TTL = 600;
	private const DELETE_LINK_NONCE_LIMIT = 20;

	/**
	 * pt 1: smallest set that makes a collection. One file needs no ZIP wrapper — the file's
	 * own link is that. Checked here as well as in the panel, because the endpoint is public
	 * API surface and the browser is not the only caller.
	 */
	public const COLLECTION_MIN_FILES = 2;
	public const COLLECTION_MAX_FILES = 200;
	private const COLLECTION_JSON_MAX_BYTES = 1048576;

	private static function deleteTokenMatches(string $token, string $stored): bool
	{
		if ($token === '' || $stored === '' || strlen($token) > InputLimits::SHORT_TEXT_MAX) {
			return false;
		}
		return password_verify($token, $stored)
			|| (!str_starts_with($stored, '$2') && hash_equals($stored, $token));
	}

	/**
	 * The public file representation is intentionally independent from FileManager's internal
	 * storage DTO. Adding an internal field (IP, path, delete hash, owner metadata) can no
	 * longer make it appear in `?action=info` by accident.
	 */
	private static function publicFileDto(array $file): array
	{
		return [
			'id' => (string) ($file['id'] ?? ''),
			'name' => (string) ($file['name'] ?? ''),
			'mimeType' => (string) ($file['mimeType'] ?? 'application/octet-stream'),
			'size' => (int) ($file['size'] ?? 0),
			'uploadedAt' => (int) ($file['uploadedAt'] ?? 0),
			'downloads' => (int) ($file['downloads'] ?? 0),
			'previewType' => (string) ($file['previewType'] ?? 'file'),
		];
	}

	private static function jsonInput(): array
	{
		$input = json_decode((string) file_get_contents('php://input'), true);
		return is_array($input) ? $input : [];
	}

	public static function handleUploadStatus(): void
	{
		header('Content-Type: application/json');
		header('Cache-Control: no-store');
		$id = strtolower(trim((string) ($_GET['id'] ?? '')));
		if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) {
			http_response_code(400);
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		echo json_encode([
			'success' => true,
			'status' => ActiveDownloadRepository::uploadStatus($id) ?? 'finished',
		]);
	}

	private static function revertPayload(string $body): void
	{
		$body = trim($body);
		if ($body === '') {
			return;
		}

		$parts = explode(':', $body, 2);
		$id = (string) ($parts[0] ?? '');
		$token = (string) ($parts[1] ?? '');
		if (!FileManager::isValidFileId($id) || $token === '') {
			return;
		}

		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}

		$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
		$table = $prefix . 'files';

		try {
			$stmt = $pdo->prepare("SELECT `delete_token` FROM `{$table}` WHERE `id` = ?");
			$stmt->execute([$id]);
			$file = $stmt->fetch();

			// File age is never authority. Guests must present the per-upload capability even
			// during the first seconds after upload.
			if ($file && self::deleteTokenMatches($token, (string) $file['delete_token'])) {
				$stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `id` = ?");
				$stmt->execute([$id]);
				if ($stmt->rowCount() === 1) {
					Database::deleteReportsByFileIds([$id]);
					FileManager::purgeFileArtifacts($id);
				}
			}
		} catch (PDOException $e) {
		}
	}

	public static function handleRevert()
	{
		self::revertPayload((string) file_get_contents('php://input'));
		http_response_code(200);
	}

	private static function issueDeleteLinkNonce(string $id, string $token): string
	{
		$now = time();
		$nonces = is_array($_SESSION['delete_link_nonces'] ?? null)
			? $_SESSION['delete_link_nonces']
			: [];
		foreach ($nonces as $key => $entry) {
			if (!is_array($entry) || (int) ($entry['expires'] ?? 0) < $now) {
				unset($nonces[$key]);
			}
		}
		while (count($nonces) >= self::DELETE_LINK_NONCE_LIMIT) {
			array_shift($nonces);
		}
		$nonce = bin2hex(random_bytes(32));
		$nonces[$nonce] = [
			'id' => $id,
			'token_hash' => hash('sha256', $token),
			'expires' => $now + self::DELETE_LINK_NONCE_TTL,
		];
		$_SESSION['delete_link_nonces'] = $nonces;
		return $nonce;
	}

	private static function consumeDeleteLinkNonce(string $nonce, string $id, string $token): bool
	{
		$nonces = is_array($_SESSION['delete_link_nonces'] ?? null)
			? $_SESSION['delete_link_nonces']
			: [];
		$entry = $nonces[$nonce] ?? null;
		if (!is_array($entry)
			|| (int) ($entry['expires'] ?? 0) < time()
			|| !hash_equals((string) ($entry['id'] ?? ''), $id)
			|| !hash_equals((string) ($entry['token_hash'] ?? ''), hash('sha256', $token))
		) {
			return false;
		}
		unset($nonces[$nonce]);
		$_SESSION['delete_link_nonces'] = $nonces;
		return true;
	}

	public static function handleDownload()
	{
		$token = (string) ($_GET['token'] ?? '');
		if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
			http_response_code(403);
			die('Access denied');
		}

		// Keep the legacy action as a compatibility redirect, but let the Python byte-serving
		// pipeline own token reservation, first-byte one-time claims, Range completion and
		// disconnect compensation. Consuming the token here would bypass that state machine.
		header(
			'Location: ' . rtrim(APP_URL, '/') . '/api/download?token=' . rawurlencode($token),
			true,
			307
		);
		exit;
	}

	public static function handleGetDownloadToken()
	{
		$input = self::jsonInput();
		$id = (string) ($input['id'] ?? $_POST['id'] ?? $_GET['id'] ?? '');
		$captchaProof = (string) ($input['captcha_proof'] ?? $_POST['captcha_proof'] ?? $_GET['captcha_proof'] ?? '');

		if (!$id) {
			echo json_encode(['success' => false, 'error' => __('api.missing_id2')]);
			return;
		}

		// One-time links: if already used up, refuse cleanly here so the page can show a
		// friendly message instead of the raw 410 the download endpoint would otherwise emit.
		if (Database::oneTimeConsumed($id)) {
			echo json_encode(['success' => false, 'error' => __('api.one_time_used')]);
			return;
		}

		// Same reasoning for a spent download cap: refuse here with a readable message rather
		// than letting the browser land on the upload server's raw {"detail": …} 410.
		$file = FileManager::getFile($id);
		if ($file) {
			$row = Database::getFileSharingState($id);
			if (!empty($row['max_downloads']) && (int) $row['downloads'] >= (int) $row['max_downloads']) {
				echo json_encode(['success' => false, 'error' => __('download.err_limit')]);
				return;
			}
			if (!empty($row['expires_at']) && (int) $row['expires_at'] < time()) {
				echo json_encode(['success' => false, 'error' => __('collection.err_expired')]);
				return;
			}
		}

		$captchaWasAccepted = false;
		// Password-protected files: require the correct password before issuing a token. After
		// repeated failures the same CAPTCHA proof used by the download page becomes mandatory.
		if (Database::fileIsProtected($id)) {
			$ip = getClientIP();
			$failCount = Database::getSecurityEvent($ip, 'file_password_fail');
			$passwordThreshold = (int) Database::getSetting('recaptcha_file_password_threshold', 3);
			if ($passwordThreshold >= 0 && $failCount >= $passwordThreshold && Database::isRecaptchaEnabled()) {
				if (!$captchaProof || !Database::verifyUploadToken($captchaProof, $ip)) {
					echo json_encode(['success' => false, 'require_captcha' => true, 'error' => __('api.captcha_required')]);
					return;
				}
				Database::deleteUploadToken($captchaProof);
				$captchaWasAccepted = true;
				Database::clearSecurityEvent($ip, 'file_password_fail');
				$failCount = 0;
			}
			$pwRaw = $input['pw'] ?? $_POST['pw'] ?? $_GET['pw'] ?? '';
			$pw = is_string($pwRaw) && strlen($pwRaw) <= InputLimits::PASSWORD_MAX ? $pwRaw : '';
			if ($pw === '' || !Database::verifyFilePassword($id, $pw)) {
				Database::incrementSecurityEvent($ip, 'file_password_fail');
				$captchaNext = $passwordThreshold >= 0 && ($failCount + 1) >= $passwordThreshold && Database::isRecaptchaEnabled();
				echo json_encode($captchaNext
					? ['success' => false, 'require_captcha' => true, 'error' => __('api.captcha_required')]
					: ['success' => false, 'require_password' => true]);
				return;
			}
			Database::clearSecurityEvent($ip, 'file_password_fail');
		}

		// CAPTCHA Logic
		if (Database::isRecaptchaEnabled()) {
			$threshold = (int) Database::getSetting('recaptcha_download_threshold', 0);
			// 0 = Always, -1 = Disabled, N = Every N clicks

			if ($threshold !== -1) {
				$requireCaptcha = false;
				$currentCount = $_SESSION['download_clicks_counter'] ?? 0;
				$nextCount = $currentCount + 1;

				if ($threshold === 0) {
					$requireCaptcha = true;
				} else {
					if ($nextCount % $threshold === 0) {
						$requireCaptcha = true;
					}
				}

				if ($requireCaptcha) {
					// If we have a valid proof, we allow it this time
					if ($captchaWasAccepted || ($captchaProof && Database::verifyUploadToken($captchaProof, getClientIP()))) {
						// Valid proof, proceed.
						// CONSUME token so it can't be reused infinitely
						if (!$captchaWasAccepted) {
							Database::deleteUploadToken($captchaProof);
						}

						// Increment counter ONLY after successful verification/bypass
						$_SESSION['download_clicks_counter'] = $nextCount;
					} else {
						// No valid proof
						echo json_encode(['success' => false, 'require_captcha' => true]);
						return;
					}
				} else {
					// No captcha required, just increment
					$_SESSION['download_clicks_counter'] = $nextCount;
				}
			}
		}

		$token = Database::createDownloadToken($id, getClientIP(), $_SESSION['user_id'] ?? null);
		if ($token) {
			echo json_encode(['success' => true, 'token' => $token]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.token_gen_failed2')]);
		}
	}

	public static function handlePreview()
	{
		$id = $_GET['id'] ?? '';
		if (!$id)
			throw new Exception('Missing ID', 400);

		// Password-protected files must not be viewable in the preview pane without the
		// password — otherwise the protection is trivially bypassed. Streaming here never
		// increments the download counter (streamFile(..., false)).
		if (Database::fileIsProtected($id) && !previewGranted($id)) {
			http_response_code(403);
			header('Content-Type: application/json');
			echo json_encode(['success' => false, 'require_password' => true, 'error' => __('api.preview_protected')]);
			return;
		}

		$file = FileManager::getFile($id);
		if (!$file) {
			http_response_code(404);
			exit('File not found');
		}
		if (!FileManager::isInlinePreviewAllowed((string) $file['mimeType'])) {
			http_response_code(415);
			header('Content-Type: application/json');
			header('Cache-Control: no-store');
			echo json_encode(['success' => false, 'error' => __('api.preview_unsupported')]);
			return;
		}

		FileManager::streamFile($id, false, true, previewGranted($id));
	}

	// Embed / hotlink endpoint: serves an image inline (so `<img src>` renders) but,
	// unlike `preview`, DOES count as a download. Used by the download page's embed codes,
	// so views from other sites where the image is embedded increment the counter, while the
	// on-page preview (action=preview) does not. Only for public (non-protected) images.
	public static function handleEmbed()
	{
		$id = $_GET['id'] ?? '';
		if (!$id) {
			throw new Exception('Missing ID', 400);
		}

		if (Database::fileIsProtected($id)) {
			http_response_code(403);
			header('Content-Type: application/json');
			echo json_encode(['success' => false, 'error' => __('api.embed_protected')]);
			return;
		}

		$file = FileManager::getFile($id);
		if (!$file) {
			http_response_code(404);
			exit('File not found');
		}
		if (!FileManager::isEmbeddableImage((string) $file['mimeType'])) {
			http_response_code(415);
			header('Content-Type: application/json');
			header('Cache-Control: no-store');
			echo json_encode(['success' => false, 'error' => __('api.embed_unsupported')]);
			return;
		}

		FileManager::streamFile($id, true, true); // count as a download, but serve inline so it renders
	}

	// Verify a file password and, on success, store a short-lived session permit that
	// authorises previews of that file (used by the download page's locked preview).
	public static function handlePreviewAuth()
	{
		header('Content-Type: application/json');

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = $input['id'] ?? $_POST['id'] ?? '';
		$pwRaw = $input['pw'] ?? $_POST['pw'] ?? '';
		$pw = is_string($pwRaw) && strlen($pwRaw) <= InputLimits::PASSWORD_MAX ? $pwRaw : '';

		if (!$id) {
			echo json_encode(['success' => false, 'error' => __('api.missing_id2')]);
			return;
		}

		// Nothing to unlock for unprotected files.
		if (!Database::fileIsProtected($id)) {
			echo json_encode(['success' => true]);
			return;
		}

		if ($pw === '' || !Database::verifyFilePassword($id, $pw)) {
			echo json_encode(['success' => false, 'require_password' => true, 'error' => __('api.bad_password')]);
			return;
		}

		$_SESSION['preview_grants'][$id] = time();
		echo json_encode(['success' => true]);
	}

	public static function handleInfo()
	{
		$id = $_GET['id'] ?? '';
		if (!$id)
			throw new Exception('Missing ID', 400);

		$file = FileManager::getFile($id);
		if (!$file)
			throw new Exception('Not found', 404);

		header('Content-Type: application/json');
		echo json_encode(['success' => true, 'data' => self::publicFileDto($file)]);
	}

	/**
	 * The all-files browser.
	 *
	 * Used to be admin-only; a group may now be granted `files.view_all` and the surrounding
	 * capabilities (search / sort / advanced filters / seeing the owner or the IP). This is the
	 * enforcement point for all of them — the panel hides what a user may not use, but the
	 * decisions are made here, so a hand-written request gains nothing:
	 *
	 *   - no `files.view_all`               → 403, full stop;
	 *   - no `files.search_all`             → the `search` parameter is ignored;
	 *   - no `files.sort_all`               → sort/order are forced back to the default;
	 *   - no `files.advanced_filters`       → every filter is dropped, and each individual
	 *                                         filter additionally needs its own `filter.*`;
	 *   - no `files.see_owner` / `see_ip`   → those fields are stripped from the response
	 *                                         rather than merely hidden in the UI.
	 */
	public static function handleList()
	{
		if (!isset($_SESSION['user_id'])) {
			throw new Exception('Unauthorized', 401);
		}
		if (!Permissions::has('files.view_all')) {
			throw new Exception('Unauthorized', 403);
		}

		$page = max(1, (int) ($_GET['page'] ?? 1));
		$perPage = min(100, max(5, (int) ($_GET['per_page'] ?? 20)));

		$search = Permissions::has('files.search_all') ? trim((string) ($_GET['search'] ?? '')) : '';

		$sort = 'uploaded_at';
		$order = 'DESC';
		if (Permissions::has('files.sort_all')) {
			$sort = (string) ($_GET['sort'] ?? 'uploaded_at');
			$order = (string) ($_GET['order'] ?? 'DESC');
		}
		// Sorting by owner is only meaningful — and only allowed — when the owner is visible.
		if ($sort === 'owner' && !Permissions::has('files.see_owner')) {
			$sort = 'uploaded_at';
		}
		if ($sort === 'uploaded_ip' && !Permissions::has('files.see_ip')) {
			$sort = 'uploaded_at';
		}
		$sorts = [];
		if (Permissions::has('files.sort_all') && Permissions::has('tables.multi_sort')) {
			$decoded = json_decode((string) ($_GET['sorts'] ?? ''), true);
			if (is_array($decoded)) {
				foreach (array_slice($decoded, 0, 5) as $item) {
					$key = (string) ($item['key'] ?? '');
					if ($key === 'owner' && !Permissions::has('files.see_owner')) {
						continue;
					}
					if ($key === 'uploaded_ip' && !Permissions::has('files.see_ip')) {
						continue;
					}
					if (!in_array($key, ['uploaded_at', 'size', 'downloads', 'original_name', 'owner', 'uploaded_ip'], true)
						|| isset($sorts[$key])) {
						continue;
					}
					$sorts[$key] = strtoupper((string) ($item['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
				}
			}
		}

		$filters = self::authorisedFilters();

		$result = FileManager::browse([
			'page' => $page,
			'per_page' => $perPage,
			'search' => $search,
			// The search term reaches only as far as this session may see: the columns below are
			// stripped from the response for the same groups, and a search that still matched
			// them would hand back by inference what the response withholds.
			'search_ip' => Permissions::has('files.see_ip'),
			'search_owner' => Permissions::has('files.see_owner'),
			'sort' => $sort,
			'order' => $order,
			'sorts' => $sorts,
			'filters' => $filters,
		]);

		$showOwner = Permissions::has('files.see_owner');
		$showIp = Permissions::has('files.see_ip');
		$files = array_map(function ($f) use ($showOwner, $showIp) {
			if (!$showOwner) {
				unset($f['owner'], $f['userId']);
			}
			if (!$showIp) {
				unset($f['uploadedIP']);
			}
			return $f;
		}, $result['files']);

		sendCachedJson([
			'success' => true,
			'files' => $files,
			'total' => $result['total'],
			'page' => $page,
			'per_page' => $perPage,
			'applied_filters' => array_keys($filters),
		]);
	}

	/**
	 * Read the advanced filters off the request, keeping only those the session may actually
	 * use. Returns an empty array when `files.advanced_filters` is missing, so an unprivileged
	 * caller simply gets the unfiltered list rather than an error.
	 */
	private static function authorisedFilters(): array
	{
		// filter permission => the keys it unlocks.
		return self::gateFilters('files.advanced_filters', [
			'filter.date'      => ['date_from', 'date_to'],
			'filter.size'      => ['size_min', 'size_max'],
			'filter.downloads' => ['dl_min', 'dl_max'],
			'filter.type'      => ['extensions', 'mime'],
			'filter.user'      => ['users'],
			'filter.ip'        => ['ips'],
			'filter.inactive'  => ['inactive_days'],
			'filter.dead'      => ['dead'],
			'filter.sharing'   => ['sharing'],
			'filter.in_collection' => ['in_collection'],
		]);
	}

	/**
	 * The same, for the account's own files (pt 8). A smaller set on purpose: owner and IP
	 * criteria have one answer on this list, so they are not offered and — more to the point —
	 * not accepted, whatever the caller sends.
	 */
	private static function authorisedMyFilters(): array
	{
		return self::gateFilters('myfiles.filters', [
			'mfilter.date'          => ['date_from', 'date_to'],
			'mfilter.size'          => ['size_min', 'size_max'],
			'mfilter.downloads'     => ['dl_min', 'dl_max'],
			'mfilter.type'          => ['extensions', 'mime'],
			'mfilter.inactive'      => ['inactive_days'],
			'mfilter.dead'          => ['dead'],
			'mfilter.sharing'       => ['sharing'],
			'mfilter.in_collection' => ['in_collection'],
		]);
	}

	/**
	 * Shared gate: parse `?filters=`, drop everything the session has no permission for, then
	 * convert the units the modal speaks (Y-m-d dates, MiB sizes) into what the query wants.
	 *
	 * @param string                     $master The permission without which nothing is allowed.
	 * @param array<string, list<string>> $groups permission => the filter keys it unlocks.
	 */
	private static function gateFilters(string $master, array $groups): array
	{
		if (!Permissions::has($master)) {
			return [];
		}
		$raw = $_GET['filters'] ?? '';
		$in = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
		if (!is_array($in)) {
			return [];
		}

		$out = [];
		foreach ($groups as $perm => $keys) {
			if (!Permissions::has($perm)) {
				continue;
			}
			foreach ($keys as $k) {
				if (!isset($in[$k]) || $in[$k] === '' || $in[$k] === null || $in[$k] === []) {
					continue;
				}
				$out[$k] = $in[$k];
			}
		}

		// Dates arrive as Y-m-d from the picker; store them as timestamps for the query.
		foreach (['date_from' => ' 00:00:00', 'date_to' => ' 23:59:59'] as $k => $suffix) {
			if (isset($out[$k]) && !is_numeric($out[$k])) {
				$ts = strtotime($out[$k] . $suffix);
				if ($ts) {
					$out[$k] = $ts;
				} else {
					unset($out[$k]);
				}
			}
		}
		// Sizes come in MiB (what the modal asks for), the column is bytes.
		foreach (['size_min', 'size_max'] as $k) {
			if (isset($out[$k])) {
				$out[$k] = (int) round(((float) $out[$k]) * 1024 * 1024);
			}
		}

		return $out;
	}

	/** Choice lists (owners / IPs / extensions) for the filter modal. */
	public static function handleFileFacets()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id']) || !Permissions::has('files.advanced_filters')) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}

		$facets = FileManager::browseFacets();
		// The IP list is a filter choice like any other — but it is still an IP list, so it
		// only ships to sessions allowed to filter by one.
		if (!Permissions::has('filter.ip')) {
			$facets['ips'] = [];
		}
		if (!Permissions::has('filter.user')) {
			$facets['users'] = [];
		}
		if (!Permissions::has('filter.type')) {
			$facets['extensions'] = [];
		}

		echo json_encode([
			'success' => true,
			'facets' => $facets,
			// Which filters this session may use — the modal renders exactly these sections.
			'allowed' => array_values(array_filter(
				array_keys(Permissions::FILTER_PERMS),
				fn($p) => Permissions::has($p)
			)),
		]);
	}

	public static function handleStats()
	{
		$stats = FileManager::getStats();

		header('Content-Type: application/json');
		echo json_encode(['success' => true, 'stats' => $stats]);
	}

	public static function handleHealth()
	{
		$pythonHealth = null;

		$ch = curl_init('http://127.0.0.1:8001/health');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			// Health is public and may be polled often. A stopped sidecar must not hold a PHP
			// worker for seconds; loopback should answer in milliseconds.
			CURLOPT_TIMEOUT_MS => 500,
			CURLOPT_CONNECTTIMEOUT_MS => 200,
		]);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpCode === 200 && $response) {
			$pythonHealth = json_decode($response, true);
		}

		$dbConnected = Database::getInstance() !== null;

		header('Content-Type: application/json');
		echo json_encode([
			'success' => true,
			'php' => [
				'version' => phpversion(),
				'database_connected' => $dbConnected,
			],
			'python' => $pythonHealth,
			'python_running' => $pythonHealth !== null,
		]);
	}

	public static function handleVerifyCaptcha()
	{
		header('Content-Type: application/json');

		if (!Database::isRecaptchaEnabled()) {
			$token = Database::createUploadToken(getClientIP(), $_SESSION['user_id'] ?? null);
			echo json_encode(['success' => true, 'token' => $token]);
			return;
		}

		// Allow logged-in users to bypass captcha - DISABLED as per user request
		/*
		if (isset($_SESSION['user_id'])) {
			$token = Database::createUploadToken(getClientIP(), $_SESSION['user_id']);
			if ($token) {
				echo json_encode(['success' => true, 'token' => $token]);
				return;
			}
		}
		*/


		$input = json_decode(file_get_contents('php://input'), true);
		$captchaResponse = $input['captcha_response'] ?? '';

		if (empty($captchaResponse)) {
			http_response_code(400);
			echo json_encode(['success' => false, 'error' => __('api.captcha_missing')]);
			return;
		}

		$ip = getClientIP();
		if (!Database::verifyRecaptcha($captchaResponse, $ip)) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.captcha_failed')]);
			return;
		}

		$token = Database::createUploadToken($ip, $_SESSION['user_id'] ?? null);
		if (!$token) {
			http_response_code(500);
			echo json_encode(['success' => false, 'error' => __('api.token_gen_failed')]);
			return;
		}

		echo json_encode(['success' => true, 'token' => $token]);
	}

	public static function handleVerifyToken()
	{
		header('Content-Type: application/json');

		if (!Database::isRecaptchaEnabled()) {
			echo json_encode(['success' => true, 'valid' => true]);
			return;
		}

		$token = $_GET['token'] ?? $_SERVER['HTTP_X_UPLOAD_TOKEN'] ?? '';
		$ip = $_GET['ip'] ?? getClientIP();

		if (empty($token)) {
			echo json_encode(['success' => true, 'valid' => false, 'error' => __('api.missing_token')]);
			return;
		}

		$valid = Database::verifyUploadToken($token, $ip);
		echo json_encode(['success' => true, 'valid' => $valid]);
	}

	public static function handleTokenInfo()
	{
		header('Content-Type: application/json');

		$token = $_GET['token'] ?? $_SERVER['HTTP_X_UPLOAD_TOKEN'] ?? '';
		$ip = getClientIP();

		if (empty($token)) {
			echo json_encode(['success' => false, 'error' => __('api.missing_token')]);
			return;
		}

		$info = Database::getTokenInfo($token, $ip);
		if ($info) {
			echo json_encode(['success' => true] + $info);
		} else {
			echo json_encode(['success' => false, 'valid' => false]);
		}
	}

	public static function handleIncrementToken()
	{
		header('Content-Type: application/json');

		$input = json_decode(file_get_contents('php://input'), true);
		$token = $input['token'] ?? $_GET['token'] ?? '';

		if (empty($token)) {
			echo json_encode(['success' => false, 'error' => __('api.missing_token')]);
			return;
		}

		// Deprecated: Upload server now increments count automatically on start
		// $result = Database::incrementTokenFileCount($token);
		$result = true;

		$info = Database::getTokenInfo($token, getClientIP());

		echo json_encode([
			'success' => $result,
			'files_uploaded' => $info['files_uploaded'] ?? 0,
			'files_remaining' => $info['files_remaining'] ?? -1
		]);
	}

	/**
	 * Delete one file.
	 *
	 * Two ways to prove the request may do that, and they are not interchangeable:
	 *
	 *  - the signed-in owner of the row. Owning the upload *is* the authorisation, so no
	 *    per-upload token is asked for. This is what the panel's "My files" tab uses: the
	 *    delete token is stored as a bcrypt hash, so the server cannot hand the row's token
	 *    back to the browser and the browser has nothing to echo. The route is a `$write`
	 *    (POST + session CSRF token), which is what keeps a session-authorised delete off
	 *    limits to other origins.
	 *  - anyone else: the per-upload delete capability, as before. That is the guest path —
	 *    the token handed out once at upload time by the homepage and by ShareX.
	 *
	 * The brute-force gate (fail counter + CAPTCHA) belongs to the capability path only.
	 * An owner deleting their own file is not guessing at a token, and must not be locked
	 * out of their own panel by someone else's failures from the same IP.
	 */
	public static function handleDelete()
	{
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			http_response_code(405);
			echo json_encode(['success' => false, 'error' => __('api.method_not_allowed')]);
			return;
		}

		$id = is_string($_POST['id'] ?? null) ? $_POST['id'] : '';
		$token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
		$captchaProof = $_POST['captcha_proof'] ?? ''; // Upload token from verify_captcha

		if (!$id) {
			echo json_encode(['success' => false, 'error' => __('api.missing_id_or_token2')]);
			return;
		}

		$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
		$pdo = Database::getInstance();
		if (!$pdo) {
			echo json_encode(['success' => false, 'error' => __('api.db_error2')]);
			return;
		}

		$table = $prefix . 'files';
		$stmt = $pdo->prepare("SELECT `delete_token`, `user_id` FROM `{$table}` WHERE `id` = ?");
		$stmt->execute([$id]);
		$file = $stmt->fetch(PDO::FETCH_ASSOC);

		$sessionUser = (int) ($_SESSION['user_id'] ?? 0);
		$isOwner = $file
			&& $sessionUser > 0
			&& $file['user_id'] !== null
			&& (int) $file['user_id'] === $sessionUser;

		if (!$isOwner) {
			if (!$token) {
				echo json_encode(['success' => false, 'error' => __('api.missing_id_or_token2')]);
				return;
			}

			$ip = getClientIP();

			// Check Security Threshold. Kept ahead of the row lookup's verdict so a throttled
			// caller cannot use this endpoint to probe which ids exist.
			$failCount = Database::getSecurityEvent($ip, 'delete_fail');
			$threshold = (int) Database::getSetting('recaptcha_delete_token_threshold', 1);

			if ($threshold >= 0 && $failCount >= $threshold && Database::isRecaptchaEnabled()) {
				if (empty($captchaProof) || !Database::verifyUploadToken($captchaProof, $ip)) {
					echo json_encode(['success' => false, 'error' => __('api.captcha_required'), 'require_captcha' => true]);
					return;
				}
				// Valid proof -> Consume it
				Database::deleteUploadToken($captchaProof);
			}

			if (!$file) {
				echo json_encode(['success' => false, 'error' => __('api.file_not_found2')]);
				return;
			}

			// Verify token (bcrypt hash, with constant-time fallback for legacy plaintext tokens)
			if (!self::deleteTokenMatches($token, (string) $file['delete_token'])) {
				Database::incrementSecurityEvent($ip, 'delete_fail');
				echo json_encode(['success' => false, 'error' => __('api.bad_delete_token')]);
				return;
			}

			Database::clearSecurityEvent($ip, 'delete_fail');
		}

		// Reached either as the row's signed-in owner or with a verified capability token;
		// `$isOwner` implies the row exists, and the capability path returned above if it did not.
		$stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `id` = ?");
		$stmt->execute([$id]);

		// Close any moderation reports for this file (avoid orphaned entries).
		Database::deleteReportsByFileIds([$id]);

		FileManager::purgeFileArtifacts($id);

		echo json_encode(['success' => true]);
	}

	/**
	 * Browser-openable delete link (ShareX `DeletionURL`).
	 *
	 * ShareX opens this URL in a browser, so it has to be a GET that renders a page rather
	 * than the JSON POST `delete` uses. Authority is the same in both: the file's delete
	 * token, a high-entropy per-file secret shown once after upload. Anyone holding it can
	 * already delete via the POST path, so exposing the same capability over GET grants
	 * nothing new — but because a GET can be triggered by a third-party page, the deletion
	 * is not performed on load: the page asks for a confirmation that posts back.
	 */
	public static function handleDeleteLink()
	{
		$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		$id = (string) ($method === 'POST' ? ($_POST['id'] ?? '') : ($_GET['id'] ?? ''));
		$token = (string) ($method === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''));

		$title = __('dellink.title');
		$render = function (string $heading, string $message, string $state, ?array $deleteForm = null) use ($title) {
			header('Content-Type: text/html; charset=utf-8');
			header('X-Robots-Tag: noindex, nofollow');
			header('X-Content-Type-Options: nosniff');
			header('Cache-Control: no-store, private, max-age=0');
			header('Pragma: no-cache');
			header('Referrer-Policy: no-referrer');
			header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; font-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
			$icon = $state === 'ok' ? 'fa-circle-check' : ($state === 'ask' ? 'fa-triangle-exclamation' : 'fa-circle-xmark');
			require __DIR__ . '/../delete_link_page.php';
		};

		if (!FileManager::isValidFileId($id) || $token === '') {
			http_response_code(400);
			$render(__('dellink.bad_title'), __('api.missing_id_or_token'), 'bad');
			return;
		}

		$file = FileManager::getFile($id);
		if (!$file) {
			http_response_code(404);
			$render(__('dellink.bad_title'), __('api.file_not_found'), 'bad');
			return;
		}

		// Same ownership proof as the POST path (bcrypt hash, constant-time legacy fallback).
		if (!self::deleteTokenMatches($token, (string) $file['deleteToken'])) {
			http_response_code(403);
			$render(__('dellink.bad_title'), __('api.bad_delete_token'), 'bad');
			return;
		}

		if ($method === 'GET') {
			// A scanner may load this page, but it receives only a form. The random nonce is
			// bound to both the id and the presented capability and is valid once in this
			// browser session.
			$nonce = self::issueDeleteLinkNonce($id, $token);
			$render(
				__('dellink.confirm_title'),
				__('dellink.confirm_body', ['name' => $file['name']]),
				'ask',
				[
					'action' => APP_URL . '/api.php?action=delete_link',
					'id' => $id,
					'token' => $token,
					'nonce' => $nonce,
				]
			);
			return;
		}

		$nonce = (string) ($_POST['nonce'] ?? '');
		if (!self::consumeDeleteLinkNonce($nonce, $id, $token)) {
			http_response_code(403);
			$render(__('dellink.bad_title'), __('api.bad_delete_token'), 'bad');
			return;
		}

		if (FileManager::deleteFile($id, $token)) {
			$render(__('dellink.done_title'), __('dellink.done_body', ['name' => $file['name']]), 'ok');
		} else {
			http_response_code(500);
			$render(__('dellink.bad_title'), __('api.delete_file_failed'), 'bad');
		}
	}

	public static function handleUserFiles()
	{
		header('Content-Type: application/json');
		// Session already started at the top of api.php

		if (!isset($_SESSION['user_id'])) {
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}

		// pt 8: the same browse engine the all-files list runs on, hard-scoped to this account
		// and narrowed by whichever `mfilter.*` criteria the group allows. Still returns the
		// whole (filtered) set: the tab pages, sorts and searches in the browser, and that is
		// one account's uploads rather than the install's.
		$filters = self::authorisedMyFilters();
		$result = FileManager::browse([
			'owner_id' => (int) $_SESSION['user_id'],
			'owner_fields' => true,
			'unpaged' => true,
			'filters' => $filters,
			'sort' => 'uploaded_at',
			'order' => 'DESC',
		]);

		// Runda 9: enough for the client to stamp each row with "zniknie za X dni" — one
		// retention period per account (the group's effective days) and the alternative clock
		// start (`group_changed_at`, so a fresh downgrade does not mark years of uploads as
		// already-overdue). 0 days = nothing here expires by age and no marker renders.
		$me = Database::getUserById((int) $_SESSION['user_id']);
		$retDays = GroupRepository::retentionDays(Database::getUserEffectiveGroup((int) $_SESSION['user_id']));

		sendCachedJson([
			'success' => true,
			'files' => $result['files'],
			'applied_filters' => array_keys($filters),
			'retention' => [
				'days' => $retDays,
				'since' => (int) ($me['group_changed_at'] ?? 0),
				'warnDays' => max(0, (int) Database::getSetting('notify_expiry_days', 3)),
			],
		]);
	}

	/**
	 * Choice lists + permitted criteria for the "My files" filter panel (pt 8).
	 *
	 * Separate from `file_facets`: that one reports every uploader, IP and extension on the
	 * install, which is precisely what an ordinary account must not see. This reports only
	 * what the caller's own uploads contain.
	 */
	public static function handleMyFileFacets()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}

		// pt 6: both halves of the panel report their permissions. Only the file half was
		// listed here, so every collection criterion was filtered out of the modal and picking
		// the "Kolekcje" scope showed an empty dialog.
		$allowed = [];
		if (Permissions::has('myfiles.filters')) {
			foreach (array_keys(Permissions::MFILTER_PERMS) as $perm) {
				if (Permissions::has($perm)) {
					$allowed[] = $perm;
				}
			}
		}
		if (Permissions::has('myfiles.coll_filters')) {
			foreach (array_keys(Permissions::MCFILTER_PERMS) as $perm) {
				if (Permissions::has($perm)) {
					$allowed[] = $perm;
				}
			}
		}

		sendCachedJson([
			'success' => true,
			'facets' => FileManager::ownerFacets((int) $_SESSION['user_id']),
			'allowed' => $allowed,
			// The panel needs these separately: which scopes are worth offering at all, and
			// whether collection criteria may narrow anything.
			'canFilterFiles' => Permissions::has('myfiles.filters'),
			'canFilterCollections' => Permissions::has('myfiles.coll_filters'),
		]);
	}

	public static function handleUserSetFileOptions()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in2')]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$fileId = $input['file_id'] ?? '';
		if (!$fileId) {
			echo json_encode(['success' => false, 'error' => __('api.missing_file_id')]);
			return;
		}

		$expiryDays = max(0, (int) ($input['expiry_days'] ?? 0));
		$maxDownloads = max(0, (int) ($input['max_downloads'] ?? 0));
		$expiresAt = $expiryDays > 0 ? time() + ($expiryDays * 86400) : null;
		$oneTime = !empty($input['one_time']);

		$passwordRaw = $input['password'] ?? null;
		if ($passwordRaw !== null && !is_string($passwordRaw)) {
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		$password = $passwordRaw;
		$clearPassword = !empty($input['clear_password']);

		// A non-empty password must meet the same minimum as account passwords.
		// An empty password means "leave unchanged", so it is allowed through.
		if (!$clearPassword && $password !== null && $password !== '') {
			if (strlen($password) > InputLimits::PASSWORD_MAX) {
				echo json_encode(['success' => false, 'error' => __('api.password_too_long')]);
				return;
			}
			if (strlen($password) < 8) {
				echo json_encode(['success' => false, 'error' => __('api.pass_min8')]);
				return;
			}
		}

		// Admins may edit any file; regular users only their own.
		$ownerId = empty($_SESSION['is_admin']) ? (int) $_SESSION['user_id'] : null;

		$onLimit = ($input['on_limit_action'] ?? 'keep') === 'delete' ? 'delete' : 'keep';

		$ok = Database::setFileOptions($fileId, $ownerId, $expiresAt, $maxDownloads ?: null, $password, $clearPassword, $oneTime, $onLimit);
		if ($ok) {
			echo json_encode(['success' => true]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.save_failed')]);
		}
	}

	/* ---- Collections (Faza 3.2): group own files under one shareable ZIP link ---- */

	/**
	 * Split $fileIds into the ones that may go into a collection and the ones that may not (pt 1).
	 *
	 * A password on a file is a lock on its *contents*, and a collection hands those contents out
	 * as a ZIP. Owning the file is therefore not enough: without this check anyone who got at an
	 * account — a borrowed session, a shared machine — could bundle its protected uploads into an
	 * unprotected collection and read every one of them. So a protected file is admitted only on
	 * proof of the password itself, exactly as the all-files builder already demanded.
	 *
	 * Two proofs count:
	 *   - the file's password, in `passwords[<id>]`;
	 *   - the file's delete token, in `tokens[<id>]` — the secret handed to whoever uploaded it,
	 *     which the upload page still holds for the files in the current result list. That is the
	 *     "I just uploaded these and set that password a moment ago" case, and it can be switched
	 *     off with the `collection_upload_exempt` setting for installations that want the password
	 *     typed every single time.
	 *
	 * @return array{0: string[], 1: string[]} [allowed ids, rejected file names]
	 */
	private static function partitionProtectedFiles(
		array $fileIds,
		array $passwords,
		array $tokens,
		?int $ownerId = null
	): array
	{
		$exemptByToken = Database::getSetting('collection_upload_exempt', '1') === '1';
		$pdo = Database::getInstance();
		if (!$pdo || $fileIds === []) {
			return [[], $fileIds];
		}
		$in = implode(',', array_fill(0, count($fileIds), '?'));
		$params = $fileIds;
		$ownerSql = '';
		if ($ownerId !== null) {
			$ownerSql = ' AND `user_id` = ?';
			$params[] = $ownerId;
		}
		$stmt = $pdo->prepare(
			"SELECT `id`, `original_name`, `password_hash`, `delete_token`
			 FROM `" . Database::table('files') . "`
			 WHERE `id` IN ({$in}){$ownerSql}"
		);
		$stmt->execute($params);
		$rows = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$rows[(string) $row['id']] = $row;
		}
		$allowed = [];
		$rejected = [];
		foreach ($fileIds as $fid) {
			$row = $rows[$fid] ?? null;
			if (!$row) {
				$rejected[] = $fid;
				continue;
			}
			if (empty($row['password_hash'])) {
				$allowed[] = $fid;
				continue;
			}
			$pwRaw = $passwords[$fid] ?? '';
			$tokRaw = $tokens[$fid] ?? '';
			$pw = is_string($pwRaw) && strlen($pwRaw) <= InputLimits::PASSWORD_MAX ? $pwRaw : '';
			$tok = is_string($tokRaw) && strlen($tokRaw) <= InputLimits::SHORT_TEXT_MAX ? $tokRaw : '';
			if (($pw !== '' && password_verify($pw, (string) $row['password_hash']))
				|| ($exemptByToken && $tok !== ''
					&& self::deleteTokenMatches($tok, (string) $row['delete_token']))
			) {
				$allowed[] = $fid;
			} else {
				$rejected[] = $row['original_name'] ?? $fid;
			}
		}
		return [$allowed, $rejected];
	}

	private static function collectionProtectedPolicy(): string
	{
		$policy = (string) Database::getSetting('collection_protected_file_policy', 'prompt_skip');
		return in_array($policy, ['prompt_skip', 'remember_access', 'require_collection_password'], true)
			? $policy
			: 'prompt_skip';
	}

	private static function idsContainProtectedFiles(array $fileIds): bool
	{
		if (!$fileIds) {
			return false;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$in = implode(',', array_fill(0, count($fileIds), '?'));
		$stmt = $pdo->prepare(
			"SELECT 1 FROM `" . Database::table('files') . "`
			 WHERE `id` IN ({$in}) AND `password_hash` IS NOT NULL AND `password_hash` <> '' LIMIT 1"
		);
		$stmt->execute(array_values($fileIds));
		return (bool) $stmt->fetchColumn();
	}

	public static function handleUserCreateCollection()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}

		// pt 4: a group may be allowed files but not collections of them.
		if (!Permissions::has('myfiles.coll_create')) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}

		try {
			$input = readBoundedJsonBody(self::COLLECTION_JSON_MAX_BYTES);
			$fileIds = sanitizeFileIdList(
				$input['file_ids'] ?? [],
				self::COLLECTION_MAX_FILES
			);
		} catch (LengthException $e) {
			http_response_code(413);
			echo json_encode(['success' => false, 'error' => __('api.too_many_files')]);
			return;
		} catch (UnexpectedValueException $e) {
			http_response_code(400);
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		$name = trim((string) ($input['name'] ?? ''));
		if ($name === '') {
			$name = __('panel.cc.default_name');
		}
		if (mb_strlen($name) > 255) {
			$name = mb_substr($name, 0, 255);
		}

		if (count($fileIds) < self::COLLECTION_MIN_FILES) {
			echo json_encode([
				'success' => false,
				'error' => __('api.collection_min_files', ['n' => self::COLLECTION_MIN_FILES]),
			]);
			return;
		}

		// pt 1: password-protected files need their password (or the upload's delete token) here
		// too — owning a file is not permission to unwrap it.
		[$fileIds, $rejected] = self::partitionProtectedFiles(
			$fileIds,
			is_array($input['passwords'] ?? null) ? $input['passwords'] : [],
			is_array($input['tokens'] ?? null) ? $input['tokens'] : [],
			(int) $_SESSION['user_id']
		);
		if (!$fileIds) {
			echo json_encode(['success' => false, 'error' => __('api.collection_all_locked'), 'rejected' => $rejected]);
			return;
		}

		// C2: optional sharing controls — password, expiry (days), download cap, one-time link.
		$opts = [];
		$passwordRaw = $input['password'] ?? '';
		if (!is_string($passwordRaw)) {
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		$password = $passwordRaw;
		if ($password !== '') {
			if (strlen($password) > InputLimits::PASSWORD_MAX) {
				echo json_encode(['success' => false, 'error' => __('api.password_too_long')]);
				return;
			}
			if (strlen($password) < 8) {
				echo json_encode(['success' => false, 'error' => __('api.pw_short')]);
				return;
			}
			$opts['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
		}
		if (self::collectionProtectedPolicy() === 'require_collection_password'
			&& !isset($opts['password_hash'])
			&& self::idsContainProtectedFiles($fileIds)) {
			echo json_encode(['success' => false, 'error' => __('api.collection_password_required_for_protected')]);
			return;
		}
		$expiryDays = max(0, (int) ($input['expiry_days'] ?? 0));
		if ($expiryDays > 0) {
			$opts['expires_at'] = time() + ($expiryDays * 86400);
		}
		$maxDownloads = max(0, (int) ($input['max_downloads'] ?? 0));
		if ($maxDownloads > 0) {
			$opts['max_downloads'] = $maxDownloads;
		}
		if (!empty($input['one_time'])) {
			$opts['one_time'] = 1;
		}
		// Only meaningful together with a cap; stored regardless so the choice survives edits.
		if (($input['on_limit_action'] ?? 'keep') === 'delete') {
			$opts['on_limit_action'] = 'delete';
		}

		$id = generateFileId();
		$deleteToken = generateToken(32);
		$userId = (int) $_SESSION['user_id'];
		$result = Database::createCollectionWithFiles(
			$id,
			$name,
			$userId,
			password_hash($deleteToken, PASSWORD_DEFAULT),
			$fileIds,
			$userId,
			$opts,
			self::COLLECTION_MIN_FILES
		);
		if (!$result['success']) {
			echo json_encode([
				'success' => false,
				'error' => __('api.collection_failed'),
				'rejected' => $rejected,
			]);
			return;
		}
		$added = (int) $result['added'];

		echo json_encode([
			'success' => true,
			'id' => $id,
			'added' => $added,
			'rejected' => $rejected,
			'url' => APP_URL . '/collection.php?id=' . $id,
		]);
	}

	/**
	 * Which of the given files are password-protected (pt 7).
	 *
	 * The collection builder on the all-files list calls this before opening its modal, so it
	 * can ask for exactly the passwords it needs instead of failing at create time. Requires
	 * `files.collection_all`, and reports nothing beyond "this id is protected".
	 */
	public static function handleCheckFilesProtected()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id']) || !Permissions::has('files.collection_all')) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}

		try {
			$input = readBoundedJsonBody(self::COLLECTION_JSON_MAX_BYTES);
			$ids = sanitizeFileIdList(
				$input['file_ids'] ?? [],
				self::COLLECTION_MAX_FILES
			);
		} catch (LengthException $e) {
			http_response_code(413);
			echo json_encode(['success' => false, 'error' => __('api.too_many_files')]);
			return;
		} catch (UnexpectedValueException $e) {
			http_response_code(400);
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		if (!$ids) {
			echo json_encode(['success' => false, 'error' => __('api.no_files_selected')]);
			return;
		}

		$pdo = Database::getInstance();
		if (!$pdo) {
			echo json_encode(['success' => false, 'error' => __('api.db_error')]);
			return;
		}

		$table = Database::table('files');
		$in = implode(',', array_fill(0, count($ids), '?'));
		$stmt = $pdo->prepare("SELECT `id`, `original_name`, `password_hash` FROM `{$table}` WHERE `id` IN ({$in})");
		$stmt->execute($ids);

		$protected = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			if (!empty($row['password_hash'])) {
				$protected[] = ['id' => $row['id'], 'name' => $row['original_name']];
			}
		}

		echo json_encode(['success' => true, 'protected' => $protected]);
	}

	/**
	 * Build a collection from *any* user's files (pt 7), for groups holding
	 * `files.collection_all`.
	 *
	 * Password-protected files may be included only if the caller supplies the file's own
	 * password, verified here against its hash — so this permission grants the ability to
	 * gather files, never to bypass the protection someone put on one. Files whose password is
	 * missing or wrong are skipped and reported back by name.
	 */
	public static function handleCreateCollectionFromAll()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id']) || !Permissions::has('files.collection_all')) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}

		try {
			$input = readBoundedJsonBody(self::COLLECTION_JSON_MAX_BYTES);
			$ids = sanitizeFileIdList(
				$input['file_ids'] ?? [],
				self::COLLECTION_MAX_FILES
			);
		} catch (LengthException $e) {
			http_response_code(413);
			echo json_encode(['success' => false, 'error' => __('api.too_many_files')]);
			return;
		} catch (UnexpectedValueException $e) {
			http_response_code(400);
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		if (count($ids) < self::COLLECTION_MIN_FILES) {
			echo json_encode([
				'success' => false,
				'error' => __('api.collection_min_files', ['n' => self::COLLECTION_MIN_FILES]),
			]);
			return;
		}

		// Drop protected files we were not given the correct password for. No token exemption
		// here: these are other people's uploads, so the only proof that counts is the password.
		[$allowed, $rejected] = self::partitionProtectedFiles(
			$ids,
			is_array($input['passwords'] ?? null) ? $input['passwords'] : [],
			[],
			null
		);

		if (!$allowed) {
			echo json_encode(['success' => false, 'error' => __('api.collection_all_locked'), 'rejected' => $rejected]);
			return;
		}

		$name = trim((string) ($input['name'] ?? ''));
		if ($name === '') {
			$name = __('panel.cc.default_name');
		}
		if (mb_strlen($name) > 255) {
			$name = mb_substr($name, 0, 255);
		}

		$opts = [];
		$passwordRaw = $input['password'] ?? '';
		if (!is_string($passwordRaw)) {
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		$password = $passwordRaw;
		if ($password !== '') {
			if (strlen($password) > InputLimits::PASSWORD_MAX) {
				echo json_encode(['success' => false, 'error' => __('api.password_too_long')]);
				return;
			}
			if (strlen($password) < 8) {
				echo json_encode(['success' => false, 'error' => __('api.pw_short')]);
				return;
			}
			$opts['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
		}
		if (self::collectionProtectedPolicy() === 'require_collection_password'
			&& !isset($opts['password_hash'])
			&& self::idsContainProtectedFiles($allowed)) {
			echo json_encode(['success' => false, 'error' => __('api.collection_password_required_for_protected')]);
			return;
		}
		$expiryDays = max(0, (int) ($input['expiry_days'] ?? 0));
		if ($expiryDays > 0) {
			$opts['expires_at'] = time() + ($expiryDays * 86400);
		}
		$maxDownloads = max(0, (int) ($input['max_downloads'] ?? 0));
		if ($maxDownloads > 0) {
			$opts['max_downloads'] = $maxDownloads;
		}
		if (!empty($input['one_time'])) {
			$opts['one_time'] = 1;
		}
		// Only meaningful together with a cap; stored regardless so the choice survives edits.
		if (($input['on_limit_action'] ?? 'keep') === 'delete') {
			$opts['on_limit_action'] = 'delete';
		}

		$id = generateFileId();
		$deleteToken = generateToken(32);
		$userId = (int) $_SESSION['user_id'];
		$result = Database::createCollectionWithFiles(
			$id,
			$name,
			$userId,
			password_hash($deleteToken, PASSWORD_DEFAULT),
			$allowed,
			null,
			$opts,
			self::COLLECTION_MIN_FILES
		);
		if (!$result['success']) {
			echo json_encode([
				'success' => false,
				'error' => __('api.collection_failed'),
				'rejected' => $rejected,
			]);
			return;
		}
		$added = (int) $result['added'];

		Database::logAudit('collection_created_all', "collection #$id from $added file(s)");

		echo json_encode([
			'success' => true,
			'id' => $id,
			'added' => $added,
			'rejected' => $rejected,
			'url' => APP_URL . '/collection.php?id=' . $id,
		]);
	}

	/**
	 * Verify a password-protected collection's password (C2). On success returns a short-lived
	 * download token the ZIP endpoint (upload_server.py) can validate statelessly: it is an
	 * HMAC over "id|expiry" keyed by the collection's own delete_token hash (a per-collection
	 * secret already in the DB, so no shared secret or token table is needed).
	 */
	public static function handleVerifyCollectionPassword()
	{
		header('Content-Type: application/json');
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (string) ($input['id'] ?? '');
		$passwordRaw = $input['password'] ?? '';
		if (!is_string($passwordRaw)) {
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		$password = $passwordRaw;
		if ($id === '' || !preg_match('/^[a-zA-Z0-9]+$/', $id)) {
			echo json_encode(['success' => false, 'error' => __('collection.err_bad_id')]);
			return;
		}
		$col = Database::getCollection($id);
		if (!$col) {
			echo json_encode(['success' => false, 'error' => __('collection.err_gone')]);
			return;
		}
		if (!empty($col['expires_at']) && (int) $col['expires_at'] < time()) {
			echo json_encode(['success' => false, 'error' => __('collection.err_expired')]);
			return;
		}
		if (!empty($col['one_time']) && !empty($col['consumed_at'])) {
			echo json_encode(['success' => false, 'error' => __('collection.err_used')]);
			return;
		}
		if (empty($col['password_hash'])) {
			// Not protected — no token needed; the id-based link works directly.
			echo json_encode(['success' => true, 'token' => '']);
			return;
		}
		if (strlen($password) > InputLimits::PASSWORD_MAX) {
			echo json_encode(['success' => false, 'require_password' => true, 'error' => __('api.bad_password')]);
			return;
		}
		if (!password_verify($password, $col['password_hash'])) {
			echo json_encode(['success' => false, 'require_password' => true, 'error' => __('api.bad_password')]);
			return;
		}
		echo json_encode(['success' => true, 'token' => self::collectionToken($id, $col['delete_token'])]);
	}

	/** Build the stateless HMAC download token for a collection (see handleVerifyCollectionPassword). */
	private static function collectionToken(string $id, string $deleteTokenHash, int $ttl = 900): string
	{
		$expiry = time() + $ttl;
		return $expiry . '.' . hash_hmac('sha256', $id . '|' . $expiry, $deleteTokenHash);
	}

	/** Signed list of collection members unlocked for this one ZIP request. */
	private static function collectionMemberToken(string $id, string $deleteTokenHash, array $fileIds, int $ttl = 900): string
	{
		$payload = json_encode([
			'e' => time() + $ttl,
			'i' => array_values(array_unique(array_map('strval', $fileIds))),
		], JSON_UNESCAPED_SLASHES);
		if ($payload === false) {
			return '';
		}
		$encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
		$mac = hash_hmac('sha256', $id . '|' . $encoded, $deleteTokenHash);
		return $encoded . '.' . $mac;
	}

	/**
	 * "The person about to fetch this ZIP is account N" — signed, so the upload server can
	 * believe it.
	 *
	 * A collection ZIP is a public, id-based URL with no session behind it, which is why an
	 * owner downloading their own collection used to ring their own bell: the server serving
	 * the bytes had no idea who was asking. A plain `?viewer=7` would fix that and hand anyone
	 * a way to silence somebody else's notifications, so it is signed with the same key the
	 * collection's password token already uses — the collection's stored delete-token hash,
	 * which both sides read from the database and nobody else can see.
	 *
	 * Suppressing a notification is all this can ever do: the ZIP itself is public either way,
	 * so a forged value would buy an attacker nothing they did not already have.
	 */
	public static function collectionViewerTag(string $id, string $deleteTokenHash, int $ttl = 900): string
	{
		$userId = (int) ($_SESSION['user_id'] ?? 0);
		if (!$userId || $deleteTokenHash === '') {
			return '';
		}
		$expiry = time() + $ttl;
		$mac = hash_hmac('sha256', $userId . '|' . $id . '|' . $expiry, $deleteTokenHash);
		return $userId . '.' . $expiry . '.' . $mac;
	}

	/** The ZIP link for a collection, carrying the signed viewer tag when there is a session. */
	public static function collectionZipUrl(array $collection): string
	{
		$url = APP_URL . '/api/collection?id=' . urlencode((string) $collection['id']);
		$tag = self::collectionViewerTag((string) $collection['id'], (string) ($collection['delete_token'] ?? ''));
		return $tag === '' ? $url : $url . '&v=' . urlencode($tag);
	}

	public static function handleUserCollections()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		// pt 4: no collections surface for this group — an empty list rather than an error, so
		// the tab simply has nothing there instead of showing a failure.
		if (!Permissions::has('myfiles.collections')) {
			echo json_encode(['success' => true, 'collections' => []]);
			return;
		}

		$appUrl = APP_URL;
		$cols = array_map(function ($c) use ($appUrl) {
			// Member file names (for C3 in-collection search); empty for empty collections.
			$names = is_array($c['file_names'] ?? null) ? $c['file_names'] : [];
			return [
				'id' => $c['id'],
				'name' => $c['name'],
				'fileCount' => (int) $c['file_count'],
				'totalSize' => (int) $c['total_size'],
				'downloads' => (int) $c['downloads'],
				'createdAt' => (int) $c['created_at'],
				'url' => $appUrl . '/collection.php?id=' . $c['id'],
				// Signed so the ZIP server knows who is asking — see collectionViewerTag().
				'zipUrl' => self::collectionZipUrl($c),
				'fileNames' => $names,
				// C2 sharing controls, for the badges in "My Files".
				'hasPassword' => !empty($c['password_hash']),
				'expiresAt' => isset($c['expires_at']) ? (int) $c['expires_at'] : 0,
				'maxDownloads' => isset($c['max_downloads']) ? (int) $c['max_downloads'] : 0,
				'oneTime' => !empty($c['one_time']),
				'consumed' => !empty($c['one_time']) && !empty($c['consumed_at']),
				'onLimitAction' => $c['on_limit_action'] ?? 'keep',
			];
		}, Database::getUserCollections((int) $_SESSION['user_id']));

		echo json_encode(['success' => true, 'collections' => $cols]);
	}

	/**
	 * Put one existing file into one existing collection (pt 5).
	 *
	 * The counterpart of "select several files and make a collection": that one always builds
	 * something new, so filing a file into a collection that already exists meant recreating
	 * the collection from scratch.
	 *
	 * Two permissions, because they are two different acts. Adding **your own** file to **your
	 * own** collection is `myfiles.coll_add`. Adding **someone else's** file — reachable only
	 * from the all-files browser — is `files.coll_add`, and it is deliberately separate: a group
	 * trusted to organise its own uploads is not thereby trusted to scoop up everyone's.
	 *
	 * The collection is always the caller's own. A password-protected file still needs its
	 * password, because a collection serves its members' bytes as a ZIP: filing a locked file
	 * into an open collection would be a way to unlock it.
	 */
	public static function handleAddFileToCollection()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$userId = (int) $_SESSION['user_id'];

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$collectionId = trim((string) ($input['collection_id'] ?? ''));
		$fileId = trim((string) ($input['file_id'] ?? ''));
		if ($collectionId === '' || $fileId === '') {
			echo json_encode(['success' => false, 'error' => __('api.missing_file_id')]);
			return;
		}

		// The collection has to be one of ours — never someone else's, whatever was posted.
		$collection = Database::getCollection($collectionId);
		if (!$collection || (int) ($collection['user_id'] ?? 0) !== $userId) {
			echo json_encode(['success' => false, 'error' => __('api.collection_not_found')]);
			return;
		}

		$pdo = Database::getInstance();
		if (!$pdo) {
			echo json_encode(['success' => false, 'error' => __('api.db_error')]);
			return;
		}
		$stmt = $pdo->prepare("SELECT `id`, `user_id`, `original_name`, `password_hash` FROM `" . Database::table('files') . "` WHERE `id` = ?");
		$stmt->execute([$fileId]);
		$file = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$file) {
			echo json_encode(['success' => false, 'error' => __('api.file_not_found')]);
			return;
		}

		$isOwn = isset($file['user_id']) && (int) $file['user_id'] === $userId;
		if (!Permissions::has($isOwn ? 'myfiles.coll_add' : 'files.coll_add')) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}

		// A locked file needs its password — owning it is not the same as knowing it, and the
		// ZIP would hand the contents over either way.
		$passwords = [];
		if (isset($input['password']) && $input['password'] !== '') {
			$passwords[$fileId] = (string) $input['password'];
		}
		[$allowed] = self::partitionProtectedFiles([$fileId], $passwords, []);
		if (!$allowed) {
			echo json_encode(['success' => false, 'require_password' => true, 'error' => __('api.file_locked')]);
			return;
		}
		if (self::collectionProtectedPolicy() === 'require_collection_password'
			&& !empty($file['password_hash']) && empty($collection['password_hash'])) {
			echo json_encode(['success' => false, 'error' => __('api.collection_password_required_for_protected')]);
			return;
		}

		// ownerId scopes the insert to our own files for the own-file case; null is the
		// "collect what I do not own" path, which the permission above is exactly about.
		$added = Database::addFilesToCollection($collectionId, $allowed, $isOwn ? $userId : null);
		if ($added === 0) {
			// INSERT IGNORE returns 0 for a file that is already a member — not an error, and
			// saying "already in there" is more useful than a generic failure.
			echo json_encode(['success' => true, 'added' => 0, 'already' => true, 'name' => $collection['name']]);
			return;
		}

		Database::logAudit('collection_file_added', 'file ' . $fileId . ' → collection ' . $collectionId, $userId);
		echo json_encode(['success' => true, 'added' => $added, 'name' => $collection['name']]);
	}

	public static function handleUserRenameCollection()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (string) ($input['id'] ?? '');
		$name = trim((string) ($input['name'] ?? ''));
		if ($id === '' || $name === '') {
			echo json_encode(['success' => false, 'error' => __('api.missing_data')]);
			return;
		}
		if (mb_strlen($name) > 255) {
			$name = mb_substr($name, 0, 255);
		}
		$ownerId = empty($_SESSION['is_admin']) ? (int) $_SESSION['user_id'] : null;
		echo json_encode(['success' => Database::renameCollection($id, $name, $ownerId)]);
	}

	/**
	 * Edit a collection's name and sharing controls in one go (pt 17) — the collection
	 * counterpart of handleUserSetFileOptions. Owners edit their own; admins edit any.
	 *
	 * Password handling matches the per-file modal: an absent/empty `password` leaves the
	 * existing one untouched, `clear_password` removes it.
	 */
	/**
	 * A short-lived token for one collection-ZIP download (runda 10). The ZIP endpoint used
	 * to serve on the bare id; now the id alone is not a download — the token rides the same
	 * `download_tokens` table as single files (same TTL, same single-use, bound to the IP),
	 * and carries the signed-in downloader so Python can throttle by their group's limit.
	 * A password-protected collection still additionally requires the `t` password token.
	 */
	public static function handleCollectionZipToken()
	{
		header('Content-Type: application/json');
		$input = self::jsonInput();
		$id = (string) ($input['id'] ?? $_POST['id'] ?? $_GET['id'] ?? '');
		$collection = $id !== '' ? Database::getCollection($id) : null;
		if (!$collection) {
			echo json_encode(['success' => false, 'error' => __('api.collection_not_found')]);
			return;
		}
		// Friendly refusals here, so the page shows a sentence instead of the ZIP
		// endpoint's raw {"detail": …} — mirrors handleGetDownloadToken's checks.
		if (!empty($collection['expires_at']) && (int) $collection['expires_at'] < time()) {
			echo json_encode(['success' => false, 'error' => __('collection.err_expired')]);
			return;
		}
		if (!empty($collection['max_downloads'])
			&& (int) ($collection['downloads'] ?? 0) >= (int) $collection['max_downloads']) {
			echo json_encode(['success' => false, 'error' => __('download.err_limit')]);
			return;
		}
		if (!empty($collection['one_time']) && !empty($collection['consumed_at'])) {
			echo json_encode(['success' => false, 'error' => __('api.one_time_used')]);
			return;
		}
		$policy = self::collectionProtectedPolicy();
		$protected = array_values(array_filter(
			$collection['files'] ?? [],
			static fn(array $file): bool => !empty($file['is_protected'])
		));
		$memberToken = '';
		$skipped = [];
		if ($protected && $policy === 'require_collection_password' && empty($collection['password_hash'])) {
			echo json_encode(['success' => false, 'error' => __('api.collection_password_required_for_protected')]);
			return;
		}
		if ($protected && $policy === 'prompt_skip' && empty($collection['password_hash'])) {
			if (empty($input['confirm_member_passwords'])) {
				echo json_encode([
					'success' => false,
					'require_member_passwords' => true,
					'protected' => array_map(static fn(array $file): array => [
						'id' => (string) $file['id'],
						'name' => (string) $file['original_name'],
					], $protected),
				]);
				return;
			}
			$allIds = array_map(static fn(array $file): string => (string) $file['id'], $collection['files'] ?? []);
			[$allowedIds, $skipped] = self::partitionProtectedFiles(
				$allIds,
				is_array($input['passwords'] ?? null) ? $input['passwords'] : [],
				[]
			);
			if (!$allowedIds) {
				echo json_encode(['success' => false, 'error' => __('collection.no_unlocked_files')]);
				return;
			}
			$memberToken = self::collectionMemberToken(
				$id,
				(string) ($collection['delete_token'] ?? ''),
				$allowedIds
			);
		}
		$token = Database::createDownloadToken($id, getClientIP(), $_SESSION['user_id'] ?? null);
		if ($token) {
			echo json_encode([
				'success' => true,
				'token' => $token,
				'member_token' => $memberToken,
				'skipped' => $skipped,
			]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.token_gen_failed2')]);
		}
	}

	/** The collection's member list, in display order — feeds the settings modal (runda 9). */
	public static function handleUserCollectionFiles()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$id = (string) ($_GET['id'] ?? '');
		$ownerId = empty($_SESSION['is_admin']) ? (int) $_SESSION['user_id'] : null;
		$col = $id !== '' ? Database::getCollection($id) : null;
		if (!$col || ($ownerId !== null && (int) ($col['user_id'] ?? 0) !== $ownerId)) {
			echo json_encode(['success' => false, 'error' => __('api.collection_not_found')]);
			return;
		}
		echo json_encode([
			'success' => true,
			'files' => array_map(fn($f) => [
				'id' => (string) $f['id'],
				'name' => (string) $f['original_name'],
				'size' => (int) $f['size'],
				'mime' => (string) ($f['mime_type'] ?? ''),
			], $col['files'] ?? []),
		]);
	}

	/** Take one file out of one's own collection (runda 9). The file itself is untouched. */
	public static function handleUserCollectionRemoveFile()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$ownerId = empty($_SESSION['is_admin']) ? (int) $_SESSION['user_id'] : null;
		$ok = CollectionRepository::removeFile(
			(string) ($input['collection_id'] ?? ''),
			(string) ($input['file_id'] ?? ''),
			$ownerId
		);
		echo json_encode($ok ? ['success' => true] : ['success' => false, 'error' => __('api.collection_not_found')]);
	}

	/** Store the settings modal's new member order for one's own collection (runda 9). */
	public static function handleUserCollectionReorder()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		try {
			$input = readBoundedJsonBody(self::COLLECTION_JSON_MAX_BYTES);
			$order = sanitizeFileIdList(
				$input['order'] ?? [],
				self::COLLECTION_MAX_FILES
			);
		} catch (LengthException $e) {
			http_response_code(413);
			echo json_encode(['success' => false, 'error' => __('api.too_many_files')]);
			return;
		} catch (UnexpectedValueException $e) {
			http_response_code(400);
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		$ownerId = empty($_SESSION['is_admin']) ? (int) $_SESSION['user_id'] : null;
		$ok = $order && CollectionRepository::reorder((string) ($input['collection_id'] ?? ''), $order, $ownerId);
		echo json_encode($ok ? ['success' => true] : ['success' => false, 'error' => __('api.collection_not_found')]);
	}

	public static function handleUserUpdateCollection()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (string) ($input['id'] ?? '');
		if ($id === '') {
			echo json_encode(['success' => false, 'error' => __('api.missing_id')]);
			return;
		}

		$ownerId = empty($_SESSION['is_admin']) ? (int) $_SESSION['user_id'] : null;

		$col = Database::getCollection($id);
		if (!$col || ($ownerId !== null && (int) ($col['user_id'] ?? 0) !== $ownerId)) {
			echo json_encode(['success' => false, 'error' => __('api.collection_not_found')]);
			return;
		}

		$name = trim((string) ($input['name'] ?? ''));
		if ($name !== '') {
			if (mb_strlen($name) > 255) {
				$name = mb_substr($name, 0, 255);
			}
			Database::renameCollection($id, $name, $ownerId);
		}

		$opts = [];
		$expiryDays = max(0, (int) ($input['expiry_days'] ?? 0));
		$opts['expires_at'] = $expiryDays > 0 ? time() + ($expiryDays * 86400) : null;

		$maxDownloads = max(0, (int) ($input['max_downloads'] ?? 0));
		$opts['max_downloads'] = $maxDownloads > 0 ? $maxDownloads : null;

		$oneTime = !empty($input['one_time']);
		$opts['one_time'] = $oneTime ? 1 : 0;
		// Re-arming a one-time link clears the burned flag so the share works afresh, matching
		// FileRepository::setOptions for single files.
		if ($oneTime) {
			$opts['consumed_at'] = null;
		}

		$opts['on_limit_action'] = ($input['on_limit_action'] ?? 'keep') === 'delete' ? 'delete' : 'keep';

		if (!empty($input['clear_password'])) {
			if (self::collectionProtectedPolicy() === 'require_collection_password'
				&& self::idsContainProtectedFiles(array_column($col['files'] ?? [], 'id'))) {
				echo json_encode(['success' => false, 'error' => __('api.collection_password_required_for_protected')]);
				return;
			}
			$opts['password_hash'] = null;
		} else {
			$passwordRaw = $input['password'] ?? '';
			if (!is_string($passwordRaw)) {
				echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
				return;
			}
			$password = $passwordRaw;
			if ($password !== '') {
				if (strlen($password) > InputLimits::PASSWORD_MAX) {
					echo json_encode(['success' => false, 'error' => __('api.password_too_long')]);
					return;
				}
				if (strlen($password) < 8) {
					echo json_encode(['success' => false, 'error' => __('api.pw_short')]);
					return;
				}
				$opts['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
			}
		}

		$ok = Database::setCollectionOptions($id, $ownerId, $opts);
		echo json_encode(['success' => (bool) $ok]);
	}

	public static function handleUserDeleteCollection()
	{
		header('Content-Type: application/json');
		if (isset($_SESSION['user_id']) && !Permissions::has('myfiles.coll_delete')) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		if (!isset($_SESSION['user_id'])) {
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (string) ($input['id'] ?? '');
		if ($id === '') {
			echo json_encode(['success' => false, 'error' => __('api.missing_id')]);
			return;
		}
		$ownerId = empty($_SESSION['is_admin']) ? (int) $_SESSION['user_id'] : null;
		echo json_encode(['success' => Database::deleteCollection($id, $ownerId)]);
	}

	/* ---- API keys (Faza 3.3): per-user secrets for ShareX / programmatic upload ---- */

	public static function handleUserCreateApiKey()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}

		$maxKeys = 10; // per-user cap on active API keys
		$userId = (int) $_SESSION['user_id'];
		if (Database::countUserApiKeys($userId) >= $maxKeys) {
			echo json_encode(['success' => false, 'error' => __('api.keys_limit', ['n' => $maxKeys])]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$label = trim((string) ($input['label'] ?? ''));
		if (mb_strlen($label) > 100) {
			$label = mb_substr($label, 0, 100);
		}

		// The plaintext key is shown to the user exactly once; only its SHA-256 is stored.
		$key = 'fh_' . bin2hex(random_bytes(24));
		$keyHash = hash('sha256', $key);
		$prefix = substr($key, 0, 11);

		$id = Database::createApiKey($userId, $keyHash, $prefix, $label);
		if ($id === false) {
			echo json_encode(['success' => false, 'error' => __('api.key_failed')]);
			return;
		}

		echo json_encode([
			'success' => true,
			'id' => $id,
			'key' => $key,          // shown once, never stored in plaintext
			'prefix' => $prefix,
			'label' => $label,
			'endpoint' => APP_URL . '/api/sharex',
			'url_template' => APP_URL . '/download.php?id=',
			// Direct-bytes URL (renders in editors/chat) and the browser-openable delete link
			// ShareX uses for its history entries.
			'embed_template' => APP_URL . '/api.php?action=embed&id=',
			'delete_template' => APP_URL . '/api.php?action=delete_link&id=',
		]);
	}

	public static function handleUserApiKeys()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}

		$keys = array_map(function ($k) {
			return [
				'id' => (int) $k['id'],
				'prefix' => $k['key_prefix'],
				'label' => $k['label'],
				'createdAt' => (int) $k['created_at'],
				'lastUsedAt' => $k['last_used_at'] !== null ? (int) $k['last_used_at'] : 0,
			];
		}, Database::getUserApiKeys((int) $_SESSION['user_id']));

		echo json_encode(['success' => true, 'keys' => $keys]);
	}

	public static function handleUserRevokeApiKey()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (int) ($input['id'] ?? 0);
		if ($id <= 0) {
			echo json_encode(['success' => false, 'error' => __('api.missing_id')]);
			return;
		}
		echo json_encode(['success' => Database::revokeApiKey($id, (int) $_SESSION['user_id'])]);
	}

	/* ---- Webhooks (Faza 4.1): user-registered event endpoints ---- */

	public static function handleUserCreateWebhook()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}

		$maxWebhooks = 10; // per-user cap
		$allowedEvents = webhookAllowedEvents();
		$userId = (int) $_SESSION['user_id'];
		if (Database::countUserWebhooks($userId) >= $maxWebhooks) {
			echo json_encode(['success' => false, 'error' => __('api.webhooks_limit', ['n' => $maxWebhooks])]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$url = trim((string) ($input['url'] ?? ''));
		if (!webhookUrlAllowed($url)) {
			echo json_encode(['success' => false, 'error' => __('api.webhook_bad_url')]);
			return;
		}

		// Events: array or comma string; keep only known ones; default to all.
		$rawEvents = $input['events'] ?? $allowedEvents;
		if (is_string($rawEvents)) {
			$rawEvents = explode(',', $rawEvents);
		}
		$events = array_values(array_intersect(
			array_map(fn($e) => strtolower(trim((string) $e)), (array) $rawEvents),
			$allowedEvents
		));
		if (!$events) {
			$events = $allowedEvents;
		}

		$secret = bin2hex(random_bytes(16));
		$id = Database::createWebhook($userId, $url, $secret, implode(',', $events));
		if ($id === false) {
			echo json_encode(['success' => false, 'error' => __('api.webhook_failed')]);
			return;
		}

		echo json_encode([
			'success' => true,
			'id' => $id,
			'url' => $url,
			'events' => $events,
			'secret' => $secret, // shown once here; used to verify the X-FileHost-Signature (HMAC-SHA256)
		]);
	}

	public static function handleUserWebhooks()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$hooks = array_map(function ($w) {
			return [
				'id' => (int) $w['id'],
				'url' => $w['url'],
				'events' => array_map('trim', explode(',', (string) $w['events'])),
				'createdAt' => (int) $w['created_at'],
				'lastStatus' => $w['last_status'],
				'lastDeliveryAt' => $w['last_delivery_at'] !== null ? (int) $w['last_delivery_at'] : 0,
			];
		}, Database::getUserWebhooks((int) $_SESSION['user_id']));
		echo json_encode(['success' => true, 'webhooks' => $hooks]);
	}

	public static function handleUserDeleteWebhook()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (int) ($input['id'] ?? 0);
		if ($id <= 0) {
			echo json_encode(['success' => false, 'error' => __('api.missing_id')]);
			return;
		}
		echo json_encode(['success' => Database::deleteWebhook($id, (int) $_SESSION['user_id'])]);
	}

	// Set/clear a file's password from the upload result on the home page. Ownership is
	// proven by the file's delete token (the same secret shown after upload), so this works
	// for guests too — no login required.
	public static function handleSetFilePassword()
	{
		header('Content-Type: application/json');

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = is_string($input['id'] ?? null) ? $input['id'] : '';
		$token = is_string($input['token'] ?? null) ? $input['token'] : '';
		$clear = !empty($input['clear']);
		$passwordRaw = $input['password'] ?? '';
		if (!is_string($passwordRaw)) {
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		$password = $passwordRaw;

		if (!$id || !$token) {
			echo json_encode(['success' => false, 'error' => __('api.missing_id_or_token')]);
			return;
		}
		if (!$clear) {
			if (strlen($password) > InputLimits::PASSWORD_MAX) {
				echo json_encode(['success' => false, 'error' => __('api.password_too_long')]);
				return;
			}
			if (strlen($password) < 8) {
				echo json_encode(['success' => false, 'error' => __('api.pass_min8')]);
				return;
			}
		}

		$pdo = Database::getInstance();
		if (!$pdo) {
			echo json_encode(['success' => false, 'error' => __('api.db_error')]);
			return;
		}

		$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
		$table = $prefix . 'files';
		$stmt = $pdo->prepare("SELECT `delete_token` FROM `{$table}` WHERE `id` = ?");
		$stmt->execute([$id]);
		$file = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$file) {
			echo json_encode(['success' => false, 'error' => __('api.file_not_found')]);
			return;
		}

		// Same ownership proof as deletion (bcrypt hash, with constant-time legacy fallback).
		if (!self::deleteTokenMatches($token, (string) $file['delete_token'])) {
			echo json_encode(['success' => false, 'error' => __('api.bad_token')]);
			return;
		}

		$ok = Database::setFilePassword($id, $clear ? null : $password, $clear);
		echo json_encode(['success' => (bool) $ok, 'protected' => !$clear]);
	}

	public static function handleUserDeleteFiles()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in2')]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true);
		$password = $input['password'] ?? '';
		if (!is_string($password) || strlen($password) > InputLimits::PASSWORD_MAX) {
			echo json_encode(['success' => false, 'error' => __('api.bad_password2')]);
			return;
		}

		if (!Database::verifyUserPassword($_SESSION['user_id'], $password)) {
			echo json_encode(['success' => false, 'error' => __('api.bad_password2')]);
			return;
		}

		$deleted = FileManager::deleteAllUserFiles($_SESSION['user_id']);
		echo json_encode(['success' => true, 'deleted' => $deleted]);
	}
}
