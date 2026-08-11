<?php

/**
 * MageAustralia DataSync
 *
 * @package    MahoCLI
 * @copyright  Copyright (c) 2026 MageAustralia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoCLI\Commands;

use Mage;
use Mage_Core_Model_Locale;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Align dev order STATUS to live.
 *
 * The incremental sync relies on the live tracker (TW_DataSyncTracker), which only fires on
 * sales_order_save_after — so status changes that bypass the model save (StarShipit API,
 * bulk-grid mass updates, direct SQL) never create a tracker row and dev's status goes stale.
 * This reconciles by comparing dev to live directly.
 *
 * Deliberately status-only: state-only differences (e.g. live's complete/processing quirk that
 * the importer normalizes to complete/complete) are left alone — the admin grid keys on status.
 * Updates BOTH sales_flat_order (status + state) AND sales_flat_order_grid (status): direct SQL
 * does not reindex the grid. Dev-native test orders have no live increment_id match and are
 * skipped. Every applied change is appended to var/log/status-reconcile-audit.log.
 */
#[AsCommand(
    name: 'datasync:reconcile-statuses',
    description: 'Align dev order status to live (catches status changes the incremental tracker misses)',
)]
class DatasyncReconcileStatuses extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write changes (default is a dry-run report)')
            ->addOption('months', null, InputOption::VALUE_REQUIRED, 'Only orders live-updated in the last N months (0 = all)', '0');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Mage::app('admin');

        $apply  = (bool) $input->getOption('apply');
        $months = (int) $input->getOption('months');

        $env = [];
        $envFile = Mage::getBaseDir() . '/.env.local';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $env[trim($k)] = trim($v);
            }
        }
        if (empty($env['DATASYNC_LIVE_DB']) || empty($env['DATASYNC_LIVE_USER'])) {
            $output->writeln('<error>Missing DATASYNC_LIVE_* in .env.local</error>');
            return Command::FAILURE;
        }

        try {
            $live = new PDO(
                'mysql:host=' . ($env['DATASYNC_LIVE_HOST'] ?? 'localhost') . ";dbname={$env['DATASYNC_LIVE_DB']};charset=utf8mb4",
                $env['DATASYNC_LIVE_USER'],
                $env['DATASYNC_LIVE_PASS'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10],
            );
        } catch (\Throwable $e) {
            $output->writeln('<error>Live DB connection failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $resource = Mage::getSingleton('core/resource');
        $read     = $resource->getConnection('core_read');
        $write    = $resource->getConnection('core_write');
        $orderT   = $resource->getTableName('sales/order');
        $gridT    = $resource->getTableName('sales/order_grid');

        $where  = 'increment_id IS NOT NULL';
        $params = [];
        if ($months > 0) {
            $where .= ' AND updated_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)';
            $params[] = $months;
        }
        $ls = $live->prepare("SELECT increment_id, status, state FROM sales_flat_order WHERE {$where}");
        $ls->execute($params);
        $liveRows = $ls->fetchAll();
        $output->writeln('Live orders in scope: ' . count($liveRows) . ($months ? " (last {$months} mo)" : ' (all)'));

        $fh = null;
        if ($apply) {
            $fh = fopen(Mage::getBaseDir('var') . '/log/status-reconcile-audit.log', 'a');
        }

        $checked = 0;
        $drift   = 0;
        $updated = 0;
        $by      = [];
        $batch   = 2000;

        for ($i = 0, $n = count($liveRows); $i < $n; $i += $batch) {
            $map = [];
            foreach (array_slice($liveRows, $i, $batch) as $r) {
                $map[$r['increment_id']] = $r;
            }
            $inList  = implode(',', array_map(fn($x) => $read->quote($x), array_keys($map)));
            $devRows = $read->fetchAll("SELECT increment_id, status, state FROM {$orderT} WHERE increment_id IN ({$inList})");
            foreach ($devRows as $d) {
                $checked++;
                $l = $map[$d['increment_id']];
                if ((string) $d['status'] === (string) $l['status']) {
                    continue; // status matches — leave state-only differences alone
                }
                $drift++;
                $key = "{$d['status']} -> {$l['status']}";
                $by[$key] = ($by[$key] ?? 0) + 1;
                if ($apply && $fh !== false) {
                    fwrite($fh, Mage_Core_Model_Locale::nowUtc() . "\t{$d['increment_id']}\t{$d['status']}/{$d['state']}\t=>\t{$l['status']}/{$l['state']}\n");
                    $write->update($orderT, ['status' => $l['status'], 'state' => $l['state']], ['increment_id = ?' => $d['increment_id']]);
                    $write->update($gridT, ['status' => $l['status']], ['increment_id = ?' => $d['increment_id']]);
                    $updated++;
                }
            }
        }
        if (is_resource($fh)) {
            fclose($fh);
        }

        $output->writeln(($apply ? '<info>APPLIED</info>' : '<comment>DRY-RUN (no writes)</comment>'));
        $output->writeln("  checked: {$checked}   status-drift: {$drift}   updated: {$updated}");
        arsort($by);
        foreach ($by as $k => $v) {
            $output->writeln("    {$v}x  {$k}");
        }

        return Command::SUCCESS;
    }
}
