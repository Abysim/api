<!-- Parent: ../AGENTS.md -->
<!-- Generated: 2026-03-06 | Updated: 2026-03-06 -->

# News

## Purpose
Free (no-API-key) news aggregation sources. An alternative to the paid NewsCatcher API, combining Google News RSS and GDELT Project as data sources with Readability-based content extraction.

## Key Files

| File | Description |
|------|-------------|
| `FreeNewsService.php` | Main orchestrator implementing `NewsServiceInterface`. Three-phase pipeline: (1) fetch metadata from Google News + GDELT, (2) title pre-filter with keyword matching and dedup, (3) content extraction via Readability. Includes SSRF protection via `isUrlSafe()`. |
| `GoogleNewsSource.php` | Fetches article metadata from Google News RSS feeds. Builds language-specific RSS URLs and parses XML responses. |
| `GdeltSource.php` | Fetches article metadata from GDELT DOC 2.0 API. Supports country/domain exclusions and language filtering. |
| `GoogleNewsUrlDecoder.php` | Decodes Google News protobuf URLs to real article URLs. Two methods: legacy base64 (CBMi prefix) and batchexecute API (AU_yqL prefix, requires SOCS consent cookie + signature/timestamp from article page). |

## For AI Agents

### Working In This Directory
- `FreeNewsService` is selected when `NEWS_DRIVER=free` in `.env`
- The service uses `FileHelper::getUrl()` for HTTP fetching (with ScraperAPI fallback)
- Google News URLs are decoded via `GoogleNewsUrlDecoder` (not HTTP redirects — redirects don't work)
- The SOCS consent cookie in `GoogleNewsUrlDecoder` bypasses Google's EU/GDPR consent redirect
- GDELT uses **FIPS country codes**, not ISO — Russia=`RS`, Belarus=`BO`, China=`CH`. See `GDELT_COUNTRY_MAP` in `GdeltSource.php`
- `max_enrich` config limits how many articles get full content extraction (default 30)
- Content extraction uses `fivefilters/readability.php` library
- Search query format: `(word1 OR word2) !exclude1 !exclude2`
- Title dedup uses `similar_text()` at 70% threshold (same as NewsController)

### Testing Requirements
- Test with both `en` and `uk` language queries
- Verify SSRF protection rejects private/reserved IP ranges

<!-- MANUAL: -->
