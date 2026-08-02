<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Session is started with hardened cookie flags inside config.php.
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/includes/FileManager.php';
require_once __DIR__ . '/../src/includes/Totp.php';
require_once __DIR__ . '/../src/includes/RateLimiter.php';

/*
 * API layer (Faza 5 · #1). The former ~2700-line monolith (one giant switch + ~70 handler
 * functions) is now a thin front controller: shared helpers + per-domain controllers +
 * a route table. Behaviour is unchanged — handler bodies moved verbatim into the controllers.
 */
require_once __DIR__ . '/../src/includes/api/ApiSupport.php';
require_once __DIR__ . '/../src/includes/api/AuthController.php';
require_once __DIR__ . '/../src/includes/api/FileController.php';
require_once __DIR__ . '/../src/includes/api/AdminController.php';
require_once __DIR__ . '/../src/includes/api/ReportController.php';
require_once __DIR__ . '/../src/includes/Markdown.php';
require_once __DIR__ . '/../src/includes/api/PremiumController.php';
require_once __DIR__ . '/../src/includes/api/PaymentController.php';
require_once __DIR__ . '/../src/includes/api/P24Controller.php';
require_once __DIR__ . '/../src/includes/api/NotificationController.php';
require_once __DIR__ . '/../src/includes/AdRenderer.php'; // also loads AdsController
require_once __DIR__ . '/../src/includes/api/RoutePolicy.php';

$routes = require __DIR__ . '/../src/includes/api/routes.php';

// Cross-cutting request gates. Leaves one already-authorised descriptor in $route.
$route = null;
require_once __DIR__ . '/../src/includes/api/bootstrap.php';
if (!is_array($route) || !isset($route['handler'])) {
	throw new LogicException('API bootstrap did not resolve a route');
}

try {
	if (!empty($route['redirect_status'])) {
		// A following Location header preserves an already selected 3xx status.
		http_response_code((int) $route['redirect_status']);
	}
	call_user_func($route['handler']);
	exit;
} catch (Throwable $e) {
	try {
		$correlationId = bin2hex(random_bytes(12));
	} catch (Throwable $idError) {
		$correlationId = substr(hash('sha256', uniqid('', true)), 0, 24);
	}
	$code = $e->getCode() ?: 500;
	if ($code < 100 || $code > 599)
		$code = 500;
	error_log(sprintf(
		'API failure [%s] %s %s: %s in %s:%d',
		$correlationId,
		(string) ($_SERVER['REQUEST_METHOD'] ?? ''),
		(string) ($_GET['action'] ?? ''),
		get_class($e),
		$e->getFile(),
		$e->getLine()
	));
	http_response_code($code);
	header('Content-Type: application/json');
	header('X-Correlation-ID: ' . $correlationId);
	echo json_encode([
		'success' => false,
		'error' => $code >= 500 ? __('common.error') : __('api.invalid_request'),
		'correlationId' => $correlationId,
	]);
}
