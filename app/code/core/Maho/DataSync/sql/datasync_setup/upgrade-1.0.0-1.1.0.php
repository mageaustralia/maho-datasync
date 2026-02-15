<?php

/**
 * Maho DataSync Upgrade Script 1.0.0 -> 1.1.0
 *
 * Adds DataSync tracking columns to sales tables for invoice, shipment, and creditmemo imports.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */

/** @var Mage_Core_Model_Resource_Setup $this */
/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();

// Tables that need DataSync tracking columns
$tablesToUpdate = [
    'sales_flat_invoice',
    'sales_flat_shipment',
    'sales_flat_creditmemo',
];

$columnsToAdd = [
    'datasync_source_system' => [
        'type' => \Maho\Db\Ddl\Table::TYPE_VARCHAR,
        'length' => 50,
        'nullable' => true,
        'comment' => 'DataSync Source System',
    ],
    'datasync_source_id' => [
        'type' => \Maho\Db\Ddl\Table::TYPE_INTEGER,
        'nullable' => true,
        'unsigned' => true,
        'comment' => 'Original Entity ID in Source System',
    ],
    'datasync_source_increment_id' => [
        'type' => \Maho\Db\Ddl\Table::TYPE_VARCHAR,
        'length' => 50,
        'nullable' => true,
        'comment' => 'Original Increment ID in Source System',
    ],
    'datasync_imported_at' => [
        'type' => \Maho\Db\Ddl\Table::TYPE_DATETIME,
        'nullable' => true,
        'comment' => 'DataSync Import Timestamp',
    ],
];

foreach ($tablesToUpdate as $tableName) {
    $table = $installer->getTable($tableName);

    if (!$connection->isTableExists($table)) {
        continue;
    }

    foreach ($columnsToAdd as $columnName => $columnDef) {
        if (!$connection->tableColumnExists($table, $columnName)) {
            $connection->addColumn(
                $table,
                $columnName,
                [
                    'type' => $columnDef['type'],
                    'length' => $columnDef['length'] ?? null,
                    'nullable' => $columnDef['nullable'],
                    'unsigned' => $columnDef['unsigned'] ?? false,
                    'comment' => $columnDef['comment'],
                ],
            );

            Mage::log("Maho_DataSync: Added {$columnName} to {$tableName}", null, 'datasync.log');
        }
    }

    // Add index on datasync_source_system and datasync_source_id
    $indexName = $installer->getIdxName($tableName, ['datasync_source_system', 'datasync_source_id']);
    $indexes = $connection->getIndexList($table);

    if (!isset($indexes[strtoupper($indexName)])) {
        $connection->addIndex(
            $table,
            $indexName,
            ['datasync_source_system', 'datasync_source_id'],
        );
    }
}

$installer->endSetup();

Mage::log('Maho_DataSync: Added datasync tracking columns to sales_flat_invoice, sales_flat_shipment, sales_flat_creditmemo', null, 'datasync.log');
