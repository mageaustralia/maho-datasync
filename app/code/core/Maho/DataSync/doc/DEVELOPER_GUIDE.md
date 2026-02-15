# Maho DataSync Developer Guide

Technical documentation for extending and customizing the DataSync module.

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                      Shell Command                          │
│                   shell/datasync.php                        │
└─────────────────────┬───────────────────────────────────────┘
                      │
┌─────────────────────▼───────────────────────────────────────┐
│                       Engine                                │
│              Maho_DataSync_Model_Engine                     │
│  - Orchestrates sync operations                             │
│  - Handles duplicate detection                              │
│  - Manages FK resolution                                    │
│  - Progress reporting                                       │
└──────┬──────────────────────────────┬───────────────────────┘
       │                              │
┌──────▼──────────┐          ┌────────▼────────┐
│    Adapters     │          │ Entity Handlers │
│  (Data Source)  │          │ (Import Logic)  │
├─────────────────┤          ├─────────────────┤
│ CSV             │          │ Customer        │
│ OpenMage        │          │ Order           │
│ WooCommerce     │          │ Product         │
│ Magento2        │          │ Category        │
│ Shopify         │          │ Review          │
└─────────────────┘          └─────────────────┘
       │                              │
       └──────────────┬───────────────┘
                      │
┌─────────────────────▼───────────────────────────────────────┐
│                      Registry                               │
│           Maho_DataSync_Model_Registry                      │
│  - Maps source IDs to target IDs                            │
│  - Persists to datasync_entity_registry table               │
└─────────────────────────────────────────────────────────────┘
```

## Creating a Custom Entity Handler

### 1. Create the Handler Class

```php
<?php
// app/code/local/MyModule/DataSync/Model/Entity/MyEntity.php

class MyModule_DataSync_Model_Entity_MyEntity extends Maho_DataSync_Model_Entity_Abstract
{
    // Required fields for validation
    protected array $_requiredFields = ['name', 'code'];

    // Foreign key mappings (source field => entity type)
    protected array $_foreignKeyFields = [
        'category_id' => [
            'entity_type' => 'category',
            'required' => false, // Optional FK
        ],
    ];

    // Field used for external reference (for lookup)
    protected ?string $_externalRefField = 'code';

    public function getEntityType(): string
    {
        return 'my_entity';
    }

    public function getLabel(): string
    {
        return 'My Custom Entities';
    }

    /**
     * Find existing entity by unique identifier
     * Return entity_id if found, null if not
     */
    public function findExisting(array $data): ?int
    {
        if (empty($data['code'])) {
            return null;
        }

        $model = Mage::getModel('mymodule/entity')
            ->load($data['code'], 'code');

        return $model->getId() ? (int) $model->getId() : null;
    }

    /**
     * Import a single entity
     * Return the new/updated entity_id
     */
    public function import(array $data, Maho_DataSync_Model_Registry $registry): int
    {
        $existingId = $data['_existing_id'] ?? null;
        $action = $data['_action'] ?? 'create';

        $model = Mage::getModel('mymodule/entity');

        if ($existingId) {
            $model->load($existingId);
        }

        // Map fields
        $model->setName($this->_cleanString($data['name']));
        $model->setCode($this->_cleanString($data['code']));

        // Handle optional FK (already resolved by engine)
        if (!empty($data['category_id'])) {
            $model->setCategoryId($data['category_id']);
        }

        // Set DataSync tracking
        $model->setData('datasync_source_system', $data['_source_system'] ?? 'import');
        $model->setData('datasync_source_id', $data['entity_id'] ?? null);
        $model->setData('datasync_imported_at', Mage_Core_Model_Locale::now());

        $model->save();

        // Set external reference for registry
        $data['_external_ref'] = $data['code'];

        return (int) $model->getId();
    }

    /**
     * Custom validation (optional)
     */
    public function validate(array $data): array
    {
        $errors = parent::validate($data);

        // Add custom validation
        if (isset($data['code']) && strlen($data['code']) > 50) {
            $errors[] = "Code too long: max 50 characters";
        }

        return $errors;
    }
}
```

### 2. Register in config.xml

```xml
<config>
    <global>
        <datasync>
            <entities>
                <my_entity>
                    <class>mymodule_datasync/entity_myEntity</class>
                    <label>My Custom Entities</label>
                    <order>25</order>
                    <depends>category</depends>
                </my_entity>
            </entities>
        </datasync>
    </global>
</config>
```

### 3. Usage

```bash
php shell/datasync.php --entity my_entity --adapter csv \
    --source var/import/my_entities.csv --system legacy
```

---

## Creating a Custom Adapter

### 1. Create the Adapter Class

```php
<?php
// app/code/local/MyModule/DataSync/Model/Adapter/MyApi.php

class MyModule_DataSync_Model_Adapter_MyApi extends Maho_DataSync_Model_Adapter_Abstract
{
    protected string $_apiUrl = '';
    protected string $_apiKey = '';

    public function getCode(): string
    {
        return 'myapi';
    }

    public function getLabel(): string
    {
        return 'My External API';
    }

    /**
     * Configure from command line options
     */
    public function configure(array $config): void
    {
        parent::configure($config);

        $this->_apiUrl = $config['api_url'] ?? $config['source'] ?? '';
        $this->_apiKey = $config['api_key'] ?? '';
    }

    /**
     * Validate connection
     */
    public function validate(): bool
    {
        $this->_ensureConfigured();

        if (empty($this->_apiUrl)) {
            throw new Maho_DataSync_Exception('API URL not configured');
        }

        // Test connection
        $response = $this->_apiCall('ping');
        return $response['status'] === 'ok';
    }

    /**
     * Read entities from source
     * Must return an iterable (generator recommended for memory efficiency)
     */
    public function read(string $entityType, array $filters = []): iterable
    {
        $this->_ensureConfigured();

        $page = 1;
        $perPage = 100;

        do {
            $response = $this->_apiCall("{$entityType}/list", [
                'page' => $page,
                'per_page' => $perPage,
                'updated_after' => $filters['date_from'] ?? null,
            ]);

            foreach ($response['items'] as $item) {
                yield $item;
            }

            $page++;
        } while (count($response['items']) === $perPage);
    }

    /**
     * Count available records (optional, for progress display)
     */
    public function count(string $entityType, array $filters = []): ?int
    {
        $response = $this->_apiCall("{$entityType}/count", $filters);
        return $response['count'] ?? null;
    }

    protected function _apiCall(string $endpoint, array $params = []): array
    {
        $url = rtrim($this->_apiUrl, '/') . '/' . $endpoint;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url . '?' . http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->_apiKey,
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?? [];
    }
}
```

### 2. Register in config.xml

```xml
<config>
    <global>
        <datasync>
            <adapters>
                <myapi>
                    <class>mymodule_datasync/adapter_myApi</class>
                    <label>My External API</label>
                    <description>Import from My External System</description>
                </myapi>
            </adapters>
        </datasync>
    </global>
</config>
```

### 3. Usage

```bash
php shell/datasync.php --entity customer --adapter myapi \
    --source "https://api.example.com" --api-key "secret" --system legacy
```

---

## Data Flow & Lifecycle

### Import Flow

```
1. Shell parses arguments
2. Engine initializes adapter + handler
3. Adapter validates connection
4. For each record from adapter->read():
   a. Engine resolves foreign keys via registry
   b. Engine validates required fields
   c. Engine checks for duplicates via handler->findExisting()
   d. Based on --on-duplicate mode:
      - skip: continue to next record
      - error: throw exception
      - update/merge: set _existing_id in data
   e. Handler->import() creates/updates entity
   f. Engine registers mapping in registry
   g. Result recorded (success/error/skip)
5. Delta state updated
6. Summary displayed
```

### Registry Lookup Flow

```
1. Order has customer_id=100 (source system)
2. Engine checks handler->getForeignKeyFields()
3. For 'customer_id' field:
   a. Look up in registry: source_system + 'customer' + source_id=100
   b. Get target_id from registry (e.g., 5)
   c. Replace data['customer_id'] = 5
   d. Store original: data['_original_customer_id'] = 100
4. Handler receives resolved FK
```

---

## Database Schema

### datasync_entity_registry

Maps source IDs to target IDs.

```sql
CREATE TABLE datasync_entity_registry (
    registry_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_system VARCHAR(64) NOT NULL,      -- e.g., 'legacy_store'
    entity_type VARCHAR(64) NOT NULL,        -- e.g., 'customer'
    source_id INT UNSIGNED NOT NULL,         -- Original ID
    target_id INT UNSIGNED NOT NULL,         -- New ID in Maho
    external_ref VARCHAR(255),               -- e.g., email, SKU
    synced_at DATETIME NOT NULL,
    metadata TEXT,                           -- JSON additional data
    UNIQUE KEY (source_system, entity_type, source_id)
);
```

### datasync_delta_state

Tracks sync progress for incremental imports.

```sql
CREATE TABLE datasync_delta_state (
    delta_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_system VARCHAR(64) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    adapter_code VARCHAR(64) NOT NULL,
    last_sync_at DATETIME,
    last_entity_id INT UNSIGNED,
    last_updated_at DATETIME,
    filters TEXT,                            -- JSON filters used
    UNIQUE KEY (source_system, entity_type, adapter_code)
);
```

---

## Edge Case Handling Reference

### Order Items - Non-Existent SKU

```php
// In Order handler _importItems():
$item->setProductId($itemData['product_id'] ?? null);  // NULL if not found
$item->setSku($this->_cleanString($itemData['sku']));  // Stored as text
$item->setName($itemData['name'] ?? $itemData['sku']); // Fallback to SKU
```

**Result**: Order item created without catalog link. SKU preserved for reference.

### Order - Invalid Shipping Method

```php
// In Order handler:
$order->setShippingMethod($data['shipping_method']);        // Stored as-is
$order->setShippingDescription($data['shipping_description']); // Display text
```

**Result**: String stored without validation. No carrier functionality.

### Order - Invalid Payment Method

```php
// In Order handler:
$payment = Mage::getModel('sales/order_payment');
$payment->setMethod($paymentMethod);  // May fail validation
```

**Recommendation**: Use `checkmo` (Check/Money Order) for imports - always available.

### Customer - Duplicate Email

```php
// In Customer handler findExisting():
$customer->setWebsiteId($websiteId);
$customer->loadByEmail($data['email']);
return $customer->getId() ? (int) $customer->getId() : null;
```

**Result**: Duplicate detected per website. Handle via `--on-duplicate`.

---

## Extending DataSync

### Add Custom Tracking Attributes

```sql
-- Add to customer_entity
ALTER TABLE customer_entity
ADD COLUMN datasync_source_system VARCHAR(64),
ADD COLUMN datasync_source_id INT UNSIGNED,
ADD COLUMN datasync_imported_at DATETIME;

-- Add to sales_flat_order
ALTER TABLE sales_flat_order
ADD COLUMN datasync_source_system VARCHAR(64),
ADD COLUMN datasync_source_id INT UNSIGNED,
ADD COLUMN datasync_source_increment_id VARCHAR(50),
ADD COLUMN datasync_imported_at DATETIME;
```

### Custom Progress Callback

```php
$engine = Mage::getModel('datasync/engine');
$engine->setProgressCallback(function($message) {
    echo "[" . date('H:i:s') . "] $message\n";
});
```

### Programmatic Usage

```php
// Import from code
$engine = Mage::getModel('datasync/engine');

$adapter = Mage::getModel('datasync/adapter_csv');
$adapter->configure(['file_path' => '/path/to/file.csv']);

$engine->setSourceAdapter($adapter)
    ->setSourceSystem('my_import')
    ->setOnDuplicate('skip')
    ->setVerbose(true);

$result = $engine->sync('customer');

echo "Created: " . $result->getCreatedCount() . "\n";
echo "Errors: " . $result->getErrorCount() . "\n";
```

---

## Testing

### Unit Test Example

```php
class Maho_DataSync_Test_Entity_CustomerTest extends PHPUnit\Framework\TestCase
{
    public function testFindExistingByEmail()
    {
        $handler = Mage::getModel('datasync/entity_customer');

        // Create test customer
        $customer = Mage::getModel('customer/customer')
            ->setWebsiteId(1)
            ->setEmail('test@example.com')
            ->setFirstname('Test')
            ->setLastname('User')
            ->save();

        // Test findExisting
        $found = $handler->findExisting([
            'email' => 'test@example.com',
            'website_id' => 1,
        ]);

        $this->assertEquals($customer->getId(), $found);

        // Cleanup
        $customer->delete();
    }
}
```

---

## Best Practices

1. **Always use `--dry-run` first** - Validate data before importing
2. **Import in dependency order** - Customers before orders
3. **Use unique source system IDs** - e.g., `legacy_2024`, `pos_system`
4. **Batch large imports** - Split into 10K record chunks
5. **Monitor logs** - `tail -f var/log/datasync.log`
6. **Reindex after import** - `php shell/indexer.php --reindex`
7. **Use `checkmo` for payment** - Most reliable for imports
8. **Map shipping methods** - Convert to valid Maho carriers
