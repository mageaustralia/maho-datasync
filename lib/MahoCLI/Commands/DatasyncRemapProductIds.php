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
 * and rewrites `sales_flat_order_item.product_id`. It previews by default; pass
 * --apply to write.
 *
 * ONLY rows DataSync imported are touched, identified by their order carrying a
 * `datasync_source_id`. That restriction is the whole safety argument. A row this
 * installation created itself already references a local entity_id, and remapping
 * it would move a correct reference onto a different product: `sales_flat_quote_item`,
 * `wishlist_item` and `report_viewed_product_index` are written by ordinary browsing
 * and checkout here, so they are deliberately out of scope. Do not add them.
 *
 * Take a database backup first. This edits historical sales data.
 */
#[AsCommand(
    name: 'datasync:remap-product-ids',
    description: 'Repoint product_id columns from source entity_ids to local ones (preview by default)',
)]
class DatasyncRemapProductIds extends Command
{
    private const MARKER_PATH = 'datasync/remap/product_ids_applied_at';

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the changes. Without this, preview only')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Apply even though a previous run is recorded');
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

        $output->writeln(sprintf('Mappings where the source id differs from the local id: <info>%d</info>', count($map)));

        $itemIds = $this->collectImportedItemIds($conn, $map);
        $skipped = $this->countLocallyCreated($conn, $map);

        $output->writeln(sprintf('Order items DataSync imported that point at a stale id: <info>%d</info>', count($itemIds)));
        if ($skipped > 0) {
            $output->writeln(sprintf(
                '<comment>Leaving %d row(s) alone: their order carries no datasync_source_id, so DataSync</comment>',
                $skipped,
            ));
            $output->writeln('<comment>did not import them and their product_id is not assumed to be a source id.</comment>');
        }

        if ($itemIds === []) {
            $output->writeln('<info>Nothing to rewrite.</info>');
            return Command::SUCCESS;
        }

        if (!$apply) {
            $output->writeln('');
            $output->writeln('<comment>Preview only. Re-run with --apply to write. Back up the database first.</comment>');
            return Command::SUCCESS;
        }

        $written = $this->remapItems($conn, $itemIds, $map);
        $output->writeln(sprintf('Rewrote <info>%d</info> row(s) in sales_flat_order_item.', $written));

        Mage::getModel('core/config')->saveConfig(self::MARKER_PATH, Mage_Core_Model_Locale::nowUtc(), 'default', 0);
        $output->writeln('');
        $output->writeln('<info>Done.</info> Reindex Meilisearch so the corrected ordered_qty reaches the index.');
        return Command::SUCCESS;
    }

    /**
     * item_id list for imported order items whose product_id is a stale source id.
     *
     * Scoped through sales_flat_order.datasync_source_id. Only an imported order is
     * known to carry source entity_ids; for anything else the product_id means
     * whatever it meant when the row was written, and rewriting it would be a guess.
     *
     * @param array<int, int> $map
     * @return array<int, int>
     */
    private function collectImportedItemIds(\Maho\Db\Adapter\AbstractPdoAdapter $conn, array $map): array
    {
        $ids = [];
        foreach (array_chunk(array_keys($map), 500) as $chunk) {
            $select = $conn->select()
                ->from(['oi' => 'sales_flat_order_item'], ['item_id', 'product_id'])
                ->join(['o' => 'sales_flat_order'], 'o.entity_id = oi.order_id', [])
                ->where('o.datasync_source_id IS NOT NULL')
                ->where('oi.product_id IN (?)', $chunk);
            foreach ($conn->fetchAll($select) as $row) {
                $ids[(int) $row['item_id']] = (int) $row['product_id'];
            }
        }
        return $ids;
    }

    /** @param array<int, int> $map */
    private function countLocallyCreated(\Maho\Db\Adapter\AbstractPdoAdapter $conn, array $map): int
    {
        $total = 0;
        foreach (array_chunk(array_keys($map), 500) as $chunk) {
            $select = $conn->select()
                ->from(['oi' => 'sales_flat_order_item'], ['n' => 'COUNT(*)'])
                ->join(['o' => 'sales_flat_order'], 'o.entity_id = oi.order_id', [])
                ->where('o.datasync_source_id IS NULL')
                ->where('oi.product_id IN (?)', $chunk);
            $total += (int) $conn->fetchOne($select);
        }
        return $total;
    }

    /**
     * Rewrite by item_id, so scoping is exact and a mapping cannot cascade onto a
     * row an earlier mapping already moved.
     *
     * @param array<int, int> $itemIds item_id => current (stale) product_id
     * @param array<int, int> $map
     */
    private function remapItems(\Maho\Db\Adapter\AbstractPdoAdapter $conn, array $itemIds, array $map): int
    {
        $written = 0;
        $conn->beginTransaction();
        try {
            foreach ($itemIds as $itemId => $staleId) {
                $written += $conn->update(
                    'sales_flat_order_item',
                    ['product_id' => $map[$staleId]],
                    ['item_id = ?' => $itemId],
                );
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
        return $written;
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
}
