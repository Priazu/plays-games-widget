CREATE TABLE tt_content (
    tx_playsgameswidget_width int unsigned DEFAULT '100' NOT NULL,
    tx_playsgameswidget_height int unsigned DEFAULT '700' NOT NULL,
    tx_playsgameswidget_radius int unsigned DEFAULT '20' NOT NULL,
    tx_playsgameswidget_experience varchar(16) DEFAULT 'general' NOT NULL,
    tx_playsgameswidget_score_games text DEFAULT NULL,
    tx_playsgameswidget_category varchar(64) DEFAULT '' NOT NULL,
    tx_playsgameswidget_leaderboard_game varchar(128) DEFAULT '__auto__' NOT NULL,
    tx_playsgameswidget_leaderboard_limit int unsigned DEFAULT '10' NOT NULL
);

CREATE TABLE tx_playsgameswidget_score (
    uid int unsigned NOT NULL AUTO_INCREMENT,
    fe_user_uid int unsigned DEFAULT '0' NOT NULL,
    game_id int unsigned DEFAULT '0' NOT NULL,
    score_key varchar(128) DEFAULT '' NOT NULL,
    game_name varchar(255) DEFAULT '' NOT NULL,
    metric_key varchar(96) DEFAULT '' NOT NULL,
    metric_label varchar(128) DEFAULT '' NOT NULL,
    metric_type varchar(32) DEFAULT 'SCORE' NOT NULL,
    aggregation varchar(16) DEFAULT 'LATEST' NOT NULL,
    is_primary smallint unsigned DEFAULT '0' NOT NULL,
    rankable smallint unsigned DEFAULT '0' NOT NULL,
    current_value decimal(24,6) DEFAULT NULL,
    best_value decimal(24,6) DEFAULT NULL,
    current_text varchar(512) DEFAULT NULL,
    best_text varchar(512) DEFAULT NULL,
    attempts int unsigned DEFAULT '0' NOT NULL,
    created_at int unsigned DEFAULT '0' NOT NULL,
    updated_at int unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY user_game_metric (fe_user_uid, game_id, metric_key),
    KEY leaderboard (score_key, metric_key, rankable),
    KEY user_scores (fe_user_uid, updated_at)
);
