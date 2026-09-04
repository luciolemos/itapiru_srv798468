<?php

require __DIR__ . '/../vendor/autoload.php';

$_ENV['APP_ENV'] = 'test';

$testDatabasePath = sys_get_temp_dir() . '/itapiru-phpunit-' . getmypid() . '.sqlite';
$_ENV['APP_DB_PATH'] = $testDatabasePath;
putenv('APP_DB_PATH=' . $testDatabasePath);

register_shutdown_function(static function () use ($testDatabasePath): void {
    if (is_file($testDatabasePath)) {
        unlink($testDatabasePath);
    }
});
