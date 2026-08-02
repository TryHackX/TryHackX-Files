<?php
/**
 * Emits the CSRF token and a fetch() wrapper that auto-attaches it to same-origin
 * state-changing requests. Include this inside <head> on every page that calls the API.
 */
if (!defined('APP_ROOT')) {
	exit;
}
$__csrf = function_exists('csrfToken') ? csrfToken() : '';
?>
<meta name="csrf-token" content="<?= htmlspecialchars($__csrf, ENT_QUOTES) ?>">
<meta name="api-base" content="<?= htmlspecialchars(
	APP_URL . '/api.php',
	ENT_QUOTES | ENT_SUBSTITUTE,
	'UTF-8'
) ?>">
