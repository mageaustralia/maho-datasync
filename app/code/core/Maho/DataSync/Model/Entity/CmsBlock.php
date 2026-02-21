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
 * DataSync CMS Block Entity Handler
 *
 * Handles import/export of CMS static blocks using direct SQL for speed.
 */
class Maho_DataSync_Model_Entity_CmsBlock extends Maho_DataSync_Model_Entity_Abstract
{
    protected array $_requiredFields = ['identifier', 'title'];
    protected array $_foreignKeyFields = [];
    protected ?string $_externalRefField = 'identifier';

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getEntityType(): string
    {
        return 'cms_block';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'CMS Blocks';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function findExisting(array $data): ?int
    {
        if (empty($data['identifier'])) {
            return null;
        }

        $collection = Mage::getModel('cms/block')->getCollection()
            ->addFieldToFilter('identifier', $data['identifier']);

        // If store_ids provided, try to find block in matching store
        if (!empty($data['store_ids'])) {
            $storeIds = is_array($data['store_ids']) ? $data['store_ids'] : explode(',', (string) $data['store_ids']);
            $collection->addStoreFilter((int) $storeIds[0]);
        }

        $block = $collection->getFirstItem();
        return $block->getId() ? (int) $block->getId() : null;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function import(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $existingId = $data['_existing_id'] ?? null;

        $identifier = $this->_cleanString($data['identifier'] ?? '');
        $title = $this->_cleanString($data['title'] ?? '');

        if (empty($identifier) || empty($title)) {
            throw new Maho_DataSync_Exception(
                'Validation failed for cms_block: identifier and title are required',
                Maho_DataSync_Exception::CODE_VALIDATION_FAILED,
            );
        }

        $write = Mage::getSingleton('core/resource')->getConnection('core_write');
        $blockTable = Mage::getSingleton('core/resource')->getTableName('cms/block');
        $storeTable = Mage::getSingleton('core/resource')->getTableName('cms/block_store');

        $blockData = [
            'title' => $title,
            'identifier' => $identifier,
            'content' => $data['content'] ?? '',
            'is_active' => (int) ($data['is_active'] ?? 1),
        ];

        if (isset($data['creation_time'])) {
            $blockData['creation_time'] = $this->_parseDate($data['creation_time']) ?? now();
        }
        $blockData['update_time'] = $this->_parseDate($data['update_time'] ?? '') ?? now();

        if ($existingId) {
            $write->update($blockTable, $blockData, ['block_id = ?' => $existingId]);
            $blockId = $existingId;
            $this->_log("Updated CMS block #{$blockId}: {$identifier}");
        } else {
            if (!isset($blockData['creation_time'])) {
                $blockData['creation_time'] = now();
            }
            $write->insert($blockTable, $blockData);
            $blockId = (int) $write->lastInsertId($blockTable);
            $this->_log("Imported CMS block #{$blockId}: {$identifier}");
        }

        // Sync store associations
        $this->_syncStoreAssociations($write, $storeTable, 'block_id', $blockId, $data);

        $data['_external_ref'] = $identifier;

        return $blockId;
    }

    /**
     * Sync store associations for a CMS entity
     */
    protected function _syncStoreAssociations(
        Varien_Db_Adapter_Interface $write,
        string $storeTable,
        string $idField,
        int $entityId,
        array $data,
    ): void {
        // Delete existing store associations
        $write->delete($storeTable, ["{$idField} = ?" => $entityId]);

        // Determine store IDs
        $storeIds = [0]; // Default: all stores
        if (!empty($data['store_ids'])) {
            $storeIds = is_array($data['store_ids']) ? $data['store_ids'] : explode(',', (string) $data['store_ids']);
            $storeIds = array_map('intval', $storeIds);
        }

        // Insert new store associations
        foreach ($storeIds as $storeId) {
            $write->insert($storeTable, [
                $idField => $entityId,
                'store_id' => (int) $storeId,
            ]);
        }
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function export(array $filters = []): iterable
    {
        $collection = Mage::getModel('cms/block')->getCollection();

        if (!empty($filters['date_from'])) {
            $collection->addFieldToFilter('update_time', ['gteq' => $filters['date_from']]);
        }

        if (!empty($filters['date_to'])) {
            $collection->addFieldToFilter('update_time', ['lteq' => $filters['date_to']]);
        }

        if (!empty($filters['id_from'])) {
            $collection->addFieldToFilter('block_id', ['gteq' => $filters['id_from']]);
        }

        if (!empty($filters['id_to'])) {
            $collection->addFieldToFilter('block_id', ['lteq' => $filters['id_to']]);
        }

        $collection->setOrder('block_id', 'ASC');

        if (!empty($filters['limit'])) {
            $collection->setPageSize($filters['limit']);
        }

        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $storeTable = Mage::getSingleton('core/resource')->getTableName('cms/block_store');

        foreach ($collection as $block) {
            $row = $block->getData();

            // Add store_ids
            $storeIds = $read->fetchCol(
                "SELECT store_id FROM {$storeTable} WHERE block_id = ?",
                [$block->getId()],
            );
            $row['store_ids'] = array_map('intval', $storeIds);

            yield $row;
        }
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function validate(array $data): array
    {
        $errors = parent::validate($data);

        // Validate identifier format (alphanumeric, underscores, hyphens)
        $identifier = $data['identifier'] ?? '';
        if (!empty($identifier) && !preg_match('/^[a-z0-9_\-]+$/i', $identifier)) {
            $errors[] = "Invalid block identifier format: {$identifier} (use alphanumeric, underscores, hyphens)";
        }

        return $errors;
    }
}
