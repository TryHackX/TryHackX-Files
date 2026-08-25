<?php
/**
 * Shared API helpers (Faza 5 · #1).
 *
 * Free functions used across the API controllers: cached-JSON output (ETag/304), the
 * short-lived preview permit, the shared post-auth session grant, the per-session rate
 * limiter, and small input/URL sanitisers. Kept as plain functions so controller methods
 * call them exactly as the old monolithic api.php did (behaviour unchanged).
 */

/**
 * Emit a JSON body tagged with an ETag so the panel's live auto-refresh (A6)
 * can poll cheaply: when the client re-sends the matching `If-None-Match`, we
 * answer `304 Not Modified` with no body and the browser skips re-rendering.
 * Use only for idempotent, per-session GET reads (lists, dashboard). The tag is
 * an opaque content hash, so gzip/deflate on the way out does not affect it.
 */
function sendCachedJson(array $payload): void
{
	$body = json_encode($payload);
	$etag = '"' . md5($body) . '"';

	header('Content-Type: application/json');
	header('ETag: ' . $etag);
	header('Cache-Control: private, no-cache');

	$inm = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
	if ($inm !== '' && $inm === $etag) {
		http_response_code(304);
		exit;
	}

	echo $body;
}

// Preview permits are session-scoped and short-lived. A correct file password
// grants one (see FileController::handlePreviewAuth); previews never count as downloads.
function previewGranted(string $id): bool
{
	$ttl = 3600; // permit valid for 1 hour
	$granted = $_SESSION['preview_grants'][$id] ?? 0;
	return $granted > 0 && (time() - $granted) < $ttl;
}

/**
 * Grant the session after all factors have passed. Shared by the plain password login and
 * the 2FA second step, so both take exactly the same path (fresh session id, audit, token claim).
 */
function completeLogin(array $user, string $uploadToken = '', int $rememberSeconds = 0): void
{
	// Prevent session fixation: issue a fresh session ID on privilege change.
	session_regenerate_id(true);

	// Store only an authoritative, versioned identity. SessionAuth rechecks it against the
	// users table on every later request and synchronizes role/status changes.
	SessionAuth::establish($user);
	unset($_SESSION['pending_2fa']);
	$_SESSION['recent_auth_at'] = time();
	// This one *is* a credential check, so the panel window opens here too.
	$_SESSION['panel_auth_at'] = time();

	// This browser may already hold a device token from an earlier sign-in. Signing in again is
	// a statement about *this* device, so the previous one is replaced rather than left beside
	// it — and choosing "this browser session only" means it should stop being remembered at
	// all, not keep a token nobody can see they still have.
	$previousDevice = RememberTokenRepository::presentedCookie();
	if ($previousDevice !== '') {
		RememberTokenRepository::forget($previousDevice);
		RememberTokenRepository::sendCookie('', 0);
	}

	// A PHP session ends with the browser. "Stay signed in" therefore needs its own device
	// credential, issued only when the user asked for one and only as long as the
	// administrator permits.
	$lifetime = RememberTokenRepository::resolveLifetime($rememberSeconds);
	if ($lifetime > 0) {
		$cookie = RememberTokenRepository::issue(
			(int) $user['id'],
			$lifetime,
			function_exists('getClientIP') ? getClientIP() : '',
			(string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
		);
		if ($cookie !== '') {
			RememberTokenRepository::sendCookie($cookie, $lifetime);
		}
	}

	if (($user['role'] ?? 'user') === 'admin') {
		Database::logAudit('admin_login', 'user: ' . $user['username'], $user['id'], $user['username']);
	}

	if ($uploadToken !== '') {
		Database::claimUploadToken($uploadToken, $user['id']);
	}
}

/** A high-impact account/admin mutation must follow a fresh credential check. */
function hasRecentAuthentication(int $maxAgeSeconds = 600): bool
{
	$at = (int) ($_SESSION['recent_auth_at'] ?? 0);
	return $at > 0 && time() - $at <= max(60, $maxAgeSeconds);
}

/**
 * How long the panel stays open on one credential check, in seconds. 0 disables the gate.
 */
function panelReauthWindow(): int
{
	$minutes = (int) Database::getSetting('panel_reauth_minutes', 30);
	if ($minutes <= 0) {
		return 0;
	}
	return max(5, min(1440, $minutes)) * 60;
}

/** Whether this account is inside the group the panel gate applies to. */
function panelReauthApplies(?array $user): bool
{
	if (!$user || panelReauthWindow() === 0) {
		return false;
	}
	$scope = (string) Database::getSetting('panel_reauth_scope', 'staff');
	if ($scope === 'all') {
		return true;
	}
	$role = (string) ($user['role'] ?? 'user');
	return $role === 'admin' || $role === 'moderator';
}

/**
 * Whether the panel may be entered without asking for the password again.
 *
 * The window is idle-based: every panel request pushes it forward, so a session someone is
 * actually working in never interrupts them, while one left open on a borrowed machine closes
 * on its own. Coming back on a "stay signed in" cookie never opens it — that cookie proves
 * possession of a device, not knowledge of a password.
 */
function panelAuthorizationValid(?array $user): bool
{
	$window = panelReauthWindow();
	if (!panelReauthApplies($user)) {
		return true;
	}
	$at = (int) ($_SESSION['panel_auth_at'] ?? 0);
	return $at > 0 && time() - $at <= $window;
}

/** Push the idle window forward after a request the panel served. */
function touchPanelAuthorization(): void
{
	if (isset($_SESSION['panel_auth_at'])) {
		$_SESSION['panel_auth_at'] = time();
	}
}

/** JSON gate shared by destructive controller handlers. */
function requireRecentAuthentication(int $maxAgeSeconds = 600): bool
{
	if (hasRecentAuthentication($maxAgeSeconds)) {
		return true;
	}
	http_response_code(403);
	header('Content-Type: application/json');
	echo json_encode([
		'success' => false,
		'error' => __('api.recent_auth_required'),
		'code' => 'recent_auth_required',
	]);
	return false;
}

function checkRateLimit($action, $limit = 3, $seconds = 14400)
{
	if (!isset($_SESSION['rate_limits'])) {
		$_SESSION['rate_limits'] = [];
	}

	$key = $action . '_' . ($_SESSION['user_id'] ?? 'guest');
	$now = time();

	if (!isset($_SESSION['rate_limits'][$key])) {
		$_SESSION['rate_limits'][$key] = ['count' => 0, 'start_time' => $now];
	}

	$data = &$_SESSION['rate_limits'][$key];

	// Reset if time passed
	if ($now - $data['start_time'] > $seconds) {
		$data['count'] = 0;
		$data['start_time'] = $now;
	}

	if ($data['count'] >= $limit) {
		return false;
	}

	return true;
}

function incrementRateLimit($action)
{
	$key = $action . '_' . ($_SESSION['user_id'] ?? 'guest');
	if (isset($_SESSION['rate_limits'][$key])) {
		$_SESSION['rate_limits'][$key]['count']++;
	}
}

/** Decode a JSON request without allowing an unbounded body to occupy PHP memory. */
function readBoundedJsonBody(int $maxBytes = 1048576): array
{
	$maxBytes = max(1024, min(8 * 1024 * 1024, $maxBytes));
	$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
	if ($contentLength > $maxBytes) {
		throw new LengthException('Request body is too large.');
	}
	$raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
	if ($raw === false || strlen($raw) > $maxBytes) {
		throw new LengthException('Request body is too large.');
	}
	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		throw new UnexpectedValueException('Request body must be a JSON object.');
	}
	return $decoded;
}

/** Sanitise a bounded list of resource IDs using the application's canonical ID contract. */
function sanitizeFileIdList($raw, int $maxIds = 200): array
{
	if (!is_array($raw)) {
		return [];
	}
	if (count($raw) > $maxIds) {
		throw new LengthException('Too many file ids.');
	}
	$ids = array_map('strval', $raw);
	$ids = array_filter($ids, fn($x) => FileManager::isValidFileId($x));
	return array_values(array_unique($ids));
}

function webhookAllowedEvents(): array
{
	return ['upload', 'download', 'delete'];
}

/**
 * Basic SSRF guard for a webhook target. Requires http/https and blocks link-local /
 * cloud-metadata addresses (169.254.0.0/16). Loopback/private are allowed on purpose —
 * self-hosted setups often deliver to LAN services — but that's a deployment trust choice.
 */
function webhookIpIsPublic(string $ip): bool
{
	if (filter_var(
		$ip,
		FILTER_VALIDATE_IP,
		FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
	) === false) {
		return false;
	}

	$packed = @inet_pton($ip);
	if ($packed === false) {
		return false;
	}
	if (strlen($packed) === 4) {
		$octets = unpack('C4', $packed);
		$first = (int) ($octets[1] ?? 0);
		$second = (int) ($octets[2] ?? 0);
		if (($first === 100 && $second >= 64 && $second <= 127) || $first >= 224) {
			return false;
		}
	} elseif (strlen($packed) === 16 && ord($packed[0]) === 0xff) {
		return false;
	}

	return true;
}

function webhookUrlAllowed(string $url): bool
{
	if (mb_strlen($url) > 500) {
		return false;
	}
	$p = parse_url($url);
	if (!$p || empty($p['scheme']) || empty($p['host'])) {
		return false;
	}
	$scheme = strtolower((string) $p['scheme']);
	if (!in_array($scheme, ['http', 'https'], true)
		|| isset($p['user']) || isset($p['pass'])) {
		return false;
	}

	$port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);
	if (($scheme === 'http' && $port !== 80)
		|| ($scheme === 'https' && $port !== 443)) {
		return false;
	}

	$host = trim((string) $p['host'], '[]');
	if ($host === '' || str_contains($host, '%')) {
		return false;
	}
	if (filter_var($host, FILTER_VALIDATE_IP)) {
		return webhookIpIsPublic($host);
	}

	$host = rtrim(strtolower($host), '.');
	if (strlen($host) > 253
		|| !preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/iD', $host)) {
		return false;
	}

	$records = @dns_get_record($host, DNS_A | DNS_AAAA);
	if (!is_array($records) || $records === []) {
		return false;
	}
	$addresses = [];
	foreach ($records as $record) {
		$ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
		if ($ip === '' || !webhookIpIsPublic($ip)) {
			return false;
		}
		$addresses[$ip] = true;
	}
	return $addresses !== [];
}
