<?php

declare(strict_types=1);

use App\Application\Settings\Settings;
use App\Application\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {

    // Global Settings Object
    $containerBuilder->addDefinitions([
        SettingsInterface::class => function () {
            $isProduction = strtolower((string) ($_ENV['APP_ENV'] ?? '')) === 'production';

            return new Settings([
                'displayErrorDetails' => !$isProduction,
                'logError'            => $isProduction,
                'logErrorDetails'     => $isProduction,
                'logger' => [
                    'name' => 'slim-app',
                    'path' => ($_ENV['APP_ENV'] ?? '') === 'test'
                        ? 'php://stderr'
                        : (isset($_ENV['docker']) ? 'php://stdout' : __DIR__ . '/../logs/app.log'),
                    'level' => Logger::DEBUG,
                ],
            ]);
        }
    ]);
};
