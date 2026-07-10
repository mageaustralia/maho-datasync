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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Repoint historical product_id foreign keys at the products they actually mean.
 *
 * DataSync stamps every imported product with `datasync_source_id`, the entity_id
 * it held in the source catalogue. It does not, however, rewrite the product_id
 * columns in tables copied across alongside it. Order history therefore keeps
 * referencing source entity_ids, and because the catalogue is re-imported with
 * fresh ids, those numbers now denote *different* products. Sales get credited to
 * whatever product happens to occupy the old id.
 *
 * Everything that joins history to the catalogue by product_id inherits the error:
 * ordered-quantity search ranking, bestseller reports, recommendations, cross-sells.
 *
 * This command rebuilds the source_id -> entity_id map from `datasync_source_id`
 * and rewrites the affected columns. It previews by default; pass --apply to write.
 *
 * Take a database backup first. This edits historical sales data.
 */
#[AsCommand(
    name: 'datasync:remap-product-ids',
    description: 'Repoint product_id columns from source entity_ids to local ones (preview by default)',
)]
class DatasyncRemapProductIds extends Command
{
    /**
     * Phase 1 parks rewritten values above this offset so a mapping whose source
     * id equals another product's local id cannot cascade onto rows this run has
     * already touched. Phase 2 subtracts it. Must exceed every real product id.
     */
    private const OFFSET = 1_000_000;

    private const MARKER_PATH = 'datasync/remap/product_ids_applied_at';

    /** @var array<string, string> table => column holding a catalog product id */
    private const TABLES = [
        'sales_flat_order_item' => 'product_id',
        'sales_flat_quote_item' => 'product_id',
        'wishlist_item' => 'product_id',
        'report_viewed_product_index' => 'product_id',
        'report_compared_product_index' => 'product_id',
    ];

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the changes. Without this, preview only')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Apply even though a previous run is recorded')
            ->addOption('tables', null, InputOption::VALUE_REQUIRED, 'Comma-separated subset of tables to touch')
            ->addOption('include-report-event', null, InputOption::VALUE_NONE, 'Also remap report_event.object_id (see notes)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Mage::app();
        $conn = Mage::getSingleton('core/resource')->getConnection('core_write');

        $apply = (bool) $input->getOption('apply');
        $previous = Mage::getStoreConfig(self::MARKER_PATH);

        if ($apply && $previous && !$input->getOption('force')) {
            $output->writeln("<error>Already applied on {$previous}.</error>");
            $output->writeln('Re-running would remap ids a second time. Pass --force only if you know the');
            $output->writeln('previous run did not complete.');
            return Command::FAILURE;
        }

        $map = $this->buildMap($conn);
        if ($map === []) {
            $output->writeln('<info>Nothing to do: every product already sits on its source id.</info>');
            return Command::SUCCESS;
        }

        $maxLocalId = (int) $conn->fetchOne('SELECT MAX(entity_id) FROM ' . $conn->quoteIdentifier('catalog_product_entity'));
        if ($maxLocalId >= self::OFFSET) {
            $output->writeln('<error>Product ids reach ' . $maxLocalId . ', which collides with the parking offset.</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Mappings where the source id differs from the local id: <info>%d</info>', count($map)));
        $output->writeln('');

        $tables = $this->tablesToProcess($input);
        $totalAffected = 0;

        foreach ($tables as $table => $column) {
            if (!$this->tableExists($conn, $table)) {
                $output->writeln(sprintf('  %-32s <comment>absent, skipped</comment>', $table));
                continue;
            }
            $affected = $this->countAffected($conn, $table, $column, $map);
            $totalAffected += $affected;
            $rows = (int) $conn->fetchOne('SELECT COUNT(*) FROM ' . $conn->quoteIdentifier($table));
            $output->writeln(sprintf('  %-32s %-10s rows, %s would change', $table, $rows, $affected));
        }

        $output->writeln('');
        $output->writeln(sprintf('Rows to rewrite: <info>%d</info>', $totalAffected));

        if (!$apply) {
            $output->writeln('');
            $output->writeln('<comment>Preview only. Re-run with --apply to write. Back up the database first.</comment>');
            return Command::SUCCESS;
        }

        foreach ($tables as $table => $column) {
            if (!$this->tableExists($conn, $table)) {
                continue;
            }
            $written = $this->remap($conn, $table, $column, $map);
            $output->writeln(sprintf('  %-32s rewrote %d row(s)', $table, $written));
        }

        Mage::getModel('core/config')->saveConfig(self::MARKER_PATH, Mage_Core_Model_Locale::nowUtc(), 'default', 0);
        $output->writeln('');
        $output->writeln('<info>Done.</info> Reindex Meilisearch so the corrected ordered_qty reaches the index.');
        return Command::SUCCESS;
    }

    /**
     * source entity_id => local entity_id, for products whose id moved.
     *
     * @return array<int, int>
     */
    private function buildMap(\Maho\Db\Adapter\AbstractPdoAdapter $conn): array
    {
        $attributeId = (int) $conn->fetchOne(
            'SELECT attribute_id FROM eav_attribute WHERE attribute_code = ? AND entity_type_id = 4',
            ['datasync_source_id'],
        );
        if (!$attributeId) {
            return [];
        }

        $select = $conn->select()
            ->from(['i' => 'catalog_product_entity_int'], ['source_id' => 'i.value'])
            ->join(['e' => 'catalog_product_entity'], 'e.entity_id = i.entity_id', ['local_id' => 'e.entity_id'])
            ->where('i.attribute_id = ?', $attributeId)
            ->where('i.store_id = ?', 0)
            ->where('i.value IS NOT NULL')
            ->where('i.value <> e.entity_id');

        $map = [];
        foreach ($conn->fetchAll($select) as $row) {
            // A source id that resolved to two local products would make the
            // rewrite ambiguous; refuse rather than guess.
            $source = (int) $row['source_id'];
            if (isset($map[$source])) {
                throw new \RuntimeException("datasync_source_id {$source} maps to more than one product");
            }
            $map[$source] = (int) $row['local_id'];
        }
        return $map;
    }

    /** @return array<string, string> */
    private function tablesToProcess(InputInterface $input): array
    {
        $tables = self::TABLES;
        if ($input->getOption('include-report-event')) {
            // object_id only means a product for product-scoped event types, so
            // this stays opt-in.
            $tables['report_event'] = 'object_id';
        }
        $only = $input->getOption('tables');
        if ($only) {
            $wanted = array_map('trim', explode(',', (string) $only));
            $tables = array_intersect_key($tables, array_flip($wanted));
        }
        return $tables;
    }

    private function tableExists(\Maho\Db\Adapter\AbstractPdoAdapter $conn, string $table): bool
    {
        try {
            $conn->fetchOne('SELECT 1 FROM ' . $conn->quoteIdentifier($table) . ' LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<int, int> $map */
    private function countAffected(\Maho\Db\Adapter\AbstractPdoAdapter $conn, string $table, string $column, array $map): int
    {
        $quotedTable = $conn->quoteIdentifier($table);
        $quotedColumn = $conn->quoteIdentifier($column);
        $total = 0;
        foreach (array_chunk(array_keys($map), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $total += (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM {$quotedTable} WHERE {$quotedColumn} IN ({$placeholders})",
                $chunk,
            );
        }
        return $total;
    }

    /**
     * Two phases so a mapping cannot cascade onto rows an earlier mapping in the
     * same run already rewrote: park every new value above OFFSET, then drop it
     * back down. Portable: no UPDATE ... JOIN.
     *
     * @param array<int, int> $map
     */
    private function remap(\Maho\Db\Adapter\AbstractPdoAdapter $conn, string $table, string $column, array $map): int
    {
        $quotedTable = $conn->quoteIdentifier($table);
        $quotedColumn = $conn->quoteIdentifier($column);
        $written = 0;

        $conn->beginTransaction();
        try {
            foreach ($map as $sourceId => $localId) {
                $written += $conn->update(
                    $table,
                    [$column => $localId + self::OFFSET],
                    [$quotedColumn . ' = ?' => $sourceId],
                );
            }
            $conn->query(
                "UPDATE {$quotedTable} SET {$quotedColumn} = {$quotedColumn} - ? WHERE {$quotedColumn} > ?",
                [self::OFFSET, self::OFFSET],
            );
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        return $written;
    }
}
