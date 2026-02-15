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
 * Custom Options import trait for Product entity
 *
 * Formats supported:
 * - Full JSON array
 * - Simplified shorthand: Title:type:required:values_or_params;Title2:type:...
 *
 * Options modes:
 * - replace: Delete existing options, import new (default)
 * - merge: Match by title, update or add
 * - append: Keep existing, add new only
 */
trait Maho_DataSync_Model_Entity_Product_CustomOptionsTrait
{
    /**
     * Option types mapping for simplified format
     */
    protected static array $_optionTypeMapping = [
        // Text types
        'field' => ['type' => 'field', 'group' => 'text'],
        'area' => ['type' => 'area', 'group' => 'text'],

        // Select types
        'drop_down' => ['type' => 'drop_down', 'group' => 'select'],
        'dropdown' => ['type' => 'drop_down', 'group' => 'select'],
        'radio' => ['type' => 'radio', 'group' => 'select'],
        'checkbox' => ['type' => 'checkbox', 'group' => 'select'],
        'multiple' => ['type' => 'multiple', 'group' => 'select'],

        // Date types
        'date' => ['type' => 'date', 'group' => 'date'],
        'date_time' => ['type' => 'date_time', 'group' => 'date'],
        'datetime' => ['type' => 'date_time', 'group' => 'date'],
        'time' => ['type' => 'time', 'group' => 'date'],

        // File type
        'file' => ['type' => 'file', 'group' => 'file'],
    ];

    /**
     * Import custom options for a product
     *
     * @param string $optionsMode 'replace', 'merge', or 'append'
     */
    protected function _importCustomOptions(
        Mage_Catalog_Model_Product $product,
        string|array $customOptions,
        string $optionsMode = 'replace',
    ): void {
        $options = $this->_parseCustomOptions($customOptions, $product->getSku());

        if (empty($options)) {
            return;
        }

        // Handle existing options based on mode
        $existingOptions = $product->getProductOptionsCollection();

        switch ($optionsMode) {
            case 'replace':
                // Delete all existing options
                foreach ($existingOptions as $option) {
                    $option->delete();
                }
                // Clear source option_id from all options (they're new in replace mode)
                foreach ($options as &$optData) {
                    unset($optData['option_id']);
                }
                unset($optData);
                break;

            case 'merge':
                // Build lookup of existing options by title
                $existingByTitle = [];
                foreach ($existingOptions as $option) {
                    $existingByTitle[strtolower($option->getTitle())] = $option;
                }

                // Update options array to include existing option IDs for matching titles
                foreach ($options as &$optData) {
                    $titleKey = strtolower($optData['title']);
                    if (isset($existingByTitle[$titleKey])) {
                        $optData['option_id'] = $existingByTitle[$titleKey]->getId();
                        unset($existingByTitle[$titleKey]);
                    }
                }
                break;

            case 'append':
                // Only add new options (skip if title exists)
                $existingTitles = [];
                foreach ($existingOptions as $option) {
                    $existingTitles[strtolower($option->getTitle())] = true;
                }

                $options = array_filter($options, function ($opt) use ($existingTitles) {
                    return !isset($existingTitles[strtolower($opt['title'])]);
                });
                break;
        }

        if (empty($options)) {
            return;
        }

        // Create options
        $sortOrder = 0;
        $createdCount = 0;
        foreach ($options as $optionData) {
            // Skip select-type options with no values (would cause validation error)
            $type = $optionData['type'] ?? '';
            if (in_array($type, ['drop_down', 'radio', 'checkbox', 'multiple'])) {
                if (empty($optionData['values'])) {
                    $this->_log(
                        "Skipping select-type option '{$optionData['title']}' for {$product->getSku()}: no values",
                        Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
                    );
                    continue;
                }
            }
            $this->_createProductOption($product, $optionData, $sortOrder++);
            $createdCount++;
        }

        if ($createdCount > 0) {
            $this->_log(
                "Created {$createdCount} custom options for {$product->getSku()}",
                Maho_DataSync_Helper_Data::LOG_LEVEL_DEBUG,
            );
        }
    }

    /**
     * Parse custom options from string or array
     *
     * @param string $sku For logging
     */
    protected function _parseCustomOptions(string|array $customOptions, string $sku): array
    {
        // Already an array
        if (is_array($customOptions)) {
            return $customOptions;
        }

        $trimmed = trim($customOptions);
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
                "Invalid custom_options JSON for product {$sku}",
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
            return [];
        }

        // Parse simplified format
        return $this->_parseSimplifiedOptions($trimmed);
    }

    /**
     * Parse simplified options format
     *
     * Format: Title:type:required:values_or_params;Title2:type:...
     * Examples:
     *   Size:drop_down:required:Small=0|Medium=2|Large=5
     *   Engraving:field:optional:max=20
     *   Gift Wrap:checkbox:optional:price=3.99
     */
    protected function _parseSimplifiedOptions(string $optionsString): array
    {
        $options = [];
        $optionParts = explode(';', $optionsString);

        foreach ($optionParts as $optionStr) {
            $optionStr = trim($optionStr);
            if (empty($optionStr)) {
                continue;
            }

            $option = $this->_parseSimplifiedOption($optionStr);
            if ($option !== null) {
                $options[] = $option;
            }
        }

        return $options;
    }

    /**
     * Parse a single simplified option definition
     */
    protected function _parseSimplifiedOption(string $optionStr): ?array
    {
        // Format: Title:type:required:values_or_params
        $parts = explode(':', $optionStr, 4);

        if (count($parts) < 2) {
            return null;
        }

        $title = trim($parts[0]);
        $typeKey = strtolower(trim($parts[1]));
        $required = isset($parts[2]) && strtolower(trim($parts[2])) === 'required';
        $valuesPart = $parts[3] ?? '';

        if (!isset(self::$_optionTypeMapping[$typeKey])) {
            return null;
        }

        $typeInfo = self::$_optionTypeMapping[$typeKey];
        $option = [
            'title' => $title,
            'type' => $typeInfo['type'],
            'is_require' => $required ? 1 : 0,
        ];

        // Parse values/params based on option group
        if ($typeInfo['group'] === 'select') {
            $option['values'] = $this->_parseOptionValues($valuesPart);
        } elseif ($typeInfo['group'] === 'text') {
            $params = $this->_parseOptionParams($valuesPart);
            if (isset($params['max'])) {
                $option['max_characters'] = (int) $params['max'];
            }
            if (isset($params['price'])) {
                $option['price'] = (float) $params['price'];
                $option['price_type'] = $params['price_type'] ?? 'fixed';
            }
        } elseif ($typeInfo['group'] === 'file') {
            $params = $this->_parseOptionParams($valuesPart);
            if (isset($params['ext'])) {
                $option['file_extension'] = $params['ext'];
            }
            if (isset($params['width'])) {
                $option['image_size_x'] = (int) $params['width'];
            }
            if (isset($params['height'])) {
                $option['image_size_y'] = (int) $params['height'];
            }
            if (isset($params['price'])) {
                $option['price'] = (float) $params['price'];
                $option['price_type'] = $params['price_type'] ?? 'fixed';
            }
        } elseif ($typeInfo['group'] === 'date') {
            $params = $this->_parseOptionParams($valuesPart);
            if (isset($params['price'])) {
                $option['price'] = (float) $params['price'];
                $option['price_type'] = $params['price_type'] ?? 'fixed';
            }
        }

        return $option;
    }

    /**
     * Parse option values for select types
     *
     * Format: Title=price|Title=price or just Title|Title
     */
    protected function _parseOptionValues(string $valuesStr): array
    {
        $values = [];
        $valueParts = explode('|', $valuesStr);

        $sortOrder = 0;
        foreach ($valueParts as $valuePart) {
            $valuePart = trim($valuePart);
            if (empty($valuePart)) {
                continue;
            }

            if (str_contains($valuePart, '=')) {
                [$title, $price] = explode('=', $valuePart, 2);
                $values[] = [
                    'title' => trim($title),
                    'price' => (float) trim($price),
                    'price_type' => 'fixed',
                    'sort_order' => $sortOrder++,
                ];
            } else {
                $values[] = [
                    'title' => $valuePart,
                    'price' => 0,
                    'price_type' => 'fixed',
                    'sort_order' => $sortOrder++,
                ];
            }
        }

        return $values;
    }

    /**
     * Parse option parameters
     *
     * Format: param=value|param=value
     */
    protected function _parseOptionParams(string $paramsStr): array
    {
        $params = [];
        $paramParts = explode('|', $paramsStr);

        foreach ($paramParts as $paramPart) {
            $paramPart = trim($paramPart);
            if (empty($paramPart) || !str_contains($paramPart, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $paramPart, 2);
            $params[strtolower(trim($key))] = trim($value);
        }

        return $params;
    }

    /**
     * Create a product option
     */
    protected function _createProductOption(
        Mage_Catalog_Model_Product $product,
        array $optionData,
        int $sortOrder,
    ): void {
        /** @var Mage_Catalog_Model_Product_Option $option */
        $option = Mage::getModel('catalog/product_option');

        // Load existing if updating
        if (!empty($optionData['option_id'])) {
            $option->load($optionData['option_id']);
        }

        $option->setProduct($product);
        $option->setProductId($product->getId());
        $option->setStoreId(0);
        $option->setTitle($optionData['title']);
        $option->setType($optionData['type']);
        $option->setIsRequire($optionData['is_require'] ?? 0);
        $option->setSortOrder($optionData['sort_order'] ?? $sortOrder);

        // Set type-specific fields
        if (isset($optionData['price'])) {
            $option->setPrice($optionData['price']);
            $option->setPriceType($optionData['price_type'] ?? 'fixed');
        }
        if (isset($optionData['max_characters'])) {
            $option->setMaxCharacters($optionData['max_characters']);
        }
        if (isset($optionData['file_extension'])) {
            $option->setFileExtension($optionData['file_extension']);
        }
        if (isset($optionData['image_size_x'])) {
            $option->setImageSizeX($optionData['image_size_x']);
        }
        if (isset($optionData['image_size_y'])) {
            $option->setImageSizeY($optionData['image_size_y']);
        }

        // Set option values BEFORE save for select types (validation happens in _afterSave)
        if (!empty($optionData['values']) && in_array($optionData['type'], ['drop_down', 'radio', 'checkbox', 'multiple'])) {
            // Strip source IDs from values to prevent FK constraint errors
            $cleanValues = [];
            foreach ($optionData['values'] as $valueData) {
                unset($valueData['option_type_id']); // Remove source ID
                $cleanValues[] = $valueData;
            }
            $option->setData('values', $cleanValues);
        }

        $option->save();
    }

    /**
     * Create option values for select-type options
     */
    protected function _createOptionValues(Mage_Catalog_Model_Product_Option $option, array $values): void
    {
        // Delete existing values first
        foreach ($option->getValues() ?? [] as $value) {
            $value->delete();
        }

        foreach ($values as $valueData) {
            /** @var Mage_Catalog_Model_Product_Option_Value $value */
            $value = Mage::getModel('catalog/product_option_value');
            $value->setOption($option);
            $value->setTitle($valueData['title']);
            $value->setPrice($valueData['price'] ?? 0);
            $value->setPriceType($valueData['price_type'] ?? 'fixed');
            $value->setSortOrder($valueData['sort_order'] ?? 0);
            $value->setSku($valueData['sku'] ?? '');
            $value->save();
        }
    }
}
