# DataSync Import Order

When migrating data between systems, **import order matters**. Entities have dependencies on other entities (foreign keys), so you must import them in the correct sequence.

## Required Import Sequence

```
┌─────────────────────────────────────────────────────────────────────┐
│                        IMPORT ORDER                                  │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  1. FOUNDATION (no dependencies)                                     │
│     ├── Attribute Sets                                               │
│     ├── Product Attributes (+ options)                               │
│     └── Customer Groups                                              │
│                                                                      │
│  2. STRUCTURE                                                        │
│     └── Categories                                                   │
│                                                                      │
│  3. CATALOG                                                          │
│     ├── Simple Products                                              │
│     ├── Virtual Products                                             │
│     ├── Downloadable Products                                        │
│     ├── Configurable Products (after simple children exist)          │
│     ├── Grouped Products (after simple children exist)               │
│     └── Bundle Products (after simple children exist)                │
│                                                                      │
│  4. CUSTOMERS                                                        │
│     └── Customers (with addresses)                                   │
│                                                                      │
│  5. SALES (requires customers & products)                            │
│     ├── Orders                                                       │
│     ├── Invoices (requires parent order)                             │
│     ├── Shipments (requires parent order)                            │
│     └── Credit Memos (requires parent order)                         │
│                                                                      │
│  6. MARKETING                                                        │
│     ├── Newsletter Subscribers                                       │
│     └── Cart Price Rules                                             │
│                                                                      │
│  7. INVENTORY                                                        │
│     └── Stock Updates                                                │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

## Detailed Dependency Map

### Products

| Product Type | Dependencies |
|--------------|--------------|
| Simple | Attribute Set, Categories, Attributes |
| Virtual | Attribute Set, Categories, Attributes |
| Downloadable | Attribute Set, Categories, Attributes |
| Configurable | Simple products (children), Configurable attributes |
| Grouped | Simple products (associated) |
| Bundle | Simple products (selections) |

### Orders

| Entity | Dependencies |
|--------|--------------|
| Order | Customer (optional - can be guest), Products (for validation) |
| Invoice | Order |
| Shipment | Order |
| Credit Memo | Order, Invoice (optional) |

### Customers

| Entity | Dependencies |
|--------|--------------|
| Customer | Customer Group |
| Customer Address | Customer |

## CLI Example: Full Migration

```bash
#!/bin/bash
# Full migration script - run entities in correct order

SOURCE="legacy_system"

echo "=== Step 1: Foundation ==="
./maho datasync:sync attribute --system $SOURCE --verbose
./maho datasync:sync attribute_set --system $SOURCE --verbose

echo "=== Step 2: Categories ==="
./maho datasync:sync category --system $SOURCE --verbose

echo "=== Step 3: Products ==="
# Simple products first
./maho datasync:sync product --system $SOURCE --filter="type_id=simple" --verbose
./maho datasync:sync product --system $SOURCE --filter="type_id=virtual" --verbose
./maho datasync:sync product --system $SOURCE --filter="type_id=downloadable" --verbose

# Complex products after their children exist
./maho datasync:sync product --system $SOURCE --filter="type_id=configurable" --verbose
./maho datasync:sync product --system $SOURCE --filter="type_id=grouped" --verbose
./maho datasync:sync product --system $SOURCE --filter="type_id=bundle" --verbose

echo "=== Step 4: Customers ==="
./maho datasync:sync customer --system $SOURCE --verbose

echo "=== Step 5: Orders ==="
./maho datasync:sync order --system $SOURCE --verbose

# Child entities after orders
./maho datasync:sync invoice --system $SOURCE --verbose
./maho datasync:sync shipment --system $SOURCE --verbose
./maho datasync:sync creditmemo --system $SOURCE --verbose

echo "=== Step 6: Marketing ==="
./maho datasync:sync newsletter --system $SOURCE --verbose

echo "=== Step 7: Stock ==="
./maho datasync:sync stock --system $SOURCE --verbose

echo "=== Migration Complete ==="
```

## Foreign Key Resolution

DataSync automatically handles foreign key resolution using a **registry**:

1. When you import a customer with source ID `100`, it creates target ID `5001`
2. The registry stores: `source_system=legacy, entity=customer, source_id=100, target_id=5001`
3. When importing an order with `customer_id=100`, DataSync looks up the registry
4. The order is created with `customer_id=5001` (the target ID)

**This is why import order matters** - the parent entity must exist in the registry before importing child entities.

## Handling Missing Dependencies

If a dependency is missing, DataSync will:

1. **Error mode** (default): Throw an error and stop
2. **Skip mode** (`--skip-invalid`): Skip the record and continue
3. **Warning mode**: Log a warning but try to continue

```bash
# Skip records with missing dependencies
./maho datasync:sync invoice --system legacy --skip-invalid

# Verbose logging to see what was skipped
./maho datasync:sync invoice --system legacy --skip-invalid -vv
```

## Incremental Sync Order

For incremental syncs, the `datasync:incremental` command automatically processes entities in the correct order:

1. Products
2. Customers
3. Orders
4. Invoices
5. Shipments
6. Credit Memos
7. Newsletter Subscribers
8. Stock

You don't need to manage order manually for incremental syncs.

## Tips

1. **Always run a dry-run first**: `--dry-run` validates without importing
2. **Use verbose mode**: `-v` or `-vv` shows what's happening
3. **Check the registry**: After import, verify mappings were created
4. **Re-run failed imports**: After fixing data issues, just re-run - existing records will be skipped or updated
