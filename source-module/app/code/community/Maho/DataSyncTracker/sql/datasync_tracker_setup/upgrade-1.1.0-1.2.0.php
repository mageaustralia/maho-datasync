<?php
/**
 * Maho DataSync Tracker upgrade 1.1.0 -> 1.2.0
 *
 * Adds source_identifier column to datasync_change_tracker.
 *
 * Background:
 *   delete events on the source fire AFTER the source row is gone, so the
 *   destination can no longer look the entity up on live by entity_id when
 *   it tries to mirror the delete (live's entity_id is dangling). Storing
 *   a portable identifier at the moment the delete observer fires (SKU
 *   for products, email for customers, name for categories) lets the
 *   destination find and delete the matching row by SKU/email instead.
 *
 * @category   Maho
 * @package    Maho_DataSyncTracker
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$conn  = $installer->getConnection();
$table = $installer->getTable('datasync_change_tracker');

if (!$conn->tableColumnExists($table, 'source_identifier')) {
    $conn->addColumn($table, 'source_identifier', [
        'type'     => Varien_Db_Ddl_Table::TYPE_VARCHAR,
        'length'   => 255,
        'nullable' => true,
        'default'  => null,
        'comment'  => 'Portable identifier (SKU/email/etc) captured at delete time so the destination can resolve the entity by something stable',
    ]);
}

// Composite index so the destination's "find tracker rows for this SKU" lookup
// stays cheap as the tracker grows.
$idxName = $installer->getIdxName(
    'datasync_change_tracker',
    ['entity_type', 'source_identifier'],
);
if (!$conn->isTableExists($table) || !in_array($idxName, array_keys($conn->getIndexList($table)), true)) {
    $conn->addIndex(
        $table,
        $idxName,
        ['entity_type', 'source_identifier'],
    );
}

Mage::log('Maho_DataSyncTracker: added source_identifier column + index', Zend_Log::INFO, 'datasync.log');

$installer->endSetup();
