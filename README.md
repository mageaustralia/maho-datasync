# Maho DataSync

Data synchronization module for [Maho](https://github.com/mahocommerce/maho) and OpenMage. Enables seamless data migration and continuous synchronization between OpenMage/Maho instances.

## Features

- **Full Entity Support**: Orders, invoices, shipments, credit memos, customers, products, categories, newsletter subscribers, and more
- **Incremental Sync**: Track changes via database triggers and sync only what's changed
- **Batch Processing**: Efficient bulk operations with configurable batch sizes
- **Foreign Key Resolution**: Automatic mapping of entity relationships between source and destination
- **Validation**: Pre-import validation with configurable skip/error modes for invalid records
- **Dry Run Mode**: Preview changes before committing
- **CLI Commands**: Full command-line interface for automation and cron scheduling
- **Extensible Architecture**: Plugin-based entity handlers for custom entity types

## Use Cases

- **Environment Synchronization**: Keep development/staging environments in sync with production
- **Multi-Store Data Sharing**: Share customer or product data across multiple stores
- **Backup & Disaster Recovery**: Replicate critical data to secondary instances
- **Data Migration**: Migrate data between OpenMage instances during upgrades or consolidations

## Import Order (Important!)

Entities must be imported in the correct order due to foreign key dependencies:

```
1. Foundation    → Attributes, Attribute Sets, Customer Groups
2. Structure     → Categories
3. Catalog       → Simple Products → Configurable/Grouped/Bundle Products
4. Customers     → Customers (with addresses)
5. Sales         → Orders → Invoices → Shipments → Credit Memos
6. Marketing     → Newsletter Subscribers
7. Inventory     → Stock Updates
```

See [doc/IMPORT_ORDER.md](app/code/core/Maho/DataSync/doc/IMPORT_ORDER.md) for detailed dependency documentation.

## Requirements

- PHP 8.2+
- Maho 24.10+ or OpenMage with Maho compatibility layer
- MySQL 8.0+ / MariaDB 10.6+

## Installation

```bash
composer require mageaustralia/maho-datasync
```

## Configuration

### Source Database Connection

Configure the source database connection in your `.env.local`:

```env
DATASYNC_LIVE_HOST=your-source-db-host
DATASYNC_LIVE_USER=datasync_readonly
DATASYNC_LIVE_PASS=your-password
DATASYNC_LIVE_DB=your_source_database
```

#### Security Warnings

1. **Block `.env*` files in your web server config:**

   **Apache (.htaccess or vhost):**
   ```apache
   <FilesMatch "^\.env">
       Require all denied
   </FilesMatch>
   ```

   **Nginx:**
   ```nginx
   location ~ /\.env {
       deny all;
       return 404;
   }
   ```

2. **Add `.env.local` to `.gitignore`** - never commit credentials to version control

3. **Restrict file permissions:**
   ```bash
   chmod 600 .env.local
   chown www-data:www-data .env.local
   ```

4. **Use a read-only database user** for the source connection - DataSync only needs SELECT access (plus UPDATE/DELETE on `datasync_change_tracker` to mark records as synced)

5. **Don't pass credentials via command line** - they appear in process lists (`ps aux`). Use `.env.local` instead

### Source Module (for Incremental Sync)

To use incremental sync, install the **DataSync Tracker** module on your **source** OpenMage/Magento 1 system:

```bash
# Copy from source-module/ directory in this repo
cp -r source-module/app/etc/modules/Maho_DataSyncTracker.xml /path/to/source-magento/app/etc/modules/
cp -r source-module/app/code/community/Maho /path/to/source-magento/app/code/community/

# Clear cache
rm -rf /path/to/source-magento/var/cache/*
```

This module:
- Creates the `datasync_change_tracker` table automatically
- Uses Magento events to track entity changes (customers, orders, products, etc.)
- Handles rapid successive updates with upsert pattern
- Cleans up old synced records via weekly cron

See [source-module/README.md](source-module/README.md) for full documentation.

### Database User Permissions

The DataSync user needs read access to sync data and write access to mark records as synced:

```sql
-- Read access for syncing
GRANT SELECT ON source_database.* TO 'datasync_user'@'%';

-- Write access to mark tracker records as synced
GRANT UPDATE, DELETE ON source_database.datasync_change_tracker TO 'datasync_user'@'%';
```

## CLI Commands

### Full Sync (`datasync:sync`)

Sync all entities of a specific type from a source system.

```bash
./maho datasync:sync <entity> <source> [options]
```

**Required Arguments:**
| Argument | Description |
|----------|-------------|
| `entity` | Entity type: `product`, `customer`, `category`, `order`, `invoice`, `shipment`, `creditmemo`, `productattribute` |
| `source` | Source system identifier (e.g., "legacy", "woocommerce", "csv") |

**Source Options:**
| Option | Short | Description |
|--------|-------|-------------|
| `--adapter` | `-a` | Source adapter type: `csv`, `openmage`, `woocommerce`, `shopify`, `magento2` (default: csv) |
| `--file` | `-f` | CSV file path (required for csv adapter) |
| `--url` | `-u` | Remote API URL (for API-based adapters) |
| `--db-host` | | Database host for direct DB adapter (default: localhost) |
| `--db-name` | | Database name for direct DB adapter |
| `--db-user` | | Database username |
| `--db-pass` | | Database password |
| `--db-prefix` | | Table prefix (default: none) |
| `--api-key` | `-k` | API key for authentication |
| `--api-secret` | | API secret for authentication |

**Behavior Options:**
| Option | Short | Description |
|--------|-------|-------------|
| `--on-duplicate` | `-d` | How to handle duplicates: `skip`, `update`, `merge`, `error` (default: error) |
| `--dry-run` | | Validate without actually importing |
| `--skip-invalid` | | Skip invalid records instead of failing |

**Filter Options:**
| Option | Short | Description |
|--------|-------|-------------|
| `--limit` | `-l` | Limit number of records to import |
| `--date-from` | | Import only records modified after date (YYYY-MM-DD) |
| `--date-to` | | Import only records modified before date (YYYY-MM-DD) |
| `--entity-ids` | | Sync specific entity IDs (comma-separated: "123,456,789") |
| `--increment-ids` | | Sync specific increment IDs (comma-separated: "100001,100002") |

**Product-Specific Options:**
| Option | Description |
|--------|-------------|
| `--auto-link-configurables` | Auto-link simple products to configurables based on CSV position |
| `--options-mode` | Custom options handling: `replace`, `merge`, `append` (default: replace) |
| `--image-base-url` | Base URL for downloading images |
| `--image-base-path` | Base path for copying local images |
| `--stock` | Stock sync mode: `include` (default), `exclude`, `only` |

**Examples:**
```bash
# Import orders from CSV
./maho datasync:sync order csv_import -a csv -f /path/to/orders.csv

# Sync products from another OpenMage database
./maho datasync:sync product legacy -a openmage --db-host=192.168.1.100 \
    --db-name=legacy_db --db-user=readonly --db-pass=secret

# Import customers, update existing ones
./maho datasync:sync customer migration -a csv -f customers.csv --on-duplicate=merge

# Dry run with date filter
./maho datasync:sync order legacy -a openmage --date-from="2024-01-01" \
    --date-to="2024-06-30" --dry-run -v

# Import products, skip stock updates
./maho datasync:sync product csv_import -a csv -f products.csv --stock=exclude
```

### Incremental Sync (`datasync:incremental`)

Sync only changed records using the tracker table (requires change tracking setup on source).

```bash
./maho datasync:incremental [options]
```

**Options:**
| Option | Short | Description |
|--------|-------|-------------|
| `--entity` | `-e` | Sync only specific entity type (order, invoice, shipment, etc.) |
| `--mark-synced` | | Mark tracker records as synced after successful import |
| `--limit` | `-l` | Limit number of records to process per entity type |
| `--dry-run` | | Show what would be synced without making changes |
| `--stock` | | Stock sync mode: `include` (default), `exclude`, `only` |
| `--no-lock` | | Skip lock file check (not recommended) |
| `--db-host` | | Live database host (or set DATASYNC_LIVE_HOST in .env.local) |
| `--db-name` | | Live database name (or set DATASYNC_LIVE_DB in .env.local) |
| `--db-user` | | Live database user (or set DATASYNC_LIVE_USER in .env.local) |
| `--db-pass` | | Live database password (or set DATASYNC_LIVE_PASS in .env.local) |

**Verbosity:**
| Flag | Description |
|------|-------------|
| `-v` | Verbose output (show progress) |
| `-vv` | Very verbose (show individual entity sync details) |

**Entity Processing Order:**

Incremental sync automatically processes entities in dependency order:
1. customer
2. order
3. invoice
4. shipment
5. creditmemo
6. newsletter
7. product
8. stock
9. category

**Examples:**
```bash
# Sync all pending changes
./maho datasync:incremental --mark-synced

# Sync only orders (with verbose output)
./maho datasync:incremental --entity=order --mark-synced -vv

# Preview what would be synced
./maho datasync:incremental --dry-run

# Sync everything except stock
./maho datasync:incremental --stock=exclude --mark-synced

# Sync only stock updates
./maho datasync:incremental --stock=only --mark-synced

# Limit to 100 records per entity type
./maho datasync:incremental --limit=100 --mark-synced
```

### Cron Integration

Add to crontab for continuous synchronization:

```bash
# Run incremental sync every 5 minutes
*/5 * * * * cd /var/www/html && ./maho datasync:incremental --mark-synced >> var/log/datasync.log 2>&1
```

The `--no-lock` option exists but is not recommended. The default flock-based locking prevents concurrent runs.

## Supported Entities

| Entity | Type Key | Incremental | Notes |
|--------|----------|-------------|-------|
| Orders | `order` | ✅ | Full order data including addresses, items, payment |
| Invoices | `invoice` | ✅ | Requires parent order to be synced first |
| Shipments | `shipment` | ✅ | Requires parent order to be synced first |
| Credit Memos | `creditmemo` | ✅ | Requires parent order to be synced first |
| Customers | `customer` | ✅ | Including addresses |
| Products | `product` | ✅ | Simple, configurable, bundle, downloadable, virtual |
| Categories | `category` | ✅ | With full tree structure |
| Newsletter | `newsletter` | ✅ | Subscriber data |
| Stock | `stock` | ✅ | Inventory levels |

### Not Yet Supported (Roadmap)

| Entity | Status | Notes |
|--------|--------|-------|
| Attributes | Planned | Product attributes and options |
| Attribute Sets | Planned | Attribute set definitions |
| Customer Groups | Planned | Group definitions |
| CMS Pages | Planned | Content pages |
| CMS Blocks | Planned | Static blocks |
| Reviews & Ratings | Planned | Product reviews |
| Cart Price Rules | Planned | Promotions and coupons |
| URL Rewrites | Not planned | Can be massive; regenerate on destination instead |

**Note:** Incremental sync (`datasync:incremental`) only syncs entities tracked by the source module. For initial migration or foundational data (attributes, customer groups), use `datasync:sync` with the appropriate entity type.

## Architecture

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│  Source DB      │────▶│  DataSync       │────▶│  Destination DB │
│  (Live/Prod)    │     │  Engine         │     │  (Dev/Staging)  │
└─────────────────┘     └─────────────────┘     └─────────────────┘
                               │
                    ┌──────────┼──────────┐
                    ▼          ▼          ▼
              ┌─────────┐┌─────────┐┌─────────┐
              │ Adapter ││ Entity  ││Registry │
              │         ││Handlers ││         │
              └─────────┘└─────────┘└─────────┘
```

- **Adapter**: Connects to source system and reads entity data
- **Entity Handlers**: Transform and import specific entity types
- **Registry**: Tracks source-to-destination ID mappings for foreign key resolution

## Examples

The `doc/examples/` directory contains comprehensive examples:

### CSV Examples
- `customers_basic.csv` - Minimal customer import
- `customers_with_addresses.csv` - Full customer with billing/shipping
- `orders_basic.csv` - Simple order import
- `orders_with_items.csv` - Orders with line items
- `products_simple.csv` - Simple product import
- `categories.csv` - Category structure
- `newsletter_subscribers.csv` - Newsletter subscriber import

### PHP Array Examples
- `php_array_examples.php` - Customers, orders, products, invoices, shipments
- `products_advanced.php` - Configurable, grouped, bundle, downloadable products with images
- `attributes.php` - Product attributes and attribute sets

### Documentation
- `IMPORT_ORDER.md` - Required import sequence and dependency map
- `examples/README.md` - Detailed field documentation

## Performance

Sync speed varies by entity type, network latency, and server resources. Approximate throughput with direct database connection:

| Entity | Throughput | Notes |
|--------|------------|-------|
| Stock | 500-1000/sec | Direct SQL updates, very efficient |
| Customers | 50-100/sec | Includes address processing |
| Products (Simple) | 30-80/sec | Depends on attribute count |
| Products (Configurable) | 10-30/sec | Includes child product linking |
| Newsletter | 100-200/sec | Simple table structure |
| Orders | 10-50/sec | Complex: addresses, items, payment, status history |
| Invoices | 20-60/sec | Requires parent order lookup |
| Shipments | 20-60/sec | Includes tracking info |
| Credit Memos | 20-50/sec | Includes item adjustments |

**Optimization tips:**

- **Use `--stock=exclude`** during initial product sync, then sync stock separately
- **Batch size**: The incremental command processes entities in batches to balance memory and speed
- **Network**: Direct database connection is fastest; PHP proxy adds ~20-40% overhead
- **Indexes**: Ensure source database has proper indexes on `updated_at` columns
- **Disable indexers**: On destination, disable Maho indexers during large imports

**Incremental vs Full Sync:**

Incremental sync (`datasync:incremental`) is significantly faster for ongoing synchronization because it only processes changed records. For initial migration, use `datasync:sync` with appropriate filters.

## Roadmap

### Entity Support
- [ ] Product Attributes (+ attribute options)
- [ ] Attribute Sets (+ attribute groups)
- [ ] Customer Groups
- [ ] CMS Pages
- [ ] CMS Blocks
- [ ] Reviews & Ratings
- [ ] Cart Price Rules (+ coupons)

### Adapters
- [ ] **PHP Proxy adapter** - HTTP API on source system to avoid direct DB connection (firewall-friendly)
- [ ] Magento 2 source adapter
- [ ] Shopify source adapter
- [ ] WooCommerce source adapter

### Features
- [ ] Real-time webhook-based sync
- [ ] Admin UI for sync management
- [ ] Sync scheduling via admin

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

MIT License - see [LICENSE](LICENSE) for details.

## Credits

Developed by [MageAustralia](https://github.com/mageaustralia) for the Maho Commerce ecosystem.
