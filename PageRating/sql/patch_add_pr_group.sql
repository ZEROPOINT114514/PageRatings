-- ---------------------------------------------------------------------------
-- PageRating 1.1.0 — rating-group support.
-- Adds pr_group to page_rating_pages ('' = base {{投票}} template,
-- 'Crossover' = Template:投票/Crossover, ...). Applied via update.php
-- only when the column does not exist yet.
-- ---------------------------------------------------------------------------
ALTER TABLE /*_*/page_rating_pages
    ADD COLUMN pr_group VARBINARY(255) NOT NULL DEFAULT '' AFTER page_title,
    ADD INDEX /*i*/idx_pr_group (pr_group);
