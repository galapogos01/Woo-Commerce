<?php
/**
*  Mamis.IT
*
*  NOTICE OF LICENSE
*
*  This source file is subject to the EULA
*  that is available through the world-wide-web at this URL:
*  http://www.mamis.com.au/licencing
*
*  @category   Mamis
*  @copyright  Copyright (c) 2015 by Mamis.IT Pty Ltd (http://www.mamis.com.au)
*  @author     Matthew Muscat <matthew@mamis.com.au>
*  @license    http://www.mamis.com.au/licencing
*/

class Mamis_Shippit_Order
{
    private $api;
    private $s;

    const CARRIER_CODE = 'mamis_shippit';

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->api = new Mamis_Shippit_Api();
        $this->s = new Mamis_Shippit_Settings();
    }

    /**
     * Remove a pending sync
     *
     * Called when an order moves out from "processing"
     * status to a hold status
     * 
     * @param  int     $order_id    The Order Id
     * @return boolean              True or false
     */
    public function removePendingSync($orderId)
    {
        $order = new WC_Order($orderId);

        if (get_post_meta($orderId, '_mamis_shippit_sync', true) == 'false') {
            delete_post_meta($orderId, '_mamis_shippit_sync');
        }
    }

    /**
     * Add a pending sync
     *
     * Called when an order moves into the "processing" state
     * 
     * @param  int     $orderId    The Order Id
     * @return boolean              True or false
     */
    public function addPendingSync($orderId)
    {   $isEnabled = $this->s->getSetting('enabled');
        $sendAllOrders = $this->s->getSetting('send_all_orders');

        if ($isEnabled != 'yes') {
            return;
        }

        if (get_post_meta($orderId, '_mamis_shippit_sync', true) == 'true') {
            return;
        }

        // Get the orders_item_id meta with key shipping
        $order = new WC_Order($orderId);
        $isShippitShippingMethod = $order->get_shipping_methods();

        if ($sendAllOrders == 'yes' && $order->shipping_country == 'AU') {
            add_post_meta($orderId, '_mamis_shippit_sync', 'false', true);
            // attempt to sync the order now
            $this->syncOrder($orderId);
        }
        elseif ($this->_isShippitShippingMethod($order)) {
            add_post_meta($orderId, '_mamis_shippit_sync', 'false', true);
            // attempt to sync the order now
            $this->syncOrder($orderId);
        }
    }

    private function _isShippitShippingMethod($order)
    {
        $shippingMethods = $order->get_shipping_methods();
        $standardShippingMethods = $this->s->getSetting('standard_shipping_methods');
        $expressShippingMethods = $this->s->getSetting('express_shipping_methods');

        foreach ($shippingMethods as $shippingMethod) {
            if (!empty($standardShippingMethods)
                && in_array($shippingMethod['method_id'], $standardShippingMethods)) {
                return true;
            }

            if (!empty($expressShippingMethods)
                && in_array($shippingMethod['method_id'], $expressShippingMethods)) {
                return true;
            }

            // Check if the shipping method chosen is a shippit method
            if (strpos($shippingMethod['method_id'], 'Mamis_Shippit') !== FALSE) {
                return true;
            }
        }

        return false;
    }

    private function _getShippingMethodId($order)
    {
        $shippingMethods = $order->get_shipping_methods();
        $standardShippingMethods = $this->s->getSetting('standard_shipping_methods');
        $expressShippingMethods = $this->s->getSetting('express_shipping_methods');

        foreach ($shippingMethods as $shippingMethod) {
            // Check if shipping method is mapped to standard
            if (!empty($standardShippingMethods)
                && in_array($shippingMethod['method_id'], $standardShippingMethods)) {
                return 'CouriersPlease';
            }

            // Check if shipping method is mapped to express
            if (!empty($mappedExpressShipping)
                && in_array($shippingMethod['method_id'], $mappedExpressShipping)) {
                return 'eParcelExpress';
            }

            // Check if the shipping method chosen is Mamis_Shippit
            if (strpos($shippingMethod['method_id'], 'Mamis_Shippit') !== FALSE) {
                return $shippingMethod['method_id'];
            }
        }

        return false;
    }

    /**
    * Add Sync Meta
    *
    * Add _mamis_shippit_sync meta key value to all orders that
    * are using the Mamis_Shippit Method
    */

    /**
     * Sync all pending orders
     * @return [type] [description]
     */
    public function syncOrders()
    {
        global $woocommerce;

        $orderPostArg = array(
            'post_status' => 'wc-processing',
            'post_type' => 'shop_order',
            'meta_query' => array(
                array(
                'key' => '_mamis_shippit_sync',
                'value' => 'false',
                'compare' => '='
                )
            ),
        );

        // Get all woocommerce orders that are processing

        $orderPosts = get_posts($orderPostArg);

        foreach ($orderPosts as $orderPost) {
            $this->syncOrder($orderPost->ID);
        }
    }

    public function syncOrder($orderId)
    {
        // Get the orders_item_id meta with key shipping
        $order = new WC_Order($orderId);
        $orderItems = $order->get_items();
        $orderData = array();

        $shippingMethodId = $this->_getShippingMethodId($order);

        if ($shippingMethodId) {
            // Check if the shipping method chosen was Mamis_Shippit
            $shippingOptions = str_replace('Mamis_Shippit_', '', $shippingMethodId);
            $shippingOptions = explode('_', $shippingOptions);

            $orderData['courier_type'] = $shippingOptions[0];

            if (isset($shippingOptions[1])) {
                $orderData['delivery_date'] = $shippingOptions[1];
            }
            if (isset($shippingOptions[2])) {
                $orderData['delivery_window'] = $shippingOptions[2];
            }
        }
        // fallback to couriers please if a method could no longer be mapped
        else {
            $orderData['courier_type'] = 'CouriersPlease';
        }

        // Set user attributes
        $orderData['user_attributes'] = array(
            'email'      => get_post_meta($orderId, '_billing_email', true),
            'first_name' => get_post_meta($orderId, '_billing_first_name', true),
            'last_name'  => get_post_meta($orderId, '_billing_last_name', true)
        );

        $orderData['receiver_name'] =
            get_post_meta($orderId, '_shipping_first_name', true)
            . ' ' . get_post_meta($orderId, '_shipping_last_name', true);

        $orderData['receiver_contact_number'] = get_post_meta($orderId, '_billing_phone', true);

        if (sizeof($orderItems) > 0) {
            foreach ($orderItems as $orderItem) {
                if ($orderItem['product_id'] > 0) {
                    $product = $order->get_product_from_item($orderItem);

                    if (!$product->is_virtual()) {
                        $orderData['parcel_attributes'][] = array(
                            'qty' => $orderItem['qty'],
                            'weight' => ($product->get_weight() == 0 ? 0.2 : $product->get_weight())
                        );
                    }
                }
            }
        }

        $authorityToLeave = get_post_meta($orderId, 'authority_to_leave', true);
        
        if (empty($authorityToLeave)) {
            $authorityToLeave = 'No';
        }

        $orderData['delivery_postcode']        = $order->shipping_postcode;
        $orderData['delivery_address']         = trim($order->shipping_address_1 . ' ' . $order->shipping_address_2);
        $orderData['delivery_suburb']          = $order->shipping_city;
        $orderData['delivery_state']           = $order->shipping_state;
        $orderData['delivery_instructions']    = $order->customer_message;
        $orderData['authority_to_leave']       = $authorityToLeave;
        $orderData['retailer_invoice']         = $order->get_order_number();

        // Send the API request
        $apiResponse = $this->api->sendOrder($orderData);

        if ($apiResponse && $apiResponse->tracking_number) {
            update_post_meta($orderId, '_mamis_shippit_sync', 'true', 'false');
            $orderComment = 'Order Synced with Shippit. Tracking number: ' . $apiResponse->tracking_number . '.';
            $order->add_order_note($orderComment, 0);
        }
    }
}