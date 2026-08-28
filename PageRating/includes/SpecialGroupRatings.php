<?php
/**
 * Special:<SubPage> — rankings for one 投票/<SubPage> rating group.
 *
 * Instances are registered dynamically by Hooks::onSpecialPage_initList()
 * with the sub-template name (e.g. 'Crossover' → Special:Crossover) when
 * the sub-template exists and the special-page name is not already taken.
 *
 * @license MIT
 */

declare( strict_types = 1 );

namespace MediaWiki\Extension\PageRating;

use MediaWiki\Html\Html;
use MediaWiki\Message\Message;
use MediaWiki\Title\Title;

class SpecialGroupRatings extends SpecialRatingsBase {

	private string $ratingGroup;

	/**
	 * @param string $group sub-template name, e.g. 'Crossover' for
	 *                      Template:投票/Crossover (also the page name)
	 */
	public function __construct( string $group = '' ) {
		parent::__construct( $group );
		$this->ratingGroup = $group;
	}

	/**
	 * The per-group page stays routable (Special:Crossover) but is
	 * intentionally NOT shown in the Special:SpecialPages listing.
	 *
	 * @inheritDoc
	 */
	public function isListed(): bool {
		return false;
	}

	public function getRatingGroup(): string {
		return $this->ratingGroup;
	}

	public function getDescription(): Message {
		return $this->msg( 'pagerating-group-special-desc', $this->ratingGroup );
	}

	public function getTitleMsg(): Message {
		return $this->msg( 'pagerating-group-special-title', $this->ratingGroup );
	}

	protected function getEmptyMessageKey(): string {
		return 'pagerating-empty-group';
	}

	protected function renderAfterTable( array $rows ): string {
		$back = Title::makeTitle( NS_SPECIAL, 'ViewRatings' );
		return Html::rawElement(
			'p',
			[ 'class' => 'pagerating-groups-note' ],
			Html::rawElement(
				'a',
				[ 'href' => $back->getLinkURL() ],
				$this->msg( 'pagerating-group-back-label' )->text()
			)
		);
	}
}
