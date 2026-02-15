<?php

/**
 * Maho DataSync Helper
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const LOG_FILE = 'datasync.log';
    public const ERROR_LOG_FILE = 'datasync_errors.log';

    public const LOG_LEVEL_ERROR = 1;
    public const LOG_LEVEL_WARNING = 2;
    public const LOG_LEVEL_INFO = 3;
    public const LOG_LEVEL_DEBUG = 4;

    /**
     * Check if module is enabled
     */
    public function isEnabled(): bool
    {
        return Mage::getStoreConfigFlag('datasync/general/enabled');
    }

    /**
     * Get configured log level
     */
    public function getLogLevel(): int
    {
        return (int) Mage::getStoreConfig('datasync/general/log_level');
    }

    /**
     * Log message if level is appropriate
     */
    public function log(string $message, int $level = self::LOG_LEVEL_INFO, array $context = []): void
    {
        if ($level > $this->getLogLevel()) {
            return;
        }

        $levelLabels = [
            self::LOG_LEVEL_ERROR => 'ERROR',
            self::LOG_LEVEL_WARNING => 'WARNING',
            self::LOG_LEVEL_INFO => 'INFO',
            self::LOG_LEVEL_DEBUG => 'DEBUG',
        ];

        $prefix = $levelLabels[$level] ?? 'UNKNOWN';

        if (!empty($context)) {
            $message .= ' | Context: ' . json_encode($context);
        }

        $logFile = ($level === self::LOG_LEVEL_ERROR) ? self::ERROR_LOG_FILE : self::LOG_FILE;

        Mage::log("[{$prefix}] {$message}", null, $logFile);
    }

    /**
     * Log error
     */
    public function logError(string $message, array $context = []): void
    {
        $this->log($message, self::LOG_LEVEL_ERROR, $context);
    }

    /**
     * Log warning
     */
    public function logWarning(string $message, array $context = []): void
    {
        $this->log($message, self::LOG_LEVEL_WARNING, $context);
    }

    /**
     * Log info
     */
    public function logInfo(string $message, array $context = []): void
    {
        $this->log($message, self::LOG_LEVEL_INFO, $context);
    }

    /**
     * Log debug
     */
    public function logDebug(string $message, array $context = []): void
    {
        $this->log($message, self::LOG_LEVEL_DEBUG, $context);
    }

    /**
     * Get registered adapters from config
     *
     * @return array<string, array{class: string, label: string, description: string}>
     */
    public function getRegisteredAdapters(): array
    {
        $adapters = [];
        $config = Mage::getConfig()->getNode('global/datasync/adapters');

        if ($config) {
            foreach ($config->children() as $code => $adapterConfig) {
                $adapters[$code] = [
                    'class' => (string) $adapterConfig->class,
                    'label' => (string) $adapterConfig->label,
                    'description' => (string) $adapterConfig->descr,
                ];
            }
        }

        return $adapters;
    }

    /**
     * Get registered entity handlers from config
     *
     * @return array<string, array{class: string, label: string, order: int, depends: string|null}>
     */
    public function getRegisteredEntities(): array
    {
        $entities = [];
        $config = Mage::getConfig()->getNode('global/datasync/entities');

        if ($config) {
            foreach ($config->children() as $code => $entityConfig) {
                $entities[$code] = [
                    'class' => (string) $entityConfig->class,
                    'label' => (string) $entityConfig->label,
                    'order' => (int) $entityConfig->order,
                    'depends' => $entityConfig->depends ? (string) $entityConfig->depends : null,
                ];
            }
        }

        // Sort by order
        uasort($entities, fn($a, $b) => $a['order'] <=> $b['order']);

        return $entities;
    }

    /**
     * Get adapter instance by code
     */
    public function getAdapter(string $code): Maho_DataSync_Model_Adapter_Interface
    {
        $adapters = $this->getRegisteredAdapters();

        if (!isset($adapters[$code])) {
            throw new Maho_DataSync_Exception("Unknown adapter: {$code}");
        }

        $adapter = Mage::getModel($adapters[$code]['class']);

        if (!$adapter instanceof Maho_DataSync_Model_Adapter_Interface) {
            throw new Maho_DataSync_Exception("Adapter {$code} must implement Maho_DataSync_Model_Adapter_Interface");
        }

        return $adapter;
    }

    /**
     * Get entity handler instance by type
     */
    public function getEntityHandler(string $type): Maho_DataSync_Model_Entity_Interface
    {
        $entities = $this->getRegisteredEntities();

        if (!isset($entities[$type])) {
            throw new Maho_DataSync_Exception("Unknown entity type: {$type}");
        }

        $handler = Mage::getModel($entities[$type]['class']);

        if (!$handler instanceof Maho_DataSync_Model_Entity_Interface) {
            throw new Maho_DataSync_Exception("Entity handler {$type} must implement Maho_DataSync_Model_Entity_Interface");
        }

        return $handler;
    }

    /**
     * Parse connection string into array
     *
     * Format: "host=localhost;db=database;user=username;pass=password;port=3306;prefix=mage_"
     */
    public function parseConnectionString(string $connectionString): array
    {
        $config = [
            'host' => 'localhost',
            'port' => 3306,
            'database' => '',
            'username' => '',
            'password' => '',
            'prefix' => '',
        ];

        $parts = explode(';', $connectionString);
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            $keyValue = explode('=', $part, 2);
            if (count($keyValue) !== 2) {
                continue;
            }

            $key = trim($keyValue[0]);
            $value = trim($keyValue[1]);

            // Map short keys to full names
            $keyMap = [
                'host' => 'host',
                'db' => 'database',
                'database' => 'database',
                'user' => 'username',
                'username' => 'username',
                'pass' => 'password',
                'password' => 'password',
                'port' => 'port',
                'prefix' => 'prefix',
            ];

            if (isset($keyMap[$key])) {
                $config[$keyMap[$key]] = $value;
            }
        }

        return $config;
    }

    /**
     * Generate a secure random password
     */
    public function generateRandomPassword(int $length = 16): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }

    /**
     * Format bytes to human readable
     */
    public function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Format duration in seconds to human readable
     */
    public function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds, 2) . 's';
        }

        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;

        if ($minutes < 60) {
            return "{$minutes}m " . round($secs) . 's';
        }

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        return "{$hours}h {$mins}m";
    }
}
