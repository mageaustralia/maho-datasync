<?php

/**
 * Maho DataSync Delta Resource Model
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Resource_Delta extends Mage_Core_Model_Resource_Db_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('datasync/delta', 'state_id');
    }

    /**
     * Load by source system and entity type
     */
    public function loadBySourceAndType(
        Maho_DataSync_Model_Delta $object,
        string $sourceSystem,
        string $entityType,
    ): self {
        $adapter = $this->_getReadAdapter();
        $select = $adapter->select()
            ->from($this->getMainTable())
            ->where('source_system = ?', $sourceSystem)
            ->where('entity_type = ?', $entityType)
            ->limit(1);

        $data = $adapter->fetchRow($select);

        if ($data) {
            $object->setData($data);
        }

        return $this;
    }

    /**
     * Reset delta state for a source system
     *
     * @param string $sourceSystem Source system identifier
     * @param string|null $entityType Optional entity type filter
     * @return int Number of rows reset
     */
    public function resetState(string $sourceSystem, ?string $entityType = null): int
    {
        $adapter = $this->_getWriteAdapter();

        $bind = [
            'last_sync_at' => null,
            'last_entity_id' => null,
            'last_updated_at' => null,
            'sync_count' => 0,
            'error_count' => 0,
            'last_error' => null,
        ];

        $where = ['source_system = ?' => $sourceSystem];

        if ($entityType !== null) {
            $where['entity_type = ?'] = $entityType;
        }

        return $adapter->update($this->getMainTable(), $bind, $where);
    }

    /**
     * Delete all delta states for a source system
     *
     * @param string $sourceSystem Source system identifier
     * @return int Number of rows deleted
     */
    public function deleteBySourceSystem(string $sourceSystem): int
    {
        $adapter = $this->_getWriteAdapter();
        return $adapter->delete($this->getMainTable(), ['source_system = ?' => $sourceSystem]);
    }
}
