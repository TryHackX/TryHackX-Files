<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * scripts/check-repository-policy.php is what keeps CI from accepting a tag-pinned action, a
 * lock that no longer matches its requirements or a smoke test that stopped testing. Each case
 * below once passed it unnoticed. The real script runs on a copy of exactly the files it reads,
 * with one change applied, and has to refuse that change by name.
 */
final class RepositoryPolicyTest extends TestCase
{
	/** Every file the policy reads; the copy holds nothing else, so its secret scan stays small. */
	private const FILES = [
		'scripts/check-repository-policy.php',
		'.github/workflows/ci.yml',
		'requirements.txt',
		'requirements-dev.txt',
		'requirements-lock.txt',
		'requirements-dev-lock.txt',
		'docker/Dockerfile',
		'docker/supervisord.conf',
		'docker/wait-for-install.sh',
		'docker-compose.yml',
		'tests/smoke/docker-transfer.sh',
		'tests/README.md',
		'CHANGELOG.md',
		'src/config.php',
		'scripts/mail-worker.php',
		'upload_server.py',
		'docs/MIGRATION.md',
		'docs/P24.md',
		'docs/ROADMAP.md',
	];

	private string $tree = '';

	protected function setUp(): void
	{
		$this->tree = sys_get_temp_dir() . '/fh-policy-' . bin2hex(random_bytes(6));
		foreach (self::FILES as $relative) {
			$target = $this->tree . '/' . $relative;
			if (!is_dir(dirname($target))) {
				mkdir(dirname($target), 0700, true);
			}
			$this->assertTrue(copy(PROJECT_ROOT . '/' . $relative, $target), $relative);
		}
	}

	protected function tearDown(): void
	{
		if ($this->tree === '' || !is_dir($this->tree)) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->tree, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			/** @var SplFileInfo $item */
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($this->tree);
	}

	/** @return array{int, string} the exit status and everything the script printed */
	private function runPolicy(): array
	{
		$process = proc_open(
			[PHP_BINARY, $this->tree . '/scripts/check-repository-policy.php'],
			[1 => ['pipe', 'w'], 2 => ['redirect', 1]],
			$pipes
		);
		$this->assertIsResource($process);
		$output = (string) stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		return [proc_close($process), $output];
	}

	public function testTheRepositoryAsCommittedPasses(): void
	{
		[$status, $output] = $this->runPolicy();
		$this->assertSame(0, $status, $output);
		$this->assertStringContainsString('Repository policy checks passed.', $output);
	}

	/**
	 * file, regular expression, replacement, part of the refusal the script must print.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function bypasses(): array
	{
		return [
			'a tag-pinned action written as a `- uses:` step' => [
				'.github/workflows/ci.yml',
				'/\z/',
				"      - uses: actions/cache@v4\n",
				'must pin each external action to a full commit SHA',
			],
			'the smoke no longer asking Supervisor for the upload pid (only its failure label names it)' => [
				'tests/smoke/docker-transfer.sh',
				'/supervisorctl\s+-c\s+\S+\s+pid\s+upload\b/',
				'echo 0',
				'must target the upload Supervisor program',
			],
			'a runtime floor raised above the locked version, as for a security fix' => [
				'requirements.txt',
				'/^([A-Za-z0-9][A-Za-z0-9._-]*)>=[^\s;]+/m',
				'$1>=999.0',
				'which does not satisfy',
			],
			'a test tool pinned to another version than the dev lock' => [
				'requirements-dev.txt',
				'/^ruff==\S+/m',
				'ruff==0.0.1',
				'which does not satisfy ruff==0.0.1 from requirements-dev.txt',
			],
			'a test requirement the dev lock does not pin' => [
				'requirements-dev.txt',
				'/\z/',
				"mypy==1.20.0\n",
				'requirements-dev-lock.txt does not pin mypy from requirements-dev.txt',
			],
			'tests/README.md installing another uv than requirements-dev.txt pins' => [
				'tests/README.md',
				'/\buv==[^\s`]+/',
				'uv==0.0.1',
				'tests/README.md must install uv==',
			],
		];
	}

	#[DataProvider('bypasses')]
	public function testTheChangeIsRefused(string $relative, string $pattern, string $replacement, string $expected): void
	{
		$this->change($relative, $pattern, $replacement);

		[$status, $output] = $this->runPolicy();
		$this->assertSame(1, $status, $output);
		$this->assertStringContainsString($expected, $output);
	}

	/**
	 * Equivalent spellings stay accepted: the version comparison is numeric (`0.143` is below
	 * `0.143.2`), ranges and compatible releases are understood, and comments, case and quoting
	 * do not matter.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function equivalents(): array
	{
		return [
			'a shorter floor' => ['requirements.txt', '/^fastapi>=\S+/m', 'fastapi>=0.143'],
			'a range' => ['requirements.txt', '/^uvicorn>=\S+/m', 'uvicorn>=0.54,<1'],
			'a compatible release, other case and spacing' => ['requirements.txt', '/^Pillow>=\S+/m', 'pillow ~= 13.0'],
			'a trailing comment' => ['requirements-dev.txt', '/^(pytest>=\S+)/m', '$1  # test runner'],
			'a quoted action reference' => ['.github/workflows/ci.yml', '/uses: (actions\/checkout@[0-9a-f]{40})/', 'uses: "$1"'],
		];
	}

	#[DataProvider('equivalents')]
	public function testAnEquivalentSpellingStillPasses(string $relative, string $pattern, string $replacement): void
	{
		$this->change($relative, $pattern, $replacement);

		[$status, $output] = $this->runPolicy();
		$this->assertSame(0, $status, $output);
	}

	private function change(string $relative, string $pattern, string $replacement): void
	{
		$path = $this->tree . '/' . $relative;
		$before = (string) file_get_contents($path);
		$after = (string) preg_replace($pattern, $replacement, $before, 1);
		$this->assertNotSame($before, $after, "the change to {$relative} applies to the current file");
		file_put_contents($path, $after);
	}
}
