<?php

/**
 * Maho DataSync Exception
 *
 * Base exception class for all DataSync-related errors.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Exception extends Mage_Core_Exception
{
    public const CODE_GENERAL = 1000;
    public const CODE_ADAPTER_NOT_FOUND = 1001;
    public const CODE_ENTITY_NOT_FOUND = 1002;
    public const CODE_CONNECTION_FAILED = 1003;
    public const CODE_VALIDATION_FAILED = 1004;
    public const CODE_IMPORT_FAILED = 1005;
    public const CODE_FK_RESOLUTION_FAILED = 1006;
    public const CODE_CONFIGURATION_ERROR = 1007;
    public const CODE_FILE_NOT_FOUND = 1008;
    public const CODE_PERMISSION_DENIED = 1009;
    public const CODE_DUPLICATE_ENTITY = 1010;

    protected ?string $_entityType = null;
    protected ?int $_sourceId = null;
    protected ?string $_sourceSystem = null;
    protected array $_context = [];

    /**
     * Create exception with context
     */
    public static function create(
        string $message,
        int $code = self::CODE_GENERAL,
        ?string $entityType = null,
        ?int $sourceId = null,
        ?string $sourceSystem = null,
        array $context = [],
    ): self {
        $exception = new self($message, $code);
        $exception->_entityType = $entityType;
        $exception->_sourceId = $sourceId;
        $exception->_sourceSystem = $sourceSystem;
        $exception->_context = $context;

        return $exception;
    }

    /**
     * Create adapter not found exception
     */
    public static function adapterNotFound(string $adapterCode): self
    {
        return self::create(
            "Adapter not found: {$adapterCode}",
            self::CODE_ADAPTER_NOT_FOUND,
            null,
            null,
            null,
            ['adapter' => $adapterCode],
        );
    }

    /**
     * Create entity handler not found exception
     */
    public static function entityNotFound(string $entityType): self
    {
        return self::create(
            "Entity handler not found: {$entityType}",
            self::CODE_ENTITY_NOT_FOUND,
            $entityType,
        );
    }

    /**
     * Create connection failed exception
     */
    public static function connectionFailed(string $message, array $config = []): self
    {
        // Remove sensitive data from config
        unset($config['password'], $config['pass']);

        return self::create(
            "Connection failed: {$message}",
            self::CODE_CONNECTION_FAILED,
            null,
            null,
            null,
            ['config' => $config],
        );
    }

    /**
     * Create FK resolution failed exception
     */
    public static function fkResolutionFailed(
        string $entityType,
        string $fkField,
        int $sourceId,
        string $sourceSystem,
    ): self {
        return self::create(
            "Foreign key resolution failed: {$entityType}.{$fkField} = {$sourceId} not found in registry",
            self::CODE_FK_RESOLUTION_FAILED,
            $entityType,
            $sourceId,
            $sourceSystem,
            ['fk_field' => $fkField],
        );
    }

    /**
     * Create import failed exception
     */
    public static function importFailed(
        string $entityType,
        int $sourceId,
        string $sourceSystem,
        string $reason,
    ): self {
        return self::create(
            "Import failed for {$entityType} #{$sourceId}: {$reason}",
            self::CODE_IMPORT_FAILED,
            $entityType,
            $sourceId,
            $sourceSystem,
        );
    }

    /**
     * Create file not found exception
     */
    public static function fileNotFound(string $filePath): self
    {
        return self::create(
            "File not found: {$filePath}",
            self::CODE_FILE_NOT_FOUND,
            null,
            null,
            null,
            ['file_path' => $filePath],
        );
    }

    public function getEntityType(): ?string
    {
        return $this->_entityType;
    }

    public function getSourceId(): ?int
    {
        return $this->_sourceId;
    }

    public function getSourceSystem(): ?string
    {
        return $this->_sourceSystem;
    }

    public function getContext(): array
    {
        return $this->_context;
    }

    /**
     * Get full error details for logging
     */
    public function getLogDetails(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'entity_type' => $this->_entityType,
            'source_id' => $this->_sourceId,
            'source_system' => $this->_sourceSystem,
            'context' => $this->_context,
            'trace' => $this->getTraceAsString(),
        ];
    }
}
