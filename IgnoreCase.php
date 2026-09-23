<?php

use MediaWiki\Page\Hook\BeforeDisplayNoArticleTextHook;
use MediaWiki\Page\RedirectStore;
use MediaWiki\Hook\InitializeArticleMaybeRedirectHook;
use MediaWiki\Title\Title;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Context\RequestContext;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Html\Html;
use Wikimedia\Rdbms\ILoadBalancer;

class IgnoreCase implements BeforeDisplayNoArticleTextHook, InitializeArticleMaybeRedirectHook {

	private ILoadBalancer $loadBalancer;
	private Config $config;
	private RedirectStore $redirectStore;
	private static $memoPages = [];
	private static $memoAttribs = [];
	private static $memoQuery = [];

	public function __construct(
		ILoadBalancer $loadBalancer,
		ConfigFactory $configFactory,
		RedirectStore $redirectStore,
	) {
		$this->loadBalancer = $loadBalancer;
		$this->config = $configFactory->makeConfig( 'main' );
		$this->redirectStore = $redirectStore;
	}

	private function getMatchingPages( LinkTarget $target, bool $suffixes = false ): array {
		$ns = $target->getNamespace();
		$key = $target->getDBkey();
		if ( !isset( self::$memoPages[$ns] ) || !isset( self::$memoPages[$ns][$key] ) ) {
			$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
			if ( $suffixes ) {
				$where = 'convert(page_title using utf8mb4)' . $dbr->buildLike( $key, $dbr->anyString() );
			} else {
				$where = [ 'convert(page_title using utf8mb4)' => $key ];
			}
			$res = $dbr->newSelectQueryBuilder()
				->select( [ 'page_title', 'page_id' ] )
				->from( 'page' )
				->where( [ 'page_namespace' => $ns ] )
				->where( $where )
				->caller( __METHOD__ )
				->fetchResultSet();

			$pages = [];
			$iKey = mb_strtolower( $key );
			foreach ( $res as $row ) {
				$query = mb_strtolower( $row->page_title );
				if (
					$suffixes
					? str_starts_with( $query, $iKey )
					: $query === $iKey
				) {
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

	private function updateRedirectTarget( $wikiPage ) {
		if ( !$this->config->get( 'IgnoreCaseInLinks' ) ) return;
		$redir = $this->redirectStore->getRedirectTarget( $wikiPage );
		// This article is not a redirect
		if ( $redir === null ) return;
		$oldKey = $redir->getDBkey();
		$redir = $this->getOnePage( $redir );
		// This redirect target doesn't have exactly one case variation
		if ( $redir === null ) return;
		// No update necessary
		if ( $redir->getDBkey() === $oldKey ) return;
		$this->redirectStore->updateRedirectTarget( $wikiPage, $redir );
	}
	public function onPageSaveComplete(
		$wikiPage, $user, string $summary, int $flags, $revisionRecord, $editResult
	) {
		$this->updateRedirectTarget( $page );
		return true;
	}
	public function onPageUndeleteComplete(
		$page, $restorer, string $reason, $restoredRev, $logEntry,
		int $restoredRevisionCount, bool $created, array $restoredPageIds
	) {
		$this->updateRedirectTarget( $page );
	}
	public function onArticlePurge( &$article ) {
		$this->updateRedirectTarget( $article );
		return true;
	}
	public function onAfterImportPage(
		$title, $origTitle, $revCount, $sRevCount, $pageInfo
	) {
		$this->updateRedirectTarget( $title );
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

	// NOTE: onSearchGetNearMatch is unnecessary because MW core already
	// redirects case-insensitively on a committed search. This just adds
	// autocompletion.
	public function onApiOpenSearchSuggest( &$results ): void {
		if ( !$this->config->get( 'IgnoreCaseInSearchSuggestions' ) ) return;
		$search = RequestContext::getMain()->getRequest()->getText( 'search' );
		$key = Title::newFromText( $search );
		$pages = $this->getMatchingPages( $key, true );
		if ( $key === null ) return;
		foreach ( $pages as $pageid => $page_title ) {
			$title = Title::makeTitle( $key->getNamespace(), $page_title );
			$results[$pageid] = [
				'title' => $title,
				'redirect from' => null,
				'extract' => false,
				'extract trimmed' => false,
				'image' => false,
				'url' => $title->getFullURL(),
			];
		}
	}

	public function onHtmlPageLinkRendererBegin(
		$linkRenderer, $target, &$text, &$extraAttribs, &$query, &$ret
	): bool {
		if ( !$this->config->get( 'IgnoreCaseInLinks' ) ) return true;
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

		if ( !$this->config->get( 'IgnoreCaseInLinks' ) ) return true;

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
