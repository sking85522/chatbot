<?php
/**
 * SitemapCrawler - Discovers and batch-indexes all pages of a website
 * into Zilliz Cloud via sitemap.xml or internal link traversal.
 */

require_once __DIR__ . '/WebpageScanner.php';

class SitemapCrawler {
    private WebpageScanner $scanner;

    public function __construct(?WebpageScanner $scanner = null) {
        $this->scanner = $scanner ?: new WebpageScanner();
    }

    /**
     * Crawl and index all pages from a sitemap or website root
     *
     * @param string $sourceUrl URL of sitemap.xml or root website domain
     * @param array $options Configuration options (max_pages, chunk_size)
     * @return array Ingestion summary
     */
    public function crawlAndIndex(string $sourceUrl, array $options = []): array {
        $sourceUrl = trim($sourceUrl);
        if (!filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid URL format: {$sourceUrl}");
        }

        $maxPages = (int) ($options['max_pages'] ?? 20);
        $discoveredUrls = $this->discoverUrls($sourceUrl, $maxPages * 2);

        if (empty($discoveredUrls)) {
            // Fall back to scanning the single source URL directly
            $discoveredUrls = [$sourceUrl];
        }

        // Limit to max pages
        $urlsToCrawl = array_slice(array_unique($discoveredUrls), 0, $maxPages);

        $results = [];
        $totalChunks = 0;
        $errors = [];

        foreach ($urlsToCrawl as $url) {
            try {
                $scanRes = $this->scanner->scanUrl($url, $options);
                $totalChunks += ($scanRes['chunks_indexed'] ?? 0);
                $results[] = [
                    'url' => $url,
                    'title' => $scanRes['title'] ?? 'Untitled',
                    'chunks_indexed' => $scanRes['chunks_indexed'] ?? 0,
                    'status' => 'success'
                ];
            } catch (Throwable $e) {
                $errors[] = [
                    'url' => $url,
                    'error' => $e->getMessage()
                ];
                $results[] = [
                    'url' => $url,
                    'title' => 'Failed',
                    'chunks_indexed' => 0,
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        return [
            'success' => true,
            'source' => $sourceUrl,
            'total_urls_discovered' => count($discoveredUrls),
            'pages_crawled' => count($results),
            'total_chunks_indexed' => $totalChunks,
            'pages' => $results,
            'errors' => $errors
        ];
    }

    /**
     * Discover URLs via sitemap.xml or internal link extraction
     */
    public function discoverUrls(string $url, int $max = 50): array {
        $urls = [];

        // 1. If URL ends with .xml or contains 'sitemap', fetch as sitemap
        if (preg_match('/\.xml(\?.*)?$/i', $url) || str_contains(strtolower($url), 'sitemap')) {
            $urls = $this->parseSitemapXml($url, $max);
            if (!empty($urls)) {
                return $urls;
            }
        }

        // 2. Try fetching /sitemap.xml on the host
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $sitemapUrl = "{$scheme}://{$host}{$port}/sitemap.xml";

        if ($sitemapUrl !== $url) {
            $discoveredFromRootSitemap = $this->parseSitemapXml($sitemapUrl, $max);
            if (!empty($discoveredFromRootSitemap)) {
                return $discoveredFromRootSitemap;
            }
        }

        // 3. Fallback: Extract internal links from HTML of page
        return $this->extractInternalLinks($url, $max);
    }

    /**
     * Parse XML sitemap (supports both standard urlset and nested sitemapindex)
     */
    private function parseSitemapXml(string $sitemapUrl, int $max = 50): array {
        $xmlContent = $this->fetchContent($sitemapUrl);
        if (empty($xmlContent)) {
            return [];
        }

        $urls = [];

        // Check if it's a nested sitemap index (<sitemapindex>)
        if (str_contains($xmlContent, '<sitemapindex')) {
            if (preg_match_all('/<sitemap>\s*<loc>(.*?)<\/loc>/is', $xmlContent, $matches)) {
                foreach (array_slice($matches[1], 0, 5) as $subSitemapUrl) {
                    $subSitemapUrl = trim($subSitemapUrl);
                    $subUrls = $this->parseSitemapXml($subSitemapUrl, $max - count($urls));
                    $urls = array_merge($urls, $subUrls);
                    if (count($urls) >= $max) break;
                }
            }
        }

        // Standard <urlset>
        if (preg_match_all('/<loc>(.*?)<\/loc>/is', $xmlContent, $matches)) {
            foreach ($matches[1] as $loc) {
                $loc = trim($loc);
                if (filter_var($loc, FILTER_VALIDATE_URL) && !preg_match('/\.xml$/i', $loc)) {
                    $urls[] = $loc;
                    if (count($urls) >= $max) break;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Extract internal hyperlinks belonging to the same host domain
     */
    private function extractInternalLinks(string $pageUrl, int $max = 50): array {
        $html = $this->fetchContent($pageUrl);
        if (empty($html)) {
            return [$pageUrl];
        }

        $parsedPage = parse_url($pageUrl);
        $pageHost = $parsedPage['host'] ?? '';
        $baseScheme = $parsedPage['scheme'] ?? 'http';
        $port = isset($parsedPage['port']) ? ':' . $parsedPage['port'] : '';
        $baseUrl = "{$baseScheme}://{$pageHost}{$port}";

        $found = [$pageUrl];

        if (preg_match_all('/<a\b[^>]*href=[\'"]([^\'"]+)[\'"]/is', $html, $matches)) {
            foreach ($matches[1] as $href) {
                $href = trim($href);
                if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
                    continue;
                }

                // Ignore static assets
                if (preg_match('/\.(jpg|jpeg|png|gif|svg|webp|pdf|zip|mp4|css|js)$/i', $href)) {
                    continue;
                }

                $fullUrl = '';
                if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                    $hrefHost = parse_url($href, PHP_URL_HOST);
                    if ($hrefHost === $pageHost) {
                        $fullUrl = $href;
                    }
                } elseif (str_starts_with($href, '/')) {
                    $fullUrl = $baseUrl . $href;
                } else {
                    $fullUrl = rtrim($pageUrl, '/') . '/' . $href;
                }

                if (!empty($fullUrl) && !in_array($fullUrl, $found)) {
                    $found[] = $fullUrl;
                    if (count($found) >= $max) break;
                }
            }
        }

        return $found;
    }

    /**
     * Resilient fetcher
     */
    private function fetchContent(string $url): string {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        // Local development optimization for single-threaded built-in PHP server
        if (in_array($host, ['127.0.0.1', 'localhost', '0.0.0.0'])) {
            $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : dirname(__DIR__);
            $localPath = rtrim($docRoot, '/') . '/' . ltrim($parsed['path'] ?? '', '/');
            if (file_exists($localPath)) {
                return (string) file_get_contents($localPath);
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode < 400 && is_string($content)) ? $content : '';
    }
}

