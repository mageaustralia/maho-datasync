<?php

/**
 * Maho DataSync Abstract Adapter
 *
 * Base class for adapters with common functionality.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
abstract class Maho_DataSync_Model_Adapter_Abstract implements Maho_DataSync_Model_Adapter_Interface
{
    protected array $_config = [];
    protected bool $_configured = false;
    protected string $_baseUrl = '';
    protected string $_apiKey = '';
    protected string $_apiSecret = '';

    /**
     * Default supported entities (override in subclasses)
     */
    protected array $_supportedEntities = [
        'product_attribute',
        'category',
        'product',
        'customer',
        'order',
        'invoice',
        'shipment',
        'creditmemo',
        'review',
        'shopping_cart_rule',
    ];

    /**
     * @inheritDoc
     */
    #[\Override]
    abstract public function getCode(): string;

    /**
     * @inheritDoc
     */
    #[\Override]
    abstract public function getLabel(): string;

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getSupportedEntities(): array
    {
        return $this->_supportedEntities;
    }

    /**
     * Set base URL for API adapters
     */
    public function setBaseUrl(string $url): self
    {
        $this->_baseUrl = rtrim($url, '/');
        return $this;
    }

    /**
     * Set API key for authentication
     */
    public function setApiKey(#[\SensitiveParameter]
        string $apiKey): self
    {
        $this->_apiKey = $apiKey;
        return $this;
    }

    /**
     * Set API secret for authentication
     */
    public function setApiSecret(string $apiSecret): self
    {
        $this->_apiSecret = $apiSecret;
        return $this;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function configure(array $config): void
    {
        $this->_config = $config;
        $this->_configured = true;
    }

    /**
     * Get configuration value
     */
    protected function _getConfig(string $key, mixed $default = null): mixed
    {
        return $this->_config[$key] ?? $default;
    }

    /**
     * Ensure adapter is configured
     *
     * @throws Maho_DataSync_Exception
     */
    protected function _ensureConfigured(): void
    {
        if (!$this->_configured) {
            throw new Maho_DataSync_Exception(
                "Adapter {$this->getCode()} not configured. Call configure() first.",
                Maho_DataSync_Exception::CODE_CONFIGURATION_ERROR,
            );
        }
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function count(string $entityType, array $filters = []): ?int
    {
        // Default: count not supported, return null
        return null;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getInfo(): array
    {
        return [
            'code' => $this->getCode(),
            'label' => $this->getLabel(),
            'configured' => $this->_configured,
            'supported_entities' => $this->_supportedEntities,
        ];
    }

    /**
     * Apply filters to a collection/query
     *
     * Helper for subclasses to apply standard filter logic.
     *
     * @param array $filters Filter array from read()
     * @return array Processed filters with defaults
     */
    protected function _normalizeFilters(array $filters): array
    {
        return [
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'id_from' => isset($filters['id_from']) ? (int) $filters['id_from'] : null,
            'id_to' => isset($filters['id_to']) ? (int) $filters['id_to'] : null,
            'limit' => isset($filters['limit']) ? (int) $filters['limit'] : null,
            'offset' => isset($filters['offset']) ? (int) $filters['offset'] : 0,
            'store_id' => $filters['store_id'] ?? null,
            'entity_ids' => $filters['entity_ids'] ?? null,
            'increment_ids' => $filters['increment_ids'] ?? null,
        ];
    }

    /**
     * Check if a record matches date filters
     *
     * @param array $record Record data
     * @param array $filters Normalized filters
     * @param string $dateField Field name containing date (default: 'created_at')
     */
    protected function _matchesDateFilter(array $record, array $filters, string $dateField = 'created_at'): bool
    {
        if (!isset($record[$dateField])) {
            return true; // No date field, assume matches
        }

        $recordDate = strtotime($record[$dateField]);

        if ($filters['date_from'] !== null) {
            $fromDate = strtotime($filters['date_from']);
            if ($recordDate < $fromDate) {
                return false;
            }
        }

        if ($filters['date_to'] !== null) {
            $toDate = strtotime($filters['date_to'] . ' 23:59:59');
            if ($recordDate > $toDate) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a record matches ID filters
     *
     * @param array $record Record data
     * @param array $filters Normalized filters
     * @param string $idField Field name containing ID (default: 'entity_id')
     */
    protected function _matchesIdFilter(array $record, array $filters, string $idField = 'entity_id'): bool
    {
        if (!isset($record[$idField])) {
            return true;
        }

        $recordId = (int) $record[$idField];

        if ($filters['id_from'] !== null && $recordId < $filters['id_from']) {
            return false;
        }

        if ($filters['id_to'] !== null && $recordId > $filters['id_to']) {
            return false;
        }

        return true;
    }

    /**
     * Get helper
     */
    protected function _getHelper(): Maho_DataSync_Helper_Data
    {
        return Mage::helper('datasync');
    }
}
