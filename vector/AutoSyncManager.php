<?php
/**
 * AutoSyncManager - Autonomous real-time knowledge discovery and auto-syncing
 * for any website or domain hosting the HRITIK AI widget.
 *
 * 100% Cloud-native & stateless:
 * - Checks Zilliz Cloud if a domain or page has already been vectorized.
 * - If not yet indexed, automatically extracts content, chunks it, and pushes
 *   it directly to Zilliz Cloud Vector DB under type: 'webpage_knowledge'.
 * - No local file storage used.
 */

require_once __DIR__ . '/WebpageScanner.php';
require_once __DIR__ . '/SitemapCrawler.php';
require_once __DIR__ . '/ZillizClient.php';

class AutoSyncManager {
    private WebpageScanner $scanner;
    private SitemapCrawler $crawler;
    private ZillizClient $client;

    public function __construct(
        ?WebpageScanner $scanner = null,
        ?SitemapCrawler $crawler = null,
        ?ZillizClient $client = null
    ) {
        $this->scanner = $scanner ?: new WebpageScanner();
        $this->crawler = $crawler ?: new SitemapCrawler($this->scanner);
        $this->client = $client ?: new ZillizClient();
    }

    /**
     * Check if domain or URL already has indexed knowledge in Zilliz
     */
    public function isIndexed(string $urlOrDomain): bool {
        $domain = parse_url($urlOrDomain, PHP_URL_HOST) ?: $urlOrDomain;
        $domain = trim(strtolower($domain));

        try {
            $safeDomain = addslashes($domain);
            $results = $this->client->query("domain == '{$safeDomain}' and type == 'webpage_knowledge'", ['id'], 1);
            return !empty($results);
        } catch (Throwable $t) {
            return false;
        }
    }

    /**
     * Auto-sync domain or current page:
     * Discovers pages/sitemap and indexes them into Zilliz Cloud if not yet done.
     *
     * @param string $pageUrl The URL of the page hosting the widget
     * @param bool $fullSite Whether to crawl internal sitemap/links (up to 10 pages) or single page
     * @return array Ingestion report
     */
    public function autoSync(string $pageUrl, bool $fullSite = true): array {
        $pageUrl = trim($pageUrl);
        if (!filter_var($pageUrl, FILTER_VALIDATE_URL)) {
            return [
                'success' => false,
                'error' => 'Invalid URL provided for auto-sync.'
            ];
        }

        $domain = parse_url($pageUrl, PHP_URL_HOST) ?: 'unknown';

        // Check if already indexed
        if ($this->isIndexed($domain)) {
            return [
                'success' => true,
                'already_indexed' => true,
                'domain' => $domain,
                'message' => "Domain '{$domain}' is already synchronized in Zilliz Cloud Vector DB."
            ];
        }

        // Perform auto-ingestion
        if ($fullSite) {
            // Crawl up to 8 core pages or sitemap to give HRITIK AI full store/site intelligence
            $res = $this->crawler->crawlAndIndex($pageUrl, [
                'max_pages' => 8,
                'chunk_size' => 450
            ]);

            return [
                'success' => true,
                'already_indexed' => false,
                'domain' => $domain,
                'pages_crawled' => $res['pages_crawled'] ?? 0,
                'total_chunks_indexed' => $res['total_chunks_indexed'] ?? 0,
                'message' => "Auto-synchronized {$domain} into Zilliz Cloud Vector DB."
            ];
        } else {
            // Single page scan
            $scanRes = $this->scanner->scanUrl($pageUrl);
            return [
                'success' => true,
                'already_indexed' => false,
                'domain' => $domain,
                'title' => $scanRes['title'] ?? '',
                'chunks_indexed' => $scanRes['chunks_indexed'] ?? 0,
                'message' => "Auto-synchronized page into Zilliz Cloud Vector DB."
            ];
        }
    }
}

