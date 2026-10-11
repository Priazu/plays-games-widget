<?php

declare(strict_types=1);

return [
    'frontend' => [
        'cysquale/plays-games-widget/score-api' => [
            'target' => \Cysquale\PlaysGamesWidget\Middleware\ScoreApiMiddleware::class,
            'after' => [
                'typo3/cms-core/request-token-middleware',
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
