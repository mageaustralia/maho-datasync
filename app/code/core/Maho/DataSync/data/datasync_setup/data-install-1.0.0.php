<?php

/**
 * Maho DataSync Data Install Script
 *
 * Creates EAV attributes for tracking imported entities.
 * Adds tracking attributes to Customer, Product, and Category entities.
 *
 * @category   Maho
 * @package    Maho_DataSync
 */

// ============================================================================
// CUSTOMER ATTRIBUTES
// ============================================================================

/** @var Mage_Customer_Model_Entity_Setup $customerInstaller */
$customerInstaller = Mage::getResourceModel('customer/setup', ['resourceName' => 'customer_setup']);
$customerInstaller->startSetup();

/**
 * Customer attribute: force_password_reset
 *
 * When set to 1, the customer must reset their password on next login.
 * Used for imported customers since passwords cannot be migrated.
 */
$customerInstaller->addAttribute('customer', 'force_password_reset', [
    'type'           => 'int',
    'input'          => 'select',
    'label'          => 'Force Password Reset',
    'source'         => 'eav/entity_attribute_source_boolean',
    'global'         => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
    'visible'        => true,
    'required'       => false,
    'user_defined'   => false,
    'default'        => '0',
    'system'         => true,
    'position'       => 200,
    'adminhtml_only' => true,
]);

/**
 * Customer attribute: datasync_source_system
 *
 * Identifies which external system the customer was imported from.
 * Examples: 'legacy_store', 'pos', 'woocommerce'
 */
$customerInstaller->addAttribute('customer', 'datasync_source_system', [
    'type'           => 'varchar',
    'input'          => 'text',
    'label'          => 'DataSync Source System',
    'global'         => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
    'visible'        => true,
    'required'       => false,
    'user_defined'   => false,
    'default'        => '',
    'system'         => true,
    'position'       => 201,
    'adminhtml_only' => true,
]);

/**
 * Customer attribute: datasync_source_id
 *
 * The original entity_id from the source system.
 * Useful for quick reference without querying the registry table.
 */
$customerInstaller->addAttribute('customer', 'datasync_source_id', [
    'type'           => 'int',
    'input'          => 'text',
    'label'          => 'DataSync Source ID',
    'global'         => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
    'visible'        => true,
    'required'       => false,
    'user_defined'   => false,
    'default'        => null,
    'system'         => true,
    'position'       => 202,
    'adminhtml_only' => true,
]);

/**
 * Customer attribute: datasync_imported_at
 *
 * Timestamp when the customer was imported via DataSync.
 */
$customerInstaller->addAttribute('customer', 'datasync_imported_at', [
    'type'           => 'datetime',
    'input'          => 'date',
    'label'          => 'DataSync Imported At',
    'global'         => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
    'visible'        => true,
    'required'       => false,
    'user_defined'   => false,
    'default'        => null,
    'system'         => true,
    'position'       => 203,
    'adminhtml_only' => true,
]);

// Add attributes to forms (makes them visible in admin customer edit)
$customerAttributeCodes = [
    'force_password_reset',
    'datasync_source_system',
    'datasync_source_id',
    'datasync_imported_at',
];

foreach ($customerAttributeCodes as $code) {
    $attribute = Mage::getSingleton('eav/config')
        ->getAttribute('customer', $code);

    if ($attribute && $attribute->getId()) {
        // Add to admin customer form
        $customerInstaller->getConnection()->insertIgnore(
            $customerInstaller->getTable('customer/form_attribute'),
            [
                'form_code' => 'adminhtml_customer',
                'attribute_id' => $attribute->getId(),
            ],
        );
    }
}

$customerInstaller->endSetup();

Mage::log(
    'Maho_DataSync: Created customer attributes (force_password_reset, datasync_source_system, datasync_source_id, datasync_imported_at)',
    null,
    'datasync.log',
);

// ============================================================================
// PRODUCT ATTRIBUTES
// ============================================================================

/** @var Mage_Catalog_Model_Resource_Setup $catalogInstaller */
$catalogInstaller = Mage::getResourceModel('catalog/setup', ['resourceName' => 'catalog_setup']);
$catalogInstaller->startSetup();

/**
 * Product attribute group for DataSync attributes
 */
$entityTypeId = $catalogInstaller->getEntityTypeId('catalog_product');
$attributeSetIds = $catalogInstaller->getAllAttributeSetIds($entityTypeId);

foreach ($attributeSetIds as $attributeSetId) {
    $catalogInstaller->addAttributeGroup($entityTypeId, $attributeSetId, 'DataSync', 100);
}

/**
 * Product attribute: datasync_source_system
 */
$catalogInstaller->addAttribute('catalog_product', 'datasync_source_system', [
    'type'                       => 'varchar',
    'input'                      => 'text',
    'label'                      => 'DataSync Source System',
    'global'                     => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
    'visible'                    => true,
    'required'                   => false,
    'user_defined'               => false,
    'default'                    => '',
    'searchable'                 => false,
    'filterable'                 => false,
    'comparable'                 => false,
    'visible_on_front'           => false,
    'used_in_product_listing'    => false,
    'unique'                     => false,
    'apply_to'                   => '',
    'group'                      => 'DataSync',
]);

/**
 * Product attribute: datasync_source_id
 */
$catalogInstaller->addAttribute('catalog_product', 'datasync_source_id', [
    'type'                       => 'int',
    'input'                      => 'text',
    'label'                      => 'DataSync Source ID',
    'global'                     => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
    'visible'                    => true,
    'required'                   => false,
    'user_defined'               => false,
    'default'                    => null,
    'searchable'                 => false,
    'filterable'                 => false,
    'comparable'                 => false,
    'visible_on_front'           => false,
    'used_in_product_listing'    => false,
    'unique'                     => false,
    'apply_to'                   => '',
    'group'                      => 'DataSync',
]);

/**
 * Product attribute: datasync_imported_at
 */
$catalogInstaller->addAttribute('catalog_product', 'datasync_imported_at', [
    'type'                       => 'datetime',
    'input'                      => 'date',
    'label'                      => 'DataSync Imported At',
    'backend'                    => 'eav/entity_attribute_backend_datetime',
    'global'                     => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
    'visible'                    => true,
    'required'                   => false,
    'user_defined'               => false,
    'default'                    => null,
    'searchable'                 => false,
    'filterable'                 => false,
    'comparable'                 => false,
    'visible_on_front'           => false,
    'used_in_product_listing'    => false,
    'unique'                     => false,
    'apply_to'                   => '',
    'group'                      => 'DataSync',
]);

$catalogInstaller->endSetup();

Mage::log(
    'Maho_DataSync: Created product attributes (datasync_source_system, datasync_source_id, datasync_imported_at)',
    null,
    'datasync.log',
);

// ============================================================================
// CATEGORY ATTRIBUTES
// ============================================================================

// Re-use the catalog setup for categories
$catalogInstaller->startSetup();

/**
 * Category attribute: datasync_source_system
 */
$catalogInstaller->addAttribute('catalog_category', 'datasync_source_system', [
    'type'           => 'varchar',
    'input'          => 'text',
    'label'          => 'DataSync Source System',
    'global'         => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
    'visible'        => true,
    'required'       => false,
    'user_defined'   => false,
    'default'        => '',
    'group'          => 'General Information',
]);

/**
 * Category attribute: datasync_source_id
 */
$catalogInstaller->addAttribute('catalog_category', 'datasync_source_id', [
    'type'           => 'int',
    'input'          => 'text',
    'label'          => 'DataSync Source ID',
    'global'         => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
    'visible'        => true,
    'required'       => false,
    'user_defined'   => false,
    'default'        => null,
    'group'          => 'General Information',
]);

/**
 * Category attribute: datasync_imported_at
 */
$catalogInstaller->addAttribute('catalog_category', 'datasync_imported_at', [
    'type'           => 'datetime',
    'input'          => 'date',
    'label'          => 'DataSync Imported At',
    'backend'        => 'eav/entity_attribute_backend_datetime',
    'global'         => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL,
    'visible'        => true,
    'required'       => false,
    'user_defined'   => false,
    'default'        => null,
    'group'          => 'General Information',
]);

$catalogInstaller->endSetup();

Mage::log(
    'Maho_DataSync: Created category attributes (datasync_source_system, datasync_source_id, datasync_imported_at)',
    null,
    'datasync.log',
);

Mage::log('Maho_DataSync: Data install complete - all tracking attributes created', null, 'datasync.log');
