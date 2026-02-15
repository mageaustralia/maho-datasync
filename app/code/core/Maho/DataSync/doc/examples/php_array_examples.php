<?php
/**
 * Maho DataSync - PHP Array Examples
 *
 * These examples show how to use DataSync programmatically with PHP arrays.
 * This is useful for custom integrations, API imports, or migration scripts.
 */

// ============================================================================
// CUSTOMER EXAMPLE
// ============================================================================

$customerData = [
    'entity_id'     => 1001,                    // Source system ID
    'email'         => 'john.doe@example.com',
    'firstname'     => 'John',
    'lastname'      => 'Doe',
    'gender'        => 1,                       // 1=Male, 2=Female
    'dob'           => '1985-06-15',
    'group_id'      => 1,                       // General group
    'store_id'      => 1,
    'website_id'    => 1,

    // Billing address
    'billing_firstname'   => 'John',
    'billing_lastname'    => 'Doe',
    'billing_company'     => 'ACME Corp',
    'billing_street'      => "123 Main Street\nSuite 400",
    'billing_city'        => 'Sydney',
    'billing_region'      => 'New South Wales',
    'billing_postcode'    => '2000',
    'billing_country_id'  => 'AU',
    'billing_telephone'   => '+61 2 9000 0000',

    // Shipping address (or use 'same_as_billing' => 1)
    'shipping_firstname'  => 'John',
    'shipping_lastname'   => 'Doe',
    'shipping_street'     => '456 Warehouse Road',
    'shipping_city'       => 'Melbourne',
    'shipping_region'     => 'Victoria',
    'shipping_postcode'   => '3000',
    'shipping_country_id' => 'AU',
    'shipping_telephone'  => '+61 3 9000 0000',
];

// ============================================================================
// ORDER EXAMPLE
// ============================================================================

$orderData = [
    'entity_id'          => 5001,               // Source system ID
    'increment_id'       => 'ORD-2024-0001',    // Order number
    'customer_id'        => 1001,               // Source customer ID (resolved via registry)
    'customer_email'     => 'john.doe@example.com',
    'customer_firstname' => 'John',
    'customer_lastname'  => 'Doe',
    'customer_is_guest'  => 0,

    // Order details
    'status'             => 'processing',
    'state'              => 'processing',
    'store_id'           => 1,
    'created_at'         => '2024-01-15 10:30:00',

    // Totals
    'subtotal'           => 109.90,
    'tax_amount'         => 10.99,
    'shipping_amount'    => 9.95,
    'discount_amount'    => 0.00,
    'grand_total'        => 130.84,
    'base_grand_total'   => 130.84,
    'total_qty_ordered'  => 3,

    // Shipping
    'shipping_method'      => 'flatrate_flatrate',
    'shipping_description' => 'Flat Rate - Fixed',

    // Payment
    'payment_method'       => 'checkmo',

    // Currency
    'order_currency_code'  => 'AUD',
    'base_currency_code'   => 'AUD',

    // Billing address
    'billing_firstname'    => 'John',
    'billing_lastname'     => 'Doe',
    'billing_street'       => '123 Main Street',
    'billing_city'         => 'Sydney',
    'billing_region'       => 'New South Wales',
    'billing_postcode'     => '2000',
    'billing_country_id'   => 'AU',
    'billing_telephone'    => '+61 2 9000 0000',

    // Shipping address
    'shipping_firstname'   => 'John',
    'shipping_lastname'    => 'Doe',
    'shipping_street'      => '123 Main Street',
    'shipping_city'        => 'Sydney',
    'shipping_region'      => 'New South Wales',
    'shipping_postcode'    => '2000',
    'shipping_country_id'  => 'AU',
    'shipping_telephone'   => '+61 2 9000 0000',

    // Order items (array format)
    'items' => [
        [
            'sku'          => 'DEMO-SHIRT-BLU-M',
            'name'         => 'Blue T-Shirt Medium',
            'qty_ordered'  => 2,
            'price'        => 29.95,
            'row_total'    => 59.90,
            'tax_amount'   => 5.99,
            'tax_percent'  => 10.00,
            'product_type' => 'simple',
        ],
        [
            'sku'          => 'DEMO-HAT-WHT',
            'name'         => 'White Baseball Cap',
            'qty_ordered'  => 1,
            'price'        => 19.95,
            'row_total'    => 19.95,
            'tax_amount'   => 2.00,
            'tax_percent'  => 10.00,
            'product_type' => 'simple',
        ],
    ],
];

// ============================================================================
// PRODUCT EXAMPLE (Simple)
// ============================================================================

$productSimple = [
    'entity_id'         => 2001,
    'sku'               => 'DEMO-WIDGET-001',
    'name'              => 'Demo Widget',
    'description'       => 'This is a demo widget for testing purposes.',
    'short_description' => 'Demo widget',
    'price'             => 49.95,
    'special_price'     => 39.95,
    'cost'              => 20.00,
    'weight'            => 1.5,
    'status'            => 1,                   // 1=Enabled, 2=Disabled
    'visibility'        => 4,                   // 4=Catalog/Search
    'tax_class_id'      => 2,                   // Taxable Goods
    'type_id'           => 'simple',
    'attribute_set_id'  => 4,                   // Default

    // Stock
    'qty'               => 100,
    'is_in_stock'       => 1,
    'manage_stock'      => 1,

    // SEO
    'url_key'           => 'demo-widget',
    'meta_title'        => 'Demo Widget',
    'meta_description'  => 'Buy our demo widget.',

    // Categories (array of IDs)
    'category_ids'      => [3, 5, 12],

    // Custom attributes
    'color'             => 'Blue',
    'manufacturer'      => 'ACME',
];

// ============================================================================
// PRODUCT EXAMPLE (Configurable)
// ============================================================================

$productConfigurable = [
    'entity_id'           => 3001,
    'sku'                 => 'DEMO-SHIRT-CONFIG',
    'name'                => 'Demo T-Shirt',
    'description'         => 'Premium cotton t-shirt available in multiple sizes.',
    'short_description'   => 'Cotton t-shirt',
    'price'               => 29.95,
    'status'              => 1,
    'visibility'          => 4,
    'type_id'             => 'configurable',
    'attribute_set_id'    => 4,

    // Configurable attributes
    'configurable_attributes' => ['size', 'color'],

    // Associated simple products
    'associated_products' => [
        ['sku' => 'DEMO-SHIRT-S-BLU', 'size' => 'S', 'color' => 'Blue', 'price' => 29.95, 'qty' => 50],
        ['sku' => 'DEMO-SHIRT-M-BLU', 'size' => 'M', 'color' => 'Blue', 'price' => 29.95, 'qty' => 75],
        ['sku' => 'DEMO-SHIRT-L-BLU', 'size' => 'L', 'color' => 'Blue', 'price' => 29.95, 'qty' => 60],
        ['sku' => 'DEMO-SHIRT-S-RED', 'size' => 'S', 'color' => 'Red', 'price' => 29.95, 'qty' => 40],
        ['sku' => 'DEMO-SHIRT-M-RED', 'size' => 'M', 'color' => 'Red', 'price' => 29.95, 'qty' => 55],
        ['sku' => 'DEMO-SHIRT-L-RED', 'size' => 'L', 'color' => 'Red', 'price' => 29.95, 'qty' => 45],
    ],

    'category_ids' => [3, 5, 12],
];

// ============================================================================
// CATEGORY EXAMPLE
// ============================================================================

$categoryData = [
    'entity_id'       => 10,
    'parent_id'       => 2,                     // Parent category ID
    'name'            => 'New Arrivals',
    'description'     => 'Check out our latest products!',
    'url_key'         => 'new-arrivals',
    'is_active'       => 1,
    'include_in_menu' => 1,
    'position'        => 1,
    'meta_title'      => 'New Arrivals',
    'meta_description'=> 'Shop our newest products',
];

// ============================================================================
// INVOICE EXAMPLE
// ============================================================================

$invoiceData = [
    'entity_id'        => 6001,
    'increment_id'     => 'INV-2024-0001',
    'order_id'         => 5001,                 // Source order ID (resolved via registry)
    'state'            => 2,                    // 2=Paid
    'grand_total'      => 130.84,
    'base_grand_total' => 130.84,
    'subtotal'         => 109.90,
    'tax_amount'       => 10.99,
    'shipping_amount'  => 9.95,
    'created_at'       => '2024-01-15 11:00:00',

    // Items to invoice (optional - defaults to all order items)
    'items' => [
        ['sku' => 'DEMO-SHIRT-BLU-M', 'qty' => 2],
        ['sku' => 'DEMO-HAT-WHT', 'qty' => 1],
    ],
];

// ============================================================================
// SHIPMENT EXAMPLE
// ============================================================================

$shipmentData = [
    'entity_id'    => 7001,
    'increment_id' => 'SHP-2024-0001',
    'order_id'     => 5001,                     // Source order ID (resolved via registry)
    'created_at'   => '2024-01-16 09:00:00',

    // Items to ship
    'items' => [
        ['sku' => 'DEMO-SHIRT-BLU-M', 'qty' => 2],
        ['sku' => 'DEMO-HAT-WHT', 'qty' => 1],
    ],

    // Tracking numbers (optional)
    'tracks' => [
        [
            'carrier_code' => 'auspost',
            'title'        => 'Australia Post',
            'track_number' => 'AP123456789AU',
        ],
    ],
];

// ============================================================================
// USAGE: Programmatic Import
// ============================================================================

/*
// Import a single customer
$engine = Mage::getModel('datasync/engine');
$engine->setSourceSystem('my_source');

$handler = Mage::getModel('datasync/entity_customer');
$result = $engine->importSingle('customer', $customerData);

if ($result->hasErrors()) {
    foreach ($result->getErrors() as $error) {
        Mage::log("Import error: " . $error['message']);
    }
}

// Batch import multiple orders
$orders = [$orderData, $orderData2, $orderData3];
$adapter = Mage::getModel('datasync/adapter_array');
$adapter->setData($orders);

$engine->setSourceAdapter($adapter);
$result = $engine->sync('order');

echo "Created: " . $result->getCreated() . "\n";
echo "Updated: " . $result->getUpdated() . "\n";
echo "Skipped: " . $result->getSkipped() . "\n";
echo "Errors: " . count($result->getErrors()) . "\n";
*/
