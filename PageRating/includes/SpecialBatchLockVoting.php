<?php
/**
 * Special:批量停止投票 (Special:BatchLockVoting)
 *
 * Lists the 投票 sub-templates (Template:投票/X) and lets an admin/bureaucrat
 * lock voting on ALL pages of a given group in one click.
 *
 * @license MIT
 */

declare( strict_types = 1 );

namespace MediaWiki\Extension\PageRating;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialBatchLockVoting extends SpecialPage {

	private Store $store;

	public function __construct() {
		parent::__construct( 'BatchLockVoting', 'protect' );
		$this->store = MediaWikiServices::getInstance()->getService( 'PageRating.Store' );
	}

	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->outputHeader();

		$out = $this->getOutput();
		$request = $this->getRequest();
		$user = $this->getUser();

		$out->addModuleStyles( 'ext.pageRating.styles' );
		$out->setPageTitleMsg( $this->msg( 'pagerating-batchlockvoting-title' ) );

		// Handle one-click lock/unlock (POST + edit token).
		if ( $request->wasPosted() && $user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			$group = (string)$request->getVal( 'group' );
			$lock = ( $request->getVal( 'lockaction' ) === 'lock' );
			if ( $group !== '' ) {
				$count = $this->store->setGroupLocked( $group, $lock );
				$out->addHTML( Html::rawElement(
					'div',
					[ 'class' => 'mw-message-box mw-message-box-success' ],
					$this->msg( $lock ? 'pagerating-batch-lock-success' : 'pagerating-batch-unlock-success' )
						->params( $group, (string)$count )
						->parse()
				) );
			}
		}

		$config = MediaWikiServices::getInstance()->getMainConfig();
		// Only groups that actually have registered pages — avoid listing
		// doc/stylesheets/numbered sub-pages as lockable groups.
		$groups = array_values( array_filter(
			$this->store->getTemplateSubpageGroups( Hooks::getTemplateBaseNames( $config ) ),
			fn ( $g ) => $this->store->countGroupPages( $g ) > 0
		) );

		// Optional filter by sub-template name.
		$query = (string)$request->getVal( 'q', '' );
		if ( $query !== '' ) {
			$groups = array_values( array_filter(
				$groups,
				static fn ( $g ) => mb_stripos( $g, $query ) !== false
			) );
		}

		$out->addHTML( $this->renderSearchForm( $query ) );

		if ( !$groups ) {
			$out->addHTML( Html::rawElement(
				'p',
				[ 'class' => 'pagerating-lock-hint' ],
				$this->msg( 'pagerating-batch-no-groups' )->parse()
			) );
			return;
		}

		$out->addHTML( $this->renderGroupTable( $groups, $user->getEditToken() ) );
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'pagerating';
	}

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
			'placeholder' => $this->msg( 'pagerating-batch-search-placeholder' )->text(),
			'aria-label'  => $this->msg( 'pagerating-batch-search-placeholder' )->text(),
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
	 * @param string[] $groups
	 */
	private function renderGroupTable( array $groups, string $editToken ): string {
		$html = Html::openElement( 'table', [ 'class' => 'wikitable pagerating-lock-table' ] );
		$html .= Html::openElement( 'tr' );
		$html .= Html::element( 'th', [], $this->msg( 'pagerating-batch-col-group' )->text() );
		$html .= Html::element( 'th', [], $this->msg( 'pagerating-batch-col-pages' )->text() );
		$html .= Html::element( 'th', [], $this->msg( 'pagerating-lock-col-action' )->text() );
		$html .= Html::closeElement( 'tr' );

		foreach ( $groups as $group ) {
			$pageCount = $this->store->countGroupPages( $group );
			$lockedCount = $this->store->countLockedGroupPages( $group );

			$html .= Html::openElement( 'tr' );
			$html .= Html::element( 'td', [], $group );
			$html .= Html::element(
				'td',
				[],
				$this->msg( 'pagerating-batch-pages-summary' )
					->params( (string)$pageCount, (string)$lockedCount )
					->text()
			);
			$html .= Html::rawElement(
				'td',
				[],
				$this->renderGroupButtons( $group, $editToken, $lockedCount >= $pageCount )
			);
			$html .= Html::closeElement( 'tr' );
		}

		$html .= Html::closeElement( 'table' );
		return $html;
	}

	/**
	 * Lock-all / unlock-all buttons for one group.
	 */
	private function renderGroupButtons( string $group, string $editToken, bool $allLocked ): string {
		$html = '';
		if ( !$allLocked ) {
			$html .= $this->renderGroupForm( $group, 'lock', $this->msg( 'pagerating-lock-all-btn' )->text(), $editToken );
		}
		$html .= $this->renderGroupForm( $group, 'unlock', $this->msg( 'pagerating-unlock-all-btn' )->text(), $editToken );
		return $html;
	}

	private function renderGroupForm( string $group, string $action, string $label, string $editToken ): string {
		$html = Html::openElement( 'form', [ 'method' => 'post', 'class' => 'pagerating-lock-form' ] );
		$html .= Html::element( 'input', [ 'type' => 'hidden', 'name' => 'group', 'value' => $group ] );
		$html .= Html::element( 'input', [ 'type' => 'hidden', 'name' => 'lockaction', 'value' => $action ] );
		$html .= Html::element( 'input', [ 'type' => 'hidden', 'name' => 'wpEditToken', 'value' => $editToken ] );
		$html .= Html::element( 'input', [ 'type' => 'submit', 'value' => $label ] );
		$html .= Html::closeElement( 'form' );
		return $html;
	}
}
