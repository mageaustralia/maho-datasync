<?php

/**
 * DataSync Product Entity Handler
 *
 * Handles import of products from external systems.
 *
 * NOTE: Bundle product options are not yet fully implemented.
 *
 * IMPLEMENTATION NOTES:
 * =====================
 *
 * 1. PRODUCT TYPES
 *    - simple: Basic product, single SKU
 *    - configurable: Parent product with simple children (color/size variants)
 *    - grouped: Collection of simple products sold together
 *    - bundle: Customizable product with component options
 *    - virtual: Non-physical product (no shipping)
 *    - downloadable: Digital products with file downloads
 *
 * 2. REQUIRED CSV/SOURCE FIELDS
 *    - sku (unique product identifier)
 *    - name (product name)
 *    - type_id (simple, configurable, etc.)
 *    - attribute_set_id (or attribute_set_code for lookup)
 *    - price (base price)
 *    - status (1=enabled, 2=disabled)
 *    - visibility (1=not visible, 2=catalog, 3=search, 4=both)
 *    - tax_class_id (tax class)
 *
 * 3. OPTIONAL FIELDS - BASIC
 *    - description, short_description (HTML content)
 *    - weight (for shipping calculations)
 *    - url_key (auto-generated from name if not provided)
 *    - meta_title, meta_keyword, meta_description (SEO)
 *    - special_price, special_from_date, special_to_date
 *    - cost (product cost for margin calculations)
 *    - msrp (manufacturer's suggested retail price)
 *
 * 4. INVENTORY FIELDS
 *    - qty (stock quantity)
 *    - is_in_stock (0/1)
 *    - manage_stock (0/1)
 *    - use_config_manage_stock (0/1)
 *    - min_qty, max_qty (stock limits)
 *    - min_sale_qty, max_sale_qty (cart limits)
 *    - backorders (0=no, 1=allow qty below 0, 2=allow and notify)
 *    - notify_stock_qty (low stock notification threshold)
 *
 * 5. CATEGORY ASSIGNMENT
 *    - category_ids: Comma-separated source category IDs
 *    - Resolve via registry to get Maho category IDs
 *    - Products can belong to multiple categories
 *
 * 6. IMAGE HANDLING
 *    - Images require special handling (download, resize, assign)
 *    - Fields: image, small_image, thumbnail (main images)
 *    - media_gallery: Additional images (JSON or pipe-separated)
 *    - image_label, small_image_label, thumbnail_label
 *    - Consider: Download from URL, import from file path, or skip
 *
 * 7. CONFIGURABLE PRODUCTS
 *    - Import parent first, then children
 *    - super_attribute_ids: Attributes used for variants (e.g., color, size)
 *    - Link simple products to configurable via configurable_product_links
 *    - Pricing: Can be simple (same price) or per-variant
 *
 * 8. TIER PRICING
 *    - tier_prices: JSON array of tier price rules
 *    - Format: [{"website_id":0,"cust_group":0,"qty":10,"price":9.99}]
 *    - customer_group: 0=all groups, or specific group ID
 *
 * 9. CUSTOM OPTIONS
 *    - custom_options: JSON array of product options
 *    - Types: field, area, file, drop_down, radio, checkbox, multiple, date, date_time, time
 *    - Include option values for select types
 *
 * 10. RELATED/CROSS-SELL/UP-SELL
 *     - related_skus, cross_sell_skus, up_sell_skus
 *     - Comma-separated SKUs (resolve via registry or direct lookup)
 *
 * 11. WEBSITES
 *     - website_ids: Which websites product is assigned to
 *     - Default: All websites or current website
 *
 * 12. IMPORT STRATEGY
 *     a. Validate attribute set exists
 *     b. Check if product exists (by SKU)
 *     c. Create/update product with basic attributes
 *     d. Handle inventory separately (cataloginventory/stock_item)
 *     e. Assign categories (resolve via registry)
 *     f. Import images (if configured)
 *     g. Handle tier prices
 *     h. Set tracking attributes
 *     i. Reindex as needed
 *
 * 13. IMPORT EXAMPLE
 *     ```php
 *     $data = [
 *         'entity_id' => 1234,       // Source product ID
 *         'sku' => 'PROD-001',
 *         'name' => 'Example Product',
 *         'type_id' => 'simple',
 *         'attribute_set_code' => 'Default',
 *         'price' => 199.99,
 *         'status' => 1,
 *         'visibility' => 4,
 *         'qty' => 50,
 *         'category_ids' => '42,15,7',  // Source category IDs
 *     ];
 *
 *     // After import
 *     $registry->register('legacy_store', 'product', 1234, $newProductId);
 *     ```
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Entity_Product extends Maho_DataSync_Model_Entity_Abstract
{
    use Maho_DataSync_Model_Entity_Product_GroupPriceTrait;
    use Maho_DataSync_Model_Entity_Product_GroupedTrait;
    use Maho_DataSync_Model_Entity_Product_ConfigurableTrait;
    use Maho_DataSync_Model_Entity_Product_CustomOptionsTrait;
    use Maho_DataSync_Model_Entity_Product_BundleTrait;

    /**
     * Timing data for profiling
     */
    protected array $_timings = [
        'product_save' => 0.0,
        'images' => 0.0,
        'custom_options' => 0.0,
        'tier_prices' => 0.0,
        'group_prices' => 0.0,
        'categories' => 0.0,
        'stock_data' => 0.0,
        'configurable_links' => 0.0,
        'grouped_links' => 0.0,
        'custom_attributes' => 0.0,
        'total' => 0.0,
    ];

    protected int $_timingProductCount = 0;

    /**
     * Add time to a timing bucket
     */
    protected function _addTiming(string $key, float $elapsed): void
    {
        if (isset($this->_timings[$key])) {
            $this->_timings[$key] += $elapsed;
        }
        $this->_timings['total'] += $elapsed;
    }

    /**
     * Reset timings for a new batch
     */
    protected function _resetTimings(): void
    {
        foreach (array_keys($this->_timings) as $key) {
            $this->_timings[$key] = 0.0;
        }
        $this->_timingProductCount = 0;
    }

    /**
     * Log timing summary
     */
    protected function _logTimingSummary(): void
    {
        if ($this->_timingProductCount === 0) {
            return;
        }

        $total = $this->_timings['total'];
        $this->_log("=== TIMING SUMMARY ({$this->_timingProductCount} products) ===");

        // Build stats for both log and stats file
        $stats = [];
        $stats['timestamp'] = date('Y-m-d H:i:s');
        $stats['products'] = $this->_timingProductCount;
        $stats['total_time'] = round($total, 2);
        $stats['products_per_sec'] = $total > 0 ? round($this->_timingProductCount / $total, 2) : 0;

        foreach ($this->_timings as $key => $time) {
            if ($key === 'total') {
                continue;
            }
            if ($time > 0) {
                $pct = ($total > 0) ? round(($time / $total) * 100, 1) : 0;
                $avg = round($time / $this->_timingProductCount * 1000, 1);
                $this->_log(sprintf('  %-20s: %6.2fs (%5.1f%%)  avg: %5.1fms/product', $key, $time, $pct, $avg));
                $stats[$key] = [
                    'time' => round($time, 2),
                    'pct' => $pct,
                    'avg_ms' => $avg,
                ];
            }
        }
        $this->_log(sprintf('  %-20s: %6.2fs', 'TOTAL', $total));

        // Write to stats file
        $statsFile = Mage::getBaseDir('var') . '/log/datasync_stats.log';
        $line = date('Y-m-d H:i:s') . ' | ' .
            sprintf('%d products in %.1fs (%.2f/s)', $this->_timingProductCount, $total, $stats['products_per_sec']) . ' | ' .
            sprintf(
                'save:%.0f%% img:%.0f%% attr:%.0f%% cat:%.0f%% opt:%.0f%% cfg:%.0f%%',
                $stats['product_save']['pct'] ?? 0,
                $stats['images']['pct'] ?? 0,
                $stats['custom_attributes']['pct'] ?? 0,
                $stats['categories']['pct'] ?? 0,
                $stats['custom_options']['pct'] ?? 0,
                $stats['configurable_links']['pct'] ?? 0,
            ) . "\n";
        file_put_contents($statsFile, $line, FILE_APPEND);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getEntityType(): string
    {
        return 'product';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'Products';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getRequiredFields(): array
    {
        return ['sku', 'name', 'price'];
    }

    /**
     * Get optional fields that can be imported
     *
     * @return array<string>
     */
    public function getOptionalFields(): array
    {
        return [
            // Basic
            'type_id',
            'attribute_set_id',
            'attribute_set_code',
            'status',
            'visibility',
            'tax_class_id',
            'weight',
            'url_key',

            // Descriptions
            'description',
            'short_description',
            'meta_title',
            'meta_keyword',
            'meta_description',

            // Pricing
            'special_price',
            'special_from_date',
            'special_to_date',
            'cost',
            'msrp',
            'tier_prices',

            // Inventory
            'qty',
            'is_in_stock',
            'manage_stock',
            'min_qty',
            'max_qty',
            'min_sale_qty',
            'max_sale_qty',
            'backorders',
            'notify_stock_qty',

            // Categories
            'category_ids',

            // Images
            'image',
            'small_image',
            'thumbnail',
            'media_gallery',

            // Websites
            'website_ids',

            // Related products
            'related_skus',
            'cross_sell_skus',
            'up_sell_skus',

            // Group prices (customer group pricing)
            'group_prices',

            // Configurable product links
            'configurable_children_skus',  // On configurable: pipe/comma-separated child SKUs
            'configurable_parent_sku',     // On simple: parent configurable SKU
            'super_attributes',            // Attribute codes for configurable (color,size)
            'super_attribute_ids',         // Legacy - attribute IDs
            'configurable_product_links',  // Legacy

            // Grouped product links
            'grouped_product_skus',  // On grouped: SKU:qty|SKU:qty
            'grouped_parent_sku',    // On simple: parent grouped SKU
            'grouped_qty',           // Default qty when using grouped_parent_sku

            // Custom options
            'custom_options',

            // Bundle options (not yet implemented)
            'bundle_options',
        ];
    }

    /**
     * @inheritDoc
     *
     * Note: category_ids is NOT a FK field because:
     * 1. It can contain multiple IDs (comma-separated)
     * 2. It can be either source IDs (registry) or direct target IDs
     * 3. We handle resolution manually in _resolveCategoryIds()
     */
    #[\Override]
    public function getForeignKeyFields(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function import(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $existingId = $data['_existing_id'] ?? null;

        if ($existingId) {
            // Use fast update by default (uses updateAttributes for speed)
            // Fall back to slow update only if explicitly disabled
            $useFastUpdate = $data['_entity_options']['fast_update'] ?? true;
            if ($useFastUpdate) {
                return $this->_updateProductFast($existingId, $data, $registry);
            }
            return $this->_updateProduct($existingId, $data, $registry);
        }

        return $this->_createProduct($data, $registry);
    }

    /**
     * Create a new product
     *
     * @throws Maho_DataSync_Exception
     */
    protected function _createProduct(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $this->_log("Creating product: {$data['sku']}");

        /** @var Mage_Catalog_Model_Product $product */
        $product = Mage::getModel('catalog/product');

        // Product type (default: simple)
        $typeId = $data['type_id'] ?? Mage_Catalog_Model_Product_Type::TYPE_SIMPLE;
        $product->setTypeId($typeId);

        // Attribute set
        $attributeSetId = $this->_resolveAttributeSetId($data);
        $product->setAttributeSetId($attributeSetId);

        // Store (default: admin/all stores)
        $storeId = (int) ($data['store_id'] ?? 0);
        $product->setStoreId($storeId);

        // Required attributes
        $product->setSku($data['sku']);
        $product->setName($this->_cleanString($data['name']));
        $product->setPrice((float) $data['price']);

        // Status and visibility
        $product->setStatus((int) ($data['status'] ?? Mage_Catalog_Model_Product_Status::STATUS_ENABLED));
        $product->setVisibility((int) ($data['visibility'] ?? Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH));

        // Tax class (default: Taxable Goods = 2)
        $product->setTaxClassId((int) ($data['tax_class_id'] ?? 2));

        // URL key
        $urlKey = $data['url_key'] ?? $this->_generateUrlKey($data['name']);
        $product->setUrlKey($urlKey);

        // Weight (required for shipping)
        if (isset($data['weight'])) {
            $product->setWeight((float) $data['weight']);
        }

        // Descriptions
        if (!empty($data['description'])) {
            $product->setDescription($data['description']);
        }
        if (!empty($data['short_description'])) {
            $product->setShortDescription($data['short_description']);
        }

        // SEO
        if (!empty($data['meta_title'])) {
            $product->setMetaTitle($data['meta_title']);
        }
        if (!empty($data['meta_keyword'])) {
            $product->setMetaKeyword($data['meta_keyword']);
        }
        if (!empty($data['meta_description'])) {
            $product->setMetaDescription($data['meta_description']);
        }

        // Special pricing
        if (isset($data['special_price'])) {
            $product->setSpecialPrice((float) $data['special_price']);
        }
        if (!empty($data['special_from_date'])) {
            $product->setSpecialFromDate($data['special_from_date']);
        }
        if (!empty($data['special_to_date'])) {
            $product->setSpecialToDate($data['special_to_date']);
        }

        // Cost and MSRP
        if (isset($data['cost'])) {
            $product->setCost((float) $data['cost']);
        }
        if (isset($data['msrp'])) {
            $product->setMsrp((float) $data['msrp']);
        }

        // Websites (default: all)
        $websiteIds = $this->_resolveWebsiteIds($data);
        $product->setWebsiteIds($websiteIds);

        // Set custom attributes (any attributes not explicitly handled)
        $t0 = microtime(true);
        $this->_setCustomAttributes($product, $data);
        $this->_addTiming('custom_attributes', microtime(true) - $t0);

        // Assign categories before save
        if (!empty($data['category_ids'])) {
            $t0 = microtime(true);
            $categoryIds = $this->_resolveCategoryIds(
                $data['category_ids'],
                $registry,
                $data['_source_system'] ?? '',
            );
            if (!empty($categoryIds)) {
                $product->setCategoryIds($categoryIds);
            }
            $this->_addTiming('categories', microtime(true) - $t0);
        }

        // Set stock data BEFORE save (canonical approach)
        $t0 = microtime(true);
        $stockData = $this->_prepareStockData($data);
        if (!empty($stockData)) {
            $product->setStockData($stockData);
        }
        $this->_addTiming('stock_data', microtime(true) - $t0);

        // Set DataSync tracking fields
        $sourceSystem = $data['_source_system'] ?? null;
        $sourceId = $data['entity_id'] ?? null;
        if ($sourceSystem) {
            $product->setData('datasync_source_system', $sourceSystem);
        }
        if ($sourceId) {
            $product->setData('datasync_source_id', (int) $sourceId);
        }
        $product->setData('datasync_imported_at', Mage_Core_Model_Locale::now());

        // Save product
        $t0 = microtime(true);
        try {
            $product->save();
        } catch (Exception $e) {
            throw Maho_DataSync_Exception::importFailed(
                'product',
                $data['entity_id'] ?? 0,
                $data['_source_system'] ?? 'import',
                $e->getMessage(),
            );
        }
        $this->_addTiming('product_save', microtime(true) - $t0);

        $productId = (int) $product->getId();
        $this->_timingProductCount++;

        // Handle tier prices
        if (!empty($data['tier_prices'])) {
            $t0 = microtime(true);
            $this->_importTierPrices($product, $data['tier_prices']);
            $this->_addTiming('tier_prices', microtime(true) - $t0);
        }

        // Handle group prices (customer group-specific pricing)
        if (!empty($data['group_prices'])) {
            $t0 = microtime(true);
            $this->_importGroupPrices($product, $data['group_prices']);
            $product->save();
            $this->_addTiming('group_prices', microtime(true) - $t0);
        }

        // Handle images
        $t0 = microtime(true);
        $this->_importImages($product, $data);
        $this->_addTiming('images', microtime(true) - $t0);

        // Handle custom options
        if (!empty($data['custom_options'])) {
            $t0 = microtime(true);
            $optionsMode = $data['_entity_options']['options_mode'] ?? 'replace';
            $this->_importCustomOptions($product, $data['custom_options'], $optionsMode);
            $this->_addTiming('custom_options', microtime(true) - $t0);
        }

        // Handle bundle options (not yet implemented)
        if (!empty($data['bundle_options'])) {
            $this->_importBundleOptions($product, $data['bundle_options']);
        }

        // Collect configurable links for batch processing
        $autoLinkEnabled = !empty($data['_entity_options']['auto_link_configurables']);
        $this->_collectConfigurableLinks($data, $data['sku'], $autoLinkEnabled);

        // Collect grouped links for batch processing
        $this->_collectGroupedLinks($data, $data['sku']);

        // Set external reference
        $data['_external_ref'] = $product->getSku();

        $this->_log("Created product #{$productId}: {$data['sku']}");

        return $productId;
    }

    /**
     * Update an existing product
     *
     * @throws Maho_DataSync_Exception
     */
    protected function _updateProduct(int $productId, array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $this->_log("Updating product #{$productId}: {$data['sku']}");

        /** @var Mage_Catalog_Model_Product $product */
        $product = Mage::getModel('catalog/product')->load($productId);

        if (!$product->getId()) {
            throw new Maho_DataSync_Exception("Product #{$productId} not found for update");
        }

        // Store scope
        $storeId = (int) ($data['store_id'] ?? 0);
        $product->setStoreId($storeId);

        // Update basic attributes
        if (!empty($data['name'])) {
            $product->setName($this->_cleanString($data['name']));
        }
        if (isset($data['price'])) {
            $product->setPrice((float) $data['price']);
        }
        if (isset($data['status'])) {
            $product->setStatus((int) $data['status']);
        }
        if (isset($data['visibility'])) {
            $product->setVisibility((int) $data['visibility']);
        }
        if (isset($data['tax_class_id'])) {
            $product->setTaxClassId((int) $data['tax_class_id']);
        }
        if (!empty($data['url_key'])) {
            $product->setUrlKey($data['url_key']);
        }
        if (isset($data['weight'])) {
            $product->setWeight((float) $data['weight']);
        }

        // Descriptions
        if (isset($data['description'])) {
            $product->setDescription($data['description']);
        }
        if (isset($data['short_description'])) {
            $product->setShortDescription($data['short_description']);
        }

        // SEO
        if (isset($data['meta_title'])) {
            $product->setMetaTitle($data['meta_title']);
        }
        if (isset($data['meta_keyword'])) {
            $product->setMetaKeyword($data['meta_keyword']);
        }
        if (isset($data['meta_description'])) {
            $product->setMetaDescription($data['meta_description']);
        }

        // Special pricing
        if (isset($data['special_price'])) {
            $product->setSpecialPrice($data['special_price'] !== '' ? (float) $data['special_price'] : null);
        }
        if (isset($data['special_from_date'])) {
            $product->setSpecialFromDate($data['special_from_date'] ?: null);
        }
        if (isset($data['special_to_date'])) {
            $product->setSpecialToDate($data['special_to_date'] ?: null);
        }

        // Cost and MSRP
        if (isset($data['cost'])) {
            $product->setCost((float) $data['cost']);
        }
        if (isset($data['msrp'])) {
            $product->setMsrp((float) $data['msrp']);
        }

        // Websites
        if (!empty($data['website_ids'])) {
            $websiteIds = $this->_resolveWebsiteIds($data);
            $product->setWebsiteIds($websiteIds);
        }

        // Set custom attributes
        $t0 = microtime(true);
        $this->_setCustomAttributes($product, $data);
        $this->_addTiming('custom_attributes', microtime(true) - $t0);

        // Update categories before save
        if (!empty($data['category_ids'])) {
            $t0 = microtime(true);
            $categoryIds = $this->_resolveCategoryIds(
                $data['category_ids'],
                $registry,
                $data['_source_system'] ?? '',
            );
            if (!empty($categoryIds)) {
                $product->setCategoryIds($categoryIds);
            }
            $this->_addTiming('categories', microtime(true) - $t0);
        }

        // Set stock data for save
        $t0 = microtime(true);
        $stockData = $this->_prepareStockData($data);
        if (!empty($stockData)) {
            $product->setStockData($stockData);
        }
        $this->_addTiming('stock_data', microtime(true) - $t0);

        // Update DataSync tracking fields
        $sourceSystem = $data['_source_system'] ?? null;
        $sourceId = $data['entity_id'] ?? null;
        if ($sourceSystem) {
            $product->setData('datasync_source_system', $sourceSystem);
        }
        if ($sourceId) {
            $product->setData('datasync_source_id', (int) $sourceId);
        }
        $product->setData('datasync_imported_at', Mage_Core_Model_Locale::now());

        // Clear custom options before save if replacing (prevents validation errors from corrupted existing options)
        if (!empty($data['custom_options'])) {
            $optionsMode = $data['_entity_options']['options_mode'] ?? 'replace';
            if ($optionsMode === 'replace') {
                foreach ($product->getProductOptionsCollection() as $option) {
                    $option->delete();
                }
                // Reset options data on product to prevent _afterSave from reprocessing
                $product->setData('product_options', []);
            }
        }

        // Save product
        $t0 = microtime(true);
        try {
            $product->save();
        } catch (Exception $e) {
            throw Maho_DataSync_Exception::importFailed(
                'product',
                $data['entity_id'] ?? 0,
                $data['_source_system'] ?? 'import',
                'Update failed: ' . $e->getMessage(),
            );
        }
        $this->_addTiming('product_save', microtime(true) - $t0);
        $this->_timingProductCount++;

        // Handle tier prices
        if (!empty($data['tier_prices'])) {
            $t0 = microtime(true);
            $this->_importTierPrices($product, $data['tier_prices']);
            $this->_addTiming('tier_prices', microtime(true) - $t0);
        }

        // Handle group prices (customer group-specific pricing)
        if (!empty($data['group_prices'])) {
            $t0 = microtime(true);
            $this->_importGroupPrices($product, $data['group_prices']);
            $product->save();
            $this->_addTiming('group_prices', microtime(true) - $t0);
        }

        // Handle images
        $t0 = microtime(true);
        $this->_importImages($product, $data);
        $this->_addTiming('images', microtime(true) - $t0);

        // Handle custom options
        if (!empty($data['custom_options'])) {
            $t0 = microtime(true);
            $optionsMode = $data['_entity_options']['options_mode'] ?? 'replace';
            $this->_importCustomOptions($product, $data['custom_options'], $optionsMode);
            $this->_addTiming('custom_options', microtime(true) - $t0);
        }

        // Handle bundle options (not yet implemented)
        if (!empty($data['bundle_options'])) {
            $this->_importBundleOptions($product, $data['bundle_options']);
        }

        // Collect configurable links for batch processing
        $autoLinkEnabled = !empty($data['_entity_options']['auto_link_configurables']);
        $this->_collectConfigurableLinks($data, $data['sku'], $autoLinkEnabled);

        // Collect grouped links for batch processing
        $this->_collectGroupedLinks($data, $data['sku']);

        $data['_external_ref'] = $product->getSku();

        $this->_log("Updated product #{$productId}");

        return $productId;
    }

    /**
     * Fast update of an existing product using bulk attribute update
     *
     * Uses Mage::getSingleton('catalog/product_action')->updateAttributes() for speed.
     * Falls back to full save only when necessary (custom options, new images, etc.)
     *
     * @throws Maho_DataSync_Exception
     */
    protected function _updateProductFast(int $productId, array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $this->_log("Fast-updating product #{$productId}: {$data['sku']}");
        $this->_timingProductCount++;

        $storeId = (int) ($data['store_id'] ?? 0);

        // Build attribute data for bulk update
        $attrData = [];

        // Standard attributes mapping: data key => attribute code
        $standardAttrs = [
            'name' => 'name',
            'price' => 'price',
            'status' => 'status',
            'visibility' => 'visibility',
            'tax_class_id' => 'tax_class_id',
            'url_key' => 'url_key',
            'weight' => 'weight',
            'description' => 'description',
            'short_description' => 'short_description',
            'meta_title' => 'meta_title',
            'meta_keyword' => 'meta_keyword',
            'meta_description' => 'meta_description',
            'special_price' => 'special_price',
            'special_from_date' => 'special_from_date',
            'special_to_date' => 'special_to_date',
            'cost' => 'cost',
            'msrp' => 'msrp',
        ];

        foreach ($standardAttrs as $dataKey => $attrCode) {
            if (isset($data[$dataKey])) {
                $value = $data[$dataKey];
                // Clean strings
                if ($dataKey === 'name') {
                    $value = $this->_cleanString($value);
                }
                // Handle numeric types
                if (in_array($dataKey, ['price', 'weight', 'special_price', 'cost', 'msrp'])) {
                    $value = $value !== '' ? (float) $value : null;
                }
                if (in_array($dataKey, ['status', 'visibility', 'tax_class_id'])) {
                    $value = (int) $value;
                }
                $attrData[$attrCode] = $value;
            }
        }

        // Add custom attributes
        $t0 = microtime(true);
        $skipFields = array_merge(array_keys($standardAttrs), [
            'entity_id', 'sku', 'type_id', 'attribute_set_id', 'attribute_set_code',
            'website_ids', 'category_ids', 'qty', 'is_in_stock', 'manage_stock',
            'min_qty', 'max_qty', 'min_sale_qty', 'max_sale_qty', 'backorders',
            'notify_stock_qty', 'tier_prices', 'group_prices', 'store_id',
            '_source_system', '_existing_id', '_external_ref', '_entity_options',
            'image', 'small_image', 'thumbnail', 'media_gallery', 'images',
            'custom_options', 'configurable_attributes', 'configurable_children_skus',
            'configurable_parent_sku', 'grouped_products', 'bundle_options',
        ]);

        // Load product attributes once for select/multiselect resolution
        $product = Mage::getModel('catalog/product')->load($productId);
        if (!$product->getId()) {
            throw new Maho_DataSync_Exception("Product #{$productId} not found for update");
        }

        $attributes = $product->getAttributes();

        // Only include attributes that:
        // 1. Actually exist in EAV
        // 2. Are NOT 'static' backend type (those are stored in main table, not EAV value tables)
        // updateAttributes() only works with EAV attributes that have value tables
        $validAttrCodes = [];
        foreach ($attributes as $attrCode => $attribute) {
            $backendType = $attribute->getBackendType();
            // Skip static attributes (sku, entity_id, etc) - they don't have EAV value tables
            if ($backendType && $backendType !== 'static') {
                $validAttrCodes[] = $attrCode;
            }
        }

        // Filter standard attributes to only valid ones
        $attrData = array_filter($attrData, function ($key) use ($validAttrCodes) {
            return in_array($key, $validAttrCodes);
        }, ARRAY_FILTER_USE_KEY);

        // Add custom attributes (only if they exist in EAV and are not static)
        foreach ($data as $key => $value) {
            if (in_array($key, $skipFields) || str_starts_with($key, '_')) {
                continue;
            }
            // Only process attributes that are in our valid (non-static) list
            if (in_array($key, $validAttrCodes) && isset($attributes[$key])) {
                $attribute = $attributes[$key];
                $frontendInput = $attribute->getFrontendInput();
                // Resolve select/multiselect option values by label
                if (in_array($frontendInput, ['select', 'multiselect']) && !empty($value) && !is_numeric($value)) {
                    $value = $this->_resolveOptionValue($attribute, $value);
                }
                if ($value !== null && $value !== '') {
                    $attrData[$key] = $value;
                }
            }
        }
        $this->_addTiming('custom_attributes', microtime(true) - $t0);

        // Add tracking fields (only if they exist as attributes)
        $sourceSystem = $data['_source_system'] ?? null;
        $sourceId = $data['entity_id'] ?? null;
        if ($sourceSystem && in_array('datasync_source_system', $validAttrCodes)) {
            $attrData['datasync_source_system'] = $sourceSystem;
        }
        if ($sourceId && in_array('datasync_source_id', $validAttrCodes)) {
            $attrData['datasync_source_id'] = (int) $sourceId;
        }
        if (in_array('datasync_imported_at', $validAttrCodes)) {
            $attrData['datasync_imported_at'] = Mage_Core_Model_Locale::now();
        }

        // Bulk update attributes - much faster than model save
        $t0 = microtime(true);
        if (!empty($attrData)) {
            Mage::getSingleton('catalog/product_action')
                ->updateAttributes([$productId], $attrData, $storeId);
        }
        $this->_addTiming('product_save', microtime(true) - $t0);

        // Update stock data directly
        $t0 = microtime(true);
        $stockData = $this->_prepareStockData($data);
        if (!empty($stockData)) {
            $this->_updateStockItemDirect($productId, $stockData);
        }
        $this->_addTiming('stock_data', microtime(true) - $t0);

        // Update categories directly
        if (!empty($data['category_ids'])) {
            $t0 = microtime(true);
            $categoryIds = $this->_resolveCategoryIds(
                $data['category_ids'],
                $registry,
                $data['_source_system'] ?? '',
            );
            if (!empty($categoryIds)) {
                $this->_updateProductCategoriesDirect($productId, $categoryIds);
            }
            $this->_addTiming('categories', microtime(true) - $t0);
        }

        // Handle tier prices
        if (!empty($data['tier_prices'])) {
            $t0 = microtime(true);
            $this->_importTierPrices($product, $data['tier_prices']);
            $this->_addTiming('tier_prices', microtime(true) - $t0);
        }

        // Handle group prices
        if (!empty($data['group_prices'])) {
            $t0 = microtime(true);
            $this->_importGroupPrices($product, $data['group_prices']);
            $this->_addTiming('group_prices', microtime(true) - $t0);
        }

        // Handle images - skip during fast-update unless explicitly requested
        // During incremental sync, image EAV values (image, small_image, thumbnail) are
        // always present in source data even if unchanged. Re-processing them adds ~750ms
        // per product for file-exists checks + gallery re-add + a full $product->save().
        $t0 = microtime(true);
        $skipImages = $data['_entity_options']['skip_images_on_update'] ?? true;
        if (!$skipImages) {
            $this->_importImages($product, $data);
        }
        $this->_addTiming('images', microtime(true) - $t0);

        // Handle custom options - requires full model
        if (!empty($data['custom_options'])) {
            $t0 = microtime(true);
            $optionsMode = $data['_entity_options']['options_mode'] ?? 'replace';
            $this->_importCustomOptions($product, $data['custom_options'], $optionsMode);
            $this->_addTiming('custom_options', microtime(true) - $t0);
        }

        // Collect configurable links
        $autoLinkEnabled = !empty($data['_entity_options']['auto_link_configurables']);
        $this->_collectConfigurableLinks($data, $data['sku'], $autoLinkEnabled);

        // Collect grouped links
        $this->_collectGroupedLinks($data, $data['sku']);

        $data['_external_ref'] = $product->getSku();
        $this->_log("Fast-updated product #{$productId}");

        return $productId;
    }

    /**
     * Update stock item directly without loading full model
     */
    protected function _updateStockItemDirect(int $productId, array $stockData): void
    {
        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        $table = $resource->getTableName('cataloginventory/stock_item');

        // Check if stock item exists
        $stockItemId = $write->fetchOne(
            "SELECT item_id FROM {$table} WHERE product_id = ? AND stock_id = 1",
            [$productId],
        );

        if ($stockItemId) {
            $write->update($table, $stockData, "item_id = {$stockItemId}");
        } else {
            $stockData['product_id'] = $productId;
            $stockData['stock_id'] = 1;
            $write->insert($table, $stockData);
        }
    }

    /**
     * Update product categories directly without loading full model
     */
    protected function _updateProductCategoriesDirect(int $productId, array $categoryIds): void
    {
        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        $table = $resource->getTableName('catalog/category_product');

        // Get existing category links
        $existing = $write->fetchCol(
            "SELECT category_id FROM {$table} WHERE product_id = ?",
            [$productId],
        );

        $toAdd = array_diff($categoryIds, $existing);
        $toRemove = array_diff($existing, $categoryIds);

        // Add new
        foreach ($toAdd as $categoryId) {
            try {
                $write->insert($table, [
                    'category_id' => $categoryId,
                    'product_id' => $productId,
                    'position' => 0,
                ]);
            } catch (Exception $e) {
                // Ignore duplicates
            }
        }

        // Remove old
        if (!empty($toRemove)) {
            $write->delete($table, sprintf(
                'product_id = %d AND category_id IN (%s)',
                $productId,
                implode(',', array_map('intval', $toRemove)),
            ));
        }
    }

    /**
     * Resolve attribute set ID from data
     */
    protected function _resolveAttributeSetId(array $data): int
    {
        // Direct attribute_set_id
        if (!empty($data['attribute_set_id']) && is_numeric($data['attribute_set_id'])) {
            return (int) $data['attribute_set_id'];
        }

        // Lookup by code
        if (!empty($data['attribute_set_code'])) {
            $setId = $this->_getAttributeSetIdByCode($data['attribute_set_code']);
            if ($setId) {
                return $setId;
            }
            $this->_log(
                "Attribute set '{$data['attribute_set_code']}' not found, using default",
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
        }

        return $this->_getDefaultAttributeSetId();
    }

    /**
     * Resolve website IDs from data
     */
    protected function _resolveWebsiteIds(array $data): array
    {
        if (!empty($data['website_ids'])) {
            if (is_string($data['website_ids'])) {
                return array_map('intval', explode(',', $data['website_ids']));
            }
            return (array) $data['website_ids'];
        }

        // Default: all websites
        return Mage::app()->getWebsite()->getCollection()->getAllIds();
    }

    /**
     * Generate URL key from product name
     */
    protected function _generateUrlKey(string $name): string
    {
        return Mage::getModel('catalog/product_url')->formatUrlKey($name);
    }

    /**
     * Set custom attributes on product
     */
    protected function _setCustomAttributes(Mage_Catalog_Model_Product $product, array $data): void
    {
        // Get product attributes
        $attributes = $product->getAttributes();
        $skipFields = [
            'entity_id', 'sku', 'name', 'price', 'type_id', 'attribute_set_id',
            'attribute_set_code', 'status', 'visibility', 'tax_class_id', 'url_key',
            'weight', 'description', 'short_description', 'meta_title', 'meta_keyword',
            'meta_description', 'special_price', 'special_from_date', 'special_to_date',
            'cost', 'msrp', 'website_ids', 'category_ids', 'qty', 'is_in_stock',
            'manage_stock', 'min_qty', 'max_qty', 'min_sale_qty', 'max_sale_qty',
            'backorders', 'notify_stock_qty', 'tier_prices', 'store_id',
            '_source_system', '_existing_id', '_external_ref',
        ];

        foreach ($data as $key => $value) {
            if (in_array($key, $skipFields) || str_starts_with($key, '_')) {
                continue;
            }

            // Check if this is a valid product attribute
            if (isset($attributes[$key])) {
                $attribute = $attributes[$key];
                $frontendInput = $attribute->getFrontendInput();

                // Resolve select/multiselect option values by label
                if (in_array($frontendInput, ['select', 'multiselect']) && !empty($value) && !is_numeric($value)) {
                    $value = $this->_resolveOptionValue($attribute, $value);
                }

                if ($value !== null && $value !== '') {
                    $product->setData($key, $value);
                }
            }
        }
    }

    /**
     * Resolve option value(s) from label(s)
     *
     * @param Mage_Catalog_Model_Resource_Eav_Attribute $attribute
     * @param string|array $value Option label(s) or ID(s)
     * @return mixed Option ID(s) or original value
     */
    protected function _resolveOptionValue($attribute, $value)
    {
        /** @phpstan-ignore arguments.count */
        $options = $attribute->getSource()->getAllOptions(false);
        $optionMap = [];
        foreach ($options as $opt) {
            if (!empty($opt['value'])) {
                $optionMap[strtolower(trim($opt['label']))] = $opt['value'];
            }
        }

        $frontendInput = $attribute->getFrontendInput();

        if ($frontendInput === 'multiselect') {
            // Handle multiple values (comma-separated)
            $values = is_array($value) ? $value : explode(',', $value);
            $resolvedIds = [];
            foreach ($values as $v) {
                $v = strtolower(trim($v));
                if (isset($optionMap[$v])) {
                    $resolvedIds[] = $optionMap[$v];
                } elseif (is_numeric($v)) {
                    $resolvedIds[] = $v; // Already an ID
                }
            }
            return implode(',', $resolvedIds);
        }
        // Single select
        $v = strtolower(trim($value));
        if (isset($optionMap[$v])) {
            return $optionMap[$v];
        }
        if (is_numeric($value)) {
            return $value;
            // Already an ID
        }

        return null;
    }

    /**
     * Import tier prices for a product
     *
     * @param string|array $tierPrices JSON string or array
     */
    protected function _importTierPrices(Mage_Catalog_Model_Product $product, $tierPrices): void
    {
        if (is_string($tierPrices)) {
            $tierPrices = json_decode($tierPrices, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->_log(
                    "Invalid tier_prices JSON for product {$product->getSku()}",
                    Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
                );
                return;
            }
        }

        if (empty($tierPrices) || !is_array($tierPrices)) {
            return;
        }

        $formattedPrices = [];
        foreach ($tierPrices as $tier) {
            $formattedPrices[] = [
                'website_id' => $tier['website_id'] ?? 0,
                'cust_group' => $tier['cust_group'] ?? $tier['customer_group'] ?? Mage_Customer_Model_Group::CUST_GROUP_ALL,
                'price_qty' => $tier['qty'] ?? $tier['price_qty'] ?? 1,
                'price' => $tier['price'] ?? 0,
            ];
        }

        $product->setTierPrice($formattedPrices);
        $product->save();
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function findExisting(array $data): ?int
    {
        if (empty($data['sku'])) {
            return null;
        }

        $product = Mage::getModel('catalog/product')->loadByAttribute('sku', $data['sku']);

        if ($product && $product->getId()) {
            return (int) $product->getId();
        }

        return null;
    }

    /**
     * Get attribute set ID by code
     */
    protected function _getAttributeSetIdByCode(string $attributeSetCode): ?int
    {
        $entityTypeId = Mage::getModel('eav/entity')
            ->setType('catalog_product')
            ->getTypeId();

        $attributeSet = Mage::getModel('eav/entity_attribute_set')
            ->getCollection()
            ->addFieldToFilter('entity_type_id', $entityTypeId)
            ->addFieldToFilter('attribute_set_name', $attributeSetCode)
            ->getFirstItem();

        return $attributeSet && $attributeSet->getId()
            ? (int) $attributeSet->getId()
            : null;
    }

    /**
     * Get default attribute set ID for products
     */
    protected function _getDefaultAttributeSetId(): int
    {
        $entityTypeId = Mage::getModel('eav/entity')
            ->setType('catalog_product')
            ->getTypeId();

        return (int) Mage::getModel('eav/entity_type')
            ->load($entityTypeId)
            ->getDefaultAttributeSetId();
    }

    /**
     * Prepare stock data array for product
     */
    protected function _prepareStockData(array $data): array
    {
        $stockData = [];

        // Map stock fields
        $stockFields = [
            'qty', 'is_in_stock', 'manage_stock', 'use_config_manage_stock',
            'min_qty', 'max_qty', 'min_sale_qty', 'max_sale_qty',
            'backorders', 'notify_stock_qty',
        ];

        foreach ($stockFields as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $stockData[$field] = $data[$field];
            }
        }

        // Auto-set is_in_stock based on qty if not provided
        if (!isset($stockData['is_in_stock']) && isset($stockData['qty'])) {
            $stockData['is_in_stock'] = (float) $stockData['qty'] > 0 ? 1 : 0;
        }

        // Default manage_stock if qty is set
        if (isset($stockData['qty']) && !isset($stockData['manage_stock'])) {
            $stockData['manage_stock'] = 1;
            $stockData['use_config_manage_stock'] = 0;
        }

        return $stockData;
    }

    /**
     * Update product stock/inventory (for existing products)
     */
    protected function _updateStock(int $productId, array $stockData): void
    {
        $prepared = $this->_prepareStockData($stockData);
        if (empty($prepared)) {
            return;
        }

        $stockItem = Mage::getModel('cataloginventory/stock_item');
        $stockItem->loadByProduct($productId);

        if (!$stockItem->getId()) {
            $stockItem->setProductId($productId);
            $stockItem->setStockId(Mage_CatalogInventory_Model_Stock::DEFAULT_STOCK_ID);
        }

        foreach ($prepared as $key => $value) {
            $stockItem->setData($key, $value);
        }

        $stockItem->save();
    }

    /**
     * Resolve category IDs from source to target
     *
     * Handles both:
     * - Source IDs from registry (e.g., "1,2,3" where 1,2,3 are source system IDs)
     * - Direct target IDs (if no registry mapping exists, assumes they're direct Maho IDs)
     *
     * @param string|array $categoryIds Source category IDs (comma-separated or array)
     * @return array Resolved target category IDs
     */
    protected function _resolveCategoryIds($categoryIds, Maho_DataSync_Model_Registry $registry, string $sourceSystem): array
    {
        if (is_string($categoryIds)) {
            $categoryIds = explode(',', $categoryIds);
        }

        $targetCategoryIds = [];

        foreach ($categoryIds as $categoryId) {
            $categoryId = trim($categoryId);
            if (empty($categoryId)) {
                continue;
            }

            // Try to resolve via registry first (source ID -> target ID)
            if (!empty($sourceSystem)) {
                $targetId = $registry->resolve($sourceSystem, 'category', (int) $categoryId);
                if ($targetId) {
                    $targetCategoryIds[] = $targetId;
                    continue;
                }
            }

            // If no registry mapping, check if this is a valid direct category ID
            if (is_numeric($categoryId)) {
                $category = Mage::getModel('catalog/category')->load((int) $categoryId);
                if ($category->getId()) {
                    $targetCategoryIds[] = (int) $categoryId;
                } else {
                    $this->_log(
                        "Category ID {$categoryId} not found (neither in registry nor as direct ID)",
                        Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
                    );
                }
            }
        }

        return array_unique($targetCategoryIds);
    }

    /**
     * Import images for a product
     *
     * Handles image, small_image, thumbnail, and media_gallery columns.
     * Uses priority: local destination → base-path → base-url
     */
    protected function _importImages(Mage_Catalog_Model_Product $product, array $data): void
    {
        $options = $data['_entity_options'] ?? [];
        $baseUrl = $options['image_base_url'] ?? null;
        $basePath = $options['image_base_path'] ?? null;
        $skipExisting = $options['skip_existing_images'] ?? true;
        $timeout = $options['image_timeout'] ?? 30000;

        // Nothing to do if no image fields and no base sources
        $imageFields = ['image', 'small_image', 'thumbnail'];
        $hasImages = false;
        foreach ($imageFields as $field) {
            if (!empty($data[$field])) {
                $hasImages = true;
                break;
            }
        }
        if (!$hasImages && empty($data['media_gallery'])) {
            return;
        }

        $mediaDir = Mage::getBaseDir('media') . DS . 'catalog' . DS . 'product';
        $importedImages = [];
        $needsSave = false;

        // Process main image roles (image, small_image, thumbnail)
        foreach ($imageFields as $field) {
            if (empty($data[$field])) {
                continue;
            }

            // Skip "no_selection" placeholder values
            $rawPath = trim($data[$field], '/');
            if ($rawPath === 'no_selection' || $rawPath === '') {
                continue;
            }

            $imagePath = $this->_normalizeImagePath($data[$field]);
            $localPath = $this->_resolveImage($imagePath, $mediaDir, $basePath, $baseUrl, $skipExisting, $timeout);

            if ($localPath) {
                $changed = $this->_assignImageRole($product, $localPath, $field);
                $importedImages[$imagePath] = $localPath;
                if ($changed) {
                    $needsSave = true;
                }
            }
        }

        // Process media gallery (additional images)
        if (!empty($data['media_gallery'])) {
            $galleryImages = $this->_parseGalleryImages($data['media_gallery']);
            $galleryBefore = count($product->getMediaGallery()['images'] ?? []);

            foreach ($galleryImages as $imagePath) {
                // Skip "no_selection" placeholder values
                $rawPath = trim($imagePath, '/');
                if ($rawPath === 'no_selection' || $rawPath === '') {
                    continue;
                }

                $imagePath = $this->_normalizeImagePath($imagePath);

                // Skip if already imported as a main image
                if (isset($importedImages[$imagePath])) {
                    continue;
                }

                $localPath = $this->_resolveImage($imagePath, $mediaDir, $basePath, $baseUrl, $skipExisting, $timeout);

                if ($localPath) {
                    $this->_addToGallery($product, $localPath);
                    $importedImages[$imagePath] = $localPath;
                }
            }

            // Only need save if gallery actually grew
            $galleryAfter = count($product->getMediaGallery()['images'] ?? []);
            if ($galleryAfter > $galleryBefore) {
                $needsSave = true;
            }
        }

        // Save once after all image operations
        if ($needsSave) {
            // Temporarily unset stock data to avoid duplicate stock item error
            $stockData = $product->getStockData();
            $product->unsetData('stock_data');

            try {
                $product->save();
            } finally {
                // Restore stock data
                if ($stockData) {
                    $product->setStockData($stockData);
                }
            }
        }
    }

    /**
     * Normalize image path (remove leading slashes, etc.)
     */
    protected function _normalizeImagePath(string $path): string
    {
        $path = trim($path);

        // If it's a full URL, return as-is
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Normalize slashes
        $path = str_replace('\\', '/', $path);

        // Remove leading slash for consistency
        return ltrim($path, '/');
    }

    /**
     * Resolve image: check local, then base-path, then base-url
     *
     * @param string $imagePath Relative image path (e.g., "m/a/main.jpg")
     * @param string $mediaDir Local media directory
     * @param string|null $basePath Base path for local copies
     * @param string|null $baseUrl Base URL for downloads
     * @param bool $skipExisting Skip if already exists locally
     * @param int $timeout Download timeout in milliseconds
     * @return string|null Local path relative to media dir, or null if failed
     */
    protected function _resolveImage(
        string $imagePath,
        string $mediaDir,
        ?string $basePath,
        ?string $baseUrl,
        bool $skipExisting,
        int $timeout,
    ): ?string {
        // Handle full URLs directly
        if (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
            return $this->_downloadImage($imagePath, $mediaDir, $timeout);
        }

        $destinationFile = $mediaDir . DS . $imagePath;

        // 1. Check if already exists at destination
        if (file_exists($destinationFile)) {
            if ($skipExisting) {
                $this->_log("Image exists, skipping: {$imagePath}", Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG);
                return '/' . $imagePath;
            }
        }

        // 2. Try base-path (local copy)
        if ($basePath) {
            $sourceFile = $basePath . '/' . $imagePath;
            if (file_exists($sourceFile)) {
                if ($this->_copyImage($sourceFile, $destinationFile)) {
                    $this->_log("Copied image from base-path: {$imagePath}");
                    return '/' . $imagePath;
                }
            }
        }

        // 3. Try base-url (download)
        if ($baseUrl) {
            $sourceUrl = $baseUrl . '/' . $imagePath;
            $downloaded = $this->_downloadImage($sourceUrl, $mediaDir, $timeout, $imagePath);
            if ($downloaded) {
                return $downloaded;
            }
        }

        // 4. Failed to resolve
        $this->_log(
            "Could not resolve image: {$imagePath}",
            Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
        );
        return null;
    }

    /**
     * Copy image file, creating directories as needed
     *
     * @param string $source Source file path
     * @param string $destination Destination file path
     */
    protected function _copyImage(string $source, string $destination): bool
    {
        $destDir = dirname($destination);
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0755, true)) {
                $this->_log("Failed to create directory: {$destDir}", Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING);
                return false;
            }
        }

        return copy($source, $destination);
    }

    /**
     * Download image from URL
     *
     * @param string $url Source URL
     * @param string $mediaDir Local media directory
     * @param int $timeout Timeout in milliseconds
     * @param string|null $targetPath Optional target path (relative), otherwise extracted from URL
     * @return string|null Local path relative to media dir, or null if failed
     */
    protected function _downloadImage(string $url, string $mediaDir, int $timeout, ?string $targetPath = null): ?string
    {
        // Determine target path
        if (!$targetPath) {
            // Extract filename from URL and create Magento-style path
            $filename = basename(parse_url($url, PHP_URL_PATH));
            $targetPath = $this->_generateImagePath($filename);
        }

        $destinationFile = $mediaDir . DS . $targetPath;

        // Create directory
        $destDir = dirname($destinationFile);
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0755, true)) {
                $this->_log("Failed to create directory: {$destDir}", Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING);
                return null;
            }
        }

        // Download
        $ch = curl_init($url);
        $fp = fopen($destinationFile, 'w');

        if (!$fp) {
            $this->_log("Failed to open file for writing: {$destinationFile}", Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING);
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT_MS => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Maho DataSync Image Importer',
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode !== 200) {
            unlink($destinationFile);
            $this->_log(
                "Failed to download image {$url}: HTTP {$httpCode} - {$error}",
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
            return null;
        }

        $this->_log("Downloaded image: {$url} -> {$targetPath}");
        return '/' . $targetPath;
    }

    /**
     * Generate Magento-style image path from filename
     *
     * @return string Path like "m/a/main.jpg"
     */
    protected function _generateImagePath(string $filename): string
    {
        $filename = preg_replace('/[^a-zA-Z0-9_.-]/', '', $filename);
        $filename = strtolower($filename);

        if (strlen($filename) < 2) {
            $filename = 'img_' . $filename;
        }

        return $filename[0] . '/' . $filename[1] . '/' . $filename;
    }

    /**
     * Parse media gallery field (comma or pipe separated)
     *
     * @param string|array $gallery
     */
    protected function _parseGalleryImages($gallery): array
    {
        if (is_array($gallery)) {
            return $gallery;
        }

        // Try JSON first
        $decoded = json_decode($gallery, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_column($decoded, 'file') ?: $decoded;
        }

        // Pipe or comma separated
        $separator = str_contains($gallery, '|') ? '|' : ',';
        return array_map('trim', explode($separator, $gallery));
    }

    /**
     * Assign image role to product (image, small_image, thumbnail)
     *
     * @param string $imagePath Local path (e.g., "/m/a/main.jpg")
     * @param string $role Role name (image, small_image, thumbnail)
     * @return bool True if any change was made, false if already correct
     */
    protected function _assignImageRole(Mage_Catalog_Model_Product $product, string $imagePath, string $role): bool
    {
        // First add to gallery if not already there
        $galleryFile = $this->_addToGallery($product, $imagePath);

        if (!$galleryFile) {
            return false;
        }

        // Check if role already points to the correct file
        $currentValue = $product->getData($role);
        if ($currentValue === $galleryFile) {
            $this->_log("Role '{$role}' already set to {$galleryFile}, skipping", Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG);
            return false;
        }

        // Assign the role using the actual gallery file path (may have suffix if renamed)
        $product->setData($role, $galleryFile);
        return true;
    }

    /**
     * Track images added to gallery during current import to avoid duplicates
     * Maps source path to actual gallery file path
     * @var array
     */
    protected $_addedGalleryImages = [];

    /**
     * Add image to product media gallery
     *
     * @param string $imagePath Local path (e.g., "/m/a/main.jpg")
     * @return string|null The actual gallery file path (may differ from input if renamed)
     */
    protected function _addToGallery(Mage_Catalog_Model_Product $product, string $imagePath): ?string
    {
        // Check if we've already added this image in this session
        $productId = $product->getId() ?: 'new';
        $trackingKey = $productId . ':' . $imagePath;
        if (isset($this->_addedGalleryImages[$trackingKey])) {
            return $this->_addedGalleryImages[$trackingKey]; // Return the actual gallery path
        }

        // Check if image already exists in the product's current gallery (from DB)
        $gallery = $product->getMediaGallery();
        $existingImages = $gallery['images'] ?? [];
        $normalizedInput = ltrim($imagePath, '/');

        foreach ($existingImages as $existing) {
            $existingFile = ltrim($existing['file'] ?? '', '/');
            if ($existingFile === $normalizedInput) {
                // Image already in gallery - cache and return without re-adding
                $this->_addedGalleryImages[$trackingKey] = $existing['file'];
                $this->_log("Image already in gallery, skipping: {$imagePath}", Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG);
                return $existing['file'];
            }
        }

        // Add to gallery
        try {
            $countBefore = count($existingImages);

            $product->addImageToMediaGallery(
                Mage::getBaseDir('media') . DS . 'catalog' . DS . 'product' . $imagePath,
                null,
                false,
                false,
            );

            // Get the actual file path that was added (may have suffix)
            $gallery = $product->getMediaGallery();
            $images = $gallery['images'] ?? [];
            $actualPath = null;

            if (count($images) > $countBefore) {
                // Get the last added image
                $lastImage = end($images);
                $actualPath = $lastImage['file'] ?? null;
            }

            // Track with actual path
            $this->_addedGalleryImages[$trackingKey] = $actualPath ?: $imagePath;
            return $this->_addedGalleryImages[$trackingKey];
        } catch (Exception $e) {
            $this->_log(
                "Failed to add image to gallery: {$imagePath} - " . $e->getMessage(),
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
            return null;
        }
    }

    /**
     * @inheritDoc
     *
     * Process collected product links after all products have been imported.
     * This is necessary because configurable and grouped products need their
     * children/associated products to exist before links can be created.
     */
    #[\Override]
    public function finishSync(Maho_DataSync_Model_Registry $registry): void
    {
        // Process configurable product links
        $t0 = microtime(true);
        $this->_processConfigurableLinks();
        $this->_addTiming('configurable_links', microtime(true) - $t0);

        // Process grouped product links
        $t0 = microtime(true);
        $this->_processGroupedLinks();
        $this->_addTiming('grouped_links', microtime(true) - $t0);

        // Log timing summary
        $this->_logTimingSummary();

        // Reset gallery tracking and timings for next batch
        $this->_addedGalleryImages = [];
        $this->_resetTimings();
    }
}
