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

            $assetBasePath = trim((string) ($_ENV['APP_BASE_PATH'] ?? ''));
            if ($assetBasePath === '' || $assetBasePath === '/') {
                if (PHP_SAPI === 'cli-server') {
                    $assetBasePath = '';
                } else {
                    $requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
                    $assetBasePath = str_starts_with($requestPath, '/itapiru') ? '/itapiru' : '';
                }
            } else {
                $assetBasePath = '/' . trim($assetBasePath, '/');
            }

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
            $twig->getEnvironment()->addGlobal('asset_base_path', $assetBasePath);

            return $twig;
        },
        DashboardRepository::class => function () {
            return new DashboardRepository(
                __DIR__ . '/../var/data/itapiru.sqlite',
                __DIR__ . '/content/dashboard.php'
            );
        },
    ]);
};
