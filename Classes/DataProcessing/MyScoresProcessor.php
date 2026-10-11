<?php

declare(strict_types=1);

namespace Cysquale\PlaysGamesWidget\DataProcessing;

use Cysquale\PlaysGamesWidget\Service\ScoreCatalog;
use Cysquale\PlaysGamesWidget\Service\ScoreStorage;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

final class MyScoresProcessor implements DataProcessorInterface
{
    public function __construct(
        private readonly ScoreCatalog $scoreCatalog,
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
        $currentUid = (int)$this->context
            ->getPropertyFromAspect(
                'frontend.user',
                'id',
                0
            );

        $processedData['loggedIn'] = $currentUid > 0;
        $processedData['games'] = [];

        if ($currentUid <= 0) {
            return $processedData;
        }

        $catalog = $this->scoreCatalog->getCatalog(false);

        $processedData['games'] =
            $this->scoreStorage->getUserScores(
                $currentUid,
                $catalog
            );

        return $processedData;
    }
}
