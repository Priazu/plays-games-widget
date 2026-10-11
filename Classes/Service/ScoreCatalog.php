<?php

declare(strict_types=1);

namespace Cysquale\PlaysGamesWidget\Service;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\RequestFactory;

final class ScoreCatalog
{
    private const CATALOG_URL = 'https://plays-games.com/api/score-catalog.php';
    private const CACHE_NAME = 'plays_games_widget_score_catalog';
    private const CACHE_KEY = 'catalog';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly CacheManager $cacheManager,
    ) {
    }

    public function getCatalog(bool $refresh = false): array
    {
        if ($refresh) {
            try {
                return $this->refresh();
            } catch (\Throwable) {
                // Keep using the last valid cached catalogue.
            }
        }

        $cached = $this->getCachedData();

        if (!empty($cached['catalog']) && is_array($cached['catalog'])) {
            return $cached['catalog'];
        }

        try {
            return $this->refresh();
        } catch (\Throwable) {
            return [];
        }
    }

    public function getCachedCatalog(): array
    {
        $cached = $this->getCachedData();

        return isset($cached['catalog']) && is_array($cached['catalog'])
            ? $cached['catalog']
            : [];
    }

    public function refresh(): array
    {
        $response = $this->requestFactory->request(
            self::CATALOG_URL,
            'GET',
            [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'timeout' => 8,
                'connect_timeout' => 5,
            ],
        );

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(
                'Catalogue endpoint returned HTTP ' . $response->getStatusCode()
            );
        }

        $decoded = json_decode(
            (string)$response->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (
            !is_array($decoded)
            || empty($decoded['ok'])
            || !isset($decoded['games'])
            || !is_array($decoded['games'])
        ) {
            throw new \RuntimeException('Catalogue response is not valid.');
        }

        $catalog = [];

        foreach ($decoded['games'] as $rawGame) {
            $game = $this->normalizeGame($rawGame);

            if ($game !== null) {
                $catalog[$game['score_key']] = $game;
            }
        }

        if ($catalog === []) {
            throw new \RuntimeException(
                'Catalogue contains no publishable score games.'
            );
        }

        $this->cacheManager
            ->getCache(self::CACHE_NAME)
            ->set(
                self::CACHE_KEY,
                [
                    'catalog' => $catalog,
                    'updated' => time(),
                ]
            );

        return $catalog;
    }

    public function getGame(string $scoreKey, bool $allowFetch = true): ?array
    {
        $scoreKey = $this->cleanKey($scoreKey);

        $catalog = $allowFetch
            ? $this->getCatalog()
            : $this->getCachedCatalog();

        return $catalog[$scoreKey] ?? null;
    }

    public function getLastUpdated(): int
    {
        $cached = $this->getCachedData();

        return (int)($cached['updated'] ?? 0);
    }

    public function getMetric(array $game, string $metricKey): ?array
    {
        $metricKey = $this->cleanMetricKey($metricKey);

        foreach ($game['metrics'] ?? [] as $metric) {
            if (($metric['metric_key'] ?? '') === $metricKey) {
                return $metric;
            }
        }

        return null;
    }

    public function getPrimaryMetric(array $game): ?array
    {
        $primaryKey = (string)($game['primary_metric_key'] ?? '');

        if ($primaryKey !== '') {
            $metric = $this->getMetric($game, $primaryKey);

            if ($metric !== null) {
                return $metric;
            }
        }

        foreach ($game['metrics'] ?? [] as $metric) {
            if (!empty($metric['is_primary'])) {
                return $metric;
            }
        }

        return null;
    }

    private function getCachedData(): array
    {
        $cached = $this->cacheManager
            ->getCache(self::CACHE_NAME)
            ->get(self::CACHE_KEY);

        return is_array($cached) ? $cached : [];
    }

    private function normalizeGame(mixed $rawGame): ?array
    {
        if (!is_array($rawGame)) {
            return null;
        }

        $scoreKey = $this->cleanKey(
            (string)($rawGame['score_key'] ?? '')
        );

        $name = trim((string)($rawGame['name'] ?? ''));
        $gameId = (int)($rawGame['plays_game_id'] ?? 0);

        if ($scoreKey === '' || $name === '' || $gameId <= 0) {
            return null;
        }

        $metrics = [];

        foreach (($rawGame['metrics'] ?? []) as $rawMetric) {
            if (!is_array($rawMetric)) {
                continue;
            }

            $metricKey = $this->cleanMetricKey(
                (string)($rawMetric['metric_key'] ?? '')
            );

            $label = trim((string)($rawMetric['label'] ?? ''));
            $type = strtoupper(
                trim((string)($rawMetric['type'] ?? ''))
            );
            $aggregation = strtoupper(
                trim((string)($rawMetric['aggregation'] ?? 'LATEST'))
            );

            if (
                $metricKey === ''
                || $label === ''
                || !in_array(
                    $aggregation,
                    ['MAX', 'MIN', 'LATEST'],
                    true
                )
            ) {
                continue;
            }

            $metrics[] = [
                'metric_key' => $metricKey,
                'label' => mb_substr($label, 0, 128),
                'type' => mb_substr(
                    $type !== '' ? $type : 'SCORE',
                    0,
                    32
                ),
                'aggregation' => $aggregation,
                'is_primary' => !empty($rawMetric['is_primary']),
                'rankable' => !empty($rawMetric['rankable']),
            ];
        }

        if ($metrics === []) {
            return null;
        }

        return [
            'score_key' => $scoreKey,
            'name' => mb_substr($name, 0, 255),
            'plays_game_id' => $gameId,
            'primary_metric_key' => $this->cleanMetricKey(
                (string)($rawGame['primary_metric_key'] ?? '')
            ),
            'metric_label' => mb_substr(
                (string)($rawGame['metric_label'] ?? ''),
                0,
                128
            ),
            'metric_type' => mb_substr(
                strtoupper((string)($rawGame['metric_type'] ?? '')),
                0,
                32
            ),
            'aggregation' => strtoupper(
                (string)($rawGame['aggregation'] ?? 'LATEST')
            ),
            'rankable' => !empty($rawGame['rankable']),
            'metrics' => $metrics,
        ];
    }

    private function cleanKey(string $value): string
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim($value))
        ) ?? '';
    }

    private function cleanMetricKey(string $value): string
    {
        return preg_replace(
            '/[^a-zA-Z0-9_.:-]/',
            '',
            trim($value)
        ) ?? '';
    }
}
