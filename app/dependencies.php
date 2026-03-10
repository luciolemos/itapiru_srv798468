<?php

declare(strict_types=1);

use App\Application\Settings\SettingsInterface;
use App\Infrastructure\Persistence\Dashboard\DashboardRepository;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;
use Twig\TwigFilter;
use Twig\TwigFunction;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        LoggerInterface::class => function (ContainerInterface $c) {
            $settings = $c->get(SettingsInterface::class);

            $loggerSettings = $settings->get('logger');
            $logger = new Logger($loggerSettings['name']);

            $processor = new UidProcessor();
            $logger->pushProcessor($processor);

            $handler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
            $logger->pushHandler($handler);

            return $logger;
        },
        Twig::class => function () {
            $twig = Twig::create(__DIR__ . '/../templates', [
                'cache' => false,
            ]);

            $twig->getEnvironment()->addFilter(new TwigFilter('label_case', static function (?string $value): string {
                $label = trim((string) $value);
                if ($label === '') {
                    return '';
                }

                $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
                $parts = explode(' ', mb_strtolower($label, 'UTF-8'));
                $stopWords = ['da', 'das', 'de', 'do', 'dos', 'e'];
                $acronyms = ['om', 'sfpc'];

                foreach ($parts as $index => $part) {
                    if (in_array($part, $acronyms, true)) {
                        $parts[$index] = mb_strtoupper($part, 'UTF-8');
                        continue;
                    }

                    if ($index > 0 && in_array($part, $stopWords, true)) {
                        continue;
                    }

                    $parts[$index] = mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
                }

                return implode(' ', $parts);
            }));

            $resolveBasePath = static function (): string {
                $configuredBasePath = trim((string) ($_ENV['APP_BASE_PATH'] ?? ''));
                if ($configuredBasePath !== '' && $configuredBasePath !== '/') {
                    return '/' . trim($configuredBasePath, '/');
                }

                if (PHP_SAPI === 'cli-server') {
                    return '';
                }

                $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
                $scriptDir = trim(str_replace('\\', '/', dirname($scriptName)));

                if ($scriptDir === '' || $scriptDir === '.' || $scriptDir === '/') {
                    return '';
                }

                return '/' . trim($scriptDir, '/');
            };

            $assetBasePath = $resolveBasePath();

            $allowedThemes = ['blue', 'red', 'green', 'violet', 'amber'];
            $allowedModes = ['light', 'dark'];
            $allowedIntensities = ['neutral', 'vivid'];

            $defaultTheme = strtolower((string) ($_ENV['APP_DEFAULT_THEME'] ?? 'amber'));
            $defaultMode = strtolower((string) ($_ENV['APP_DEFAULT_MODE'] ?? 'light'));
            $defaultDarkIntensity = strtolower((string) ($_ENV['APP_DEFAULT_DARK_INTENSITY'] ?? 'neutral'));

            if (!in_array($defaultTheme, $allowedThemes, true)) {
                $defaultTheme = 'amber';
            }
            if (!in_array($defaultMode, $allowedModes, true)) {
                $defaultMode = 'light';
            }
            if (!in_array($defaultDarkIntensity, $allowedIntensities, true)) {
                $defaultDarkIntensity = 'neutral';
            }

            $twig->getEnvironment()->addGlobal('default_theme', $defaultTheme);
            $twig->getEnvironment()->addGlobal('default_mode', $defaultMode);
            $twig->getEnvironment()->addGlobal('default_dark_intensity', $defaultDarkIntensity);
            $twig->getEnvironment()->addGlobal('app_base_path', $assetBasePath);
            $twig->getEnvironment()->addGlobal('asset_base_path', $assetBasePath);
            $twig->getEnvironment()->addFunction(new TwigFunction('app_url', static function (string $path = '') use ($assetBasePath): string {
                $normalizedPath = trim($path);
                if ($normalizedPath === '' || $normalizedPath === '/') {
                    return $assetBasePath !== '' ? $assetBasePath : '/';
                }

                return ($assetBasePath !== '' ? $assetBasePath : '') . '/' . ltrim($normalizedPath, '/');
            }));

            return $twig;
        },
        DashboardRepository::class => function () {
            $configuredBasePath = trim((string) ($_ENV['APP_BASE_PATH'] ?? ''));
            $appSlug = trim($configuredBasePath, '/');
            if ($appSlug === '') {
                $appSlug = basename(dirname(__DIR__));
            }

            $dbPath = trim((string) ($_ENV['APP_DB_PATH'] ?? ''));
            if ($dbPath === '') {
                $dbPath = __DIR__ . '/../var/data/' . $appSlug . '.sqlite';
            }

            return new DashboardRepository(
                $dbPath,
                __DIR__ . '/content/dashboard.php'
            );
        },
    ]);
};
