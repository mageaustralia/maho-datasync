<?php

/**
 * Maho DataSync Registry Collection
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Resource_Registry_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('datasync/registry');
    }

    /**
     * Filter by source system
     */
    public function addSourceSystemFilter(string $sourceSystem): self
    {
        return $this->addFieldToFilter('source_system', $sourceSystem);
    }

    /**
     * Filter by entity type
     */
    public function addEntityTypeFilter(string $entityType): self
    {
        return $this->addFieldToFilter('entity_type', $entityType);
    }

    /**
     * Filter by synced date range
     */
    public function addSyncedDateFilter(string $from, ?string $to = null): self
    {
        $this->addFieldToFilter('synced_at', ['gteq' => $from]);

        if ($to !== null) {
            $this->addFieldToFilter('synced_at', ['lteq' => $to]);
        }

        return $this;
    }

    /**
     * Get as source_id => target_id map
     *
     * @return array<int, int>
     */
    public function toIdMap(): array
    {
        $map = [];
        foreach ($this as $item) {
            $map[(int) $item->getSourceId()] = (int) $item->getTargetId();
        }
        return $map;
    }
}
