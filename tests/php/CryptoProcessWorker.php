<?php

if ($argc < 3) {
	fwrite(STDERR, "usage: CryptoProcessWorker.php <data-dir> <mode> [value]\n");
	exit(64);
}

define('DATA_DIR', $argv[1]);
$mode = $argv[2];

if ($mode === 'production-missing') {
	putenv('APP_ENV=production');
	putenv('FILEHOST_ENV');
	putenv('APP_SECRET_KEY');
} elseif ($mode === 'production-env') {
	putenv('APP_ENV=production');
	putenv('APP_SECRET_KEY=' . ($argv[3] ?? ''));
} else {
	putenv('APP_ENV=development');
	putenv('FILEHOST_ENV');
	putenv('APP_SECRET_KEY');
}

require_once dirname(__DIR__, 2) . '/src/includes/Crypto.php';

try {
	switch ($mode) {
		case 'encrypt-fallback':
			echo Crypto::encrypt('race-probe');
			break;
		case 'decrypt-fallback':
			echo Crypto::decrypt((string) ($argv[3] ?? ''));
			break;
		case 'production-env':
			$value = (string) ($argv[4] ?? '');
			if (str_starts_with($value, 'enc:')) {
				echo Crypto::decrypt($value);
			} else {
				echo Crypto::encrypt($value);
			}
			break;
		case 'production-missing':
			Crypto::encrypt('must-fail');
			break;
		default:
			throw new InvalidArgumentException('unknown worker mode');
	}
} catch (Throwable $e) {
	fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
	exit(70);
}
