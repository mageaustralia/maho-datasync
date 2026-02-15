<?php

/**
 * Maho DataSync Category Entity Handler
 *
 * Handles import of categories from external systems.
 * Manages tree structure, parent-child relationships, and store-scoped attributes.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Entity_Category extends Maho_DataSync_Model_Entity_Abstract
{
    protected array $_requiredFields = ['name'];

    protected array $_foreignKeyFields = [
        'parent_id' => [
            'entity_type' => 'category',
            'required' => false, // Root-level categories have no parent in source
        ],
    ];

    protected ?string $_externalRefField = 'url_key';

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getEntityType(): string
    {
        return 'category';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'Categories';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function findExisting(array $data): ?int
    {
        // Try to find by url_key within same parent first (most specific)
        if (!empty($data['url_key'])) {
            $collection = Mage::getModel('catalog/category')->getCollection()
                ->addAttributeToFilter('url_key', $data['url_key']);

            if (!empty($data['parent_id'])) {
                $collection->addFieldToFilter('parent_id', $data['parent_id']);
            }

            $collection->setPageSize(1);

            if ($collection->getSize() > 0) {
                return (int) $collection->getFirstItem()->getId();
            }
        }

        // Fallback: match by name within same parent
        if (!empty($data['name'])) {
            $collection = Mage::getModel('catalog/category')->getCollection()
                ->addAttributeToFilter('name', $data['name']);

            if (!empty($data['parent_id'])) {
                $collection->addFieldToFilter('parent_id', $data['parent_id']);
            }

            $collection->setPageSize(1);

            if ($collection->getSize() > 0) {
                return (int) $collection->getFirstItem()->getId();
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function import(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $existingId = $data['_existing_id'] ?? null;

        if ($existingId) {
            return $this->_updateCategory($existingId, $data, $registry);
        }

        return $this->_createCategory($data, $registry);
    }

    /**
     * Create a new category
     *
     * @throws Maho_DataSync_Exception
     */
    protected function _createCategory(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $this->_log("Creating category: {$data['name']}");

        // Determine parent category
        $parentId = $this->_resolveParentId($data);

        /** @var Mage_Catalog_Model_Category $parent */
        $parent = Mage::getModel('catalog/category')->load($parentId);
        if (!$parent->getId()) {
            throw new Maho_DataSync_Exception("Parent category #{$parentId} not found");
        }

        /** @var Mage_Catalog_Model_Category $category */
        $category = Mage::getModel('catalog/category');

        // Set parent path info
        $category->setPath($parent->getPath());

        // Store scope (default to admin/all stores)
        $storeId = (int) ($data['store_id'] ?? 0);
        $category->setStoreId($storeId);

        // Basic attributes
        $category->setName($this->_cleanString($data['name']));
        $category->setIsActive((int) ($data['is_active'] ?? 1));
        $category->setIncludeInMenu((int) ($data['include_in_menu'] ?? 1));

        // URL key (generate if not provided)
        $urlKey = $data['url_key'] ?? $this->_generateUrlKey($data['name']);
        $category->setUrlKey($urlKey);

        // Position in tree
        $category->setPosition((int) ($data['position'] ?? 0));

        // Description and content
        if (!empty($data['description'])) {
            $category->setDescription($data['description']);
        }

        // SEO attributes
        if (!empty($data['meta_title'])) {
            $category->setMetaTitle($data['meta_title']);
        }
        if (!empty($data['meta_keywords'])) {
            $category->setMetaKeywords($data['meta_keywords']);
        }
        if (!empty($data['meta_description'])) {
            $category->setMetaDescription($data['meta_description']);
        }

        // Display settings
        $displayMode = $data['display_mode'] ?? 'PRODUCTS';
        $category->setDisplayMode($displayMode);

        if (!empty($data['landing_page'])) {
            $category->setLandingPage($data['landing_page']);
        }

        // Layered navigation
        $category->setIsAnchor((int) ($data['is_anchor'] ?? 1));

        // Sorting
        if (!empty($data['available_sort_by'])) {
            $sortBy = is_array($data['available_sort_by'])
                ? $data['available_sort_by']
                : explode(',', $data['available_sort_by']);
            $category->setAvailableSortBy($sortBy);
        }
        if (!empty($data['default_sort_by'])) {
            $category->setDefaultSortBy($data['default_sort_by']);
        }

        // Custom design
        if (!empty($data['custom_design'])) {
            $category->setCustomDesign($data['custom_design']);
        }
        if (!empty($data['page_layout'])) {
            $category->setPageLayout($data['page_layout']);
        }
        if (!empty($data['custom_layout_update'])) {
            $category->setCustomLayoutUpdate($data['custom_layout_update']);
        }
        if (!empty($data['custom_use_parent_settings'])) {
            $category->setCustomUseParentSettings((int) $data['custom_use_parent_settings']);
        }

        // Price filter
        if (isset($data['filter_price_range'])) {
            $category->setFilterPriceRange((float) $data['filter_price_range']);
        }

        // Set DataSync tracking fields
        $sourceSystem = $data['_source_system'] ?? null;
        $sourceId = $data['entity_id'] ?? null;
        if ($sourceSystem) {
            $category->setData('datasync_source_system', $sourceSystem);
        }
        if ($sourceId) {
            $category->setData('datasync_source_id', (int) $sourceId);
        }
        $category->setData('datasync_imported_at', Mage_Core_Model_Locale::now());

        // Save category
        try {
            $category->save();
        } catch (Exception $e) {
            throw Maho_DataSync_Exception::importFailed(
                'category',
                $data['entity_id'] ?? 0,
                $data['_source_system'] ?? 'import',
                $e->getMessage(),
            );
        }

        // Handle image if provided
        if (!empty($data['image'])) {
            $this->_importCategoryImage($category, $data['image']);
        }

        // Set external reference for registry
        $data['_external_ref'] = $category->getUrlKey();

        $this->_log("Created category #{$category->getId()}: {$data['name']} (parent: #{$parentId})");

        return (int) $category->getId();
    }

    /**
     * Update an existing category
     *
     * @throws Maho_DataSync_Exception
     */
    protected function _updateCategory(int $categoryId, array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $this->_log("Updating category #{$categoryId}: {$data['name']}");

        /** @var Mage_Catalog_Model_Category $category */
        $category = Mage::getModel('catalog/category')->load($categoryId);

        if (!$category->getId()) {
            throw new Maho_DataSync_Exception("Category #{$categoryId} not found for update");
        }

        // Store scope
        $storeId = (int) ($data['store_id'] ?? 0);
        $category->setStoreId($storeId);

        // Update basic attributes
        if (!empty($data['name'])) {
            $category->setName($this->_cleanString($data['name']));
        }
        if (isset($data['is_active'])) {
            $category->setIsActive((int) $data['is_active']);
        }
        if (isset($data['include_in_menu'])) {
            $category->setIncludeInMenu((int) $data['include_in_menu']);
        }
        if (!empty($data['url_key'])) {
            $category->setUrlKey($data['url_key']);
        }
        if (isset($data['position'])) {
            $category->setPosition((int) $data['position']);
        }

        // Update content
        if (isset($data['description'])) {
            $category->setDescription($data['description']);
        }

        // Update SEO
        if (isset($data['meta_title'])) {
            $category->setMetaTitle($data['meta_title']);
        }
        if (isset($data['meta_keywords'])) {
            $category->setMetaKeywords($data['meta_keywords']);
        }
        if (isset($data['meta_description'])) {
            $category->setMetaDescription($data['meta_description']);
        }

        // Update display settings
        if (!empty($data['display_mode'])) {
            $category->setDisplayMode($data['display_mode']);
        }
        if (isset($data['landing_page'])) {
            $category->setLandingPage($data['landing_page']);
        }
        if (isset($data['is_anchor'])) {
            $category->setIsAnchor((int) $data['is_anchor']);
        }

        // Update sorting
        if (!empty($data['available_sort_by'])) {
            $sortBy = is_array($data['available_sort_by'])
                ? $data['available_sort_by']
                : explode(',', $data['available_sort_by']);
            $category->setAvailableSortBy($sortBy);
        }
        if (!empty($data['default_sort_by'])) {
            $category->setDefaultSortBy($data['default_sort_by']);
        }

        // Update design
        if (isset($data['custom_design'])) {
            $category->setCustomDesign($data['custom_design']);
        }
        if (isset($data['page_layout'])) {
            $category->setPageLayout($data['page_layout']);
        }
        if (isset($data['custom_layout_update'])) {
            $category->setCustomLayoutUpdate($data['custom_layout_update']);
        }

        // Handle parent change (moves category in tree)
        if (!empty($data['parent_id']) && $data['parent_id'] != $category->getParentId()) {
            $newParentId = $this->_resolveParentId($data);
            if ($newParentId != $category->getParentId()) {
                $this->_moveCategory($category, $newParentId);
            }
        }

        // Update DataSync tracking fields
        $sourceSystem = $data['_source_system'] ?? null;
        $sourceId = $data['entity_id'] ?? null;
        if ($sourceSystem) {
            $category->setData('datasync_source_system', $sourceSystem);
        }
        if ($sourceId) {
            $category->setData('datasync_source_id', (int) $sourceId);
        }
        $category->setData('datasync_imported_at', Mage_Core_Model_Locale::now());

        // Save
        try {
            $category->save();
        } catch (Exception $e) {
            throw Maho_DataSync_Exception::importFailed(
                'category',
                $data['entity_id'] ?? 0,
                $data['_source_system'] ?? 'import',
                'Update failed: ' . $e->getMessage(),
            );
        }

        // Handle image update
        if (!empty($data['image'])) {
            $this->_importCategoryImage($category, $data['image']);
        }

        $data['_external_ref'] = $category->getUrlKey();

        $this->_log("Updated category #{$categoryId}");

        return $categoryId;
    }

    /**
     * Resolve parent category ID
     */
    protected function _resolveParentId(array $data): int
    {
        // If parent_id was resolved via FK resolver, use it
        if (!empty($data['parent_id']) && is_numeric($data['parent_id']) && $data['parent_id'] > 1) {
            // Verify the parent exists
            $parent = Mage::getModel('catalog/category')->load($data['parent_id']);
            if ($parent->getId()) {
                return (int) $data['parent_id'];
            }
        }

        // Default to store root category
        $storeId = (int) ($data['store_id'] ?? Mage::app()->getStore()->getId());
        if ($storeId === 0) {
            $storeId = Mage::app()->getDefaultStoreView()->getId();
        }

        return $this->_getStoreRootCategoryId($storeId);
    }

    /**
     * Get the store's root category ID
     */
    protected function _getStoreRootCategoryId(?int $storeId = null): int
    {
        if ($storeId === null) {
            $storeId = Mage::app()->getDefaultStoreView()->getId();
        }

        $store = Mage::app()->getStore($storeId);
        $rootId = (int) $store->getRootCategoryId();

        // Fallback to default root category (usually ID 2)
        if ($rootId < 2) {
            $rootId = Mage::app()->getDefaultStoreView()->getRootCategoryId();
        }

        return $rootId ?: 2;
    }

    /**
     * Move category to new parent
     */
    protected function _moveCategory(Mage_Catalog_Model_Category $category, int $newParentId): void
    {
        try {
            $category->move($newParentId, 0);
            $this->_log("Moved category #{$category->getId()} to parent #{$newParentId}");
        } catch (Exception $e) {
            $this->_log(
                "Failed to move category #{$category->getId()}: " . $e->getMessage(),
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
        }
    }

    /**
     * Import category image
     *
     * @param string $image Image path or URL
     */
    protected function _importCategoryImage(Mage_Catalog_Model_Category $category, string $image): void
    {
        // Skip if image looks like a URL (would need downloading)
        if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
            $this->_log("Skipping image URL: {$image} (URL download not implemented)", Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG);
            return;
        }

        // Check if it's a path to existing file
        $mediaPath = Mage::getBaseDir('media') . DS . 'catalog' . DS . 'category' . DS;

        // If just filename, check in category media folder
        if (!str_contains($image, DS) && !str_contains($image, '/')) {
            if (file_exists($mediaPath . $image)) {
                $category->setImage($image);
                $category->save();
                $this->_log("Set category image: {$image}");
            }
        }
    }

    /**
     * Generate URL key from category name
     */
    protected function _generateUrlKey(string $name): string
    {
        return Mage::getModel('catalog/product_url')->formatUrlKey($name);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function validate(array $data): array
    {
        $errors = parent::validate($data);

        // Validate display_mode if provided
        if (!empty($data['display_mode'])) {
            $validModes = ['PRODUCTS', 'PAGE', 'PRODUCTS_AND_PAGE'];
            if (!in_array($data['display_mode'], $validModes)) {
                $errors[] = "Invalid display_mode: {$data['display_mode']}. Valid: " . implode(', ', $validModes);
            }
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function export(array $filters = []): iterable
    {
        $collection = Mage::getModel('catalog/category')->getCollection()
            ->addAttributeToSelect('*')
            ->addFieldToFilter('level', ['gt' => 1])  // Skip root categories
            ->setOrder('path', 'ASC');  // Ensure parents come before children

        if (!empty($filters['store_id'])) {
            $collection->setStoreId($filters['store_id']);
        }

        if (!empty($filters['limit'])) {
            $collection->setPageSize($filters['limit']);
        }

        foreach ($collection as $category) {
            yield $this->_exportCategory($category);
        }
    }

    /**
     * Export a single category
     */
    protected function _exportCategory(Mage_Catalog_Model_Category $category): array
    {
        return [
            'entity_id' => $category->getId(),
            'parent_id' => $category->getParentId(),
            'name' => $category->getName(),
            'url_key' => $category->getUrlKey(),
            'is_active' => $category->getIsActive(),
            'include_in_menu' => $category->getIncludeInMenu(),
            'position' => $category->getPosition(),
            'level' => $category->getLevel(),
            'path' => $category->getPath(),
            'description' => $category->getDescription(),
            'meta_title' => $category->getMetaTitle(),
            'meta_keywords' => $category->getMetaKeywords(),
            'meta_description' => $category->getMetaDescription(),
            'display_mode' => $category->getDisplayMode(),
            'is_anchor' => $category->getIsAnchor(),
            'image' => $category->getImage(),
        ];
    }
}
