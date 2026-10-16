<?php
/**
 * WebpageScanner - Crawls, parses, extracts clean text from any website URL,
 * splits it into semantic chunks, and vectorizes them into Zilliz Cloud
 * with type = 'webpage_knowledge'.
 */

require_once __DIR__ . '/ZillizClient.php';
require_once __DIR__ . '/EmbeddingService.php';
require_once __DIR__ . '/VectorInsert.php';

class WebpageScanner {
    private ZillizClient $client;
    private EmbeddingService $embedder;
    private VectorInsert $vectorInsert;

    public function __construct(?ZillizClient $client = null, ?EmbeddingService $embedder = null, ?VectorInsert $vectorInsert = null) {
        $this->client = $client ?: new ZillizClient();
        $this->embedder = $embedder ?: new EmbeddingService();
        $this->vectorInsert = $vectorInsert ?: new VectorInsert($this->client, $this->embedder);
    }

    /**
     * Scan and ingest an entire webpage URL into Zilliz Cloud Vector DB
     *
     * @param string $url The webpage URL to scan
     * @param array $options Optional configuration (max_chunks, chunk_size, chunk_overlap)
     * @return array Scan and insertion summary
     */
    public function scanUrl(string $url, array $options = []): array {
        $url = trim($url);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid URL format: {$url}");
        }

        $domain = parse_url($url, PHP_URL_HOST) ?: 'unknown';

        // 1. Fetch HTML content
        $html = $this->fetchUrlHtml($url);
        if (empty($html)) {
            throw new RuntimeException("Failed to fetch content from URL or page is empty: {$url}");
        }

        // 2. Extract Metadata & Clean Text
        $extracted = $this->extractContent($html, $url);
        $title = $extracted['title'] ?: $domain;
        $cleanText = $extracted['text'];

        if (mb_strlen($cleanText, 'UTF-8') < 30) {
            throw new RuntimeException("The page does not contain sufficient text content to index.");
        }

        // 3. Chunk text into semantic pieces
        $chunkSize = (int) ($options['chunk_size'] ?? 450);
        $overlap = (int) ($options['chunk_overlap'] ?? 60);
        $maxChunks = (int) ($options['max_chunks'] ?? 50);

        $chunks = $this->chunkText($cleanText, $chunkSize, $overlap, $maxChunks);
        if (empty($chunks)) {
            throw new RuntimeException("Could not create text chunks from the webpage content.");
        }

        // 4. Vectorize & Batch Insert into Zilliz Cloud
        $urlHash = substr(md5($url), 0, 10);
        $records = [];
        $totalChunks = count($chunks);

        foreach ($chunks as $index => $chunk) {
            // Prepend contextual source header so Kira AI knows the origin
            $contextualText = "[Webpage: {$title} | URL: {$url}]\n{$chunk}";
            $vector = $this->embedder->embed($contextualText);

            $records[] = [
                'id' => "web_{$urlHash}_{$index}",
                'vector' => $vector,
                'text' => $contextualText,
                'url' => $url,
                'domain' => $domain,
                'title' => $title,
                'chunk_index' => $index,
                'total_chunks' => $totalChunks,
                'type' => 'webpage_knowledge',
                'created_at' => time()
            ];
        }

        // Insert records into Zilliz
        $res = $this->vectorInsert->insertBatch($records);

        return [
            'success' => true,
            'url' => $url,
            'domain' => $domain,
            'title' => $title,
            'description' => $extracted['description'],
            'total_characters' => mb_strlen($cleanText, 'UTF-8'),
            'chunks_indexed' => $totalChunks,
            'zilliz_response' => $res,
            'sample_chunks' => array_slice($chunks, 0, 3)
        ];
    }

    /**
     * Fetch HTML from URL with resilient headers & redirect support
     */
    private function fetchUrlHtml(string $url): string {
        // Optimization for local development: if host is localhost/127.0.0.1 and file exists locally, read directly
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
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
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 7,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING => '', // supports gzip, deflate
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9,hi;q=0.8'
            ]
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException("cURL Error fetching URL: " . $curlError);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("HTTP {$httpCode} returned by server for {$url}");
        }

        return (string) $html;
    }

    /**
     * Extract title, meta description, and clean readable text from HTML
     */
    public function extractContent(string $html, string $url = ''): array {
        // 1. Extract Title
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $title = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // 2. Extract Meta Description
        $description = '';
        if (preg_match('/<meta[^>]*name=[\'"]description[\'"][^>]*content=[\'"](.*?)[\'"]/is', $html, $matches)) {
            $description = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        } elseif (preg_match('/<meta[^>]*property=[\'"]og:description[\'"][^>]*content=[\'"](.*?)[\'"]/is', $html, $matches)) {
            $description = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // 3. Remove non-content elements
        $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $clean = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $clean);
        $clean = preg_replace('/<noscript\b[^>]*>(.*?)<\/noscript>/is', '', $clean);
        $clean = preg_replace('/<svg\b[^>]*>(.*?)<\/svg>/is', '', $clean);
        $clean = preg_replace('/<canvas\b[^>]*>(.*?)<\/canvas>/is', '', $clean);
        $clean = preg_replace('/<header\b[^>]*>(.*?)<\/header>/is', '', $clean);
        $clean = preg_replace('/<footer\b[^>]*>(.*?)<\/footer>/is', '', $clean);
        $clean = preg_replace('/<nav\b[^>]*>(.*?)<\/nav>/is', '', $clean);
        $clean = preg_replace('/<!--(.*?)-->/s', '', $clean);

        // 4. Convert block elements to newlines
        $clean = preg_replace('/<(?:p|div|section|article|li|tr|h[1-6]|br)[^>]*>/i', "\n", $clean);

        // 5. Strip remaining tags
        $clean = strip_tags($clean);

        // 6. Decode HTML entities
        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 7. Normalize whitespace
        $clean = preg_replace("/[ \t]+/", ' ', $clean);
        $clean = preg_replace("/\n\s*\n+/", "\n\n", $clean);
        $clean = trim($clean);

        // If title is still empty, fall back to first line
        if (empty($title)) {
            $firstLine = strtok($clean, "\n");
            $title = mb_substr($firstLine ?: 'Webpage', 0, 80);
        }

        return [
            'title' => $title,
            'description' => $description,
            'text' => $clean
        ];
    }

    /**
     * Split text into overlapping semantic chunks
     *
     * @param string $text
     * @param int $chunkSize Target chunk character length
     * @param int $overlap Overlap characters between chunks
     * @param int $maxChunks Maximum chunks allowed per page
     * @return array Array of chunk strings
     */
    public function chunkText(string $text, int $chunkSize = 450, int $overlap = 60, int $maxChunks = 50): array {
        $paragraphs = explode("\n\n", $text);
        $chunks = [];
        $currentChunk = '';

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (empty($para)) {
                continue;
            }

            // If a single paragraph is longer than chunk size, split by sentences
            if (mb_strlen($para, 'UTF-8') > $chunkSize) {
                $sentences = preg_split('/(?<=[.?!])\s+/', $para);
                foreach ($sentences as $sentence) {
                    $sentence = trim($sentence);
                    if (empty($sentence)) continue;

                    if (mb_strlen($currentChunk . ' ' . $sentence, 'UTF-8') > $chunkSize && !empty($currentChunk)) {
                        $chunks[] = trim($currentChunk);
                        if (count($chunks) >= $maxChunks) break 2;
                        
                        // Overlap: retain tail of current chunk cleanly at word boundary
                        $tail = '';
                        if ($overlap > 0 && mb_strlen($currentChunk, 'UTF-8') > $overlap) {
                            $rawTail = mb_substr($currentChunk, -$overlap, null, 'UTF-8');
                            $firstSpace = mb_strpos($rawTail, ' ', 0, 'UTF-8');
                            $tail = ($firstSpace !== false) ? mb_substr($rawTail, $firstSpace + 1, null, 'UTF-8') : $rawTail;
                        }
                        $currentChunk = trim($tail . ' ' . $sentence);
                    } else {
                        $currentChunk = empty($currentChunk) ? $sentence : $currentChunk . ' ' . $sentence;
                    }
                }
            } else {
                if (mb_strlen($currentChunk . "\n" . $para, 'UTF-8') > $chunkSize && !empty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                    if (count($chunks) >= $maxChunks) break;

                    $tail = '';
                    if ($overlap > 0 && mb_strlen($currentChunk, 'UTF-8') > $overlap) {
                        $rawTail = mb_substr($currentChunk, -$overlap, null, 'UTF-8');
                        $firstSpace = mb_strpos($rawTail, ' ', 0, 'UTF-8');
                        $tail = ($firstSpace !== false) ? mb_substr($rawTail, $firstSpace + 1, null, 'UTF-8') : $rawTail;
                    }
                    $currentChunk = trim($tail . "\n" . $para);
                } else {
                    $currentChunk = empty($currentChunk) ? $para : $currentChunk . "\n" . $para;
                }
            }
        }

        if (!empty($currentChunk) && count($chunks) < $maxChunks) {
            $chunks[] = trim($currentChunk);
        }

        // Filter out empty or trivially tiny chunks
        return array_values(array_filter($chunks, fn($c) => mb_strlen(trim($c), 'UTF-8') >= 20));
    }

    /**
     * Delete all indexed knowledge for a specific URL
     */
    public function deletePageKnowledge(string $url): array {
        $collection = $this->client->getDefaultCollection();
        $safeUrl = addslashes($url);
        return $this->client->request('entities/delete', [
            'collectionName' => $collection,
            'filter' => "url == \"{$safeUrl}\""
        ]);
    }
}
