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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'datasync:stock',
    description: 'Bulk sync stock/inventory data from live database (efficient direct SQL)',
)]
class DatasyncStock extends Command
{
    protected ?PDO $livePdo = null;

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption(
                'sku',
                null,
                InputOption::VALUE_REQUIRED,
                'Sync only specific SKU pattern (supports % wildcard)',
            )
            ->addOption(
                'missing-only',
                null,
                InputOption::VALUE_NONE,
                'Only create stock records for products that don\'t have one',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be synced without making changes',
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

        $output->writeln('<info>DataSync: Bulk stock sync from live database</info>');
        $output->writeln('');

        // Connect to live database
        try {
            $this->livePdo = $this->connectToLive($input);
        } catch (\Exception $e) {
            $output->writeln("<error>Failed to connect to live database: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $skuPattern = $input->getOption('sku');
        $missingOnly = $input->getOption('missing-only');
        $dryRun = $input->getOption('dry-run');

        // Get dev database connection
        $devWrite = Mage::getSingleton('core/resource')->getConnection('core_write');
        $devRead = Mage::getSingleton('core/resource')->getConnection('core_read');
        $stockTable = Mage::getSingleton('core/resource')->getTableName('cataloginventory/stock_item');
        $productTable = Mage::getSingleton('core/resource')->getTableName('catalog/product');

        // Build query for live stock data
        $sql = '
            SELECT
                p.sku,
                p.entity_id as live_product_id,
                s.qty,
                s.is_in_stock,
                s.manage_stock,
                s.use_config_manage_stock,
                s.min_qty,
                s.use_config_min_qty,
                s.min_sale_qty,
                s.use_config_min_sale_qty,
                s.max_sale_qty,
                s.use_config_max_sale_qty,
                s.backorders,
                s.use_config_backorders,
                s.notify_stock_qty,
                s.use_config_notify_stock_qty
            FROM catalog_product_entity p
            JOIN cataloginventory_stock_item s ON s.product_id = p.entity_id
        ';

        $params = [];
        if ($skuPattern) {
            $sql .= ' WHERE p.sku LIKE :sku';
            $params['sku'] = $skuPattern;
        }

        $sql .= ' ORDER BY p.sku';

        $stmt = $this->livePdo->prepare($sql);
        $stmt->execute($params);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $notFound = 0;
        $startTime = microtime(true);

        while ($liveStock = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sku = $liveStock['sku'];

            // Find matching product on dev by SKU
            $devProductId = $devRead->fetchOne(
                "SELECT entity_id FROM {$productTable} WHERE sku = ?",
                [$sku],
            );

            if (!$devProductId) {
                $notFound++;
                if ($output->isVerbose()) {
                    $output->writeln("  <comment>SKU not found on dev: {$sku}</comment>");
                }
                continue;
            }

            // Check if stock record exists on dev
            $existingStock = $devRead->fetchOne(
                "SELECT item_id FROM {$stockTable} WHERE product_id = ?",
                [$devProductId],
            );

            if ($existingStock && $missingOnly) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                if ($existingStock) {
                    $output->writeln("  Would update: {$sku} (dev ID: {$devProductId})");
                    $updated++;
                } else {
                    $output->writeln("  Would create: {$sku} (dev ID: {$devProductId})");
                    $created++;
                }
                continue;
            }

            // Prepare stock data
            $stockData = [
                'product_id' => $devProductId,
                'stock_id' => 1,
                'qty' => $liveStock['qty'],
                'is_in_stock' => $liveStock['is_in_stock'],
                'manage_stock' => $liveStock['manage_stock'],
                'use_config_manage_stock' => $liveStock['use_config_manage_stock'],
                'min_qty' => $liveStock['min_qty'],
                'use_config_min_qty' => $liveStock['use_config_min_qty'],
                'min_sale_qty' => $liveStock['min_sale_qty'],
                'use_config_min_sale_qty' => $liveStock['use_config_min_sale_qty'],
                'max_sale_qty' => $liveStock['max_sale_qty'],
                'use_config_max_sale_qty' => $liveStock['use_config_max_sale_qty'],
                'backorders' => $liveStock['backorders'],
                'use_config_backorders' => $liveStock['use_config_backorders'],
                'notify_stock_qty' => $liveStock['notify_stock_qty'],
                'use_config_notify_stock_qty' => $liveStock['use_config_notify_stock_qty'],
            ];

            if ($existingStock) {
                // Update existing
                $devWrite->update($stockTable, $stockData, "item_id = {$existingStock}");
                $updated++;
            } else {
                // Insert new
                $devWrite->insert($stockTable, $stockData);
                $created++;
            }

            // Progress every 500 records
            $total = $created + $updated + $skipped;
            if ($total % 500 === 0) {
                $elapsed = microtime(true) - $startTime;
                $speed = $elapsed > 0 ? round($total / $elapsed, 1) : 0;
                $output->writeln("  Progress: {$total} records ({$speed}/sec)");
            }
        }

        $elapsed = round(microtime(true) - $startTime, 2);

        $output->writeln('');
        $output->writeln("<info>Completed in {$elapsed}s</info>");
        $output->writeln("  Created: {$created}");
        $output->writeln("  Updated: {$updated}");
        $output->writeln("  Skipped: {$skipped}");
        $output->writeln("  Not found on dev: {$notFound}");

        return Command::SUCCESS;
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
}
