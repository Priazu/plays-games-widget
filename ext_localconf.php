<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTypoScriptSetup(
    "@import 'EXT:plays_games_widget/Configuration/TypoScript/setup.typoscript'"
);

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['plays_games_widget_score_catalog']
    ??= [];
