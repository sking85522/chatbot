<?php
/**
 * EmbeddingService - Converts text into 384-dimensional normalized float vectors for Zilliz Cloud.
 * Supports built-in zero-dependency feature projection and optional neural embedding APIs.
 */

class EmbeddingService {
    private int $dimension;

    public function __construct(int $dimension = 384) {
        $this->dimension = $dimension ?: (defined('ZILLIZ_DIMENSION') ? ZILLIZ_DIMENSION : 384);
    }

    /**
     * Convert text to 384-dimensional unit vector
     *
     * @param string $text
     * @return float[] Array of 384 floats
     */
    public function embed(string $text): array {
        $cleanText = trim($text);
        if ($cleanText === '') {
            return array_fill(0, $this->dimension, 0.0);
        }

        // 1. If an external neural embedding API is configured in .env, use it
        $externalApiKey = env('EMBEDDING_API_KEY', '');
        $externalApiUrl = env('EMBEDDING_API_URL', '');
        if (!empty($externalApiKey) && !empty($externalApiUrl)) {
            $neuralVector = $this->callExternalEmbeddingApi($cleanText, $externalApiUrl, $externalApiKey);
            if (!empty($neuralVector) && count($neuralVector) === $this->dimension) {
                return $neuralVector;
            }
        }

        // 2. High-performance, zero-dependency deterministic semantic vector generator
        return $this->generateDeterministicVector($cleanText);
    }

    /**
     * Convert array of texts to array of vectors
     *
     * @param string[] $texts
     * @return array[]
     */
    public function embedBatch(array $texts): array {
        $vectors = [];
        foreach ($texts as $text) {
            $vectors[] = $this->embed($text);
        }
        return $vectors;
    }

    /**
     * Standalone pure-PHP semantic projection into 384-dimensional unit hypersphere
     */
    private function generateDeterministicVector(string $text): array {
        $vector = array_fill(0, $this->dimension, 0.0);

        // Normalize text (lowercase, unicode preserved for Hindi/English)
        $normalized = mb_strtolower($text, 'UTF-8');

        // Extract words (supports Latin & Devanagari Hindi characters)
        preg_match_all('/[\p{L}\p{N}]+/u', $normalized, $matches);
        $tokens = $matches[0] ?? [];

        if (empty($tokens)) {
            return array_fill(0, $this->dimension, 0.0);
        }

        $weights = [];

        // 1. Unigram tokens
        foreach ($tokens as $pos => $token) {
            $len = mb_strlen($token, 'UTF-8');
            // Weight: longer semantic words have slightly higher weight than 1-2 letter particles
            $weight = ($len > 2) ? 1.2 : 0.8;
            $weights[$token] = ($weights[$token] ?? 0.0) + $weight;
        }

        // 2. Bigrams for phrase preservation
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            $bigram = $tokens[$i] . '_' . $tokens[$i + 1];
            $weights[$bigram] = ($weights[$bigram] ?? 0.0) + 1.5;
        }

        // 3. Character tri-grams for subword & morphological similarity (helpful for Hindi inflections)
        foreach ($tokens as $token) {
            $tokenLen = mb_strlen($token, 'UTF-8');
            if ($tokenLen >= 3) {
                for ($j = 0; $j <= $tokenLen - 3; $j++) {
                    $tri = mb_substr($token, $j, 3, 'UTF-8');
                    $weights['tr_' . $tri] = ($weights['tr_' . $tri] ?? 0.0) + 0.4;
                }
            }
        }

        // 4. Project features onto 384 dimensions using multiple hash seeds
        foreach ($weights as $feature => $weight) {
            // Seed 1
            $h1 = crc32($feature);
            $idx1 = abs($h1) % $this->dimension;
            $sign1 = ($h1 & 1) ? 1.0 : -1.0;
            $vector[$idx1] += $sign1 * $weight;

            // Seed 2 (Murmur/FNV offset)
            $h2 = crc32($feature . '_seed2');
            $idx2 = abs($h2) % $this->dimension;
            $sign2 = ($h2 & 1) ? 1.0 : -1.0;
            $vector[$idx2] += $sign2 * ($weight * 0.75);

            // Seed 3
            $h3 = crc32($feature . '_seed3');
            $idx3 = abs($h3) % $this->dimension;
            $sign3 = ($h3 & 1) ? 1.0 : -1.0;
            $vector[$idx3] += $sign3 * ($weight * 0.5);
        }

        // 5. L2 Normalization (ensures length = 1.0 for perfect Cosine metric on Zilliz)
        $norm = 0.0;
        for ($i = 0; $i < $this->dimension; $i++) {
            $norm += $vector[$i] * $vector[$i];
        }

        if ($norm > 0.0) {
            $norm = sqrt($norm);
            for ($i = 0; $i < $this->dimension; $i++) {
                $vector[$i] = (float) round($vector[$i] / $norm, 6);
            }
        }

        return $vector;
    }

    /**
     * Optional neural embedding API caller
     */
    private function callExternalEmbeddingApi(string $text, string $apiUrl, string $apiKey): ?array {
        try {
            $payload = json_encode(['inputs' => $text, 'input' => $text]);
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey
                ],
                CURLOPT_TIMEOUT => 8
            ]);
            $res = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($res, true);
            if (isset($data['data'][0]['embedding']) && is_array($data['data'][0]['embedding'])) {
                return $data['data'][0]['embedding'];
            }
            if (is_array($data) && isset($data[0]) && is_numeric($data[0])) {
                return $data;
            }
        } catch (Exception $e) {
            // Fallback to deterministic
        }
        return null;
    }
}

