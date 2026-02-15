<?php

/**
 * Maho DataSync WooCommerce Adapter (STUB)
 *
 * Future adapter for syncing from WooCommerce via REST API.
 * This is a stub with detailed TODOs - not yet implemented.
 *
 * @category   Maho
 * @package    Maho_DataSync
 *
 * @todo AUTHENTICATION
 *   - Implement WooCommerce REST API authentication
 *   - Support both Basic Auth (application passwords) and OAuth 1.0a
 *   - Store credentials securely (encrypted in Maho config)
 *   - Handle token refresh for OAuth
 *
 * @todo ENTITY MAPPING - CUSTOMERS
 *   - Map WooCommerce customer to Maho customer_entity
 *   - Handle WP user ID vs WooCommerce customer ID
 *   - Map meta fields (_billing_*, _shipping_*) to customer addresses
 *   - Handle guest customers (orders without accounts)
 *
 * @todo ENTITY MAPPING - ORDERS
 *   - Map WooCommerce order statuses to Maho equivalents:
 *     - wc-pending -> pending
 *     - wc-processing -> processing
 *     - wc-on-hold -> holded
 *     - wc-completed -> complete
 *     - wc-cancelled -> canceled
 *     - wc-refunded -> closed
 *     - wc-failed -> canceled
 *   - Map line items including:
 *     - Product ID/SKU resolution
 *     - Variation data for variable products
 *     - Custom meta (gift wrapping, etc.)
 *   - Map order meta fields
 *   - Handle partial refunds and order notes
 *
 * @todo ENTITY MAPPING - PRODUCTS (if needed)
 *   - Map WooCommerce product types:
 *     - simple -> simple
 *     - variable -> configurable
 *     - grouped -> grouped
 *     - external/affiliate -> virtual with custom options
 *   - Handle product variations
 *   - Map product attributes
 *   - Handle product images (may need to download)
 *
 * @todo ENTITY MAPPING - REVIEWS
 *   - Map WooCommerce product reviews to Mage_Review
 *   - Handle review ratings (1-5 stars)
 *   - Map review status (approved, pending, spam)
 *
 * @todo PAGINATION
 *   - Implement WP REST API pagination using:
 *     - per_page parameter (max 100)
 *     - page parameter
 *     - X-WP-Total header for total count
 *     - X-WP-TotalPages header for page count
 *   - Stream results efficiently for large datasets
 *
 * @todo DELTA SYNC
 *   - Use 'after' parameter for date-based delta (ISO 8601)
 *   - Use 'modified_after' for updated records
 *   - Track last sync timestamp per entity type
 *
 * @todo RATE LIMITING
 *   - Respect WooCommerce API rate limits
 *   - Implement exponential backoff on 429 responses
 *   - Consider batching requests where possible
 *
 * @todo ERROR HANDLING
 *   - Handle API errors gracefully
 *   - Map WC error codes to DataSync exceptions
 *   - Log failed requests with context
 *
 * @todo EXTENSIONS
 *   - WooCommerce Subscriptions support
 *   - WooCommerce Memberships support
 *   - Custom checkout field plugins
 *   - Multi-currency plugins (WPML, WooCommerce Multi-Currency)
 */
class Maho_DataSync_Model_Adapter_Woocommerce extends Maho_DataSync_Model_Adapter_Abstract
{
    protected string $_endpoint = '';
    protected string $_consumerKey = '';
    protected string $_consumerSecret = '';

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getCode(): string
    {
        return 'woocommerce';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'WooCommerce (Not Yet Implemented)';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function configure(array $config): void
    {
        parent::configure($config);

        $this->_endpoint = $config['endpoint'] ?? '';
        $this->_consumerKey = $config['consumer_key'] ?? '';
        $this->_consumerSecret = $config['consumer_secret'] ?? '';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function validate(): bool
    {
        throw new Maho_DataSync_Exception(
            'WooCommerce adapter is not yet implemented. See class TODOs for implementation roadmap.',
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
            'WooCommerce adapter is not yet implemented. See class TODOs for implementation roadmap.',
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
