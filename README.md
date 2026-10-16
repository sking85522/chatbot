# 🤖 Universal Chatbot REST API (Powered by Kira AI)

A lightweight, high-performance, and production-ready AI Chatbot API built with PHP and connected to **Kira AI** (`kira-mini-1.0`).

Designed to run smoothly on any environment—including **Shared Hosting (cPanel)**, VPS, Apache, Nginx, or Docker—with **zero external dependencies**.

---

## ✨ Features

- 🌐 **Call from Anywhere**: Fully CORS-enabled (`Access-Control-Allow-Origin: *`). Use from React, Vue, HTML/JS, Mobile Apps (Flutter/React Native), Python backends, or desktop software.
- 📦 **1-Line Embeddable Chat Widget**: Add `<script src=".../widget.js"></script>` to any website to get a floating, glassmorphic chat bubble with real-time streaming and page context detection!
- 🕷️ **Webpage Scanner & RAG Ingestion**: Ingest any website URL into Zilliz Cloud Vector DB so your AI answers visitor queries with up-to-date knowledge about the website.
- 🧠 **Zilliz Cloud Vector DB Integration**: Serverless Milvus vector storage (collection: `hritikai`, 384 dimensions) for semantic memory and RAG.
- 🛡️ **Auto-Storage Manager (4.5 GB Limit)**: Keeps Zilliz Cloud free-tier storage healthy by auto-purging old inactive chats at 4.5 GB while strictly protecting permanent website knowledge.
- 📊 **Multi-Site Origin Logging**: Tracks which website domains are using your API, with request counters and chronological logs.
- ⚡ **Zero Dependencies & Zero SQL**: Native PHP cURL engine. No composer, vendor, MySQL, or npm installation required.
- 🔐 **Secure Credential Storage**: Upstream Kira AI API keys and models are safely kept in `.env` and excluded from git version control.
- 💬 **English & Hindi Default Support**: Built-in prompt tuning for fluent English, Hindi, and natural Hinglish.

---

## 🚀 Quick Start (Local Development)

1. **Clone the repository:**
   ```bash
   git clone https://github.com/sking85522/chatbot.git
   cd chatbot
   ```

2. **Configure Environment:**
   Create a `.env` file (or copy from `.env.example`):
   ```env
   KIRA_API_KEY=kira_a8e5cc839a0d320992889546057bb6d6
   KIRA_API_URL=https://kiraai.vn/api/v1/chat/completions
   KIRA_DEFAULT_MODEL=kira-mini-1.0
   DEFAULT_SYSTEM_PROMPT=You are a helpful and intelligent AI chatbot assistant.
   ```

3. **Start the PHP Built-in Server:**
   ```bash
   php -S 127.0.0.1:8000
   ```

4. **Test in Browser or Terminal:**
   - Open `http://127.0.0.1:8000/` in your browser to use the interactive console.
   - Or test with cURL:
     ```bash
     curl -X POST http://127.0.0.1:8000/api.php \
       -H "Content-Type: application/json" \
       -d '{"message": "Hello Kira AI!"}'
     ```

---

## 📡 API Usage & Integration

### Endpoint:
`POST /api.php`

### Headers:
`Content-Type: application/json`

### 1. Simple Prompt
```json
{
  "message": "Hello, explain photosynthesis in simple terms."
}
```

### 2. Prompt with System Persona & Conversation History
```json
{
  "message": "What should I pack?",
  "system": "You are a professional travel advisor.",
  "history": [
    {"role": "user", "content": "I am traveling to Switzerland next week."},
    {"role": "assistant", "content": "Switzerland is lovely! It can be chilly in the mountains."}
  ]
}
```

### Example Response:
```json
{
  "success": true,
  "reply": "Pack warm layers, waterproof jacket, comfortable hiking boots...",
  "model": "kira-mini-1.0",
  "usage": {
    "prompt_tokens": 42,
    "completion_tokens": 98,
    "total_tokens": 140
  },
  "created": 1789813316
}
```

---

## 💻 Calling from Other Projects

### JavaScript / Frontend (React, Vue, Web)
```javascript
const response = await fetch('https://your-domain.com/api.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ message: 'Hello!' })
});
const data = await response.json();
console.log(data.reply);
```

### Python
```python
import requests

res = requests.post('https://your-domain.com/api.php', json={'message': 'Hello!'})
print(res.json().get('reply'))
```

---

## 🌐 Live Server Deployment (Shared Hosting / cPanel)

1. Upload all project files to your domain's web root (e.g. `public_html/`).
2. Create `.env` on your hosting server with your Kira AI credentials and a long, random `AUTOUPDATE_SECRET_TOKEN`.
3. For auto-updating without SSH:
   Trigger: `https://your-domain.com/autoupdate.php?token=YOUR_AUTOUPDATE_SECRET_TOKEN`
   *(Your `.env` file will be safely kept and will never be overwritten).*

---

## 📁 Project Structure

```
├── .env                  # Private configuration (DO NOT commit to git)
├── .env.example          # Safe template configuration
├── .gitignore            # Git exclusions
├── api.php               # Main REST API entrypoint (Chat & Vector actions)
├── autoupdate.php        # Remote update script with .env safeguard
├── index.php             # Root web router (loads public/index.html)
├── config/
│   └── config.php        # .env loader, CORS setup, helpers
├── vector/               # Modular Cloud Vector Database Engine
│   ├── ZillizClient.php      # Core REST client for Zilliz Cloud (Milvus)
│   ├── EmbeddingService.php  # 384-dimensional vector embedding engine
│   ├── VectorInsert.php      # Inserts memories & documents into collection
│   ├── VectorSearch.php      # Semantic similarity search & RAG context builder
│   └── CollectionManager.php # Collection & entity management
├── src/
│   ├── KiraClient.php    # cURL connector to Kira AI
│   ├── SessionManager.php # Session conversation accumulator
│   └── ChatService.php   # Request validation, Vector RAG & response formatter
├── public/
│   └── index.html        # Interactive chat & Vector DB tester
└── docs/
    └── API_DOCUMENTATION.md # In-depth developer guide
```

---

## 📄 License
MIT License. Free to use and integrate across all your personal and commercial projects.