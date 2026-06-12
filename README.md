# IgnoreCase
Redirect or disambiguate titles that case-insensitively match existing ones.

## Mechanism
* When exactly one case variation of a title exists, all alternate casings will be redirected to that title as if a `#REDIRECT` existed there (including a "Redirected from" message).
* When more than one case variation exists, or when `?redirect=no` applies, they are listed before the normal missing article text.
* When no such title exists in any case, the normal missing article text is displayed as usual.

## Comparison with [SaneCase](https://www.mediawiki.org/wiki/Extension:SaneCase)
| Property | SaneCase | IgnoreCase |
| -------- | -------- | ---------- |
| Redirection mechanism | Raw PHP `header( 'HTTP/1.1 301 Moved Permanently' )` | Existing MediaWiki `#REDIRECT` infrastructure |
| Behavior with multiple existing case variations | Redirect to the one which the database arbitrarily returns first | List variations in missing article message |
| Extra queries per *existing* article | Zero | Zero |
| Extra queries per *missing* article | One select from `page` + one `Title::newFromID()` call | One select from `page` |

### Notes
* In both SaneCase and IgnoreCase, one query is required to find the existing case variation. However, SaneCase uses `Title::newFromID()`, which could cause an extra query, in order to then `->getLocalURL()`.
* SaneCase avoids extra queries for existing articles by only using the [BeforeDisplayNoArticleText](https://www.mediawiki.org/wiki/Manual:Hooks/BeforeDisplayNoArticleText) hook, which is only triggered when the article's existence has already been checked. IgnoreCase avoids extra queries for existing articles by using `WikiPage::exists()` which makes no extra queries.
* The "existing MediaWiki `#REDIRECT` infrastructure" in question calls the [InitializeArticleMaybeRedirect](https://www.mediawiki.org/wiki/Manual:Hooks/InitializeArticleMaybeRedirect) hook, which is what IgnoreCase uses.
