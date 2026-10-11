<?php

declare(strict_types=1);

namespace Cysquale\PlaysGamesWidget\DataProcessing;

use Cysquale\PlaysGamesWidget\Service\ScoreCatalog;
use Cysquale\PlaysGamesWidget\Service\ScoreSettings;
use Cysquale\PlaysGamesWidget\Service\ScoreStorage;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

final class LeaderboardProcessor implements DataProcessorInterface
{
    public function __construct(
        private readonly ScoreCatalog $scoreCatalog,
        private readonly ScoreSettings $scoreSettings,
        private readonly ScoreStorage $scoreStorage,
        private readonly Context $context,
    ) {
    }

    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        $data = $processedData['data'] ?? $cObj->data ?? [];

        $limit = max(
            3,
            min(
                50,
                (int)(
                    $data[
                        'tx_playsgameswidget_leaderboard_limit'
                    ] ?? 10
                )
            )
        );

        $currentUid = (int)$this->context
            ->getPropertyFromAspect(
                'frontend.user',
                'id',
                0
            );

        $boards = [];

        $selectedGame = (string)(
            $data['tx_playsgameswidget_leaderboard_game']
            ?? '__auto__'
        );

        $enabledGames =
            $this->scoreSettings->getEnabledScoreGames();

        $autoFollow = $selectedGame === '__auto__';

        if ($autoFollow) {
            $scoreKeys = $enabledGames;
        } elseif (in_array($selectedGame, $enabledGames, true)) {
            $scoreKeys = [$selectedGame];
        } else {
            $scoreKeys = [];
        }

        foreach ($scoreKeys as $scoreKey) {
            $game = $this->scoreCatalog->getGame(
                $scoreKey,
                false
            );

            if ($game === null) {
                continue;
            }

            $metric = $this->scoreCatalog
                ->getPrimaryMetric($game);

            if (
                $metric === null
                || empty($metric['rankable'])
            ) {
                continue;
            }

            $rows = $this->scoreStorage
                ->getLeaderboard(
                    $game,
                    $metric,
                    $limit
                );

            foreach ($rows as &$row) {
                $row['is_current'] =
                    $currentUid > 0
                    && (int)$row['uid'] === $currentUid;

                $row['initials'] =
                    $this->initials(
                        (string)$row['name']
                    );
            }

            unset($row);

            $currentEntry = null;

            if ($currentUid > 0) {
                $currentInRows = false;

                foreach ($rows as $row) {
                    if ((int)$row['uid'] === $currentUid) {
                        $currentInRows = true;
                        break;
                    }
                }

                if (!$currentInRows) {
                    $currentEntry = $this->scoreStorage
                        ->getLeaderboardEntryForUser(
                            $game,
                            $metric,
                            $currentUid
                        );

                    if (is_array($currentEntry)) {
                        $currentEntry['is_current'] = true;
                        $currentEntry['initials'] = $this->initials(
                            (string)$currentEntry['name']
                        );
                    }
                }
            }

            $boards[] = [
                'score_key' => $scoreKey,
                'game_id' => (int)(
                    $game['plays_game_id'] ?? 0
                ),
                'game_name' => (string)(
                    $game['name'] ?? $scoreKey
                ),
                'metric_label' => (string)(
                    $metric['label'] ?? 'Score'
                ),
                'rows' => $rows,
                'total_players' =>
                    $this->scoreStorage
                        ->countLeaderboardPlayers(
                            $game,
                            $metric
                        ),
                'show_podium' => count($rows) >= 3,
                'current_entry' => $currentEntry,
            ];
        }

        $processedData['boards'] = $boards;
        $processedData['autoFollow'] = $autoFollow;

        return $processedData;
    }

    private function initials(string $name): string
    {
        $parts = preg_split(
            '/\s+/u',
            trim($name),
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];

        if ($parts === []) {
            return '?';
        }

        if (count($parts) >= 2) {
            return mb_strtoupper(
                mb_substr($parts[0], 0, 1)
                . mb_substr(
                    $parts[count($parts) - 1],
                    0,
                    1
                )
            );
        }

        if (
            preg_match(
                '/^(.).*?(\d+)$/u',
                $parts[0],
                $matches
            ) === 1
        ) {
            return mb_strtoupper(
                mb_substr($matches[1], 0, 1)
                . mb_substr($matches[2], -1)
            );
        }

        return mb_strtoupper(
            mb_substr($parts[0], 0, 2)
        );
    }
}
