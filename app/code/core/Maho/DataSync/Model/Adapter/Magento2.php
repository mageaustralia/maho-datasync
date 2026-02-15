<?php

/**
 * Maho DataSync Magento 2 Adapter (STUB)
 *
 * Future adapter for syncing from Magento 2 via REST API.
 * This is a stub with detailed TODOs - not yet implemented.
 *
 * @category   Maho
 * @package    Maho_DataSync
 *
 * @todo AUTHENTICATION
 *   - Implement M2 REST API authentication
 *   - Support Integration tokens (recommended for server-to-server)
 *   - Support Admin tokens (for testing/development)
 *   - Handle token expiration and refresh
 *   - Store credentials securely (encrypted in Maho config)
 *
 * @todo ENTITY MAPPING - CUSTOMERS
 *   - Map M2 customer to Maho customer_entity
 *   - Handle customer attributes (EAV vs flat)
 *   - Map customer addresses
 *   - Handle customer groups mapping
 *   - Map custom customer attributes
 *
 * @todo ENTITY MAPPING - ORDERS
 *   - Map M2 order statuses to Maho equivalents (mostly 1:1)
 *   - Map order items including:
 *     - Product options and custom options
 *     - Bundle selections
 *     - Configurable super attributes
 *     - Downloadable links
 *   - Map payment information
 *   - Map shipping information and tracking
 *   - Handle order comments/history
 *   - Handle invoices, shipments, credit memos
 *
 * @todo ENTITY MAPPING - PRODUCTS (if needed)
 *   - Map M2 product types (mostly 1:1):
 *     - simple, configurable, grouped, bundle, virtual, downloadable
 *   - Handle product attributes (EAV)
 *   - Map product images and media gallery
 *   - Handle product links (related, upsell, crosssell)
 *   - Map tier prices
 *   - Handle MSI inventory data
 *
 * @todo ENTITY MAPPING - CATEGORIES (if needed)
 *   - Map category tree structure
 *   - Handle category attributes
 *   - Map category products assignments
 *
 * @todo ENTITY MAPPING - REVIEWS
 *   - Map M2 reviews to Mage_Review
 *   - Handle multiple rating types (Quality, Value, Price, etc.)
 *   - Map review status
 *
 * @todo PAGINATION
 *   - Use M2 searchCriteria for filtering:
 *     - searchCriteria[filterGroups][0][filters][0][field]
 *     - searchCriteria[filterGroups][0][filters][0][value]
 *     - searchCriteria[filterGroups][0][filters][0][conditionType]
 *   - Use searchCriteria[pageSize] and searchCriteria[currentPage]
 *   - Parse total_count from response
 *
 * @todo DELTA SYNC
 *   - Use updated_at filter for delta sync
 *   - Track last sync timestamp per entity type
 *   - Handle timezone differences
 *
 * @todo BULK API
 *   - Consider using M2 Bulk API for large datasets
 *   - Handle async operations
 *
 * @todo ERROR HANDLING
 *   - Handle M2 API errors (400, 401, 404, 500)
 *   - Map error codes to DataSync exceptions
 *   - Handle rate limiting (if configured on M2 side)
 *
 * @todo MULTI-STORE
 *   - Handle M2 store views
 *   - Support website/store/store_view hierarchy
 *   - Map store codes to Maho store IDs
 *
 * @todo EXTENSIONS
 *   - Adobe Commerce (Enterprise) features
 *   - MSI (Multi-Source Inventory)
 *   - B2B features (Company accounts, shared catalogs)
 *   - Page Builder content
 */
class Maho_DataSync_Model_Adapter_Magento2 extends Maho_DataSync_Model_Adapter_Abstract
{
    protected string $_baseUrl = '';
    protected string $_accessToken = '';

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getCode(): string
    {
        return 'magento2';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'Magento 2 (Not Yet Implemented)';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function configure(array $config): void
    {
        parent::configure($config);

        $this->_baseUrl = rtrim($config['base_url'] ?? '', '/');
        $this->_accessToken = $config['access_token'] ?? '';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function validate(): bool
    {
        throw new Maho_DataSync_Exception(
            'Magento 2 adapter is not yet implemented. See class TODOs for implementation roadmap.',
            Maho_DataSync_Exception::CODE_GENERAL,
        );
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function read(string $entityType, array $filters = []): iterable
    {
        throw new Maho_DataSync_Exception(
            'Magento 2 adapter is not yet implemented. See class TODOs for implementation roadmap.',
            Maho_DataSync_Exception::CODE_GENERAL,
        );
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getInfo(): array
    {
        $info = parent::getInfo();
        $info['status'] = 'STUB - Not Implemented';
        $info['documentation'] = 'See class PHPDoc for implementation TODOs';
        return $info;
    }
}
