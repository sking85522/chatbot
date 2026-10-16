<?php
/**
 * SessionManager - 100% Cloud-Native Conversation History backed by Zilliz Cloud Vector DB.
 *
 * ZERO LOCAL FILES, ZERO COOKIES, ZERO DISK STORAGE:
 * - History is fetched and queried directly from Zilliz Cloud entities (type == 'chat_turn' or 'chat_memory').
 * - Append operations are pushed directly as vector embeddings and metadata in Zilliz.
 */

require_once dirname(__DIR__) . '/vector/ZillizClient.php';
require_once dirname(__DIR__) . '/vector/VectorInsert.php';

class SessionManager {
    private ZillizClient $client;
    private int $maxHistory;

    public function __construct(?ZillizClient $client = null, int $maxHistory = 10) {
        $this->client = $client ?: new ZillizClient();
        $this->maxHistory = $maxHistory;
    }

    /**
     * Generate a unique session ID
     */
    public static function generateId(): string {
        return 'sess_' . bin2hex(random_bytes(8));
    }

    /**
     * Load existing history for a session from Zilliz Cloud Vector DB
     */
    public function getHistory(string $sessionId): array {
        if (empty($sessionId)) {
            return [];
        }

        try {
            $safeSession = addslashes($sessionId);
            $filter = "session_id == '{$safeSession}' and (type == 'chat_turn' or type == 'chat_memory')";
            
            $results = $this->client->query($filter, ['user_message', 'assistant_reply', 'created_at'], $this->maxHistory);
            if (empty($results)) {
                return [];
            }

            // Sort ascending by created_at
            usort($results, function($a, $b) {
                return ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0);
            });

            $history = [];
            foreach ($results as $item) {
                if (!empty($item['user_message'])) {
                    $history[] = ['role' => 'user', 'content' => $item['user_message']];
                }
                if (!empty($item['assistant_reply'])) {
                    $history[] = ['role' => 'assistant', 'content' => $item['assistant_reply']];
                }
            }

            return $history;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * In the cloud-native model, persistence is handled by VectorInsert::insertChatTurn directly to Zilliz.
     * This method acts as a safe passthrough.
     */
    public function appendMessages(string $sessionId, array $newMessages): void {
        // No local disk operations.
    }

    /**
     * Clear history for a given session from Zilliz Cloud
     */
    public function clear(string $sessionId): bool {
        if (empty($sessionId)) {
            return true;
        }

        try {
            $safeSession = addslashes($sessionId);
            $this->client->delete("session_id == '{$safeSession}' and (type == 'chat_turn' or type == 'chat_memory')");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
