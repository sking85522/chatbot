<?php
/**
 * KiraClient - Communicates with Kira AI API with ultra-low latency & streaming support.
 */

class KiraClient {
    private string $apiKey;
    private string $apiUrl;
    private string $defaultModel;

    public function __construct(?string $apiKey = null, ?string $apiUrl = null, ?string $defaultModel = null) {
        $this->apiKey = $apiKey ?: KIRA_API_KEY;
        $this->apiUrl = $apiUrl ?: KIRA_API_URL;
        $this->defaultModel = $defaultModel ?: KIRA_DEFAULT_MODEL;

        if (empty($this->apiKey)) {
            throw new Exception("Kira API key is missing. Please configure KIRA_API_KEY in your .env file.");
        }
    }

    /**
     * Send chat completion request to Kira AI (Synchronous / Standard JSON)
     */
    public function chatCompletion(array $messages, array $options = []): array {
        $model = $options['model'] ?? $this->defaultModel;

        $payload = [
            'model' => $model,
            'messages' => $messages
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = (int) $options['max_tokens'];
        }
        if (isset($options['top_p'])) {
            $payload['top_p'] = (float) $options['top_p'];
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'User-Agent: UniversalChatbotAPI/1.0',
                'Connection: keep-alive'
            ],
            // Low-latency network optimizations
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_TCP_NODELAY => 1,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception("cURL Error: " . $curlError);
        }

        if (empty($response)) {
            throw new Exception("Received empty response from Kira AI (HTTP {$httpCode}).");
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON returned by Kira AI: " . substr($response, 0, 200));
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $errMsg = $decoded['error']['message'] ?? $decoded['message'] ?? "Kira AI HTTP {$httpCode}";

            // Auto-fallback if primary model is busy/maintenance
            $fallbackModel = defined('KIRA_FALLBACK_MODEL') ? KIRA_FALLBACK_MODEL : null;
            $isBusy = (stripos($errMsg, 'unavailable') !== false || stripos($errMsg, 'maintenance') !== false || $httpCode == 503);

            if ($isBusy && !empty($fallbackModel) && $model !== $fallbackModel && empty($options['_is_fallback'])) {
                $options['model'] = $fallbackModel;
                $options['_is_fallback'] = true;
                return $this->chatCompletion($messages, $options);
            }

            throw new Exception("Kira AI API Error: " . $errMsg, $httpCode);
        }

        return $decoded;
    }

    /**
     * Stream chat completion directly to the client with ZERO delay (Server-Sent Events)
     */
    public function streamChatCompletion(array $messages, array $options = [], ?callable $onComplete = null): void {
        $model = $options['model'] ?? $this->defaultModel;

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = (int) $options['max_tokens'];
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // Turn off PHP output buffering for instant streaming
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Send SSE headers
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Nginx unbuffered

        $fullReply = '';

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => false, // Direct output
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: text/event-stream',
                'User-Agent: UniversalChatbotAPI/1.0'
            ],
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_TCP_NODELAY => 1,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_WRITEFUNCTION => function($curlHandle, $chunk) use (&$fullReply) {
                echo $chunk;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                // Accumulate reply text
                $lines = explode("\n", $chunk);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (str_starts_with($line, 'data:')) {
                        $json = trim(substr($line, 5));
                        if ($json !== '[DONE]' && !empty($json)) {
                            $decoded = json_decode($json, true);
                            $token = $decoded['choices'][0]['delta']['content'] ?? '';
                            if ($token !== '') {
                                $fullReply .= $token;
                            }
                        }
                    }
                }

                return strlen($chunk);
            }
        ]);

        curl_exec($ch);
        curl_close($ch);

        if ($onComplete && !empty($fullReply)) {
            $onComplete($fullReply);
        }

        exit(0);
    }

    /**
     * Extract assistant message reply text from Kira AI response
     */
    public static function extractReplyText(array $response): string {
        if (!empty($response['choices'][0]['message']['content'])) {
            $text = (string) $response['choices'][0]['message']['content'];
            // Clean out raw model tags or commentary blocks if emitted by reasoning model
            $text = preg_replace('/<\|start\|>.*?<\|call\|>/is', '', $text);
            $text = preg_replace('/<\|.*?\|>/is', '', $text);
            return trim($text);
        }

        return '';
    }
}
