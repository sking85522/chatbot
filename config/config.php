<?php
/**
 * Configuration & Environment Loader
 * Supports zero-dependency .env parsing for shared hosting and standalone servers.
 */

// Load .env file
function load_env($filePath) {
    if (!file_exists($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and empty lines
        if (empty($line) || $line[0] === '#' || substr($line, 0, 2) === '//') {
            continue;
        }

        // Split into name and value
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $name = trim($parts[0]);
        $value = trim($parts[1]);

        // Remove surrounding single or double quotes
        if (
            (strlen($value) >= 2) &&
            (($value[0] === '"' && substr($value, -1) === '"') ||
             ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        // Set environment variables if not already set
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Load .env from root directory
load_env(dirname(__DIR__) . '/.env');

// Helper to get environment variables with fallback
function env($key, $default = null) {
    $val = getenv($key);
    if ($val === false) {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
    
    // Handle booleans
    if ($val === 'true' || $val === '(true)') return true;
    if ($val === 'false' || $val === '(false)') return false;
    if ($val === 'null' || $val === '(null)') return null;

    return $val;
}

// Environment settings
define('APP_ENV', env('APP_ENV', 'production'));
define('APP_DEBUG', (bool) env('APP_DEBUG', false));
define('APP_NAME', env('APP_NAME', 'Universal Chatbot API'));

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// Kira AI Settings
define('KIRA_API_KEY', env('KIRA_API_KEY', ''));
define('KIRA_API_URL', env('KIRA_API_URL', 'https://kiraai.vn/api/v1/chat/completions'));
define('KIRA_DEFAULT_MODEL', env('KIRA_DEFAULT_MODEL', 'kira-mini-1.0'));
define('KIRA_FALLBACK_MODEL', env('KIRA_FALLBACK_MODEL', 'kira-mini-1.0'));
define('DEFAULT_SYSTEM_PROMPT', env('DEFAULT_SYSTEM_PROMPT', 'You are a helpful, respectful, and intelligent AI chatbot assistant. Your default languages for communication are English and Hindi (including natural Hinglish). Understand and respond fluently in English, Hindi, or a natural mix of both as requested by the user. Keep your replies concise, helpful, and direct.'));
define('CLIENT_ACCESS_TOKEN', env('CLIENT_ACCESS_TOKEN', ''));

// Zilliz Cloud Vector Database Settings
define('ZILLIZ_ENDPOINT', rtrim((string) env('ZILLIZ_ENDPOINT', ''), '/'));
define('ZILLIZ_API_KEY', env('ZILLIZ_API_KEY', ''));
define('ZILLIZ_COLLECTION', env('ZILLIZ_COLLECTION', 'hritikai'));
define('ZILLIZ_DIMENSION', (int) env('ZILLIZ_DIMENSION', 384));

// CORS Headers setup
function handle_cors() {
    // Allow from any origin
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    } else {
        header("Access-Control-Allow-Origin: *");
    }
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Max-Age: 86400");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-Key, Accept, X-Session-ID");
    header("Access-Control-Expose-Headers: X-Session-ID");

    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit(0);
    }
}

// JSON Output Helper
function send_json($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
