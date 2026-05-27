<?php

/**
 * Maho
 *
 * @package    MahoCLI
 * @copyright  Copyright (c) 2026 Maho (https://mahocommerce.com)
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

/**
 * Polling-based catch-up for products the live change-tracker missed.
 *
 * Compares dev and live `catalog_product_entity.updated_at` per SKU and reports
 * (dry-run) or re-syncs (not yet implemented) products where live is newer than
 * dev by more than --threshold days. Catches anything that bypassed the live
 * Maho_DataSyncTracker observers (direct SQL writes, imports, etc.).
 *
 * Without `--dry-run`, syncs each stale product via the same engine the
 * incremental cron uses (`datasync/engine` + `datasync/adapter_openmage`,
 * `on_duplicate=merge`, `entity_ids` filter), bypassing the live tracker.
 */
#[AsCommand(
    name: 'datasync:resync-stale',
    description: 'Find/resync dev products whose live updated_at is newer (dry-run preview)',
)]
class DatasyncResyncStale extends Command
{
    protected ?PDO $livePdo = null;

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Days stale to flag (default 7)', '7')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter dev type: configurable | simple | all (default all)', 'all')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max stale products to report', '500')
            ->addOption('sku', null, InputOption::VALUE_REQUIRED, 'SKU LIKE pattern (e.g. KSM%)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview only — no writes')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Engine sync batch size (default 50)', '50')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'Live database host')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'Live database name')
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'Live database user')
            ->addOption('db-pass', null, InputOption::VALUE_REQUIRED, 'Live database password');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Mage::app('admin');

        $thresholdDays = (int) $input->getOption('threshold');
        $typeFilter = (string) $input->getOption('type');
        $limit = (int) $input->getOption('limit');
        $skuPattern = $input->getOption('sku');
        $dryRun = (bool) $input->getOption('dry-run');

        $output->writeln('<info>DataSync: stale-product catch-up scan</info>');
        $output->writeln(sprintf('threshold=%dd  type=%s  limit=%d  sku=%s', $thresholdDays, $typeFilter, $limit, $skuPattern ?: '(any)'));
        $output->writeln($dryRun
            ? '<comment>DRY RUN — discovery only, no writes</comment>'
            : '<comment>APPLY mode — will sync stale products via the engine (entity_ids filter)</comment>');
        $output->writeln('');

        try {
            $this->livePdo = $this->connectToLive($input);
        } catch (\Exception $e) {
            $output->writeln("<error>Failed to connect to live: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $res = Mage::getSingleton('core/resource');
        $devDb = $res->getConnection('core_read');
        $tProduct = $res->getTableName('catalog/product');

        // --- 1. discovery: live and dev product snapshots ---
        $sql = 'SELECT entity_id, sku, type_id, updated_at FROM catalog_product_entity';
        if ($skuPattern) $sql .= ' WHERE sku LIKE ' . $this->livePdo->quote($skuPattern);
        $live = [];
        foreach ($this->livePdo->query($sql) as $r) {
            $live[$r['sku']] = ['id' => (int) $r['entity_id'], 'type' => $r['type_id'], 'upd' => $r['upd'] ?? $r['updated_at']];
        }
        $devRows = $devDb->fetchAll("SELECT entity_id, sku, type_id, updated_at FROM {$tProduct}" . ($skuPattern ? " WHERE sku LIKE " . $devDb->quote($skuPattern) : ''));

        $stale = [];
        foreach ($devRows as $d) {
            $l = $live[$d['sku']] ?? null;
            if (!$l) continue;
            if ($typeFilter !== 'all' && $d['type_id'] !== $typeFilter) continue;
            $delta = (strtotime($l['upd']) - strtotime($d['updated_at'])) / 86400;
            if ($delta > $thresholdDays) {
                $stale[] = [
                    'sku' => $d['sku'],
                    'dev_id' => (int) $d['entity_id'],
                    'live_id' => $l['id'],
                    'type' => $d['type_id'],
                    'delta' => round($delta, 1),
                    'dev_upd' => $d['updated_at'],
                    'live_upd' => $l['upd'],
                ];
            }
        }
        usort($stale, fn($a, $b) => $b['delta'] <=> $a['delta']);
        $stale = array_slice($stale, 0, $limit);

        if (!$stale) {
            $output->writeln('<info>No stale products found above threshold.</info>');
            return Command::SUCCESS;
        }

        // --- 2. resolve registry mappings (sanity check) ---
        $registry = Mage::getModel('datasync/registry');
        $liveIds = array_column($stale, 'live_id');
        $regMap = $registry->resolveMany('live', 'product', $liveIds);
        $registryMatch = 0; $registryMismatch = 0; $registryMissing = 0;
        foreach ($stale as $s) {
            $mapped = $regMap[$s['live_id']] ?? null;
            if ($mapped === null) $registryMissing++;
            elseif ($mapped === $s['dev_id']) $registryMatch++;
            else $registryMismatch++;
        }

        // --- 3. batch fetch status + image counts on both sides ---
        $devStatus = $this->batchAttrInt($devDb, $tProduct, array_column($stale, 'dev_id'), 'status');
        $devImages = $this->batchImageCount($devDb, $res, array_column($stale, 'dev_id'));
        $liveStatus = $this->liveAttrInt(array_column($stale, 'live_id'), 'status');
        $liveImages = $this->liveImageCount(array_column($stale, 'live_id'));

        // --- 4. classify "what would change" ---
        $diffs = ['status_differs' => 0, 'images_missing_on_dev' => 0, 'images_count_differs' => 0];
        $byType = [];
        foreach ($stale as &$s) {
            $ds = $devStatus[$s['dev_id']] ?? null;
            $ls = $liveStatus[$s['live_id']] ?? null;
            $di = $devImages[$s['dev_id']] ?? 0;
            $li = $liveImages[$s['live_id']] ?? 0;
            $s['dev_status'] = $ds; $s['live_status'] = $ls;
            $s['dev_imgs'] = $di; $s['live_imgs'] = $li;
            if ($ds !== null && $ls !== null && (int) $ds !== (int) $ls) $diffs['status_differs']++;
            if ($di === 0 && $li > 0) $diffs['images_missing_on_dev']++;
            elseif ($di !== $li) $diffs['images_count_differs']++;
            $byType[$s['type']] = ($byType[$s['type']] ?? 0) + 1;
        }
        unset($s);

        // --- 5. output ---
        $output->writeln(sprintf('<info>%d stale products</info>', count($stale)));
        $output->writeln('  by dev type: ' . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($byType), $byType)));
        $output->writeln(sprintf('  registry: %d match, %d mismatch, %d missing', $registryMatch, $registryMismatch, $registryMissing));
        $output->writeln('  would change: ' . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($diffs), $diffs)));
        $output->writeln('');
        $output->writeln(sprintf('%-22s %8s %8s %12s %4s %4s %4s %4s %8s', 'SKU', 'LIVE_ID', 'DEV_ID', 'TYPE', 'L_ST', 'D_ST', 'L_IM', 'D_IM', 'DELTA_D'));
        $output->writeln(str_repeat('-', 96));
        foreach (array_slice($stale, 0, 25) as $s) {
            $output->writeln(sprintf(
                '%-22s %8d %8d %12s %4s %4s %4d %4d %8.1f',
                substr($s['sku'], 0, 22), $s['live_id'], $s['dev_id'], $s['type'],
                $s['live_status'] ?? '?', $s['dev_status'] ?? '?', $s['live_imgs'], $s['dev_imgs'], $s['delta'],
            ));
        }
        if (count($stale) > 25) $output->writeln(sprintf('  ... (%d more)', count($stale) - 25));

        if ($dryRun) {
            $output->writeln('');
            $output->writeln('<comment>Dry run only — re-run without --dry-run to apply.</comment>');
            return Command::SUCCESS;
        }

        // --- 6. apply: sync via the engine (same path as datasync:incremental) ---
        $output->writeln('');
        $output->writeln('<info>Applying re-sync via engine (source_system=live, on_duplicate=merge)</info>');

        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $liveIds = array_map('intval', array_column($stale, 'live_id'));
        $chunks = array_chunk($liveIds, $batchSize);
        $created = 0; $merged = 0; $skipped = 0; $errs = 0;
        $startedAt = microtime(true);

        foreach ($chunks as $i => $chunkIds) {
            $output->writeln(sprintf('  batch %d/%d (%d ids)…', $i + 1, count($chunks), count($chunkIds)));
            try {
                /** @var \Maho_DataSync_Model_Adapter_OpenMage $adapter */
                $adapter = Mage::getModel('datasync/adapter_openmage');
                $adapter->setDatabaseConnection($this->livePdo);

                /** @var \Maho_DataSync_Model_Engine $engine */
                $engine = Mage::getModel('datasync/engine');
                $engine->setSourceAdapter($adapter)
                    ->setSourceSystem('live')
                    ->setOnDuplicate('merge')
                    ->setSkipInvalid(true)
                    ->setFilters(['entity_ids' => $chunkIds]);

                $result = $engine->sync('product');
                $created += $result->getCreated();
                $merged += $result->getMerged();
                $skipped += $result->getSkipped();
                if ($result->hasErrors()) {
                    foreach ($result->getErrors() as $err) {
                        $errs++;
                        $output->writeln('    <error>' . ($err['message'] ?? 'error') . '</error>');
                    }
                }
            } catch (\Throwable $e) {
                $errs++;
                $output->writeln('  <error>batch failed: ' . $e->getMessage() . '</error>');
            }
        }
        $elapsed = round(microtime(true) - $startedAt, 1);
        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Sync complete in %ss — created=%d merged=%d skipped=%d errors=%d</info>',
            $elapsed, $created, $merged, $skipped, $errs,
        ));

        return Command::SUCCESS;
    }

    protected function batchAttrInt($conn, string $tProduct, array $ids, string $attrCode): array
    {
        if (!$ids) return [];
        $attrId = (int) $conn->fetchOne(
            "SELECT a.attribute_id FROM eav_attribute a JOIN eav_entity_type t ON t.entity_type_id=a.entity_type_id WHERE a.attribute_code=? AND t.entity_type_code='catalog_product'",
            [$attrCode],
        );
        $rows = $conn->fetchAll("SELECT entity_id, value FROM {$tProduct}_int WHERE attribute_id=? AND store_id=0 AND entity_id IN (" . implode(',', array_map('intval', $ids)) . ')', [$attrId]);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['entity_id']] = (int) $r['value'];
        return $out;
    }

    protected function batchImageCount($conn, $res, array $ids): array
    {
        if (!$ids) return [];
        $t = $res->getTableName('catalog/product_attribute_media_gallery');
        $rows = $conn->fetchAll("SELECT entity_id, COUNT(*) c FROM {$t} WHERE entity_id IN (" . implode(',', array_map('intval', $ids)) . ') GROUP BY entity_id');
        $out = [];
        foreach ($rows as $r) $out[(int) $r['entity_id']] = (int) $r['c'];
        return $out;
    }

    protected function liveAttrInt(array $ids, string $attrCode): array
    {
        if (!$ids) return [];
        $stmt = $this->livePdo->prepare("SELECT attribute_id FROM eav_attribute a JOIN eav_entity_type t ON t.entity_type_id=a.entity_type_id WHERE a.attribute_code=? AND t.entity_type_code='catalog_product'");
        $stmt->execute([$attrCode]);
        $aid = (int) $stmt->fetchColumn();
        $in = implode(',', array_map('intval', $ids));
        $rows = $this->livePdo->query("SELECT entity_id, value FROM catalog_product_entity_int WHERE attribute_id=$aid AND store_id=0 AND entity_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['entity_id']] = (int) $r['value'];
        return $out;
    }

    protected function liveImageCount(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_map('intval', $ids));
        $rows = $this->livePdo->query("SELECT entity_id, COUNT(*) c FROM catalog_product_entity_media_gallery WHERE entity_id IN ($in) GROUP BY entity_id")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['entity_id']] = (int) $r['c'];
        return $out;
    }

    protected function connectToLive(InputInterface $input): PDO
    {
        $host = $input->getOption('db-host'); $name = $input->getOption('db-name');
        $user = $input->getOption('db-user'); $pass = $input->getOption('db-pass');
        if (!$name || !$user) {
            $envFile = Mage::getBaseDir() . '/.env.local';
            if (file_exists($envFile)) {
                $env = [];
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
                    [$k, $v] = explode('=', $line, 2);
                    $env[trim($k)] = trim($v, " \t\"'");
                }
                $host = $host ?: ($env['DATASYNC_LIVE_HOST'] ?? 'localhost');
                $name = $name ?: ($env['DATASYNC_LIVE_DB'] ?? null);
                $user = $user ?: ($env['DATASYNC_LIVE_USER'] ?? null);
                $pass = $pass ?: ($env['DATASYNC_LIVE_PASS'] ?? null);
            }
        }
        if (!$name || !$user) {
            throw new \Exception('Live DB credentials missing. Use --db-* or set DATASYNC_LIVE_* in .env.local');
        }
        return new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
}
