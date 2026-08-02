<?php
/**
 * Minimal PHP development-server router for request-level tests.
 *
 * The built-in server does not read public/.htaccess. Keep this deliberately narrow: only
 * emulate the public API v1 rewrite and let the server handle every real file itself.
 */
$path = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));
if (preg_match('#^/api/v1(?:/(.*))?/?$#', $path, $matches) === 1) {
	$_GET['path'] = $matches[1] ?? '';
	require dirname(__DIR__, 2) . '/public/apiv1.php';
	return true;
}

return false;
