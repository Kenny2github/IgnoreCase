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

	public function onInitializeArticleMaybeRedirect(
		$title, $request, &$ignoreRedirect, &$target, &$article
	): void {
		// If the article exists at this title, there is no redirecting to be done.
		// Avoids using Title::isKnown() to avoid an unnecessary DB query.
		if ( $article->getPage()->exists() ) return;
		// if there is more than one option, we are not redirecting regardless;
		// if there are no options, there's nothing to redirect to
		$pages = $this->getMatchingPages( $title );
		if ( count( $pages ) !== 1 ) return;
		// don't redirect even if Page::exists() failed us
		if ( in_array( $title->getDBKey(), $pages, true) ) return;
		// the only matching title is not the one we're viewing; redirect to it
		foreach ( $pages as $page_id => $page_title ) {
			$target = Title::makeTitle( $title->getNamespace(), $page_title );
		}
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
		// if there is more than one option, we are not relinking regardless;
		// if there are no options, there's nothing to relink to
		$pages = $this->getMatchingPages( $target );
		if ( count( $pages ) !== 1 ) return true;
		// don't relink even if the redlink check failed us
		if ( in_array( $target->getDBKey(), $pages, true) ) return true;
		// the only matching title is not the one we're linking; link to it
		$ns = $target->getNamespace();
		$key = $target->getDBkey();
		foreach ( $pages as $page_id => $page_title ) {
			$target = Title::makeTitle( $ns, $page_title );
		}
		$extraAttribs = self::$memoAttribs[$ns][$key] ?? [];
		$query = self::$memoQuery[$ns][$key] ?? [];
		$ret = $linkRenderer->makeLink( $target, $text, $extraAttribs, $query );
		return false;
	}

}
