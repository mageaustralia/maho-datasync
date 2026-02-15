<?php

/**
 * Maho DataSync Exception
 *
 * Custom exception class for DataSync module with error codes and factory methods.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Exception extends Mage_Core_Exception
{
    public const CODE_GENERAL = 1;
    public const CODE_CONFIGURATION_ERROR = 10;
    public const CODE_CONNECTION_FAILED = 20;
    public const CODE_FILE_NOT_FOUND = 30;
    public const CODE_PERMISSION_DENIED = 31;
    public const CODE_VALIDATION_FAILED = 40;
    public const CODE_ENTITY_NOT_FOUND = 50;
    public const CODE_DUPLICATE_ENTITY = 51;
    public const CODE_FK_RESOLUTION_FAILED = 60;
    public const CODE_IMPORT_FAILED = 70;

    /**
     * Create exception for connection failures
     */
    public static function connectionFailed(string $message): self
    {
        return new self($message, self::CODE_CONNECTION_FAILED);
    }

    /**
     * Create exception for file not found
     */
    public static function fileNotFound(string $path): self
    {
        return new self("File not found: {$path}", self::CODE_FILE_NOT_FOUND);
    }

    /**
     * Create exception for FK resolution failures
     */
    public static function fkResolutionFailed(
        string $entityType,
        string $field,
        int $sourceId,
        string $sourceSystem,
    ): self {
        return new self(
            "Cannot resolve foreign key for {$entityType}.{$field}: " .
            "source ID {$sourceId} not found in registry for system '{$sourceSystem}'. " .
            'Ensure the related entity was imported first.',
            self::CODE_FK_RESOLUTION_FAILED,
        );
    }

    /**
     * Create exception for import failures
     */
    public static function importFailed(
        string $entityType,
        int $sourceId,
        string $sourceSystem,
        string $reason,
    ): self {
        return new self(
            "Failed to import {$entityType} #{$sourceId} from {$sourceSystem}: {$reason}",
            self::CODE_IMPORT_FAILED,
        );
    }
}
