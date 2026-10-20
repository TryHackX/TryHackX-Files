<?php
/**
 * Lightweight repository supply-chain and secret policy checks.
 *
 * This complements (rather than replaces) language-aware analyzers: it fails CI when a
 * third-party GitHub Action is tag-pinned, a lock file loses hashes or no longer satisfies the
 * requirements it was compiled from, a floating `latest` container appears, or a tracked file
 * contains a well-known credential shape. tests/php/RepositoryPolicyTest.php proves each rule
 * on a mutated copy.
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
		// Both `uses:` under a named step and the `- uses:` step shorthand.
		if (!preg_match('/^\s*(?:-\s+)?uses:\s*([^\s#]+)/', $line, $match)) {
			continue;
		}
		$reference = trim($match[1], '"\'');
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

/** A package name in PEP 503 form, so `Pillow`/`pillow` and `typing_extensions`/`typing-extensions` match. */
function policyNormaliseName(string $name): string
{
	return strtolower((string) preg_replace('/[-_.]+/', '-', $name));
}

/**
 * The pins of a pip lock file: normalised name => version, environment marker, hash count.
 *
 * Understands the `name==1.2.3 ; marker \` + `    --hash=sha256:...` layout that
 * `uv pip compile --generate-hashes` writes.
 *
 * @return array<string, array{version: string, marker: string, hashes: int}>
 */
function policyParseLock(string $content): array
{
	$pins = [];
	$current = null;
	foreach (preg_split('/\R/', $content) ?: [] as $line) {
		if (preg_match('/^([A-Za-z0-9][A-Za-z0-9._-]*)==([^\s;\\\\]+)\s*(?:;\s*([^\\\\]*?))?\s*\\\\?\s*$/', $line, $match)) {
			$current = policyNormaliseName($match[1]);
			$pins[$current] = ['version' => $match[2], 'marker' => trim($match[3] ?? ''), 'hashes' => 0];
			continue;
		}
		if ($current !== null && preg_match('/^\s+--hash=sha256:[0-9a-f]{64}\s*\\\\?\s*$/', $line)) {
			$pins[$current]['hashes']++;
		}
	}
	return $pins;
}

/**
 * The requirements of a human-maintained requirements file: normalised name => the version
 * specifiers on its line (`>=0.143.2`, `==0.17.1`; none for a bare name). Comments, options
 * such as `-r`, extras and environment markers are skipped.
 *
 * @return array<string, list<string>>
 */
function policyParseRequirements(string $content): array
{
	$requirements = [];
	foreach (preg_split('/\R/', $content) ?: [] as $line) {
		$line = (string) preg_replace('/(?:^|\s)#.*$/', '', $line);
		if (!preg_match('/^\s*([A-Za-z0-9][A-Za-z0-9._-]*)\s*(?:\[[^\]]*\])?\s*([^;]*)/', $line, $match)) {
			continue;
		}
		$specifiers = array_values(array_filter(
			array_map('trim', explode(',', $match[2])),
			static fn(string $specifier): bool => $specifier !== ''
		));
		$requirements[policyNormaliseName($match[1])] = $specifiers;
	}
	return $requirements;
}

/**
 * Whether a locked version satisfies one specifier. Only the numeric release segment is
 * compared (`0.143` equals `0.143.0`), which covers the final releases the locks pin; `null`
 * means the specifier is not understood, so the caller reports it instead of passing it.
 */
function policySatisfies(string $version, string $specifier): ?bool
{
	if (!preg_match('/^(~=|===|==|!=|<=|>=|<|>)\s*(\d+(?:\.\d+)*)(\.\*)?$/', $specifier, $match)
		|| !preg_match('/^(\d+(?:\.\d+)*)/', $version, $locked)) {
		return null;
	}
	[, $operator, $wanted] = $match;
	$wildcard = isset($match[3]) && $match[3] !== '';
	$have = array_map('intval', explode('.', $locked[1]));
	$want = array_map('intval', explode('.', $wanted));
	$order = 0;
	for ($i = 0, $n = max(count($have), count($want)); $i < $n && $order === 0; $i++) {
		$order = ($have[$i] ?? 0) <=> ($want[$i] ?? 0);
	}
	$prefix = static fn(array $parts): bool => array_slice($have + array_fill(0, count($parts), 0), 0, count($parts)) === $parts;
	if ($wildcard) {
		return match ($operator) {
			'==' => $prefix($want),
			'!=' => !$prefix($want),
			default => null,
		};
	}
	return match ($operator) {
		'==', '===' => $order === 0,
		'!=' => $order !== 0,
		'>=' => $order >= 0,
		'<=' => $order <= 0,
		'>' => $order > 0,
		'<' => $order < 0,
		// ~=1.4.5 means >=1.4.5 and ==1.4.*; a single segment is not a valid compatible release.
		'~=' => count($want) > 1 ? $order >= 0 && $prefix(array_slice($want, 0, -1)) : null,
		default => null,
	};
}

$lockPins = [];
foreach ([$root . '/requirements-lock.txt', $root . '/requirements-dev-lock.txt'] as $lock) {
	$content = is_file($lock) ? (string) file_get_contents($lock) : '';
	$relative = basename($lock);
	if ($content === '' || !str_contains($content, '--hash=sha256:')) {
		$errors[] = "{$relative} is missing SHA-256 package hashes";
	}
	if (preg_match('/^\s*(?:-e|--editable|https?:|git\+)/mi', $content)) {
		$errors[] = "{$relative} contains an editable or direct unpinned dependency";
	}
	$lockPins[$relative] = policyParseLock($content);
	foreach ($lockPins[$relative] as $name => $pin) {
		if ($pin['hashes'] < 1) {
			$errors[] = "{$relative} pins {$name} without a SHA-256 hash";
		}
	}
}

// The two lock files are compiled separately. A package both of them pin must have the same
// version and marker, otherwise CI tests one set of dependencies and production runs another.
$runtimePins = $lockPins['requirements-lock.txt'] ?? [];
$devPins = $lockPins['requirements-dev-lock.txt'] ?? [];
foreach ($runtimePins as $name => $pin) {
	if (!isset($devPins[$name])) {
		$errors[] = "requirements-dev-lock.txt does not pin {$name}, which requirements-lock.txt does";
	} elseif ($devPins[$name]['version'] !== $pin['version'] || $devPins[$name]['marker'] !== $pin['marker']) {
		$errors[] = "{$name} differs between the lock files ({$pin['version']} vs {$devPins[$name]['version']}); "
			. 'recompile both with the commands in tests/README.md';
	}
}

// Each human-maintained input must be what its lock was compiled from. A requirement added
// without recompiling would install nothing, and a floor raised for a security fix
// (`cryptography>=51`) would look done while production keeps installing the old pin. The dev
// lock also contains requirements.txt; the agreement check above covers that half.
foreach (['requirements.txt' => 'requirements-lock.txt', 'requirements-dev.txt' => 'requirements-dev-lock.txt'] as $input => $lock) {
	$inputText = is_file($root . '/' . $input) ? (string) file_get_contents($root . '/' . $input) : '';
	foreach (policyParseRequirements($inputText) as $name => $specifiers) {
		$pin = $lockPins[$lock][$name] ?? null;
		if ($pin === null) {
			$errors[] = "{$lock} does not pin {$name} from {$input}";
			continue;
		}
		foreach ($specifiers as $specifier) {
			$satisfied = policySatisfies($pin['version'], $specifier);
			if ($satisfied === null) {
				$errors[] = "{$input} gives {$name} the specifier '{$specifier}', which this check cannot compare";
			} elseif (!$satisfied) {
				$errors[] = "{$lock} pins {$name}=={$pin['version']}, which does not satisfy {$name}{$specifier} "
					. "from {$input}; recompile the locks with the commands in tests/README.md";
			}
		}
	}
}

// Container bases must be pinned by digest so a rebuild cannot silently change the image; the
// docker and docker-compose Dependabot ecosystems refresh tag and digest together.
foreach ([$root . '/docker/Dockerfile', $root . '/docker-compose.yml'] as $containerFile) {
	$content = is_file($containerFile) ? (string) file_get_contents($containerFile) : '';
	$relative = substr($containerFile, strlen($root) + 1);
	if (preg_match('/(?:FROM\s+\S+:latest\b|image:\s*\S+:latest\b)/i', $content)) {
		$errors[] = "{$relative} uses a floating latest tag";
	}
	$references = [];
	if (preg_match_all('/^\s*FROM\s+(?:--platform=\S+\s+)?(\S+)/mi', $content, $found)) {
		$references = array_merge($references, $found[1]);
	}
	if (preg_match_all('/^\s*image:\s*(\S+)/mi', $content, $found)) {
		$references = array_merge($references, $found[1]);
	}
	foreach ($references as $reference) {
		if (strcasecmp($reference, 'scratch') !== 0 && !preg_match('/@sha256:[0-9a-f]{64}$/', $reference)) {
			$errors[] = "{$relative} references {$reference} without an @sha256 digest";
		}
	}
}

// The release number is written in several places by hand. A release that updates only some of
// them leaves the sidecar, the e-mail worker or the upgrade guide announcing another version.
$versionSources = [
	'src/config.php' => "/define\\('APP_VERSION',\\s*'([^']+)'\\)/",
	'scripts/mail-worker.php' => "/define\\('APP_VERSION',\\s*'([^']+)'\\)/",
	'upload_server.py' => '/^SERVER_VERSION\s*=\s*"([^"]+)"/m',
	'docs/MIGRATION.md' => '/upgrades a running installation to \*\*([0-9][^*]*)\*\*/',
	'docs/P24.md' => '/\*\*Application version:\*\*\s*([0-9][^\s<]*)/',
	'docs/ROADMAP.md' => '/for version ([0-9][^\s]*)/',
];
$changelog = is_file($root . '/CHANGELOG.md') ? (string) file_get_contents($root . '/CHANGELOG.md') : '';
$versions = [];
if (preg_match('/^## \[(\d+\.\d+\.\d+[^\]]*)\]/m', $changelog, $match)) {
	$versions['CHANGELOG.md'] = $match[1];
} else {
	$errors[] = 'CHANGELOG.md has no released version heading';
}
foreach ($versionSources as $relative => $pattern) {
	$text = is_file($root . '/' . $relative) ? (string) file_get_contents($root . '/' . $relative) : '';
	if (preg_match($pattern, $text, $match)) {
		$versions[$relative] = $match[1];
	} else {
		$errors[] = "{$relative} does not state the application version";
	}
}
if (count(array_unique($versions)) > 1) {
	$summary = [];
	foreach ($versions as $relative => $value) {
		$summary[] = "{$relative}={$value}";
	}
	$errors[] = 'the application version differs between files: ' . implode(', ', $summary);
}

// Every PHPUnit phar the workflow downloads has its SHA-256 documented next to the command a
// developer runs locally, so the two cannot drift apart.
$workflowText = is_file($root . '/.github/workflows/ci.yml') ? (string) file_get_contents($root . '/.github/workflows/ci.yml') : '';
$testsReadme = is_file($root . '/tests/README.md') ? (string) file_get_contents($root . '/tests/README.md') : '';
if (preg_match_all('/phpunit_sha256:\s*([0-9a-f]{64})/', $workflowText, $found)) {
	foreach (array_unique($found[1]) as $hash) {
		if (!str_contains($testsReadme, $hash)) {
			$errors[] = "tests/README.md does not list the PHPUnit phar checksum {$hash} from .github/workflows/ci.yml";
		}
	}
}

// The same file tells developers which uv compiles the locks. It has to be the one
// requirements-dev.txt pins, otherwise the documented commands run a resolver CI never used.
$devRequirements = is_file($root . '/requirements-dev.txt') ? (string) file_get_contents($root . '/requirements-dev.txt') : '';
if (preg_match('/^\s*uv\s*==\s*([^\s;#]+)/mi', $devRequirements, $uvPin)) {
	preg_match_all('/\buv==([^\s`]+)/', $testsReadme, $documentedUv);
	if (array_values(array_unique($documentedUv[1])) !== [$uvPin[1]]) {
		$errors[] = "tests/README.md must install uv=={$uvPin[1]}, the version requirements-dev.txt pins";
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
// The command itself, not the failure label next to it that names it, and not a comment.
if (!preg_match('/^(?![ \t]*#).*\bsupervisorctl\s+-c\s+\S+\s+pid\s+upload\b/m', $dockerSmoke)) {
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
