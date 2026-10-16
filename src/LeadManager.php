<?php
/**
 * LeadManager - Captures, persists, and retrieves customer leads
 * directly in Zilliz Cloud Vector DB (Zero local disk files).
 */

require_once dirname(__DIR__) . '/vector/VectorInsert.php';
require_once dirname(__DIR__) . '/vector/ZillizClient.php';

class LeadManager {
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
     * Save a lead directly into Zilliz Cloud DB
     */
    public function saveLead(array $data): array {
        $leadId = 'lead_' . bin2hex(random_bytes(6)) . '_' . time();
        $name = trim($data['name'] ?? 'Anonymous Visitor');
        $phone = trim($data['phone'] ?? '');
        $email = trim($data['email'] ?? '');
        $intent = trim($data['intent'] ?? 'general_inquiry');
        $notes = trim($data['notes'] ?? '');
        $sourceUrl = trim($data['source_url'] ?? '');
        $sessionId = trim($data['session_id'] ?? '');

        $domain = 'direct_api';
        if (!empty($sourceUrl)) {
            $parsedHost = parse_url($sourceUrl, PHP_URL_HOST);
            if ($parsedHost) $domain = $parsedHost;
        }

        $leadSummary = "Customer Lead [{$intent}]: Name: {$name} | Phone: {$phone} | Email: {$email} | Domain: {$domain} | Notes: {$notes}";

        if ($this->vectorInsert !== null) {
            try {
                $this->vectorInsert->insertText($leadSummary, [
                    'type' => 'lead_contact',
                    'domain' => $domain,
                    'url' => $sourceUrl,
                    'session_id' => $sessionId,
                    'user_message' => "Name: {$name}, Phone: {$phone}, Email: {$email}",
                    'assistant_reply' => "Lead recorded for intent: {$intent}"
                ], $leadId);
            } catch (Throwable $t) {}
        }

        return [
            'id' => $leadId,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'intent' => $intent,
            'domain' => $domain,
            'notes' => $notes,
            'created_at' => date('c')
        ];
    }

    /**
     * Retrieve leads directly from Zilliz Cloud DB
     */
    public function getLeads(int $limit = 50, ?string $domain = null): array {
        if ($this->client === null) return [];
        try {
            $filter = 'type == "lead_contact"';
            if (!empty($domain)) {
                $safe = addslashes($domain);
                $filter .= " and domain == \"{$safe}\"";
            }
            return $this->client->query($filter, ['id', 'text', 'domain', 'url', 'session_id', 'created_at', 'user_message'], $limit);
        } catch (Throwable $t) {
            return [];
        }
    }

    /**
     * Get basic lead statistics from Zilliz DB
     */
    public function getStats(): array {
        $leads = $this->getLeads(100);
        return [
            'total_leads' => count($leads),
            'storage_engine' => 'Zilliz Cloud Milvus (Serverless)'
        ];
    }

    /**
     * Extract contact info from natural text
     */
    public static function extractContactInfo(string $text): array {
        $phone = null;
        $email = null;
        $name = null;

        // Indian 10-digit mobile or international phone regex
        if (preg_match('/(?:\+91[\-\s]?)?[6-9]\d{9}\b/', $text, $matches)) {
            $phone = trim($matches[0]);
        } elseif (preg_match('/(?:\+?1[\-\s]?)?\(?\d{3}\)?[\-\s]?\d{3}[\-\s]?\d{4}\b/', $text, $matches)) {
            $phone = trim($matches[0]);
        }

        // Email regex
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $matches)) {
            $email = trim($matches[0]);
        }

        // Name heuristics
        if (preg_match('/(?:my name is|mera naam|i am|this is)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)/i', $text, $matches)) {
            $name = trim($matches[1]);
        }

        $intent = 'product_inquiry';
        $lower = strtolower($text);
        if (str_contains($lower, 'price') || str_contains($lower, 'discount') || str_contains($lower, 'buy') || str_contains($lower, 'order') || str_contains($lower, 'kharidna')) {
            $intent = 'purchase_intent';
        } elseif (str_contains($lower, 'support') || str_contains($lower, 'help') || str_contains($lower, 'return') || str_contains($lower, 'refund')) {
            $intent = 'customer_support';
        }

        return [
            'has_contact' => (!empty($phone) || !empty($email)),
            'phone' => $phone,
            'email' => $email,
            'name' => $name,
            'intent' => $intent
        ];
    }
}
