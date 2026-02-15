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
 * Grouped Product links import trait
 *
 * Formats supported:
 * - On grouped row: grouped_product_skus = SKU:qty|SKU:qty
 * - On simple row: grouped_parent_sku = GROUPED-SKU
 */
trait Maho_DataSync_Model_Entity_Product_GroupedTrait
{
    /**
     * Pending grouped links to process after all products created
     * Format: [grouped_sku => [child_sku => qty, ...]]
     */
    protected array $_pendingGroupedLinks = [];

    /**
     * Collect grouped product links for later processing
     *
     * @param array $data Row data
     * @param string $sku Product SKU
     */
    protected function _collectGroupedLinks(array $data, string $sku): void
    {
        // From grouped parent: grouped_product_skus
        if (!empty($data['grouped_product_skus'])) {
            $links = $this->_parseGroupedSkus($data['grouped_product_skus']);
            if (!empty($links)) {
                $this->_pendingGroupedLinks[$sku] = $links;
            }
        }

        // From child: grouped_parent_sku
        if (!empty($data['grouped_parent_sku'])) {
            $parentSku = trim($data['grouped_parent_sku']);
            $qty = $data['grouped_qty'] ?? 1;

            if (!isset($this->_pendingGroupedLinks[$parentSku])) {
                $this->_pendingGroupedLinks[$parentSku] = [];
            }
            $this->_pendingGroupedLinks[$parentSku][$sku] = (float) $qty;
        }
    }

    /**
     * Parse grouped product SKUs with quantities
     *
     * Format: SKU:qty|SKU:qty or SKU,SKU (defaults to qty 1)
     *
     * @return array [sku => qty, ...]
     */
    protected function _parseGroupedSkus(string $skuString): array
    {
        $links = [];
        $parts = preg_split('/[|,]/', $skuString);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            if (str_contains($part, ':')) {
                [$sku, $qty] = explode(':', $part, 2);
                $links[trim($sku)] = (float) trim($qty);
            } else {
                $links[$part] = 1.0;
            }
        }

        return $links;
    }

    /**
     * Process pending grouped product links
     * Called after all products in batch have been created/updated
     */
    protected function _processGroupedLinks(): void
    {
        if (empty($this->_pendingGroupedLinks)) {
            return;
        }

        $this->_log('Processing grouped product links...', Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG);

        // Link type ID for grouped products
        $linkTypeId = Mage_Catalog_Model_Product_Link::LINK_TYPE_GROUPED;

        foreach ($this->_pendingGroupedLinks as $parentSku => $childLinks) {
            $parentProduct = Mage::getModel('catalog/product')->loadByAttribute('sku', $parentSku);

            if (!$parentProduct || !$parentProduct->getId()) {
                $this->_log(
                    "Grouped parent SKU not found: {$parentSku}",
                    Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
                );
                continue;
            }

            if ($parentProduct->getTypeId() !== 'grouped') {
                $this->_log(
                    "Product {$parentSku} is not a grouped product (type: {$parentProduct->getTypeId()})",
                    Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
                );
                continue;
            }

            $linkData = [];
            foreach ($childLinks as $childSku => $qty) {
                $childProduct = Mage::getModel('catalog/product')->loadByAttribute('sku', $childSku);

                if (!$childProduct || !$childProduct->getId()) {
                    $this->_log(
                        "Grouped child SKU not found: {$childSku}",
                        Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
                    );
                    continue;
                }

                $linkData[$childProduct->getId()] = ['qty' => $qty];
            }

            if (!empty($linkData)) {
                $parentProduct->setGroupedLinkData($linkData);
                $parentProduct->save();

                $this->_log(
                    'Linked ' . count($linkData) . " products to grouped {$parentSku}",
                    Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
                );
            }
        }

        // Clear pending links
        $this->_pendingGroupedLinks = [];
    }
}
