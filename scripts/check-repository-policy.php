<?php
/**
 * Lightweight repository supply-chain and secret policy checks.
 *
 * This complements (rather than replaces) language-aware analyzers: it fails CI when a
 * third-party GitHub Action is tag-pinned, a lock file loses hashes, a floating `latest`
 * container appears, or a tracked file contains a well-known credential shape.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$root = dirname(__DIR__);
$errors = [];

$workflowFiles = glob($root . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE) ?: [];
foreach ($workflowFiles as $workflow) {
	$lines = file($workflow, FILE_IGNORE_NEW_LINES) ?: [];
	foreach ($lines as $index => $line) {
		if (!preg_match('/^\s*uses:\s*([^\s#]+)\s*/', $line, $match)) {
			continue;
		}
		$reference = $match[1];
		if (str_starts_with($reference, './')) {
			continue;
		}
		$at = strrpos($reference, '@');
		$revision = $at === false ? '' : substr($reference, $at + 1);
		if (!preg_match('/^[a-f0-9]{40}$/', $revision)) {
			$relative = substr($workflow, strlen($root) + 1);
			$errors[] = "{$relative}:" . ($index + 1)
				. ' must pin each external action to a full commit SHA';
		}
	}
}

foreach ([$root . '/requirements-lock.txt', $root . '/requirements-dev-lock.txt'] as $lock) {
	$content = is_file($lock) ? (string) file_get_contents($lock) : '';
	$relative = basename($lock);
	if ($content === '' || !str_contains($content, '--hash=sha256:')) {
		$errors[] = "{$relative} is missing SHA-256 package hashes";
	}
	if (preg_match('/^\s*(?:-e|--editable|https?:|git\+)/mi', $content)) {
		$errors[] = "{$relative} contains an editable or direct unpinned dependency";
	}
}

foreach ([$root . '/docker/Dockerfile', $root . '/docker-compose.yml'] as $containerFile) {
	$content = is_file($containerFile) ? (string) file_get_contents($containerFile) : '';
	if (preg_match('/(?:FROM\s+\S+:latest\b|image:\s*\S+:latest\b)/i', $content)) {
		$errors[] = substr($containerFile, strlen($root) + 1) . ' uses a floating latest tag';
	}
}

// Keep the pre-install process contract and destructive smoke test in sync. A typo in the
// Supervisor program name previously made the lease-recovery smoke fail only inside Docker,
// while eager worker startup could exhaust its retries before the installer created config.
$supervisor = (string) @file_get_contents($root . '/docker/supervisord.conf');
$dockerfile = (string) @file_get_contents($root . '/docker/Dockerfile');
$waitForInstall = (string) @file_get_contents($root . '/docker/wait-for-install.sh');
$dockerSmoke = (string) @file_get_contents($root . '/tests/smoke/docker-transfer.sh');
if (!str_contains($supervisor, '[program:upload]')
	|| !str_contains($supervisor, 'command=/app/docker/wait-for-install.sh /app/venv/bin/python /app/upload_server.py')) {
	$errors[] = 'docker/supervisord.conf must keep the upload process named upload and gated by installation';
}
if (!str_contains($supervisor, 'command=/app/docker/wait-for-install.sh php /app/scripts/mail-worker.php')) {
	$errors[] = 'docker/supervisord.conf must gate the mail worker until installation';
}
if (!str_contains($supervisor, 'command=/app/docker/wait-for-install.sh /bin/sh -c "while true; do php /app/scripts/cleanup.php')) {
	$errors[] = 'docker/supervisord.conf must gate cleanup until installation';
}
if (!str_contains($dockerfile, 'chmod 0755 /app/docker/wait-for-install.sh')
	|| !str_contains($waitForInstall, 'while [ ! -f /app/config/config.local.php ]')) {
	$errors[] = 'docker/wait-for-install.sh must be executable and wait for the persistent config';
}
if (!str_contains($dockerSmoke, 'supervisorctl pid upload')) {
	$errors[] = 'tests/smoke/docker-transfer.sh must target the upload Supervisor program';
}

// Authentication and the public uploader are the first CSP-clean islands. Keep API messages
// on textContent/DOM nodes and event wiring in addEventListener so a later edit cannot silently
// restore the former reflected-DOM-XSS sinks or inline handlers.
foreach ([
	$root . '/src/includes/auth_modal.php',
	$root . '/src/includes/auth_scripts.php',
	$root . '/src/includes/header_ui.php',
	$root . '/src/includes/AdRenderer.php',
	$root . '/public/index.php',
	$root . '/public/collection.php',
	$root . '/public/download.php',
	$root . '/public/panel.php',
	$root . '/public/premium.php',
	$root . '/public/assets/js/auth.js',
	$root . '/public/assets/js/ads-consent.js',
	$root . '/public/assets/js/adsense-slots.js',
	$root . '/public/assets/js/collection.js',
	$root . '/public/assets/js/download.js',
	$root . '/public/assets/js/index.js',
	$root . '/public/assets/js/notifications.js',
	$root . '/public/assets/js/panel-events.js',
	$root . '/public/assets/js/panel-notifications.js',
	$root . '/public/assets/js/premium.js',
] as $authFile) {
	$content = is_file($authFile) ? (string) file_get_contents($authFile) : '';
	$relative = substr($authFile, strlen($root) + 1);
	if (preg_match('/\.(?:innerHTML|outerHTML)\s*=|insertAdjacentHTML\s*\(/', $content)) {
		$errors[] = "{$relative} must not render data through an HTML-string sink";
	}
	if (preg_match('/<[^>]+\son(?:click|submit|change|input|load|error)\s*=/i', $content)) {
		$errors[] = "{$relative} must use delegated listeners instead of inline handlers";
	}
}

$secretPatterns = [
	'private key' => '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/',
	'AWS access key' => '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',
	'GitHub token' => '/\bgh[pousr]_[A-Za-z0-9]{36,255}\b/',
	'Slack token' => '/\bxox[baprs]-[A-Za-z0-9-]{20,255}\b/',
	'Google API key' => '/\bAIza[0-9A-Za-z_-]{35}\b/',
	'Stripe live key' => '/\b(?:sk|rk)_live_[0-9A-Za-z]{16,255}\b/',
];

$excludedDirectories = [
	'.git', '.idea', '.pytest_cache', '.vscode', 'data', 'node_modules',
	'tools', 'uploads', 'venv',
];
$excludedNames = ['config.local.php', '.env'];
$iterator = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		static function (SplFileInfo $item) use ($excludedDirectories, $excludedNames): bool {
			if ($item->isDir()) {
				return !in_array($item->getFilename(), $excludedDirectories, true)
					&& !str_starts_with($item->getFilename(), 'venv-');
			}
			return !in_array($item->getFilename(), $excludedNames, true)
				&& $item->getSize() <= 2 * 1024 * 1024;
		}
	)
);
foreach ($iterator as $file) {
	/** @var SplFileInfo $file */
	if (!$file->isFile()) {
		continue;
	}
	$content = @file_get_contents($file->getPathname());
	if ($content === false || str_contains($content, "\0")) {
		continue;
	}
	foreach ($secretPatterns as $label => $pattern) {
		if (preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE)) {
			$offset = $match[0][1];
			$line = substr_count(substr($content, 0, $offset), "\n") + 1;
			$relative = substr($file->getPathname(), strlen($root) + 1);
			$errors[] = "{$relative}:{$line} resembles a {$label}";
		}
	}
}

if ($errors) {
	foreach ($errors as $error) {
		fwrite(STDERR, "POLICY: {$error}\n");
	}
	exit(1);
}

echo "Repository policy checks passed.\n";
