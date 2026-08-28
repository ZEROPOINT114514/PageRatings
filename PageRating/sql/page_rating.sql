-- ============================================================================
-- PageRating Extension SQL Schema
-- Database: page_rating_votes   - stores every single vote
-- Database: page_rating_pages   - registry of pages participating in the rating
-- Run this when installing the extension (or let the extension auto-create).
-- Compatible with MySQL/MariaDB. For PostgreSQL/SQLite, the extension can
-- also generate tables automatically using the LoadExtensionSchemaUpdates hook.
-- ============================================================================

-- Table 1: page_rating_pages
--   Registry of pages currently including the {{投票}} (Vote) template.
--   Maintained by the ArticleSaveComplete hook.
-- ============================================================================
CREATE TABLE IF NOT EXISTS /*_*/page_rating_pages (
    -- Primary key matches page_id (page_page_id from the `page` table).
    page_id          INT UNSIGNED        NOT NULL,
    -- Cache page title for fast access.
    page_title       VARBINARY(255)      NOT NULL DEFAULT '',
    -- Namespace of the page (matches page_namespace).
    page_namespace   INT                 NOT NULL DEFAULT 0,
    -- Rating group: '' = base {{投票}} template; 'Crossover' = Template:投票/Crossover.
    pr_group         VARBINARY(255)      NOT NULL DEFAULT '',
    -- Timestamp when the page first registered for rating (Unix epoch).
    registered_at    VARBINARY(14)       NOT NULL DEFAULT '',
    -- Last time a vote was cast, for quick ordering / sorting.
    last_vote_at     VARBINARY(14)       NOT NULL DEFAULT '',
    -- Current count of votes (denormalised; can also be computed by COUNT).
    vote_count       INT UNSIGNED        NOT NULL DEFAULT 0,
    PRIMARY KEY (page_id),
    KEY idx_namespace_title (page_namespace, page_title),
    KEY idx_pr_group (pr_group),
    KEY idx_last_vote_at (last_vote_at)
) /*$wgDBTableOptions*/;

-- Table 2: page_rating_votes
--   One row per (user, page) pair. The PRIMARY KEY enforces idempotency.
--   We store the raw vote as an INT representing one of {-2,-1,0,1,2,99}
--     -2 = -1.0  (-1 出类拔萃)
--     -1 = -0.5  (-0.5 千篇一律)
--      0 = 0     (0 差强人意)
--      1 = +0.5  (+0.5 笔酣墨饱)
--      2 = +1.0  (+1 出类拔萃)
--     99 = CANCEL placeholder (the row is actually deleted in that case,
--          but we keep a tombstone for audit).
-- ============================================================================
CREATE TABLE IF NOT EXISTS /*_*/page_rating_votes (
    vote_id          BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    page_id          INT UNSIGNED        NOT NULL,
    vote_user_id     BIGINT UNSIGNED     NOT NULL,         -- 0 for anon
    vote_user_name   VARBINARY(255)      NOT NULL DEFAULT '',
    vote_actor       BIGINT UNSIGNED     NOT NULL DEFAULT 0, -- actor_id (MW 1.36+)
    vote_value       TINYINT             NOT NULL,         -- -2 .. 2
    voted_at         VARBINARY(14)       NOT NULL DEFAULT '',
    PRIMARY KEY (vote_id),
    UNIQUE KEY uniq_user_page (page_id, vote_actor),
    KEY idx_page_value (page_id, vote_value),
    KEY idx_voted_at (voted_at)
) /*$wgDBTableOptions*/;
