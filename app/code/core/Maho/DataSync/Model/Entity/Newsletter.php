<?php

/**
 * Maho
 *
 * @package    Maho_DataSync
 * @copyright  Copyright (c) 2025-2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

/**
 * DataSync Newsletter Subscriber Entity Handler
 *
 * Handles import of newsletter subscribers using direct SQL for speed.
 */
class Maho_DataSync_Model_Entity_Newsletter extends Maho_DataSync_Model_Entity_Abstract
{
    protected array $_requiredFields = ['subscriber_email'];
    protected array $_foreignKeyFields = [];
    protected ?string $_externalRefField = 'subscriber_email';

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getEntityType(): string
    {
        return 'newsletter';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'Newsletter Subscribers';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function findExisting(array $data): ?int
    {
        if (empty($data['subscriber_email'])) {
            return null;
        }

        $subscriber = Mage::getModel('newsletter/subscriber')
            ->loadByEmail($data['subscriber_email']);

        return $subscriber->getId() ? (int) $subscriber->getId() : null;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function import(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $existingId = $data['_existing_id'] ?? null;
        $action = $data['_action'] ?? 'create';
        $isMerge = ($action === 'merge');

        $email = $this->_cleanString($data['subscriber_email'] ?? '');
        if (empty($email)) {
            throw new Maho_DataSync_Exception(
                'Validation failed for newsletter: subscriber_email is required',
                Maho_DataSync_Exception::CODE_VALIDATION_FAILED,
            );
        }

        // Use direct SQL for speed (matches shell script approach)
        $write = Mage::getSingleton('core/resource')->getConnection('core_write');
        $table = Mage::getSingleton('core/resource')->getTableName('newsletter/subscriber');

        // Resolve customer_id if present
        $localCustomerId = null;
        if (!empty($data['customer_id'])) {
            $localCustomerId = $this->_resolveCustomerId((int) $data['customer_id'], $email);
        }

        $status = (int) ($data['subscriber_status'] ?? 1);
        $storeId = (int) ($data['store_id'] ?? 1);
        $confirmCode = $data['subscriber_confirm_code'] ?? md5(uniqid());
        $changeStatusAt = $data['change_status_at'] ?? null;

        if ($existingId) {
            // Update existing subscriber
            $updateData = [
                'subscriber_status' => $status,
                'store_id' => $storeId,
            ];

            if ($localCustomerId) {
                $updateData['customer_id'] = $localCustomerId;
            }

            if ($changeStatusAt) {
                $updateData['change_status_at'] = $changeStatusAt;
            }

            $write->update($table, $updateData, ['subscriber_id = ?' => $existingId]);

            $this->_log("Updated newsletter subscriber #{$existingId}: {$email}");
            return $existingId;
        }

        // Insert new subscriber
        $insertData = [
            'subscriber_email' => $email,
            'subscriber_status' => $status,
            'subscriber_confirm_code' => $confirmCode,
            'store_id' => $storeId,
        ];

        if ($localCustomerId) {
            $insertData['customer_id'] = $localCustomerId;
        }

        if ($changeStatusAt) {
            $insertData['change_status_at'] = $changeStatusAt;
        }

        $write->insert($table, $insertData);
        $subscriberId = (int) $write->lastInsertId($table);

        $this->_log("Imported newsletter subscriber #{$subscriberId}: {$email}");

        $data['_external_ref'] = $email;

        return $subscriberId;
    }

    /**
     * Resolve live customer_id to local customer_id
     */
    protected function _resolveCustomerId(int $liveCustomerId, #[\SensitiveParameter]
        string $email): ?int
    {
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $table = Mage::getSingleton('core/resource')->getTableName('customer/entity');

        // Try by datasync_source_id first (if column exists)
        try {
            $sql = "SELECT entity_id FROM {$table} WHERE datasync_source_id = ?";
            $localId = $read->fetchOne($sql, [$liveCustomerId]);
            if ($localId) {
                return (int) $localId;
            }
        } catch (\Exception $e) {
            // Column doesn't exist, skip
        }

        // Most common case: ID matches between systems (same database dump)
        $sql = "SELECT entity_id FROM {$table} WHERE entity_id = ?";
        $localId = $read->fetchOne($sql, [$liveCustomerId]);
        if ($localId) {
            return (int) $localId;
        }

        // Fallback to email match
        $sql = "SELECT entity_id FROM {$table} WHERE email = ?";
        $localId = $read->fetchOne($sql, [$email]);

        return $localId ? (int) $localId : null;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function export(array $filters = []): iterable
    {
        $collection = Mage::getModel('newsletter/subscriber')->getCollection();

        if (!empty($filters['date_from'])) {
            $collection->addFieldToFilter('change_status_at', ['gteq' => $filters['date_from']]);
        }

        if (!empty($filters['date_to'])) {
            $collection->addFieldToFilter('change_status_at', ['lteq' => $filters['date_to']]);
        }

        if (!empty($filters['id_from'])) {
            $collection->addFieldToFilter('subscriber_id', ['gteq' => $filters['id_from']]);
        }

        if (!empty($filters['id_to'])) {
            $collection->addFieldToFilter('subscriber_id', ['lteq' => $filters['id_to']]);
        }

        $collection->setOrder('subscriber_id', 'ASC');

        if (!empty($filters['limit'])) {
            $collection->setPageSize($filters['limit']);
        }

        foreach ($collection as $subscriber) {
            yield $subscriber->getData();
        }
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function validate(array $data): array
    {
        $errors = parent::validate($data);

        // Validate email format
        $email = $data['subscriber_email'] ?? '';
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format: {$email}";
        }

        // Validate status
        if (isset($data['subscriber_status'])) {
            $status = (int) $data['subscriber_status'];
            if (!in_array($status, [1, 2, 3, 4])) {
                $errors[] = "Invalid subscriber_status: {$status} (expected 1-4)";
            }
        }

        return $errors;
    }
}
