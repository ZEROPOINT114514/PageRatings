-- ---------------------------------------------------------------------------
-- PageRating 1.2.0 — voting lock support.
-- Adds pr_locked to page_rating_pages (0 = open, 1 = voting closed).
-- Applied via update.php only when the column does not exist yet.
-- ---------------------------------------------------------------------------
ALTER TABLE /*_*/page_rating_pages
    ADD COLUMN pr_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER pr_group;
