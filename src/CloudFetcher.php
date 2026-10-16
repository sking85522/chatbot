<?php
/**
 * CloudFetcher - Real-Time Autonomous Live Web Crawler & External REST API Fetcher.
 * Automatically ingests & indexes scraped or fetched data straight into Zilliz Cloud Vector DB.
 */

require_once dirname(__DIR__) . '/vector/VectorInsert.php';
require_once dirname(__DIR__) . '/vector/WebpageScanner.php';
require_once dirname(__DIR__) . '/src/WebNavigator.php';

class CloudFetcher {
    private ?VectorInsert $vectorInsert;
    private WebNavigator $navigator;

    public function __construct(?VectorInsert $vectorInsert = null) {
        try {
            $this->vectorInsert = $vectorInsert ?: new VectorInsert();
        } catch (Throwable $t) {
            $this->vectorInsert = null;
        }
        $this->navigator = new WebNavigator();
    }

    /**
     * Fetch any live webpage, extract text, vectorize, and save into Zilliz Cloud DB
     */
    public function fetchAndIngestWebpage(string $url): array {
        $data = $this->navigator->extractContent($url);
        if (!$data['success']) {
            return $data;
        }

        $domain = parse_url($url, PHP_URL_HOST) ?: 'unknown';
        $title = $data['title'] ?? 'Webpage';
        $headings = implode(" | ", $data['headings'] ?? []);
        $sampleText = $data['content_sample'] ?? '';

        $fullDoc = "Webpage: {$title} ({$url})\nDomain: {$domain}\nHeadings: {$headings}\nContent:\n{$sampleText}";

        $insertedCount = 0;
        if ($this->vectorInsert !== null) {
            try {
                // Chunk into 800-char pieces
                $chunks = str_split($sampleText, 800);
                foreach ($chunks as $idx => $chunk) {
                    $chunkDoc = "Webpage [{$title}] ({$url}) - Part {$idx}:\n{$chunk}";
                    $this->vectorInsert->insertText($chunkDoc, [
                        'type' => 'crawled_knowledge',
                        'domain' => $domain,
                        'url' => $url,
                        'title' => $title
                    ]);
                    $insertedCount++;
                }
            } catch (Throwable $t) {}
        }

        return [
            'success' => true,
            'url' => $url,
            'domain' => $domain,
            'title' => $title,
            'headings' => $data['headings'] ?? [],
            'chunks_indexed_to_zilliz' => $insertedCount,
            'summary' => substr($sampleText, 0, 500) . '...'
        ];
    }

    /**
     * Fetch an external third-party JSON API, summarize it, and optionally cache in Zilliz DB
     */
    public function fetchApi(string $apiUrl, string $method = 'GET', array $params = [], array $headers = []): array {
        $ch = curl_init();
        $url = $apiUrl;

        $httpHeaders = [
            'Accept: application/json',
            'User-Agent: HRITIK-AI-CloudFetcher/1.0'
        ];
        foreach ($headers as $k => $v) {
            $httpHeaders[] = "{$k}: {$v}";
        }

        if (strtoupper($method) === 'GET' && !empty($params)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        }

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || empty($res) || $httpCode >= 400) {
            return [
                'success' => false,
                'error' => "API fetch failed (HTTP {$httpCode}): " . ($err ?: 'Empty response'),
                'url' => $apiUrl
            ];
        }

        $decoded = json_decode($res, true);
        $dataSample = $decoded ?: substr($res, 0, 1000);

        // Vectorize and cache API data into Zilliz
        if ($this->vectorInsert !== null) {
            try {
                $docText = "API Response from {$apiUrl}:\n" . substr(json_encode($dataSample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0, 1500);
                $this->vectorInsert->insertText($docText, [
                    'type' => 'api_cache',
                    'domain' => parse_url($apiUrl, PHP_URL_HOST) ?: 'api',
                    'url' => $apiUrl
                ]);
            } catch (Throwable $t) {}
        }

        return [
            'success' => true,
            'url' => $apiUrl,
            'status_code' => $httpCode,
            'data' => $dataSample
        ];
    }
}

