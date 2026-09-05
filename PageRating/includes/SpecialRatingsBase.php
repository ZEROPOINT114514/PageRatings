<?php
/**
 * SpecialRatingsBase - shared implementation for the rating summary pages.
 *
 * The default special page (Special:查看文章评分) shows the default group
 * (pages using the base {{投票}} template); one same-named special page per
 * 投票 sub-template (Special:Crossover, ...) shows that group's member pages.
 *
 * @license MIT
 */

declare( strict_types = 1 );

namespace MediaWiki\Extension\PageRating;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

abstract class SpecialRatingsBase extends SpecialPage {

	private Store $store;

	/**
	 * NOTE (MW 1.41+): getDescription() MUST return a Message object;
	 * returning a string is deprecated and hard-fails in newer versions.
	 */
	public function __construct( string $name ) {
		parent::__construct( $name );
		// Fetching services inside the constructor (instead of injecting them)
		// keeps the class robust against ObjectFactory arg order differences.
		$this->store = MediaWikiServices::getInstance()->getService( 'PageRating.Store' );
	}

	/** @return string rating group key ('' = base template group) */
	abstract public function getRatingGroup(): string;

	/** @return Message page title shown when the page renders */
	abstract public function getTitleMsg(): Message;

	/** @return string message key used for the empty state */
	protected function getEmptyMessageKey(): string {
		return 'pagerating-empty';
	}

	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->outputHeader();

		$out = $this->getOutput();
		$out->addModuleStyles( 'ext.pageRating.styles' );
		$out->addModules( 'ext.pageRating.voters' );
		$out->setPageTitleMsg( $this->getTitleMsg() );

		$group = $this->getRatingGroup();
		$rows = $this->store->listRankedPages( $group );

		// Lazy cleanup + group healing: pages whose {{投票}} widget was
		// removed are dropped; pages that switched to another sub-template
		// are re-assigned to that group. Template-namespace pages are
		// excluded from the per-group pages (they are the templates, not
		// contest entries).
		$filtered = [];
		foreach ( $rows as $row ) {
			$pageId = (int)$row[ 0 ];
			$title = Title::newFromID( $pageId );
			if ( !$title || !$title->exists() ) {
				$this->store->unregisterPage( $pageId );
				continue;
			}
			$detected = Hooks::pageHasRatingTemplate( $title );
			if ( $detected === null ) {
				$this->store->unregisterPage( $pageId );
				continue;
			}
			if ( $detected !== $group ) {
				// The page now belongs to another group (or switched back to
				// the base template): heal its registry row, do not list it here.
				$this->store->registerPage(
					$pageId,
					$title->getNamespace(),
					$title->getDBkey(),
					$detected
				);
				continue;
			}
			if ( $group !== '' && $title->getNamespace() === NS_TEMPLATE ) {
				// The 投票 sub-template page itself is not a contest entry.
				continue;
			}
			$row[ 1 ] = $title->getPrefixedText();
			$filtered[] = $row;
		}

		$out->addHTML( $this->renderTable( $filtered ) );
		$out->addHTML( $this->renderAfterTable( $filtered ) );
	}

	/** Hook point for subclasses to append content (group links, etc.). */
	protected function renderAfterTable( array $rows ): string {
		return '';
	}

	/**
	 * Render the ranked table.
	 *
	 * @param array<int, array{0:int,1:string,2:float,3:int,4:array}> $rows
	 */
	private function renderTable( array $rows ): string {
		if ( empty( $rows ) ) {
			return Html::rawElement(
				'div',
				[ 'class' => 'pagerating-empty' ],
				$this->msg( $this->getEmptyMessageKey() )->parse()
			);
		}

		$html = Html::openElement( 'table', [
			'class' => 'wikitable sortable pagerating-table',
			'id'    => 'pagerating-table',
		] );

		$html .= Html::openElement( 'thead' );
		$html .= Html::openElement( 'tr' );
		$headers = [
			'pagerating-th-rank',
			'pagerating-th-page',
			'pagerating-th-score',
			'pagerating-th-positive1',
			'pagerating-th-positive05',
			'pagerating-th-zero',
			'pagerating-th-negative05',
			'pagerating-th-negative1',
			'pagerating-th-total',
		];
		foreach ( $headers as $key ) {
			$html .= Html::element( 'th', [
				'scope' => 'col',
				'class' => $key,
			], $this->msg( $key )->text() );
		}
		$html .= Html::closeElement( 'tr' );
		$html .= Html::closeElement( 'thead' );

		$html .= Html::openElement( 'tbody' );
		$rank = 0;
		foreach ( $rows as $row ) {
			$rank++;
			[ $pageId, $titleText, $score, $total, $buckets ] = $row;

			$result = new RatingResult( $score, $buckets, $total );
			$title = Title::newFromText( $titleText );
			$link = $title ? $title->getLocalURL() : '#';

			$html .= Html::openElement( 'tr' );
			$html .= Html::element( 'td', [ 'class' => 'pagerating-rank' ], (string)$rank );
			$html .= Html::rawElement(
				'td',
				[ 'class' => 'pagerating-title' ],
				$title
					? Html::rawElement( 'a', [ 'href' => $link ], htmlspecialchars( $title->getText() ) )
					: htmlspecialchars( $titleText )
			);
			$html .= Html::element(
				'td',
				[ 'class' => 'pagerating-score' . ( $score >= 0 ? ' pagerating-score-pos' : ' pagerating-score-neg' ) ],
				$result->formattedScore()
			);
			$html .= Html::rawElement(
				'td',
				[ 'class' => 'pagerating-count' ],
				$this->renderCountCell( $pageId, Store::VOTE_POS1, $result->positive1() )
			);
			$html .= Html::rawElement(
				'td',
				[ 'class' => 'pagerating-count' ],
				$this->renderCountCell( $pageId, Store::VOTE_POS05, $result->positive05() )
			);
			$html .= Html::rawElement(
				'td',
				[ 'class' => 'pagerating-count' ],
				$this->renderCountCell( $pageId, Store::VOTE_ZERO, $result->zero() )
			);
			$html .= Html::rawElement(
				'td',
				[ 'class' => 'pagerating-count' ],
				$this->renderCountCell( $pageId, Store::VOTE_NEG05, $result->negative05() )
			);
			$html .= Html::rawElement(
				'td',
				[ 'class' => 'pagerating-count' ],
				$this->renderCountCell( $pageId, Store::VOTE_NEG1, $result->negative1() )
			);
			$html .= Html::element( 'td', [ 'class' => 'pagerating-total' ], (string)$total );
			$html .= Html::closeElement( 'tr' );
		}
		$html .= Html::closeElement( 'tbody' );
		$html .= Html::closeElement( 'table' );

		$html .= Html::rawElement(
			'p',
			[ 'class' => 'pagerating-formula' ],
			$this->msg( 'pagerating-formula' )->parse()
		);

		return $html;
	}

	/**
	 * Render one bucket-count cell.
	 * A count > 0 becomes a clickable link carrying pageid/value so the
	 * voters module can fetch and show who voted; a count of 0 stays a
	 * plain (non-clickable) "0" per product requirement.
	 */
	private function renderCountCell( int $pageId, int $value, int $count ): string {
		if ( $count <= 0 ) {
			return '0';
		}
		return Html::rawElement(
			'a',
			[
				'href'         => '#',
				'class'        => 'pagerating-voters',
				'data-pageid'  => (string)$pageId,
				'data-value'   => (string)$value,
				'title'        => $this->msg( 'pagerating-voters-hint' )->text(),
			],
			(string)$count
		);
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		// Custom group "pagerating" → shows under the "页面投票" (Page voting)
		// heading on Special:SpecialPages (see specialpages-group-pagerating).
		return 'pagerating';
	}
}
