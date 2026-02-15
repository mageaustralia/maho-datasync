<?php

/**
 * Maho DataSync Engine
 *
 * Core orchestrator for sync operations. Coordinates between adapters,
 * entity handlers, and the registry for foreign key resolution.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Engine
{
    protected ?Maho_DataSync_Model_Adapter_Interface $_sourceAdapter = null;
    protected ?Maho_DataSync_Model_Registry $_registry = null;
    protected ?Maho_DataSync_Helper_Data $_helper = null;

    protected string $_sourceSystem = '';
    protected array $_filters = [];
    protected bool $_dryRun = false;
    protected bool $_verbose = false;
    protected string $_onDuplicate = 'error';
    protected bool $_skipInvalid = false;
    protected bool $_strict = false;
    protected int $_progressInterval = 100; // Show speed every N records
    protected array $_entityOptions = [];

    public const DUPLICATE_SKIP = 'skip';
    public const DUPLICATE_UPDATE = 'update';
    public const DUPLICATE_MERGE = 'merge';
    public const DUPLICATE_ERROR = 'error';

    /** @var callable|null */
    protected $_progressCallback = null;

    public function __construct()
    {
        $this->_registry = Mage::getModel('datasync/registry');
        $this->_helper = Mage::helper('datasync');
    }

    /**
     * Set the source adapter
     */
    public function setSourceAdapter(Maho_DataSync_Model_Adapter_Interface $adapter): self
    {
        $this->_sourceAdapter = $adapter;
        return $this;
    }

    /**
     * Get the source adapter
     */
    public function getSourceAdapter(): ?Maho_DataSync_Model_Adapter_Interface
    {
        return $this->_sourceAdapter;
    }

    /**
     * Set source system identifier
     */
    public function setSourceSystem(string $sourceSystem): self
    {
        $this->_sourceSystem = $sourceSystem;
        return $this;
    }

    /**
     * Get source system identifier
     */
    public function getSourceSystem(): string
    {
        return $this->_sourceSystem;
    }

    /**
     * Set delta filters
     *
     * @param array{date_from?: string, date_to?: string, id_from?: int, id_to?: int, limit?: int, store_id?: int|array} $filters
     */
    public function setFilters(array $filters): self
    {
        $this->_filters = $filters;
        return $this;
    }

    /**
     * Get filters
     */
    public function getFilters(): array
    {
        return $this->_filters;
    }

    /**
     * Enable/disable dry run mode (validate without importing)
     */
    public function setDryRun(bool $dryRun): self
    {
        $this->_dryRun = $dryRun;
        return $this;
    }

    /**
     * Check if in dry run mode
     */
    public function isDryRun(): bool
    {
        return $this->_dryRun;
    }

    /**
     * Enable/disable verbose output
     */
    public function setVerbose(bool $verbose): self
    {
        $this->_verbose = $verbose;
        return $this;
    }

    /**
     * Set duplicate handling mode
     *
     * @param string $mode One of: skip, update, merge, error
     */
    public function setOnDuplicate(string $mode): self
    {
        $validModes = [self::DUPLICATE_SKIP, self::DUPLICATE_UPDATE, self::DUPLICATE_MERGE, self::DUPLICATE_ERROR];
        if (!in_array($mode, $validModes)) {
            throw new Maho_DataSync_Exception(
                "Invalid duplicate mode: {$mode}. Must be one of: " . implode(', ', $validModes),
                Maho_DataSync_Exception::CODE_CONFIGURATION_ERROR,
            );
        }
        $this->_onDuplicate = $mode;
        return $this;
    }

    /**
     * Get duplicate handling mode
     */
    public function getOnDuplicate(): string
    {
        return $this->_onDuplicate;
    }

    /**
     * Enable/disable skip-invalid mode
     *
     * When enabled, records that fail validation are skipped instead of causing errors.
     */
    public function setSkipInvalid(bool $skipInvalid): self
    {
        $this->_skipInvalid = $skipInvalid;
        return $this;
    }

    /**
     * Check if skip-invalid mode is enabled
     */
    public function isSkipInvalid(): bool
    {
        return $this->_skipInvalid;
    }

    /**
     * Enable/disable strict validation mode
     *
     * When enabled, warnings are treated as errors (e.g., missing items, unknown payment methods).
     */
    public function setStrict(bool $strict): self
    {
        $this->_strict = $strict;
        return $this;
    }

    /**
     * Check if strict mode is enabled
     */
    public function isStrict(): bool
    {
        return $this->_strict;
    }

    /**
     * Set entity-specific options
     *
     * Options like default_payment_method, default_shipping_method for orders.
     */
    public function setEntityOptions(array $options): self
    {
        $this->_entityOptions = $options;
        return $this;
    }

    /**
     * Get entity options
     */
    public function getEntityOptions(): array
    {
        return $this->_entityOptions;
    }

    /**
     * Set progress callback for verbose output
     *
     * @param callable(string $message): void $callback
     */
    public function setProgressCallback(callable $callback): self
    {
        $this->_progressCallback = $callback;
        return $this;
    }

    /**
     * Set progress reporting interval (show speed every N records)
     */
    public function setProgressInterval(int $interval): self
    {
        $this->_progressInterval = max(1, $interval);
        return $this;
    }

    /**
     * Get progress reporting interval
     */
    public function getProgressInterval(): int
    {
        return $this->_progressInterval;
    }

    /**
     * Sync entities from source to Maho
     *
     * @param string $entityType Entity type to sync
     * @param array $options Additional options
     * @throws Maho_DataSync_Exception
     */
    public function sync(string $entityType, array $options = []): Maho_DataSync_Model_Result
    {
        // Validate prerequisites
        $this->_validatePrerequisites($entityType);

        // CRITICAL: Enable import mode to suppress emails
        // This registry flag is checked by observers to prevent sending emails
        $this->_enableImportMode();

        $result = new Maho_DataSync_Model_Result();
        $result->setEntityType($entityType)
            ->setSourceSystem($this->_sourceSystem);

        $handler = $this->_helper->getEntityHandler($entityType);

        $this->_log("Starting sync for {$entityType} from {$this->_sourceSystem}");
        $this->_log('Filters: ' . json_encode($this->_filters));

        if ($this->_dryRun) {
            $this->_log('DRY RUN MODE - No data will be imported');
        }

        $count = 0;
        $batchSize = $options['batch_size'] ?? 100;
        $batch = [];
        $startTime = microtime(true);
        $lastProgressTime = $startTime;
        $lastProgressCount = 0;

        try {
            // Read entities from source
            foreach ($this->_sourceAdapter->read($entityType, $this->_filters) as $sourceData) {
                $count++;

                // Show progress with speed every N records
                if ($count % $this->_progressInterval === 0) {
                    $elapsed = microtime(true) - $startTime;
                    $intervalElapsed = microtime(true) - $lastProgressTime;
                    $intervalCount = $count - $lastProgressCount;

                    $overallSpeed = $elapsed > 0 ? round($count / $elapsed, 1) : 0;
                    $currentSpeed = $intervalElapsed > 0 ? round($intervalCount / $intervalElapsed, 1) : 0;

                    $this->_progress(sprintf(
                        'Progress: %d records | %.1f rec/s (current: %.1f rec/s)',
                        $count,
                        $overallSpeed,
                        $currentSpeed,
                    ));

                    $lastProgressTime = microtime(true);
                    $lastProgressCount = $count;
                }

                // Add metadata
                $sourceData['_source_system'] = $this->_sourceSystem;
                $sourceData['_adapter'] = $this->_sourceAdapter->getCode();
                $sourceData['_on_duplicate'] = $this->_onDuplicate;
                $sourceData['_entity_options'] = $this->_entityOptions;

                try {
                    // Resolve foreign keys
                    $sourceData = $this->_resolveForeignKeys($handler, $sourceData);

                    // Validate entity (required fields + handler-specific validation)
                    $validationErrors = $this->_validateEntity($handler, $sourceData);
                    if (!empty($validationErrors)) {
                        $sourceId = $sourceData['entity_id'] ?? $count;
                        $errorMsg = implode('; ', $validationErrors);

                        if ($this->_skipInvalid) {
                            $result->addSkipped($sourceId, null, "Validation failed: {$errorMsg}");
                            $this->_progress("Skipped invalid {$entityType} #{$sourceId}: {$errorMsg}");
                            continue;
                        }

                        throw new Maho_DataSync_Exception(
                            "Validation failed for {$entityType} #{$sourceId}: {$errorMsg}",
                            Maho_DataSync_Exception::CODE_VALIDATION_FAILED,
                        );
                    }

                    // Check for existing record
                    $existingId = $handler->findExisting($sourceData);
                    $sourceId = $sourceData['entity_id'] ?? $count;

                    // Handle duplicate based on mode
                    if ($existingId !== null) {
                        switch ($this->_onDuplicate) {
                            case self::DUPLICATE_SKIP:
                                $result->addSkipped($sourceId, $existingId, 'Duplicate - skipped');
                                $this->_progress("Skipped {$entityType} #{$sourceId} (exists as #{$existingId})");
                                continue 2; // Continue to next record

                            case self::DUPLICATE_ERROR:
                                throw new Maho_DataSync_Exception(
                                    "Duplicate {$entityType} found: source #{$sourceId} already exists as #{$existingId}. " .
                                    'Use --on-duplicate update|skip|merge to handle duplicates.',
                                    Maho_DataSync_Exception::CODE_DUPLICATE_ENTITY,
                                );

                            case self::DUPLICATE_UPDATE:
                            case self::DUPLICATE_MERGE:
                                // Mark for update, handler will use existing ID
                                $sourceData['_existing_id'] = $existingId;
                                $sourceData['_action'] = ($this->_onDuplicate === self::DUPLICATE_MERGE) ? 'merge' : 'update';
                                break;
                        }
                    }

                    // Dry run - just validate and report what would happen
                    if ($this->_dryRun) {
                        if ($existingId !== null) {
                            $action = ($this->_onDuplicate === self::DUPLICATE_MERGE) ? 'would_merge' : 'would_update';
                            $result->addSuccess($sourceId, $existingId, $action);
                            $this->_progress("Would {$action} {$entityType} #{$sourceId} (existing #{$existingId})");
                        } else {
                            $result->addSuccess($sourceId, 0, 'would_create');
                            $this->_progress("Would create {$entityType} #{$sourceId}");
                        }
                        continue;
                    }

                    // Import (create or update)
                    $targetId = $handler->import($sourceData, $this->_registry);

                    // Determine action type for result
                    $action = ($existingId !== null) ? 'updated' : 'created';
                    if ($existingId !== null && $this->_onDuplicate === self::DUPLICATE_MERGE) {
                        $action = 'merged';
                    }

                    // Register mapping
                    $this->_registry->register(
                        $this->_sourceSystem,
                        $entityType,
                        $sourceId,
                        $targetId,
                        $sourceData['_external_ref'] ?? null,
                    );

                    $result->addSuccess($sourceId, $targetId, $action);
                    $this->_progress("{$action} {$entityType} #{$sourceId} -> #{$targetId}");

                } catch (Throwable $e) {
                    $sourceId = $sourceData['entity_id'] ?? $count;
                    $result->addException($sourceId, $e);
                    $this->_helper->logError("Failed to import {$entityType} #{$sourceId}: " . $e->getMessage());
                    $this->_progress("ERROR: {$e->getMessage()}");

                    // Continue with next record unless fatal
                    if ($e instanceof Maho_DataSync_Exception && $e->getCode() === Maho_DataSync_Exception::CODE_CONNECTION_FAILED) {
                        throw $e; // Don't continue on connection errors
                    }
                }
            }

            // Process any post-import tasks (e.g., product links)
            if (!$this->_dryRun) {
                $handler->finishSync($this->_registry);
            }

            $result->finish();

            // Update delta state
            if (!$this->_dryRun) {
                Mage::getModel('datasync/delta')->updateFromResult(
                    $this->_sourceSystem,
                    $entityType,
                    $this->_sourceAdapter->getCode(),
                    $result,
                    $this->_filters,
                );
            }

            $this->_log($result->getSummary());

        } catch (Throwable $e) {
            $result->finish();
            $this->_helper->logError('Sync failed: ' . $e->getMessage());
            throw $e;
        } finally {
            // Always disable import mode when done
            $this->_disableImportMode();
        }

        return $result;
    }

    /**
     * Enable import mode - suppresses emails and certain observers
     *
     * Sets a registry flag that can be checked by:
     * - Order confirmation email observers
     * - Invoice email observers
     * - Shipment notification observers
     * - Credit memo email observers
     * - Stock adjustment observers (for historical imports)
     */
    protected function _enableImportMode(): void
    {
        // Remove first in case it's already set
        Mage::unregister('datasync_import_mode');
        Mage::register('datasync_import_mode', true);

        // Also suppress transactional emails globally
        Mage::unregister('datasync_suppress_emails');
        Mage::register('datasync_suppress_emails', true);

        $this->_log('Import mode enabled - emails and stock adjustments suppressed');
    }

    /**
     * Disable import mode - restores normal behavior
     */
    protected function _disableImportMode(): void
    {
        Mage::unregister('datasync_import_mode');
        Mage::unregister('datasync_suppress_emails');

        $this->_log('Import mode disabled');
    }

    /**
     * Check if import mode is currently active
     *
     * Can be called statically from observers:
     * if (Mage::registry('datasync_import_mode')) { return; }
     */
    public static function isImportModeActive(): bool
    {
        return (bool) Mage::registry('datasync_import_mode');
    }

    /**
     * Validate prerequisites before sync
     *
     * @throws Maho_DataSync_Exception
     */
    protected function _validatePrerequisites(string $entityType): void
    {
        if ($this->_sourceAdapter === null) {
            throw new Maho_DataSync_Exception(
                'Source adapter not set. Call setSourceAdapter() first.',
                Maho_DataSync_Exception::CODE_CONFIGURATION_ERROR,
            );
        }

        if (empty($this->_sourceSystem)) {
            throw new Maho_DataSync_Exception(
                'Source system not set. Call setSourceSystem() first.',
                Maho_DataSync_Exception::CODE_CONFIGURATION_ERROR,
            );
        }

        // Validate adapter supports this entity type
        $supportedEntities = $this->_sourceAdapter->getSupportedEntities();
        if (!in_array($entityType, $supportedEntities)) {
            throw new Maho_DataSync_Exception(
                "Adapter {$this->_sourceAdapter->getCode()} does not support entity type: {$entityType}",
                Maho_DataSync_Exception::CODE_ENTITY_NOT_FOUND,
            );
        }

        // Validate adapter connection
        if (!$this->_sourceAdapter->validate()) {
            throw Maho_DataSync_Exception::connectionFailed(
                "Adapter {$this->_sourceAdapter->getCode()} validation failed",
            );
        }
    }

    /**
     * Resolve foreign keys in source data
     *
     * @throws Maho_DataSync_Exception
     */
    protected function _resolveForeignKeys(
        Maho_DataSync_Model_Entity_Interface $handler,
        array $sourceData,
    ): array {
        $fkFields = $handler->getForeignKeyFields();

        foreach ($fkFields as $field => $config) {
            if (!isset($sourceData[$field])) {
                continue; // Skip null FKs (e.g., guest orders)
            }

            $sourceId = (int) $sourceData[$field];

            // Determine entity type for this FK
            $fkEntityType = is_array($config) ? $config['entity_type'] : $config;
            $required = is_array($config) ? ($config['required'] ?? true) : true;

            // Look up in registry
            $targetId = $this->_registry->resolve(
                $this->_sourceSystem,
                $fkEntityType,
                $sourceId,
            );

            if ($targetId === null && $required) {
                throw Maho_DataSync_Exception::fkResolutionFailed(
                    $handler->getEntityType(),
                    $field,
                    $sourceId,
                    $this->_sourceSystem,
                );
            }

            // Replace source FK with resolved target FK
            $sourceData[$field] = $targetId;
            $sourceData["_original_{$field}"] = $sourceId;
        }

        return $sourceData;
    }

    /**
     * Validate entity data
     *
     * Returns array of error messages. Empty array means validation passed.
     *
     * @return array<string> Validation errors (empty if valid)
     */
    protected function _validateEntity(
        Maho_DataSync_Model_Entity_Interface $handler,
        array $sourceData,
    ): array {
        $errors = [];

        // Check required fields
        $requiredFields = $handler->getRequiredFields();
        $missing = [];

        foreach ($requiredFields as $field) {
            if (!isset($sourceData[$field]) || $sourceData[$field] === '') {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            $errors[] = 'Missing required fields: ' . implode(', ', $missing);
        }

        // Run handler-specific validation
        $handlerErrors = $handler->validate($sourceData);
        if (!empty($handlerErrors)) {
            $errors = array_merge($errors, $handlerErrors);
        }

        return $errors;
    }

    /**
     * Determine if this was a create, update, or skip
     */
    protected function _determineAction(string $entityType, array $sourceData, int $targetId): string
    {
        // Check if we already had a mapping for this source ID
        $existingTargetId = $this->_registry->resolve(
            $this->_sourceSystem,
            $entityType,
            $sourceData['entity_id'],
        );

        if ($existingTargetId === null) {
            return 'created';
        }

        if ($existingTargetId === $targetId) {
            return 'updated';
        }

        return 'created'; // Rare case: mapping changed
    }

    /**
     * Log message
     */
    protected function _log(string $message): void
    {
        $this->_helper->logInfo($message);

        if ($this->_verbose && $this->_progressCallback) {
            ($this->_progressCallback)($message);
        }
    }

    /**
     * Progress message (for verbose output only)
     */
    protected function _progress(string $message): void
    {
        if ($this->_verbose && $this->_progressCallback) {
            ($this->_progressCallback)($message);
        }
    }

    /**
     * Get the registry instance
     */
    public function getRegistry(): Maho_DataSync_Model_Registry
    {
        return $this->_registry;
    }
}
