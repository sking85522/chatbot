<?php
/**
 * SuggestionEngine - Generates dynamic, context-aware quick suggestion chips
 * based on the user's active webpage URL, document title, and search cache.
 */

class SuggestionEngine {
    public function __construct() {
        // 100% Stateless & in-memory - Zero local disk storage
    }

    /**
     * Generate context-aware suggestions for a given page URL and title
     *
     * @param string|null $url The current webpage URL
     * @param string|null $title The current document/page title
     * @param string|null $recentQuery The last user query (for follow-up suggestions)
     * @return array List of suggestions with label, query, and icon
     */
    public function getSuggestions(?string $url = null, ?string $title = null, ?string $recentQuery = null): array {
        $url = trim((string) $url);
        $title = trim((string) $title);
        $path = '';

        if (!empty($url)) {
            $parsed = parse_url($url);
            $path = strtolower($parsed['path'] ?? '');
            if (empty($title) && !empty($parsed['host'])) {
                $title = $parsed['host'];
            }
        }

        $combinedContext = strtolower($path . ' ' . $title);

        // 1. Follow-up suggestions if recentQuery exists
        if (!empty($recentQuery)) {
            $recentLower = strtolower($recentQuery);
            if (str_contains($recentLower, 'price') || str_contains($recentLower, 'cost') || str_contains($recentLower, 'plan')) {
                return [
                    ['label' => '💳 Payment & Refund Policy', 'query' => 'Aapke payment methods aur refund policy kya hai?', 'icon' => '💳'],
                    ['label' => '⚡ Free Trial / Demo', 'query' => 'Kya free trial ya demo available hai?', 'icon' => '⚡'],
                    ['label' => '📞 Request a Quote', 'query' => 'Mujhe sales team se baat karke quote chahiye', 'icon' => '📞']
                ];
            }
        }

        // 2. Pricing / Plans page context
        if (str_contains($combinedContext, 'price') || str_contains($combinedContext, 'pricing') || str_contains($combinedContext, 'plan') || str_contains($combinedContext, 'cost')) {
            return [
                ['label' => '🏷️ Compare Plans', 'query' => 'Sabhi plans aur unke pricing details bataiye', 'icon' => '🏷️'],
                ['label' => '🎁 Annual Discount', 'query' => 'Kya annual ya long-term subscription par discount hai?', 'icon' => '🎁'],
                ['label' => '💼 Enterprise / Custom Plan', 'query' => 'Enterprise ya custom requirements ke liye kya options hain?', 'icon' => '💼'],
                ['label' => '🔄 Refund Policy', 'query' => 'Aapki cancellation aur refund policy kya hai?', 'icon' => '🔄']
            ];
        }

        // 3. Contact / Support / Help page context
        if (str_contains($combinedContext, 'contact') || str_contains($combinedContext, 'support') || str_contains($combinedContext, 'help') || str_contains($combinedContext, 'faq')) {
            return [
                ['label' => '📞 Helpline & Support', 'query' => 'Customer support ka phone number aur email kya hai?', 'icon' => '📞'],
                ['label' => '🕒 Business Hours', 'query' => 'Aapke working hours aur response time kya hai?', 'icon' => '🕒'],
                ['label' => '📍 Office Location', 'query' => 'Aapka office address ya location kahan hai?', 'icon' => '📍'],
                ['label' => '🤝 Request Callback', 'query' => 'Mujhe ek callback chahiye support team se', 'icon' => '🤝']
            ];
        }

        // 4. Product / Service / Feature page context
        if (str_contains($combinedContext, 'product') || str_contains($combinedContext, 'service') || str_contains($combinedContext, 'feature') || str_contains($combinedContext, 'solution')) {
            return [
                ['label' => '✨ Key Features', 'query' => 'Is service ke top features aur benefits kya hain?', 'icon' => '✨'],
                ['label' => '🚀 How it Works', 'query' => 'Yeh kaise kaam karta hai aur setup kaise karein?', 'icon' => '🚀'],
                ['label' => '💰 Pricing Details', 'query' => 'Iski pricing aur plans kya hain?', 'icon' => '💰'],
                ['label' => '🎯 Book a Demo', 'query' => 'Mujhe iska live demo ya walkthrough dekhna hai', 'icon' => '🎯']
            ];
        }

        // 5. If title mentions a specific brand/topic (e.g. Apex Cloud)
        if (!empty($title) && !in_array(strtolower($title), ['webpage', 'untitled', 'home'])) {
            $shortTitle = mb_substr($title, 0, 30);
            return [
                ['label' => "📖 About {$shortTitle}", 'query' => "{$shortTitle} ke baare me detail me bataiye", 'icon' => '📖'],
                ['label' => '💰 Plans & Pricing', 'query' => "{$shortTitle} ke plans aur pricing kya hain?", 'icon' => '💰'],
                ['label' => '⭐ Top Features', 'query' => "{$shortTitle} ke main features aur fayde kya hain?", 'icon' => '⭐'],
                ['label' => '📞 Contact & Support', 'query' => 'Contact details aur support helpline kya hai?', 'icon' => '📞']
            ];
        }

        // 6. Default general suggestions (Bilingual English & Hindi)
        return [
            ['label' => '❓ How can you help me?', 'query' => 'Aap meri kya madad kar sakte hain is website par?', 'icon' => '❓'],
            ['label' => '🏷️ Products & Pricing', 'query' => 'Website ke main products aur pricing plans kya hain?', 'icon' => '🏷️'],
            ['label' => '📞 Contact Support', 'query' => 'Support team se kaise contact karein?', 'icon' => '📞'],
            ['label' => '⚡ What is this site about?', 'query' => 'Yeh website kis baare me hai?', 'icon' => '⚡']
        ];
    }
}

