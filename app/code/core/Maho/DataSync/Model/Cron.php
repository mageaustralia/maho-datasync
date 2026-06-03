<?php

declare(strict_types=1);

/**
 * Maho_DataSync
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Cron callback for the datasync_delta_sync job. The job has always been wired
 * to datasync/cron::runDeltaSync but this class never existed, so every run
 * failed with "Invalid callback". It is a no-op while DataSync (or its cron) is
 * disabled, and otherwise runs a sync for each configured entity type (the
 * engine tracks delta state internally).
 */
class Maho_DataSync_Model_Cron
{
    public function runDeltaSync(): void
    {
        if (!Mage::getStoreConfigFlag('datasync/general/enabled')
            || !Mage::getStoreConfigFlag('datasync/cron/enabled')
        ) {
            return;
        }

        $types = array_filter(array_map(
            'trim',
            explode(',', (string) Mage::getStoreConfig('datasync/cron/entity_types')),
        ));
        if ($types === []) {
            return;
        }

        /** @var Maho_DataSync_Model_Engine $engine */
        $engine = Mage::getModel('datasync/engine');
        foreach ($types as $type) {
            try {
                $engine->sync($type);
            } catch (Throwable $e) {
                Mage::logException($e);
            }
        }
    }
}
