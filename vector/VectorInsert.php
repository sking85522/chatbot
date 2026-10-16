<?php
/**
 * VectorInsert - Handles inserting and upserting data/embeddings into Zilliz Cloud.
 */

require_once __DIR__ . '/ZillizClient.php';
require_once __DIR__ . '/EmbeddingService.php';

class VectorInsert {
    private ZillizClient $client;
    private EmbeddingService $embedder;

    public function __construct(?ZillizClient $client = null, ?EmbeddingService $embedder = null) {
        $this->client = $client ?: new ZillizClient();
        $this->embedder = $embedder ?: new EmbeddingService();
    }

    /**
     * Insert a single text document/memory into Zilliz
     *
     * @param string $text Raw text content to be embedded and stored
     * @param array $metadata Additional metadata fields (session_id, role, category, etc.)
     * @param string|null $id Optional custom ID (auto-generated if null)
     * @param string|null $collectionName Optional collection name override
     * @return array Zilliz API response
     */
    public function insertText(string $text, array $metadata = [], ?string $id = null, ?string $collectionName = null): array {
        $collection = $collectionName ?: $this->client->getDefaultCollection();
        $docId = $id ?: 'vec_' . bin2hex(random_bytes(8));
        $vector = $this->embedder->embed($text);

        $record = array_merge([
            'id' => $docId,
            'vector' => $vector,
            'text' => $text,
            'created_at' => time()
        ], $metadata);

        return $this->insertBatch([$record], $collection);
    }

    /**
     * Insert multiple raw records directly
     *
     * @param array $records Array of entity associative arrays
     * @param string|null $collectionName
     * @return array
     */
    public function insertBatch(array $records, ?string $collectionName = null): array {
        $collection = $collectionName ?: $this->client->getDefaultCollection();

        $payload = [
            'collectionName' => $collection,
            'data' => $records
        ];

        return $this->client->request('entities/insert', $payload);
    }

    /**
     * Store conversation exchange into Zilliz Cloud for semantic long-term memory
     */
    public function insertChatTurn(string $sessionId, string $userMessage, string $botReply, ?string $collectionName = null): array {
        $turnId = 'chat_' . bin2hex(random_bytes(6));
        $combinedContext = "User: {$userMessage}\nAssistant: {$botReply}";

        return $this->insertText($combinedContext, [
            'type' => 'chat_memory',
            'session_id' => $sessionId,
            'user_message' => $userMessage,
            'assistant_reply' => $botReply
        ], $turnId, $collectionName);
    }
}

