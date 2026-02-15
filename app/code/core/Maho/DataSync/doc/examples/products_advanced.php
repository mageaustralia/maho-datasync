<?php
/**
 * Maho DataSync - Advanced Product Examples
 *
 * Examples for configurable, grouped, bundle products, and image handling.
 */

// ============================================================================
// SIMPLE PRODUCT WITH IMAGES
// ============================================================================

$simpleProductWithImages = [
    'entity_id'         => 2001,
    'sku'               => 'DEMO-RACKET-PRO',
    'name'              => 'Pro Tennis Racket',
    'description'       => 'Professional grade tennis racket with carbon fiber frame.',
    'short_description' => 'Pro tennis racket',
    'price'             => 299.95,
    'special_price'     => 249.95,
    'weight'            => 0.32,
    'status'            => 1,
    'visibility'        => 4,
    'type_id'           => 'simple',
    'attribute_set_id'  => 4,

    // Stock
    'qty'               => 50,
    'is_in_stock'       => 1,

    // Categories
    'category_ids'      => [3, 10, 25],

    // Images - multiple formats supported
    'images' => [
        [
            'file'       => '/p/r/pro-racket-front.jpg',    // Path in media/catalog/product
            'label'      => 'Pro Racket - Front View',
            'position'   => 1,
            'types'      => ['image', 'small_image', 'thumbnail'],  // Base, small, thumb
            'disabled'   => 0,
        ],
        [
            'file'       => '/p/r/pro-racket-side.jpg',
            'label'      => 'Pro Racket - Side View',
            'position'   => 2,
            'types'      => [],                              // Additional image only
            'disabled'   => 0,
        ],
        [
            'file'       => '/p/r/pro-racket-detail.jpg',
            'label'      => 'Pro Racket - String Detail',
            'position'   => 3,
            'types'      => [],
            'disabled'   => 0,
        ],
    ],

    // Alternative: Simple image assignment (uses first as base/small/thumb)
    // 'image'       => '/p/r/pro-racket-front.jpg',
    // 'small_image' => '/p/r/pro-racket-front.jpg',
    // 'thumbnail'   => '/p/r/pro-racket-front.jpg',
];

// ============================================================================
// CONFIGURABLE PRODUCT
// ============================================================================

$configurableProduct = [
    'entity_id'         => 3001,
    'sku'               => 'DEMO-POLO-CONFIG',
    'name'              => 'Classic Polo Shirt',
    'description'       => 'Classic polo shirt available in multiple sizes and colors.',
    'short_description' => 'Classic polo shirt',
    'price'             => 49.95,
    'status'            => 1,
    'visibility'        => 4,                   // Catalog, Search
    'type_id'           => 'configurable',
    'attribute_set_id'  => 4,

    'category_ids'      => [3, 5, 12],

    // Configurable attributes (must be catalog_input_type = select/dropdown)
    'configurable_attributes' => ['size', 'color'],

    // Super attribute pricing (optional - price adjustments per option)
    'super_attribute_pricing' => [
        'size' => [
            'XL'  => ['price' => 5.00, 'is_percent' => 0],   // +$5.00 for XL
            'XXL' => ['price' => 10.00, 'is_percent' => 0],  // +$10.00 for XXL
        ],
        'color' => [
            'Gold' => ['price' => 10, 'is_percent' => 1],    // +10% for Gold
        ],
    ],

    // Associated simple products
    'associated_products' => [
        [
            'sku'          => 'DEMO-POLO-S-WHT',
            'name'         => 'Classic Polo Shirt - S / White',
            'size'         => 'S',
            'color'        => 'White',
            'price'        => 49.95,
            'qty'          => 25,
            'is_in_stock'  => 1,
            'visibility'   => 1,                // Not visible individually
            'status'       => 1,
        ],
        [
            'sku'          => 'DEMO-POLO-M-WHT',
            'name'         => 'Classic Polo Shirt - M / White',
            'size'         => 'M',
            'color'        => 'White',
            'price'        => 49.95,
            'qty'          => 40,
            'is_in_stock'  => 1,
            'visibility'   => 1,
            'status'       => 1,
        ],
        [
            'sku'          => 'DEMO-POLO-L-WHT',
            'name'         => 'Classic Polo Shirt - L / White',
            'size'         => 'L',
            'color'        => 'White',
            'price'        => 49.95,
            'qty'          => 35,
            'is_in_stock'  => 1,
            'visibility'   => 1,
            'status'       => 1,
        ],
        [
            'sku'          => 'DEMO-POLO-S-NAV',
            'name'         => 'Classic Polo Shirt - S / Navy',
            'size'         => 'S',
            'color'        => 'Navy',
            'price'        => 49.95,
            'qty'          => 20,
            'is_in_stock'  => 1,
            'visibility'   => 1,
            'status'       => 1,
        ],
        [
            'sku'          => 'DEMO-POLO-M-NAV',
            'name'         => 'Classic Polo Shirt - M / Navy',
            'size'         => 'M',
            'color'        => 'Navy',
            'price'        => 49.95,
            'qty'          => 30,
            'is_in_stock'  => 1,
            'visibility'   => 1,
            'status'       => 1,
        ],
    ],

    // Images for configurable (shown on product page)
    'images' => [
        [
            'file'     => '/p/o/polo-white-front.jpg',
            'label'    => 'White Polo - Front',
            'position' => 1,
            'types'    => ['image', 'small_image', 'thumbnail'],
        ],
        [
            'file'     => '/p/o/polo-navy-front.jpg',
            'label'    => 'Navy Polo - Front',
            'position' => 2,
            'types'    => [],
        ],
    ],
];

// ============================================================================
// GROUPED PRODUCT
// ============================================================================

$groupedProduct = [
    'entity_id'         => 4001,
    'sku'               => 'DEMO-TENNIS-SET',
    'name'              => 'Tennis Starter Set',
    'description'       => 'Everything you need to start playing tennis. Includes racket, balls, and bag.',
    'short_description' => 'Complete tennis starter set',
    'status'            => 1,
    'visibility'        => 4,
    'type_id'           => 'grouped',
    'attribute_set_id'  => 4,

    'category_ids'      => [3, 10],

    // Associated products with default quantities
    'associated_products' => [
        [
            'sku'             => 'DEMO-RACKET-BASIC',
            'name'            => 'Basic Tennis Racket',
            'price'           => 89.95,
            'default_qty'     => 1,
            'position'        => 1,
            'qty'             => 100,
            'is_in_stock'     => 1,
        ],
        [
            'sku'             => 'DEMO-BALLS-3PK',
            'name'            => 'Tennis Balls (3 Pack)',
            'price'           => 12.95,
            'default_qty'     => 2,                 // Suggest 2 packs
            'position'        => 2,
            'qty'             => 500,
            'is_in_stock'     => 1,
        ],
        [
            'sku'             => 'DEMO-BAG-BASIC',
            'name'            => 'Tennis Bag',
            'price'           => 39.95,
            'default_qty'     => 1,
            'position'        => 3,
            'qty'             => 75,
            'is_in_stock'     => 1,
        ],
    ],

    'images' => [
        [
            'file'     => '/t/e/tennis-set-complete.jpg',
            'label'    => 'Tennis Starter Set',
            'position' => 1,
            'types'    => ['image', 'small_image', 'thumbnail'],
        ],
    ],
];

// ============================================================================
// BUNDLE PRODUCT
// ============================================================================

$bundleProduct = [
    'entity_id'         => 5001,
    'sku'               => 'DEMO-CUSTOM-RACKET',
    'name'              => 'Build Your Own Racket Bundle',
    'description'       => 'Customize your perfect tennis racket setup.',
    'short_description' => 'Customizable racket bundle',
    'price'             => 0,                       // Dynamic pricing
    'price_type'        => 0,                       // 0=Dynamic, 1=Fixed
    'sku_type'          => 0,                       // 0=Dynamic, 1=Fixed
    'weight_type'       => 0,                       // 0=Dynamic, 1=Fixed
    'status'            => 1,
    'visibility'        => 4,
    'type_id'           => 'bundle',
    'attribute_set_id'  => 4,

    'category_ids'      => [3, 10],

    // Bundle options
    'bundle_options' => [
        [
            'title'      => 'Choose Your Racket',
            'type'       => 'radio',                // radio, select, checkbox, multi
            'required'   => 1,
            'position'   => 1,
            'selections' => [
                [
                    'sku'              => 'DEMO-RACKET-BASIC',
                    'name'             => 'Basic Racket',
                    'selection_qty'    => 1,
                    'selection_price'  => 89.95,
                    'is_default'       => 1,
                    'position'         => 1,
                ],
                [
                    'sku'              => 'DEMO-RACKET-PRO',
                    'name'             => 'Pro Racket',
                    'selection_qty'    => 1,
                    'selection_price'  => 249.95,
                    'is_default'       => 0,
                    'position'         => 2,
                ],
            ],
        ],
        [
            'title'      => 'Add Balls',
            'type'       => 'select',
            'required'   => 0,                      // Optional
            'position'   => 2,
            'selections' => [
                [
                    'sku'              => 'DEMO-BALLS-3PK',
                    'name'             => '3 Pack Balls',
                    'selection_qty'    => 1,
                    'selection_price'  => 12.95,
                    'is_default'       => 0,
                    'position'         => 1,
                ],
                [
                    'sku'              => 'DEMO-BALLS-6PK',
                    'name'             => '6 Pack Balls',
                    'selection_qty'    => 1,
                    'selection_price'  => 22.95,
                    'is_default'       => 1,
                    'position'         => 2,
                ],
            ],
        ],
        [
            'title'      => 'Add Accessories',
            'type'       => 'checkbox',             // Multi-select
            'required'   => 0,
            'position'   => 3,
            'selections' => [
                [
                    'sku'              => 'DEMO-GRIP-WHT',
                    'name'             => 'White Grip Tape',
                    'selection_qty'    => 1,
                    'selection_price'  => 8.95,
                    'is_default'       => 0,
                    'position'         => 1,
                ],
                [
                    'sku'              => 'DEMO-DAMPENER',
                    'name'             => 'Vibration Dampener',
                    'selection_qty'    => 1,
                    'selection_price'  => 4.95,
                    'is_default'       => 0,
                    'position'         => 2,
                ],
                [
                    'sku'              => 'DEMO-OVERGRIP-3',
                    'name'             => 'Overgrip 3-Pack',
                    'selection_qty'    => 1,
                    'selection_price'  => 14.95,
                    'is_default'       => 0,
                    'position'         => 3,
                ],
            ],
        ],
    ],

    'images' => [
        [
            'file'     => '/b/u/bundle-racket-custom.jpg',
            'label'    => 'Build Your Own Racket',
            'position' => 1,
            'types'    => ['image', 'small_image', 'thumbnail'],
        ],
    ],
];

// ============================================================================
// DOWNLOADABLE PRODUCT
// ============================================================================

$downloadableProduct = [
    'entity_id'         => 6001,
    'sku'               => 'DEMO-EBOOK-TENNIS',
    'name'              => 'Tennis Mastery eBook',
    'description'       => 'Complete guide to mastering tennis techniques. PDF download.',
    'short_description' => 'Tennis technique guide',
    'price'             => 19.95,
    'status'            => 1,
    'visibility'        => 4,
    'type_id'           => 'downloadable',
    'attribute_set_id'  => 4,

    'category_ids'      => [3, 20],

    // Links (downloadable files)
    'downloadable_links' => [
        [
            'title'               => 'Tennis Mastery eBook (PDF)',
            'price'               => 0,             // Included in product price
            'type'                => 'file',        // file or url
            'file'                => '/t/e/tennis-mastery-ebook.pdf',
            'number_of_downloads' => 5,             // 0 = unlimited
            'is_shareable'        => 0,             // 0=No, 1=Yes, 2=Use config
            'sort_order'          => 1,
        ],
        [
            'title'               => 'Bonus: Video Tutorials',
            'price'               => 9.95,          // Additional purchase
            'type'                => 'url',
            'link_url'            => 'https://example.com/videos/tennis-tutorials.zip',
            'number_of_downloads' => 3,
            'is_shareable'        => 0,
            'sort_order'          => 2,
        ],
    ],

    // Samples (free previews)
    'downloadable_samples' => [
        [
            'title'      => 'Free Chapter Preview',
            'type'       => 'file',
            'file'       => '/t/e/tennis-mastery-sample.pdf',
            'sort_order' => 1,
        ],
    ],

    'images' => [
        [
            'file'     => '/e/b/ebook-tennis-cover.jpg',
            'label'    => 'Tennis Mastery eBook Cover',
            'position' => 1,
            'types'    => ['image', 'small_image', 'thumbnail'],
        ],
    ],
];

// ============================================================================
// IMAGE IMPORT FROM EXTERNAL URL
// ============================================================================

$productWithExternalImages = [
    'entity_id' => 7001,
    'sku'       => 'DEMO-EXTERNAL-IMG',
    'name'      => 'Product with External Images',
    // ... other attributes ...

    // Import images from URLs (will be downloaded and stored locally)
    'images' => [
        [
            'url'        => 'https://example.com/images/product-front.jpg',
            'label'      => 'Front View',
            'position'   => 1,
            'types'      => ['image', 'small_image', 'thumbnail'],
        ],
        [
            'url'        => 'https://example.com/images/product-back.jpg',
            'label'      => 'Back View',
            'position'   => 2,
            'types'      => [],
        ],
    ],
];

// ============================================================================
// CSV FORMAT FOR CONFIGURABLE
// ============================================================================

/*
For CSV imports, configurable products need two rows:

Row 1: The configurable parent
sku,name,type_id,configurable_attributes,visibility,...
DEMO-POLO-CONFIG,Classic Polo,configurable,"size,color",4,...

Row 2+: Associated simple products (reference parent via _super_products_sku)
sku,name,type_id,_super_products_sku,size,color,visibility,...
DEMO-POLO-S-WHT,Polo S White,simple,DEMO-POLO-CONFIG,S,White,1,...
DEMO-POLO-M-WHT,Polo M White,simple,DEMO-POLO-CONFIG,M,White,1,...

OR use JSON in a single row:
sku,name,type_id,associated_products_json,...
DEMO-POLO-CONFIG,Classic Polo,configurable,"[{""sku"":""DEMO-POLO-S-WHT"",""size"":""S"",""color"":""White""}]",...
*/
