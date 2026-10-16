<?php
/**
 * ProjectAuditor - Secure, sandboxed read-only file & structure inspector.
 *
 * Allows HRITIK AI to understand and inspect the website/project where it is deployed:
 * - Scans directory tree within the allowed project root
 * - Inspects and summarizes content of allowed file extensions (.html, .json, .md, .txt, .css, .js)
 * - Strictly enforces security:
 *     1. Directory traversal prevention (no ../ escape)
 *     2. Root sandboxing (cannot access /etc, system files, or outside project)
 *     3. Sensitive file blocklist (.env, .git, private keys, database dumps)
 *     4. Zero code execution (static, read-only inspection)
 */

class ProjectAuditor {
    private string $projectRoot;

    // Allowed file extensions for reading
    private const ALLOWED_EXTENSIONS = [
        'html', 'htm', 'json', 'md', 'txt', 'css', 'js', 'xml', 'svg'
    ];

    // Blocklist of sensitive filenames and folders
    private const SENSITIVE_PATTERNS = [
        '.env',
        '.git',
        '.ssh',
        '.htpasswd',
        'id_rsa',
        'composer.lock',
        'package-lock.json',
        '.sql',
        '.pem',
        '.key',
        'config.php' // protects database and API keys
    ];

    public function __construct(?string $projectRoot = null) {
        $this->projectRoot = realpath($projectRoot ?: dirname(__DIR__)) ?: dirname(__DIR__);
    }

    /**
     * Get root path
     */
    public function getProjectRoot(): string {
        return $this->projectRoot;
    }

    /**
     * Scan the project folder structure (up to specified depth)
     *
     * @param string $subDir Relative sub-directory inside project root
     * @param int $maxDepth Maximum directory depth to traverse
     * @return array Tree of directories and allowed files
     */
    public function scanStructure(string $subDir = '', int $maxDepth = 3): array {
        $targetPath = $this->resolveSafePath($subDir);
        if (!$targetPath || !is_dir($targetPath)) {
            return [
                'success' => false,
                'error' => 'Invalid directory or access denied.'
            ];
        }

        $tree = $this->traverseDir($targetPath, 1, $maxDepth);

        return [
            'success' => true,
            'project_root' => basename($this->projectRoot),
            'scanned_path' => $this->getRelativePath($targetPath),
            'tree' => $tree
        ];
    }

    /**
     * Safely read content of a specific file inside the project
     *
     * @param string $relativePath Relative file path inside the project
     * @param int $maxBytes Maximum bytes to read (prevents memory exhaustion)
     * @return array
     */
    public function readFile(string $relativePath, int $maxBytes = 32768): array {
        $filePath = $this->resolveSafePath($relativePath);

        if (!$filePath || !is_file($filePath)) {
            return [
                'success' => false,
                'error' => 'File not found or access denied.'
            ];
        }

        // Extension check
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return [
                'success' => false,
                'error' => "Access denied: Reading .{$extension} files is not permitted for security."
            ];
        }

        // Sensitive pattern check
        $basename = basename($filePath);
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (stripos($basename, $pattern) !== false || stripos($filePath, DIRECTORY_SEPARATOR . $pattern) !== false) {
                return [
                    'success' => false,
                    'error' => 'Access denied: Sensitive configuration file protected.'
                ];
            }
        }

        $fileSize = filesize($filePath);
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return [
                'success' => false,
                'error' => 'Failed to open file for reading.'
            ];
        }

        $content = fread($handle, $maxBytes);
        fclose($handle);

        $isTruncated = ($fileSize > $maxBytes);

        return [
            'success' => true,
            'file_name' => $basename,
            'relative_path' => $this->getRelativePath($filePath),
            'extension' => $extension,
            'size_bytes' => $fileSize,
            'is_truncated' => $isTruncated,
            'content' => $content
        ];
    }

    /**
     * Resolve a path and verify it remains strictly inside the project root sandbox
     */
    private function resolveSafePath(string $path): ?string {
        $trimmed = trim($path, " \t\n\r\0\x0B/\\");

        // Prevent traversal tricks
        if (str_contains($trimmed, '..')) {
            return null;
        }

        $fullPath = empty($trimmed) ? $this->projectRoot : ($this->projectRoot . DIRECTORY_SEPARATOR . $trimmed);
        $real = realpath($fullPath);

        if (!$real) {
            return null;
        }

        // Must start with projectRoot path
        if (!str_starts_with($real, $this->projectRoot)) {
            return null;
        }

        // Check if any path component hits sensitive blocklist
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (stripos($real, DIRECTORY_SEPARATOR . $pattern) !== false) {
                return null;
            }
        }

        return $real;
    }

    /**
     * Recursive directory scanner within depth limits
     */
    private function traverseDir(string $dir, int $currentDepth, int $maxDepth): array {
        if ($currentDepth > $maxDepth) {
            return ['... (depth limit reached)'];
        }

        $items = [];
        $entries = @scandir($dir);
        if (!$entries) return [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;

            // Skip hidden files & sensitive directories
            if (str_starts_with($entry, '.')) continue;

            $full = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($full)) {
                $items[$entry . '/'] = $this->traverseDir($full, $currentDepth + 1, $maxDepth);
            } elseif (is_file($full)) {
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                    $items[] = $entry . ' (' . round(filesize($full) / 1024, 1) . ' KB)';
                }
            }
        }

        return $items;
    }

    private function getRelativePath(string $fullPath): string {
        if (str_starts_with($fullPath, $this->projectRoot)) {
            $rel = substr($fullPath, strlen($this->projectRoot));
            return ltrim($rel, DIRECTORY_SEPARATOR) ?: '/';
        }
        return $fullPath;
    }
}

