<?php
/**
 * API endpoint listing who voted a specific value on a page.
 *
 *   action=voters &pageid=<int> &value=<int>
 *
 * Read-only: no token required.
 *
 * @license MIT
 */

declare( strict_types = 1 );

namespace MediaWiki\Extension\PageRating;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

class ApiVoters extends ApiBase {

	private Store $store;

	public function __construct( ApiMain $main, string $action ) {
		parent::__construct( $main, $action );
		$this->store = MediaWikiServices::getInstance()->getService( 'PageRating.Store' );
	}

	/** @inheritDoc */
	public function execute(): void {
		$params = $this->extractRequestParams();
		$pageId = (int)$params['pageid'];
		$value = (int)$params['value'];

		$users = $this->store->getVoters( $pageId, $value );

		$result = $this->getResult();
		$result->addValue( null, 'voters', [
			'pageid' => $pageId,
			'value'  => $value,
			'users'  => $users,
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'pageid' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				IntegerDef::PARAM_MIN => 1,
			],
			'value'  => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				IntegerDef::PARAM_MIN => -2,
				IntegerDef::PARAM_MAX => 2,
			],
		];
	}

	/** @inheritDoc */
	public function isReadMode(): bool {
		return true;
	}
}
