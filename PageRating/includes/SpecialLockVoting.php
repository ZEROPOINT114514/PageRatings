<?php
/**
 * Special:锁定投票 (Special:LockVoting)
 *
 * Lets administrators and bureaucrats search registered rating pages and
 * lock/unlock voting on them individually. A locked page shows "投票已截止"
 * on its widget and rejects further votes.
 *
 * @license MIT
 */

declare( strict_types = 1 );

namespace MediaWiki\Extension\PageRating;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class SpecialLockVoting extends SpecialPage {

	private Store $store;

	public function __construct() {
		// 'protect' permission = sysop (and bureaucrat inherits it).
		parent::__construct( 'LockVoting', 'protect' );
		$this->store = MediaWikiServices::getInstance()->getService( 'PageRating.Store' );
	}

	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->outputHeader();

		$out = $this->getOutput();
		$request = $this->getRequest();
		$user = $this->getUser();

		$out->addModuleStyles( 'ext.pageRating.styles' );
		$out->setPageTitleMsg( $this->msg( 'pagerating-lockvoting-title' ) );

		// Handle lock/unlock (POST + edit token).
		if ( $request->wasPosted() && $user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			$pageId = (int)$request->getVal( 'pageid' );
			$lock = ( $request->getVal( 'lockaction' ) === 'lock' );
			if ( $pageId > 0 ) {
				$this->store->setPageLocked( $pageId, $lock );
				$out->addHTML( Html::rawElement(
					'div',
					[ 'class' => 'mw-message-box mw-message-box-success' ],
					$this->msg( $lock ? 'pagerating-lock-success' : 'pagerating-unlock-success' )
						->params( (string)$pageId )
						->parse()
				) );
			}
		}

		$query = (string)$request->getVal( 'q', '' );

		$out->addHTML( $this->renderSearchForm( $query ) );

		if ( $query !== '' ) {
			$results = $this->store->searchRegisteredPages( $query );
			$out->addHTML( $this->renderResults( $results, $user->getEditToken() ) );
		} else {
			$out->addHTML( Html::rawElement(
				'p',
				[ 'class' => 'pagerating-lock-hint' ],
				$this->msg( 'pagerating-lock-search-hint' )->parse()
			) );
		}
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'pagerating';
	}

	/**
	 * GET search form, mirroring MediaWiki's built-in search form (p-search):
	 * the form targets $wgScript and carries a hidden `title` field so the
	 * GET submission always lands back on this special page (a bare
	 * `method="get"` form drops the `title` query param and breaks).
	 */
	private function renderSearchForm( string $query ): string {
		$html = Html::openElement( 'form', [
			'method' => 'get',
			'action' => $this->getConfig()->get( 'Script' ),
			'class'  => 'pagerating-lock-search',
			'role'   => 'search',
		] );
		$html .= Html::hidden( 'title', $this->getPageTitle()->getPrefixedText() );
		$html .= Html::element( 'input', [
			'type'        => 'search',
			'name'        => 'q',
			'value'       => $query,
			'placeholder' => $this->msg( 'pagerating-search-placeholder' )->text(),
			'aria-label'  => $this->msg( 'pagerating-search-placeholder' )->text(),
			'size'        => 40,
			'class'       => 'mw-searchInput',
		] );
		$html .= ' ';
		$html .= Html::element( 'input', [
			'type'  => 'submit',
			'value' => $this->msg( 'pagerating-search-btn' )->text(),
		] );
		$html .= Html::closeElement( 'form' );
		return $html;
	}

	/**
	 * Search result table, one lock/unlock button per row.
	 *
	 * @param array<int, array{id:int,ns:int,title:string,group:string,locked:bool}> $results
	 */
	private function renderResults( array $results, string $editToken ): string {
		if ( !$results ) {
			return Html::rawElement(
				'p',
				[],
				$this->msg( 'pagerating-lock-no-results' )->parse()
			);
		}

		$html = Html::openElement( 'table', [ 'class' => 'wikitable pagerating-lock-table' ] );
		$html .= Html::openElement( 'tr' );
		$html .= Html::element( 'th', [], $this->msg( 'pagerating-th-page' )->text() );
		$html .= Html::element( 'th', [], $this->msg( 'pagerating-lock-col-group' )->text() );
		$html .= Html::element( 'th', [], $this->msg( 'pagerating-lock-col-status' )->text() );
		$html .= Html::element( 'th', [], $this->msg( 'pagerating-lock-col-action' )->text() );
		$html .= Html::closeElement( 'tr' );

		foreach ( $results as $r ) {
			$title = Title::makeTitle( $r['ns'], $r['title'] );
			$link = $title
				? Html::rawElement( 'a', [ 'href' => $title->getLocalURL() ], htmlspecialchars( $title->getPrefixedText() ) )
				: htmlspecialchars( $r['title'] );

			$groupText = $r['group'] === ''
				? $this->msg( 'pagerating-lock-group-default' )->text()
				: htmlspecialchars( $r['group'] );

			$status = $r['locked']
				? Html::rawElement( 'span', [ 'class' => 'pagerating-lock-status pagerating-lock-status--on' ], $this->msg( 'pagerating-lock-status-on' )->text() )
				: Html::rawElement( 'span', [ 'class' => 'pagerating-lock-status pagerating-lock-status--off' ], $this->msg( 'pagerating-lock-status-off' )->text() );

			$action = $this->renderLockButton( $r['id'], $r['locked'], $editToken );

			$html .= Html::openElement( 'tr' );
			$html .= Html::rawElement( 'td', [], $link );
			$html .= Html::rawElement( 'td', [], $groupText );
			$html .= Html::rawElement( 'td', [], $status );
			$html .= Html::rawElement( 'td', [], $action );
			$html .= Html::closeElement( 'tr' );
		}

		$html .= Html::closeElement( 'table' );
		return $html;
	}

	/**
	 * A tiny inline POST form toggling the lock for one page.
	 */
	private function renderLockButton( int $pageId, bool $locked, string $editToken ): string {
		$lockNext = $locked ? 'unlock' : 'lock';
		$label = $locked
			? $this->msg( 'pagerating-unlock-btn' )->text()
			: $this->msg( 'pagerating-lock-btn' )->text();

		$html = Html::openElement( 'form', [ 'method' => 'post', 'class' => 'pagerating-lock-form' ] );
		$html .= Html::element( 'input', [ 'type' => 'hidden', 'name' => 'pageid', 'value' => (string)$pageId ] );
		$html .= Html::element( 'input', [ 'type' => 'hidden', 'name' => 'lockaction', 'value' => $lockNext ] );
		$html .= Html::element( 'input', [ 'type' => 'hidden', 'name' => 'wpEditToken', 'value' => $editToken ] );
		$html .= Html::element( 'input', [ 'type' => 'submit', 'value' => $label ] );
		$html .= Html::closeElement( 'form' );
		return $html;
	}
}
