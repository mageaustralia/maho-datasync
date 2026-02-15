# Maho DataSync Tracker - Source Module

This module is installed on your **SOURCE** OpenMage/Magento 1 system to track entity changes for incremental sync.

## What It Does

- Creates the `datasync_change_tracker` table
- Uses Magento event observers to track entity changes
- Logs create/update/delete actions for all syncable entities
- Provides upsert pattern to handle rapid successive changes
- Includes weekly cron cleanup of old synced records

## Tracked Entities

| Entity | Events |
|--------|--------|
| Customer | Save, Delete, Address changes |
| Order | Save |
| Invoice | Save |
| Shipment | Save |
| Credit Memo | Save |
| Order Comments | Save |
| Newsletter Subscriber | Save |
| Product | Save, Delete |
| Category | Save, Delete |
| Stock | Save |

## Installation

### Manual Installation

Copy the module files to your OpenMage/Magento 1 installation:

```bash
# Copy module declaration
cp app/etc/modules/Maho_DataSyncTracker.xml /path/to/magento/app/etc/modules/

# Copy module code
cp -r app/code/community/Maho /path/to/magento/app/code/community/

# Clear cache
rm -rf /path/to/magento/var/cache/*
```

### Via Modman

```bash
modman clone https://github.com/mageaustralia/maho-datasync
modman deploy maho-datasync
```

After installation, the module will automatically:
1. Create the `datasync_change_tracker` table on first page load
2. Start tracking changes to all supported entities

## Database Table

The module creates:

```sql
CREATE TABLE datasync_change_tracker (
    tracker_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    action VARCHAR(10) NOT NULL DEFAULT 'update',
    created_at DATETIME NOT NULL,
    synced_at DATETIME DEFAULT NULL,
    sync_completed SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    UNIQUE INDEX (entity_type, entity_id, sync_completed),
    INDEX (sync_completed, entity_type),
    INDEX (entity_type, entity_id),
    INDEX (created_at)
);
```

## How It Works

1. When an entity is saved/deleted, an observer fires
2. The observer inserts a record into `datasync_change_tracker`
3. Uses `ON DUPLICATE KEY UPDATE` to avoid duplicates for pending syncs
4. The `sync_completed=0` records are pending sync
5. After sync, DataSync marks records with `sync_completed=1`
6. Weekly cron cleans up synced records older than 30 days

## Database User Permissions

The DataSync destination needs these permissions on the source database:

```sql
-- Read access for syncing
GRANT SELECT ON your_database.* TO 'datasync_user'@'%';

-- Write access to mark records as synced
GRANT UPDATE, DELETE ON your_database.datasync_change_tracker TO 'datasync_user'@'%';
```

## Checking Pending Changes

```sql
-- Count pending changes by entity type
SELECT entity_type, COUNT(*) as pending
FROM datasync_change_tracker
WHERE sync_completed = 0
GROUP BY entity_type;

-- View recent pending changes
SELECT * FROM datasync_change_tracker
WHERE sync_completed = 0
ORDER BY created_at DESC
LIMIT 20;
```

## Troubleshooting

### Module not installing

1. Clear cache: `rm -rf var/cache/*`
2. Check module is enabled: Admin > System > Configuration > Advanced > Disable Modules Output
3. Check logs: `var/log/system.log`

### Changes not being tracked

1. Verify table exists: `SHOW TABLES LIKE 'datasync_change_tracker'`
2. Check events are firing (add logging to Observer)
3. Verify module is loading: check `app/etc/modules/Maho_DataSyncTracker.xml`

### Permissions error marking as synced

Grant UPDATE/DELETE permissions on the tracker table to your DataSync database user.
