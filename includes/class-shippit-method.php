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

class Mamis_Shippit_Method extends WC_Shipping_Method
{
    private $api;
    private $s;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->api = new Mamis_Shippit_Api();
        $this->s = new Mamis_Shippit_Settings();
        $this->log = new Mamis_Shippit_Log();

        $this->id                   = 'mamis_shippit';
        $this->title                = __('Shippit', 'woocommerce-shippit');
        $this->method_title         = __('Shippit', 'woocommerce-shippit');
        $this->method_description   = __('Configure Shippit');

        $this->init();
    }

    /**
     * Initialize plugin parts.
     *
     * @since 1.0.0
     */
    public function init()
    {
        // Load the settings API
        $this->init_form_fields();
        $this->init_settings();
    
        // *****************
        // Shipping Method
        // *****************

        // add shipping method
        add_filter('woocommerce_shipping_methods', array($this, 'add_shipping_method'));

        // *****************
        // Shipping Method Save Event
        // *****************

        // Save settings in admin if you have any defined
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields()
    {
        // parent::init_form_fields();
        $this->form_fields = Mamis_Shippit_Settings::getFields();
    }

    /**
     * Add shipping method.
     *
     * Add shipping method to WooCommerce.
     *
     */
    public function add_shipping_method($methods)
    {
        if (class_exists('Mamis_Shippit_Method')) {
            $methods[] = 'Mamis_Shippit_Method';
        }

        return $methods;
    }

    /**
     * Calculate shipping.
     *
     * @param mixed $package
     * @return void
     */
    public function calculate_shipping($package)
    {
        // Check if the module is enabled and used for shipping quotes
        if ($this->enabled != 'yes') {// || !$this->s->getSetting('allowed_methods')) {
            return;
        }
        
        $quoteDestination = $package['destination'];
        $quoteCart = $package['contents'];

        // Check if we can ship the products by enabled filtering
        if (!$this->_canShipEnabledProducts($package)) {
            return;
        }

        // Check if we can ship the products by attribute filtering
        if (!$this->_canShipEnabledAttributes($package)) {
            return;
        }

        $this->_processShippingQuotes($quoteDestination, $quoteCart);
    }

    private function _processShippingQuotes($quoteDestination, $quoteCart)
    {
        $isPremiumAvailable = in_array('premium', $this->s->getSetting('allowed_methods'));
        $isStandardAvailable = in_array('standard', $this->s->getSetting('allowed_methods'));

        $dropoffSuburb = $quoteDestination['city'];
        $dropoffPostcode = $quoteDestination['postcode'];
        $dropoffState = $quoteDestination['state'];

        $qty = WC()->cart->cart_contents_count;
        $weight = WC()->cart->cart_contents_weight;
        
        if ($weight == 0) {
            // override the weight to 1kg
            $weight = 1;
        }

        $quoteData = array(
            'order_date' => '', // get all available dates
            'dropoff_suburb' => $dropoffSuburb,
            'dropoff_postcode' => $dropoffPostcode,
            'dropoff_state' => $dropoffState,
            'parcel_attributes' => array(
                array(
                    'qty' => $qty,
                    'weight' => $weight
                )
            ),
        );

        $shippingQuotes = $this->api->getQuote($quoteData);

        if ($shippingQuotes) {
            foreach($shippingQuotes as $shippingQuote) {
                if ($shippingQuote->success) {
                    if ($shippingQuote->courier_type == 'Bonds'
                        && $isPremiumAvailable) {
                        $this->_addPremiumQuote($shippingQuote);
                    }
                    elseif ($shippingQuote->courier_type != 'Bonds'
                        && $isStandardAvailable) {
                        $this->_addStandardQuote($shippingQuote);
                    }
                }
            }
        }
        else {
            return false;
        }
    }

    private function _addStandardQuote($shippingQuote)
    {
        foreach ($shippingQuote->quotes as $standardQuote) {
            $rate = array(
                'id'    => 'Mamis_Shippit_' . $shippingQuote->courier_type,// . '_' . uniqid(),
                'label' => 'Couriers Please',
                'cost'  => $standardQuote->price,
                'taxes' => false,
            );

            $this->add_rate($rate);
        }
    }

    private function _addPremiumQuote($shippingQuote)
    {
        $timeSlotCount = 0;
        $maxTimeSlots = $this->s->getSetting('max_timeslots');

        foreach ($shippingQuote->quotes as $premiumQuote) {
            if (!empty($maxTimeSlots) && $maxTimeSlots <= $timeSlotCount) {
                break;
            }

            if (property_exists($premiumQuote, 'delivery_date')
                && property_exists($premiumQuote, 'delivery_window')
                && property_exists($premiumQuote, 'delivery_window_desc')) {
                $timeSlotCount++;
                $carrierTitle = $shippingQuote->courier_type;
                $method = $shippingQuote->courier_type . '_' . $premiumQuote->delivery_date . '_' . $premiumQuote->delivery_window;
                $premiumQuoteDeliveryDate = $premiumQuote->delivery_date;
                $premiumQuoteDeliveryDate = date('d/m/Y',strtotime($premiumQuoteDeliveryDate));
                $methodTitle = 'Scheduled' . ' - Delivered ' . $premiumQuoteDeliveryDate. ' between ' . $premiumQuote->delivery_window_desc;
            }
            else {
                $carrierTitle = $shippingQuote->courier_type;
                $method = $shippingQuote->courier_type;
                $methodTitle = 'Scheduled';
            }

            $rate = array(
                'id'    => 'Mamis_Shippit_'.$carrierTitle .'_' . $premiumQuote->delivery_date . '_' . $premiumQuote->delivery_window,// . '_' . uniqid(),
                'label' => $methodTitle,
                'cost'  => $premiumQuote->price,
                'taxes' => false,
            );

            $this->add_rate($rate);
        }
    }



    /**
     * Checks if we can ship the products in the cart
     * @return [type] [description]
     */
    private function _canShipEnabledProducts($package)
    {
        if ($this->s->getSetting('filter_enabled') == 'no') {
            return true;
        }

        $allowedProducts = $this->s->getSetting('filter_enabled_products');

        $products = $package['contents'];
        $productIds = array();

        foreach ($products as $itemKey => $product) {
            $productIds[] = $product['product_id'];
        }

        if (count($allowedProducts) > 0) {
            // If item is not enabled return false
            if ($productIds != array_intersect($productIds, $allowedProducts)) {
                $this->log->add(
                    'Can Ship Enabled Products',
                    'Returning false'
                );

                return false;
            }
        }

        $this->log->add(
            'Can Ship Enabled Products',
            'Returning true'
        );
        
        return true;
    }

    private function _canShipEnabledAttributes($package)
    {
        if ($this->s->getSetting('filter_attribute') == 'no') {
            return true;
        }

        $attributeCode = $this->s->getSetting('filter_attribute_code');

        // Check if there is an attribute code set
        if (empty($attributeCode)) {
            return true;
        }

        $attributeValue = $this->s->getSetting('filter_attribute_value');

        // Check if there is an attribute value set
        if (empty($attributeValue)) {
            return true;
        }

        $products = $package['contents'];

        foreach ($products as $itemKey => $product) {
            $productObject = new WC_Product($product['product_id']);
            $productAttributeValue = $productObject->get_attribute($attributeCode);

            if (strpos($productAttributeValue, $attributeValue) === false) {
                $this->log->add(
                    'Can Ship Enabled Attributes',
                    'Returning false'
                );

                return false;
            }
        }

        $this->log->add(
            'Can Ship Enabled Attributes',
            'Returning true'
        );

        return true;
    }
}