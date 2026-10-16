# Chatbot API Documentation (Powered by Kira AI)

A universal, production-ready REST API for chatbots powered by Kira AI (`kira-mini-1.0`). It can be hosted on any PHP server (cPanel, Shared Hosting, VPS, Apache, Nginx, Docker) and consumed by any client application.

---

## 🚀 Features

- **Model**: Powered exclusively by `kira-mini-1.0`.
- **Vector Database**: Integrated with **Zilliz Cloud (Milvus Serverless)** in collection `hritikai` (384-dimensional cosine similarity).
- **Semantic Long-Term Memory & RAG**: Automatically retrieves relevant past context and knowledge from Zilliz Cloud and injects it into Kira AI prompt.
- **Default Languages**: Fluent English & Hindi (including natural Hinglish).
- **Auto-Accumulating Chat History**: Simply pass a `session_id` (e.g. `session_id: "user_123"`), and the server automatically stores and remembers previous messages without any database setup!
- **Zero-Delay Real-time Streaming**: Supports Server-Sent Events (`stream: true`) for instant token typing.
- **Zero SQL / Serverless**: 100% cloud-based vector storage without MySQL or local DB headaches.
- **Multi-Client Support**: Call from JavaScript, React, Vue, Python, Android, iOS (Flutter / React Native), PHP, cURL.
- **CORS Enabled**: Pre-configured `Access-Control-Allow-Origin: *` for seamless browser calls without CORS blocks.
- **Environment Security**: Sensitive API keys and upstream endpoints are stored in `.env` and excluded from git.
- **Shared Hosting Ready**: Pure native PHP with cURL — zero external dependencies, no composer required.
- **Auto-Update Safe**: `autoupdate.php` protects `.env` from accidental overwriting.
- **Interactive UI**: Built-in test console and playground at `public/index.html` (or root `/`).

---

## 📡 API Endpoints

### 1. Health & Status Check

- **Method**: `GET`
- **URL**: `https://your-domain.com/api.php`
- **Description**: Returns the server status, active model, and Zilliz Cloud vector database connection details.

**Response Example (200 OK):**
```json
{
  "status": "online",
  "service": "Universal Chatbot API",
  "version": "1.1.0",
  "default_model": "kira-mini-1.0",
  "vector_database": {
    "provider": "Zilliz Cloud (Milvus Serverless)",
    "collection": "hritikai",
    "dimension": 384,
    "endpoint": "in03-67e27e93a0a1153.serverless.aws-eu-central-1.cloud.zilliz.com"
  },
  "timestamp": "2026-09-19T11:17:40+00:00"
}
```

---

### 2. Chat Completion

- **Method**: `POST`
- **URL**: `https://your-domain.com/api.php`
- **Headers**:
  - `Content-Type: application/json`
  - *(Optional)* `Authorization: Bearer <CLIENT_ACCESS_TOKEN>` (if configured in `.env`)

---

#### Format A: Simple Message (Quickest)

Send a single message string:

**Request Payload:**
```json
{
  "message": "What are 3 tips for learning PHP?",
  "system": "You are a senior web developer."
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "reply": "1. Understand fundamentals...\n2. Learn modern PHP 8+ features...\n3. Build real projects.",
  "model": "kira-mini-1.0",
  "usage": {
    "prompt_tokens": 35,
    "completion_tokens": 120,
    "total_tokens": 155
  },
  "created": 1789813316
}
```

---

#### Format B: Multi-Turn Conversation (With History)

Provide previous dialogue turns so the AI remembers context:

**Request Payload:**
```json
{
  "message": "What is my favorite city?",
  "history": [
    { "role": "user", "content": "I live in Mumbai, which is my favorite city." },
    { "role": "assistant", "content": "Mumbai is an amazing city!" }
  ],
  "system": "You are a helpful travel assistant."
}
```

---

#### Format C: Standard OpenAI Format

Fully compatible with OpenAI messages schema:

**Request Payload:**
```json
{
  "model": "kira-mini-1.0",
  "messages": [
    { "role": "system", "content": "You are a helpful assistant." },
    { "role": "user", "content": "Hello!" }
  ],
  "temperature": 0.7,
  "max_tokens": 1024
}
```

---

## 💻 Integration Code Examples

### 1. JavaScript (Frontend / React / Vue / Vanilla JS)

```javascript
async function askChatbot(prompt) {
  const response = await fetch('https://your-domain.com/api.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      message: prompt,
      system: 'You are a helpful customer support representative.'
    })
  });

  const data = await response.json();
  if (data.success) {
    console.log("Chatbot:", data.reply);
    return data.reply;
  } else {
    console.error("Error:", data.error);
  }
}

// Example call:
askChatbot("What are your business hours?");
```

---

### 2. Python (Flask, Django, FastAPI, or Scripts)

```python
import requests

API_URL = "https://your-domain.com/api.php"

def chat(prompt, history=None):
    payload = {
        "message": prompt,
        "history": history or []
    }
    
    response = requests.post(
        API_URL,
        json=payload,
        headers={"Content-Type": "application/json"}
    )
    
    result = response.json()
    if result.get("success"):
        return result["reply"]
    else:
        raise Exception(result.get("error", "Unknown API error"))

print(chat("Explain recursion in 2 sentences."))
```

---

### 3. PHP (Calling from another PHP Website)

```php
<?php
function callChatbotApi($message) {
    $url = 'https://your-domain.com/api.php';
    $payload = json_encode(['message' => $message]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['reply'] ?? null;
}

echo callChatbotApi("Hello from my PHP app!");
?>
```

---

### 4. cURL (Command Line / Bash / CI)

```bash
curl -X POST https://your-domain.com/api.php \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Hello!",
    "system": "You are a friendly bot."
  }'
```

---

### 3. Webpage Scanner & URL Knowledge Ingestion

- **Method**: `POST`
- **URL**: `https://your-domain.com/api.php`
- **Payload**:
```json
{
  "action": "scan_webpage",
  "url": "https://example.com/pricing",
  "options": {
    "chunk_size": 450,
    "chunk_overlap": 60,
    "max_chunks": 50
  }
}
```
- **Response**:
```json
{
  "success": true,
  "action": "scan_webpage",
  "data": {
    "url": "https://example.com/pricing",
    "domain": "example.com",
    "title": "Example Pricing",
    "total_characters": 3400,
    "chunks_indexed": 8,
    "zilliz_response": { "code": 0, "data": { "insertCount": 8 } }
  }
}
```

---

### 3b. Sitemap & Multi-Page Deep Crawler

- **Method**: `POST`
- **URL**: `https://your-domain.com/api.php`
- **Payload**:
```json
{
  "action": "crawl_sitemap",
  "url": "https://example.com/sitemap.xml",
  "options": {
    "max_pages": 20
  }
}
```
- **Response**:
```json
{
  "success": true,
  "action": "crawl_sitemap",
  "data": {
    "source": "https://example.com/sitemap.xml",
    "total_urls_discovered": 15,
    "pages_crawled": 15,
    "total_chunks_indexed": 42,
    "pages": [
      { "url": "https://example.com/", "title": "Home", "chunks_indexed": 4 },
      { "url": "https://example.com/pricing", "title": "Pricing", "chunks_indexed": 3 }
    ]
  }
}
```

---

### 3c. Context-Aware Dynamic Suggestion Chips

- **Method**: `POST`
- **URL**: `https://your-domain.com/api.php`
- **Payload**:
```json
{
  "action": "get_suggestions",
  "url": "https://example.com/pricing",
  "page_title": "Pricing Plans"
}
```
- **Response**:
```json
{
  "success": true,
  "suggestions": [
    { "label": "🏷️ Compare Plans", "query": "Sabhi plans aur unke pricing details bataiye" },
    { "label": "🎁 Annual Discount", "query": "Kya annual plan par discount hai?" },
    { "label": "💼 Enterprise Custom Plan", "query": "Enterprise options kya hain?" }
  ]
}
```

---

### 3d. Captured Customer Leads

- **Method**: `POST`
- **URL**: `https://your-domain.com/api.php`
- **Payload**:
```json
{
  "action": "get_leads",
  "limit": 50
}
```
- **Response**:
```json
{
  "success": true,
  "stats": {
    "total_leads": 12,
    "domains": { "example.com": 12 }
  },
  "leads": [
    {
      "id": "lead_36bd5d9c329d",
      "name": "Amit Kumar",
      "phone": "9811223344",
      "email": "amit@techcorp.in",
      "intent": "sales_inquiry",
      "source_url": "https://example.com/pricing",
      "created_at": "2026-09-19T12:17:17+00:00"
    }
  ]
}
```

---

### 4. 1-Line Embeddable Chat Widget

Embed a full-featured, responsive, floating chat bubble on any website (WordPress, Shopify, React, PHP, static HTML):

```html
<!-- Paste before closing </body> tag -->
<script src="https://your-domain.com/public/widget.js" 
        data-api="https://your-domain.com/api.php" 
        data-title="AI Website Assistant" 
        data-color="#6366f1">
</script>
```

**Features:**
- **Zero dependencies** (Vanilla JavaScript).
- **Auto-Page Context**: Automatically sends `window.location.href` and `document.title` to the API.
- **Real-Time Streaming**: Live typing animation with blinking cursor (`▌`).
- **Persistent Session**: Remembers user conversations across page navigations via `localStorage`.

---

### 5. Storage Monitor & 4.5 GB Auto-Purge

#### Check Storage Status:
```json
{
  "action": "storage_status"
}
```
**Response:**
```json
{
  "success": true,
  "stats": {
    "total_entities": 209,
    "estimated_gb": 0.0004,
    "threshold_gb": 4.5,
    "capacity_gb": 5.0,
    "usage_percent": 0.01,
    "status": "healthy"
  }
}
```

#### Trigger Cleanup:
```json
{
  "action": "storage_cleanup",
  "force": true,
  "records": 1000
}
```

---

### 6. Connected Websites & Activity Logs

```json
{
  "action": "site_logs",
  "limit": 50
}
```
Returns list of connected domains, request counts, action breakdown, and recent chronological events.

---

## 🔒 Security & Deployment Best Practices

1. **Keep `.env` Private**: Never commit `.env` to GitHub. A `.env.example` file is provided for templates.
2. **Access Token Protection**: If you want only authorized clients to call your API, set `CLIENT_ACCESS_TOKEN=your_secret_passphrase` in `.env`. Clients must then provide it in headers: `Authorization: Bearer your_secret_passphrase` or `X-API-Key: your_secret_passphrase`.
3. **Deploying on Shared Hosting / cPanel**:
   - Upload all files to `public_html/`.
   - Ensure PHP version is 7.4 or 8.0+.
   - Set up your `.env` on the server with your Kira AI key and a long, random `AUTOUPDATE_SECRET_TOKEN`.
   - Use `autoupdate.php?token=YOUR_AUTOUPDATE_SECRET_TOKEN` to pull the latest main branch from GitHub without overwriting `.env`.
