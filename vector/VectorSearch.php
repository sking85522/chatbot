<?php
/**
 * VectorSearch - Semantic similarity search and memory retrieval from Zilliz Cloud.
 */

require_once __DIR__ . '/ZillizClient.php';
require_once __DIR__ . '/EmbeddingService.php';

class VectorSearch {
    private ZillizClient $client;
    private EmbeddingService $embedder;

    public function __construct(?ZillizClient $client = null, ?EmbeddingService $embedder = null) {
        $this->client = $client ?: new ZillizClient();
        $this->embedder = $embedder ?: new EmbeddingService();
    }

    /**
     * Search most similar vectors in Zilliz collection
     *
     * @param string $query Query text
     * @param int $topK Number of matches to return
     * @param string|null $filter Milvus boolean filter expression (e.g. 'session_id == "user_123"')
     * @param string|null $collectionName
     * @return array Array of matched entities with distance scores
     */
    public function search(string $query, int $topK = 5, ?string $filter = null, ?string $collectionName = null): array {
        $collection = $collectionName ?: $this->client->getDefaultCollection();
        $queryVector = $this->embedder->embed($query);

        $payload = [
            'collectionName' => $collection,
            'data' => [$queryVector],
            'limit' => $topK,
            'outputFields' => ['id', 'text', 'session_id', 'type', 'url', 'title', 'domain', 'created_at', 'user_message', 'assistant_reply']
        ];

        if (!empty($filter)) {
            $payload['filter'] = $filter;
        }

        $res = $this->client->request('entities/search', $payload);
        return $res['data'] ?? [];
    }

    /**
     * Search memory or knowledge base and format as context block for Kira AI
     *
     * @param string $query
     * @param string|null $sessionId Optional session ID
     * @param string|null $domain Optional website domain to filter webpage knowledge
     * @param int $topK
     * @param float $minSimilarity Minimum cosine similarity threshold
     * @return string Formatted context string, or empty if no relevant memory found
     */
    public function getRelevantContext(string $query, ?string $sessionId = null, ?string $domain = null, int $topK = 3, float $minSimilarity = 0.20): string {
        $filter = null;
        if (!empty($domain)) {
            $safeDomain = addslashes($domain);
            if (!empty($sessionId)) {
                $safeSession = addslashes($sessionId);
                $filter = "domain == '{$safeDomain}' or session_id == '{$safeSession}'";
            } else {
                $filter = "domain == '{$safeDomain}'";
            }
        } elseif (!empty($sessionId)) {
            $safeSession = addslashes($sessionId);
            $filter = "session_id == '{$safeSession}' or type == 'webpage_knowledge'";
        }

        try {
            $matches = $this->search($query, $topK, $filter);
            if (empty($matches) && $filter !== null) {
                // If filtered search returned nothing, fall back to global search
                $matches = $this->search($query, $topK);
            }

            if (empty($matches)) {
                return '';
            }

            $contextLines = [];
            foreach ($matches as $item) {
                $score = $item['distance'] ?? 0;
                $text = $item['text'] ?? '';
                if ($score >= $minSimilarity && !empty($text)) {
                    $contextLines[] = "- " . trim($text);
                }
            }

            if (empty($contextLines)) {
                return '';
            }

            return "Relevant memories & knowledge from Vector DB:\n" . implode("\n", $contextLines);
        } catch (Exception $e) {
            // Silently return empty context if vector search fails so chat never breaks
            return '';
        }
    }
}

