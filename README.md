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

### Change Tracking (for Incremental Sync)

Run the setup script on your **source** database to create the change tracker table and triggers:

```sql
-- See sql/datasync_setup/install-1.0.0.php for full schema
CREATE TABLE datasync_change_tracker (
    tracker_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    action ENUM('create', 'update', 'delete') NOT NULL,
    created_at DATETIME NOT NULL,
    sync_completed TINYINT(1) DEFAULT 0,
    synced_at DATETIME DEFAULT NULL,
    INDEX idx_pending (sync_completed, entity_type),
    INDEX idx_entity (entity_type, entity_id)
);
```

## CLI Commands

### Full Sync

Sync all entities of a specific type:

```bash
# Sync all orders
./maho datasync:sync order

# Sync with filters
./maho datasync:sync order --from-date="2024-01-01" --to-date="2024-12-31"

# Dry run (preview only)
./maho datasync:sync order --dry-run

# Handle duplicates
./maho datasync:sync order --on-duplicate=merge  # merge|skip|update|error
```

### Incremental Sync

Sync only changed records (requires change tracking):

```bash
# Sync all pending changes
./maho datasync:incremental

# Sync specific entity types
./maho datasync:incremental --entity=order

# Mark records as synced after successful import
./maho datasync:incremental --mark-synced

# Verbose output
./maho datasync:incremental -vv
```

## Supported Entities

| Entity | Type Key | Notes |
|--------|----------|-------|
| Orders | `order` | Full order data including addresses, items, payment |
| Invoices | `invoice` | Requires parent order to be synced first |
| Shipments | `shipment` | Requires parent order to be synced first |
| Credit Memos | `creditmemo` | Requires parent order to be synced first |
| Customers | `customer` | Including addresses |
| Products | `product` | Simple, configurable, bundle, downloadable, virtual |
| Categories | `category` | With full tree structure |
| Newsletter | `newsletter` | Subscriber data |
| Stock | `stock` | Inventory levels |

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

## Roadmap

- [ ] Magento 2 source adapter
- [ ] Shopify source adapter
- [ ] WooCommerce source adapter
- [ ] Real-time webhook-based sync
- [ ] Admin UI for sync management
- [ ] Sync scheduling via admin

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

MIT License - see [LICENSE](LICENSE) for details.

## Credits

Developed by [MageAustralia](https://github.com/mageaustralia) for the Maho Commerce ecosystem.
