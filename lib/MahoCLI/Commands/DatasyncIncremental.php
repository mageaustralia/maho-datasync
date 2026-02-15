<?php

/**
 * Maho
 *
 * @package    MahoCLI
 * @copyright  Copyright (c) 2025-2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoCLI\Commands;

use Mage;
use PDO;
use PDOException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'datasync:incremental',
    description: 'Sync changes from live OpenMage using the tracker table',
)]
class DatasyncIncremental extends Command
{
    /**
     * Entity processing order (respects dependencies)
     */
    protected const ENTITY_ORDER = [
        'customer',
        'customer_address',
        'order',
        'invoice',
        'shipment',
        'creditmemo',
        'order_comment',
        'newsletter',
        'product',
        'stock',
        'category',
    ];

    /**
     * Map tracker entity types to DataSync entity types
     */
    protected const ENTITY_MAP = [
        'customer' => 'customer',
        'customer_address' => null, // Handled with customer
        'order' => 'order',
        'invoice' => 'invoice',
        'shipment' => 'shipment',
        'creditmemo' => 'creditmemo',
        'order_comment' => null, // Handled with order
        'newsletter' => 'newsletter',
        'product' => 'product',
        'stock' => 'stock', // Use dedicated stock sync (efficient)
        'category' => 'category',
    ];

    protected ?PDO $livePdo = null;

    /** @var resource|null File handle for lock file */
    private $lockFileHandle = null;

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption(
                'entity',
                'e',
                InputOption::VALUE_REQUIRED,
                'Sync only specific entity type (order, invoice, shipment, creditmemo, product, customer, newsletter)',
            )
            ->addOption(
                'mark-synced',
                null,
                InputOption::VALUE_NONE,
                'Mark tracker records as synced after successful import',
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Limit number of records to process per entity type',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be synced without making changes',
            )
            ->addOption(
                'stock',
                null,
                InputOption::VALUE_REQUIRED,
                'Stock sync mode: include (default), exclude, only',
                'include',
            )
            ->addOption(
                'no-lock',
                null,
                InputOption::VALUE_NONE,
                'Skip lock file check (not recommended)',
            )
            ->addOption(
                'db-host',
                null,
                InputOption::VALUE_REQUIRED,
                'Live database host',
            )
            ->addOption(
                'db-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Live database name',
            )
            ->addOption(
                'db-user',
                null,
                InputOption::VALUE_REQUIRED,
                'Live database user',
            )
            ->addOption(
                'db-pass',
                null,
                InputOption::VALUE_REQUIRED,
                'Live database password',
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Mage::app('admin');

        // Acquire lock to prevent concurrent runs
        if (!$input->getOption('no-lock') && !$this->acquireLock($output)) {
            return Command::FAILURE;
        }

        try {
            return $this->doExecute($input, $output);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Main execution logic (wrapped by lock)
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>DataSync: Incremental sync from tracker table</info>');
        $output->writeln('');

        // Connect to live database
        try {
            $this->livePdo = $this->connectToLive($input);
        } catch (\Exception $e) {
            $output->writeln("<error>Failed to connect to live database: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $entityFilter = $input->getOption('entity');
        $markSynced = $input->getOption('mark-synced');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $dryRun = $input->getOption('dry-run');
        $stockMode = $input->getOption('stock') ?? 'include';

        // Validate stock mode
        if (!in_array($stockMode, ['include', 'exclude', 'only'])) {
            $output->writeln("<error>Invalid --stock mode: {$stockMode}. Use: include, exclude, only</error>");
            return Command::FAILURE;
        }

        // Get pending changes from tracker
        $pending = $this->getPendingChanges($entityFilter, $limit);

        // Apply stock mode filtering
        if ($stockMode === 'only') {
            // Only sync stock changes
            $pending = array_filter($pending, fn($k) => $k === 'stock', ARRAY_FILTER_USE_KEY);
        } elseif ($stockMode === 'exclude') {
            // Exclude stock from sync (still mark as synced to clear queue)
            unset($pending['stock']);
        }
        // 'include' is default - sync everything

        if (empty($pending)) {
            $output->writeln('<comment>No pending changes to sync.</comment>');
            return Command::SUCCESS;
        }

        // Display summary
        $output->writeln('<info>Pending changes:</info>');
        foreach ($pending as $type => $items) {
            $count = count($items);
            $output->writeln("  {$type}: {$count}");
        }
        $output->writeln('');

        if ($dryRun) {
            $output->writeln('<comment>Dry run - no changes will be made.</comment>');
            return Command::SUCCESS;
        }

        // Process deletes first and clean up orphaned records
        $orphanedIds = $this->processDeletesAndOrphans($pending, $output);
        $syncedTrackerIds = $orphanedIds;

        // Process in dependency order
        $totalSynced = 0;
        $totalErrors = 0;

        foreach (self::ENTITY_ORDER as $trackerType) {
            if (!isset($pending[$trackerType])) {
                continue;
            }

            $datasyncType = self::ENTITY_MAP[$trackerType] ?? null;
            if (!$datasyncType) {
                // Skip types that are handled by parent entity (but mark as synced)
                foreach ($pending[$trackerType] as $item) {
                    $syncedTrackerIds[] = $item['tracker_id'];
                }
                continue;
            }

            // Filter out already-processed items (deletes/orphans)
            $items = array_filter($pending[$trackerType], function ($item) use ($syncedTrackerIds) {
                return !in_array($item['tracker_id'], $syncedTrackerIds);
            });

            if (empty($items)) {
                continue;
            }

            $output->writeln("<info>Syncing {$trackerType} (" . count($items) . ' items)...</info>');

            // Stock uses efficient direct SQL (already batched internally)
            if ($datasyncType === 'stock') {
                foreach ($items as $item) {
                    try {
                        if ($this->syncStock((int) $item['entity_id'], $output)) {
                            $totalSynced++;
                            $syncedTrackerIds[] = $item['tracker_id'];
                        }
                    } catch (\Exception $e) {
                        $totalErrors++;
                        if ($output->isVerbose()) {
                            $output->writeln("  <error>Failed stock #{$item['entity_id']}: {$e->getMessage()}</error>");
                        }
                    }
                }
                continue;
            }

            // Batch sync: reuse a single adapter+engine per entity type
            $entityIds = array_column($items, 'entity_id');
            $trackerIdMap = [];
            foreach ($items as $item) {
                $trackerIdMap[$item['entity_id']] = $item['tracker_id'];
            }

            try {
                $result = $this->syncEntityBatch($datasyncType, $entityIds, $output);

                // Map successes back to tracker IDs
                // Only mark truly successful imports as synced - not validation-skipped records
                // (skipped records should be retried if source data is fixed)
                foreach ($result->getSuccesses() as $success) {
                    $sourceId = $success['source_id'];
                    $action = $success['action'] ?? '';
                    // Skip validation failures - they should not be marked synced
                    if ($action === 'skipped') {
                        $totalSkipped = ($totalSkipped ?? 0) + 1;
                        continue;
                    }
                    if (isset($trackerIdMap[$sourceId])) {
                        $syncedTrackerIds[] = $trackerIdMap[$sourceId];
                        $totalSynced++;
                    }
                }

                // Count errors
                $batchErrors = count($result->getErrors());
                $totalErrors += $batchErrors;

                if ($batchErrors > 0 && $output->isVerbose()) {
                    foreach ($result->getErrors() as $error) {
                        $output->writeln("  <error>Failed {$trackerType} #{$error['source_id']}: {$error['message']}</error>");
                    }
                }
            } catch (\Exception $e) {
                // Entire batch failed (connection error etc), fall back to one-by-one
                $output->writeln("  <comment>Batch sync failed, falling back to individual sync: {$e->getMessage()}</comment>");

                foreach ($items as $item) {
                    try {
                        $success = $this->syncEntitySingle($datasyncType, $item, $output);
                        if ($success) {
                            $totalSynced++;
                            $syncedTrackerIds[] = $item['tracker_id'];
                        }
                    } catch (\Exception $e2) {
                        $totalErrors++;
                        if ($output->isVerbose()) {
                            $output->writeln("  <error>Failed {$trackerType} #{$item['entity_id']}: {$e2->getMessage()}</error>");
                        }
                    }
                }
            }
        }

        // Mark as synced (batched)
        if ($markSynced && !empty($syncedTrackerIds)) {
            if ($this->markAsSynced($syncedTrackerIds, $output)) {
                $output->writeln('');
                $output->writeln('<info>Marked ' . count($syncedTrackerIds) . ' tracker records as synced.</info>');
            }
        }

        $output->writeln('');
        $output->writeln("<info>Synced: {$totalSynced}, Errors: {$totalErrors}</info>");

        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Acquire an exclusive lock to prevent concurrent runs
     *
     * Uses flock() which is automatically released if the process crashes.
     */
    protected function acquireLock(OutputInterface $output): bool
    {
        $lockFile = Mage::getBaseDir('var') . '/locks/datasync_incremental.lock';
        $lockDir = dirname($lockFile);

        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }

        $this->lockFileHandle = fopen($lockFile, 'c');
        if (!$this->lockFileHandle) {
            $output->writeln('<error>Could not create lock file.</error>');
            return false;
        }

        if (!flock($this->lockFileHandle, LOCK_EX | LOCK_NB)) {
            // Lock is held by another process — check how long it's been running
            $mtime = filemtime($lockFile);
            $age = $mtime ? time() - $mtime : 0;
            $ageStr = $age > 3600 ? sprintf('%dh%dm', intdiv($age, 3600), intdiv($age % 3600, 60)) : sprintf('%dm%ds', intdiv($age, 60), $age % 60);

            fclose($this->lockFileHandle);
            $this->lockFileHandle = null;

            $output->writeln("<error>Another datasync:incremental is already running (lock age: {$ageStr}).</error>");
            $output->writeln('<comment>Use --no-lock to override (not recommended).</comment>');
            return false;
        }

        // Write PID and start time to lock file for debugging
        ftruncate($this->lockFileHandle, 0);
        fwrite($this->lockFileHandle, json_encode([
            'pid' => getmypid(),
            'started_at' => date('Y-m-d H:i:s'),
            'command' => implode(' ', $_SERVER['argv'] ?? []),
        ]));
        fflush($this->lockFileHandle);

        // Touch the file so mtime reflects lock acquisition time
        touch($lockFile);

        return true;
    }

    /**
     * Release the lock
     */
    protected function releaseLock(): void
    {
        if ($this->lockFileHandle) {
            flock($this->lockFileHandle, LOCK_UN);
            fclose($this->lockFileHandle);
            $this->lockFileHandle = null;
        }
    }

    /**
     * Connect to live database
     */
    protected function connectToLive(InputInterface $input): PDO
    {
        $host = $input->getOption('db-host');
        $name = $input->getOption('db-name');
        $user = $input->getOption('db-user');
        $pass = $input->getOption('db-pass');

        // Try to load from .env.local if not provided
        if (!$name || !$user) {
            $envFile = Mage::getBaseDir() . '/.env.local';
            if (file_exists($envFile)) {
                $env = [];
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (str_starts_with($line, '#')) {
                        continue;
                    }
                    if (str_contains($line, '=')) {
                        [$k, $v] = explode('=', $line, 2);
                        $env[trim($k)] = trim($v);
                    }
                }
                $host = $host ?: ($env['DATASYNC_LIVE_HOST'] ?? 'localhost');
                $name = $name ?: ($env['DATASYNC_LIVE_DB'] ?? null);
                $user = $user ?: ($env['DATASYNC_LIVE_USER'] ?? null);
                $pass = $pass ?: ($env['DATASYNC_LIVE_PASS'] ?? null);
            }
        }

        if (!$name || !$user) {
            throw new \Exception('Database credentials not provided. Use --db-* options or set DATASYNC_LIVE_* in .env.local');
        }

        return new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }

    /**
     * Get pending changes from tracker table
     */
    protected function getPendingChanges(?string $entityFilter, ?int $limit): array
    {
        $sql = 'SELECT tracker_id, entity_type, entity_id, action, created_at
                FROM datasync_change_tracker
                WHERE sync_completed = 0';

        $params = [];
        if ($entityFilter) {
            $sql .= ' AND entity_type = :type';
            $params['type'] = $entityFilter;
        }

        $sql .= ' ORDER BY created_at ASC';

        if ($limit) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $stmt = $this->livePdo->prepare($sql);
        $stmt->execute($params);

        $changes = $stmt->fetchAll();

        // Group by entity type
        $grouped = [];
        foreach ($changes as $change) {
            $grouped[$change['entity_type']][] = $change;
        }

        return $grouped;
    }

    /**
     * Sync a batch of entities of the same type using a single adapter+engine
     *
     * Much faster than creating a new adapter+engine per entity because:
     * - Single Mage::app() bootstrap overhead
     * - Single adapter validation
     * - Reuses registry cache across entities
     * - Engine finishSync() runs once for all (e.g., configurable link processing)
     */
    protected function syncEntityBatch(
        string $entityType,
        array $entityIds,
        OutputInterface $output,
    ): \Maho_DataSync_Model_Result {
        /** @var \Maho_DataSync_Model_Adapter_OpenMage $adapter */
        $adapter = Mage::getModel('datasync/adapter_openmage');
        $adapter->setDatabaseConnection($this->livePdo);

        /** @var \Maho_DataSync_Model_Engine $engine */
        $engine = Mage::getModel('datasync/engine');
        $engine->setSourceAdapter($adapter)
            ->setSourceSystem('live')
            ->setOnDuplicate('merge')
            ->setSkipInvalid(true)
            ->setFilters(['entity_ids' => array_map('intval', $entityIds)]);

        if ($output->isVerbose()) {
            $engine->setVerbose(true);
            $engine->setProgressCallback(function (string $message) use ($output) {
                $output->writeln("  {$message}");
            });
        }

        return $engine->sync($entityType);
    }

    /**
     * Sync a single entity (fallback when batch fails)
     */
    protected function syncEntitySingle(
        string $entityType,
        array $trackerItem,
        OutputInterface $output,
    ): bool {
        $entityId = (int) $trackerItem['entity_id'];
        $action = $trackerItem['action'];

        if ($action === 'delete') {
            return true;
        }

        /** @var \Maho_DataSync_Model_Adapter_OpenMage $adapter */
        $adapter = Mage::getModel('datasync/adapter_openmage');
        $adapter->setDatabaseConnection($this->livePdo);
        $adapter->setEntityIdFilter($entityId);

        /** @var \Maho_DataSync_Model_Engine $engine */
        $engine = Mage::getModel('datasync/engine');
        $engine->setSourceAdapter($adapter)
            ->setSourceSystem('live')
            ->setOnDuplicate('merge')
            ->setSkipInvalid(true);

        $result = $engine->sync($entityType);

        if ($result->hasErrors()) {
            $errors = $result->getErrors();
            throw new \Exception($errors[0]['message'] ?? 'Unknown error');
        }

        return $result->getCreated() > 0 || $result->getMerged() > 0 || $result->getSkipped() > 0;
    }

    /**
     * Sync stock data directly (efficient)
     *
     * Matches products by SKU and updates stock records directly.
     */
    protected function syncStock(int $liveProductId, OutputInterface $output): bool
    {
        // Get live product SKU and stock data
        $stmt = $this->livePdo->prepare('
            SELECT p.sku, s.*
            FROM catalog_product_entity p
            JOIN cataloginventory_stock_item s ON s.product_id = p.entity_id
            WHERE p.entity_id = ?
        ');
        $stmt->execute([$liveProductId]);
        $liveData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$liveData) {
            // Product or stock doesn't exist on live (deleted?)
            return true; // Mark as handled
        }

        $sku = $liveData['sku'];

        // Find matching product on dev by SKU
        /** @var \Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $devRead = $resource->getConnection('core_read');
        $devWrite = $resource->getConnection('core_write');
        $productTable = $resource->getTableName('catalog/product');
        $stockTable = $resource->getTableName('cataloginventory/stock_item');

        $devProductId = $devRead->fetchOne(
            "SELECT entity_id FROM {$productTable} WHERE sku = ?",
            [$sku],
        );

        if (!$devProductId) {
            if ($output->isVeryVerbose()) {
                $output->writeln("    <comment>SKU not found on dev: {$sku}</comment>");
            }
            return true; // Can't sync, but not an error
        }

        // Prepare stock data
        $stockData = [
            'product_id' => $devProductId,
            'stock_id' => 1,
            'qty' => $liveData['qty'],
            'is_in_stock' => $liveData['is_in_stock'],
            'manage_stock' => $liveData['manage_stock'],
            'use_config_manage_stock' => $liveData['use_config_manage_stock'],
            'min_qty' => $liveData['min_qty'],
            'use_config_min_qty' => $liveData['use_config_min_qty'],
            'min_sale_qty' => $liveData['min_sale_qty'],
            'use_config_min_sale_qty' => $liveData['use_config_min_sale_qty'],
            'max_sale_qty' => $liveData['max_sale_qty'],
            'use_config_max_sale_qty' => $liveData['use_config_max_sale_qty'],
            'backorders' => $liveData['backorders'],
            'use_config_backorders' => $liveData['use_config_backorders'],
            'notify_stock_qty' => $liveData['notify_stock_qty'],
            'use_config_notify_stock_qty' => $liveData['use_config_notify_stock_qty'],
        ];

        // Check if stock record exists
        $existingItemId = $devRead->fetchOne(
            "SELECT item_id FROM {$stockTable} WHERE product_id = ?",
            [$devProductId],
        );

        if ($existingItemId) {
            $devWrite->update($stockTable, $stockData, "item_id = {$existingItemId}");
        } else {
            $devWrite->insert($stockTable, $stockData);
        }

        return true;
    }

    /**
     * Process delete actions and mark orphaned records
     *
     * When an entity is deleted, any pending updates for related entities
     * (e.g., stock updates for a deleted product) should be marked as synced.
     *
     * @return array Tracker IDs that were processed
     */
    protected function processDeletesAndOrphans(array $pending, OutputInterface $output): array
    {
        $processedIds = [];
        $deletedEntities = [];

        // First pass: collect all delete actions
        foreach ($pending as $entityType => $items) {
            foreach ($items as $item) {
                if ($item['action'] === 'delete') {
                    $deletedEntities[$entityType][$item['entity_id']] = true;
                    $processedIds[] = $item['tracker_id'];
                }
            }
        }

        if (!empty($deletedEntities)) {
            $deleteCount = count($processedIds);
            $output->writeln("<comment>Processing {$deleteCount} delete actions...</comment>");
        }

        // Second pass: find orphaned records (updates for deleted entities)
        // Stock updates for deleted products
        if (isset($deletedEntities['product']) && isset($pending['stock'])) {
            foreach ($pending['stock'] as $item) {
                if (isset($deletedEntities['product'][$item['entity_id']])) {
                    $processedIds[] = $item['tracker_id'];
                    if ($output->isVerbose()) {
                        $output->writeln("  Orphaned stock update for deleted product #{$item['entity_id']}");
                    }
                }
            }
        }

        // Also check if stock updates reference products that don't exist on live
        if (isset($pending['stock'])) {
            // Batch check for existence instead of one-by-one
            $stockItems = array_filter($pending['stock'], function ($item) use ($processedIds) {
                return !in_array($item['tracker_id'], $processedIds);
            });

            if (!empty($stockItems)) {
                $entityIds = array_column($stockItems, 'entity_id');
                $placeholders = implode(',', array_fill(0, count($entityIds), '?'));

                $stmt = $this->livePdo->prepare(
                    "SELECT entity_id FROM catalog_product_entity WHERE entity_id IN ({$placeholders})",
                );
                $stmt->execute($entityIds);
                $existingIds = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

                foreach ($stockItems as $item) {
                    if (!isset($existingIds[$item['entity_id']])) {
                        $processedIds[] = $item['tracker_id'];
                        if ($output->isVerbose()) {
                            $output->writeln("  Orphaned stock update for non-existent product #{$item['entity_id']}");
                        }
                    }
                }
            }
        }

        if (!empty($processedIds)) {
            $orphanCount = count($processedIds) - count(array_filter($deletedEntities, static fn(array $ids): bool => count($ids) > 0));
            if ($orphanCount > 0) {
                $output->writeln("<comment>Marked {$orphanCount} orphaned records for cleanup</comment>");
            }
        }

        return $processedIds;
    }

    /**
     * Mark tracker records as synced (batched)
     *
     * The unique index on (entity_type, entity_id, sync_completed) prevents having
     * multiple synced records for the same entity. When an entity changes again after
     * being synced, we need to delete the old synced record before marking the new one.
     *
     * @return bool True if update succeeded, false if readonly user
     */
    protected function markAsSynced(array $trackerIds, OutputInterface $output): bool
    {
        if (empty($trackerIds)) {
            return true;
        }

        try {
            // Batch: get all entity info for tracker records
            $chunks = array_chunk($trackerIds, 500);

            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));

                // Get entity info for all records in this chunk
                $stmt = $this->livePdo->prepare(
                    "SELECT tracker_id, entity_type, entity_id FROM datasync_change_tracker WHERE tracker_id IN ({$placeholders})",
                );
                $stmt->execute($chunk);
                $records = $stmt->fetchAll();

                if (empty($records)) {
                    continue;
                }

                // Build batched delete: remove old synced records for these entities
                // Group by entity_type for efficient deletion
                $byType = [];
                foreach ($records as $record) {
                    $byType[$record['entity_type']][] = $record;
                }

                $this->livePdo->beginTransaction();
                try {
                    foreach ($byType as $entityType => $typeRecords) {
                        $entityIds = array_column($typeRecords, 'entity_id');
                        $currentTrackerIds = array_column($typeRecords, 'tracker_id');

                        $entityPlaceholders = implode(',', array_fill(0, count($entityIds), '?'));
                        $trackerPlaceholders = implode(',', array_fill(0, count($currentTrackerIds), '?'));

                        // Delete old synced records for these entities (except current ones)
                        $deleteSql = "DELETE FROM datasync_change_tracker
                                      WHERE entity_type = ?
                                      AND entity_id IN ({$entityPlaceholders})
                                      AND sync_completed = 1
                                      AND tracker_id NOT IN ({$trackerPlaceholders})";

                        $deleteParams = array_merge([$entityType], $entityIds, $currentTrackerIds);
                        $stmt = $this->livePdo->prepare($deleteSql);
                        $stmt->execute($deleteParams);
                    }

                    // Now mark all records in this chunk as synced
                    $stmt = $this->livePdo->prepare(
                        "UPDATE datasync_change_tracker
                         SET synced_at = NOW(), sync_completed = 1
                         WHERE tracker_id IN ({$placeholders})",
                    );
                    $stmt->execute($chunk);

                    $this->livePdo->commit();
                } catch (PDOException $e) {
                    $this->livePdo->rollBack();
                    throw $e;
                }
            }
        } catch (PDOException $e) {
            // Check if this is a permission denied error
            if (
                str_contains($e->getMessage(), 'UPDATE command denied') ||
                str_contains($e->getMessage(), 'DELETE command denied') ||
                str_contains($e->getMessage(), '1142')
            ) {
                $output->writeln('');
                $output->writeln('<comment>Cannot mark records as synced - readonly database user.</comment>');
                $output->writeln('<comment>Grant UPDATE and DELETE on datasync_change_tracker table.</comment>');
                $output->writeln('');
                return false;
            }

            // Duplicate key error - handle gracefully
            if (
                str_contains($e->getMessage(), '1062') ||
                str_contains($e->getMessage(), 'Duplicate entry')
            ) {
                $output->writeln('<comment>Some records had duplicate key conflicts (handled).</comment>');
                return true;
            }

            throw $e;
        }

        return true;
    }
}
