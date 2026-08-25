<?php
$authBootstrapUser = isset($currentUser) ? $currentUser : null;
$authBootstrapJson = json_encode(
	$authBootstrapUser,
	JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($authBootstrapJson === false) {
	$authBootstrapJson = 'null';
}
$authBootstrapApi = (isset($appUrl) ? $appUrl : '') . '/api.php';
?>
<div id="authBootstrap" hidden
	data-api="<?= htmlspecialchars($authBootstrapApi, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
	data-user="<?= htmlspecialchars($authBootstrapJson, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
<script src="<?= htmlspecialchars(
	(isset($appUrl) ? $appUrl : '') . '/assets/js/captcha.js?v=' . APP_VERSION,
	ENT_QUOTES | ENT_SUBSTITUTE,
	'UTF-8'
) ?>"></script>
<script src="<?= htmlspecialchars(
	(isset($appUrl) ? $appUrl : '') . '/assets/js/auth.js?v=' . APP_VERSION,
	ENT_QUOTES | ENT_SUBSTITUTE,
	'UTF-8'
) ?>"></script>
