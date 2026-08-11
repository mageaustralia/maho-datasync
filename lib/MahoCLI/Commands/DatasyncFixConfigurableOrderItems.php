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
 * Repoint configurable order rows that were imported onto their own child.
 *
 * On a configurable order there are two rows, and the parent row stores the
 * CHILD's sku while pointing at the PARENT product:
 *
 *   parent_item_id NULL   product_type configurable   product_id 30347   sku 232026-4 3/8
 *   parent_item_id 566539 product_type simple         product_id 30355   sku 232026-4 3/8
 *
 * DataSync used to resolve an imported order item's product_id by sku. Since both
 * rows carry the same sku, both landed on the child, and the configurable parent
 * -- the product customers actually search and browse -- was credited with none of
 * its own sales. Ordered-quantity ranking, bestseller reports, recommendations and
 * cross-sells all read these rows.
 *
 * The importer now resolves through `datasync_source_id` (see
 * Maho_DataSync_Model_Entity_Order::_resolveLocalProductId). This command
 * backfills orders imported before that fix.
 *
 * A row is only rewritten when all of the following hold, so the corrected value
 * is never a guess:
 *
 *   - it is a parent row      (parent_item_id IS NULL)
 *   - it claims to be a configurable  (product_type = 'configurable')
 *   - its product_id is a simple that is a child of EXACTLY ONE configurable
 *
 * Rows whose product_id already points at a configurable are correct and are left
 * alone. Rows whose child has zero or several configurable parents are reported,
 * never rewritten. Rows pointing at a product no longer in the catalogue cannot be
 * resolved from local data and are reported too.
 *
 * Note this reads nothing but the local catalogue: it needs no source connection
 * and makes no assumption about how an order arrived. Correctness is decided by
 * catalog_product_super_link, not by provenance.
 *
 * Take a database backup first. This edits historical sales data.
 */
#[AsCommand(
    name: 'datasync:fix-configurable-order-items',
    description: 'Repoint configurable parent order rows from their child onto the parent (preview by default)',
)]
class DatasyncFixConfigurableOrderItems extends Command
{
    private const MARKER_PATH = 'datasync/remap/configurable_order_items_fixed_at';

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
            $output->writeln('Pass --force only if you know the previous run did not complete.');
            return Command::FAILURE;
        }

        [$fix, $ambiguous, $missing, $correct] = $this->classify($conn);

        $output->writeln(sprintf('Configurable parent rows already correct : <info>%d</info>', $correct));
        $output->writeln(sprintf('Rows pointing at their own child (fixable): <info>%d</info>', count($fix)));

        if ($ambiguous !== []) {
            $output->writeln(sprintf(
                '<comment>%d row(s) point at a simple with zero or several configurable parents; skipped:</comment>',
                count($ambiguous),
            ));
            foreach (array_slice($ambiguous, 0, 20) as $itemId => $productId) {
                $output->writeln(sprintf('  item %d -> product %d', $itemId, $productId));
            }
        }
        if ($missing > 0) {
            $output->writeln(sprintf(
                '<comment>%d row(s) point at a product no longer in the catalogue; cannot be resolved locally.</comment>',
                $missing,
            ));
        }

        if ($fix === []) {
            $output->writeln('<info>Nothing to rewrite.</info>');
            return Command::SUCCESS;
        }

        if (!$apply) {
            $output->writeln('');
            $output->writeln('<comment>Preview only. Re-run with --apply to write. Back up the database first.</comment>');
            return Command::SUCCESS;
        }

        $written = $this->rewrite($conn, $fix);
        $output->writeln(sprintf('Rewrote <info>%d</info> row(s) in sales_flat_order_item.', $written));

        Mage::getModel('core/config')->saveConfig(self::MARKER_PATH, \Mage_Core_Model_Locale::nowUtc(), 'default', 0);
        $output->writeln('');
        $output->writeln('<info>Done.</info> Reindex so the corrected ordered_qty reaches the search index.');
        return Command::SUCCESS;
    }

    /**
     * Sort every configurable parent row into fixable / ambiguous / unresolvable / correct.
     *
     * @return array{0: array<int, int>, 1: array<int, int>, 2: int, 3: int}
     *         [item_id => new product_id], [item_id => product_id], missing, correct
     */
    private function classify(\Maho\Db\Adapter\AbstractPdoAdapter $conn): array
    {
        $type = [];
        foreach ($conn->fetchAll('SELECT entity_id, type_id FROM catalog_product_entity') as $row) {
            $type[(int) $row['entity_id']] = $row['type_id'];
        }

        /** @var array<int, array<int, true>> $parentsOf */
        $parentsOf = [];
        foreach ($conn->fetchAll('SELECT product_id, parent_id FROM catalog_product_super_link') as $row) {
            $parentsOf[(int) $row['product_id']][(int) $row['parent_id']] = true;
        }

        $fix = [];
        $ambiguous = [];
        $missing = 0;
        $correct = 0;

        $lastId = 0;
        while (true) {
            $rows = $conn->fetchAll(
                "SELECT item_id, product_id FROM sales_flat_order_item
                 WHERE parent_item_id IS NULL AND product_type = 'configurable' AND item_id > ?
                 ORDER BY item_id LIMIT 20000",
                [$lastId],
            );
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $lastId = (int) $row['item_id'];
                if ($row['product_id'] === null) {
                    $missing++;
                    continue;
                }
                $productId = (int) $row['product_id'];

                if (!isset($type[$productId])) {
                    $missing++;
                    continue;
                }
                if ($type[$productId] === \Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
                    $correct++;
                    continue;
                }

                $parents = array_keys($parentsOf[$productId] ?? []);
                if (count($parents) === 1) {
                    $fix[$lastId] = $parents[0];
                } else {
                    $ambiguous[$lastId] = $productId;
                }
            }
        }

        return [$fix, $ambiguous, $missing, $correct];
    }

    /** @param array<int, int> $fix item_id => new product_id */
    private function rewrite(\Maho\Db\Adapter\AbstractPdoAdapter $conn, array $fix): int
    {
        $written = 0;
        $conn->beginTransaction();
        try {
            foreach ($fix as $itemId => $productId) {
                $written += $conn->update(
                    'sales_flat_order_item',
                    ['product_id' => $productId],
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
}
