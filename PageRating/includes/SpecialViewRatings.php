<?php
/**
 * Special:查看文章评分 - ranking overview for the DEFAULT group
 * (pages using the base {{投票}} template / the raw <pagerating> tag).
 *
 * Per-sub-template groups are surfaced by dynamic same-named special pages
 * (see SpecialGroupRatings and Hooks::onSpecialPage_initList).
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

	public function __construct() {
		parent::__construct( 'ViewRatings' );
	}

	public function getRatingGroup(): string {
		return Store::GROUP_DEFAULT;
	}

	/**
	 * Allow "Special:查看文章评分" via the translated alias (pagePath).
	 *
	 * NOTE (MW 1.41+): getDescription() MUST return a Message object;
	 * returning a string is deprecated and hard-fails in newer versions.
	 * There is NO Message::newFromText() — simply return $this->msg().
	 */
	public function getDescription(): Message {
		return $this->msg( 'pagerating-special-title' );
	}

	public function getTitleMsg(): Message {
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
	 * List the per-sub-page group special pages below the main table so
	 * admins can jump straight to e.g. Special:Crossover.
	 */
	protected function renderAfterTable( array $rows ): string {
		$services = MediaWikiServices::getInstance();
		$store = $services->getService( 'PageRating.Store' );
		$config = $services->getMainConfig();
		$groups = Hooks::getKnownGroups( $store, $config );
		if ( !$groups ) {
			return '';
		}
		$links = [];
		foreach ( $groups as $group ) {
			$page = $services->getSpecialPageFactory()->getPage( $group );
			if ( !$page ) {
				// Conflicted or otherwise unregistered group.
				$links[] = htmlspecialchars( $group ) . ' (×)';
				continue;
			}
			$url = Title::makeTitle( NS_SPECIAL, $page->getName() )->getLocalURL();
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
