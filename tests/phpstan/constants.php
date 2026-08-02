<?php

/**
 * Analysis-only declarations for values created dynamically by src/config.php.
 *
 * PHPStan scans this file but the application never loads it at runtime.
 */
const APP_URL = 'https://filehost.invalid';
const APP_ROOT = __DIR__ . '/../../src';
const PROJECT_ROOT = __DIR__ . '/../..';
const DATA_DIR = PROJECT_ROOT . '/data';
const UPLOADS_DIR = PROJECT_ROOT . '/uploads';
