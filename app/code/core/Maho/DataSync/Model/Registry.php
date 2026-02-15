<?php

/**
 * Maho DataSync Registry Model
 *
 * Maps source system entity IDs to target (Maho) entity IDs.
 * Used for foreign key resolution when importing related entities.
 *
 * @category   Maho
 * @package    Maho_DataSync
 *
 * @method string getSourceSystem()
 * @method $this setSourceSystem(string $value)
 * @method string getEntityType()
 * @method $this setEntityType(string $value)
 * @method int getSourceId()
 * @method $this setSourceId(int $value)
 * @method int getTargetId()
 * @method $this setTargetId(int $value)
 * @method string|null getExternalRef()
 * @method $this setExternalRef(string|null $value)
 * @method string getSyncedAt()
 * @method $this setSyncedAt(string $value)
 * @method string|null getMetadata()
 * @method $this setMetadata(string|null $value)
 */
class Maho_DataSync_Model_Registry extends Mage_Core_Model_Abstract
{
    /**
     * In-memory cache for bulk lookups
     * @var array<string, int>
     */
    protected static array $_cache = [];

    #[\Override]
    protected function _construct(): void
    {
        $this->_init('datasync/registry');
    }

    /**
     * Resolve source entity ID to target entity ID
     *
     * @param string $sourceSystem Source system identifier (e.g., 'legacy_store')
     * @param string $entityType Entity type (e.g., 'customer', 'order')
     * @param int $sourceId Original entity ID in source system
     * @return int|null Target entity ID in Maho, or null if not found
     */
    public function resolve(string $sourceSystem, string $entityType, int $sourceId): ?int
    {
        // Check cache first
        $cacheKey = "{$sourceSystem}:{$entityType}:{$sourceId}";
        if (isset(self::$_cache[$cacheKey])) {
            return self::$_cache[$cacheKey];
        }

        $item = $this->getCollection()
            ->addFieldToFilter('source_system', $sourceSystem)
            ->addFieldToFilter('entity_type', $entityType)
            ->addFieldToFilter('source_id', $sourceId)
            ->setPageSize(1)
            ->getFirstItem();

        if ($item->getId()) {
            $targetId = (int) $item->getTargetId();
            self::$_cache[$cacheKey] = $targetId;
            return $targetId;
        }

        return null;
    }

    /**
     * Resolve by external reference (e.g., email, increment_id, sku)
     *
     * @param string $entityType Entity type
     * @param string $externalRef External reference value
     * @return int|null Target entity ID or null if not found
     */
    public function resolveByExternalRef(string $entityType, string $externalRef): ?int
    {
        $item = $this->getCollection()
            ->addFieldToFilter('entity_type', $entityType)
            ->addFieldToFilter('external_ref', $externalRef)
            ->setPageSize(1)
            ->getFirstItem();

        return $item->getId() ? (int) $item->getTargetId() : null;
    }

    /**
     * Bulk resolve multiple source IDs at once (more efficient)
     *
     * @param string $sourceSystem Source system identifier
     * @param string $entityType Entity type
     * @param array<int> $sourceIds Array of source entity IDs
     * @return array<int, int> Map of source_id => target_id
     */
    public function resolveMany(string $sourceSystem, string $entityType, array $sourceIds): array
    {
        if (empty($sourceIds)) {
            return [];
        }

        $result = [];
        $uncached = [];

        // Check cache first
        foreach ($sourceIds as $sourceId) {
            $cacheKey = "{$sourceSystem}:{$entityType}:{$sourceId}";
            if (isset(self::$_cache[$cacheKey])) {
                $result[$sourceId] = self::$_cache[$cacheKey];
            } else {
                $uncached[] = $sourceId;
            }
        }

        // Query database for uncached IDs
        if (!empty($uncached)) {
            $collection = $this->getCollection()
                ->addFieldToFilter('source_system', $sourceSystem)
                ->addFieldToFilter('entity_type', $entityType)
                ->addFieldToFilter('source_id', ['in' => $uncached]);

            foreach ($collection as $item) {
                $sourceId = (int) $item->getSourceId();
                $targetId = (int) $item->getTargetId();
                $result[$sourceId] = $targetId;

                // Update cache
                $cacheKey = "{$sourceSystem}:{$entityType}:{$sourceId}";
                self::$_cache[$cacheKey] = $targetId;
            }
        }

        return $result;
    }

    /**
     * Register a new mapping
     *
     * @param string $sourceSystem Source system identifier
     * @param string $entityType Entity type
     * @param int $sourceId Original entity ID in source
     * @param int $targetId New entity ID in Maho
     * @param string|null $externalRef Optional external reference
     * @param array|null $metadata Optional metadata
     * @return $this
     */
    public function register(
        string $sourceSystem,
        string $entityType,
        int $sourceId,
        int $targetId,
        ?string $externalRef = null,
        ?array $metadata = null,
    ): self {
        // Check if mapping already exists
        /** @var Maho_DataSync_Model_Registry $existing */
        $existing = $this->getCollection()
            ->addFieldToFilter('source_system', $sourceSystem)
            ->addFieldToFilter('entity_type', $entityType)
            ->addFieldToFilter('source_id', $sourceId)
            ->setPageSize(1)
            ->getFirstItem();

        if ($existing->getId()) {
            // Update existing
            $existing->setTargetId($targetId)
                ->setExternalRef($externalRef)
                ->setSyncedAt(Mage_Core_Model_Locale::now())
                ->setMetadata($metadata ? json_encode($metadata) : null)
                ->save();
        } else {
            // Create new
            $this->setData([
                'source_system' => $sourceSystem,
                'entity_type' => $entityType,
                'source_id' => $sourceId,
                'target_id' => $targetId,
                'external_ref' => $externalRef,
                'synced_at' => Mage_Core_Model_Locale::now(),
                'metadata' => $metadata ? json_encode($metadata) : null,
            ])->save();
        }

        // Update cache
        $cacheKey = "{$sourceSystem}:{$entityType}:{$sourceId}";
        self::$_cache[$cacheKey] = $targetId;

        return $this;
    }

    /**
     * Check if a mapping exists
     */
    public function exists(string $sourceSystem, string $entityType, int $sourceId): bool
    {
        return $this->resolve($sourceSystem, $entityType, $sourceId) !== null;
    }

    /**
     * Get reverse mapping (target ID to source ID)
     *
     * @param string $sourceSystem Source system identifier
     * @param string $entityType Entity type
     * @param int $targetId Maho entity ID
     * @return int|null Source entity ID or null if not found
     */
    public function resolveReverse(string $sourceSystem, string $entityType, int $targetId): ?int
    {
        $item = $this->getCollection()
            ->addFieldToFilter('source_system', $sourceSystem)
            ->addFieldToFilter('entity_type', $entityType)
            ->addFieldToFilter('target_id', $targetId)
            ->setPageSize(1)
            ->getFirstItem();

        return $item->getId() ? (int) $item->getSourceId() : null;
    }

    /**
     * Get statistics for a source system
     *
     * @param string $sourceSystem Source system identifier
     * @return array<string, int> Entity type => count
     */
    public function getStats(string $sourceSystem): array
    {
        $connection = $this->getResource()->getReadConnection();
        $table = $this->getResource()->getMainTable();

        $select = $connection->select()
            ->from($table, ['entity_type', 'count' => new \Maho\Db\Expr('COUNT(*)')])
            ->where('source_system = ?', $sourceSystem)
            ->group('entity_type');

        $results = $connection->fetchPairs($select);

        return $results;
    }

    /**
     * Clear the in-memory cache
     */
    public static function clearCache(): void
    {
        self::$_cache = [];
    }

    /**
     * Preload cache for a batch of source IDs (optimization for bulk imports)
     */
    public function preloadCache(string $sourceSystem, string $entityType, array $sourceIds): void
    {
        $this->resolveMany($sourceSystem, $entityType, $sourceIds);
    }
}
