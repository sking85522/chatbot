<?php
/**
 * PageGenerator - Creates modern, responsive standalone HTML webpages on-demand
 * for comparisons, product showcases, reports, and detailed explanations.
 */

class PageGenerator {
    private string $pagesDir;

    public function __construct(?string $pagesDir = null) {
        $this->pagesDir = $pagesDir ?: dirname(__DIR__) . '/public/pages';
        if (!is_dir($this->pagesDir)) {
            mkdir($this->pagesDir, 0777, true);
        }
    }

    /**
     * Create a standalone webpage and return its access URL and metadata.
     *
     * @param string $title Page title
     * @param string $content HTML or markdown content
     * @param string $category Optional category/tag (comparison, product_spec, report, general)
     * @param array $meta Extra metadata (author, badges, etc.)
     * @return array
     */
    public function generatePage(string $title, string $content, string $category = 'general', array $meta = []): array {
        $cleanTitle = trim($title) ?: 'Custom Page';
        $slug = $this->generateSlug($cleanTitle) . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
        $fileName = $slug . '.html';
        $filePath = $this->pagesDir . '/' . $fileName;

        $createdDate = date('d M Y, h:i A');
        $bodyHtml = $this->formatContentToHtml($content);

        $badgeColor = '#2874f0';
        $badgeText = strtoupper($category);
        if ($category === 'comparison') {
            $badgeColor = '#fb641b';
            $badgeText = 'VS COMPARISON';
        } elseif ($category === 'product_spec') {
            $badgeColor = '#388e3c';
            $badgeText = 'PRODUCT SPEC';
        }

        $fullHtml = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$cleanTitle} - ShopKart AI Insights</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>⚡</text></svg>">
    <style>
        :root {
            --primary: #2874f0;
            --primary-hover: #1259c7;
            --accent: #fb641b;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --bg-page: #f1f3f6;
            --bg-card: #ffffff;
            --border: #e2e8f0;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-dark);
            line-height: 1.6;
            padding-bottom: 60px;
        }
        .header-bar {
            background: var(--primary);
            color: white;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .header-brand {
            font-size: 20px;
            font-weight: 800;
            text-decoration: none;
            color: white;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .back-link {
            color: white;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            background: rgba(255,255,255,0.18);
            padding: 6px 14px;
            border-radius: 6px;
            transition: background 0.2s ease;
        }
        .back-link:hover {
            background: rgba(255,255,255,0.3);
        }
        .page-container {
            max-width: 960px;
            margin: 32px auto;
            padding: 0 16px;
        }
        .content-card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid var(--border);
        }
        .badge {
            display: inline-block;
            background: {$badgeColor};
            color: white;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 12px;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        h1 {
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 8px;
        }
        .meta-info {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .meta-tag {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        .article-body {
            font-size: 15px;
            color: #334155;
        }
        .article-body h2 {
            font-size: 22px;
            margin-top: 28px;
            margin-bottom: 14px;
            color: #0f172a;
            border-left: 4px solid var(--primary);
            padding-left: 10px;
        }
        .article-body h3 {
            font-size: 18px;
            margin-top: 20px;
            margin-bottom: 10px;
            color: #1e293b;
        }
        .article-body p {
            margin-bottom: 14px;
        }
        .article-body ul, .article-body ol {
            margin-left: 24px;
            margin-bottom: 16px;
        }
        .article-body li {
            margin-bottom: 6px;
        }
        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }
        th {
            background: #f8fafc;
            font-weight: 700;
            color: #475569;
        }
        tr:hover {
            background-color: #f1f5f9;
        }
        /* Comparison Grid */
        .comparison-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin: 24px 0;
        }
        .comp-card {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .comp-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.08);
        }
        .comp-card h4 {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 8px;
        }
        .comp-price {
            font-size: 20px;
            font-weight: 800;
            color: #2e7d32;
            margin-bottom: 12px;
        }
        /* Footer Banner */
        .action-banner {
            margin-top: 36px;
            padding: 20px;
            background: linear-gradient(135deg, #2874f0, #1e3a8a);
            border-radius: 8px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .action-banner h4 {
            font-size: 17px;
            margin-bottom: 4px;
        }
        .action-btn {
            background: var(--accent);
            color: white;
            text-decoration: none;
            padding: 10px 22px;
            font-weight: 700;
            border-radius: 6px;
            font-size: 14px;
            box-shadow: 0 4px 10px rgba(251,100,27,0.4);
        }
        .action-btn:hover {
            background: #e65100;
        }
    </style>
</head>
<body>
    <header class="header-bar">
        <a href="../index.html" class="header-brand">
            <span>🛍️ ShopKart</span>
            <span style="font-size: 12px; font-weight: 400; opacity: 0.9;">AI Generated Insight</span>
        </a>
        <a href="../index.html" class="back-link">&larr; Return to Store</a>
    </header>

    <div class="page-container">
        <div class="content-card">
            <span class="badge">{$badgeText}</span>
            <h1>{$cleanTitle}</h1>
            <div class="meta-info">
                <span>Created: {$createdDate}</span>
                <span class="meta-tag">⚡ Generated by Kira AI Assistant</span>
            </div>

            <div class="article-body">
                {$bodyHtml}
            </div>

            <div class="action-banner">
                <div>
                    <h4>Want to purchase or learn more?</h4>
                    <p style="font-size: 13px; opacity: 0.9;">Shop with 100% genuine guarantee, fast delivery, and instant discounts.</p>
                </div>
                <a href="../index.html" class="action-btn">Shop Now</a>
            </div>
        </div>
    </div>
</body>
</html>
HTML;

        file_put_contents($filePath, $fullHtml);

        $url = 'pages/' . $fileName;

        return [
            'success' => true,
            'title' => $cleanTitle,
            'slug' => $slug,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'relative_url' => $url,
            'web_url' => '/public/' . $url,
            'created_at' => date('c')
        ];
    }

    /**
     * Convert markdown text or plain HTML into safe clean formatted HTML.
     */
    private function formatContentToHtml(string $content): string {
        $trimmed = trim($content);

        // If it's already structured HTML with tags like <table>, <div>, <h2>, keep it
        if (preg_match('/<table|<div|<h[1-6]|<p>/i', $trimmed)) {
            return $trimmed;
        }

        // Otherwise format markdown to HTML
        $html = htmlspecialchars($trimmed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Headers
        $html = preg_replace('/^### (.*$)/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.*$)/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.*$)/m', '<h1>$1</h1>', $html);

        // Bold & Italic
        $html = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*([^\*]+)\*/s', '<em>$1</em>', $html);

        // Markdown Tables
        if (str_contains($html, '|')) {
            $html = preg_replace_callback('/(?:\|[^\n]+\|\r?\n?)+/s', function($matches) {
                $block = trim($matches[0]);
                $lines = array_filter(explode("\n", $block), fn($l) => str_contains($l, '|') && !str_contains($l, '---'));
                if (empty($lines)) return $block;

                $tbl = '<table><thead>';
                $isHeader = true;
                foreach ($lines as $line) {
                    $cols = array_values(array_filter(array_map('trim', explode('|', $line)), fn($c, $k) => $c !== '', ARRAY_FILTER_USE_BOTH));
                    if (empty($cols)) continue;

                    if ($isHeader) {
                        $tbl .= '<tr>' . implode('', array_map(fn($c) => "<th>{$c}</th>", $cols)) . '</tr></thead><tbody>';
                        $isHeader = false;
                    } else {
                        $tbl .= '<tr>' . implode('', array_map(fn($c) => "<td>{$c}</td>", $cols)) . '</tr>';
                    }
                }
                $tbl .= '</tbody></table>';
                return $tbl;
            }, $html);
        }

        // Bullet lists
        $html = preg_replace('/^\s*[-*]\s+(.*)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>(\s*<li>.*<\/li>)*)/s', '<ul>$1</ul>', $html);

        // Line breaks & paragraphs
        $html = nl2br($html);

        return $html;
    }

    private function generateSlug(string $text): string {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = strtolower($text);
        return empty($text) ? 'custom-page' : substr($text, 0, 40);
    }
}

