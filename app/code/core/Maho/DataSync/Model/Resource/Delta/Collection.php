<?php

/**
 * Maho DataSync Delta Collection
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Resource_Delta_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('datasync/delta');
    }

    /**
     * Filter by source system
     */
    public function addSourceSystemFilter(string $sourceSystem): self
    {
        return $this->addFieldToFilter('source_system', $sourceSystem);
    }

    /**
     * Filter by adapter code
     */
    public function addAdapterFilter(string $adapterCode): self
    {
        return $this->addFieldToFilter('adapter_code', $adapterCode);
    }

    /**
     * Get states that haven't synced recently
     *
     * @param int $hours Number of hours
     */
    public function addStaleFilter(int $hours): self
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        return $this->addFieldToFilter('last_sync_at', ['lt' => $cutoff]);
    }

    /**
     * Get states with errors
     */
    public function addHasErrorsFilter(): self
    {
        return $this->addFieldToFilter('error_count', ['gt' => 0]);
    }
}
