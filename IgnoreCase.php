<?php

use MediaWiki\Page\Hook\BeforeDisplayNoArticleTextHook;
use MediaWiki\Hook\InitializeArticleMaybeRedirectHook;
use MediaWiki\Title\Title;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Html\Html;
use Wikimedia\Rdbms\ILoadBalancer;

class IgnoreCase implements BeforeDisplayNoArticleTextHook, InitializeArticleMaybeRedirectHook {

	private ILoadBalancer $loadBalancer;
	private static $memoPages = [];
	private static $memoAttribs = [];
	private static $memoQuery = [];

	public function __construct( ILoadBalancer $loadBalancer ) {
		$this->loadBalancer = $loadBalancer;
	}

	private function getMatchingPages( LinkTarget $target ): array {
		$ns = $target->getNamespace();
		$key = $target->getDBkey();
		if ( !isset( self::$memoPages[$ns] ) || !isset( self::$memoPages[$ns][$key] ) ) {
			$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
			$res = $dbr->newSelectQueryBuilder()
				->select( [ 'page_title', 'page_id' ] )
				->from( 'page' )
				->where( [ 'page_namespace' => $ns ] )
				->where( [ 'convert(page_title using utf8mb4)' => $key ] )
				->caller( __METHOD__ )
				->fetchResultSet();

			$pages = [];
			foreach ( $res as $row ) {
				if ( mb_strtolower( $row->page_title ) === mb_strtolower( $key ) ) {
					// case-insensitive match
					$pages[$row->page_id] = $row->page_title;
				}
			}
			self::$memoPages[$ns][$key] = $pages;
		}
		return self::$memoPages[$ns][$key];
	}

	private function getOnePage( $title ): ?Title {
		// if there is more than one option, we are not using it regardless;
		// if there are no options, there's nothing to use
		$pages = $this->getMatchingPages( $title );
		if ( count( $pages ) !== 1 ) return null;
		// don't use even if light existence checks failed us
		if ( in_array( $title->getDBKey(), $pages, true) ) return null;
		// the only matching title is not the one we're viewing; use it
		foreach ( $pages as $page_id => $page_title ) {
			return Title::makeTitle( $title->getNamespace(), $page_title );
		}
	}

	public function onInitializeArticleMaybeRedirect(
		$title, $request, &$ignoreRedirect, &$target, &$article
	): void {
		// If the article exists at this title, there is no redirecting to be done.
		// Avoids using Title::isKnown() to avoid an unnecessary DB query.
		if ( $article->getPage()->exists() ) return;

		$result = $this->getOnePage( $title );
		if ( $result !== null ) $target = $result;
	}

	public function onBeforeDisplayNoArticleText( $article ): bool {
		$title = $article->getTitle();
		$context = $article->getContext();
		$output = $context->getOutput();

		if ( $pages = $this->getMatchingPages( $title ) ) {
			$output->addWikiMsg( 'ignorecase-disambiguation', $title->getPrefixedText(), count( $pages ) );
			$lines = [];
			foreach ( $pages as $page_id => $page_title ) {
				$t = Title::makeTitle( $title->getNamespace(), $page_title );
				$lines[] = '* [[' . $t->getPrefixedText() . ']]';
			}
			$text = implode( "\n", $lines );
			$text = "<div class=\"mw-ignorecase-disambig\">\n$text\n</div>";
			$output->addWikiTextAsInterface( $text );
		}
		return true;
	}

	public function onHtmlPageLinkRendererBegin(
		$linkRenderer, $target, &$text, &$extraAttribs, &$query, &$ret
	): bool {
		$ns = $target->getNamespace();
		$key = $target->getDBkey();
		// track the last value of these parameters for reuse in on...End()
		self::$memoAttribs[$ns][$key] = $extraAttribs;
		self::$memoQuery[$ns][$key] = $query;
		return true;
	}

	public function onHtmlPageLinkRendererEnd(
		$linkRenderer, $target, $isKnown, &$text, &$attribs, &$ret
	): bool {
		// If the article exists at this title, there is no relinking to be done.
		// The result of Title::isKnown() has kindly been provided to us.
		if ( $isKnown ) return true;

		$title = $this->getOnePage( $target );
		if ( $title === null ) return true;

		$ns = $target->getNamespace();
		$key = $target->getDBkey();
		$extraAttribs = self::$memoAttribs[$ns][$key] ?? [];
		$query = self::$memoQuery[$ns][$key] ?? [];
		$ret = $linkRenderer->makeLink( $title, $text, $extraAttribs, $query );
		return false;
	}

}
