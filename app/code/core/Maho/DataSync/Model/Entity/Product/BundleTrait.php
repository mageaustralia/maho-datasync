<?php

/**
 * Maho
 *
 * @package    Maho_DataSync
 * @copyright  Copyright (c) 2025-2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

/**
 * Bundle Options import trait (Not Yet Implemented)
 *
 * Planned format:
 * bundle_options = [{"title":"Choose Item","type":"select","required":1,"selections":[{"sku":"ITEM-001","qty":1}]}]
 */
trait Maho_DataSync_Model_Entity_Product_BundleTrait
{
    /**
     * Flag to track if bundle warning has been logged
     */
    protected bool $_bundleWarningLogged = false;

    /**
     * Handle bundle options (not yet implemented - logs warning)
     */
    protected function _importBundleOptions(Mage_Catalog_Model_Product $product, string|array $bundleOptions): void
    {
        if (!$this->_bundleWarningLogged) {
            $this->_log(
                'Bundle options import not yet implemented. bundle_options column will be ignored.',
                Maho_DataSync_Helper_Data::LOG_LEVEL_WARNING,
            );
            $this->_bundleWarningLogged = true;
        }
    }
}
