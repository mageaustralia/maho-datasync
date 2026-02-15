<?php

/**
 * Maho DataSync Registry Resource Model
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Resource_Registry extends Mage_Core_Model_Resource_Db_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('datasync/registry', 'registry_id');
    }

    /**
     * Delete all mappings for a source system
     *
     * @param string $sourceSystem Source system identifier
     * @param string|null $entityType Optional entity type filter
     * @return int Number of rows deleted
     */
    public function deleteBySourceSystem(string $sourceSystem, ?string $entityType = null): int
    {
        $adapter = $this->_getWriteAdapter();
        $where = ['source_system = ?' => $sourceSystem];

        if ($entityType !== null) {
            $where['entity_type = ?'] = $entityType;
        }

        return $adapter->delete($this->getMainTable(), $where);
    }

    /**
     * Bulk insert mappings (more efficient than individual saves)
     *
     * @param array<array{source_system: string, entity_type: string, source_id: int, target_id: int, external_ref?: string|null}> $mappings
     * @return int Number of rows inserted
     */
    public function bulkInsert(array $mappings): int
    {
        if (empty($mappings)) {
            return 0;
        }

        $adapter = $this->_getWriteAdapter();
        $now = Mage_Core_Model_Locale::now();

        $data = [];
        foreach ($mappings as $mapping) {
            $data[] = [
                'source_system' => $mapping['source_system'],
                'entity_type' => $mapping['entity_type'],
                'source_id' => $mapping['source_id'],
                'target_id' => $mapping['target_id'],
                'external_ref' => $mapping['external_ref'] ?? null,
                'synced_at' => $now,
                'metadata' => isset($mapping['metadata']) ? json_encode($mapping['metadata']) : null,
            ];
        }

        return $adapter->insertOnDuplicate(
            $this->getMainTable(),
            $data,
            ['target_id', 'external_ref', 'synced_at', 'metadata'],
        );
    }
}
