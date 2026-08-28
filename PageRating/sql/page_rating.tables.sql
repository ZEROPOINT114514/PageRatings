-- ---------------------------------------------------------------------------
-- PageRating Extension — minimal CREATE TABLE block used by MediaWiki's
-- update.php (via the LoadExtensionSchemaUpdates hook). Each statement
-- MUST be idempotent because the hook fires on every update run.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS /*_*/page_rating_pages (
    page_id          INT UNSIGNED        NOT NULL,
    page_title       VARBINARY(255)      NOT NULL DEFAULT '',
    page_namespace   INT                 NOT NULL DEFAULT 0,
    pr_group         VARBINARY(255)      NOT NULL DEFAULT '',
    registered_at    VARBINARY(14)       NOT NULL DEFAULT '',
    last_vote_at     VARBINARY(14)       NOT NULL DEFAULT '',
    vote_count       INT UNSIGNED        NOT NULL DEFAULT 0,
    PRIMARY KEY (page_id),
    KEY idx_namespace_title (page_namespace, page_title),
    KEY idx_pr_group (pr_group),
    KEY idx_last_vote_at (last_vote_at)
) /*$wgDBTableOptions*/;

CREATE TABLE IF NOT EXISTS /*_*/page_rating_votes (
    vote_id          BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    page_id          INT UNSIGNED        NOT NULL,
    vote_user_id     BIGINT UNSIGNED     NOT NULL,
    vote_user_name   VARBINARY(255)      NOT NULL DEFAULT '',
    vote_actor       BIGINT UNSIGNED     NOT NULL DEFAULT 0,
    vote_value       TINYINT             NOT NULL,
    voted_at         VARBINARY(14)       NOT NULL DEFAULT '',
    PRIMARY KEY (vote_id),
    UNIQUE KEY uniq_user_page (page_id, vote_actor),
    KEY idx_page_value (page_id, vote_value),
    KEY idx_voted_at (voted_at)
) /*$wgDBTableOptions*/;
