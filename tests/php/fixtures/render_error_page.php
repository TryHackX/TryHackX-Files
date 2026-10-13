<?php
/**
 * Renders src/includes/error_page.php the way the ban page does, in its own process (the
 * template is guarded by APP_ROOT and should not leak that constant into the test run).
 *
 * usage: php render_error_page.php [dark|light] [icon-name]
 */
define('APP_ROOT', dirname(__DIR__, 3) . '/src');
define('APP_URL', 'http://localhost');
define('APP_NAME', 'FileHostTest');
define('APP_VERSION', '0.0.0-test');

$_COOKIE['theme'] = $argv[1] ?? 'dark';
$pageTitle = 'Access denied';
$pageIcon = $argv[2] ?? 'fa-ban';
$pageHeading = 'You are banned';
$pageMessage = 'Contact the operator.';
$pageMeta = ['Reason' => '<script>alert(1)</script>', 'Expires' => 'never'];

require APP_ROOT . '/includes/error_page.php';
