<?php
/**
 * Maho DataSync - Attribute Examples
 *
 * Examples for creating/syncing product attributes and attribute sets.
 */

// ============================================================================
// SIMPLE DROPDOWN ATTRIBUTE
// ============================================================================

$dropdownAttribute = [
    'attribute_code'     => 'brand',
    'frontend_label'     => 'Brand',
    'frontend_input'     => 'select',           // text, textarea, select, multiselect, boolean, date, price
    'is_required'        => 0,
    'is_user_defined'    => 1,
    'is_searchable'      => 1,
    'is_filterable'      => 1,                  // 0=No, 1=Filterable (results), 2=Filterable (no results)
    'is_comparable'      => 1,
    'is_visible_on_front'=> 1,
    'is_html_allowed_on_front' => 0,
    'is_used_for_price_rules'  => 0,
    'is_filterable_in_search'  => 1,
    'used_in_product_listing'  => 1,
    'used_for_sort_by'         => 1,
    'is_visible_in_advanced_search' => 1,
    'position'           => 10,
    'is_global'          => 1,                  // 0=Store View, 1=Global, 2=Website
    'default_value'      => '',
    'apply_to'           => '',                 // Empty = all product types

    // Dropdown options
    'options' => [
        ['label' => 'Wilson', 'sort_order' => 1],
        ['label' => 'Babolat', 'sort_order' => 2],
        ['label' => 'Head', 'sort_order' => 3],
        ['label' => 'Yonex', 'sort_order' => 4],
        ['label' => 'Dunlop', 'sort_order' => 5],
    ],
];

// ============================================================================
// TEXT ATTRIBUTE
// ============================================================================

$textAttribute = [
    'attribute_code'     => 'upc_code',
    'frontend_label'     => 'UPC Code',
    'frontend_input'     => 'text',
    'is_required'        => 0,
    'is_user_defined'    => 1,
    'is_searchable'      => 1,
    'is_filterable'      => 0,
    'is_comparable'      => 0,
    'is_visible_on_front'=> 0,
    'is_global'          => 1,
    'is_unique'          => 1,                  // Enforce unique values
];

// ============================================================================
// MULTISELECT ATTRIBUTE
// ============================================================================

$multiselectAttribute = [
    'attribute_code'     => 'features',
    'frontend_label'     => 'Features',
    'frontend_input'     => 'multiselect',
    'backend_type'       => 'varchar',
    'is_required'        => 0,
    'is_user_defined'    => 1,
    'is_searchable'      => 1,
    'is_filterable'      => 1,
    'is_comparable'      => 1,
    'is_visible_on_front'=> 1,
    'is_global'          => 1,

    'options' => [
        ['label' => 'Lightweight', 'sort_order' => 1],
        ['label' => 'Durable', 'sort_order' => 2],
        ['label' => 'Waterproof', 'sort_order' => 3],
        ['label' => 'UV Protection', 'sort_order' => 4],
        ['label' => 'Ergonomic', 'sort_order' => 5],
    ],
];

// ============================================================================
// BOOLEAN (YES/NO) ATTRIBUTE
// ============================================================================

$booleanAttribute = [
    'attribute_code'     => 'is_clearance',
    'frontend_label'     => 'Clearance Item',
    'frontend_input'     => 'boolean',
    'is_required'        => 0,
    'is_user_defined'    => 1,
    'is_searchable'      => 0,
    'is_filterable'      => 1,
    'is_comparable'      => 0,
    'is_visible_on_front'=> 1,
    'is_global'          => 1,
    'default_value'      => 0,
    'used_in_product_listing' => 1,
];

// ============================================================================
// DATE ATTRIBUTE
// ============================================================================

$dateAttribute = [
    'attribute_code'     => 'release_date',
    'frontend_label'     => 'Release Date',
    'frontend_input'     => 'date',
    'backend_type'       => 'datetime',
    'is_required'        => 0,
    'is_user_defined'    => 1,
    'is_searchable'      => 0,
    'is_filterable'      => 0,
    'is_comparable'      => 0,
    'is_visible_on_front'=> 1,
    'is_global'          => 1,
];

// ============================================================================
// PRICE ATTRIBUTE
// ============================================================================

$priceAttribute = [
    'attribute_code'     => 'msrp',
    'frontend_label'     => 'Manufacturer\'s Suggested Retail Price',
    'frontend_input'     => 'price',
    'backend_type'       => 'decimal',
    'is_required'        => 0,
    'is_user_defined'    => 1,
    'is_searchable'      => 0,
    'is_filterable'      => 0,
    'is_comparable'      => 1,
    'is_visible_on_front'=> 1,
    'is_global'          => 2,                  // Website scope for prices
];

// ============================================================================
// VISUAL SWATCH ATTRIBUTE (for configurable products)
// ============================================================================

$swatchAttribute = [
    'attribute_code'           => 'color',
    'frontend_label'           => 'Color',
    'frontend_input'           => 'select',
    'is_required'              => 0,
    'is_user_defined'          => 1,
    'is_searchable'            => 1,
    'is_filterable'            => 1,
    'is_comparable'            => 1,
    'is_visible_on_front'      => 1,
    'is_global'                => 1,
    'is_configurable'          => 1,            // Can be used in configurable products
    'used_in_product_listing'  => 1,

    // Swatch options with visual values
    'options' => [
        ['label' => 'Red',   'sort_order' => 1, 'swatch_type' => 'color', 'swatch_value' => '#FF0000'],
        ['label' => 'Blue',  'sort_order' => 2, 'swatch_type' => 'color', 'swatch_value' => '#0000FF'],
        ['label' => 'Green', 'sort_order' => 3, 'swatch_type' => 'color', 'swatch_value' => '#00FF00'],
        ['label' => 'Black', 'sort_order' => 4, 'swatch_type' => 'color', 'swatch_value' => '#000000'],
        ['label' => 'White', 'sort_order' => 5, 'swatch_type' => 'color', 'swatch_value' => '#FFFFFF'],
    ],
];

// ============================================================================
// SIZE ATTRIBUTE (for configurable products)
// ============================================================================

$sizeAttribute = [
    'attribute_code'           => 'size',
    'frontend_label'           => 'Size',
    'frontend_input'           => 'select',
    'is_required'              => 0,
    'is_user_defined'          => 1,
    'is_searchable'            => 1,
    'is_filterable'            => 1,
    'is_comparable'            => 0,
    'is_visible_on_front'      => 1,
    'is_global'                => 1,
    'is_configurable'          => 1,
    'used_in_product_listing'  => 1,

    'options' => [
        ['label' => 'XS',  'sort_order' => 1],
        ['label' => 'S',   'sort_order' => 2],
        ['label' => 'M',   'sort_order' => 3],
        ['label' => 'L',   'sort_order' => 4],
        ['label' => 'XL',  'sort_order' => 5],
        ['label' => 'XXL', 'sort_order' => 6],
    ],
];

// ============================================================================
// ATTRIBUTE SET EXAMPLE
// ============================================================================

$attributeSet = [
    'attribute_set_name' => 'Tennis Equipment',
    'skeleton_set_id'    => 4,                  // Clone from Default (ID 4)

    // Groups and their attributes
    'groups' => [
        [
            'name'       => 'General',
            'sort_order' => 1,
            'attributes' => ['name', 'sku', 'price', 'status', 'visibility'],
        ],
        [
            'name'       => 'Tennis Specs',
            'sort_order' => 10,
            'attributes' => ['brand', 'grip_size', 'head_size', 'weight', 'balance'],
        ],
        [
            'name'       => 'Description',
            'sort_order' => 20,
            'attributes' => ['description', 'short_description'],
        ],
        [
            'name'       => 'Images',
            'sort_order' => 30,
            'attributes' => ['image', 'small_image', 'thumbnail', 'media_gallery'],
        ],
    ],
];
