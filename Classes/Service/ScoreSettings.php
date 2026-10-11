<?php
declare(strict_types=1);

namespace Cysquale\PlaysGamesWidget\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class ScoreSettings
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ScoreCatalog $scoreCatalog,
    ) {
    }

    public function getEnabledScoreGames(): array
    {
        $catalog = $this->scoreCatalog->getCatalog();

        if ($catalog === []) {
            return [];
        }

        $config = $this->getConfiguration();
        $raw = (string)($config['scoreGames'] ?? '');

        $configuredKeys = [];

        foreach (explode(',', $raw) as $part) {
            $key = strtolower(trim($part));

            if (
                $key !== ''
                && preg_match('/^[a-z0-9\_-]+$/', $key) === 1
                && isset($catalog[$key])
            ) {
                $configuredKeys[$key] = true;
            }
        }

        /*
         * Empty global configuration means:
         * allow every published score game from the central catalogue.
         *
         * A non-empty value remains available as an advanced installation-wide
         * restriction.
         */
        if ($configuredKeys === []) {
            return array_keys($catalog);
        }

        return array_values(array_filter(
            array_keys($configuredKeys),
            static fn(string $key): bool => isset($catalog[$key]),
        ));
    }

    public function resolveWidgetScoreGames(string $selection): array
    {
        $available = $this->getEnabledScoreGames();

        if ($available === []) {
            return [];
        }

        $requested = [];

        foreach (explode(',', $selection) as $part) {
            $key = strtolower(trim($part));

            if ($key === '__all__') {
                return $available;
            }

            if (
                $key !== ''
                && preg_match('/^[a-z0-9\_-]+$/', $key) === 1
            ) {
                $requested[$key] = true;
            }
        }

        /*
         * Existing content elements created before this field existed have an
         * empty value. Treat them as "All" for backward compatibility.
         */
        if ($requested === []) {
            return $available;
        }

        return array_values(array_filter(
            $available,
            static fn(string $key): bool => isset($requested[$key]),
        ));
    }


    public function isGameEnabled(string $scoreKey): bool
    {
        return in_array(
            $scoreKey,
            $this->getEnabledScoreGames(),
            true
        );
    }

    public function isScoreInfinite(): bool
    {
        $config = $this->getConfiguration();
        $value = $config['scoreInfinite'] ?? false;

        return filter_var(
            $value,
            FILTER_VALIDATE_BOOL
        );
    }

    private function getConfiguration(): array
    {
        try {
            $config = $this->extensionConfiguration->get(
                'plays_games_widget'
            );

            return is_array($config) ? $config : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
