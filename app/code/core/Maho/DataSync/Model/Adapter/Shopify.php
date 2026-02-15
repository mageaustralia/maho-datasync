<?php

/**
 * Maho DataSync Shopify Adapter (STUB)
 *
 * Future adapter for syncing from Shopify via Admin API.
 * This is a stub with detailed TODOs - not yet implemented.
 *
 * @category   Maho
 * @package    Maho_DataSync
 *
 * @todo AUTHENTICATION
 *   - Implement Shopify Admin API authentication
 *   - Support Custom App tokens (recommended for private apps)
 *   - Support OAuth for public apps
 *   - Handle API versioning (Shopify versions API quarterly)
 *   - Store credentials securely (encrypted in Maho config)
 *
 * @todo API VERSIONING
 *   - Use stable API version (e.g., 2024-01)
 *   - Handle API deprecation warnings
 *   - Plan for quarterly version updates
 *
 * @todo ENTITY MAPPING - CUSTOMERS
 *   - Map Shopify customer to Maho customer_entity
 *   - Handle customer metafields
 *   - Map customer addresses (Shopify allows up to 10)
 *   - Handle customer tags
 *   - Map customer marketing consent
 *   - Handle multi-pass for SSO (if using)
 *
 * @todo ENTITY MAPPING - ORDERS
 *   - Map Shopify order statuses:
 *     - open -> processing
 *     - archived -> complete
 *     - cancelled -> canceled
 *   - Map fulfillment status:
 *     - fulfilled, partial, unfulfilled
 *   - Map financial status:
 *     - paid, pending, refunded, partially_refunded
 *   - Map line items including:
 *     - Variant ID to Maho product mapping
 *     - Custom properties
 *     - Discounts and discount allocations
 *   - Handle transactions (payments, refunds)
 *   - Map shipping lines
 *   - Handle order metafields
 *   - Map order risks (fraud analysis)
 *
 * @todo ENTITY MAPPING - PRODUCTS (if needed)
 *   - Map Shopify products to Maho:
 *     - Simple product: Shopify product with 1 variant
 *     - Configurable: Shopify product with multiple variants
 *   - Handle product options (up to 3 in Shopify)
 *   - Map product images
 *   - Handle product metafields
 *   - Map collections to categories
 *   - Handle product tags
 *
 * @todo PAGINATION
 *   - Use cursor-based pagination (page_info parameter)
 *   - Parse Link header for next/previous page URLs
 *   - Handle limit parameter (max 250)
 *   - Stream results efficiently
 *
 * @todo DELTA SYNC
 *   - Use created_at_min/created_at_max for date filtering
 *   - Use updated_at_min/updated_at_max for delta
 *   - Track last sync timestamp per entity type
 *
 * @todo RATE LIMITING
 *   - Respect Shopify API limits:
 *     - REST: 40 requests/second (bucket)
 *     - GraphQL: 1000 points/second
 *   - Handle X-Shopify-Shop-Api-Call-Limit header
 *   - Implement exponential backoff on 429
 *
 * @todo GRAPHQL ALTERNATIVE
 *   - Consider using GraphQL for efficiency
 *   - Bulk operations for large datasets
 *   - Query cost calculation
 *
 * @todo ERROR HANDLING
 *   - Handle Shopify API errors
 *   - Map error codes to DataSync exceptions
 *   - Handle shop hibernation/frozen states
 *
 * @todo MULTI-LOCATION
 *   - Handle Shopify Locations for inventory
 *   - Map fulfillment locations
 *
 * @todo SHOPIFY PLUS
 *   - Scripts (discounts, shipping, payment)
 *   - Checkout customization
 *   - B2B features (company accounts)
 *   - Organization-level access
 */
class Maho_DataSync_Model_Adapter_Shopify extends Maho_DataSync_Model_Adapter_Abstract
{
    protected string $_shopDomain = '';
    protected string $_accessToken = '';
    protected string $_apiVersion = '2024-01';

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getCode(): string
    {
        return 'shopify';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getLabel(): string
    {
        return 'Shopify (Not Yet Implemented)';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function configure(array $config): void
    {
        parent::configure($config);

        $this->_shopDomain = $config['shop_domain'] ?? '';
        $this->_accessToken = $config['access_token'] ?? '';
        $this->_apiVersion = $config['api_version'] ?? '2024-01';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function validate(): bool
    {
        throw new Maho_DataSync_Exception(
            'Shopify adapter is not yet implemented. See class TODOs for implementation roadmap.',
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
            'Shopify adapter is not yet implemented. See class TODOs for implementation roadmap.',
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
        $info['api_version'] = $this->_apiVersion;
        $info['documentation'] = 'See class PHPDoc for implementation TODOs';
        return $info;
    }
}
