# DataSync CSV Examples

Example CSV files for importing data using Maho DataSync.

## Customer Import

### Basic Customers (`customers_basic.csv`)
Minimal customer import with required fields only.

| Column | Required | Description |
|--------|----------|-------------|
| entity_id | No | Source system ID (for tracking) |
| email | Yes | Customer email (unique per website) |
| firstname | Yes | First name |
| lastname | Yes | Last name |
| gender | No | 0=Not specified, 1=Male, 2=Female |
| dob | No | Date of birth (YYYY-MM-DD) |
| group_id | No | Customer group ID |

### Customers with Addresses (`customers_with_addresses.csv`)
Full customer import including billing and shipping addresses.

**Additional address columns use prefixes:**
- `billing_*` - Billing address fields
- `shipping_*` - Shipping address fields

| Address Column | Description |
|----------------|-------------|
| {prefix}_firstname | First name (defaults to customer firstname) |
| {prefix}_lastname | Last name (defaults to customer lastname) |
| {prefix}_company | Company name |
| {prefix}_street | Street address (use quotes for multi-line) |
| {prefix}_city | City |
| {prefix}_region | State/Province name |
| {prefix}_postcode | Postal/ZIP code |
| {prefix}_country_id | 2-letter country code (AU, US, GB, etc.) |
| {prefix}_telephone | Phone number |
| {prefix}_fax | Fax number |

**Special columns:**
- `same_as_billing` - Set to 1 to use billing address as shipping
- `store_id` - Target store view ID
- `website_id` - Target website ID

## Usage Examples

```bash
# Basic import
php shell/datasync.php --entity customer --adapter csv \
    --source var/import/customers_basic.csv --system legacy_db

# Import with addresses, skip duplicates
php shell/datasync.php --entity customer --adapter csv \
    --source var/import/customers_with_addresses.csv \
    --system migration --on-duplicate skip --verbose

# Update existing customers
php shell/datasync.php --entity customer --adapter csv \
    --source var/import/customer_updates.csv \
    --system crm --on-duplicate update

# Dry run (validate without importing)
php shell/datasync.php --entity customer --adapter csv \
    --source var/import/customers.csv --system test --dry-run
```

## Multi-line Street Addresses

For addresses with multiple lines, wrap the value in quotes:

```csv
billing_street
"123 Main St
Apartment 4B
Building C"
```

## Gender Values

| Value | Meaning |
|-------|---------|
| 0 | Not Specified |
| 1 | Male |
| 2 | Female |

---

## Order Import

### Basic Orders (`orders_basic.csv`)
Simple order import without line items.

| Column | Required | Description |
|--------|----------|-------------|
| entity_id | No | Source system ID (for tracking) |
| increment_id | Yes | Order number (e.g., ORD-001, 100000001) |
| customer_id | No | Customer ID (leave empty for guest orders) |
| customer_email | Yes | Customer email |
| customer_firstname | Yes | Customer first name |
| customer_lastname | Yes | Customer last name |
| status | No | Order status (pending, processing, complete, etc.) |
| state | No | Order state (new, processing, complete, etc.) |
| grand_total | Yes | Order grand total |
| base_grand_total | Yes | Base currency grand total |
| subtotal | No | Subtotal before tax/shipping |
| tax_amount | No | Tax amount |
| shipping_amount | No | Shipping cost |
| shipping_method | No | Shipping method code (e.g., flatrate_flatrate) |
| payment_method | No | Payment method code (default: checkmo) |
| created_at | No | Order date (YYYY-MM-DD HH:MM:SS) |

### Orders with Items (`orders_with_items.csv`)
Full order import including line items.

**Address columns use prefixes:**
- `billing_*` - Billing address fields
- `shipping_*` - Shipping address fields

**Special columns:**
- `same_as_billing` - Set to 1 if shipping = billing
- `customer_id` - Source system customer ID (resolved via registry)
- `items` - Order line items (see formats below)

### Order Items Formats

**1. JSON Array (recommended for complex items):**
```csv
items
"[{""sku"":""ABC123"",""name"":""Product Name"",""qty_ordered"":2,""price"":29.95,""tax_amount"":3.00}]"
```

**2. Pipe-delimited (simple format):**
```csv
items
SKU1:qty:price|SKU2:qty:price
```
Example: `WIDGET-A:2:29.95|WIDGET-B:1:49.00`

### Order States vs Status

| State | Valid Statuses | Can Set Manually? |
|-------|----------------|-------------------|
| new | pending | Yes |
| pending_payment | pending_payment | Yes |
| processing | processing | Yes |
| complete | complete | No (workflow only) |
| closed | closed | No (workflow only) |
| canceled | canceled | Yes |
| holded | holded | Yes |

**Note:** The `complete` state cannot be set manually - orders must go through invoice/shipment workflow.

### Foreign Key Resolution

The `customer_id` column refers to the **source system** customer ID. DataSync automatically resolves it to the target Maho customer ID using the registry.

Example flow:
1. Import customers: source ID 100 → target ID 5
2. Import order with customer_id=100
3. DataSync resolves 100 → 5 automatically

Guest orders: Leave `customer_id` empty, the order will be created as a guest order.

## Order Usage Examples

```bash
# Import orders (requires customers imported first for FK resolution)
php shell/datasync.php --entity order --adapter csv \
    --source var/import/orders.csv --system legacy_db --verbose

# Import guest orders only
php shell/datasync.php --entity order --adapter csv \
    --source var/import/guest_orders.csv --system pos

# Dry run to validate
php shell/datasync.php --entity order --adapter csv \
    --source var/import/orders.csv --system test --dry-run
```
