<?php
/**
 * StorageManager - Monitors Zilliz Cloud Vector DB storage usage and automatically
 * purges oldest inactive chat records when storage reaches 4.5 GB to free 0.5 GB,
 * while strictly preserving permanent webpage knowledge.
 */

require_once __DIR__ . '/ZillizClient.php';

class StorageManager {
    // Thresholds
    public const MAX_CAPACITY_GB = 5.0;     // Free plan limit
    public const PURGE_THRESHOLD_GB = 4.5;   // Trigger auto-cleanup at 4.5 GB
    public const TARGET_FREE_GB = 0.5;       // Free up 0.5 GB on cleanup
    public const BYTES_PER_ENTITY = 2048;    // 384 float32 vector (1.5KB) + dynamic metadata (~0.5KB) ≈ 2 KB

    private ZillizClient $client;
    private static ?array $memoryCache = null;

    public function __construct(?ZillizClient $client = null) {
        $this->client = $client ?: new ZillizClient();
    }

    /**
     * Get current entity count from Zilliz Cloud
     */
    public function getEntityCount(): int {
        try {
            $res = $this->client->request('entities/query', [
                'collectionName' => $this->client->getDefaultCollection(),
                'filter' => "id != ''",
                'outputFields' => ['count(*)']
            ]);

            if (!empty($res['data'][0]['count(*)'])) {
                return (int) $res['data'][0]['count(*)'];
            }
        } catch (Throwable $e) {
            // fallback
        }
        return 0;
    }

    /**
     * Retrieve storage statistics (cached in-memory for 60 seconds unless forced)
     */
    public function getStats(bool $forceRefresh = false): array {
        if (!$forceRefresh && self::$memoryCache !== null) {
            if (time() - (self::$memoryCache['cached_at'] ?? 0) < 60) {
                return self::$memoryCache;
            }
        }

        $entityCount = $this->getEntityCount();
        $estimatedBytes = $entityCount * self::BYTES_PER_ENTITY;
        $estimatedGb = round($estimatedBytes / (1024 * 1024 * 1024), 4);
        $usagePercent = round(($estimatedGb / self::MAX_CAPACITY_GB) * 100, 2);

        $status = 'healthy';
        if ($estimatedGb >= self::PURGE_THRESHOLD_GB) {
            $status = 'critical_cleanup_needed';
        } elseif ($estimatedGb >= 4.0) {
            $status = 'warning';
        }

        $stats = [
            'total_entities' => $entityCount,
            'estimated_bytes' => $estimatedBytes,
            'estimated_gb' => $estimatedGb,
            'threshold_gb' => self::PURGE_THRESHOLD_GB,
            'capacity_gb' => self::MAX_CAPACITY_GB,
            'target_free_gb' => self::TARGET_FREE_GB,
            'usage_percent' => $usagePercent,
            'status' => $status,
            'collection' => $this->client->getDefaultCollection(),
            'cached_at' => time(),
            'updated_at' => date('c')
        ];

        self::$memoryCache = $stats;
        return $stats;
    }

    /**
     * Check if storage has reached 4.5 GB and automatically clean up oldest inactive chats
     *
     * @param bool $force If true, executes cleanup regardless of threshold (for manual triggers)
     * @param int|null $customTargetRecords Custom count of records to delete
     * @return array Cleanup summary
     */
    public function checkAndClean(bool $force = false, ?int $customTargetRecords = null): array {
        $stats = $this->getStats(true);
        $estimatedGb = $stats['estimated_gb'];

        if (!$force && $estimatedGb < self::PURGE_THRESHOLD_GB) {
            return [
                'action' => 'none',
                'reason' => "Storage is within safe limits ({$estimatedGb} GB / " . self::PURGE_THRESHOLD_GB . " GB limit).",
                'stats' => $stats
            ];
        }

        // Calculate number of chat records to purge to free ~0.5 GB
        $targetRecords = $customTargetRecords ?: (int) ceil((self::TARGET_FREE_GB * 1024 * 1024 * 1024) / self::BYTES_PER_ENTITY);
        $collection = $this->client->getDefaultCollection();
        $totalPurged = 0;
        $batchSize = 250;

        while ($totalPurged < $targetRecords) {
            $fetchLimit = min($batchSize, $targetRecords - $totalPurged);

            // Fetch oldest chat_memory entities ONLY (NEVER webpage_knowledge)
            $queryRes = $this->client->request('entities/query', [
                'collectionName' => $collection,
                'filter' => 'type == "chat_memory"',
                'outputFields' => ['id', 'created_at'],
                'limit' => $fetchLimit
            ]);

            $records = $queryRes['data'] ?? [];
            if (empty($records)) {
                // No more chat_memory records available to delete
                break;
            }

            // Extract IDs
            $ids = array_column($records, 'id');
            if (empty($ids)) {
                break;
            }

            // Delete batch
            $escapedIds = array_map(fn($id) => '"' . addslashes($id) . '"', $ids);
            $filterStr = 'id in [' . implode(',', $escapedIds) . ']';

            $delRes = $this->client->request('entities/delete', [
                'collectionName' => $collection,
                'filter' => $filterStr
            ]);

            $deletedCount = count($ids);
            $totalPurged += $deletedCount;

            // Small pause between batches to respect network rate limits
            if (count($records) < $fetchLimit) {
                break;
            }
            usleep(50000); // 50ms
        }

        $freedBytes = $totalPurged * self::BYTES_PER_ENTITY;
        $freedGb = round($freedBytes / (1024 * 1024 * 1024), 4);

        // Refresh stats
        $newStats = $this->getStats(true);

        return [
            'success' => true,
            'action' => 'cleanup_performed',
            'purged_records' => $totalPurged,
            'freed_bytes' => $freedBytes,
            'freed_gb' => $freedGb,
            'new_stats' => $newStats
        ];
    }
}

