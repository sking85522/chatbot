<?php
/**
 * CollectionManager - Manages Zilliz Cloud collections, entity deletion, and schema inspection.
 */

require_once __DIR__ . '/ZillizClient.php';

class CollectionManager {
    private ZillizClient $client;

    public function __construct(?ZillizClient $client = null) {
        $this->client = $client ?: new ZillizClient();
    }

    /**
     * List all collections in the cluster
     */
    public function listCollections(): array {
        $res = $this->client->request('collections/list', []);
        return $res['data'] ?? [];
    }

    /**
     * Describe schema and details of a collection
     */
    public function describeCollection(?string $collectionName = null): array {
        $name = $collectionName ?: $this->client->getDefaultCollection();
        $res = $this->client->request('collections/describe', [
            'collectionName' => $name
        ]);
        return $res['data'] ?? [];
    }

    /**
     * Delete an entity by ID from collection
     */
    public function deleteEntity(string $id, ?string $collectionName = null): array {
        $name = $collectionName ?: $this->client->getDefaultCollection();
        return $this->client->request('entities/delete', [
            'collectionName' => $name,
            'filter' => "id in [\"{$id}\"]"
        ]);
    }

    /**
     * Query entities using Milvus boolean filter
     */
    public function query(string $filter = "id != ''", array $outputFields = ['id', 'text', 'created_at'], int $limit = 20, ?string $collectionName = null): array {
        $name = $collectionName ?: $this->client->getDefaultCollection();
        $res = $this->client->request('entities/query', [
            'collectionName' => $name,
            'filter' => $filter,
            'outputFields' => $outputFields,
            'limit' => $limit
        ]);
        return $res['data'] ?? [];
    }
}
