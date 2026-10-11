<?php

declare(strict_types=1);

namespace Cysquale\PlaysGamesWidget\Service;

use Doctrine\DBAL\ParameterType;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ScoreStorage
{
    private const TABLE = 'tx_playsgameswidget_score';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Stores one validated metric for a logged-in TYPO3 frontend user.
     */
    public function saveMetric(
        int $feUserUid,
        array $game,
        array $metric,
        mixed $incoming
    ): array {
        if ($feUserUid <= 0) {
            throw new \InvalidArgumentException(
                'A logged-in TYPO3 frontend user is required.'
            );
        }

        $normalized = $this->normalizeIncomingValue($incoming);

        if ($normalized === null) {
            throw new \InvalidArgumentException(
                'Metric value is not valid.'
            );
        }

        $gameId = (int)($game['plays_game_id'] ?? 0);
        $scoreKey = (string)($game['score_key'] ?? '');
        $metricKey = (string)($metric['metric_key'] ?? '');

        if ($gameId <= 0 || $scoreKey === '' || $metricKey === '') {
            throw new \InvalidArgumentException(
                'Metric definition is incomplete.'
            );
        }

        $aggregation = strtoupper(
            (string)($metric['aggregation'] ?? 'LATEST')
        );

        if (!in_array($aggregation, ['MAX', 'MIN', 'LATEST'], true)) {
            $aggregation = 'LATEST';
        }

        $connection = $this->connectionPool
            ->getConnectionForTable(self::TABLE);

        return $connection->transactional(
            function (Connection $connection) use (
                $feUserUid,
                $game,
                $metric,
                $normalized,
                $gameId,
                $scoreKey,
                $metricKey,
                $aggregation
            ): array {
                $existing = $connection->fetchAssociative(
                    'SELECT * FROM ' . self::TABLE . '
                     WHERE fe_user_uid = ?
                       AND game_id = ?
                       AND metric_key = ?',
                    [
                        $feUserUid,
                        $gameId,
                        $metricKey,
                    ],
                    [
                        ParameterType::INTEGER,
                        ParameterType::INTEGER,
                        ParameterType::STRING,
                    ]
                );

                $now = time();

                $currentValue = $normalized['numeric'];
                $currentText = $normalized['text'];

                $bestValue = $currentValue;
                $bestText = $currentText;
                $attempts = 1;

                if (is_array($existing)) {
                    $attempts = ((int)$existing['attempts']) + 1;

                    if ($currentValue !== null) {
                        $oldBest = $existing['best_value'] !== null
                            ? (float)$existing['best_value']
                            : null;

                        if ($aggregation === 'MAX' && $oldBest !== null) {
                            $bestValue = max($oldBest, $currentValue);
                        } elseif (
                            $aggregation === 'MIN'
                            && $oldBest !== null
                        ) {
                            $bestValue = min($oldBest, $currentValue);
                        } elseif ($aggregation === 'LATEST') {
                            $bestValue = $currentValue;
                        } elseif ($oldBest !== null) {
                            $bestValue = $oldBest;
                        }

                        $bestText = null;
                    } else {
                        $bestValue = null;

                        $existingBestText = trim(
                            (string)($existing['best_text'] ?? '')
                        );

                        $bestText = $aggregation === 'LATEST'
                            ? $currentText
                            : (
                                $existingBestText !== ''
                                    ? $existingBestText
                                    : $currentText
                            );
                    }
                }

                $fields = [
                    'fe_user_uid' => $feUserUid,
                    'game_id' => $gameId,
                    'score_key' => $scoreKey,
                    'game_name' => (string)(
                        $game['name'] ?? $scoreKey
                    ),
                    'metric_key' => $metricKey,
                    'metric_label' => (string)(
                        $metric['label'] ?? $metricKey
                    ),
                    'metric_type' => (string)(
                        $metric['type'] ?? 'SCORE'
                    ),
                    'aggregation' => $aggregation,
                    'is_primary' => !empty($metric['is_primary'])
                        ? 1
                        : 0,
                    'rankable' => !empty($metric['rankable'])
                        ? 1
                        : 0,
                    'current_value' => $currentValue,
                    'best_value' => $bestValue,
                    'current_text' => $currentText,
                    'best_text' => $bestText,
                    'attempts' => $attempts,
                    'updated_at' => $now,
                ];

                if (is_array($existing)) {
                    $connection->update(
                        self::TABLE,
                        $fields,
                        ['uid' => (int)$existing['uid']]
                    );
                } else {
                    $fields['created_at'] = $now;

                    $connection->insert(
                        self::TABLE,
                        $fields
                    );
                }

                return [
                    'score_key' => $scoreKey,
                    'game_id' => $gameId,
                    'metric_key' => $metricKey,
                    'metric_label' => $fields['metric_label'],
                    'metric_type' => $fields['metric_type'],
                    'aggregation' => $aggregation,
                    'current_value' => $currentValue,
                    'best_value' => $bestValue,
                    'current_text' => $currentText,
                    'best_text' => $bestText,
                    'attempts' => $attempts,
                ];
            }
        );
    }

    /**
     * Returns leaderboard rows for one rankable metric.
     */
    public function getLeaderboard(
        array $game,
        array $metric,
        int $limit = 10
    ): array {
        if (empty($metric['rankable'])) {
            return [];
        }

        $scoreKey = (string)($game['score_key'] ?? '');
        $metricKey = (string)($metric['metric_key'] ?? '');

        if ($scoreKey === '' || $metricKey === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));

        $aggregation = strtoupper(
            (string)($metric['aggregation'] ?? 'MAX')
        );

        $direction = $aggregation === 'MIN'
            ? 'ASC'
            : 'DESC';

        $connection = $this->connectionPool
            ->getConnectionForTable(self::TABLE);

        $now = time();

        $sql = '
            SELECT
                s.fe_user_uid,
                s.best_value,
                s.updated_at,
                u.username,
                u.name
            FROM ' . self::TABLE . ' s
            INNER JOIN fe_users u
                ON u.uid = s.fe_user_uid
            WHERE s.score_key = ?
              AND s.metric_key = ?
              AND s.rankable = 1
              AND s.best_value IS NOT NULL
              AND u.deleted = 0
              AND u.disable = 0
              AND (u.starttime = 0 OR u.starttime <= ?)
              AND (u.endtime = 0 OR u.endtime > ?)
            ORDER BY
                s.best_value ' . $direction . ',
                s.updated_at ASC,
                s.fe_user_uid ASC
            LIMIT ' . $limit;

        $rows = $connection->fetchAllAssociative(
            $sql,
            [
                $scoreKey,
                $metricKey,
                $now,
                $now,
            ],
            [
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
            ]
        );

        $output = [];
        $rank = 0;
        $position = 0;
        $previousValue = null;

        foreach ($rows as $row) {
            $position++;

            $valueKey = (string)$row['best_value'];

            if (
                $previousValue === null
                || $valueKey !== $previousValue
            ) {
                $rank = $position;
                $previousValue = $valueKey;
            }

            $userId = (int)$row['fe_user_uid'];

            $name = trim((string)($row['name'] ?? ''));

            if ($name === '') {
                $name = trim(
                    (string)($row['username'] ?? '')
                );
            }

            if ($name === '') {
                $name = 'Player ' . $userId;
            }

            $output[] = [
                'rank' => $rank,
                'uid' => $userId,
                'name' => $name,
                'value' => $this->formatValue(
                    (float)$row['best_value']
                ),
            ];
        }

        return $output;
    }

    /**
     * Returns the number of ranked players for one metric.
     */
    public function countLeaderboardPlayers(
        array $game,
        array $metric
    ): int {
        if (empty($metric['rankable'])) {
            return 0;
        }

        $scoreKey = (string)($game['score_key'] ?? '');
        $metricKey = (string)($metric['metric_key'] ?? '');

        if ($scoreKey === '' || $metricKey === '') {
            return 0;
        }

        $connection = $this->connectionPool
            ->getConnectionForTable(self::TABLE);

        $now = time();

        return (int)$connection->fetchOne(
            'SELECT COUNT(*)
             FROM ' . self::TABLE . ' s
             INNER JOIN fe_users u
                ON u.uid = s.fe_user_uid
             WHERE s.score_key = ?
               AND s.metric_key = ?
               AND s.rankable = 1
               AND s.best_value IS NOT NULL
               AND u.deleted = 0
               AND u.disable = 0
               AND (u.starttime = 0 OR u.starttime <= ?)
               AND (u.endtime = 0 OR u.endtime > ?)',
            [
                $scoreKey,
                $metricKey,
                $now,
                $now,
            ],
            [
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
            ]
        );
    }

    /**
     * Returns all stored metrics for one frontend user,
     * grouped by game.
     */
    public function getUserScores(
        int $feUserUid,
        array $catalog
    ): array {
        if ($feUserUid <= 0) {
            return [];
        }

        $connection = $this->connectionPool
            ->getConnectionForTable(self::TABLE);

        $rows = $connection->fetchAllAssociative(
            'SELECT *
             FROM ' . self::TABLE . '
             WHERE fe_user_uid = ?
             ORDER BY updated_at DESC',
            [$feUserUid],
            [ParameterType::INTEGER]
        );

        $games = [];

        foreach ($rows as $row) {
            $scoreKey = (string)$row['score_key'];

            $game = $catalog[$scoreKey] ?? [
                'score_key' => $scoreKey,
                'name' => (string)$row['game_name'],
                'metrics' => [],
            ];

            if (!isset($games[$scoreKey])) {
                $games[$scoreKey] = [
                    'score_key' => $scoreKey,
                    'name' => (string)(
                        $game['name']
                        ?? $row['game_name']
                    ),
                    'metrics' => [],
                ];
            }

            $displayValue =
                $row['best_value'] !== null
                    ? $this->formatValue(
                        (float)$row['best_value']
                    )
                    : (string)(
                        $row['best_text']
                        ?? $row['current_text']
                        ?? ''
                    );

            $metricData = [
                'metric_key' => (string)$row['metric_key'],
                'label' => (string)$row['metric_label'],
                'type' => (string)$row['metric_type'],
                'aggregation' => (string)$row['aggregation'],
                'is_primary' => !empty($row['is_primary']),
                'rankable' => !empty($row['rankable']),
                'value' => $displayValue,
                'rank' => null,
            ];

            if (
                !empty($row['rankable'])
                && !empty($row['is_primary'])
                && $row['best_value'] !== null
            ) {
                $metricData['rank'] = $this->getRank(
                    $scoreKey,
                    (string)$row['metric_key'],
                    (string)$row['aggregation'],
                    (float)$row['best_value']
                );
            }

            $games[$scoreKey]['metrics'][] =
                $metricData;
        }

        foreach ($games as &$gameData) {
            usort(
                $gameData['metrics'],
                static function (
                    array $left,
                    array $right
                ): int {
                    $leftPriority =
                        (!empty($left['is_primary']) ? 2 : 0)
                        + (!empty($left['rankable']) ? 1 : 0);

                    $rightPriority =
                        (!empty($right['is_primary']) ? 2 : 0)
                        + (!empty($right['rankable']) ? 1 : 0);

                    return $rightPriority
                        <=> $leftPriority;
                }
            );
        }

        unset($gameData);

        return array_values($games);
    }

    /**
     * Returns one player's entry for a rankable leaderboard metric.
     */
    public function getLeaderboardEntryForUser(
        array $game,
        array $metric,
        int $feUserUid
    ): ?array {
        if ($feUserUid <= 0 || empty($metric['rankable'])) {
            return null;
        }

        $scoreKey = (string)($game['score_key'] ?? '');
        $metricKey = (string)($metric['metric_key'] ?? '');

        if ($scoreKey === '' || $metricKey === '') {
            return null;
        }

        $connection = $this->connectionPool
            ->getConnectionForTable(self::TABLE);

        $now = time();

        $row = $connection->fetchAssociative(
            'SELECT
                s.fe_user_uid,
                s.best_value,
                u.username,
                u.name
             FROM ' . self::TABLE . ' s
             INNER JOIN fe_users u
                ON u.uid = s.fe_user_uid
             WHERE s.score_key = ?
               AND s.metric_key = ?
               AND s.fe_user_uid = ?
               AND s.rankable = 1
               AND s.best_value IS NOT NULL
               AND u.deleted = 0
               AND u.disable = 0
               AND (u.starttime = 0 OR u.starttime <= ?)
               AND (u.endtime = 0 OR u.endtime > ?)
             LIMIT 1',
            [
                $scoreKey,
                $metricKey,
                $feUserUid,
                $now,
                $now,
            ],
            [
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
            ]
        );

        if (!is_array($row)) {
            return null;
        }

        $name = trim((string)($row['name'] ?? ''));

        if ($name === '') {
            $name = trim((string)($row['username'] ?? ''));
        }

        if ($name === '') {
            $name = 'Player ' . $feUserUid;
        }

        $value = (float)$row['best_value'];

        return [
            'rank' => $this->getRank(
                $scoreKey,
                $metricKey,
                (string)($metric['aggregation'] ?? 'MAX'),
                $value
            ),
            'uid' => $feUserUid,
            'name' => $name,
            'value' => $this->formatValue($value),
        ];
    }

    /**
     * Deletes all scores belonging to one frontend user.
     */
    public function deleteUserScores(int $feUserUid): void
    {
        if ($feUserUid <= 0) {
            return;
        }

        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->delete(
                self::TABLE,
                ['fe_user_uid' => $feUserUid]
            );
    }

    /**
     * Calculates competition rank for a numeric metric.
     */
    private function getRank(
        string $scoreKey,
        string $metricKey,
        string $aggregation,
        float $value
    ): int {
        $connection = $this->connectionPool
            ->getConnectionForTable(self::TABLE);

        $operator = strtoupper($aggregation) === 'MIN'
            ? '<'
            : '>';

        $now = time();

        $count = $connection->fetchOne(
            'SELECT COUNT(*)
             FROM ' . self::TABLE . ' s
             INNER JOIN fe_users u
                ON u.uid = s.fe_user_uid
             WHERE s.score_key = ?
               AND s.metric_key = ?
               AND s.rankable = 1
               AND s.best_value IS NOT NULL
               AND s.best_value ' . $operator . ' ?
               AND u.deleted = 0
               AND u.disable = 0
               AND (u.starttime = 0 OR u.starttime <= ?)
               AND (u.endtime = 0 OR u.endtime > ?)',
            [
                $scoreKey,
                $metricKey,
                (string)$value,
                $now,
                $now,
            ],
            [
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
            ]
        );

        return ((int)$count) + 1;
    }

    /**
     * Formats numeric values without unnecessary zeroes.
     */
    private function formatValue(float $value): string
    {
        if (floor($value) === $value) {
            return (string)(int)$value;
        }

        return rtrim(
            rtrim(
                number_format(
                    $value,
                    6,
                    '.',
                    ''
                ),
                '0'
            ),
            '.'
        );
    }

    /**
     * Accepts finite numbers or short textual progression values.
     */
    private function normalizeIncomingValue(mixed $incoming): ?array
    {
        if (
            is_int($incoming)
            || is_float($incoming)
            || (
                is_string($incoming)
                && is_numeric(trim($incoming))
            )
        ) {
            $numeric = (float)$incoming;

            if (!is_finite($numeric) || abs($numeric) > 1.0E15) {
                return null;
            }

            return [
                'numeric' => $numeric,
                'text' => null,
            ];
        }

        if (is_string($incoming)) {
            $text = trim($incoming);

            if ($text === '') {
                return null;
            }

            return [
                'numeric' => null,
                'text' => mb_substr($text, 0, 512),
            ];
        }

        return null;
    }
}
