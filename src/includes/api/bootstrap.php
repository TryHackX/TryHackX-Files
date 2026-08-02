<?php
/**
 * Shared API request gates.
 *
 * The route is resolved before CSRF/auth/rate-limit decisions. This is important: security
 * policy comes from the route descriptor, never from an inferred "GET is probably safe"
 * convention. On success this file leaves $method, $action and $route for public/api.php.
 */

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$requestedAction = is_string($_GET['action'] ?? null) ? (string) $_GET['action'] : '';

$gateError = static function (int $status, string $message, array $headers = []): never {
	http_response_code($status);
	foreach ($headers as $name => $value) {
		header($name . ': ' . $value);
	}
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['success' => false, 'error' => $message]);
	exit;
};

// Same-origin only: scheme, host and normalised port must all match APP_URL. This runs before
// OPTIONS so a valid preflight receives the same origin headers as the eventual request.
$allowedOrigin = null;
if (!empty($_SERVER['HTTP_ORIGIN'])) {
	$originValue = filehostOriginOrNull((string) $_SERVER['HTTP_ORIGIN']);
	if ($originValue !== null && hash_equals(filehostCanonicalOrigin(APP_URL), $originValue)) {
		$allowedOrigin = $_SERVER['HTTP_ORIGIN'];
	}
}
if ($allowedOrigin !== null) {
	header('Access-Control-Allow-Origin: ' . $allowedOrigin);
	header('Vary: Origin');
	header('Access-Control-Allow-Credentials: true');
}

// OPTIONS is evaluated against the addressed route. An undeclared action/method does not get
// a permissive preflight response.
if ($method === 'OPTIONS') {
	$preflightMethod = strtoupper(trim((string) ($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? '')));
	if ($preflightMethod === '') {
		$preflightMethod = 'GET';
	}
	$preflight = ApiRoutePolicy::resolve($routes, $requestedAction, $preflightMethod);
	if ($preflight['status'] === 404) {
		$gateError(404, 'Unknown action');
	}
	if ($preflight['status'] === 405) {
		$gateError(405, __('api.method_not_allowed'), ['Allow' => implode(', ', $preflight['allow'])]);
	}
	$route = $preflight['route'];
	header('Access-Control-Allow-Methods: ' . implode(', ', $route['methods']));
	header('Access-Control-Allow-Headers: Content-Type, Content-Disposition, X-CSRF-Token, Idempotency-Key');
	http_response_code(204);
	exit;
}

$resolved = ApiRoutePolicy::resolve($routes, $requestedAction, $method);
if ($resolved['status'] === 404) {
	$gateError(404, 'Unknown action');
}
if ($resolved['status'] === 405) {
	$gateError(405, __('api.method_not_allowed'), ['Allow' => implode(', ', $resolved['allow'])]);
}
$action = $resolved['action'];
$route = $resolved['route'];

header('Access-Control-Allow-Methods: ' . implode(', ', $route['methods']));
header('Access-Control-Allow-Headers: Content-Type, Content-Disposition, X-CSRF-Token, Idempotency-Key');

// Refuse oversized browser/API bodies before a controller can copy them into memory. File
// transfers use the dedicated streaming server; the largest request handled here is a bounded
// advertising image upload.
$contentLength = max(0, (int) ($_SERVER['CONTENT_LENGTH'] ?? 0));
if ($contentLength > InputLimits::API_BODY_MAX) {
	$gateError(413, __('api.request_too_large'));
}

// The descriptor decides whether this request needs a browser-session CSRF token. External
// signed webhooks and capability routes opt out explicitly in routes.php.
if ($route['csrf'] && !csrfValidate()) {
	$gateError(403, __('api.csrf'));
}

if ($route['auth'] && empty($_SESSION['user_id'])) {
	$gateError(401, __('api.not_logged_in'));
}
if ($route['permission'] !== null) {
	$allowed = $route['permission'] === 'admin'
		? !empty($_SESSION['is_admin'])
		: Permissions::has($route['permission']);
	if (!$allowed) {
		$gateError(403, __('api.unauthorized'));
	}
}

if (!ApiRoutePolicy::reserveIdempotencyKey($route)) {
	$gateError(409, 'Missing or reused idempotency key');
}

// Maintenance mode admits only login, health, and an already validated administrator.
$maintenanceMode = Database::getSetting('maintenance_mode', '0') === '1';
if ($maintenanceMode && empty($_SESSION['is_admin']) && $action !== 'user_login' && $action !== 'health') {
	http_response_code(503);
	$maintMsg = trim((string) Database::getSetting('maintenance_message', ''));
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode([
		'success' => false,
		'error' => $maintMsg !== '' ? $maintMsg : __('error.maintenance_message'),
	]);
	exit;
}

// Global IP ban check. Before installation there may be no database yet.
try {
	if (Database::isBlacklisted('ip', getClientIP())) {
		$gateError(403, __('api.ip_banned'));
	}
} catch (Exception $e) {
	// Installation/readiness owns the database-unavailable response.
}

if (!INSTALL_MODE && $route['rate_limit'] !== null) {
	RateLimiter::enforce('ip:' . getClientIP(), $route['rate_limit']);
}
