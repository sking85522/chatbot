<?php
/**
 * SiteLogger - Tracks and logs incoming API requests and origin websites
 * directly into Zilliz Cloud Vector DB (Zero local disk files).
 */

require_once dirname(__DIR__) . '/vector/VectorInsert.php';
require_once dirname(__DIR__) . '/vector/ZillizClient.php';

class SiteLogger {
    private ?VectorInsert $vectorInsert;
    private ?ZillizClient $client;

    public function __construct(?VectorInsert $vectorInsert = null, ?ZillizClient $client = null) {
        try {
            $this->vectorInsert = $vectorInsert ?: new VectorInsert();
            $this->client = $client ?: new ZillizClient();
        } catch (Throwable $t) {
            $this->vectorInsert = null;
            $this->client = null;
        }
    }

    /**
     * Log an API event from a website or client directly into Zilliz Cloud Vector DB
     */
    public function log(string $action, array $extra = []): void {
        if ($this->vectorInsert === null) {
            return;
        }

        try {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            $pageUrl = $extra['current_url'] ?? $referer ?: $origin ?: 'Direct API / Remote Client';
            
            // Extract domain
            $domain = 'direct_api';
            if (!empty($pageUrl) && $pageUrl !== 'Direct API / Remote Client') {
                $parsedHost = parse_url($pageUrl, PHP_URL_HOST);
                if ($parsedHost) {
                    $domain = $parsedHost;
                }
            } elseif (!empty($origin)) {
                $parsedHost = parse_url($origin, PHP_URL_HOST);
                if ($parsedHost) {
                    $domain = $parsedHost;
                }
            }

            $rawIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $clientIpHash = substr(hash('sha256', $rawIp), 0, 12);

            $logId = 'log_' . bin2hex(random_bytes(6)) . '_' . time();
            $logSummary = "Site Log [{$domain}] - Action: {$action} on URL: {$pageUrl} by session: " . ($extra['session_id'] ?? 'none');

            $this->vectorInsert->insertText($logSummary, [
                'type' => 'site_log',
                'domain' => $domain,
                'url' => $pageUrl,
                'action' => $action,
                'session_id' => $extra['session_id'] ?? '',
                'ip_hash' => $clientIpHash
            ], $logId);

        } catch (Throwable $e) {
            // Non-blocking
        }
    }

    /**
     * Get recent logs directly from Zilliz Cloud DB
     */
    public function getRecentLogs(int $limit = 50): array {
        if ($this->client === null) return [];
        try {
            return $this->client->query('type == "site_log"', ['id', 'text', 'domain', 'url', 'action', 'session_id', 'created_at'], $limit);
        } catch (Throwable $t) {
            return [];
        }
    }

    /**
     * Get summary of unique domains using the API
     */
    public function getDomainSummary(): array {
        $logs = $this->getRecentLogs(100);
        $summary = [];
        foreach ($logs as $log) {
            $domain = $log['domain'] ?? 'unknown';
            if (!isset($summary[$domain])) {
                $summary[$domain] = ['total_events' => 0, 'last_seen' => 0];
            }
            $summary[$domain]['total_events']++;
            $summary[$domain]['last_seen'] = max($summary[$domain]['last_seen'], (int) ($log['created_at'] ?? 0));
        }
        return $summary;
    }
}
