<?php
/**
 * Magic words for the PageRating extension.
 *
 * This file registers the parser-function magic word `pagerating` so that
 * Parser::setFunctionHook( 'pagerating', ... ) works — MediaWiki requires
 * every function-hook ID to be a known magic word.
 *
 * IMPORTANT: We deliberately register ONLY the ID 'pagerating' and do NOT
 * add a zh-hans alias like '投票'. If '投票' were a magic word, `{{投票}}`
 * would be parsed as a magic word / parser function instead of a template
 * transclusion — breaking the {{投票}} template design entirely.
 *
 * @license MIT
 */

$magicWords = [];

$magicWords['en'] = [
	'pagerating' => [ 0, 'pagerating' ],
];

// Same ID for Chinese wikis; the alias stays 'pagerating'.
$magicWords['zh-hans'] = [
	'pagerating' => [ 0, 'pagerating' ],
];

$magicWords['zh-hant'] = [
	'pagerating' => [ 0, 'pagerating' ],
];
