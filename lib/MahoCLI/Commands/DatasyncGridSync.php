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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Grid Sync Command
 *
 * Updates extended grid table columns (e.g., ShipEasy fields) for imported orders.
 * This command should be run after DataSync imports to populate grid columns
 * that are normally filled by observers during normal order creation.
 */
#[AsCommand(
    name: 'datasync:grid:sync',
    description: 'Update extended grid fields (ShipEasy, etc.) for imported orders',
)]
class DatasyncGridSync extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'id-from',
                null,
                InputOption::VALUE_REQUIRED,
                'Only update orders with entity_id >= this value',
            )
            ->addOption(
                'id-to',
                null,
                InputOption::VALUE_REQUIRED,
                'Only update orders with entity_id <= this value',
            )
            ->addOption(
                'increment-id',
                'i',
                InputOption::VALUE_REQUIRED,
                'Update specific order by increment_id',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be updated without making changes',
            )
            ->addOption(
                'fields',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of fields to sync (default: all configured)',
            )
            ->setHelp(
                <<<'HELP'
The <info>datasync:grid:sync</info> command updates extended grid table fields for imported orders.

This is useful after running datasync:sync to populate fields that are normally
set by observers during order creation (e.g., ShipEasy columns like szy_country,
szy_payment_method, etc.).

<info>Usage:</info>

  # Sync all extended fields for orders imported after entity_id 267031
  <info>./maho datasync:grid:sync --id-from=267031</info>

  # Sync only specific fields
  <info>./maho datasync:grid:sync --id-from=267031 --fields=szy_country,szy_payment_method</info>

  # Sync a specific order
  <info>./maho datasync:grid:sync --increment-id=100207613</info>

  # Preview what would be updated
  <info>./maho datasync:grid:sync --id-from=267031 --dry-run</info>
HELP,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Bootstrap Mage
        Mage::app('admin');

        $idFrom = $input->getOption('id-from');
        $idTo = $input->getOption('id-to');
        $incrementId = $input->getOption('increment-id');
        $dryRun = $input->getOption('dry-run');
        $fieldsOption = $input->getOption('fields');

        $adapter = Mage::getSingleton('core/resource')->getConnection('core_write');
        $gridTable = Mage::getSingleton('core/resource')->getTableName('sales/order_grid');

        // Build WHERE clause
        $where = [];
        if ($incrementId) {
            $where[] = $adapter->quoteInto('g.increment_id = ?', $incrementId);
        } else {
            if ($idFrom) {
                $where[] = $adapter->quoteInto('g.entity_id >= ?', (int) $idFrom);
            }
            if ($idTo) {
                $where[] = $adapter->quoteInto('g.entity_id <= ?', (int) $idTo);
            }
        }

        if (empty($where)) {
            $output->writeln('<error>Please specify --id-from, --id-to, or --increment-id</error>');
            return Command::FAILURE;
        }

        $whereClause = implode(' AND ', $where);

        // Get field mappings from configuration
        $mappings = $this->getFieldMappings();

        // Filter to requested fields if specified
        if ($fieldsOption) {
            $requestedFields = array_map('trim', explode(',', $fieldsOption));
            $mappings = array_filter($mappings, fn($k) => in_array($k, $requestedFields), ARRAY_FILTER_USE_KEY);
        }

        if (empty($mappings)) {
            $output->writeln('<info>No field mappings configured. Using defaults.</info>');
            $mappings = $this->getDefaultMappings();
        }

        $output->writeln(sprintf('<info>Syncing %d fields...</info>', count($mappings)));

        // Group updates by source table for efficiency
        $updateGroups = [];
        foreach ($mappings as $field => $config) {
            $sourceKey = $config['source_table'] . '|' . ($config['join_condition'] ?? '');
            if (!isset($updateGroups[$sourceKey])) {
                $updateGroups[$sourceKey] = [
                    'table' => $config['source_table'],
                    'join_condition' => $config['join_condition'] ?? null,
                    'fields' => [],
                ];
            }
            $updateGroups[$sourceKey]['fields'][$field] = $config['source_column'];
        }

        $totalUpdated = 0;

        foreach ($updateGroups as $group) {
            $result = $this->syncFieldGroup($adapter, $gridTable, $group, $whereClause, $dryRun, $output);
            $totalUpdated += $result;
        }

        if ($dryRun) {
            $output->writeln(sprintf('<info>[DRY-RUN] Would update %d rows</info>', $totalUpdated));
        } else {
            $output->writeln(sprintf('<info>Updated %d rows</info>', $totalUpdated));
        }

        return Command::SUCCESS;
    }

    /**
     * Get field mappings from configuration
     */
    protected function getFieldMappings(): array
    {
        $config = Mage::getConfig()->getNode('global/datasync/grid_sync/order');
        if (!$config) {
            return [];
        }

        $mappings = [];
        foreach ($config->children() as $field => $fieldConfig) {
            $mappings[$field] = [
                'source_table' => (string) $fieldConfig->source_table,
                'source_column' => (string) $fieldConfig->source_column,
                'join_condition' => (string) $fieldConfig->join_condition ?: null,
            ];
        }

        return $mappings;
    }

    /**
     * Get default field mappings for ShipEasy and common extensions
     */
    protected function getDefaultMappings(): array
    {
        return [
            // From shipping address
            'szy_country' => [
                'source_table' => 'sales/order_address',
                'source_column' => 'country_id',
                'join_condition' => "address_type = 'shipping'",
            ],
            'szy_region' => [
                'source_table' => 'sales/order_address',
                'source_column' => 'region',
                'join_condition' => "address_type = 'shipping'",
            ],
            'szy_postcode' => [
                'source_table' => 'sales/order_address',
                'source_column' => 'postcode',
                'join_condition' => "address_type = 'shipping'",
            ],
            'szy_customer_name' => [
                'source_table' => 'sales/order_address',
                'source_column' => "CONCAT(COALESCE(firstname, ''), ' ', COALESCE(lastname, ''))",
                'join_condition' => "address_type = 'shipping'",
            ],
            // From payment
            'szy_payment_method' => [
                'source_table' => 'sales/order_payment',
                'source_column' => 'method',
                'join_condition' => null,
            ],
            'payment_method' => [
                'source_table' => 'sales/order_payment',
                'source_column' => 'method',
                'join_condition' => null,
            ],
            // From order
            'szy_shipping_method' => [
                'source_table' => 'sales/order',
                'source_column' => 'shipping_method',
                'join_condition' => null,
            ],
            'szy_shipping_description' => [
                'source_table' => 'sales/order',
                'source_column' => 'shipping_description',
                'join_condition' => null,
            ],
            // Taos Connector / Netsuite
            'netsuite_id' => [
                'source_table' => 'sales/order',
                'source_column' => 'netsuite_id',
                'join_condition' => null,
            ],
        ];
    }

    /**
     * Sync a group of fields from the same source table
     */
    protected function syncFieldGroup(
        \Maho\Db\Adapter\AdapterInterface $adapter,
        string $gridTable,
        array $group,
        string $whereClause,
        bool $dryRun,
        OutputInterface $output
    ): int {
        $sourceTable = Mage::getSingleton('core/resource')->getTableName($group['table']);

        // Build SET clause
        $setClauses = [];
        foreach ($group['fields'] as $gridField => $sourceColumn) {
            // Handle expressions vs simple columns
            if (str_contains($sourceColumn, '(') || str_contains($sourceColumn, ' ')) {
                $setClauses[] = "g.{$gridField} = {$sourceColumn}";
            } else {
                $setClauses[] = "g.{$gridField} = src.{$sourceColumn}";
            }
        }

        // Build JOIN condition
        $joinOn = 'g.entity_id = src.parent_id';
        if ($group['table'] === 'sales/order') {
            $joinOn = 'g.entity_id = src.entity_id';
        }
        if ($group['join_condition']) {
            $joinOn .= ' AND src.' . $group['join_condition'];
        }

        $sql = "
            UPDATE {$gridTable} g
            JOIN {$sourceTable} src ON {$joinOn}
            SET " . implode(', ', $setClauses) . "
            WHERE {$whereClause}
        ";

        if ($dryRun) {
            // Count affected rows
            $countSql = "
                SELECT COUNT(*) FROM {$gridTable} g
                JOIN {$sourceTable} src ON {$joinOn}
                WHERE {$whereClause}
            ";
            $count = (int) $adapter->fetchOne($countSql);
            $output->writeln(sprintf('  [DRY-RUN] %s: %d rows', $group['table'], $count));
            return $count;
        }

        $stmt = $adapter->query($sql);
        $rowCount = $stmt->rowCount();
        $output->writeln(sprintf('  %s: %d rows updated', $group['table'], $rowCount));

        return $rowCount;
    }
}
