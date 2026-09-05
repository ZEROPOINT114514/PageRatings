<?php
/**
 * Special-page aliases for PageRating. Making Special:ViewRatings reachable
 * under its friendly alias (default: 查看文章评分 / ArticleRatings).
 *
 * The special page name is configurable via $wgPageRatingSpecialPagePath,
 * but the alias list below must exist for the router to accept it — the
 * SpecialPage::getAliases() override alone does not affect URL resolution.
 *
 * @license MIT
 */

$specialPageAliases = [];

/** English */
$specialPageAliases['en'] = [
	'ViewRatings' => [ 'ArticleRatings' ],
	'LockVoting' => [ 'LockVoting' ],
	'BatchLockVoting' => [ 'BatchLockVoting' ],
];

/** Simplified Chinese */
$specialPageAliases['zh-hans'] = [
	'ViewRatings' => [ '查看文章评分' ],
	'LockVoting' => [ '锁票', '锁定投票' ],
	'BatchLockVoting' => [ '批量停止投票', '批量锁票' ],
];

/** Traditional Chinese */
$specialPageAliases['zh-hant'] = [
	'ViewRatings' => [ '查看文章評分' ],
	'LockVoting' => [ '鎖票', '鎖定投票' ],
	'BatchLockVoting' => [ '批量停止投票', '批量鎖票' ],
];
