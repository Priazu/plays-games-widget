<?php

declare(strict_types=1);

namespace Cysquale\PlaysGamesWidget\DataProcessing;

use Cysquale\PlaysGamesWidget\Service\ScoreSettings;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

final class WidgetConfigurationProcessor implements DataProcessorInterface
{
    public function __construct(
        private readonly ScoreSettings $scoreSettings,
    ) {
    }

    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        $data = $processedData['data'] ?? $cObj->data ?? [];

        $experience = (string)(
            $data['tx_playsgameswidget_experience']
            ?? 'general'
        );

        $category = preg_replace(
            '/[^a-z0-9-]/',
            '',
            strtolower(
                (string)(
                    $data['tx_playsgameswidget_category']
                    ?? ''
                )
            )
        ) ?? '';

        $widgetScoreSelection = (string)(
            $data['tx_playsgameswidget_score_games']
            ?? '__all__'
        );

        $scoreGames = $experience === 'score'
            ? $this->scoreSettings->resolveWidgetScoreGames(
                $widgetScoreSelection
            )
            : [];

        $scoreInfinite = $this->scoreSettings
            ->isScoreInfinite();

        $scoreEnabled = (
            $experience === 'score'
            && $scoreGames !== []
        );

        $query = [
            'embed' => '1',
        ];

        if ($scoreEnabled) {
            $query['score_bridge'] = '1';
            $query['score_games'] = implode(',', $scoreGames);
            $query['score_infinite'] = $scoreInfinite
                ? '1'
                : '0';
        } elseif ($category !== '') {
            $query['category'] = $category;
        }

        $processedData['iframeUrl'] =
            'https://www.plays-games.com/index-white-label.php?'
            . http_build_query(
                $query,
                '',
                '&',
                PHP_QUERY_RFC3986
            );

        $processedData['scoreEnabled'] = $scoreEnabled;
        $processedData['scoreGames'] = $scoreGames;
        $processedData['scoreInfinite'] = $scoreInfinite;
        $processedData['experience'] = $experience;

        return $processedData;
    }
}
