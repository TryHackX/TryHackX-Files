<?php
/**
 * /ads.txt (Faza 8) — the seller-authorization file ad networks fetch at the domain root.
 *
 * A physical ads.txt cannot be served here: .htaccess denies every *.txt (and that denial
 * protects requirements.txt et al., so it stays). Instead a rewrite maps /ads.txt onto
 * this emitter, which prints the `ads_txt_content` setting verbatim.
 *
 * Served whenever content is configured, independent of the ads master toggle: crawlers
 * fetch this file on their own schedule, and an operator pausing ads for a day should not
 * trip authorization warnings at the network.
 */
// Fetched by crawlers and cached publicly: no session, so no Set-Cookie on a cacheable reply.
define('FILEHOST_STATELESS_REQUEST', true);
require_once __DIR__ . '/../src/config.php';

$content = trim((string) Database::getSetting('ads_txt_content', ''));
if ($content === '') {
	http_response_code(404);
	exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=86400');
echo $content . "\n";
