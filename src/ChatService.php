<?php
/**
 * ChatService - Handles request normalization, authorization, session history,
 * Zilliz Cloud Vector Memory retrieval & persistence, and response formatting.
 */

require_once __DIR__ . '/KiraClient.php';
require_once __DIR__ . '/SessionManager.php';
require_once __DIR__ . '/SiteLogger.php';
require_once __DIR__ . '/LeadManager.php';
require_once __DIR__ . '/PageGenerator.php';
require_once __DIR__ . '/WebNavigator.php';
require_once __DIR__ . '/CloudFetcher.php';
require_once __DIR__ . '/ProjectAuditor.php';
require_once dirname(__DIR__) . '/vector/VectorSearch.php';
require_once dirname(__DIR__) . '/vector/VectorInsert.php';
require_once dirname(__DIR__) . '/vector/StorageManager.php';

class ChatService {
    private KiraClient $client;
    private SessionManager $sessionManager;
    private SiteLogger $siteLogger;
    private StorageManager $storageManager;
    private LeadManager $leadManager;
    private PageGenerator $pageGenerator;
    private WebNavigator $webNavigator;
    private CloudFetcher $cloudFetcher;
    private ProjectAuditor $projectAuditor;
    private ?VectorSearch $vectorSearch;
    private ?VectorInsert $vectorInsert;

    public function __construct(
        ?KiraClient $client = null,
        ?SessionManager $sessionManager = null,
        ?VectorSearch $vectorSearch = null,
        ?VectorInsert $vectorInsert = null,
        ?SiteLogger $siteLogger = null,
        ?StorageManager $storageManager = null,
        ?LeadManager $leadManager = null,
        ?PageGenerator $pageGenerator = null,
        ?WebNavigator $webNavigator = null,
        ?CloudFetcher $cloudFetcher = null,
        ?ProjectAuditor $projectAuditor = null
    ) {
        $this->client = $client ?: new KiraClient();
        $this->sessionManager = $sessionManager ?: new SessionManager();
        $this->siteLogger = $siteLogger ?: new SiteLogger();
        $this->storageManager = $storageManager ?: new StorageManager();
        $this->leadManager = $leadManager ?: new LeadManager();
        $this->pageGenerator = $pageGenerator ?: new PageGenerator();
        $this->webNavigator = $webNavigator ?: new WebNavigator();
        $this->cloudFetcher = $cloudFetcher ?: new CloudFetcher();
        $this->projectAuditor = $projectAuditor ?: new ProjectAuditor();

        try {
            $this->vectorSearch = $vectorSearch ?: new VectorSearch();
            $this->vectorInsert = $vectorInsert ?: new VectorInsert();
        } catch (Exception $e) {
            $this->vectorSearch = null;
            $this->vectorInsert = null;
        }
    }

    /**
     * Authenticate request if CLIENT_ACCESS_TOKEN is configured
     */
    public function checkClientAuth(): void {
        $expectedToken = CLIENT_ACCESS_TOKEN;
        if (empty($expectedToken)) {
            return;
        }

        $providedToken = null;

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $providedToken = $matches[1];
        }

        if (!$providedToken && !empty($_SERVER['HTTP_X_API_KEY'])) {
            $providedToken = $_SERVER['HTTP_X_API_KEY'];
        }

        if (!$providedToken && !empty($_GET['token'])) {
            $providedToken = $_GET['token'];
        }

        if (!$providedToken || !hash_equals($expectedToken, $providedToken)) {
            send_json([
                'success' => false,
                'error' => 'Unauthorized. Invalid or missing client access token.'
            ], 401);
        }
    }

    /**
     * Parse raw request input into a normalized array
     */
    public function parseRequestInput(): array {
        $rawBody = file_get_contents('php://input');
        if (empty($rawBody)) {
            if (!empty($_POST)) {
                return $_POST;
            }
            throw new Exception("Empty request body. Please send JSON payload with 'message' or 'messages'.");
        }

        $input = json_decode($rawBody, true);
        if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON in request body: " . json_last_error_msg());
        }

        return (array) $input;
    }

    /**
     * Resolve session ID from input, query, or headers
     */
    public function resolveSessionId(array $input): string {
        if (!empty($input['session_id'])) {
            return (string) $input['session_id'];
        }
        if (!empty($_GET['session_id'])) {
            return (string) $_GET['session_id'];
        }
        if (!empty($_SERVER['HTTP_X_SESSION_ID'])) {
            return (string) $_SERVER['HTTP_X_SESSION_ID'];
        }
        return SessionManager::generateId();
    }

    /**
     * Normalize incoming payload into OpenAI-compatible messages array with session history & Vector DB memory
     */
    public function buildMessages(array $input, string $sessionId, ?string &$currentUserText = null, ?string &$vectorContext = null): array {
        $messages = [];
        $systemPrompt = $input['system'] ?? DEFAULT_SYSTEM_PROMPT;

        if (!empty($input['clear_history'])) {
            $this->sessionManager->clear($sessionId);
        }

        // Format 1: Direct 'messages' array provided (Manual control)
        if (!empty($input['messages']) && is_array($input['messages'])) {
            $hasSystem = false;
            foreach ($input['messages'] as $msg) {
                if (isset($msg['role']) && $msg['role'] === 'system') {
                    $hasSystem = true;
                    break;
                }
            }

            if (!$hasSystem && !empty($systemPrompt)) {
                $messages[] = [
                    'role' => 'system',
                    'content' => $systemPrompt
                ];
            }

            foreach ($input['messages'] as $msg) {
                if (isset($msg['role'], $msg['content'])) {
                    $messages[] = [
                        'role' => (string) $msg['role'],
                        'content' => (string) $msg['content']
                    ];
                    if ($msg['role'] === 'user') {
                        $currentUserText = (string) $msg['content'];
                    }
                }
            }

            return $messages;
        }

        // Format 2: Single 'message' string with automated session history & Vector Context
        if (isset($input['message']) && trim($input['message']) !== '') {
            $currentUserText = trim($input['message']);

            // 1. Semantic Memory Retrieval from Zilliz Cloud Vector DB
            $vectorContext = '';
            $useVector = ($input['use_vector'] ?? true);

            // Skip vector search for short trivial greetings (saves 1.25s network trip to Europe)
            $trimmedLower = mb_strtolower(trim($currentUserText), 'UTF-8');
            $commonGreetings = ['hi', 'hello', 'hey', 'namaste', 'namaskar', 'kaise ho', 'kya haal hai', 'kya hal hai', 'good morning', 'good evening', 'good afternoon', 'ok', 'okay', 'theek hai', 'thik hai'];
            $isTrivial = in_array($trimmedLower, $commonGreetings) || (mb_strlen($trimmedLower, 'UTF-8') <= 4);

            // Extract caller domain from payload or HTTP headers
            $targetDomain = null;
            if (!empty($input['current_url'])) {
                $targetDomain = parse_url($input['current_url'], PHP_URL_HOST);
            } elseif (!empty($_SERVER['HTTP_REFERER'])) {
                $targetDomain = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
            } elseif (!empty($_SERVER['HTTP_ORIGIN'])) {
                $targetDomain = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
            }

            if ($useVector && !$isTrivial && $this->vectorSearch !== null) {
                try {
                    $vectorContext = $this->vectorSearch->getRelevantContext($currentUserText, $sessionId, $targetDomain);
                } catch (Exception $e) {
                    $vectorContext = '';
                }
            }

            // 2. Build System Persona with Vector Context & Active Webpage Information
            $fullSystemPrompt = $systemPrompt;
            if (!empty($input['current_url'])) {
                $pageTitle = $input['page_title'] ?? 'Current Webpage';
                $fullSystemPrompt .= "\n\n[Active Webpage Context: User is browsing '{$pageTitle}' at {$input['current_url']}]";
            }

            // Lead contact detection notice
            $contactInfo = LeadManager::extractContactInfo($currentUserText);
            if ($contactInfo['has_contact']) {
                $contactStr = trim(($contactInfo['name'] ? "Name: {$contactInfo['name']} " : "") . ($contactInfo['phone'] ? "Phone: {$contactInfo['phone']} " : "") . ($contactInfo['email'] ? "Email: {$contactInfo['email']}" : ""));
                $fullSystemPrompt .= "\n\n[SYSTEM NOTIFICATION: The user just provided their contact details ({$contactStr}). Thank them warmly in English/Hindi and confirm that our sales/support team will reach out to them soon.]";
            }

            if (!empty($vectorContext)) {
                $fullSystemPrompt .= "\n\n[" . $vectorContext . "]\n(Use the above vector knowledge and memories to answer accurately if relevant.)";
            }

            // Live link detection in user message: if user provided a URL, crawl it in real-time
            if (preg_match('/https?:\/\/[^\s]+/', $currentUserText, $urlMatches)) {
                $foundUrl = rtrim($urlMatches[0], '.,;:!?)');
                try {
                    $crawled = $this->cloudFetcher->fetchAndIngestWebpage($foundUrl);
                    if (!empty($crawled['success'])) {
                        $fullSystemPrompt .= "\n\n[REAL-TIME LIVE CRAWLED URL KNOWLEDGE from {$foundUrl}]:\nTitle: {$crawled['title']}\n" . $crawled['summary'] . "\n(Use this live extracted page knowledge to answer user's question directly and accurately.)";
                    }
                } catch (Throwable $t) {}
            }

            // Command capabilities instructions
            $fullSystemPrompt .= "\n\n[SPECIAL CAPABILITIES & BROWSER INTERACTION COMMANDS]:
1. CLICK & INTERACT ON SITE (User permission granted to click anywhere on the site when requested):
When user asks to click on something on the webpage, open cart, open a product, filter category, or perform an action on the site:
- To click any button, link, or element by CSS selector or text:
- To filter a category or click category navbar (e.g. Footwear, Electronics, Fashion, Mobiles):
  Always use: [[FILTER: category=\"Footwear\"]] or [[FILTER: category=\"Electronics\"]]
- To open/view a specific product popup:
  [[OPEN_PRODUCT: id=\"prod_1\"]]
- To open the shopping cart:
  [[OPEN_CART]]
- To search a query on the page:
  [[SEARCH: query=\"iPhone 15\"]]
- To click any other custom button or link by CSS selector or text:
  [[CLICK: selector=\".fk-cart-btn\" text=\"Cart\"]]

2. DIRECT SMART ACTION BUTTONS & LINKS:
Whenever you mention a page, product, section, or external link:
- You can use standard Markdown links: [Click Here to View](https://example.com) or [Open Deals](#all)
- Or smart action buttons:
  [[LINK: url=\"https://example.com/checkout\" label=\"Go to Checkout\"]] or [[LINK: url=\"#productGrid\" label=\"View Products\"]]

3. GENERATE DEDICATED WEBPAGE:
If the user asks for a detailed comparison (e.g. comparing two phones/laptops), specs report, or custom page:
[[CREATE_PAGE: title=\"Your Page Title\" category=\"comparison|product_spec|report\"]
Markdown content with tables and specs here
[/CREATE_PAGE]]

4. Project Structure & Local File Inspection (Read-Only & Secure):
- To scan files/folders: [[SCAN_PROJECT: path=\"\"]] (or path=\"public\")
- To read a safe file: [[READ_FILE: path=\"public/index.html\"]]

Always answer warmly and helpfully. When the user asks you to click on something or go somewhere on the site, execute the action command with a clear helpful message.";

            if (!empty($fullSystemPrompt)) {
                $messages[] = [
                    'role' => 'system',
                    'content' => $fullSystemPrompt
                ];
            }

            // 3. Add history
            $history = [];
            if (!empty($input['history']) && is_array($input['history'])) {
                $history = $input['history'];
            } else {
                $history = $this->sessionManager->getHistory($sessionId);
            }

            foreach ($history as $item) {
                if (isset($item['role'], $item['content'])) {
                    $messages[] = [
                        'role' => (string) $item['role'],
                        'content' => (string) $item['content']
                    ];
                }
            }

            // 4. Add current user message
            $messages[] = [
                'role' => 'user',
                'content' => $currentUserText
            ];

            return $messages;
        }

        throw new Exception("Missing 'message' string or 'messages' array in request payload.");
    }

    /**
     * Process chat request and return standardized response
     */
    public function handleChat(array $input): array {
        $sessionId = $this->resolveSessionId($input);
        $userText = '';
        $vectorContext = '';
        $messages = $this->buildMessages($input, $sessionId, $userText, $vectorContext);

        $options = [];
        if (!empty($input['model'])) {
            $options['model'] = (string) $input['model'];
        }
        if (isset($input['temperature'])) {
            $options['temperature'] = (float) $input['temperature'];
        }
        if (isset($input['max_tokens'])) {
            $options['max_tokens'] = (int) $input['max_tokens'];
        }

        $rawResponse = $this->client->chatCompletion($messages, $options);
        $reply = KiraClient::extractReplyText($rawResponse);

        // Process any embedded action commands ([[CREATE_PAGE]], [[NAVIGATE]], etc.)
        $commandResult = $this->executeResponseCommands($reply);
        $reply = $commandResult['reply'];
        $actions = $commandResult['actions'];

        // Site activity log
        $this->siteLogger->log('chat', [
            'session_id' => $sessionId,
            'current_url' => $input['current_url'] ?? null,
            'page_title' => $input['page_title'] ?? null,
            'user_preview' => mb_substr($userText, 0, 80)
        ]);

        // Background storage health check (1-in-50 request lottery)
        if (mt_rand(1, 50) === 1) {
            try {
                $this->storageManager->checkAndClean();
            } catch (Throwable $t) {}
        }

        // Auto Lead Capture
        $contactInfo = LeadManager::extractContactInfo($userText);
        if ($contactInfo['has_contact']) {
            try {
                $this->leadManager->saveLead([
                    'name' => $contactInfo['name'],
                    'phone' => $contactInfo['phone'],
                    'email' => $contactInfo['email'],
                    'intent' => $contactInfo['intent'],
                    'notes' => $userText,
                    'source_url' => $input['current_url'] ?? '',
                    'session_id' => $sessionId
                ]);
            } catch (Throwable $e) {}
        }

        // 1. Persist to Session History
        if (!empty($userText) && !empty($reply)) {
            $this->sessionManager->appendMessages($sessionId, [
                ['role' => 'user', 'content' => $userText],
                ['role' => 'assistant', 'content' => $reply]
            ]);

            // 2. Persist to Zilliz Cloud Vector Database for Long-Term Semantic Memory (only for meaningful exchanges)
            $trimmedUser = mb_strtolower(trim($userText), 'UTF-8');
            $isTrivial = in_array($trimmedUser, ['hi', 'hello', 'hey', 'namaste', 'namaskar', 'ok', 'okay']) || (mb_strlen($trimmedUser, 'UTF-8') <= 4);

            if ($this->vectorInsert !== null && ($input['save_vector'] ?? true) && !$isTrivial) {
                try {
                    $this->vectorInsert->insertChatTurn($sessionId, $userText, $reply);
                } catch (Exception $e) {
                    // Non-blocking
                }
            }
        }

        $output = [
            'success' => true,
            'session_id' => $sessionId,
            'reply' => $reply,
            'actions' => $actions,
            'model' => $rawResponse['model'] ?? ($options['model'] ?? KIRA_DEFAULT_MODEL),
            'vector_memory_used' => !empty($vectorContext),
            'usage' => $rawResponse['usage'] ?? null,
            'created' => $rawResponse['created'] ?? time()
        ];

        if (!empty($input['include_raw']) || APP_DEBUG) {
            $output['raw'] = $rawResponse;
        }

        return $output;
    }

    /**
     * Process real-time streaming chat request with zero latency & Vector DB memory
     */
    public function handleStreamChat(array $input): void {
        $sessionId = $this->resolveSessionId($input);
        $userText = '';
        $vectorContext = '';
        $messages = $this->buildMessages($input, $sessionId, $userText, $vectorContext);

        $options = [];
        if (!empty($input['model'])) {
            $options['model'] = (string) $input['model'];
        }
        if (isset($input['temperature'])) {
            $options['temperature'] = (float) $input['temperature'];
        }
        if (isset($input['max_tokens'])) {
            $options['max_tokens'] = (int) $input['max_tokens'];
        }

        $onComplete = function(string $fullReply) use ($sessionId, $userText, $input) {
            // Log site activity
            $this->siteLogger->log('stream_chat', [
                'session_id' => $sessionId,
                'current_url' => $input['current_url'] ?? null,
                'page_title' => $input['page_title'] ?? null,
                'user_preview' => mb_substr($userText, 0, 80)
            ]);

            // Background storage health check (1-in-50 request lottery)
            if (mt_rand(1, 50) === 1) {
                try {
                    $this->storageManager->checkAndClean();
                } catch (Throwable $t) {}
            }

            // Auto Lead Capture
            $contactInfo = LeadManager::extractContactInfo($userText);
            if ($contactInfo['has_contact']) {
                try {
                    $this->leadManager->saveLead([
                        'name' => $contactInfo['name'],
                        'phone' => $contactInfo['phone'],
                        'email' => $contactInfo['email'],
                        'intent' => $contactInfo['intent'],
                        'notes' => $userText,
                        'source_url' => $input['current_url'] ?? '',
                        'session_id' => $sessionId
                    ]);
                } catch (Throwable $e) {}
            }

            if (!empty($userText) && !empty($fullReply)) {
                // 1. Session history
                $this->sessionManager->appendMessages($sessionId, [
                    ['role' => 'user', 'content' => $userText],
                    ['role' => 'assistant', 'content' => $fullReply]
                ]);

                // 2. Zilliz Vector DB (only for meaningful exchanges)
                $trimmedUser = mb_strtolower(trim($userText), 'UTF-8');
                $isTrivial = in_array($trimmedUser, ['hi', 'hello', 'hey', 'namaste', 'namaskar', 'ok', 'okay']) || (mb_strlen($trimmedUser, 'UTF-8') <= 4);

                if ($this->vectorInsert !== null && ($input['save_vector'] ?? true) && !$isTrivial) {
                    try {
                        $this->vectorInsert->insertChatTurn($sessionId, $userText, $fullReply);
                    } catch (Exception $e) {}
                }
            }
        };

        if (!headers_sent()) {
            header("X-Session-ID: {$sessionId}");
        }

        $this->client->streamChatCompletion($messages, $options, $onComplete);
    }

    /**
     * Parse and execute embedded command tags like [[CREATE_PAGE]] and [[NAVIGATE]]
     */
    public function executeResponseCommands(string $reply): array {
        $actions = [];
        $cleanReply = $reply;

        // 1. Parse [[CREATE_PAGE: title="..." category="..."]] content [[/CREATE_PAGE]] (or single bracket [CREATE_PAGE:...])
        $pagePattern = '/\[{1,2}CREATE_PAGE:\s*title="([^"]+)"(?:\s*category="([^"]+)")?\]{1,2}(.*?)\[{1,2}\/CREATE_PAGE\]{1,2}/s';
        if (preg_match_all($pagePattern, $cleanReply, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullTag = $match[0];
                $pageTitle = trim($match[1]);
                $pageCategory = !empty($match[2]) ? trim($match[2]) : 'general';
                $pageContent = trim($match[3]);

                try {
                    $pageInfo = $this->pageGenerator->generatePage($pageTitle, $pageContent, $pageCategory);
                    $actions[] = [
                        'type' => 'open_page',
                        'title' => $pageInfo['title'],
                        'url' => $pageInfo['web_url'],
                        'slug' => $pageInfo['slug']
                    ];

                    $buttonHtml = "\n\n<div class=\"kira-page-card\">"
                        . "<strong>📄 Generated Page:</strong> " . htmlspecialchars($pageInfo['title']) . "<br>"
                        . "<a href=\"{$pageInfo['web_url']}\" target=\"_blank\" class=\"kira-action-link\">Open Full Page &rarr;</a>"
                        . "</div>\n";

                    $cleanReply = str_replace($fullTag, $buttonHtml, $cleanReply);
                } catch (Throwable $t) {
                    $cleanReply = str_replace($fullTag, "\n[Page could not be generated: " . $t->getMessage() . "]\n", $cleanReply);
                }
            }
        }

        // 2. Parse [[NAVIGATE: url="..." label="..."]] or [[LINK: url="..." label="..."]] or {{LINK:...}}
        $linkPattern = '/(?:\[{1,2}|\{{1,2})(?:NAVIGATE|LINK):\s*url="([^"]+)"(?:\s*label="([^"]+)")?(?:\]{1,2}|\}{1,2})/i';
        if (preg_match_all($linkPattern, $cleanReply, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullTag = $match[0];
                $navUrl = trim($match[1]);
                $navLabel = !empty($match[2]) ? trim($match[2]) : 'Visit Link';

                $isExternal = preg_match('/^https?:\/\//i', $navUrl);
                $targetAttr = $isExternal ? ' target="_blank"' : '';

                $actions[] = [
                    'type' => 'link',
                    'url' => $navUrl,
                    'label' => $navLabel,
                    'external' => $isExternal
                ];

                $navHtml = "\n<a href=\"{$navUrl}\"{$targetAttr} class=\"kira-action-link kira-nav-link\">🔗 " . htmlspecialchars($navLabel) . " &rarr;</a>\n";
                $cleanReply = str_replace($fullTag, $navHtml, $cleanReply);
            }
        }

        // 3. Parse [[FETCH_API: url="..." method="..."]]
        $apiPattern = '/\[{1,2}FETCH_API:\s*url="([^"]+)"(?:\s*method="([^"]+)")?\]{1,2}/i';
        if (preg_match_all($apiPattern, $cleanReply, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullTag = $match[0];
                $apiUrl = trim($match[1]);
                $apiMethod = !empty($match[2]) ? strtoupper(trim($match[2])) : 'GET';

                try {
                    $apiResult = $this->cloudFetcher->fetchApi($apiUrl, $apiMethod);
                    if (!empty($apiResult['success'])) {
                        $preview = substr(json_encode($apiResult['data'], JSON_UNESCAPED_UNICODE), 0, 300);
                        $cleanReply = str_replace($fullTag, "\n[API Data: {$preview}...]\n", $cleanReply);
                    } else {
                        $cleanReply = str_replace($fullTag, "", $cleanReply);
                    }
                } catch (Throwable $t) {
                    $cleanReply = str_replace($fullTag, "", $cleanReply);
                }
            }
        }

        // 4. Parse [[SCAN_PROJECT: path="..."]]
        $scanPattern = '/\[{1,2}SCAN_PROJECT:\s*path="([^"]*)"\]{1,2}/i';
        if (preg_match_all($scanPattern, $cleanReply, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullTag = $match[0];
                $scanSubDir = trim($match[1]);
                try {
                    $scanResult = $this->projectAuditor->scanStructure($scanSubDir);
                    if (!empty($scanResult['success'])) {
                        $treeStr = "📂 Project Files (" . ($scanResult['scanned_path'] ?: 'root') . "):\n";
                        foreach (array_slice($scanResult['tree'], 0, 15) as $item) {
                            $icon = $item['type'] === 'dir' ? '📁' : '📄';
                            $treeStr .= "  {$icon} {$item['path']}\n";
                        }
                        $cleanReply = str_replace($fullTag, "\n```\n" . trim($treeStr) . "\n```\n", $cleanReply);
                    } else {
                        $cleanReply = str_replace($fullTag, "", $cleanReply);
                    }
                } catch (Throwable $t) {
                    $cleanReply = str_replace($fullTag, "", $cleanReply);
                }
            }
        }

        // 5. Parse [[READ_FILE: path="..."]]
        $readFilePattern = '/\[{1,2}READ_FILE:\s*path="([^"]+)"\]{1,2}/i';
        if (preg_match_all($readFilePattern, $cleanReply, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fullTag = $match[0];
                $targetFile = trim($match[1]);
                try {
                    $fileResult = $this->projectAuditor->readFile($targetFile);
                    if (!empty($fileResult['success'])) {
                        $snippet = mb_substr($fileResult['content'], 0, 500);
                        $cleanReply = str_replace($fullTag, "\n```\n// File: {$targetFile}\n" . $snippet . "\n```\n", $cleanReply);
                    } else {
                        $cleanReply = str_replace($fullTag, "\n[Inspection notice: " . ($fileResult['error'] ?? 'Cannot read file') . "]\n", $cleanReply);
                    }
                } catch (Throwable $t) {
                    $cleanReply = str_replace($fullTag, "", $cleanReply);
                }
            }
        }

        return [
            'reply' => trim($cleanReply),
            'actions' => $actions
        ];
    }
}

