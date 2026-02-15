<?php

/**
 * Maho DataSync CSV Adapter
 *
 * Reads entity data from CSV files. Universal adapter that works with any
 * system that can export to CSV format.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Adapter_Csv extends Maho_DataSync_Model_Adapter_Abstract
{
    protected string $_filePath = '';
    protected string $_delimiter = ',';
    protected string $_enclosure = '"';
    protected string $_escape = '\\';

    /**
     * Set file path for CSV import
     */
    public function setFilePath(string $filePath): self
    {
        $this->_filePath = $filePath;
        $this->_configured = true;
        return $this;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getCode(): string
    {
        return 'csv';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'CSV File';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function configure(array $config): void
    {
        parent::configure($config);

        $this->_filePath = $config['file_path'] ?? $config['source'] ?? '';
        $this->_delimiter = $config['delimiter'] ?? ',';
        $this->_enclosure = $config['enclosure'] ?? '"';
        $this->_escape = $config['escape'] ?? '\\';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function validate(): bool
    {
        $this->_ensureConfigured();

        if (empty($this->_filePath)) {
            throw Maho_DataSync_Exception::fileNotFound('No file path configured');
        }

        if (!file_exists($this->_filePath)) {
            throw Maho_DataSync_Exception::fileNotFound($this->_filePath);
        }

        if (!is_readable($this->_filePath)) {
            throw new Maho_DataSync_Exception(
                "File not readable: {$this->_filePath}",
                Maho_DataSync_Exception::CODE_PERMISSION_DENIED,
            );
        }

        // Try to open and read header
        $handle = fopen($this->_filePath, 'r');
        if ($handle === false) {
            throw new Maho_DataSync_Exception(
                "Cannot open file: {$this->_filePath}",
                Maho_DataSync_Exception::CODE_FILE_NOT_FOUND,
            );
        }

        $headers = fgetcsv($handle, 0, $this->_delimiter, $this->_enclosure, $this->_escape);
        fclose($handle);

        if ($headers === false || count(array_filter($headers)) === 0) {
            throw new Maho_DataSync_Exception(
                "Invalid CSV: Cannot read headers from {$this->_filePath}",
                Maho_DataSync_Exception::CODE_VALIDATION_FAILED,
            );
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function read(string $entityType, array $filters = []): iterable
    {
        $this->_ensureConfigured();

        $handle = fopen($this->_filePath, 'r');
        if ($handle === false) {
            throw Maho_DataSync_Exception::fileNotFound($this->_filePath);
        }

        // Read headers
        $headers = fgetcsv($handle, 0, $this->_delimiter, $this->_enclosure, $this->_escape);
        if ($headers === false) {
            fclose($handle);
            throw new Maho_DataSync_Exception(
                'Invalid CSV: Cannot read headers',
                Maho_DataSync_Exception::CODE_VALIDATION_FAILED,
            );
        }

        // Clean headers (remove BOM, whitespace)
        $headers = array_map(function ($header) {
            // Remove UTF-8 BOM if present
            $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
            return trim($header);
        }, $headers);

        $normalizedFilters = $this->_normalizeFilters($filters);
        $rowNumber = 0;
        $yieldCount = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle, 0, $this->_delimiter, $this->_enclosure, $this->_escape)) !== false) {
            $rowNumber++;

            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Combine with headers
            if (count($row) !== count($headers)) {
                $this->_getHelper()->logWarning(
                    "CSV row {$rowNumber} has mismatched column count, skipping",
                    ['expected' => count($headers), 'got' => count($row)],
                );
                continue;
            }

            $data = array_combine($headers, $row);

            // Apply offset
            if ($normalizedFilters['offset'] > 0 && $skipped < $normalizedFilters['offset']) {
                $skipped++;
                continue;
            }

            // Apply filters
            if (!$this->_matchesFilters($data, $normalizedFilters)) {
                continue;
            }

            // Apply limit
            if ($normalizedFilters['limit'] !== null && $yieldCount >= $normalizedFilters['limit']) {
                break;
            }

            // Add row metadata
            $data['_csv_row'] = $rowNumber;

            // Ensure entity_id exists (use row number if not present)
            if (!isset($data['entity_id'])) {
                $data['entity_id'] = $rowNumber;
            }

            yield $data;
            $yieldCount++;
        }

        fclose($handle);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function count(string $entityType, array $filters = []): ?int
    {
        if (!file_exists($this->_filePath)) {
            return null;
        }

        // Count lines (minus header)
        $count = 0;
        $handle = fopen($this->_filePath, 'r');
        if ($handle === false) {
            return null;
        }

        // Skip header
        fgetcsv($handle, 0, $this->_delimiter, $this->_enclosure, $this->_escape);

        while (fgetcsv($handle, 0, $this->_delimiter, $this->_enclosure, $this->_escape) !== false) {
            $count++;
        }

        fclose($handle);

        return $count;
    }

    /**
     * Check if record matches all filters
     */
    protected function _matchesFilters(array $data, array $filters): bool
    {
        // Date filter
        if (!$this->_matchesDateFilter($data, $filters)) {
            return false;
        }

        // Also check updated_at if present
        if (isset($data['updated_at']) && !$this->_matchesDateFilter($data, $filters, 'updated_at')) {
            // If updated_at doesn't match, still allow if created_at matches
            // (already checked above)
        }

        // ID filter
        if (!$this->_matchesIdFilter($data, $filters)) {
            return false;
        }

        // Store filter
        if ($filters['store_id'] !== null && isset($data['store_id'])) {
            $allowedStores = is_array($filters['store_id']) ? $filters['store_id'] : [$filters['store_id']];
            if (!in_array((int) $data['store_id'], $allowedStores)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getInfo(): array
    {
        $info = parent::getInfo();

        $info['file_path'] = $this->_filePath;
        $info['delimiter'] = $this->_delimiter;
        $info['file_exists'] = file_exists($this->_filePath);

        if (file_exists($this->_filePath)) {
            $info['file_size'] = filesize($this->_filePath);
            $info['file_size_human'] = $this->_getHelper()->formatBytes((int) $info['file_size']);
        }

        return $info;
    }
}
