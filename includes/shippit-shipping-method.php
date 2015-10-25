<?php
require('api-helper.php');

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( class_exists( 'Shippit_Shipping' ) ) return; // Stop if the class already exists


class Shippit_Shipping extends WC_Shipping_Method {

    /**
     * Configuration Helper
     */
    protected $helper;
    protected $api;

    /**
     * Constructor.
     */
    public function __construct() 
    {

        $this->id                   = 'mamis_shippit';
        $this->title                = __( 'Shippit', 'shippit' );
        $this->method_title         = __( 'Shippit', 'shippit' );
        $this->method_description   = __( 'Configure Shippit' ); 
        
        $this->init();

    }

    /**
     * Initialize Shippit method.
     */
    function init() {

        $this->load_helper();
        $this->init_form_fields();
        $this->init_settings();

        $this->enabled             = $this->get_option( 'enabled' );
        $this->shippit_api_key     = $this->get_option( 'shippit_api_ley' );
        $this->debug               = $this->get_option( 'shippit_debug' );
        $this->shippit_send_orders = $this->get_option( 'shippit_send_orders' );
        $this->shippit_title       = $this->get_option( 'shippit_title' );
        $this->hide_shipping       = $this->get_option( 'hide_other_shipping' );

        // Save settings in admin if you have any defined
        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    function load_helper()
    {
        $this->api = Mamis_Shippit_Helper_Api::get_instance();
        $this->api->get_post_response();
    }

    /**
     * Init fields.
     *
     * Add fields to the Shippit settings page.
     *
     */
    public function init_form_fields() {

        $this->form_fields = array(
            'enabled' => array(
                'title'         => __( 'Enabled', 'woocommerce' ),
                'type'          => 'checkbox',
                'label'         => __( 'Enable Advanced Free Shipping', 'shippit' ),
                'default'       => 'yes'
            ),
            array(
                'title'    => __( 'API Key', 'mamis' ),
                'desc'     => '',
                'id'       => 'shippit_api_key',
                'type'     => 'text',
                'css'      => 'min-width:300px;',
            ),
            array(
                'title'    => __( 'Debug', 'mamis' ),
                'id'       => 'shippit_debug',
                'class'    => 'wc-enhanced-select',
                'css'      => 'min-width:300px;',
                'default'  => '',
                'type'     => 'select',
                'options'  => array(
                    'no'  => __( 'No', 'mamis' ),
                    'yes' => __( 'Yes', 'mamis' ),
                ),
            ),
            array(
                'title'    => __( 'Send All Orders to Shippit', 'mamis' ),
                'id'       => 'shippit_send_orders',
                'class'    => 'wc-enhanced-select',
                'css'      => 'min-width:300px;',
                'default'  => '',
                'type'     => 'select',
                'options'  => array(
                    'no'  => __( 'No', 'mamis' ),
                    'yes' => __( 'Yes', 'mamis' ),
                ),
            ),
            array(
                'title'    => __( 'Title', 'mamis' ),
                'desc'     => '',
                'id'       => 'shippit_title',
                'type'     => 'text',
                'css'      => 'min-width:300px;',
            ),
            array(
                'title'    => __( 'Allowed Methods', 'mamis' ),
                'desc'     => '',
                'id'       => 'shippit_allowed_methods',
                'type'     => 'multiselect',
                'options'  => array(
                    'standard' => __( 'Standard', 'mamis'),
                    'premium'  => __( 'Premium', 'mamis'),
                    ),
                'css'      => 'min-width:300px;',
            ),
            array(
                'title'    => __( 'Maximum Timeslots', 'mamis' ),
                'id'       => 'shippit_max_timeslots',
                'class'    => 'wc-enhanced-select',
                'css'      => 'min-width:300px;',
                'default'  => '',
                'type'     => 'select',
                'options'  => array(
                    'no'  => __( '-- No Max Timeslots --', 'mamis' ),
                ),
            ),
            array(
                'title'    => __( 'Filter by enabled products', 'mamis' ),
                'id'       => 'shippit_filter_by_enabled',
                'class'    => 'wc-enhanced-select',
                'css'      => 'min-width:300px;',
                'default'  => '',
                'type'     => 'select',
                'options'  => array(
                    'no'  => __( 'No', 'mamis' ),
                    'yes' => __( 'Yes', 'mamis' ),
                ),
            ),
            array(
                'title'    => __( 'Restrict shipping to Location(s)', 'woocommerce' ),
                'desc'     => sprintf( __( 'Choose which countries you want to ship to, or choose to ship to all <a href="%s">locations you sell to</a>.', 'woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=general' ) ),
                'id'       => 'woocommerce_ship_to_countries',
                'default'  => '',
                'type'     => 'select',
                'class'    => 'wc-enhanced-select',
                'desc_tip' => false,
                'options'  => array(
                    ''         => __( 'Ship to all countries you sell to', 'woocommerce' ),
                    'all'      => __( 'Ship to all countries', 'woocommerce' ),
                    'specific' => __( 'Ship to specific countries only', 'woocommerce' )
                )
            ),
            array(
                'title'   => __( 'Specific Countries', 'woocommerce' ),
                'desc'    => '',
                'id'      => 'woocommerce_specific_ship_to_countries',
                'css'     => '',
                'default' => '',
                'type'    => 'multi_select_countries'
            ),
            array(
                'title'    => __( 'Show Method if Not Applicable', 'mamis' ),
                'id'       => 'shippit_show_method',
                'class'    => 'wc-enhanced-select',
                'css'      => 'min-width:300px;',
                'default'  => '',
                'type'     => 'select',
                'options'  => array(
                    'no'  => __( 'No', 'mamis' ),
                    'yes' => __( 'Yes', 'mamis' ),
                ),
            ),
        );
    }


    /**
     * Calculate shipping.
     *
     * @param mixed $package
     * @return void
     */
    public function calculate_shipping( $package ) {

        $API_ENDPOINT = 'http://goshippit.herokuapp.com/api/3/quotes?auth_token=R6XVx2B-lXsOzOH1Z7ew6w';

        $shipping_postcode = WC()->customer->get_shipping_postcode();
        $shipping_state = WC()->customer->get_shipping_state();

        //dropoff suburb currently hard coded for cart calculate shipping page. There is no option to select a suburb by default
        $requestData = array(
         'quote' => array(
             'order_date' => '2015-10-28', 
             'dropoff_suburb' => 'Melbourne',
             'dropoff_postcode' => $shipping_postcode,
             'dropoff_state' => $shipping_state,
             'parcel_attributes' => array(array(
                 'qty' => 1,
                 'length' => 0.1,
                 'width' => 0.10,
                 'depth' => 0.15,
                 'weight' => 3)
             ),
        ));
        $encoded = json_encode($requestData);
                                                                   
        $data_string = json_encode($requestData);                                                                                                      
        $ch = curl_init('http://goshippit.herokuapp.com/api/3/quotes?auth_token=R6XVx2B-lXsOzOH1Z7ew6w');                                                                      
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);            
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(                    
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string)
            )
        );                                                                                             

        $result = curl_exec($ch);

        // change from associative array to standard object
        $results = json_decode($result,true);

        $calc_tax = @$match_details['calc_tax'];

        foreach ($results as $resultsArray) {

            if (is_Array($resultsArray)) {

                foreach ($resultsArray as $result) {

                    if ($result['success']) {

                        $rate = array (
                            'id' => $result['courier_type'] . rand(),
                            'label' => $result['courier_type'],
                            'cost' => $result['quotes'][0]['price'],
                            'calc_tax' => ( null == $calc_tax ) ? 'per_order' : $calc_tax
                        );

                        $this->add_rate($rate);

                    }
                }
            }
        }
    }

}
