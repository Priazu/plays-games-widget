<?php

declare(strict_types=1);

namespace Cysquale\PlaysGamesWidget\Tca;

use Cysquale\PlaysGamesWidget\Service\ScoreCatalog;
use Cysquale\PlaysGamesWidget\Service\ScoreSettings;

final class ScoreGameItems
{
    public function __construct(
        private readonly ScoreCatalog $scoreCatalog,
        private readonly ScoreSettings $scoreSettings,
    ) {
    }

    public function addItems(array &$params): void
    {
        foreach ($this->scoreSettings->getEnabledScoreGames() as $scoreKey) {
            $game = $this->scoreCatalog->getGame($scoreKey, false);

            if ($game === null) {
                continue;
            }

            $params['items'][] = [
                'label' => (string)($game['name'] ?? $scoreKey),
                'value' => $scoreKey,
            ];
        }
    }
}
