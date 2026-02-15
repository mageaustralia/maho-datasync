<?php

/**
 * Maho DataSync Adapter Interface
 *
 * All source adapters must implement this interface. Adapters are responsible
 * for reading data from external systems (CSV files, databases, APIs, etc.).
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
interface Maho_DataSync_Model_Adapter_Interface
{
    /**
     * Get adapter code/identifier
     *
     * @return string Unique identifier for this adapter (e.g., 'csv', 'openmage', 'woocommerce')
     */
    public function getCode(): string;

    /**
     * Get human-readable label
     *
     * @return string Display name for this adapter
     */
    public function getLabel(): string;

    /**
     * Get supported entity types
     *
     * @return array<string> List of entity types this adapter can read
     */
    public function getSupportedEntities(): array;

    /**
     * Configure the adapter
     *
     * @param array $config Configuration options (varies by adapter)
     *                      CSV: ['file_path' => string, 'delimiter' => string]
     *                      Database: ['host' => string, 'database' => string, 'username' => string, 'password' => string]
     *                      API: ['endpoint' => string, 'api_key' => string]
     */
    public function configure(array $config): void;

    /**
     * Validate adapter configuration and connectivity
     *
     * @return bool True if adapter is ready to read data
     * @throws Maho_DataSync_Exception If validation fails with details
     */
    public function validate(): bool;

    /**
     * Read entities from source
     *
     * IMPORTANT: This method should yield results one at a time (generator pattern)
     * to support memory-efficient processing of large datasets.
     *
     * @param string $entityType Entity type to read (e.g., 'customer', 'order')
     * @param array $filters Delta filters:
     *                       - date_from: ISO date string, records created/updated after this date
     *                       - date_to: ISO date string, records created/updated before this date
     *                       - id_from: int, records with entity_id >= this value
     *                       - id_to: int, records with entity_id <= this value
     *                       - limit: int, maximum records to return
     *                       - offset: int, number of records to skip
     *                       - store_id: int|array, filter by store
     * @return iterable<array> Generator yielding entity data arrays
     * @throws Maho_DataSync_Exception If read fails
     */
    public function read(string $entityType, array $filters = []): iterable;

    /**
     * Get count of records matching filters (for progress display)
     *
     * @param string $entityType Entity type to count
     * @param array $filters Same filters as read()
     * @return int|null Count of matching records, or null if count not supported
     */
    public function count(string $entityType, array $filters = []): ?int;

    /**
     * Get adapter-specific info for debugging
     *
     * @return array Adapter information (connection status, version, etc.)
     */
    public function getInfo(): array;
}
