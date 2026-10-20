<?php

/**
 * A stand-in for the PayU REST API, for the request-level checkout tests (`php -S` router).
 *
 * Answers the OAuth token request and the order registration the way PayU does (the order
 * with a 302 whose JSON body carries the redirectUri), and serves the "payment page" that
 * redirectUri points at, on this same loopback server — another origin than the app's.
 */

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$port = (int) ($_SERVER['SERVER_PORT'] ?? 0);

if ($path === '/pl/standard/user/oauth/authorize') {
	header('Content-Type: application/json');
	echo json_encode(['access_token' => 'fake-access-token', 'token_type' => 'bearer', 'expires_in' => 43199]);
	return true;
}

if ($path === '/api/v2_1/orders') {
	$order = json_decode((string) file_get_contents('php://input'), true) ?: [];
	$orderId = 'FAKE' . strtoupper(bin2hex(random_bytes(5)));
	http_response_code(302);
	header('Content-Type: application/json');
	echo json_encode([
		'status' => ['statusCode' => 'SUCCESS'],
		'redirectUri' => 'http://127.0.0.1:' . $port . '/pay?orderId=' . $orderId
			. '&extOrderId=' . rawurlencode((string) ($order['extOrderId'] ?? '')),
		'orderId' => $orderId,
		'extOrderId' => (string) ($order['extOrderId'] ?? ''),
	]);
	return true;
}

if ($path === '/pay') {
	header('Content-Type: text/html; charset=utf-8');
	echo '<!DOCTYPE html><title>Fake provider</title><h1 id="fakeProvider">Fake payment page</h1>';
	return true;
}

http_response_code(404);
header('Content-Type: application/json');
echo '{}';
return true;
