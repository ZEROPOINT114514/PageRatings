<?php
/**
 * Hook handlers for PageRating.
 *
 *  - BeforePageDisplay       → inject CSS/JS module on article pages
 *  - ArticleSaveComplete      → auto-register/unregister pages by template
 *  - PageSaveComplete         → ditto (catch wider event)
 *
 * @license MIT
 */

declare( strict_types = 1 );

namespace MediaWiki\Extension\PageRating;

use Config;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\PageSaveCompleteHook;
use MediaWiki\Hook\ArticleSaveCompleteHook;
use OutputPage;
use Skin;
use Title;

class Hooks implements
	BeforePageDisplayHook,
	ArticleSaveCompleteHook,
	PageSaveCompleteHook {

	private Store $store;
	private Config $config;

	public function __construct( Store $store, Config $config ) {
		$this->store = $store;
		$this->config = $config;
	}

	/**
	 * Database schema migrations driven by `php maintenance/run.php update.php`.
	 *
	 * NOTE: LoadExtensionSchemaUpdates is a "noServices" hook — it is called
	 * during the installer phase before the service container is ready, so it
	 * MUST be registered as a static method in extension.json:
	 *   "LoadExtensionSchemaUpdates": "MediaWiki\\Extension\\PageRating\\Hooks::onLoadExtensionSchemaUpdates"
	 * It must NOT be routed through a HookHandlers entry that declares services.
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
	}

	/**
	 * Always-on: load CSS/JS on every page so the rating widget works
	 * wherever the {{投票}} template is rendered.
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		/** @var OutputPage $out */
		// We only need to load the *script* (it triggers the CSS via OOUI/load);
		// the styles module is dependency of scripts. But to keep things
		// simple we just load both.
		$out->addModuleStyles( 'ext.pageRating.styles' );
		$out->addModuleScripts( 'ext.pageRating.scripts' );

		// Also pass the page id (0 if not an article) so JS can self-locate.
		$title = $out->getTitle();
		if ( $title && $title->exists() && $title->getNamespace() === NS_MAIN ) {
			$out->addJsConfigVars( [
				'wgPageRatingPageId' => $out->getWikiPage()->getId(),
			] );
		}
	}

	/**
	 * Watch pages for inclusion / removal of the {{投票}} template.
	 * The template name is configurable; default is "Vote".
	 */
	public function onArticleSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		$this->syncRegistration( $wikiPage->getTitle(), $wikiPage->getContent() );
	}

	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		$this->syncRegistration( $wikiPage->getTitle(), $wikiPage->getContent() );
	}

	/**
	 * Run a fast text scan for {{Vote|...}} or {{投票|...}} transclusion.
	 * Faster than full Wikitext parsing and good enough for our needs.
	 */
	private function syncRegistration( Title $title, $content ): void {
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

		$templateNames = [
			$this->config->get( 'PageRatingTemplateName' ),   // e.g. "Vote"
			'投票',
		];

		$present = false;
		if ( $content !== null ) {
			$text = $this->extractWikitextForScan( $content, $title );
			if ( $text !== null ) {
				foreach ( $templateNames as $tplName ) {
					if ( $tplName === '' || $tplName === null ) {
						continue;
					}
					// Match {{Vote|...}} with optional parameters and whitespace.
					// Case-insensitive on the first character of the template name
					// (matches MediaWiki's default first-char-insensitive behaviour).
					$firstChar = mb_substr( $tplName, 0, 1, 'UTF-8' );
					$rest = mb_substr( $tplName, 1, null, 'UTF-8' );
					$firstClass = '['
						. preg_quote( mb_strtoupper( $firstChar, 'UTF-8' ), '/' )
						. preg_quote( mb_strtolower( $firstChar, 'UTF-8' ), '/' )
						. ']';
					$pattern = '/\{\{\s*' . $firstClass . preg_quote( $rest, '/' ) . '(?:\s*\|[^}]*)?\}\}/u';
					if ( preg_match( $pattern, $text ) ) {
						$present = true;
						break;
					}
				}
			}
		}

		$pageId = $title->getArticleID();
		if ( $pageId <= 0 ) {
			return;
		}
		$isRegistered = $this->store->isPageRegistered( $pageId );

		if ( $present && !$isRegistered ) {
			$this->store->registerPage(
				$pageId,
				$title->getNamespace(),
				$title->getDBkey()
			);
		} elseif ( !$present && $isRegistered ) {
			$this->store->unregisterPage( $pageId );
		}
	}

	/**
	 * Pull the raw wikitext out of any content model. For WikitextContent
	 * we get it directly; for other models we conservatively mark the
	 * page as not-containing-the-template (no false positives).
	 */
	private function extractWikitextForScan( $content, Title $title ): ?string {
		if ( $content instanceof \WikitextContent ) {
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
}
