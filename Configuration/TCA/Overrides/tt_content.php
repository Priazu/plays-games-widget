<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

$key = 'playsgameswidget';
$languageFile = 'LLL:EXT:plays_games_widget/Resources/Private/Language/locallang_db.xlf:';

$columns = [
    'tx_playsgameswidget_width' => [
        'label' => $languageFile . 'field.width',
        'description' => $languageFile . 'field.width.description',
        'config' => [
            'type' => 'number',
            'default' => 100,
            'range' => [
                'lower' => 20,
                'upper' => 100,
            ],
        ],
    ],
    'tx_playsgameswidget_height' => [
        'label' => $languageFile . 'field.height',
        'config' => [
            'type' => 'number',
            'default' => 700,
            'range' => [
                'lower' => 300,
                'upper' => 1400,
            ],
        ],
    ],
    'tx_playsgameswidget_radius' => [
        'label' => $languageFile . 'field.radius',
        'config' => [
            'type' => 'number',
            'default' => 20,
            'range' => [
                'lower' => 0,
                'upper' => 80,
            ],
        ],
    ],
    'tx_playsgameswidget_experience' => [
        'label' => $languageFile . 'field.experience',
        'description' => $languageFile . 'field.experience.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'default' => 'general',
            'items' => [
                [$languageFile . 'experience.general', 'general'],
                [$languageFile . 'experience.score', 'score'],
            ],
        ],
    ],

    'tx_playsgameswidget_score_games' => [
        'label' => $languageFile . 'field.scoreGames',
        'description' => $languageFile . 'field.scoreGames.description',
        'displayCond' => 'FIELD:tx_playsgameswidget_experience:=:score',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectMultipleSideBySide',
            'default' => '__all__',
            'size' => 10,
            'minitems' => 0,
            'maxitems' => 999,
            'items' => [
                [
                    'label' => $languageFile . 'scoreGames.all',
                    'value' => '__all__',
                ],
            ],
            'itemsProcFunc' =>
                \Cysquale\PlaysGamesWidget\Tca\ScoreGameItems::class
                . '->addItems',
        ],
    ],

    'tx_playsgameswidget_category' => [
        'label' => $languageFile . 'field.category',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'default' => '',
            'items' => [
                [$languageFile . 'category.all', ''],
                [$languageFile . 'category.action', 'action'],
                [$languageFile . 'category.arcade', 'arcade'],
                [$languageFile . 'category.casual', 'casual'],
                [$languageFile . 'category.puzzle', 'puzzle'],
                [$languageFile . 'category.racing', 'racing'],
                [$languageFile . 'category.sports', 'sports'],
            ],
        ],
    ],
    'tx_playsgameswidget_leaderboard_game' => [
        'label' => $languageFile . 'field.leaderboardGame',
        'description' => $languageFile . 'field.leaderboardGame.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'default' => '__auto__',
            'items' => [
                [
                    'label' => $languageFile . 'leaderboard.auto',
                    'value' => '__auto__',
                ],
            ],
            'itemsProcFunc' =>
                \Cysquale\PlaysGamesWidget\Tca\LeaderboardGameItems::class
                . '->addItems',
        ],
    ],

    'tx_playsgameswidget_leaderboard_limit' => [
        'label' => $languageFile . 'field.leaderboardLimit',
        'description' => $languageFile . 'field.leaderboardLimit.description',
        'config' => [
            'type' => 'number',
            'default' => 10,
            'range' => [
                'lower' => 3,
                'upper' => 50,
            ],
        ],
    ],

];

ExtensionManagementUtility::addTCAcolumns('tt_content', $columns);

ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => $languageFile . 'contentElement.title',
        'value' => $key,
        'icon' => 'plays-games-widget',
        'group' => 'special',
        'description' => $languageFile . 'contentElement.description',
    ],
);

$GLOBALS['TCA']['tt_content']['types'][$key] = [
    'showitem' => '
        tx_playsgameswidget_width,
        tx_playsgameswidget_height,
        tx_playsgameswidget_radius,
        tx_playsgameswidget_experience,
        tx_playsgameswidget_score_games,
        tx_playsgameswidget_category
    ',
];

ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => $languageFile . 'leaderboard.title',
        'value' => 'playsgamesleaderboard',
        'icon' => 'plays-games-widget',
        'group' => 'special',
        'description' => $languageFile . 'leaderboard.description',
    ],
);

$GLOBALS['TCA']['tt_content']['types']['playsgamesleaderboard'] = [
    'showitem' => '
        tx_playsgameswidget_leaderboard_game,
        tx_playsgameswidget_leaderboard_limit
    ',
];


ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => $languageFile . 'myScores.title',
        'value' => 'playsgamesmyscores',
        'icon' => 'plays-games-widget',
        'group' => 'special',
        'description' => $languageFile . 'myScores.description',
    ],
);

$GLOBALS['TCA']['tt_content']['types']['playsgamesmyscores'] = [
    'showitem' => '
        header
    ',
];
