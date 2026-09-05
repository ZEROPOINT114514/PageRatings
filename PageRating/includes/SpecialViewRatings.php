<?php
/**
 * Special:查看文章评分 - ranking overview.
 *
 * Handles both the default group (Special:ViewRatings — pages using the base
 * {{投票}} template) and per-sub-template groups via the Special:ViewRatings/X
 * sub-page form (e.g. Special:ViewRatings/CrossOver for Template:投票/CrossOver).
 *
 * @license MIT
 */

declare( strict_types = 1 );

namespace MediaWiki\Extension\PageRating;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\Title\Title;

class SpecialViewRatings extends SpecialRatingsBase {

	/** Rating group currently being shown ('' = default group). */
	private string $activeGroup = '';

	public function __construct() {
		parent::__construct( 'ViewRatings' );
	}

	/**
	 * A sub-page (Special:ViewRatings/CrossOver) selects the corresponding
	 * rating group; the bare Special:ViewRatings shows the default group.
	 */
	public function execute( $subPage ): void {
		$this->activeGroup = '';
		if ( is_string( $subPage ) && $subPage !== '' ) {
			$group = trim( $subPage, '/' );
			if ( $group !== '' ) {
				$this->activeGroup = $group;
			}
		}
		parent::execute( $subPage );
	}

	public function getRatingGroup(): string {
		return $this->activeGroup;
	}

	/**
	 * Allow "Special:查看文章评分" via the translated alias (pagePath).
	 *
	 * NOTE (MW 1.41+): getDescription() MUST return a Message object.
	 */
	public function getDescription(): Message {
		if ( $this->activeGroup !== '' ) {
			return $this->msg( 'pagerating-group-special-desc', $this->activeGroup );
		}
		return $this->msg( 'pagerating-special-title' );
	}

	public function getTitleMsg(): Message {
		if ( $this->activeGroup !== '' ) {
			return $this->msg( 'pagerating-group-special-title', $this->activeGroup );
		}
		return $this->msg( 'pagerating-special-title' );
	}

	/** @return string[] */
	public function getAliases(): array {
		$path = $this->getConfig()->get( 'PageRatingSpecialPagePath' );
		if ( $path !== '' ) {
			return [ $path ];
		}
		return parent::getAliases();
	}

	/**
	 * Below the main table: on the default page, list the sub-template
	 * groups as Special:ViewRatings/X links; on a group page, show a
	 * link back to the full ranking.
	 */
	protected function renderAfterTable( array $rows ): string {
		if ( $this->activeGroup !== '' ) {
			$back = Title::makeTitle( NS_SPECIAL, 'ViewRatings' );
			return Html::rawElement(
				'p',
				[ 'class' => 'pagerating-groups-note' ],
				Html::rawElement(
					'a',
					[ 'href' => $back->getLocalURL() ],
					$this->msg( 'pagerating-group-back-label' )->text()
				)
			);
		}

		$services = MediaWikiServices::getInstance();
		$store = $services->getService( 'PageRating.Store' );
		// Only groups that actually have rated pages — NOT every 投票/X
		// sub-page in the page table (that would list doc/stylesheets/
		// numbered sub-pages as meaningless group links).
		$groups = $store->getUsedGroups();
		if ( !$groups ) {
			return '';
		}
		$links = [];
		foreach ( $groups as $group ) {
			$url = Title::makeTitle( NS_SPECIAL, 'ViewRatings/' . $group )->getLocalURL();
			$links[] = Html::rawElement(
				'a',
				[ 'href' => $url ],
				htmlspecialchars( $group )
			);
		}
		return Html::rawElement(
			'p',
			[ 'class' => 'pagerating-groups-note' ],
			$this->msg( 'pagerating-groups-note' )
				->params( implode( ' | ', $links ) )
				->text()
		);
	}
}
