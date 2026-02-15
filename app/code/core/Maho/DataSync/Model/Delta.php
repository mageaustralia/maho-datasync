<?php

/**
 * Maho DataSync Delta Model
 *
 * Tracks the last sync state for each source system and entity type.
 * Used for delta sync to only import new/changed records.
 *
 * @category   Maho
 * @package    Maho_DataSync
 *
 * @method string getSourceSystem()
 * @method $this setSourceSystem(string $value)
 * @method string getEntityType()
 * @method $this setEntityType(string $value)
 * @method string getAdapterCode()
 * @method $this setAdapterCode(string $value)
 * @method string getLastSyncAt()
 * @method $this setLastSyncAt(string $value)
 * @method int|null getLastEntityId()
 * @method $this setLastEntityId(int|null $value)
 * @method string|null getLastUpdatedAt()
 * @method $this setLastUpdatedAt(string|null $value)
 * @method int getSyncCount()
 * @method $this setSyncCount(int $value)
 * @method int getErrorCount()
 * @method $this setErrorCount(int $value)
 * @method string|null getLastError()
 * @method $this setLastError(string|null $value)
 * @method string|null getConfigHash()
 * @method $this setConfigHash(string|null $value)
 */
class Maho_DataSync_Model_Delta extends Mage_Core_Model_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('datasync/delta');
    }

    /**
     * Load delta state for a source system and entity type
     *
     * @param string $sourceSystem Source system identifier
     * @param string $entityType Entity type
     * @return $this
     */
    public function loadBySourceAndType(string $sourceSystem, string $entityType): self
    {
        /** @var Maho_DataSync_Model_Resource_Delta $resource */
        $resource = $this->getResource();
        $resource->loadBySourceAndType($this, $sourceSystem, $entityType);
        return $this;
    }

    /**
     * Get the last sync timestamp for use in delta queries
     *
     * @param string $sourceSystem Source system identifier
     * @param string $entityType Entity type
     * @return string|null ISO datetime string or null if never synced
     */
    public function getLastSyncTime(string $sourceSystem, string $entityType): ?string
    {
        $this->loadBySourceAndType($sourceSystem, $entityType);
        return $this->getId() ? $this->getLastSyncAt() : null;
    }

    /**
     * Get the last synced entity ID for use in delta queries
     *
     * @param string $sourceSystem Source system identifier
     * @param string $entityType Entity type
     * @return int|null Last entity ID or null if never synced
     */
    public function getLastSyncedId(string $sourceSystem, string $entityType): ?int
    {
        $this->loadBySourceAndType($sourceSystem, $entityType);
        return $this->getId() ? $this->getLastEntityId() : null;
    }

    /**
     * Update delta state after a successful sync
     *
     * @param string $sourceSystem Source system identifier
     * @param string $entityType Entity type
     * @param string $adapterCode Adapter used
     * @param Maho_DataSync_Model_Result $result Sync result
     * @param array|null $config Optional config for hash
     * @return $this
     */
    public function updateFromResult(
        string $sourceSystem,
        string $entityType,
        string $adapterCode,
        Maho_DataSync_Model_Result $result,
        ?array $config = null,
    ): self {
        $this->loadBySourceAndType($sourceSystem, $entityType);

        // Calculate new totals
        $currentSyncCount = $this->getSyncCount() ?? 0;
        $currentErrorCount = $this->getErrorCount() ?? 0;

        // Find highest source ID from successes
        $highestId = $this->getLastEntityId();
        foreach ($result->getSuccesses() as $success) {
            if ($success['source_id'] > ($highestId ?? 0)) {
                $highestId = $success['source_id'];
            }
        }

        $this->setData([
            'state_id' => $this->getId(), // Preserve ID if updating
            'source_system' => $sourceSystem,
            'entity_type' => $entityType,
            'adapter_code' => $adapterCode,
            'last_sync_at' => Mage_Core_Model_Locale::now(),
            'last_entity_id' => $highestId,
            'last_updated_at' => Mage_Core_Model_Locale::now(),
            'sync_count' => $currentSyncCount + $result->getSuccessCount(),
            'error_count' => $currentErrorCount + $result->getErrorCount(),
            'last_error' => $result->hasErrors()
                ? implode("\n", array_slice($result->getErrorMessages(), 0, 5))
                : null,
            'config_hash' => $config ? md5(json_encode($config)) : null,
        ]);

        $this->save();

        return $this;
    }

    /**
     * Check if configuration has changed since last sync
     *
     * @param string $sourceSystem Source system identifier
     * @param string $entityType Entity type
     * @param array $config Current configuration
     * @return bool True if configuration changed
     */
    public function hasConfigChanged(string $sourceSystem, string $entityType, array $config): bool
    {
        $this->loadBySourceAndType($sourceSystem, $entityType);

        if (!$this->getId()) {
            return true; // Never synced, treat as changed
        }

        $currentHash = md5(json_encode($config));
        return $this->getConfigHash() !== $currentHash;
    }

    /**
     * Reset delta state (force full resync)
     *
     * @param string $sourceSystem Source system identifier
     * @param string|null $entityType Optional entity type (null = all types)
     * @return int Number of records reset
     */
    public function reset(string $sourceSystem, ?string $entityType = null): int
    {
        /** @var Maho_DataSync_Model_Resource_Delta $resource */
        $resource = $this->getResource();
        return $resource->resetState($sourceSystem, $entityType);
    }

    /**
     * Get all delta states for a source system
     *
     * @param string $sourceSystem Source system identifier
     * @return array<string, array{last_sync: string, count: int, errors: int}>
     */
    public function getStatesForSource(string $sourceSystem): array
    {
        $collection = $this->getCollection()
            ->addFieldToFilter('source_system', $sourceSystem);

        $states = [];
        foreach ($collection as $item) {
            $states[$item->getEntityType()] = [
                'last_sync' => $item->getLastSyncAt(),
                'last_entity_id' => $item->getLastEntityId(),
                'count' => (int) $item->getSyncCount(),
                'errors' => (int) $item->getErrorCount(),
                'adapter' => $item->getAdapterCode(),
            ];
        }

        return $states;
    }
}
