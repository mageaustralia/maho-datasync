<?php

/**
 * Maho DataSync Product Attribute Entity Handler
 *
 * Handles import of product EAV attributes from external systems.
 * Creates attributes with options and assigns to attribute sets.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */
class Maho_DataSync_Model_Entity_ProductAttribute extends Maho_DataSync_Model_Entity_Abstract
{
    protected array $_requiredFields = ['attribute_code', 'frontend_label'];

    protected ?string $_externalRefField = 'attribute_code';

    /**
     * Valid frontend input types
     */
    protected array $_validFrontendInputs = [
        'text',
        'textarea',
        'date',
        'boolean',
        'multiselect',
        'select',
        'price',
        'media_image',
        'gallery',
        'weee',
        'weight',
    ];

    /**
     * Valid backend types
     */
    protected array $_validBackendTypes = [
        'static',
        'datetime',
        'decimal',
        'int',
        'text',
        'varchar',
    ];

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getEntityType(): string
    {
        return 'product_attribute';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'Product Attributes';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function findExisting(array $data): ?int
    {
        if (empty($data['attribute_code'])) {
            return null;
        }

        /** @var Mage_Eav_Model_Entity_Attribute $attribute */
        $attribute = Mage::getModel('eav/entity_attribute')->loadByCode(
            Mage_Catalog_Model_Product::ENTITY,
            $data['attribute_code'],
        );

        return $attribute->getId() ? (int) $attribute->getId() : null;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function import(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $existingId = $data['_existing_id'] ?? null;
        $attributeCode = $data['attribute_code'];

        if ($existingId) {
            return $this->_updateAttribute($existingId, $data, $registry);
        }

        return $this->_createAttribute($data, $registry);
    }

    /**
     * Create a new product attribute
     *
     * @throws Maho_DataSync_Exception
     */
    protected function _createAttribute(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $attributeCode = $data['attribute_code'];

        // Validate attribute_code format
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $attributeCode)) {
            throw new Maho_DataSync_Exception(
                "Invalid attribute_code format: {$attributeCode}. Must start with letter, contain only lowercase letters, numbers, and underscores.",
            );
        }

        $this->_log("Creating product attribute: {$attributeCode}");

        /** @var Mage_Catalog_Model_Resource_Eav_Attribute $attribute */
        $attribute = Mage::getModel('catalog/resource_eav_attribute');

        // Set entity type
        $entityTypeId = Mage::getModel('eav/entity')->setType(Mage_Catalog_Model_Product::ENTITY)->getTypeId();
        $attribute->setEntityTypeId($entityTypeId);

        // Basic info
        $attribute->setAttributeCode($attributeCode);
        $attribute->setFrontendLabel($data['frontend_label']);

        // Frontend input type (default: text)
        $frontendInput = strtolower($data['frontend_input'] ?? 'text');
        if (!in_array($frontendInput, $this->_validFrontendInputs)) {
            $frontendInput = 'text';
        }
        $attribute->setFrontendInput($frontendInput);

        // Backend type (auto-detect or explicit)
        $backendType = $data['backend_type'] ?? $this->_detectBackendType($frontendInput);
        $attribute->setBackendType($backendType);

        // Backend model for select/multiselect
        if (in_array($frontendInput, ['select', 'multiselect'])) {
            $attribute->setBackendModel('eav/entity_attribute_backend_array');
            $attribute->setSourceModel('eav/entity_attribute_source_table');
        }

        // Scope (default: store view)
        $isGlobal = $data['is_global'] ?? Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_STORE;
        if (is_string($isGlobal)) {
            $isGlobal = match (strtolower($isGlobal)) {
                'global' => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
                'website' => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_WEBSITE,
                default => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_STORE,
            };
        }
        $attribute->setIsGlobal($isGlobal);

        // Visibility and behavior flags
        $attribute->setIsVisible((int) ($data['is_visible'] ?? 1));
        $attribute->setIsSearchable((int) ($data['is_searchable'] ?? 0));
        $attribute->setIsFilterable((int) ($data['is_filterable'] ?? 0));
        $attribute->setIsFilterableInSearch((int) ($data['is_filterable_in_search'] ?? 0));
        $attribute->setIsComparable((int) ($data['is_comparable'] ?? 0));
        $attribute->setIsVisibleOnFront((int) ($data['is_visible_on_front'] ?? 0));
        $attribute->setIsHtmlAllowedOnFront((int) ($data['is_html_allowed_on_front'] ?? 0));
        $attribute->setIsUsedForPriceRules((int) ($data['is_used_for_price_rules'] ?? 0));
        $attribute->setUsedInProductListing((int) ($data['used_in_product_listing'] ?? 0));
        $attribute->setUsedForSortBy((int) ($data['used_for_sort_by'] ?? 0));
        $attribute->setIsVisibleInAdvancedSearch((int) ($data['is_visible_in_advanced_search'] ?? 0));

        // Required flag
        $attribute->setIsRequired((int) ($data['is_required'] ?? 0));

        // Unique flag
        $attribute->setIsUnique((int) ($data['is_unique'] ?? 0));

        // Default value
        if (isset($data['default_value'])) {
            $attribute->setDefaultValue($data['default_value']);
        }

        // Frontend class (validation)
        if (!empty($data['frontend_class'])) {
            $attribute->setFrontendClass($data['frontend_class']);
        }

        // Note/comment
        if (!empty($data['note'])) {
            $attribute->setNote($data['note']);
        }

        // Position
        $attribute->setPosition((int) ($data['position'] ?? 0));

        // User-defined flag (imported attributes are always user-defined)
        $attribute->setIsUserDefined(1);

        // Save attribute
        try {
            $attribute->save();
        } catch (Exception $e) {
            throw Maho_DataSync_Exception::importFailed(
                'product_attribute',
                $data['entity_id'] ?? 0,
                $data['_source_system'] ?? 'import',
                $e->getMessage(),
            );
        }

        $attributeId = (int) $attribute->getId();

        // Add options for select/multiselect attributes
        if (!empty($data['options']) && in_array($frontendInput, ['select', 'multiselect'])) {
            $this->_addAttributeOptions($attribute, $data['options']);
        }

        // Assign to attribute set(s)
        $attributeSets = $data['attribute_sets'] ?? $data['attribute_set'] ?? 'Default';
        $this->_assignToAttributeSets($attribute, $attributeSets, $data['attribute_group'] ?? null);

        // Set external reference
        $data['_external_ref'] = $attributeCode;

        $this->_log("Created product attribute #{$attributeId}: {$attributeCode}");

        return $attributeId;
    }

    /**
     * Update existing product attribute
     *
     * @throws Maho_DataSync_Exception
     */
    protected function _updateAttribute(int $attributeId, array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $this->_log("Updating product attribute #{$attributeId}: {$data['attribute_code']}");

        /** @var Mage_Catalog_Model_Resource_Eav_Attribute $attribute */
        $attribute = Mage::getModel('catalog/resource_eav_attribute')->load($attributeId);

        if (!$attribute->getId()) {
            throw new Maho_DataSync_Exception("Attribute #{$attributeId} not found for update");
        }

        // Don't allow changing attribute_code or frontend_input on existing attributes
        // as this can cause data corruption

        // Update label
        if (!empty($data['frontend_label'])) {
            $attribute->setFrontendLabel($data['frontend_label']);
        }

        // Update visibility and behavior flags
        if (isset($data['is_visible'])) {
            $attribute->setIsVisible((int) $data['is_visible']);
        }
        if (isset($data['is_searchable'])) {
            $attribute->setIsSearchable((int) $data['is_searchable']);
        }
        if (isset($data['is_filterable'])) {
            $attribute->setIsFilterable((int) $data['is_filterable']);
        }
        if (isset($data['is_comparable'])) {
            $attribute->setIsComparable((int) $data['is_comparable']);
        }
        if (isset($data['is_visible_on_front'])) {
            $attribute->setIsVisibleOnFront((int) $data['is_visible_on_front']);
        }
        if (isset($data['used_in_product_listing'])) {
            $attribute->setUsedInProductListing((int) $data['used_in_product_listing']);
        }
        if (isset($data['used_for_sort_by'])) {
            $attribute->setUsedForSortBy((int) $data['used_for_sort_by']);
        }
        if (isset($data['position'])) {
            $attribute->setPosition((int) $data['position']);
        }

        try {
            $attribute->save();
        } catch (Exception $e) {
            throw Maho_DataSync_Exception::importFailed(
                'product_attribute',
                $data['entity_id'] ?? 0,
                $data['_source_system'] ?? 'import',
                'Update failed: ' . $e->getMessage(),
            );
        }

        // Update options for select/multiselect
        if (!empty($data['options']) && in_array($attribute->getFrontendInput(), ['select', 'multiselect'])) {
            $this->_addAttributeOptions($attribute, $data['options']);
        }

        // Update attribute set assignments
        if (!empty($data['attribute_sets']) || !empty($data['attribute_set'])) {
            $attributeSets = $data['attribute_sets'] ?? $data['attribute_set'];
            $this->_assignToAttributeSets($attribute, $attributeSets, $data['attribute_group'] ?? null);
        }

        $data['_external_ref'] = $data['attribute_code'];

        $this->_log("Updated product attribute #{$attributeId}");

        return $attributeId;
    }

    /**
     * Detect backend type from frontend input
     */
    protected function _detectBackendType(string $frontendInput): string
    {
        return match ($frontendInput) {
            'text', 'textarea' => 'varchar',
            'date', 'datetime' => 'datetime',
            'price', 'weight' => 'decimal',
            'boolean', 'select' => 'int',
            'multiselect' => 'varchar',
            'media_image', 'gallery' => 'varchar',
            default => 'varchar',
        };
    }

    /**
     * Add options to a select/multiselect attribute
     *
     * @param string|array $options
     */
    protected function _addAttributeOptions(Mage_Catalog_Model_Resource_Eav_Attribute $attribute, $options): void
    {
        // Parse options if string
        if (is_string($options)) {
            // Try JSON first
            $decoded = json_decode($options, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $options = $decoded;
            } elseif (str_contains($options, '|')) {
                // Pipe-delimited: "Red|Blue|Green" or "red:Red|blue:Blue"
                $options = array_map('trim', explode('|', $options));
            } else {
                // Comma-delimited: "Red,Blue,Green" (common CSV format)
                $options = array_map('trim', explode(',', $options));
            }
        }

        if (empty($options)) {
            return;
        }

        // Get existing options
        $existingOptions = [];
        if ($attribute->getSource()) {
            /** @phpstan-ignore arguments.count */
            foreach ($attribute->getSource()->getAllOptions(false) as $opt) {
                $existingOptions[strtolower($opt['label'])] = $opt['value'];
            }
        }

        // Prepare new options
        $optionData = ['value' => [], 'order' => []];
        $position = 0;

        foreach ($options as $key => $option) {
            $position++;

            // Handle different formats
            if (is_array($option)) {
                // Array format: ['value' => 'red', 'label' => 'Red', 'position' => 1]
                $value = $option['value'] ?? $option['admin'] ?? $key;
                $label = $option['label'] ?? $option['admin'] ?? $value;
                $pos = $option['position'] ?? $position;
            } elseif (str_contains($option, ':')) {
                // "value:Label" format
                [$value, $label] = explode(':', $option, 2);
                $pos = $position;
            } else {
                // Simple label (use as both value and label)
                $value = $option;
                $label = $option;
                $pos = $position;
            }

            // Skip if option already exists
            if (isset($existingOptions[strtolower($label)])) {
                continue;
            }

            // Add new option
            $optionId = 'option_' . $position;
            $optionData['value'][$optionId] = [
                0 => $label,  // Admin store
            ];
            $optionData['order'][$optionId] = $pos;
        }

        if (!empty($optionData['value'])) {
            $attribute->setData('option', $optionData);
            $attribute->save();
            $this->_log('Added ' . count($optionData['value']) . " options to attribute {$attribute->getAttributeCode()}");
        }
    }

    /**
     * Assign attribute to attribute set(s)
     *
     * @param string|array $attributeSets
     */
    protected function _assignToAttributeSets(
        Mage_Catalog_Model_Resource_Eav_Attribute $attribute,
        $attributeSets,
        ?string $groupName = null,
    ): void {
        // Parse attribute sets
        if (is_string($attributeSets)) {
            $attributeSets = array_map('trim', explode(',', $attributeSets));
        }

        $entityTypeId = $attribute->getEntityTypeId();

        foreach ($attributeSets as $setName) {
            // Get attribute set ID
            $attributeSet = Mage::getModel('eav/entity_attribute_set')
                ->getCollection()
                ->addFieldToFilter('entity_type_id', $entityTypeId)
                ->addFieldToFilter('attribute_set_name', $setName)
                ->getFirstItem();

            if (!$attributeSet->getId()) {
                $this->_log("Attribute set '{$setName}' not found, skipping", Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING);
                continue;
            }

            $setId = $attributeSet->getId();

            // Get or create attribute group
            $groupId = $this->_getOrCreateAttributeGroup($setId, $groupName ?: 'General', $entityTypeId);

            // Check if attribute already in set
            $existingSetId = (int) $attribute->getAttributeSetId();

            // Add to set if not already there
            /** @var Mage_Eav_Model_Entity_Setup $setup */
            $setup = Mage::getModel('eav/entity_setup', 'core_setup');

            try {
                $setup->addAttributeToSet(
                    $entityTypeId,
                    $setId,
                    $groupId,
                    $attribute->getId(),
                );
                $this->_log("Assigned attribute to set '{$setName}' in group '{$groupName}'");
            } catch (Exception $e) {
                // Attribute might already be in set
                if (!str_contains($e->getMessage(), 'already exists')) {
                    $this->_log("Failed to assign to set '{$setName}': " . $e->getMessage(), Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING);
                }
            }
        }
    }

    /**
     * Get or create attribute group in an attribute set
     *
     * @return int Group ID
     */
    protected function _getOrCreateAttributeGroup(int $attributeSetId, string $groupName, int $entityTypeId): int
    {
        // Try to find existing group
        $group = Mage::getModel('eav/entity_attribute_group')
            ->getCollection()
            ->addFieldToFilter('attribute_set_id', $attributeSetId)
            ->addFieldToFilter('attribute_group_name', $groupName)
            ->getFirstItem();

        if ($group->getId()) {
            return (int) $group->getId();
        }

        // Create new group
        $group = Mage::getModel('eav/entity_attribute_group');
        $group->setAttributeSetId($attributeSetId);
        $group->setAttributeGroupName($groupName);
        $group->setSortOrder(100);
        $group->save();

        $this->_log("Created attribute group '{$groupName}' in set #{$attributeSetId}");

        return (int) $group->getId();
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function validate(array $data): array
    {
        $errors = parent::validate($data);

        // Validate attribute_code format
        if (!empty($data['attribute_code'])) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $data['attribute_code'])) {
                $errors[] = 'Invalid attribute_code: must start with letter, contain only lowercase letters, numbers, underscores';
            }
            if (strlen($data['attribute_code']) > 60) {
                $errors[] = 'attribute_code too long (max 60 characters)';
            }
        }

        // Validate frontend_input if provided
        if (!empty($data['frontend_input'])) {
            if (!in_array(strtolower($data['frontend_input']), $this->_validFrontendInputs)) {
                $errors[] = "Invalid frontend_input: {$data['frontend_input']}. Valid: " . implode(', ', $this->_validFrontendInputs);
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
        $entityTypeId = Mage::getModel('eav/entity')->setType(Mage_Catalog_Model_Product::ENTITY)->getTypeId();

        $collection = Mage::getResourceModel('eav/entity_attribute_collection')
            ->addFieldToFilter('entity_type_id', $entityTypeId)
            ->addFieldToFilter('is_user_defined', 1)  // Only export custom attributes
            ->setOrder('attribute_id', 'ASC');

        if (!empty($filters['limit'])) {
            $collection->setPageSize($filters['limit']);
        }

        foreach ($collection as $attribute) {
            yield $this->_exportAttribute($attribute);
        }
    }

    /**
     * Export a single attribute
     */
    protected function _exportAttribute(Mage_Eav_Model_Entity_Attribute $attribute): array
    {
        $data = [
            'entity_id' => $attribute->getId(),
            'attribute_code' => $attribute->getAttributeCode(),
            'frontend_label' => $attribute->getFrontendLabel(),
            'frontend_input' => $attribute->getFrontendInput(),
            'backend_type' => $attribute->getBackendType(),
            'is_global' => $attribute->getIsGlobal(),
            'is_visible' => $attribute->getIsVisible(),
            'is_searchable' => $attribute->getIsSearchable(),
            'is_filterable' => $attribute->getIsFilterable(),
            'is_comparable' => $attribute->getIsComparable(),
            'is_visible_on_front' => $attribute->getIsVisibleOnFront(),
            'is_required' => $attribute->getIsRequired(),
            'is_unique' => $attribute->getIsUnique(),
            'default_value' => $attribute->getDefaultValue(),
            'position' => $attribute->getPosition(),
        ];

        // Export options for select/multiselect
        if (in_array($attribute->getFrontendInput(), ['select', 'multiselect'])) {
            $options = [];
            if ($attribute->getSource()) {
                /** @phpstan-ignore arguments.count */
                foreach ($attribute->getSource()->getAllOptions(false) as $opt) {
                    if ($opt['value']) {
                        $options[] = $opt['label'];
                    }
                }
            }
            $data['options'] = implode('|', $options);
        }

        return $data;
    }
}
