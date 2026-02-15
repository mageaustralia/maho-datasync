<?php
/**
 * Maho DataSync Tracker Observer
 *
 * Tracks entity changes to the datasync_change_tracker table for
 * incremental sync between OpenMage (source) and Maho (destination).
 *
 * @category   Maho
 * @package    Maho_DataSyncTracker
 */

class Maho_DataSyncTracker_Model_Observer
{
    /**
     * Track a change to the tracker table
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to handle rapid successive changes
     *
     * @param string $type Entity type (customer, order, invoice, etc.)
     * @param int $entityId Entity ID
     * @param string $action Action type (create, update, delete)
     * @return void
     */
    protected function _track($type, $entityId, $action = 'update')
    {
        if (!$entityId) {
            return;
        }

        try {
            $resource = Mage::getSingleton('core/resource');
            $write = $resource->getConnection('core_write');
            $table = $resource->getTableName('datasync_change_tracker');

            // Upsert: if entity already tracked and not synced, update timestamp
            // If action is delete, always use delete (even if previous was create/update)
            $write->query("
                INSERT INTO {$table} (entity_type, entity_id, action, created_at, sync_completed)
                VALUES (?, ?, ?, NOW(), 0)
                ON DUPLICATE KEY UPDATE
                    action = IF(VALUES(action) = 'delete', 'delete', action),
                    created_at = NOW(),
                    sync_completed = 0
            ", array($type, $entityId, $action));
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Track customer save
     */
    public function trackCustomer(Varien_Event_Observer $observer)
    {
        $customer = $observer->getCustomer();
        $action = $customer->isObjectNew() ? 'create' : 'update';
        $this->_track('customer', $customer->getId(), $action);
    }

    /**
     * Track customer delete
     */
    public function trackCustomerDelete(Varien_Event_Observer $observer)
    {
        $customer = $observer->getCustomer();
        $this->_track('customer', $customer->getId(), 'delete');
    }

    /**
     * Track customer address save
     */
    public function trackCustomerAddress(Varien_Event_Observer $observer)
    {
        $address = $observer->getCustomerAddress();
        $action = $address->isObjectNew() ? 'create' : 'update';
        $this->_track('customer_address', $address->getId(), $action);

        // Also mark parent customer as updated
        if ($address->getCustomerId()) {
            $this->_track('customer', $address->getCustomerId(), 'update');
        }
    }

    /**
     * Track order save
     */
    public function trackOrder(Varien_Event_Observer $observer)
    {
        $order = $observer->getOrder();
        $action = $order->isObjectNew() ? 'create' : 'update';
        $this->_track('order', $order->getId(), $action);
    }

    /**
     * Track invoice save
     */
    public function trackInvoice(Varien_Event_Observer $observer)
    {
        $invoice = $observer->getInvoice();
        $this->_track('invoice', $invoice->getId(), 'create');

        // Also mark parent order as updated
        $this->_track('order', $invoice->getOrderId(), 'update');
    }

    /**
     * Track shipment save
     */
    public function trackShipment(Varien_Event_Observer $observer)
    {
        $shipment = $observer->getShipment();
        $this->_track('shipment', $shipment->getId(), 'create');

        // Also mark parent order as updated
        $this->_track('order', $shipment->getOrderId(), 'update');
    }

    /**
     * Track credit memo save
     */
    public function trackCreditmemo(Varien_Event_Observer $observer)
    {
        $creditmemo = $observer->getCreditmemo();
        $this->_track('creditmemo', $creditmemo->getId(), 'create');

        // Also mark parent order as updated
        $this->_track('order', $creditmemo->getOrderId(), 'update');
    }

    /**
     * Track order status history/comment save
     */
    public function trackOrderComment(Varien_Event_Observer $observer)
    {
        $history = $observer->getStatusHistory();
        if (!$history) {
            $history = $observer->getComment();
        }

        if ($history) {
            $this->_track('order_comment', $history->getId(), 'create');

            // Also mark parent order as updated
            if ($history->getParentId()) {
                $this->_track('order', $history->getParentId(), 'update');
            }
        }
    }

    /**
     * Track newsletter subscriber save
     */
    public function trackNewsletter(Varien_Event_Observer $observer)
    {
        $subscriber = $observer->getSubscriber();
        $action = $subscriber->isObjectNew() ? 'create' : 'update';
        $this->_track('newsletter', $subscriber->getId(), $action);
    }

    /**
     * Track product save
     */
    public function trackProduct(Varien_Event_Observer $observer)
    {
        $product = $observer->getProduct();
        $action = $product->isObjectNew() ? 'create' : 'update';
        $this->_track('product', $product->getId(), $action);
    }

    /**
     * Track product delete
     */
    public function trackProductDelete(Varien_Event_Observer $observer)
    {
        $product = $observer->getProduct();
        $this->_track('product', $product->getId(), 'delete');
    }

    /**
     * Track category save
     */
    public function trackCategory(Varien_Event_Observer $observer)
    {
        $category = $observer->getCategory();
        $action = $category->isObjectNew() ? 'create' : 'update';
        $this->_track('category', $category->getId(), $action);
    }

    /**
     * Track category delete
     */
    public function trackCategoryDelete(Varien_Event_Observer $observer)
    {
        $category = $observer->getCategory();
        $this->_track('category', $category->getId(), 'delete');
    }

    /**
     * Track stock item save
     */
    public function trackStock(Varien_Event_Observer $observer)
    {
        $item = $observer->getItem();
        $this->_track('stock', $item->getProductId(), 'update');
    }

    /**
     * Cleanup old synced records (called by cron)
     * Removes records that have been synced and are older than 30 days
     */
    public function cleanupOldRecords()
    {
        try {
            $resource = Mage::getSingleton('core/resource');
            $write = $resource->getConnection('core_write');
            $table = $resource->getTableName('datasync_change_tracker');

            $deleted = $write->delete($table, array(
                'sync_completed = ?' => 1,
                'synced_at < ?' => date('Y-m-d H:i:s', strtotime('-30 days'))
            ));

            Mage::log("DataSyncTracker cleanup: Deleted {$deleted} old synced records", Zend_Log::INFO, 'datasync.log');
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }
}
