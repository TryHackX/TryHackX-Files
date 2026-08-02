<?php
/**
 * TryHackX Files REST API v1 (Faza 4.1)
 *
 * Stateless, versioned API authenticated by a per-user API key (the same keys used for
 * ShareX upload — see Faza 3.3). Reached via `/api/v1/<resource>` (rewritten to this file).
 *
 *   Authorization: Bearer <api-key>        (or  X-API-Key: <api-key>)
 *
 *   GET    /api/v1/                → API + auth info (whoami)
 *   GET    /api/v1/whoami         → same
 *   GET    /api/v1/files          → list the caller's files  (?page=&per_page=)
 *   GET    /api/v1/files/{id}     → one file's metadata
 *   DELETE /api/v1/files/{id}     → delete one of the caller's files
 *   GET    /api/v1/stats          → the caller's aggregate stats
 *
 * Errors: JSON `{ "error": "..." }` with a 4xx/5xx status. Success: the resource as JSON.
 */

ini_set('display_errors', 0);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/includes/FileManager.php';
require_once __DIR__ . '/../src/includes/Database.php';
require_once __DIR__ . '/../src/includes/RateLimiter.php';

header('Content-Type: application/json; charset=utf-8');
// Key-authenticated and cookie-free, so it is safe to allow cross-origin programmatic use.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, X-API-Key, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(204);
	exit;
}

/** Emit a JSON response and stop. */
function apiv1_respond($data, int $status = 200): never
{
	http_response_code($status);
	echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

function apiv1_error(string $message, int $status): never
{
	apiv1_respond(['error' => $message], $status);
}

if (defined('INSTALL_MODE') && INSTALL_MODE) {
	apiv1_error('Service not configured', 503);
}

// --- Authenticate ---------------------------------------------------------
// mod_php may expose the Authorization header under different keys (or drop it), so try the
// common ones; the .htaccess rewrite also re-injects it, sometimes with a REDIRECT_ prefix.
$authHeader = $_SERVER['HTTP_AUTHORIZATION']
	?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
	?? $_SERVER['HTTP_X_API_KEY']
	?? '';
if ($authHeader === '' && function_exists('apache_request_headers')) {
	// Some Apache/PHP setups don't populate HTTP_AUTHORIZATION; fall back to the raw headers.
	foreach (apache_request_headers() as $k => $v) {
		if (strcasecmp($k, 'Authorization') === 0 || strcasecmp($k, 'X-API-Key') === 0) {
			$authHeader = $v;
			break;
		}
	}
}

$identity = $authHeader !== '' ? Database::resolveApiKeyIdentity($authHeader) : null;
$userId = (int) ($identity['user_id'] ?? 0);
if (!$identity || !$userId) {
	header('WWW-Authenticate: Bearer');
	apiv1_error('Invalid or missing API key', 401);
}

// IP ban still applies to programmatic access.
try {
	if (Database::isBlacklisted('ip', getClientIP())) {
		apiv1_error('Your IP address has been banned.', 403);
	}
} catch (Exception $e) {
	// ignore — don't block on a ban-check failure
}

// Rate limiting (Faza 4.5): counted per API key, so one noisy integration can't spend
// another key's budget (and a shared NAT'd IP isn't punished collectively). Reported in
// this API's own error shape rather than the panel's.
$rate = RateLimiter::hit('key:' . (int) $identity['id'], 'api');
header('X-RateLimit-Limit: ' . $rate['limit']);
header('X-RateLimit-Remaining: ' . $rate['remaining']);
header('X-RateLimit-Reset: ' . $rate['reset']);
if (!$rate['allowed']) {
	$retry = max(1, $rate['reset'] - time());
	header('Retry-After: ' . $retry);
	apiv1_error('Rate limit exceeded — retry in ' . $retry . 's', 429);
}

// --- Route ----------------------------------------------------------------
$path = trim((string) ($_GET['path'] ?? ''), '/');
$segments = $path === '' ? [] : explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'];

// Empty or surplus path components are never aliases for an existing resource. Besides
// making the contract predictable, this prevents `/files//anything` from silently becoming
// the file-list endpoint.
if (in_array('', $segments, true)) {
	apiv1_error('Unknown resource', 404);
}

$appUrl = APP_URL;
$fileToApi = function (array $f) use ($appUrl): array {
	return [
		'id' => $f['id'],
		'name' => $f['original_name'],
		'mime' => $f['mime_type'] ?? '',
		'size' => (int) $f['size'],
		'downloads' => (int) $f['downloads'],
		'uploaded_at' => (int) $f['uploaded_at'],
		'one_time' => !empty($f['one_time']),
		'has_password' => !empty($f['password_hash']),
		'expires_at' => isset($f['expires_at']) ? (int) $f['expires_at'] : 0,
		'url' => $appUrl . '/download.php?id=' . $f['id'],
	];
};

$resource = $segments[0] ?? '';

// GET / , GET /whoami
if ($resource === '' || $resource === 'whoami') {
	if (count($segments) > ($resource === '' ? 0 : 1)) {
		apiv1_error('Unknown resource', 404);
	}
	if ($method !== 'GET') {
		apiv1_error('Method not allowed', 405);
	}
	$user = Database::getUserById($userId);
	apiv1_respond([
		'api' => defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files',
		'version' => 'v1',
		'app_version' => APP_VERSION,
		'user' => [
			'id' => $userId,
			'username' => $user['username'] ?? null,
			'role' => $user['role'] ?? null,
		],
		'upload_endpoint' => $appUrl . '/api/sharex',
	]);
}

// /stats
if ($resource === 'stats') {
	if (count($segments) !== 1) {
		apiv1_error('Unknown resource', 404);
	}
	if ($method !== 'GET') {
		apiv1_error('Method not allowed', 405);
	}
	$stats = Database::getUserStats($userId);
	apiv1_respond([
		'files' => (int) ($stats['files_count'] ?? 0),
		'total_size' => (int) ($stats['total_size'] ?? 0),
		'total_downloads' => (int) ($stats['total_downloads'] ?? 0),
	]);
}

// /files , /files/{id}
if ($resource === 'files') {
	if (count($segments) > 2) {
		apiv1_error('Unknown resource', 404);
	}
	$fileId = $segments[1] ?? '';

	if ($fileId === '') {
		if ($method !== 'GET') {
			apiv1_error('Method not allowed', 405);
		}
		$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 50)));
		$beforeAt = null;
		$beforeId = null;
		$cursor = trim((string) ($_GET['cursor'] ?? ''));
		if ($cursor !== '') {
			$decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
			$parts = $decoded !== false ? explode(':', $decoded, 2) : [];
			if (count($parts) !== 2
				|| !ctype_digit($parts[0])
				|| !FileManager::isValidFileId($parts[1])) {
				apiv1_error('Invalid cursor', 400);
			}
			$beforeAt = (int) $parts[0];
			$beforeId = $parts[1];
		}
		$result = Database::getUserFilesPage($userId, $perPage, $beforeAt, $beforeId);
		$next = $result['next'];
		$nextCursor = $next
			? rtrim(strtr(base64_encode($next['uploaded_at'] . ':' . $next['id']), '+/', '-_'), '=')
			: null;
		apiv1_respond([
			'per_page' => $perPage,
			'next_cursor' => $nextCursor,
			'files' => array_map($fileToApi, $result['files']),
		]);
	}

	if (!FileManager::isValidFileId($fileId)) {
		apiv1_error('Invalid file id', 400);
	}

	if ($method === 'GET') {
		$file = Database::getUserFileById($userId, $fileId);
		if (!$file) {
			apiv1_error('Not found', 404);
		}
		apiv1_respond($fileToApi($file));
	}

	if ($method === 'DELETE') {
		if (FileManager::deleteOwnedFile($fileId, $userId)) {
			apiv1_respond(['deleted' => $fileId]);
		}
		apiv1_error('Not found', 404);
	}

	apiv1_error('Method not allowed', 405);
}

apiv1_error('Unknown resource', 404);
