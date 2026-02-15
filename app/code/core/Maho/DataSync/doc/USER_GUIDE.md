# Maho DataSync User Guide

DataSync is Maho's built-in data migration and synchronization module for importing data from external systems.

## Table of Contents

1. [Quick Start](#quick-start)
2. [Supported Entity Types](#supported-entity-types)
3. [Command Line Usage](#command-line-usage)
4. [CSV Import Guide](#csv-import-guide)
5. [Product Image Import](#product-image-import)
6. [Product Advanced Features](#product-advanced-features)
   - [Group Prices](#group-prices)
   - [Configurable Product Links](#configurable-product-links)
   - [Grouped Product Links](#grouped-product-links)
   - [Custom Options](#custom-options)
   - [Bundle Options](#bundle-options-todo)
7. [Foreign Key Resolution](#foreign-key-resolution)
8. [Duplicate Handling](#duplicate-handling)
9. [Edge Cases & Validation](#edge-cases--validation)
10. [Troubleshooting](#troubleshooting)

---

## Quick Start

```bash
# Import customers from CSV
./maho datasync:sync customer legacy_store -a csv -f var/import/customers.csv

# Import orders (after customers)
./maho datasync:sync order legacy_store -a csv -f var/import/orders.csv

# Dry run to validate without importing
./maho datasync:sync customer test -a csv -f var/import/customers.csv --dry-run

# Import products with auto-linking of configurables
./maho datasync:sync product legacy -a csv -f var/import/products.csv --auto-link-configurables

# Import products with custom options merge mode
./maho datasync:sync product legacy -a csv -f var/import/products.csv --options-mode merge
```

---

## Supported Entity Types

| Entity | Dependencies | Description |
|--------|--------------|-------------|
| `customer` | None | Customer accounts with addresses |
| `order` | customer (optional) | Sales orders with items |
| `invoice` | order | Invoices (payment records) |
| `shipment` | order | Shipments with tracking numbers |
| `creditmemo` | order, invoice (optional) | Credit memos / refunds |
| `product` | category, product_attribute | Catalog products |
| `category` | None | Product categories |
| `product_attribute` | None | EAV attributes |

**Import Order**: Always import dependencies first:
1. `product_attribute` → 2. `category` → 3. `customer` → 4. `product` → 5. `order` → 6. `invoice` → 7. `shipment` → 8. `creditmemo`

**Email Suppression**: All imports automatically suppress transactional emails. No order confirmations, invoice emails, or shipment notifications will be sent during import.

---

## Command Line Usage

```bash
./maho datasync:sync <entity> <source> [options]
```

### Arguments

| Argument | Description |
|----------|-------------|
| `entity` | Entity type to import (customer, order, product, category, invoice, shipment, creditmemo, productattribute) |
| `source` | Source system identifier for tracking (e.g., "legacy", "woocommerce") |

### Required Options

| Option | Short | Description |
|--------|-------|-------------|
| `--adapter` | `-a` | Data source adapter (csv, openmage, woocommerce, shopify, magento2) |
| `--file` | `-f` | CSV file path (required for csv adapter) |
| `--url` | `-u` | Remote API URL (required for API-based adapters) |

### Optional Options

| Option | Short | Default | Description |
|--------|-------|---------|-------------|
| `--on-duplicate` | `-d` | error | How to handle duplicates: skip, update, merge, error |
| `--dry-run` | | false | Validate without importing |
| `--skip-invalid` | | false | Skip invalid records instead of failing |
| `--limit` | `-l` | none | Maximum records to import |
| `--date-from` | | none | Filter by modified_at >= date |
| `--date-to` | | none | Filter by modified_at <= date |
| `--api-key` | `-k` | none | API key for authentication |
| `--api-secret` | | none | API secret for authentication |

### Product Image Options

| Option | Default | Description |
|--------|---------|-------------|
| `--image-base-url` | none | Base URL for downloading images remotely |
| `--image-base-path` | none | Base path for copying local images |
| `--force-image-download` | false | Re-download images even if they already exist |
| `--image-timeout` | 30000 | Timeout for image downloads in milliseconds |

### Product Advanced Options

| Option | Default | Description |
|--------|---------|-------------|
| `--auto-link-configurables` | false | Auto-link simple products to configurables based on position in CSV |
| `--options-mode` | replace | Custom options handling: `replace`, `merge`, or `append` |

### Duplicate Handling Modes

| Mode | Behavior |
|------|----------|
| `skip` | Skip record if duplicate found |
| `update` | Overwrite all fields on existing record |
| `merge` | Only update non-empty fields (preserve existing) |
| `error` | Fail with error if duplicate found |

---

## CSV Import Guide

### Customer CSV Format

**Required columns**: `email`, `firstname`, `lastname`

```csv
entity_id,email,firstname,lastname,gender,dob,store_id,website_id
1,john@example.com,John,Doe,1,1985-03-15,1,1
```

**With addresses** (use `billing_*` and `shipping_*` prefixes):

```csv
entity_id,email,firstname,lastname,billing_street,billing_city,billing_postcode,billing_country_id,billing_telephone,same_as_billing
1,john@example.com,John,Doe,123 Main St,Sydney,2000,AU,0412345678,1
```

**Address Columns**:
- `billing_firstname`, `billing_lastname`, `billing_company`
- `billing_street`, `billing_city`, `billing_region`, `billing_postcode`
- `billing_country_id`, `billing_telephone`, `billing_fax`
- Same pattern for `shipping_*`
- `same_as_billing` - Set to 1 to use billing address for shipping

### Order CSV Format

**Required columns**: `increment_id`, `grand_total`, `base_grand_total`

```csv
entity_id,increment_id,customer_id,customer_email,grand_total,base_grand_total,status,state
1,ORD-001,1,john@example.com,99.95,99.95,pending,new
```

**Customer Linking**: Orders link to customers in this priority:
1. `customer_id` from CSV → resolved via FK registry (if customers imported first)
2. `customer_email` → looks up existing customer by email (automatic fallback)
3. If neither matches → creates as guest order

**With items** (JSON or pipe-delimited):

```csv
# JSON format (recommended)
items
"[{""sku"":""ABC"",""name"":""Product"",""qty_ordered"":2,""price"":49.95}]"

# Pipe-delimited format (simple)
items
SKU1:qty:price|SKU2:qty:price
```

### Invoice CSV Format

**Required columns**: `order_id`, `grand_total`

Invoices are automatically linked to their order. Items can be specified or auto-created from order items.

```csv
entity_id,order_id,increment_id,grand_total,created_at,items
1,100,INV-001,99.95,2024-01-15,"[{""sku"":""ABC"",""qty"":1,""price"":99.95}]"
```

**Invoice Columns**:
- `order_id` - Required: Links to order (resolved via FK registry)
- `increment_id` - Source invoice number (new one generated in Maho)
- `grand_total`, `base_grand_total` - Invoice totals
- `subtotal`, `tax_amount`, `shipping_amount`, `discount_amount`
- `created_at` - Invoice date
- `state` - Invoice state (default: 2 = paid)
- `items` - Optional: JSON array of items. If empty, auto-creates from order

### Shipment CSV Format

**Required columns**: `order_id`

Shipments support tracking numbers in multiple formats.

```csv
entity_id,order_id,increment_id,created_at,items,tracks
1,100,SHIP-001,2024-01-16,"[{""sku"":""ABC"",""qty"":1}]","[{""carrier_code"":""auspost"",""track_number"":""ABC123456""}]"
```

**Tracking Formats**:
```csv
# JSON format (recommended)
tracks
"[{""carrier_code"":""auspost"",""track_number"":""ABC123"",""title"":""Australia Post""}]"

# Pipe-delimited format (simple)
tracks
auspost:ABC123|auspost:DEF456
```

**Common Carrier Codes**:
- `auspost` - Australia Post
- `auspost_eparcel` - Australia Post eParcel
- `dhl`, `fedex`, `ups`, `usps`, `tnt`, `startrack`, `aramex`, `sendle`
- `custom` - Custom carrier (default if not specified)

### Credit Memo CSV Format

**Required columns**: `order_id`, `grand_total`

Credit memos record refunds. Items can be specified or auto-created.

```csv
entity_id,order_id,increment_id,grand_total,created_at,adjustment_positive,adjustment_negative,items
1,100,CM-001,49.95,2024-02-01,0,0,"[{""sku"":""ABC"",""qty"":1}]"
```

**Credit Memo Columns**:
- `order_id` - Required: Links to order
- `invoice_id` - Optional: Links to specific invoice
- `grand_total`, `base_grand_total` - Refund totals
- `adjustment_positive` - Fee added to refund
- `adjustment_negative` - Fee subtracted from refund
- `shipping_amount` - Shipping amount refunded
- `offline_requested` - 1 for offline refund (default), 0 for online

### Product CSV Format

**Required columns**: `sku`, `name`, `price`, `attribute_set`, `type_id`

```csv
sku,name,price,attribute_set,type_id,status,visibility,weight,description
ABC123,Product Name,99.95,Default,simple,1,4,1.5,Product description here
```

**Product Columns**:
- `sku` - Required: Unique product identifier
- `name` - Required: Product name
- `price` - Required: Product price
- `attribute_set` - Required: Attribute set name (e.g., "Default")
- `type_id` - Required: Product type (simple, configurable, grouped, bundle, virtual, downloadable)
- `status` - 1 = Enabled, 2 = Disabled
- `visibility` - 1 = Not Visible, 2 = Catalog, 3 = Search, 4 = Both
- `weight` - Product weight
- `tax_class_id` - Tax class ID (0 = None, 2 = Taxable Goods)
- `category_ids` - Comma-separated category IDs or JSON array

**Tier Prices** (JSON format):
```csv
tier_prices
"[{""website_id"":0,""cust_group"":32000,""qty"":5,""price"":89.95}]"
```

### Product Image Import

DataSync supports importing product images from local paths or remote URLs.

**Image Columns**:
- `image` - Main product image (base image)
- `small_image` - Catalog listing image
- `thumbnail` - Thumbnail image
- `media_gallery` - Additional gallery images

**Image Path Formats**:

```csv
# Relative path (combined with --image-base-path or --image-base-url)
image
/m/a/main-image.jpg

# Full URL (downloaded directly)
image
https://example.com/images/main-image.jpg

# Media gallery - JSON array
media_gallery
"[""gallery1.jpg"",""gallery2.jpg"",""gallery3.jpg""]"

# Media gallery - pipe-separated
media_gallery
gallery1.jpg|gallery2.jpg|gallery3.jpg

# Media gallery - comma-separated
media_gallery
gallery1.jpg,gallery2.jpg,gallery3.jpg
```

**Image Import Priority**:

When importing an image, DataSync follows this priority chain:

1. **Check destination** - If image already exists in `media/catalog/product/`, skip (unless `--force-image-download`)
2. **Check base-path** - If `--image-base-path` is set, copy from local path
3. **Download from URL** - If `--image-base-url` is set, download via HTTP

This approach is optimized for delta imports where most images already exist.

**Example Commands**:

```bash
# Import with images from local path (fastest)
php shell/datasync.php --entity product --adapter csv \
    --source products.csv --system legacy \
    --image-base-path /var/www/source/media/catalog/product

# Import with images from remote URL
php shell/datasync.php --entity product --adapter csv \
    --source products.csv --system legacy \
    --image-base-url https://source-store.com/media/catalog/product

# Combine both (local first, then URL fallback)
php shell/datasync.php --entity product --adapter csv \
    --source products.csv --system legacy \
    --image-base-path /backup/media/catalog/product \
    --image-base-url https://source-store.com/media/catalog/product

# Force re-download all images (ignore existing)
php shell/datasync.php --entity product --adapter csv \
    --source products.csv --system legacy \
    --image-base-url https://source-store.com/media/catalog/product \
    --force-image-download
```

**Image Path Resolution**:

Images in CSV should use the Magento media path format (`/m/a/main.jpg`):

| CSV Value | With `--image-base-path /source/media` | Result |
|-----------|----------------------------------------|--------|
| `/m/a/main.jpg` | `/source/media/m/a/main.jpg` | Copied to `media/catalog/product/m/a/main.jpg` |
| `main.jpg` | `/source/media/main.jpg` | Copied to `media/catalog/product/m/a/main.jpg` |

**Note**: The destination path is always generated from the filename characters (e.g., `main.jpg` → `/m/a/main.jpg`), matching Magento's standard media structure.

### Multi-line Values

Wrap values containing newlines in quotes:

```csv
billing_street
"123 Main St
Apartment 4B"
```

---

## Product Advanced Features

### Group Prices

Group prices allow different pricing for specific customer groups.

**CSV Column**: `group_prices`

**JSON Format**:
```csv
group_prices
"[{""cust_group"":1,""price"":89.95,""website_id"":0},{""cust_group"":2,""price"":79.95,""is_percent"":1}]"
```

**Pipe-Delimited Format**:
```csv
group_prices
1:89.95|2:79.95%|32000:69.95
```

| Field | Description |
|-------|-------------|
| `cust_group` | Customer group ID (32000 = all groups) |
| `price` | Price value or percentage |
| `website_id` | Website ID (default: 0 = all websites) |
| `is_percent` | Set to 1 for percentage discount |

The trailing `%` in pipe format indicates a percentage discount.

---

### Configurable Product Links

Link simple products to configurable parents for variant management.

**CSV Columns**:

| Column | Description |
|--------|-------------|
| `configurable_children_skus` | On configurable row: pipe/comma-separated child SKUs |
| `configurable_parent_sku` | On simple row: parent configurable SKU |
| `super_attributes` | Optional: attribute codes used for variations (e.g., `color,size`) |

**Example 1: Children on Parent Row**
```csv
sku,type_id,super_attributes,configurable_children_skus
SHIRT-CONF,configurable,color,SHIRT-RED|SHIRT-BLUE
SHIRT-RED,simple,,
SHIRT-BLUE,simple,,
```

**Example 2: Parent on Child Row**
```csv
sku,type_id,configurable_parent_sku
SHIRT-RED,simple,SHIRT-CONF
SHIRT-BLUE,simple,SHIRT-CONF
SHIRT-CONF,configurable,
```

**Example 3: Auto-Link Mode**

With `--auto-link-configurables`, simple products immediately following a configurable will auto-link if they share the same attribute set:

```csv
sku,type_id,super_attributes
SHIRT-CONF,configurable,color
SHIRT-RED,simple,
SHIRT-BLUE,simple,
PANTS-CONF,configurable,size
```

In this example, SHIRT-RED and SHIRT-BLUE auto-link to SHIRT-CONF. The chain breaks when PANTS-CONF (a new configurable) is encountered.

**Super Attribute Detection**:
1. If `super_attributes` is provided → those attributes are used
2. Otherwise → auto-detect by comparing attribute values across child products

---

### Grouped Product Links

Associate simple products with grouped parents.

**CSV Columns**:

| Column | Description |
|--------|-------------|
| `grouped_product_skus` | On grouped row: `SKU:qty\|SKU:qty` |
| `grouped_parent_sku` | On simple row: parent grouped SKU |
| `grouped_qty` | Default quantity when using `grouped_parent_sku` |

**Format**: `SKU:qty|SKU:qty` (quantity defaults to 1 if omitted)

**Example**:
```csv
sku,type_id,grouped_product_skus
BUNDLE-SET,grouped,ITEM-001:1|ITEM-002:2|ITEM-003:1
ITEM-001,simple,
ITEM-002,simple,
ITEM-003,simple,
```

**Alternative (parent on child)**:
```csv
sku,type_id,grouped_parent_sku,grouped_qty
ITEM-001,simple,BUNDLE-SET,1
ITEM-002,simple,BUNDLE-SET,2
BUNDLE-SET,grouped,,
```

---

### Custom Options

Import product custom options (dropdowns, text fields, etc.).

**CSV Column**: `custom_options`

**CLI Flag**: `--options-mode replace|merge|append`
- `replace` - Delete existing options, import new (default)
- `merge` - Match by title, update or add
- `append` - Keep existing, add new only

**Full JSON Format**:
```csv
custom_options
"[{""type"":""drop_down"",""title"":""Size"",""is_require"":1,""values"":[{""title"":""Small"",""price"":0},{""title"":""Large"",""price"":5}]}]"
```

**Simplified Shorthand Format**:
```
Title:type:required|optional:values_or_params;Title2:type:...
```

**Examples**:
```csv
# Dropdown with prices
custom_options
Size:drop_down:required:Small=0|Medium=2|Large=5

# Text field with max characters
custom_options
Engraving:field:optional:max=20

# Checkbox with price
custom_options
Gift Wrap:checkbox:optional:price=3.99

# Multiple options (semicolon-separated)
custom_options
Size:drop_down:required:Small=0|Large=5;Color:radio:required:Red=0|Blue=0
```

**Supported Option Types**:

| Type | Group | Parameters |
|------|-------|------------|
| `field` | text | `max` (max characters) |
| `area` | text | `max` (max characters) |
| `drop_down` | select | values: `Title=price\|Title=price` |
| `radio` | select | values: `Title=price\|Title=price` |
| `checkbox` | select | values: `Title=price\|Title=price` |
| `multiple` | select | values: `Title=price\|Title=price` |
| `date` | date | - |
| `date_time` | date | - |
| `time` | date | - |
| `file` | file | `ext`, `width`, `height` |

---

### Bundle Options (TODO)

**Note**: Bundle options import is not yet implemented. The column is recognized but will log a warning.

**Planned Format**:
```csv
bundle_options
"[{""title"":""Choose Racket"",""type"":""select"",""required"":1,""selections"":[{""sku"":""RACKET-001"",""qty"":1}]}]"
```

If you need bundle products, create them manually in the admin or wait for a future DataSync release.

---

## Foreign Key Resolution

DataSync automatically resolves foreign keys between entities using a registry that maps source IDs to target IDs.

### How It Works

1. **Import customers first**: Source ID 100 → Target ID 5
2. **Import orders with customer_id=100**: DataSync looks up registry
3. **Order created with customer_id=5**: Automatically resolved

### Registry Table

All mappings stored in `datasync_entity_registry`:

| Column | Description |
|--------|-------------|
| source_system | Identifier for the source (e.g., "legacy_store") |
| entity_type | Entity type (customer, order, etc.) |
| source_id | Original ID from source system |
| target_id | New ID in Maho |
| external_ref | Optional external reference (email, SKU, etc.) |

### Checking Mappings

```sql
SELECT * FROM datasync_entity_registry
WHERE source_system = 'legacy_store' AND entity_type = 'customer';
```

---

## Edge Cases & Validation

### Products with Non-Existent SKU

**Behavior**: Order items are created with `product_id = NULL`

**Why**: Historical orders may reference products that have been deleted. DataSync preserves the order history with SKU/name stored as text.

**Impact**:
- Order displays correctly in admin
- Item not linked to catalog product
- Cannot reorder or use product features

**Recommendation**: Import products before orders if you need linkage.

### Invalid Shipping Methods

**Behavior**: Shipping method string is stored as-is without validation

**Why**: Legacy systems may have different carrier codes. The order is preserved for historical accuracy.

**Impact**:
- Order displays the original shipping method text
- Cannot use Maho shipping features (tracking, etc.)
- No rates calculated

**Recommendation**: Map shipping methods in your CSV to valid Maho carriers:

| Legacy Code | Maho Code |
|-------------|-----------|
| `standard` | `flatrate_flatrate` |
| `express` | `tablerate_bestway` |
| `free` | `freeshipping_freeshipping` |

### Invalid Payment Methods

**Behavior**: Automatically falls back to default payment method (`checkmo`)

**Valid Payment Methods**:
- `checkmo` - Check/Money Order (recommended for imports)
- `banktransfer` - Bank Transfer
- `cashondelivery` - Cash on Delivery
- `free` - Free (zero-total orders)
- `purchaseorder` - Purchase Order

**Automatic Fallback**: When an invalid payment method is detected, DataSync automatically uses the default (`checkmo`) and logs a warning. The original payment method is preserved in the import data.

**Custom Default**: Use `--default-payment` to specify a different fallback:

```bash
php shell/datasync.php --entity order --adapter csv \
    --source orders.csv --system legacy --default-payment banktransfer
```

### Guest Orders

**Behavior**: Leave `customer_id` empty, order created as guest

```csv
customer_id,customer_email,customer_firstname,customer_lastname
,guest@example.com,Guest,Customer
```

### Order States

**Cannot set manually**: `complete`, `closed`

These states require workflow (invoice → ship → complete). Use `processing` for imported orders that were fulfilled:

| Imported Status | Recommended State/Status |
|-----------------|-------------------------|
| Pending | `new` / `pending` |
| Paid | `processing` / `processing` |
| Shipped | `processing` / `processing` |
| Completed | `processing` / `processing` |
| Cancelled | `canceled` / `canceled` |

---

## Troubleshooting

### "Class not found" Errors

Run composer autoload after adding DataSync files:

```bash
composer dump-autoload
```

### "Missing required fields"

Check your CSV has all required columns:
- **Customer**: email, firstname, lastname
- **Order**: increment_id, grand_total, base_grand_total
- **Invoice**: order_id, grand_total
- **Shipment**: order_id
- **Credit Memo**: order_id, grand_total

### "FK Resolution Failed"

Import dependencies first. For orders with customers:

```bash
# Step 1: Import customers
php shell/datasync.php --entity customer --adapter csv \
    --source customers.csv --system legacy

# Step 2: Import orders (now customer IDs can resolve)
php shell/datasync.php --entity order --adapter csv \
    --source orders.csv --system legacy
```

### CSV Column Count Mismatch

Ensure all rows have the same number of columns. Empty values need placeholders:

```csv
# Wrong - missing trailing comma
john@example.com,John,Doe,1

# Correct - explicit empty field
john@example.com,John,Doe,1,
```

### "Duplicate entity found"

Use `--on-duplicate` to handle:

```bash
# Skip existing records
--on-duplicate skip

# Update existing records
--on-duplicate update
```

### Image Import Issues

**Images not downloading**:
- Check URL is accessible: `curl -I https://example.com/media/path/image.jpg`
- Verify `--image-base-url` doesn't have trailing slash
- Increase timeout if server is slow: `--image-timeout 60000`

**Images not copying from local path**:
- Verify path exists and is readable
- Check file permissions on source directory
- Ensure `--image-base-path` points to the catalog/product directory

**Media gallery not importing**:
- Use JSON format for reliability: `"[""img1.jpg"",""img2.jpg""]"`
- Ensure images exist at base path/URL before import

**Images skipped (already exist)**:
- This is normal for delta imports (optimization)
- Use `--force-image-download` to re-import all images

### Checking Import Logs

```bash
tail -f var/log/datasync.log
```

### Verifying Imported Data

```sql
-- Check customers with DataSync tracking
SELECT entity_id, email, datasync_source_system, datasync_source_id
FROM customer_entity
WHERE datasync_source_system IS NOT NULL;

-- Check orders with DataSync tracking
SELECT entity_id, increment_id, datasync_source_system, datasync_source_id
FROM sales_flat_order
WHERE datasync_source_system IS NOT NULL;
```

---

## Performance Tips

1. **Use `--limit` for testing**: Test with 10-100 records first
2. **Batch large imports**: Split files into chunks of 10,000 records
3. **Disable indexing**: Run `php shell/indexer.php --reindex` after import
4. **Monitor speed**: Use `--verbose` to see records/second

Typical speeds:
- Customers: 30-50 records/sec
- Orders without items: 10-20 records/sec
- Orders with items: 5-10 records/sec

---

## Example: Full Migration

A complete migration includes customers, orders, and all sales documents (invoices, shipments, credit memos).

```bash
# 1. Validate source files (dry run)
php shell/datasync.php --entity customer --adapter csv \
    --source var/import/customers.csv --system legacy --dry-run

php shell/datasync.php --entity order --adapter csv \
    --source var/import/orders.csv --system legacy --dry-run

# 2. Import customers
php shell/datasync.php --entity customer --adapter csv \
    --source var/import/customers.csv --system legacy \
    --on-duplicate skip --verbose

# 3. Import orders
php shell/datasync.php --entity order --adapter csv \
    --source var/import/orders.csv --system legacy \
    --on-duplicate skip --verbose

# 4. Import invoices (creates payment records)
php shell/datasync.php --entity invoice --adapter csv \
    --source var/import/invoices.csv --system legacy \
    --on-duplicate skip --verbose

# 5. Import shipments (with tracking numbers)
php shell/datasync.php --entity shipment --adapter csv \
    --source var/import/shipments.csv --system legacy \
    --on-duplicate skip --verbose

# 6. Import credit memos (refund records)
php shell/datasync.php --entity creditmemo --adapter csv \
    --source var/import/creditmemos.csv --system legacy \
    --on-duplicate skip --verbose

# 7. Verify counts
mysql -e "SELECT COUNT(*) FROM customer_entity WHERE datasync_source_system='legacy'"
mysql -e "SELECT COUNT(*) FROM sales_flat_order WHERE datasync_source_system='legacy'"
mysql -e "SELECT COUNT(*) FROM sales_flat_invoice WHERE datasync_source_system='legacy'"
mysql -e "SELECT COUNT(*) FROM sales_flat_shipment WHERE datasync_source_system='legacy'"
mysql -e "SELECT COUNT(*) FROM sales_flat_creditmemo WHERE datasync_source_system='legacy'"

# 8. Reindex
php shell/indexer.php --reindex
```

**Note**: No emails are sent during import. Order confirmations, invoice emails, and shipment notifications are automatically suppressed.
