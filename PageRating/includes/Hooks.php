<?php
/**
 * Hook handlers for PageRating.
 *
 * IMPORTANT (MediaWiki 1.42+): The legacy hook interfaces (ArticleSaveCompleteHook,
 * PageSaveCompleteHook) have been removed from core. To stay compatible we register
 * plain static callbacks via extension.json ("Class::method") — the classic,
 * still-documented way that does NOT depend on any hook interface file existing.
 * Services are fetched from the container inside the methods instead of being
 * constructor-injected through HookHandlers.
 *
 * 1.1.0: rating groups. Every page using a 投票 sub-template (Template:投票/X) is
 * registered into group X. Per-group rankings are surfaced as the
 * Special:ViewRatings/X sub-page (see SpecialViewRatings), not as separate
 * same-named special pages.
 *
 * @license MIT
 */

declare( strict_types = 1 );

namespace MediaWiki\Extension\PageRating;

use MediaWiki\Config\Config;
use MediaWiki\Parser\PPFrame;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class Hooks {

	/**
	 * Database schema migrations driven by 'php maintenance/run.php update.php'.
	 *
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ): void {
		$dir = dirname( __DIR__ );
		// page_rating.tables.sql contains both CREATE TABLE IF NOT EXISTS
		// statements, so a single addExtensionTable call is sufficient
		// and stays idempotent across update.php runs.
		$updater->addExtensionTable(
			'page_rating_pages',
			"$dir/sql/page_rating.tables.sql"
		);
		// 1.1.0: rating-group column (ALTER … ADD COLUMN + INDEX in one statement).
		$updater->addExtensionField(
			'page_rating_pages',
			'pr_group',
			"$dir/sql/patch_add_pr_group.sql"
		);
		// 1.2.0: voting-lock column.
		$updater->addExtensionField(
			'page_rating_pages',
			'pr_locked',
			"$dir/sql/patch_add_pr_locked.sql"
		);
	}

	/**
	 * Load CSS/JS on every page so the rating widget works wherever the
	 * {{投票}} template is rendered.
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public static function onBeforePageDisplay( $out, $skin ): void {
		// addModules() loads both JS and CSS; addModuleStyles() loads CSS only.
		// (There is no addModuleScripts() method in OutputPage.)
		$out->addModules( 'ext.pageRating.scripts' );
		$out->addModuleStyles( 'ext.pageRating.styles' );

		// Also pass the page id (0 if not an article) so JS can self-locate.
		$title = $out->getTitle();
		if ( $title && $title->exists() && $title->getNamespace() === NS_MAIN ) {
			$out->addJsConfigVars( [
				'wgPageRatingPageId' => $title->getArticleID(),
			] );
		}
	}

	/**
	 * Watch pages for inclusion / removal of the {{投票}} template.
	 *
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $user
	 */
	public static function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		$services = MediaWikiServices::getInstance();
		$store = $services->getService( 'PageRating.Store' );
		$config = $services->getMainConfig();
		self::syncRegistration( $store, $config, $wikiPage->getTitle(), $wikiPage->getContent() );
	}

	/**
	 * Base template page texts that define rating groups.
	 *
	 * @return string[]
	 */
	public static function getTemplateBaseNames( Config $config ): array {
		$bases = [ '投票', (string)$config->get( 'PageRatingTemplateName' ) ];
		return array_values( array_unique( array_filter(
			$bases,
			static fn ( $b ) => $b !== '' && $b !== null
		) ) );
	}

	/**
	 * Determine the rating group of a <pagerating> tag from its parser frame.
	 *
	 * Walks the transclusion chain (tag → template → … → page) and returns the
	 * first sub-template of 投票/Vote (e.g. "Crossover" for Template:投票/Crossover).
	 * Returns '' for the base template or a raw tag on the page itself.
	 */
	public static function determineGroupFromFrame( ?PPFrame $frame, Config $config ): string {
		if ( !$frame ) {
			return '';
		}
		$bases = self::getTemplateBaseNames( $config );
		$f = $frame;
		// Safety net: frames form a parent chain; guard against any
		// pathological loop just in case.
		$depth = 0;
		while ( $f && $depth++ < 50 ) {
			$title = $f->getTitle();
			if ( $title && $title->getNamespace() === NS_TEMPLATE ) {
				$text = $title->getText();
				foreach ( $bases as $base ) {
					$prefix = $base . '/';
					if ( str_starts_with( strtolower( $text ), strtolower( $prefix ) ) ) {
						return substr( $text, strlen( $prefix ) );
					}
				}
			}
			// MediaWiki 1.42+ removed PPFrame::getParentFrame(); the parent
			// frame is now reachable via the public $parent property on
			// PPTemplateFrame_Hash. Prefer the old method when it exists
			// (MW 1.41 and earlier) so this stays compatible with every
			// supported core version.
			if ( method_exists( $f, 'getParentFrame' ) ) {
				$f = $f->getParentFrame();
			} elseif ( property_exists( $f, 'parent' ) && $f->parent instanceof PPFrame ) {
				$f = $f->parent;
			} else {
				$f = null;
			}
		}
		return '';
	}

	/**
	 * Scan wikitext for {{投票}} / {{投票/X}} (or {{Vote}} / {{Vote/X}}) usage.
	 *
	 * @return string|null '' = base template / raw tag; 'X' = sub-template; null = none
	 */
	public static function scanWikitextGroup( string $text, Config $config ): ?string {
		// Raw tag form: <pagerating ... /> or <pagerating>…</pagerating>
		if ( preg_match( '/<\s*pagerating(?:\s|\/|>)/iu', $text ) ) {
			return '';
		}
		foreach ( self::getTemplateBaseNames( $config ) as $tplName ) {
			[ $subPattern, $basePattern ] = self::buildTemplatePatterns( $tplName );
			if ( preg_match( $subPattern, $text, $m ) ) {
				return trim( (string)$m[ 1 ] );
			}
			if ( preg_match( $basePattern, $text ) ) {
				return '';
			}
		}
		return null;
	}

	/**
	 * Build the two transclusion patterns for one (base) template name:
	 * [ sub-page pattern (groups the sub-page part), base pattern ].
	 *
	 * @return array{0:string,1:string}
	 */
	private static function buildTemplatePatterns( string $tplName ): array {
		$firstChar = mb_substr( $tplName, 0, 1, 'UTF-8' );
		$rest = mb_substr( $tplName, 1, null, 'UTF-8' );
		$firstClass = '['
			. preg_quote( mb_strtoupper( $firstChar, 'UTF-8' ), '/' )
			. preg_quote( mb_strtolower( $firstChar, 'UTF-8' ), '/' )
			. ']';
		$name = $firstClass . preg_quote( $rest, '/' );
		// Optional namespace prefix: accept 'Template:投票/X' or '模板:投票/X'
		// or the plain '投票/X' form; likewise for the base template.
		$ns = '(?:(?:Template|模板)\s*:\s*)?';
		$sub = '/\{\{\s*' . $ns . $name . '\s*\/\s*([^}|]+?)\s*(?:\|[^}]*)?\}\}/u';
		$base = '/\{\{\s*' . $ns . $name . '(?:\s*\|[^}]*)?\}\}/u';
		return [ $sub, $base ];
	}

	/**
	 * Run a fast text scan for {{Vote|...}} / {{投票|...}} / {{Vote/X|...}} transclusion.
	 * Faster than full Wikitext parsing and good enough for our needs.
	 */
	private static function syncRegistration( Store $store, Config $config, Title $title, $content ): void {
		// Skip special pages, etc.
		if ( !$title->isValid() || !$title->exists() ) {
			return;
		}
		if ( $title->getNamespace() < NS_MAIN ) {
			return;
		}
		if ( $title->getNamespace() === NS_TEMPLATE || $title->getNamespace() === NS_MEDIAWIKI ) {
			return;
		}

		$pageId = $title->getArticleID();
		if ( $pageId <= 0 ) {
			return;
		}

		$group = null;
		if ( $content !== null ) {
			$text = self::extractWikitextForScan( $content );
			if ( $text !== null ) {
				$group = self::scanWikitextGroup( $text, $config );
			}
		}

		$isRegistered = $store->isPageRegistered( $pageId );
		if ( $group !== null ) {
			if ( !$isRegistered || $store->getGroup( $pageId ) !== $group ) {
				$store->registerPage(
					$pageId,
					$title->getNamespace(),
					$title->getDBkey(),
					$group
				);
			}
		} elseif ( $isRegistered ) {
			$store->unregisterPage( $pageId );
		}
	}

	/**
	 * Pull the raw wikitext out of any content model. For WikitextContent
	 * we get it directly; for other models we conservatively mark the
	 * page as not-containing-the-template (no false positives).
	 */
	private static function extractWikitextForScan( $content ): ?string {
		if ( $content instanceof \MediaWiki\Content\WikitextContent ) {
			return $content->getText();
		}
		// For RevisionRecord content via WikiPage, also try getText() generically.
		if ( method_exists( $content, 'getText' ) ) {
			$text = $content->getText();
			if ( is_string( $text ) ) {
				return $text;
			}
		}
		return null;
	}

	/**
	 * Does the LATEST revision of this page still contain the rating widget,
	 * and in which group?
	 *
	 * @return string|null '' / 'X' when present (group), null when absent
	 */
	public static function pageHasRatingTemplate( Title $title ): ?string {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$wikiPage = $services->getWikiPageFactory()->newFromTitle( $title );
		$content = $wikiPage->getContent();
		$text = self::extractWikitextForScan( $content );
		if ( $text === null || $text === '' ) {
			return null;
		}
		return self::scanWikitextGroup( $text, $config );
	}
}
