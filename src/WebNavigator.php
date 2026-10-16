<?php
/**
 * WebNavigator - Intelligent URL Scraper, deep content extractor, and page router.
 */

class WebNavigator {

    /**
     * Deep scrape a given URL and extract clean structured text, headings, tables, and prices.
     */
    public function extractContent(string $url): array {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid URL provided: {$url}");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 KiraNavigator/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || empty($html) || $httpCode >= 400) {
            return [
                'success' => false,
                'error' => "Failed to fetch URL (HTTP {$httpCode}): " . ($curlError ?: 'Empty response'),
                'url' => $url
            ];
        }

        return $this->parseHtml($html, $url);
    }

    /**
     * Parse HTML into structured summary, title, headings, and key highlights.
     */
    public function parseHtml(string $html, string $url): array {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        // Remove script, style, noscript
        $removeTags = ['script', 'style', 'noscript', 'iframe', 'svg'];
        foreach ($removeTags as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            while ($nodes->length > 0) {
                $nodes->item(0)->parentNode->removeChild($nodes->item(0));
            }
        }

        // Title
        $titleNode = $dom->getElementsByTagName('title');
        $title = $titleNode->length > 0 ? trim($titleNode->item(0)->textContent) : parse_url($url, PHP_URL_PATH);

        // Meta description
        $metaDescription = '';
        $metas = $dom->getElementsByTagName('meta');
        foreach ($metas as $meta) {
            if (strtolower($meta->getAttribute('name')) === 'description') {
                $metaDescription = trim($meta->getAttribute('content'));
                break;
            }
        }

        // Headings
        $headings = [];
        foreach (['h1', 'h2', 'h3'] as $htag) {
            $hNodes = $dom->getElementsByTagName($htag);
            foreach ($hNodes as $h) {
                $text = trim(preg_replace('/\s+/', ' ', $h->textContent));
                if (!empty($text) && strlen($text) > 3 && strlen($text) < 120) {
                    $headings[] = "[$htag] $text";
                }
            }
        }

        // Body plain text extraction
        $xpath = new DOMXPath($dom);
        $paragraphs = [];
        $pNodes = $xpath->query('//p | //article | //section | //li');
        foreach ($pNodes as $p) {
            $text = trim(preg_replace('/\s+/', ' ', $p->textContent));
            if (strlen($text) > 30) {
                $paragraphs[] = $text;
            }
        }

        $cleanText = implode("\n", array_slice($paragraphs, 0, 25));
        if (strlen($cleanText) > 4000) {
            $cleanText = substr($cleanText, 0, 4000) . '...';
        }

        return [
            'success' => true,
            'url' => $url,
            'title' => $title,
            'meta_description' => $metaDescription,
            'headings' => array_slice($headings, 0, 10),
            'content_sample' => $cleanText,
            'char_count' => strlen($cleanText)
        ];
    }
}

