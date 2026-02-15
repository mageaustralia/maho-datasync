<?php

/**
 * Maho DataSync Install Script
 *
 * Creates the entity registry and delta state tracking tables.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */

/** @var Mage_Core_Model_Resource_Setup $this */
/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();

/**
 * Create table 'datasync_entity_registry'
 *
 * This table maps source system entity IDs to target (Maho) entity IDs.
 * Used for foreign key resolution when importing related entities.
 *
 * Example: Source customer #5 -> Maho customer #1042
 */
$registryTable = $installer->getTable('datasync/registry');

if (!$connection->isTableExists($registryTable)) {
    $table = $connection->newTable($registryTable)
        ->addColumn('registry_id', \Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'identity' => true,
            'unsigned' => true,
            'nullable' => false,
            'primary'  => true,
        ], 'Registry ID')
        ->addColumn('source_system', \Maho\Db\Ddl\Table::TYPE_VARCHAR, 50, [
            'nullable' => false,
        ], 'Source System Identifier (e.g., legacy_store, pos, woocommerce)')
        ->addColumn('entity_type', \Maho\Db\Ddl\Table::TYPE_VARCHAR, 50, [
            'nullable' => false,
        ], 'Entity Type (e.g., customer, order, review)')
        ->addColumn('source_id', \Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => false,
        ], 'Original Entity ID in Source System')
        ->addColumn('target_id', \Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => false,
        ], 'New Entity ID in Maho')
        ->addColumn('external_ref', \Maho\Db\Ddl\Table::TYPE_VARCHAR, 255, [
            'nullable' => true,
        ], 'External Reference (e.g., email, increment_id, sku)')
        ->addColumn('synced_at', \Maho\Db\Ddl\Table::TYPE_DATETIME, null, [
            'nullable' => false,
        ], 'Timestamp When Record Was Synced')
        ->addColumn('metadata', \Maho\Db\Ddl\Table::TYPE_TEXT, '64k', [
            'nullable' => true,
        ], 'Additional Metadata (JSON)')
        ->addIndex(
            $installer->getIdxName('datasync/registry', ['source_system', 'entity_type', 'source_id']),
            ['source_system', 'entity_type', 'source_id'],
            ['type' => \Maho\Db\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE],
        )
        ->addIndex(
            $installer->getIdxName('datasync/registry', ['entity_type', 'target_id']),
            ['entity_type', 'target_id'],
        )
        ->addIndex(
            $installer->getIdxName('datasync/registry', ['external_ref']),
            ['external_ref'],
        )
        ->addIndex(
            $installer->getIdxName('datasync/registry', ['synced_at']),
            ['synced_at'],
        )
        ->setComment('DataSync Entity ID Mapping Registry');

    $connection->createTable($table);
}

/**
 * Create table 'datasync_delta_state'
 *
 * Tracks the last sync state for each source system and entity type.
 * Used for delta sync to only import new/changed records.
 */
$deltaTable = $installer->getTable('datasync/delta');

if (!$connection->isTableExists($deltaTable)) {
    $table = $connection->newTable($deltaTable)
        ->addColumn('state_id', \Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'identity' => true,
            'unsigned' => true,
            'nullable' => false,
            'primary'  => true,
        ], 'State ID')
        ->addColumn('source_system', \Maho\Db\Ddl\Table::TYPE_VARCHAR, 50, [
            'nullable' => false,
        ], 'Source System Identifier')
        ->addColumn('entity_type', \Maho\Db\Ddl\Table::TYPE_VARCHAR, 50, [
            'nullable' => false,
        ], 'Entity Type')
        ->addColumn('adapter_code', \Maho\Db\Ddl\Table::TYPE_VARCHAR, 50, [
            'nullable' => false,
            'default'  => 'csv',
        ], 'Adapter Code Used')
        ->addColumn('last_sync_at', \Maho\Db\Ddl\Table::TYPE_DATETIME, null, [
            'nullable' => false,
        ], 'Last Successful Sync Timestamp')
        ->addColumn('last_entity_id', \Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => true,
        ], 'Highest Entity ID Synced')
        ->addColumn('last_updated_at', \Maho\Db\Ddl\Table::TYPE_DATETIME, null, [
            'nullable' => true,
        ], 'Last updated_at Value Synced')
        ->addColumn('sync_count', \Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => false,
            'default'  => 0,
        ], 'Total Records Synced')
        ->addColumn('error_count', \Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => false,
            'default'  => 0,
        ], 'Total Errors Encountered')
        ->addColumn('last_error', \Maho\Db\Ddl\Table::TYPE_TEXT, '64k', [
            'nullable' => true,
        ], 'Last Error Message')
        ->addColumn('config_hash', \Maho\Db\Ddl\Table::TYPE_VARCHAR, 64, [
            'nullable' => true,
        ], 'Hash of Configuration (to detect config changes)')
        ->addIndex(
            $installer->getIdxName('datasync/delta', ['source_system', 'entity_type']),
            ['source_system', 'entity_type'],
            ['type' => \Maho\Db\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE],
        )
        ->setComment('DataSync Delta State Tracking');

    $connection->createTable($table);
}

$installer->endSetup();

Mage::log('Maho_DataSync: Created datasync_entity_registry and datasync_delta_state tables', null, 'datasync.log');
