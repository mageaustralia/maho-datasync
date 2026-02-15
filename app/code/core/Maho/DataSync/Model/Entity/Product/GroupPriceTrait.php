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
 * Group Prices import trait for Product entity
 *
 * Formats supported:
 * - JSON: [{"cust_group":1,"price":89.95,"website_id":0},{"cust_group":2,"price":79.95,"is_percent":1}]
 * - Pipe: 1:89.95|2:79.95%|32000:69.95 (group_id:price, % = percentage, 32000 = all groups)
 */
trait Maho_DataSync_Model_Entity_Product_GroupPriceTrait
{
    /**
     * Import group prices for a product
     */
    protected function _importGroupPrices(Mage_Catalog_Model_Product $product, string|array $groupPrices): void
    {
        $prices = $this->_parseGroupPrices($groupPrices, $product->getSku());

        if (empty($prices)) {
            return;
        }

        // Format for Magento's group price structure
        $formattedPrices = [];
        foreach ($prices as $price) {
            $formattedPrices[] = [
                'website_id' => $price['website_id'] ?? 0,
                'cust_group' => $price['cust_group'],
                'price' => $price['price'],
            ];

            // Handle percentage pricing if supported
            if (!empty($price['is_percent'])) {
                $basePrice = (float) $product->getPrice();
                $percentOff = (float) $price['price'];
                $formattedPrices[array_key_last($formattedPrices)]['price'] = $basePrice - ($basePrice * $percentOff / 100);
            }
        }

        $product->setData('group_price', $formattedPrices);
    }

    /**
     * Parse group prices from string or array
     *
     * @param string $sku For logging
     */
    protected function _parseGroupPrices(string|array $groupPrices, string $sku): array
    {
        // Already an array
        if (is_array($groupPrices)) {
            return $groupPrices;
        }

        $trimmed = trim($groupPrices);
        if (empty($trimmed)) {
            return [];
        }

        // Try JSON first
        if (str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            $this->_log(
                "Invalid group_prices JSON for product {$sku}",
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
            return [];
        }

        // Parse pipe-delimited format: 1:89.95|2:79.95%|32000:69.95
        return $this->_parsePipeGroupPrices($trimmed);
    }

    /**
     * Parse pipe-delimited group prices format
     *
     * Format: group_id:price|group_id:price%
     * - Trailing % = percentage discount
     * - 32000 = all customer groups
     */
    protected function _parsePipeGroupPrices(string $pipeString): array
    {
        $prices = [];
        $parts = preg_split('/[|,]/', $pipeString);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            // Format: group_id:price or group_id:price%
            if (!str_contains($part, ':')) {
                continue;
            }

            [$groupId, $priceValue] = explode(':', $part, 2);
            $groupId = trim($groupId);
            $priceValue = trim($priceValue);

            $isPercent = str_ends_with($priceValue, '%');
            if ($isPercent) {
                $priceValue = rtrim($priceValue, '%');
            }

            $prices[] = [
                'cust_group' => (int) $groupId,
                'price' => (float) $priceValue,
                'website_id' => 0,
                'is_percent' => $isPercent ? 1 : 0,
            ];
        }

        return $prices;
    }
}
