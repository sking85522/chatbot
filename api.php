<?php
/**
 * Universal Chatbot API - Main Entry Point with Zilliz Cloud Vector Database Integration.
 *
 * Endpoints:
 *   GET  /api.php -> Health check & Vector DB connection status
 *   POST /api.php -> AI Chatbot completion, Vector Knowledge Search, & Insert
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/src/ChatService.php';
require_once __DIR__ . '/src/SiteLogger.php';
require_once __DIR__ . '/src/LeadManager.php';
require_once __DIR__ . '/src/SuggestionEngine.php';
require_once __DIR__ . '/src/PageGenerator.php';
require_once __DIR__ . '/src/WebNavigator.php';
require_once __DIR__ . '/src/ProjectAuditor.php';
require_once __DIR__ . '/vector/VectorSearch.php';
require_once __DIR__ . '/vector/VectorInsert.php';
require_once __DIR__ . '/vector/WebpageScanner.php';
require_once __DIR__ . '/vector/SitemapCrawler.php';
require_once __DIR__ . '/vector/AutoSyncManager.php';
require_once __DIR__ . '/vector/StorageManager.php';

// Set CORS headers & handle OPTIONS preflight
handle_cors();

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// 1. Health check & status endpoint (GET)
if ($requestMethod === 'GET') {
    $storageManager = new StorageManager();
    $storageStats = $storageManager->getStats();

    send_json([
        'status' => 'online',
        'service' => APP_NAME,
        'version' => '1.2.0',
        'default_model' => KIRA_DEFAULT_MODEL,
        'vector_database' => [
            'provider' => 'Zilliz Cloud (Milvus Serverless)',
            'collection' => ZILLIZ_COLLECTION,
            'dimension' => ZILLIZ_DIMENSION,
            'endpoint' => ZILLIZ_ENDPOINT ? parse_url(ZILLIZ_ENDPOINT, PHP_URL_HOST) : 'Not configured',
            'storage' => $storageStats
        ],
        'timestamp' => date('c'),
        'actions' => [
            'chat' => ['method' => 'POST', 'body' => ['message' => 'Hello!', 'session_id' => 'optional', 'current_url' => 'optional_page_url']],
            'scan_webpage' => ['method' => 'POST', 'body' => ['action' => 'scan_webpage', 'url' => 'https://example.com']],
            'storage_status' => ['method' => 'POST', 'body' => ['action' => 'storage_status']],
            'storage_cleanup' => ['method' => 'POST', 'body' => ['action' => 'storage_cleanup']],
            'site_logs' => ['method' => 'POST', 'body' => ['action' => 'site_logs', 'limit' => 50]],
            'vector_search' => ['method' => 'POST', 'body' => ['action' => 'vector_search', 'query' => 'search query', 'limit' => 5]],
            'vector_insert' => ['method' => 'POST', 'body' => ['action' => 'vector_insert', 'text' => 'document text to store']]
        ]
    ]);
}

// 2. Chat completion & Vector Actions (POST)
if ($requestMethod === 'POST') {
    try {
        $chatService = new ChatService();

        // Check client authentication if CLIENT_ACCESS_TOKEN is set
        $chatService->checkClientAuth();

        // Parse incoming request body
        $input = $chatService->parseRequestInput();

        // Action A: Webpage Scanner & URL Knowledge Ingestion
        if (isset($input['action']) && in_array($input['action'], ['scan_webpage', 'scan_url'])) {
            if (empty($input['url'])) {
                throw new Exception("Missing 'url' parameter for webpage scanner.");
            }
            $scanner = new WebpageScanner();
            $scanResult = $scanner->scanUrl((string) $input['url'], (array) ($input['options'] ?? []));

            $siteLogger = new SiteLogger();
            $siteLogger->log('scan_webpage', [
                'target_url' => $input['url'],
                'domain' => $scanResult['domain'] ?? null,
                'chunks_indexed' => $scanResult['chunks_indexed'] ?? 0
            ]);

            send_json([
                'success' => true,
                'action' => 'scan_webpage',
                'data' => $scanResult
            ]);
        }

        // Action A2: Sitemap & Multi-Page Deep Crawler
        if (isset($input['action']) && in_array($input['action'], ['crawl_sitemap', 'crawl_website'])) {
            if (empty($input['url'])) {
                throw new Exception("Missing 'url' parameter for sitemap crawler.");
            }
            $crawler = new SitemapCrawler();
            $crawlResult = $crawler->crawlAndIndex((string) $input['url'], (array) ($input['options'] ?? []));

            $siteLogger = new SiteLogger();
            $siteLogger->log('crawl_sitemap', [
                'target_url' => $input['url'],
                'pages_crawled' => $crawlResult['pages_crawled'] ?? 0,
                'total_chunks_indexed' => $crawlResult['total_chunks_indexed'] ?? 0
            ]);

            send_json([
                'success' => true,
                'action' => 'crawl_sitemap',
                'data' => $crawlResult
            ]);
        }

        // Action A2.5: Auto-Sync Website Knowledge (Background discovery & auto-vectorization)
        if (isset($input['action']) && $input['action'] === 'auto_sync') {
            if (empty($input['url'])) {
                throw new Exception("Missing 'url' parameter for auto_sync.");
            }
            $autoSync = new AutoSyncManager();
            $fullSite = !isset($input['full_site']) || !empty($input['full_site']);
            $syncResult = $autoSync->autoSync((string) $input['url'], $fullSite);

            send_json([
                'success' => true,
                'action' => 'auto_sync',
                'data' => $syncResult
            ]);
        }

        // Action A3: Dynamic Context-Aware Suggestion Chips
        if (isset($input['action']) && $input['action'] === 'get_suggestions') {
            $engine = new SuggestionEngine();
            $suggestions = $engine->getSuggestions(
                $input['url'] ?? null,
                $input['page_title'] ?? null,
                $input['recent_query'] ?? null
            );
            send_json([
                'success' => true,
                'action' => 'get_suggestions',
                'suggestions' => $suggestions
            ]);
        }

        // Action A4: Leads Management & List
        if (isset($input['action']) && $input['action'] === 'get_leads') {
            $leadManager = new LeadManager();
            $limit = isset($input['limit']) ? (int) $input['limit'] : 50;
            send_json([
                'success' => true,
                'action' => 'get_leads',
                'stats' => $leadManager->getStats(),
                'leads' => $leadManager->getLeads($limit)
            ]);
        }

        // Action A5: Direct Lead Submission
        if (isset($input['action']) && $input['action'] === 'submit_lead') {
            $leadManager = new LeadManager();
            $saved = $leadManager->saveLead((array) $input);
            send_json([
                'success' => true,
                'action' => 'submit_lead',
                'lead' => $saved
            ]);
        }

        // Action A6: On-Demand Dynamic Webpage Generator
        if (isset($input['action']) && $input['action'] === 'create_page') {
            if (empty($input['title']) || empty($input['content'])) {
                throw new Exception("Missing 'title' or 'content' for page generator.");
            }
            $generator = new PageGenerator();
            $pageResult = $generator->generatePage(
                (string) $input['title'],
                (string) $input['content'],
                (string) ($input['category'] ?? 'general'),
                (array) ($input['meta'] ?? [])
            );
            send_json([
                'success' => true,
                'action' => 'create_page',
                'data' => $pageResult
            ]);
        }

        // Action A7: Deep Webpage Extractor & Scraper
        if (isset($input['action']) && $input['action'] === 'extract_webpage') {
            if (empty($input['url'])) {
                throw new Exception("Missing 'url' parameter for webpage extraction.");
            }
            $navigator = new WebNavigator();
            $extractResult = $navigator->extractContent((string) $input['url']);
            send_json([
                'success' => true,
                'action' => 'extract_webpage',
                'data' => $extractResult
            ]);
        }

        // Action A8: Project Structure Scan (Secure Sandboxed)
        if (isset($input['action']) && $input['action'] === 'scan_project') {
            $auditor = new ProjectAuditor();
            $scanResult = $auditor->scanStructure((string) ($input['path'] ?? ''));
            send_json([
                'success' => true,
                'action' => 'scan_project',
                'data' => $scanResult
            ]);
        }

        // Action A9: Read Project File (Secure Sandboxed)
        if (isset($input['action']) && $input['action'] === 'read_project_file') {
            if (empty($input['file'])) {
                throw new Exception("Missing 'file' parameter.");
            }
            $auditor = new ProjectAuditor();
            $fileResult = $auditor->readFile((string) $input['file']);
            send_json([
                'success' => $fileResult['success'],
                'action' => 'read_project_file',
                'data' => $fileResult
            ], $fileResult['success'] ? 200 : 403);
        }

        // Action B: Storage Status & Health Monitor
        if (isset($input['action']) && $input['action'] === 'storage_status') {
            $storageManager = new StorageManager();
            $stats = $storageManager->getStats(true);
            send_json([
                'success' => true,
                'action' => 'storage_status',
                'stats' => $stats
            ]);
        }

        // Action C: Storage Cleanup / Auto-Purge Trigger
        if (isset($input['action']) && $input['action'] === 'storage_cleanup') {
            $storageManager = new StorageManager();
            $customRecords = isset($input['records']) ? (int) $input['records'] : null;
            $force = !empty($input['force']);
            $cleanupResult = $storageManager->checkAndClean($force, $customRecords);
            send_json([
                'success' => true,
                'action' => 'storage_cleanup',
                'result' => $cleanupResult
            ]);
        }

        // Action D: Site Origins & Activity Logs
        if (isset($input['action']) && $input['action'] === 'site_logs') {
            $siteLogger = new SiteLogger();
            $limit = isset($input['limit']) ? (int) $input['limit'] : 50;
            send_json([
                'success' => true,
                'action' => 'site_logs',
                'summary' => $siteLogger->getDomainSummary(),
                'recent_logs' => $siteLogger->getRecentLogs($limit)
            ]);
        }

        // Action E: Vector Similarity Search
        if (isset($input['action']) && $input['action'] === 'vector_search') {
            if (empty($input['query'])) {
                throw new Exception("Missing 'query' string for vector search.");
            }
            $searcher = new VectorSearch();
            $matches = $searcher->search(
                (string) $input['query'],
                (int) ($input['limit'] ?? 5),
                $input['filter'] ?? null
            );
            send_json([
                'success' => true,
                'action' => 'vector_search',
                'query' => $input['query'],
                'matches' => $matches
            ]);
        }

        // Action F: Vector Knowledge Insert
        if (isset($input['action']) && $input['action'] === 'vector_insert') {
            if (empty($input['text'])) {
                throw new Exception("Missing 'text' string for vector insertion.");
            }
            $inserter = new VectorInsert();
            $res = $inserter->insertText(
                (string) $input['text'],
                (array) ($input['metadata'] ?? []),
                $input['id'] ?? null
            );
            send_json([
                'success' => true,
                'action' => 'vector_insert',
                'result' => $res
            ]);
        }

        // If client requested real-time streaming, stream directly with zero delay
        $isStream = (!empty($input['stream']) || isset($_GET['stream']));
        if ($isStream) {
            $chatService->handleStreamChat($input);
            exit;
        }

        // Action C: Standard Chat Completion (with automatic Vector Memory)
        $result = $chatService->handleChat($input);
        send_json($result, 200);

    } catch (Exception $e) {
        $statusCode = $e->getCode();
        if ($statusCode < 400 || $statusCode > 599) {
            $statusCode = 400;
        }

        send_json([
            'success' => false,
            'error' => $e->getMessage()
        ], $statusCode);
    }
}

// Method not allowed
send_json([
    'success' => false,
    'error' => "Method {$requestMethod} not allowed. Please use POST or GET."
], 405);