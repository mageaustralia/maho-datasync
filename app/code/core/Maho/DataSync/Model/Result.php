<?php

/**
 * Maho DataSync Result
 *
 * Tracks the outcome of a sync operation including successes, updates, and errors.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Result
{
    protected string $_entityType = '';
    protected string $_sourceSystem = '';
    protected float $_startTime = 0.0;
    protected float $_endTime = 0.0;

    /** @var array<int, array{source_id: int, target_id: int, action: string}> */
    protected array $_successes = [];

    /** @var array<int, array{source_id: int, message: string, exception: ?Throwable}> */
    protected array $_errors = [];

    protected int $_createdCount = 0;
    protected int $_updatedCount = 0;
    protected int $_mergedCount = 0;
    protected int $_skippedCount = 0;

    // Dry-run tracking
    protected int $_wouldCreateCount = 0;
    protected int $_wouldUpdateCount = 0;
    protected int $_wouldMergeCount = 0;
    protected bool $_isDryRun = false;

    public function __construct()
    {
        $this->_startTime = microtime(true);
    }

    /**
     * Set entity type for this result
     */
    public function setEntityType(string $entityType): self
    {
        $this->_entityType = $entityType;
        return $this;
    }

    public function getEntityType(): string
    {
        return $this->_entityType;
    }

    /**
     * Set source system for this result
     */
    public function setSourceSystem(string $sourceSystem): self
    {
        $this->_sourceSystem = $sourceSystem;
        return $this;
    }

    public function getSourceSystem(): string
    {
        return $this->_sourceSystem;
    }

    /**
     * Record a successful create
     */
    public function addCreated(int $sourceId, int $targetId): self
    {
        $this->_successes[] = [
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'action' => 'created',
        ];
        $this->_createdCount++;

        return $this;
    }

    /**
     * Record a successful update
     */
    public function addUpdated(int $sourceId, int $targetId): self
    {
        $this->_successes[] = [
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'action' => 'updated',
        ];
        $this->_updatedCount++;

        return $this;
    }

    /**
     * Record a skipped record (already exists, duplicate handling set to skip)
     */
    public function addSkipped(int $sourceId, ?int $targetId = null, string $reason = 'skipped'): self
    {
        $this->_successes[] = [
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'action' => 'skipped',
            'reason' => $reason,
        ];
        $this->_skippedCount++;

        return $this;
    }

    /**
     * Record a merged record (existing record updated with non-empty source fields only)
     */
    public function addMerged(int $sourceId, int $targetId): self
    {
        $this->_successes[] = [
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'action' => 'merged',
        ];
        $this->_mergedCount++;

        return $this;
    }

    /**
     * Add success with explicit action
     */
    public function addSuccess(int $sourceId, int $targetId, string $action = 'created'): self
    {
        switch ($action) {
            case 'created':
                $this->addCreated($sourceId, $targetId);
                break;
            case 'updated':
                $this->addUpdated($sourceId, $targetId);
                break;
            case 'merged':
                $this->addMerged($sourceId, $targetId);
                break;
            case 'skipped':
                $this->addSkipped($sourceId, $targetId);
                break;
            case 'would_create':
                $this->_isDryRun = true;
                $this->_wouldCreateCount++;
                $this->_successes[] = ['source_id' => $sourceId, 'target_id' => $targetId, 'action' => $action];
                break;
            case 'would_update':
                $this->_isDryRun = true;
                $this->_wouldUpdateCount++;
                $this->_successes[] = ['source_id' => $sourceId, 'target_id' => $targetId, 'action' => $action];
                break;
            case 'would_merge':
                $this->_isDryRun = true;
                $this->_wouldMergeCount++;
                $this->_successes[] = ['source_id' => $sourceId, 'target_id' => $targetId, 'action' => $action];
                break;
            default:
                $this->_successes[] = ['source_id' => $sourceId, 'target_id' => $targetId, 'action' => $action];
        }

        return $this;
    }

    /**
     * Record an error
     */
    public function addError(int $sourceId, string $message, ?Throwable $exception = null): self
    {
        $this->_errors[] = [
            'source_id' => $sourceId,
            'message' => $message,
            'exception' => $exception,
        ];

        return $this;
    }

    /**
     * Record error from exception
     */
    public function addException(int $sourceId, Throwable $exception): self
    {
        return $this->addError($sourceId, $exception->getMessage(), $exception);
    }

    /**
     * Mark sync as complete
     */
    public function finish(): self
    {
        $this->_endTime = microtime(true);
        return $this;
    }

    /**
     * Get total records processed (success + error)
     */
    public function getTotal(): int
    {
        return count($this->_successes) + count($this->_errors);
    }

    /**
     * Get total successful records
     */
    public function getSuccessCount(): int
    {
        return count($this->_successes);
    }

    /**
     * Get created count
     */
    public function getCreated(): int
    {
        return $this->_createdCount;
    }

    /**
     * Get updated count
     */
    public function getUpdated(): int
    {
        return $this->_updatedCount;
    }

    /**
     * Get merged count
     */
    public function getMerged(): int
    {
        return $this->_mergedCount;
    }

    /**
     * Get skipped count
     */
    public function getSkipped(): int
    {
        return $this->_skippedCount;
    }

    /**
     * Check if this is a dry-run result
     */
    public function isDryRun(): bool
    {
        return $this->_isDryRun;
    }

    /**
     * Get would-create count (dry-run)
     */
    public function getWouldCreate(): int
    {
        return $this->_wouldCreateCount;
    }

    /**
     * Get would-update count (dry-run)
     */
    public function getWouldUpdate(): int
    {
        return $this->_wouldUpdateCount;
    }

    /**
     * Get would-merge count (dry-run)
     */
    public function getWouldMerge(): int
    {
        return $this->_wouldMergeCount;
    }

    /**
     * Get error count
     */
    public function getErrorCount(): int
    {
        return count($this->_errors);
    }

    /**
     * Check if there were any errors
     */
    public function hasErrors(): bool
    {
        return count($this->_errors) > 0;
    }

    /**
     * Check if sync was successful (no errors)
     */
    public function isSuccess(): bool
    {
        return !$this->hasErrors();
    }

    /**
     * Get all successes
     *
     * @return array<int, array{source_id: int, target_id: int, action: string}>
     */
    public function getSuccesses(): array
    {
        return $this->_successes;
    }

    /**
     * Get all errors
     *
     * @return array<int, array{source_id: int, message: string, exception: ?Throwable}>
     */
    public function getErrors(): array
    {
        return $this->_errors;
    }

    /**
     * Get error messages only
     *
     * @return array<int, string>
     */
    public function getErrorMessages(): array
    {
        return array_map(fn($error) => "ID {$error['source_id']}: {$error['message']}", $this->_errors);
    }

    /**
     * Get duration in seconds
     */
    public function getDuration(): float
    {
        $endTime = $this->_endTime > 0 ? $this->_endTime : microtime(true);
        return $endTime - $this->_startTime;
    }

    /**
     * Get records per second
     */
    public function getRecordsPerSecond(): float
    {
        $duration = $this->getDuration();
        if ($duration <= 0) {
            return 0.0;
        }

        return $this->getTotal() / $duration;
    }

    /**
     * Get summary array
     */
    public function toArray(): array
    {
        $data = [
            'entity_type' => $this->_entityType,
            'source_system' => $this->_sourceSystem,
            'total' => $this->getTotal(),
            'success_count' => $this->getSuccessCount(),
            'error_count' => $this->getErrorCount(),
            'duration_seconds' => round($this->getDuration(), 2),
            'records_per_second' => round($this->getRecordsPerSecond(), 2),
            'is_dry_run' => $this->_isDryRun,
        ];

        if ($this->_isDryRun) {
            $data['would_create'] = $this->_wouldCreateCount;
            $data['would_update'] = $this->_wouldUpdateCount;
            $data['would_merge'] = $this->_wouldMergeCount;
        } else {
            $data['created'] = $this->_createdCount;
            $data['updated'] = $this->_updatedCount;
            $data['merged'] = $this->_mergedCount;
            $data['skipped'] = $this->_skippedCount;
        }

        return $data;
    }

    /**
     * Get formatted summary string
     */
    public function getSummary(): string
    {
        $data = $this->toArray();

        if ($this->_isDryRun) {
            return sprintf(
                'Validated %d records in %.2fs. Would create: %d, Would update: %d, Would merge: %d, Errors: %d',
                $data['total'],
                $data['duration_seconds'],
                $data['would_create'],
                $data['would_update'],
                $data['would_merge'],
                $data['error_count'],
            );
        }

        $parts = [
            sprintf('Synced %d records in %.2fs (%.1f/s)', $data['total'], $data['duration_seconds'], $data['records_per_second']),
        ];

        $actions = [];
        if ($data['created'] > 0) {
            $actions[] = "Created: {$data['created']}";
        }
        if ($data['updated'] > 0) {
            $actions[] = "Updated: {$data['updated']}";
        }
        if ($data['merged'] > 0) {
            $actions[] = "Merged: {$data['merged']}";
        }
        if ($data['skipped'] > 0) {
            $actions[] = "Skipped: {$data['skipped']}";
        }
        if ($data['error_count'] > 0) {
            $actions[] = "Errors: {$data['error_count']}";
        }

        if (!empty($actions)) {
            $parts[] = implode(', ', $actions);
        }

        return implode('. ', $parts);
    }

    /**
     * Merge another result into this one
     */
    public function merge(Maho_DataSync_Model_Result $other): self
    {
        foreach ($other->getSuccesses() as $success) {
            $this->addSuccess($success['source_id'], $success['target_id'], $success['action']);
        }

        foreach ($other->getErrors() as $error) {
            $this->addError($error['source_id'], $error['message'], $error['exception']);
        }

        return $this;
    }
}
