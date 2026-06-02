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
 * Restores the `options_container` attribute on dev from live (by SKU).
 *
 * Maho gates whether a product's options render on the PDP entirely on `options_container`
 * ("Display Product Options In"). A DataSync run cleared it on ~8.8k products, so their
 * option dropdowns / custom options vanished from the storefront. Live holds the correct
 * per-product value (mostly `container1`), so we copy it back.
 *
 * Only fills dev products whose value is currently EMPTY (won't clobber values already set),
 * unless --overwrite is given. Idempotent + dry-run-able.
 */
#[AsCommand(
    name: 'datasync:options-container',
    description: 'Restore options_container on dev from live (re-shows hidden PDP options)',
)]
class DatasyncOptionsContainer extends Command
{
    protected ?PDO $livePdo = null;

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Also overwrite dev values that differ from live (default: only fill empties)')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'Live database host')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'Live database name')
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'Live database user')
            ->addOption('db-pass', null, InputOption::VALUE_REQUIRED, 'Live database password');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Mage::app('admin');

        $dryRun = (bool) $input->getOption('dry-run');
        $overwrite = (bool) $input->getOption('overwrite');
        $output->writeln('<info>DataSync: restore options_container from live</info>');
        $output->writeln($dryRun ? '<comment>DRY RUN — no changes will be written</comment>' : '<comment>LIVE WRITE mode</comment>');
        $output->writeln('');

        try {
            $this->livePdo = $this->connectToLive($input);
        } catch (\Exception $e) {
            $output->writeln("<error>Failed to connect to live: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $res = Mage::getSingleton('core/resource');
        $devRead = $res->getConnection('core_read');
        $tProduct = $res->getTableName('catalog/product');
        $tVarchar = $tProduct . '_varchar';

        $devAttrId = (int) $devRead->fetchOne(
            "SELECT attribute_id FROM {$res->getTableName('eav/attribute')} a
             JOIN {$res->getTableName('eav/entity_type')} t ON t.entity_type_id=a.entity_type_id
             WHERE a.attribute_code='options_container' AND t.entity_type_code='catalog_product'",
        );

        // Live: sku -> options_container value (default scope, non-empty)
        $sql = "
            SELECT e.sku, v.value
            FROM catalog_product_entity_varchar v
            JOIN catalog_product_entity e ON e.entity_id = v.entity_id
            WHERE v.store_id = 0
              AND v.attribute_id = (
                  SELECT a.attribute_id FROM eav_attribute a
                  JOIN eav_entity_type t ON t.entity_type_id=a.entity_type_id
                  WHERE a.attribute_code='options_container' AND t.entity_type_code='catalog_product')
              AND v.value IS NOT NULL AND v.value <> ''";
        $liveBySku = [];
        foreach ($this->livePdo->query($sql) as $r) {
            $liveBySku[$r['sku']] = $r['value'];
        }
        $output->writeln('Live products with a value: ' . count($liveBySku));

        // Group dev product IDs by the target value we will set
        $byValue = [];
        $stats = ['live_values' => count($liveBySku), 'sku_not_on_dev' => 0, 'already_ok' => 0, 'to_set' => 0];

        foreach ($liveBySku as $sku => $value) {
            $devId = (int) $devRead->fetchOne("SELECT entity_id FROM {$tProduct} WHERE sku = ?", [$sku]);
            if (!$devId) {
                $stats['sku_not_on_dev']++;
                continue;
            }
            $current = (string) $devRead->fetchOne(
                "SELECT value FROM {$tVarchar} WHERE entity_id = ? AND attribute_id = ? AND store_id = 0",
                [$devId, $devAttrId],
            );
            if ($current === $value) {
                $stats['already_ok']++;
                continue;
            }
            if ($current !== '' && !$overwrite) {
                $stats['already_ok']++; // has some value; not overwriting unless asked
                continue;
            }
            $byValue[$value][] = $devId;
            $stats['to_set']++;
        }

        $output->writeln('');
        $output->writeln('<info>Will set:</info>');
        foreach ($byValue as $value => $ids) {
            $output->writeln(sprintf('  %-14s %d products', $value, count($ids)));
        }

        if (!$dryRun) {
            foreach ($byValue as $value => $ids) {
                foreach (array_chunk($ids, 1000) as $chunk) {
                    Mage::getSingleton('catalog/product_action')->updateAttributes($chunk, ['options_container' => $value], 0);
                }
            }
        }

        $output->writeln('');
        $output->writeln('<info>Summary</info>');
        foreach ($stats as $k => $v) {
            $output->writeln(sprintf('  %-18s %d', $k, $v));
        }
        if ($dryRun) {
            $output->writeln('');
            $output->writeln('<comment>Dry run only — re-run without --dry-run to apply.</comment>');
        }

        return Command::SUCCESS;
    }

    protected function connectToLive(InputInterface $input): PDO
    {
        $host = $input->getOption('db-host');
        $name = $input->getOption('db-name');
        $user = $input->getOption('db-user');
        $pass = $input->getOption('db-pass');

        if (!$name || !$user) {
            $envFile = Mage::getBaseDir() . '/.env.local';
            if (file_exists($envFile)) {
                $env = [];
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                        continue;
                    }
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

        return new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }
}
