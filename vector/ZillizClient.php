<?php
/**
 * ZillizClient - Low-latency REST client for Zilliz Cloud (Milvus) v2 Vector DB API.
 */

class ZillizClient {
    private string $endpoint;
    private string $apiKey;
    private string $defaultCollection;

    public function __construct(?string $endpoint = null, ?string $apiKey = null, ?string $defaultCollection = null) {
        $this->endpoint = rtrim($endpoint ?: ZILLIZ_ENDPOINT, '/');
        $this->apiKey = $apiKey ?: ZILLIZ_API_KEY;
        $this->defaultCollection = $defaultCollection ?: ZILLIZ_COLLECTION;

        if (empty($this->endpoint) || empty($this->apiKey)) {
            throw new Exception("Zilliz Cloud configuration missing. Set ZILLIZ_ENDPOINT and ZILLIZ_API_KEY in .env.");
        }
    }

    /**
     * Send HTTP POST request to Zilliz Cloud v2 API
     */
    public function request(string $path, array $data = []): array {
        $url = $this->endpoint . '/v2/vectordb/' . ltrim($path, '/');
        $jsonPayload = empty($data) ? '{}' : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === '[]') {
            $jsonPayload = '{}';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'User-Agent: UniversalChatbotAPI-Vector/1.0',
                'Connection: keep-alive'
            ],
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_TCP_NODELAY => 1,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception("cURL Error connecting to Zilliz Cloud: " . $curlError);
        }

        if (empty($response)) {
            throw new Exception("Empty response from Zilliz Cloud (HTTP {$httpCode}).");
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON returned by Zilliz Cloud: " . substr($response, 0, 300));
        }

        if (isset($decoded['code']) && $decoded['code'] !== 0) {
            $msg = $decoded['message'] ?? 'Unknown error';
            throw new Exception("Zilliz Cloud API Error (code {$decoded['code']}): " . $msg);
        }

        return $decoded;
    }

    /**
     * Query entities using Milvus boolean expression
     */
    public function query(string $filter, array $outputFields = ['*'], int $limit = 50, ?string $collectionName = null): array {
        $payload = [
            'collectionName' => $collectionName ?: $this->defaultCollection,
            'filter' => $filter,
            'limit' => $limit,
            'outputFields' => $outputFields
        ];
        $res = $this->request('entities/query', $payload);
        return $res['data'] ?? [];
    }

    /**
     * Delete entities matching an expression
     */
    public function delete(string $filter, ?string $collectionName = null): array {
        $payload = [
            'collectionName' => $collectionName ?: $this->defaultCollection,
            'filter' => $filter
        ];
        return $this->request('entities/delete', $payload);
    }

    public function getDefaultCollection(): string {
        return $this->defaultCollection;
    }
}
