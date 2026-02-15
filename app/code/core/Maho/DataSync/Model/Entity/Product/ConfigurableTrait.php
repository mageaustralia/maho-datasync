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
 * Configurable Product links import trait
 *
 * Supports three linking modes:
 * 1. Children on parent row: configurable_children_skus = SKU|SKU
 * 2. Parent on child row: configurable_parent_sku = PARENT-SKU
 * 3. Auto-link mode: Simple products following configurable auto-link if same attribute set
 *
 * Super attributes:
 * - Explicit: super_attributes = color,size
 * - Auto-detect: Analyze child products to find varying attributes
 */
trait Maho_DataSync_Model_Entity_Product_ConfigurableTrait
{
    /**
     * Pending configurable links to process after all products created
     * Format: [configurable_sku => [child_sku, ...]]
     */
    protected array $_pendingConfigurableLinks = [];

    /**
     * Super attributes by configurable SKU
     * Format: [configurable_sku => [attr_code, ...]]
     */
    protected array $_configurableSuperAttributes = [];

    /**
     * Auto-link state tracking
     *
     * @var string|null Current configurable SKU for auto-linking
     */
    protected ?string $_autoLinkCurrentConfigurable = null;

    /**
     * @var int|null Current configurable's attribute set for auto-linking
     */
    protected ?int $_autoLinkAttributeSetId = null;

    /**
     * Collect configurable product links for later processing
     *
     * @param array $data Row data
     * @param string $sku Product SKU
     * @param bool $autoLinkEnabled Whether auto-link mode is enabled
     */
    protected function _collectConfigurableLinks(array $data, string $sku, bool $autoLinkEnabled = false): void
    {
        $typeId = $data['type_id'] ?? 'simple';

        // Store super attributes if provided (from configurable_attributes or super_attributes)
        if (!empty($data['configurable_attributes'])) {
            // From Openmage adapter: array of [attribute_id, attribute_code, position, label]
            $attrs = array_column($data['configurable_attributes'], 'attribute_code');
            $this->_configurableSuperAttributes[$sku] = array_filter($attrs);
            $this->_log("Stored super attributes for {$sku} from configurable_attributes: " . implode(', ', $attrs));
        } elseif (!empty($data['super_attributes'])) {
            $attrs = is_array($data['super_attributes'])
                ? $data['super_attributes']
                : array_map('trim', explode(',', $data['super_attributes']));
            $this->_configurableSuperAttributes[$sku] = $attrs;
        }

        // Mode 1: Children on configurable row
        if (!empty($data['configurable_children_skus'])) {
            $childSkus = $this->_parseSkuList($data['configurable_children_skus']);
            if (!empty($childSkus)) {
                $this->_pendingConfigurableLinks[$sku] = $childSkus;
                $this->_log("Collected configurable links for SKU {$sku}: " . count($childSkus) . ' children');
            }
        }

        // Mode 2: Parent on simple row
        if (!empty($data['configurable_parent_sku'])) {
            $parentSku = trim($data['configurable_parent_sku']);
            if (!isset($this->_pendingConfigurableLinks[$parentSku])) {
                $this->_pendingConfigurableLinks[$parentSku] = [];
            }
            $this->_pendingConfigurableLinks[$parentSku][] = $sku;
        }

        // Mode 3: Auto-link based on position
        if ($autoLinkEnabled) {
            $this->_handleAutoLink($data, $sku, $typeId);
        }
    }

    /**
     * Handle auto-link mode for configurable products
     */
    protected function _handleAutoLink(array $data, string $sku, string $typeId): void
    {
        $attributeSetId = $this->_resolveAttributeSetId($data);

        if ($typeId === 'configurable') {
            // Start new auto-link group
            $this->_autoLinkCurrentConfigurable = $sku;
            $this->_autoLinkAttributeSetId = $attributeSetId;

            if (!isset($this->_pendingConfigurableLinks[$sku])) {
                $this->_pendingConfigurableLinks[$sku] = [];
            }
        } elseif ($typeId === 'simple' && $this->_autoLinkCurrentConfigurable !== null) {
            // Check if this simple should auto-link
            if ($attributeSetId === $this->_autoLinkAttributeSetId) {
                // Same attribute set - add to current configurable
                $this->_pendingConfigurableLinks[$this->_autoLinkCurrentConfigurable][] = $sku;
            } else {
                // Different attribute set - stop auto-linking
                $this->_autoLinkCurrentConfigurable = null;
                $this->_autoLinkAttributeSetId = null;
            }
        } else {
            // Non-simple/configurable type breaks auto-link chain
            $this->_autoLinkCurrentConfigurable = null;
            $this->_autoLinkAttributeSetId = null;
        }
    }

    /**
     * Resolve attribute set ID from data
     */
    protected function _resolveAttributeSetId(array $data): ?int
    {
        if (!empty($data['attribute_set_id'])) {
            return (int) $data['attribute_set_id'];
        }

        if (!empty($data['attribute_set']) || !empty($data['attribute_set_code'])) {
            $code = $data['attribute_set'] ?? $data['attribute_set_code'];
            return $this->_getAttributeSetIdByCode($code);
        }

        return $this->_getDefaultAttributeSetId();
    }

    /**
     * Parse pipe or comma separated SKU list
     */
    protected function _parseSkuList(string|array $skuData): array
    {
        // If already an array, just clean and return
        if (is_array($skuData)) {
            return array_filter(array_map('trim', $skuData), fn($s) => !empty($s));
        }

        $skus = [];
        $parts = preg_split('/[|,]/', $skuData);

        foreach ($parts as $part) {
            $sku = trim($part);
            if (!empty($sku)) {
                $skus[] = $sku;
            }
        }

        return $skus;
    }

    /**
     * Process pending configurable product links
     * Called after all products in batch have been created/updated
     */
    protected function _processConfigurableLinks(): void
    {
        $count = count($this->_pendingConfigurableLinks);
        $this->_log("_processConfigurableLinks called with {$count} pending links");

        if (empty($this->_pendingConfigurableLinks)) {
            $this->_log('No pending configurable links to process');
            return;
        }

        $this->_log("Processing {$count} configurable product links...");

        foreach ($this->_pendingConfigurableLinks as $parentSku => $childSkus) {
            // Cast to string because PHP converts numeric string keys to integers
            $this->_linkConfigurableChildren((string) $parentSku, array_unique($childSkus));
        }

        // Clear pending links
        $this->_pendingConfigurableLinks = [];
        $this->_configurableSuperAttributes = [];
        $this->_autoLinkCurrentConfigurable = null;
        $this->_autoLinkAttributeSetId = null;
    }

    /**
     * Link child products to a configurable parent
     */
    protected function _linkConfigurableChildren(string $parentSku, array $childSkus): void
    {
        if (empty($childSkus)) {
            return;
        }

        try {
            $this->_doLinkConfigurableChildren($parentSku, $childSkus);
        } catch (\Exception $e) {
            $this->_log(
                "Failed to link configurable children for {$parentSku}: " . $e->getMessage(),
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
        }
    }

    /**
     * Internal method to link child products to configurable parent
     */
    protected function _doLinkConfigurableChildren(string $parentSku, array $childSkus): void
    {
        $parentProduct = Mage::getModel('catalog/product')->loadByAttribute('sku', $parentSku);

        if (!$parentProduct || !$parentProduct->getId()) {
            $this->_log(
                "Configurable parent SKU not found: {$parentSku}",
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
            return;
        }

        if ($parentProduct->getTypeId() !== 'configurable') {
            $this->_log(
                "Product {$parentSku} is not configurable (type: {$parentProduct->getTypeId()})",
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
            return;
        }

        // Load child products
        $childProductIds = [];
        $childProducts = [];
        foreach ($childSkus as $childSku) {
            $child = Mage::getModel('catalog/product')->loadByAttribute('sku', $childSku);
            if ($child && $child->getId()) {
                $childProductIds[] = $child->getId();
                $childProducts[] = $child;
            } else {
                $this->_log(
                    "Configurable child SKU not found: {$childSku}",
                    Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
                );
            }
        }

        if (empty($childProductIds)) {
            return;
        }

        // Get existing child product IDs to avoid duplicates
        /** @var Mage_Catalog_Model_Product_Type_Configurable $typeInstance */
        $typeInstance = $parentProduct->getTypeInstance();
        $existingChildIds = $typeInstance->getUsedProductIds($parentProduct);
        $existingChildIds = is_array($existingChildIds) ? $existingChildIds : [];

        // Merge with new children (combine existing + new, deduplicated)
        $allChildIds = array_unique(array_merge($existingChildIds, $childProductIds));

        // If no new children, skip
        if (count($allChildIds) === count($existingChildIds)) {
            $this->_log(
                "All children already linked to configurable {$parentSku}",
                Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
            );
            return;
        }

        // Check existing configurable attributes first
        /** @var Mage_Catalog_Model_Product_Type_Configurable $typeInstance */
        $typeInstance = $parentProduct->getTypeInstance();
        $existingAttrIds = $typeInstance->getConfigurableAttributeCollection($parentProduct)
            ->getAllIds();

        // Get or detect super attributes only if none exist
        $superAttributeIds = [];
        $needSaveAttributes = false;
        if (empty($existingAttrIds)) {
            $superAttributeIds = $this->_getSuperAttributeIds($parentSku, $parentProduct, $childProducts);

            if (empty($superAttributeIds)) {
                $this->_log(
                    "No super attributes found for configurable {$parentSku}",
                    Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
                );
                return;
            }

            // Set configurable attributes
            $configurableType = $parentProduct->getTypeInstance();
            $configurableType->setProduct($parentProduct);

            // Create super attribute data
            $superAttributeData = [];
            $position = 0;
            foreach ($superAttributeIds as $attrId) {
                $superAttributeData[$attrId] = [
                    'attribute_id' => $attrId,
                    'position' => $position++,
                ];
            }

            $parentProduct->setConfigurableAttributesData($superAttributeData);
            $parentProduct->setCanSaveConfigurableAttributes(true);
            $needSaveAttributes = true;
        }

        // Use direct SQL to add child links (avoids Magento's delete-and-reinsert approach)
        $newChildIds = array_diff($childProductIds, $existingChildIds);
        if (!empty($newChildIds)) {
            $resource = Mage::getSingleton('core/resource');
            $write = $resource->getConnection('core_write');
            $tableName = $resource->getTableName('catalog/product_super_link');

            foreach ($newChildIds as $childId) {
                try {
                    $write->insertOnDuplicate(
                        $tableName,
                        ['product_id' => $childId, 'parent_id' => $parentProduct->getId()],
                        ['product_id'], // On duplicate, just update product_id (no-op)
                    );
                } catch (Exception $e) {
                    // Ignore duplicate errors, they're expected
                }
            }
        }

        // Only save if we added new super attributes
        if ($needSaveAttributes) {
            $parentProduct->save();
        }

        $this->_log(
            'Linked ' . count($newChildIds) . " new children to configurable {$parentSku} (total: " . count($allChildIds) . ')',
            Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
        );
    }

    /**
     * Get super attribute IDs - either from explicit config or auto-detect
     *
     * @return array Attribute IDs
     */
    protected function _getSuperAttributeIds(
        string $parentSku,
        Mage_Catalog_Model_Product $parentProduct,
        array $childProducts,
    ): array {
        // Check explicit super_attributes (from source data)
        if (!empty($this->_configurableSuperAttributes[$parentSku])) {
            $attrCodes = $this->_configurableSuperAttributes[$parentSku];
            $this->_log(
                "Using source super attributes for {$parentSku}: " . implode(', ', $attrCodes),
                Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
            );
            return $this->_getAttributeIdsByCodes($attrCodes, $parentProduct->getAttributeSetId());
        }

        // Check existing configurable attributes on product
        /** @var Mage_Catalog_Model_Product_Type_Configurable $typeInstance */
        $typeInstance = $parentProduct->getTypeInstance();
        $existingAttrIds = $typeInstance->getConfigurableAttributeCollection($parentProduct)
            ->getAllIds();

        if (!empty($existingAttrIds)) {
            $this->_log(
                "Using existing super attributes for {$parentSku}: " . count($existingAttrIds) . ' attributes',
                Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
            );
            return $existingAttrIds;
        }

        // Auto-detect from child products (last resort - may select wrong attributes)
        $this->_log(
            "WARNING: No source super attributes for {$parentSku}, auto-detecting from children",
            Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
        );
        return $this->_detectSuperAttributes($parentProduct, $childProducts);
    }

    /**
     * Get attribute IDs from attribute codes
     */
    protected function _getAttributeIdsByCodes(array $attrCodes, int $attributeSetId): array
    {
        $ids = [];
        foreach ($attrCodes as $code) {
            if (empty($code)) {
                continue;
            }
            $attribute = Mage::getSingleton('eav/config')
                ->getAttribute('catalog_product', $code);

            if ($attribute instanceof Mage_Eav_Model_Entity_Attribute_Abstract
                && $attribute->getId()
                && $attribute->getIsConfigurable()) {
                $ids[] = $attribute->getId();
            }
        }
        return $ids;
    }

    /**
     * Auto-detect super attributes by comparing child product attribute values
     *
     * @return array Attribute IDs
     */
    protected function _detectSuperAttributes(
        Mage_Catalog_Model_Product $parentProduct,
        array $childProducts,
    ): array {
        if (count($childProducts) < 2) {
            return [];
        }

        // Get configurable attributes for this attribute set
        $attributeSetId = $parentProduct->getAttributeSetId();
        $configurableAttrs = Mage::getResourceModel('catalog/product_type_configurable_attribute_collection')
            ->setProductFilter($parentProduct);

        // Get all select/multiselect attributes that could be configurable
        $entityType = Mage::getSingleton('eav/config')->getEntityType('catalog_product');
        $attributes = Mage::getResourceModel('eav/entity_attribute_collection')
            ->setEntityTypeFilter($entityType)
            ->addSetInfo(true)
            ->addFieldToFilter('is_configurable', 1)
            ->addFieldToFilter('frontend_input', ['in' => ['select']]);

        // Compare values across children to find varying attributes
        $varyingAttributes = [];

        foreach ($attributes as $attribute) {
            if (!$attribute instanceof Mage_Eav_Model_Entity_Attribute_Abstract) {
                continue;
            }
            $attrCode = $attribute->getAttributeCode();
            if (empty($attrCode)) {
                continue;
            }
            $values = [];

            foreach ($childProducts as $child) {
                $value = $child->getData($attrCode);
                if ($value !== null && $value !== '') {
                    $values[$value] = true;
                }
            }

            // If we have multiple distinct values, this attribute varies
            if (count($values) > 1) {
                $varyingAttributes[] = $attribute->getId();
            }
        }

        return $varyingAttributes;
    }
}
