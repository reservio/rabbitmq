<?php
declare(strict_types = 1);

require_once __DIR__ . '/../vendor/autoload.php';

if (getenv('IS_PHPSTAN') !== '1') {
	// configure environment
	date_default_timezone_set('Europe/Prague');
	umask(0);
	Tester\Environment::setup();
}

// create temporary directory
define('TEMP_DIR', __DIR__ . '/tmp/' . (isset($_SERVER['argv']) ? md5(serialize($_SERVER['argv'])) : getmypid()));
if (getenv('IS_PHPSTAN') !== '1') {
	Tester\Helpers::purge(TEMP_DIR);
}

$_ENV = $_GET = $_POST = $_FILES = [];
