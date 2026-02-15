<?php
/**
 * Maho DataSync Tracker install script
 *
 * Creates the datasync_change_tracker table for tracking entity changes.
 * This table is read by the DataSync module on the destination system.
 *
 * @category   Maho
 * @package    Maho_DataSyncTracker
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$tableName = $installer->getTable('datasync_change_tracker');

// Only create if doesn't exist
if (!$installer->getConnection()->isTableExists($tableName)) {
    $table = $installer->getConnection()
        ->newTable($tableName)
        ->addColumn('tracker_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'identity'  => true,
            'unsigned'  => true,
            'nullable'  => false,
            'primary'   => true,
        ), 'Tracker ID')
        ->addColumn('entity_type', Varien_Db_Ddl_Table::TYPE_VARCHAR, 50, array(
            'nullable'  => false,
        ), 'Entity Type (customer, order, invoice, shipment, creditmemo, product, category, stock, newsletter)')
        ->addColumn('entity_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'unsigned'  => true,
            'nullable'  => false,
        ), 'Entity ID')
        ->addColumn('action', Varien_Db_Ddl_Table::TYPE_VARCHAR, 10, array(
            'nullable'  => false,
            'default'   => 'update',
        ), 'Action (create/update/delete)')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
            'nullable'  => false,
        ), 'When the change occurred')
        ->addColumn('synced_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
            'nullable'  => true,
            'default'   => null,
        ), 'When the change was synced')
        ->addColumn('sync_completed', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array(
            'unsigned'  => true,
            'nullable'  => false,
            'default'   => 0,
        ), 'Sync Completed Flag (0=pending, 1=synced)')
        // Index for efficient pending query
        ->addIndex(
            $installer->getIdxName('datasync_change_tracker', array('sync_completed', 'entity_type')),
            array('sync_completed', 'entity_type')
        )
        // Index for entity lookup
        ->addIndex(
            $installer->getIdxName('datasync_change_tracker', array('entity_type', 'entity_id')),
            array('entity_type', 'entity_id')
        )
        // Index for cleanup query
        ->addIndex(
            $installer->getIdxName('datasync_change_tracker', array('created_at')),
            array('created_at')
        )
        // Unique index to prevent duplicate pending entries for same entity
        // Allows upsert pattern: INSERT ... ON DUPLICATE KEY UPDATE
        ->addIndex(
            $installer->getIdxName('datasync_change_tracker', array('entity_type', 'entity_id', 'sync_completed')),
            array('entity_type', 'entity_id', 'sync_completed'),
            array('type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE)
        )
        ->setComment('DataSync Change Tracker - tracks entity changes for incremental sync');

    $installer->getConnection()->createTable($table);

    Mage::log('Maho_DataSyncTracker: Created datasync_change_tracker table', Zend_Log::INFO, 'datasync.log');
}

$installer->endSetup();
