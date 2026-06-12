<?php

use MediaWiki\Page\Hook\BeforeDisplayNoArticleTextHook;
use MediaWiki\Hook\InitializeArticleMaybeRedirectHook;
use MediaWiki\Title\Title;
use MediaWiki\Html\Html;
use Wikimedia\Rdbms\ILoadBalancer;

class IgnoreCase implements BeforeDisplayNoArticleTextHook, InitializeArticleMaybeRedirectHook {

	private ILoadBalancer $loadBalancer;
	private static $memoPages = [];

	public function __construct( ILoadBalancer $loadBalancer ) {
		$this->loadBalancer = $loadBalancer;
	}

	private function getMatchingPages( Title $title ): array {
		$key = $title->getPrefixedText();
		if ( !isset( self::$memoPages[$key] ) ) {
			$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
			$res = $dbr->newSelectQueryBuilder()
				->select( [ 'page_title', 'page_id' ] )
				->from( 'page' )
				->where( [ 'page_namespace' => $title->getNamespace() ] )
				->where( [ 'convert(page_title using utf8mb4)' => $title->getDBkey() ] )
				->caller( __METHOD__ )
				->fetchResultSet();

			$pages = [];
			foreach ( $res as $row ) {
				if ( mb_strtolower( $row->page_title ) === mb_strtolower( $title->getDBkey() ) ) {
					// case-insensitive match
					$pages[$row->page_id] = $row->page_title;
				}
			}
			self::$memoPages[$key] = $pages;
		}
		return self::$memoPages[$key];
	}

	public function onInitializeArticleMaybeRedirect(
		$title, $request, &$ignoreRedirect, &$target, &$article
	): void {
		// If the article exists at this title, there is no redirecting to be done.
		// Avoids using Title::isKnown() to avoid an unnecessary DB query.
		if ( $article->getPage()->exists() ) return;
		// if there is more than one option, we are not redirecting regardless
		$pages = $this->getMatchingPages( $title );
		if ( count( $pages ) !== 1 ) return;
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

}
