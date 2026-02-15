<?php

/**
 * Maho DataSync Entity Handler Interface
 *
 * Entity handlers know how to import/export specific entity types.
 * Each handler understands the entity's structure, required fields,
 * foreign key relationships, and any special import logic.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
interface Maho_DataSync_Model_Entity_Interface
{
    /**
     * Get entity type code
     *
     * @return string Entity type identifier (e.g., 'customer', 'order')
     */
    public function getEntityType(): string;

    /**
     * Get human-readable label
     *
     * @return string Display name for this entity type
     */
    public function getLabel(): string;

    /**
     * Get required fields for import
     *
     * Fields listed here will be validated before import.
     * Import will fail if any required field is missing or empty.
     *
     * @return array<string> List of required field names
     */
    public function getRequiredFields(): array;

    /**
     * Get foreign key field mappings
     *
     * Foreign keys need to be resolved through the registry
     * to map source system IDs to target (Maho) IDs.
     *
     * Return format:
     * - Simple: ['customer_id' => 'customer'] - field => entity_type
     * - Advanced: ['customer_id' => ['entity_type' => 'customer', 'required' => false]]
     *
     * @return array<string, string|array{entity_type: string, required?: bool}>
     */
    public function getForeignKeyFields(): array;

    /**
     * Get the external reference field for this entity
     *
     * External reference is a natural key that can be used to identify
     * entities across systems (e.g., email for customers, increment_id for orders).
     *
     * @return string|null Field name for external reference, or null if none
     */
    public function getExternalRefField(): ?string;

    /**
     * Import a single entity
     *
     * This method should:
     * 1. Check if entity already exists (by external_ref or other means)
     * 2. Create or update the entity in Maho
     * 3. Return the new/existing Maho entity ID
     *
     * Foreign keys will already be resolved before this method is called.
     *
     * @param array $data Source entity data with resolved foreign keys
     * @param Maho_DataSync_Model_Registry $registry Registry for additional lookups
     * @return int Target entity ID in Maho
     * @throws Maho_DataSync_Exception If import fails
     */
    public function import(array $data, Maho_DataSync_Model_Registry $registry): int;

    /**
     * Export entities matching filters
     *
     * @param array $filters Export filters (date_from, date_to, store_id, etc.)
     * @return iterable<array> Generator yielding entity data arrays
     */
    public function export(array $filters = []): iterable;

    /**
     * Validate entity data before import
     *
     * @param array $data Source entity data
     * @return array<string> List of validation error messages (empty if valid)
     */
    public function validate(array $data): array;

    /**
     * Check if entity already exists in Maho
     *
     * @param array $data Source entity data
     * @return int|null Existing Maho entity ID, or null if not found
     */
    public function findExisting(array $data): ?int;

    /**
     * Called after all records in a sync batch have been processed
     *
     * Use this for post-processing tasks like:
     * - Processing product links (configurable, grouped)
     * - Building relationships that require all entities to exist first
     *
     * @param Maho_DataSync_Model_Registry $registry Registry for lookups
     */
    public function finishSync(Maho_DataSync_Model_Registry $registry): void;
}
