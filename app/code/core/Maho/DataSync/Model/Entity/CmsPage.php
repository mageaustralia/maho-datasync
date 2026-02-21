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
 * DataSync CMS Page Entity Handler
 *
 * Handles import/export of CMS pages using direct SQL for speed.
 */
class Maho_DataSync_Model_Entity_CmsPage extends Maho_DataSync_Model_Entity_Abstract
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
        return 'cms_page';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'CMS Pages';
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

        $collection = Mage::getModel('cms/page')->getCollection()
            ->addFieldToFilter('identifier', $data['identifier']);

        // If store_ids provided, try to find page in matching store
        if (!empty($data['store_ids'])) {
            $storeIds = is_array($data['store_ids']) ? $data['store_ids'] : explode(',', (string) $data['store_ids']);
            $collection->addStoreFilter((int) $storeIds[0]);
        }

        $page = $collection->getFirstItem();
        return $page->getId() ? (int) $page->getId() : null;
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
                'Validation failed for cms_page: identifier and title are required',
                Maho_DataSync_Exception::CODE_VALIDATION_FAILED,
            );
        }

        $write = Mage::getSingleton('core/resource')->getConnection('core_write');
        $pageTable = Mage::getSingleton('core/resource')->getTableName('cms/page');
        $storeTable = Mage::getSingleton('core/resource')->getTableName('cms/page_store');

        $pageData = [
            'title' => $title,
            'identifier' => $identifier,
            'content' => $data['content'] ?? '',
            'is_active' => (int) ($data['is_active'] ?? 1),
            'root_template' => $data['root_template'] ?? 'one_column',
        ];

        // Optional fields
        foreach (['meta_keywords', 'meta_description', 'content_heading', 'right_content',
            'sort_order', 'layout_update_xml', 'custom_theme', 'custom_root_template',
            'custom_layout_update_xml', 'custom_theme_from', 'custom_theme_to', 'meta_robots'] as $field) {
            if (isset($data[$field])) {
                $pageData[$field] = $data[$field];
            }
        }

        if (isset($data['creation_time'])) {
            $pageData['creation_time'] = $this->_parseDate($data['creation_time']) ?? now();
        }
        $pageData['update_time'] = $this->_parseDate($data['update_time'] ?? '') ?? now();

        if ($existingId) {
            $write->update($pageTable, $pageData, ['page_id = ?' => $existingId]);
            $pageId = $existingId;
            $this->_log("Updated CMS page #{$pageId}: {$identifier}");
        } else {
            if (!isset($pageData['creation_time'])) {
                $pageData['creation_time'] = now();
            }
            $write->insert($pageTable, $pageData);
            $pageId = (int) $write->lastInsertId($pageTable);
            $this->_log("Imported CMS page #{$pageId}: {$identifier}");
        }

        // Sync store associations
        $this->_syncStoreAssociations($write, $storeTable, 'page_id', $pageId, $data);

        $data['_external_ref'] = $identifier;

        return $pageId;
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
        $collection = Mage::getModel('cms/page')->getCollection();

        if (!empty($filters['date_from'])) {
            $collection->addFieldToFilter('update_time', ['gteq' => $filters['date_from']]);
        }

        if (!empty($filters['date_to'])) {
            $collection->addFieldToFilter('update_time', ['lteq' => $filters['date_to']]);
        }

        if (!empty($filters['id_from'])) {
            $collection->addFieldToFilter('page_id', ['gteq' => $filters['id_from']]);
        }

        if (!empty($filters['id_to'])) {
            $collection->addFieldToFilter('page_id', ['lteq' => $filters['id_to']]);
        }

        $collection->setOrder('page_id', 'ASC');

        if (!empty($filters['limit'])) {
            $collection->setPageSize($filters['limit']);
        }

        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $storeTable = Mage::getSingleton('core/resource')->getTableName('cms/page_store');

        foreach ($collection as $page) {
            $row = $page->getData();

            // Add store_ids
            $storeIds = $read->fetchCol(
                "SELECT store_id FROM {$storeTable} WHERE page_id = ?",
                [$page->getId()],
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

        // Validate identifier format (alphanumeric, underscores, hyphens, forward slashes for paths)
        $identifier = $data['identifier'] ?? '';
        if (!empty($identifier) && !preg_match('/^[a-z0-9_\-\/]+$/i', $identifier)) {
            $errors[] = "Invalid page identifier format: {$identifier} (use alphanumeric, underscores, hyphens, forward slashes)";
        }

        return $errors;
    }
}
