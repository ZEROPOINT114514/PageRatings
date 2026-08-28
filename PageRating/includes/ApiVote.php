<?php
/**
 * API endpoint for casting/cancelling votes.
 *
 *   action=vote &pageid=<int> &value=<int>
 *
 * Where <int> value is one of:
 *    2  → +1   (出类拔萃)
 *    1  → +0.5 (笔酣墨饱)
 *    0  → 0    (差强人意)
 *   -1  → -0.5 (千篇一律)
 *   -2  → -1   (平淡无味)
 *  100  → cancel existing vote
 *
 * NOTE: MediaWiki's API factory constructs modules as
 *   new ApiVote( $store, $main, $action )
 * — services come FIRST in the argument list (ObjectFactory prepends
 * resolved services before the framework's extraArgs). The constructor
 * must therefore declare ( Store $store, ApiMain $main, string $action ).
 *
 * @license MIT
 */

declare( strict_types = 1 );

namespace MediaWiki\Extension\PageRating;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

class ApiVote extends ApiBase {

	private Store $store;

	/**
	 * IMPORTANT: We intentionally do NOT rely on constructor service
	 * injection here. API modules are built by ApiMain through
	 * ObjectFactory with the same services-first pitfall as special pages;
	 * fetching the Store service inside the class is the robust,
	 * version-independent approach.
	 */
	public function __construct( ApiMain $main, string $action ) {
		parent::__construct( $main, $action );
		$this->store = MediaWikiServices::getInstance()->getService( 'PageRating.Store' );
	}

	/**
	 * Require a CSRF token. Without this the module would silently ignore
	 * the token the client sends ("Unrecognized parameter: token") and
	 * votes would be writable without CSRF protection.
	 *
	 * @return string
	 */
	public function needsToken(): string {
		return 'csrf';
	}

	/** @inheritDoc */
	public function execute(): void {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();

		$user = $this->getUser();
		if ( !$user->isRegistered() && !$config->get( 'PageRatingAnonAllowed' ) ) {
			$this->dieWithError( 'pagerating-err-permission' );
		}

		$params = $this->extractRequestParams();
		$pageId = (int)$params['pageid'];
		$value = (int)$params['value'];

		// Validate pageid
		if ( $pageId <= 0 ) {
			$this->dieWithError( [ 'apierror-badparameter', 'pageid' ] );
		}

		// Normalise the user-supplied value into internal Store constants.
		switch ( $value ) {
			case 2:
				$value = Store::VOTE_POS1;
				break;
			case 1:
				$value = Store::VOTE_POS05;
				break;
			case 0:
				$value = Store::VOTE_ZERO;
				break;
			case -1:
				$value = Store::VOTE_NEG05;
				break;
			case -2:
				$value = Store::VOTE_NEG1;
				break;
			case 100:
				$value = Store::VOTE_CANCEL;
				break;
			default:
				$this->dieWithError( [ 'apierror-badparameter', 'value' ] );
		}

		// Self-vote guard (if enabled via config)
		if ( !$config->get( 'PageRatingSelfVoteAllowed' ) ) {
			$title = Title::newFromID( $pageId );
			if ( $title ) {
				$revStore = $services->getRevisionStore();
				$firstRev = $revStore->getFirstRevision( $title );
				if ( $firstRev ) {
					$firstAuthor = $firstRev->getUser( RevisionRecord::RAW );
					if ( $firstAuthor && $user->getName() === $firstAuthor->getName() ) {
						$this->dieWithError( 'pagerating-err-self-vote' );
					}
				}
			}
		}

		// Validate registration
		if ( !$this->store->isPageRegistered( $pageId ) ) {
			$this->dieWithError( 'pagerating-err-notregistered' );
		}

		// Any Throwable from the store layer must surface as an API error
		// (HTTP 200 + error JSON) instead of a bare HTTP 500 HTML page —
		// otherwise the client only sees a useless "请求失败(http)".
		try {
			$changed = $this->store->castVote( $pageId, $user, $value );
			$stats = $this->store->getVoteStats( $pageId );
		} catch ( \Throwable $e ) {
			$this->dieWithError( [ 'pagerating-err-vote-failed', $e->getMessage() ] );
		}

		$result = $this->getResult();
		// NOTE: MediaWiki's ApiResult serializes boolean `true` as an empty
		// string (''), which is falsy in JS — that broke the client's
		// `res.vote.ok` check and silently skipped all post-vote UI work.
		// Use integer 1/0 so the JSON is unambiguous.
		$data = [
			'ok'      => 1,
			'changed' => $changed ? 1 : 0,
			'value'   => $value === Store::VOTE_CANCEL ? 0 : $value,
			'stats'   => $stats,
		];
		$result->addValue( null, 'vote', $data );
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		// NOTE: PARAM_MIN / PARAM_MAX live on IntegerDef (not ParamValidator).
		// ApiBase::PARAM_MIN / PARAM_MAX are deprecated aliases of them.
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
				IntegerDef::PARAM_MAX => 100,
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages(): array {
		return [
			'action=vote&pageid=123&value=1' => 'apihelp-vote-example-positive05',
		];
	}
}
