<?php
/**
 * MediaWiki DI service wiring for the PageRating extension.
 *
 * @license MIT
 */

declare( strict_types = 1 );

namespace MediaWiki\Extension\PageRating;

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IConnectionProvider;

return [
	'PageRating.Store' => static function ( MediaWikiServices $services ): Store {
		return new Store(
			$services->getConnectionProvider()
		);
	},
];
