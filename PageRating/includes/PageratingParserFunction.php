<?php
/**
 * <pagerating> tag implementing the {{投票}} widget.
 *
 * WHY A TAG INSTEAD OF A PARSER FUNCTION:
 *   Parser functions ({{#pagerating:...}}) return a string that MediaWiki
 *   pipes through its HTML Sanitizer before output. The sanitizer's tag
 *   whitelist does NOT include <input>, <label>, <button> or <script>, so
 *   every form control gets escaped to &lt;...&gt; text and the widget is
 *   destroyed. Extension TAGS (<pagerating ... />) output is NOT sanitized
 *   — this is the standard mechanism used by Widgets, InputBox, Cite, etc.
 *
 * The user-facing {{投票}} template is plain wikitext that invokes the tag:
 *
 *   <pagerating
 *       background="{{{background|{{{1|}}}}}}"
 *       title1="{{{title1|{{{2|棍母}}}}}}"
 *       title2="{{{title2|{{{3|棍父}}}}}}"
 *   />
 *
 * @license MIT
 */

declare( strict_types = 1 );

namespace MediaWiki\Extension\PageRating;

use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class PageratingParserFunction {

	/**
	 * Register the <pagerating> tag and (legacy) {{#pagerating:...}} function.
	 *
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( $parser ): void {
		// Preferred: tag form — output bypasses the HTML sanitizer.
		$parser->setHook( 'pagerating', [ self::class, 'renderTag' ] );
		// Legacy: parser function form still works (but output gets sanitized).
		$parser->setFunctionHook( 'pagerating', [ self::class, 'render' ] );
	}

	/**
	 * Tag callback: <pagerating background="..." title1="..." title2="..." />
	 *
	 * Attribute values are read from $args. Positional fallbacks 1/2/3 are
	 * also accepted for convenience.
	 *
	 * IMPORTANT: Tag attribute values are NOT preprocessed by MediaWiki —
	 * a value like {{{1|Thebackrooms.jpg}}} reaches us verbatim. We must
	 * run each value through $frame->expand() so template parameters are
	 * resolved (this is exactly what makes the {{投票}} template's own
	 * parameters work when the tag is transcluded).
	 *
	 * @param ?string $input   tag body text (unused)
	 * @param array $args      tag attributes
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string HTML (NOT sanitized)
	 */
	public static function renderTag( $input, array $args, Parser $parser, PPFrame $frame ): string {
		// Expand template parameters / magic words in each attribute value.
		// We use Parser::replaceVariables() rather than $frame->expand()
		// because the latter is unreliable when invoked from a tag callback
		// (the frame may not yet contain parameter bindings, so {{X}} is
		// never resolved). replaceVariables() with the explicit $frame is
		// the standard, well-tested API for this job.
		$expand = static function ( $value ) use ( $parser, $frame ) {
			if ( $value === null || $value === '' ) {
				return '';
			}
			return $parser->replaceVariables( (string)$value, $frame );
		};

		$background = trim( (string)$expand( $args[ 'background' ] ?? $args[ 'bg' ] ?? $args[ '1' ] ?? '' ) );
		$title1     = trim( (string)$expand( $args[ 'title1' ] ?? $args[ '2' ] ?? '' ) );
		$title2     = trim( (string)$expand( $args[ 'title2' ] ?? $args[ '3' ] ?? '' ) );

		// 1.1.0: rating group from the transclusion chain (投票/Crossover → 'Crossover').
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$group  = Hooks::determineGroupFromFrame( $frame, $config );

		return self::renderWidget( $parser, $background, $title1, $title2, $group );
	}

	/**
	 * Legacy parser-function callback: {{#pagerating:bg|title1|title2}}
	 * Kept for backwards compatibility. Note its output IS sanitized, so
	 * prefer the <pagerating> tag form.
	 *
	 * @param Parser $parser
	 * @param string ...$args
	 * @return string HTML
	 */
	public static function render( Parser $parser, string ...$args ): string {
		$background = trim( $args[ 0 ] ?? '' );
		$title1     = trim( $args[ 1 ] ?? '' );
		$title2     = trim( $args[ 2 ] ?? '' );

		return self::renderWidget( $parser, $background, $title1, $title2 );
	}

	/**
	 * Shared widget renderer.
	 *
	 * @param Parser $parser
	 * @param string $background  image filename or URL
	 * @param string $title1      collapsed card title line 1
	 * @param string $title2      collapsed card title line 2
	 * @return string HTML
	 */
	private static function renderWidget( Parser $parser, string $background, string $title1, string $title2, string $group = '' ): string {
		$services = MediaWikiServices::getInstance();
		$store = $services->getService( 'PageRating.Store' );
		$config = $services->getMainConfig();

		if ( $title1 === '' ) {
			$title1 = wfMessage( 'pagerating-default-title1' )->inContentLanguage()->text();
		}
		if ( $title2 === '' ) {
			$title2 = wfMessage( 'pagerating-default-title2' )->inContentLanguage()->text();
		}

		// Locate the article being parsed.
		$page = $parser->getPage();
		if ( !$page ) {
			return '';
		}

		// The parser also runs in contexts where there is no real article:
		// e.g. Special:Version parses extension descriptions as interface
		// text, and interface/special pages are NOT "proper pages" — they
		// cannot exist in the page table. Title::getId() throws a
		// PreconditionException for those in MW 1.43+ (assertProperPage),
		// so guard with canExist() and bail out on any non-positive id.
		if ( $page instanceof Title && !$page->canExist() ) {
			return '';
		}
		$pageId = (int)$page->getId();
		if ( $pageId <= 0 ) {
			return '';
		}
		$pageTitle = Title::newFromPageIdentity( $page );
		$pageName  = $pageTitle->getText();

		// Make sure the widget exists in the registry as soon as the
		// template is rendered on a page (acts as a safety net; the main
		// hook also does this in PageSaveComplete).
		if ( $pageId > 0 && ( !$store->isPageRegistered( $pageId ) || $store->getGroup( $pageId ) !== $group ) ) {
			try {
				$store->registerPage(
					$pageId,
					$page->getNamespace(),
					$page->getDBkey(),
					$group
				);
			} catch ( \Throwable $_ ) {
				// ignore
			}
		}

		// Lookup stats
		$stats   = $store->getVoteStats( $pageId );
		$current = $store->getUserVote( $pageId, RequestContext::getMain()->getUser() );
		$locked  = $store->isPageLocked( $pageId );

		// Resolve background into a usable URL
		$bgUrl = self::resolveBackground( $background );
		$bgStyle = $bgUrl !== ''
			? 'background-image: linear-gradient(rgba(0,0,0,.2), rgba(0,0,0,.45)), url('
				. htmlspecialchars( $bgUrl, ENT_QUOTES ) . ');'
			: '';

		// Inject per-page data via an inline script tag so the JS doesn't have
		// to do another round-trip to learn the current vote value / page name.
		$payload = [
			'pageId'   => $pageId,
			'pageName' => $pageName,
			'stats'    => $stats,
			'current'  => $current,
			'locked'   => $locked,
		];
		$payloadJson = json_encode( $payload, JSON_UNESCAPED_UNICODE );

		// Build the HTML. As a tag output this is NOT passed through the
		// sanitizer, so <input>/<label>/<button>/<script> survive intact.
		$options = [
			[ 'key' => 'positive1',   'value' => 2,  'name' => wfMessage( 'pagerating-option-positive1' )->text() ],
			[ 'key' => 'positive05',  'value' => 1,  'name' => wfMessage( 'pagerating-option-positive05' )->text() ],
			[ 'key' => 'zero',        'value' => 0,  'name' => wfMessage( 'pagerating-option-zero' )->text() ],
			[ 'key' => 'negative05',  'value' => -1, 'name' => wfMessage( 'pagerating-option-negative05' )->text() ],
			[ 'key' => 'negative1',   'value' => -2, 'name' => wfMessage( 'pagerating-option-negative1' )->text() ],
			[ 'key' => 'cancel',      'value' => 100, 'name' => wfMessage( 'pagerating-option-cancel' )->text() ],
		];

		$max = max( array_sum( $stats[ 'buckets' ] ?? [] ), 1 );
		$optionsHtml = '';
		foreach ( $options as $o ) {
			// buckets keys are the NUMERIC vote weights ("-2","-1","0","1","2"),
			// not the semantic keys ("positive1"…). Match on $o['value'].
			$count = (int)( $stats[ 'buckets' ][ (string)$o[ 'value' ] ] ?? 0 );
			$ratio = $count / max( $max, 1 );
			$selected = ( $current !== null && (int)$current === (int)$o[ 'value' ] ) ? ' pagerating-option--selected' : '';
			$checked = ( $current !== null && (int)$current === (int)$o[ 'value' ] ) ? ' checked' : '';
			// "取消投票" (value 100) is an ACTION, not a rating bucket — it
			// must NOT get a bar / counter like the five real options.
			$isCancel = ( (int)$o[ 'value' ] === 100 );
			$barCountHtml = $isCancel ? '' :
				'<span class="pagerating-option__bar" aria-hidden="true">'
				. '<span class="pagerating-option__bar-fill" style="--pr-bar-fill: '
				. number_format( $ratio, 3, '.', '' )
				. '"></span></span>'
				. '<span class="pagerating-option__count">'
				. $count
				. '</span>';
			$optionsHtml .= '<li class="pagerating-option pagerating-option--'
				. htmlspecialchars( $o[ 'key' ], ENT_QUOTES )
				. $selected
				. '" data-bucket="'
				. (int)$o[ 'value' ]
				. '" data-value="'
				. (int)$o[ 'value' ]
				. '">'
				. '<input type="radio" name="pagerating-vote" value="'
				. (int)$o[ 'value' ]
				. '" class="pagerating-option__radio"'
				. $checked
				. ' />'
				. '<label class="pagerating-option__label">'
				. '<span class="pagerating-option__name">'
				. htmlspecialchars( $o[ 'name' ] )
				. '</span></label>'
				. $barCountHtml
				. '</li>';
		}

		$total = (int)$stats[ 'total' ];
		$createdFmt = date_create_from_format( 'YmdHis', wfTimestamp( TS_MW ) )
			?: date_create();
		$createdText = $createdFmt
			->format( 'Y/m/d H:i:s' );

		// Votes change page_rating_votes without touching page content, so
		// MediaWiki's parser cache would keep serving stale stats long after
		// people vote. Disable parser caching for any page embedding the
		// widget so the rendered totals are always fresh (cost is tiny on a
		// wiki this size; matches how vote widgets normally behave).
		$parser->getOutput()->updateCacheExpiry( 0 );

		// Locked pages render a disabled "投票已截止" button and the widget
		// gets a --locked class so hover/click interaction is suppressed.
		$lockedClass   = $locked ? ' pagerating-widget--locked' : '';
		$buttonLabel   = $locked
			? wfMessage( 'pagerating-button-locked' )->text()
			: wfMessage( 'pagerating-button-vote' )->text();
		$buttonAttrs   = $locked ? ' disabled' : '';

		// The widget container. Note: the class attribute uses single
		// underscores only (pagerating_widget) so the CSS/JS can target it
		// regardless of any attribute mangling.
		// The invisible #Voting anchor right above lets the client jump
		// back to the widget after a post-vote hard refresh.
		return '<span id="Voting"></span>'
			. '<div class="pagerating-widget pagerating-widget--initial' . $lockedClass . '"'
			. ' data-pagename="' . htmlspecialchars( $pageName, ENT_QUOTES ) . '"'
			. ' role="region"'
			. ' aria-label="'
			. htmlspecialchars( wfMessage( 'pagerating-aria-label' )->text(), ENT_QUOTES )
			. '"'
			. ' style="' . $bgStyle . '"'
			. '>'
			. '<div class="pagerating-collapsed pagerating-collapsed--default"'
			. ' tabindex="0" role="button"'
			. ' aria-label="' . htmlspecialchars( wfMessage( 'pagerating-open-form' )->text(), ENT_QUOTES ) . '">'
			. '<span class="pagerating-collapsed__title">' . htmlspecialchars( $title1 ) . '</span>'
			. '<span class="pagerating-collapsed__subtitle">' . htmlspecialchars( $title2 ) . '</span>'
			. '</div>'
			. '<div class="pagerating-collapsed pagerating-collapsed--hover" aria-hidden="true">'
			. '<span class="pagerating-collapsed__title">' . htmlspecialchars( $title1 ) . '</span>'
			. '<span class="pagerating-collapsed__subtitle">' . htmlspecialchars( $title2 ) . '</span>'
			. '</div>'
			. '<div class="pagerating-expanded" role="form" aria-label="'
			. htmlspecialchars( wfMessage( 'pagerating-form-aria' )->text(), ENT_QUOTES )
			. '" style="display:none">'
			. '<div class="pagerating-expanded__heading">'
			. htmlspecialchars( wfMessage( 'pagerating-confirm-heading' )->text() )
			. ' <span class="pagerating-expanded__heading-page">'
			. htmlspecialchars( $pageName ) . '</span>'
			. '</div>'
			. '<ul class="pagerating-options">' . $optionsHtml . '</ul>'
			. '<div class="pagerating-meta">'
			. '<div class="pagerating-meta__created">'
			. wfMessage( 'pagerating-meta-prefix' )->text() . ' '
			. '<span class="pagerating-meta__date">' . htmlspecialchars( $createdText ) . '</span>'
			. ', '
			. '<span class="pagerating-meta__total">' . $total . '</span>'
			. wfMessage( 'pagerating-count-suffix' )->text()
			. '</div>'
			. '<button type="button" class="pagerating-button pagerating-button--vote"' . $buttonAttrs . '>'
			. htmlspecialchars( $buttonLabel )
			. '</button>'
			. '</div>'
			. '</div>'
			. '<script type="application/json" class="pagerating-data">' . $payloadJson . '</script>'
			. '</div>';
	}

	/**
	 * Translate a background argument into a usable URL.
	 *   - empty string → ''
	 *   - starts with http:// or https:// → used directly
	 *   - starts with File: → upload namespace path is rendered via Title::getLocalURL
	 *   - any other name → treated as File:Name
	 *   - on resolve failure → ''
	 */
	private static function resolveBackground( string $arg ): string {
		$arg = trim( $arg );
		if ( $arg === '' ) {
			return '';
		}
		if ( preg_match( '#^(https?:)?//#i', $arg ) ) {
			return $arg;
		}
		// Strip "File:" or "Image:" prefix if present
		if ( preg_match( '#^(File|Image|Media):(.*)$#i', $arg, $matches ) ) {
			$titleText = $matches[ 2 ];
		} else {
			$titleText = $arg;
		}
		$title = Title::makeTitle( NS_FILE, $titleText );
		if ( !$title || !$title->exists() ) {
			// Fall back to the literal argument as a URL string
			return $arg;
		}
		// IMPORTANT: getLocalURL(['action' => 'raw']) returns the wikitext
		// of the file page, NOT the image. We must resolve the actual file
		// URL via RepoGroup so the browser can display the picture.
		$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $title );
		if ( $file && $file->exists() ) {
			$url = $file->getUrl();
			if ( $url !== '' ) {
				return $url;
			}
		}
		// Fallback: try the Special:Redirect/file endpoint (always works)
		return SpecialPage::getTitleFor( 'Redirect', 'file/' . $title->getDBkey() )
			->getLocalURL();
	}
}
