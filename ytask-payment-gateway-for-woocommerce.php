<?php
/*
 * Plugin Name: yTask Woocommerce Payment Gateway
 * Plugin URI: https://ytask.org
 * Description: Collect Mobile and card payments payments using ytask.org. We support more than 20 payment methods among IntaSend, MPESA, stripe, Alipay, 2checkout, Paypal, Braintree, Payza, Authorize.Net, Offline payments
 * Author: Justus Ochieng & ytask.org
 * Author URI: https://ytask.org
 * Version: 1.2
 */


/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'ytask_add_gateway_class' );
function ytask_add_gateway_class( $gateways ) {
    $gateways[] = 'yTask_Gateway'; // your class name is here
    return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'ytask_init_gateway_class' );


function ytask_init_gateway_class() {

    class yTask_Gateway extends WC_Payment_Gateway {

        /**
         * Class constructor, more about it in Step 3
         */
    public function __construct() {

 
    $this->id = 'ytask'; // payment gateway plugin ID
    $this->icon = ""; // URL of the icon that will be displayed on checkout page near your gateway name
    $this->has_fields = true; // in case you need a custom credit card form
    $this->method_title = 'yTask Payment Gateway';
    $this->method_description = 'Get more than 20 payment gateways'; // will be displayed on the options page

    // gateways can support subscriptions, refunds, saved payment methods,
    // but in this tutorial we begin with simple payments
    $this->supports = array(
        'products'
    );

    // Method with all the options fields
    $this->init_form_fields();

    // Load the settings.
    $this->init_settings();
    $this->title = $this->get_option( 'title' );
    $this->description = $this->get_option( 'description' );
    $this->enabled = $this->get_option( 'enabled' );
 
    $this->api_secret = $this->get_option( 'api_secret' );
    $this->api_key = $this->get_option( 'api_key' );

 
    if(isset( $_POST['order_id'] ) ){

       $this->complete_callback(sanitize_text_field($_POST['order_id']), sanitize_text_field($_POST['alias']));

      } 
 
    if(isset( $_GET['order_id'] )){

       $this->thankyou_page(sanitize_text_field($_GET['order_id']));
 
      } 
 
  // This action hook saves the settings
    add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

    // We need custom JavaScript to obtain a token
    // You can also register a webhook here
    // add_action( 'woocommerce_api_{webhook name}', array( $this, 'webhook' ) );
    }
         
        public function complete_callback($order_id, $alias){

            $order = wc_get_order( $order_id );
            $order->payment_complete();

             $order->add_order_note('Hey, your order is paid! Thank you! yTask invoice ID '.$alias, true);

             WC()->cart->empty_cart();
             
             $thank_you_page = $this->get_return_url($order);

            wp_safe_redirect($thank_you_page);

        }      


        public function payment_fields()
        {
            $plugin_path = plugin_dir_url(__FILE__);
            $banner = $plugin_path . "assets/images/acceptedpayment.png";
            echo wpautop(wp_kses_post("<div style='margin-bottom: 10px;'><img src=" . $banner . " alt='intasend-payment' style='padding: 10px; border-top: 2px solid #e6e6e6; border-bottom: 2px solid #e6e6e6; border-radius: 10px; max-height: 55px !important;'></div>"));
 
        }

 

        public function thankyou_page($order_id){

            $order = wc_get_order( $order_id );
 
            $order_data = $order->get_data(); // The Order data
        
            $order_status = $order_data['status'];
     
             $thank_you_page = $this->get_return_url($order);

            wp_safe_redirect($thank_you_page);

        }
        /**
         * Plugin options, we deal with it in Step 3 too
         */
        public function init_form_fields(){

    
    $this->form_fields = array(
        'enabled' => array(
            'title'       => 'Enable/Disable',
            'label'       => 'Enable yTask Payment Gateway',
            'type'        => 'checkbox',
            'description' => '',
            'default'     => 'no'
        ),
        'title' => array(
            'title'       => 'yTask',
            'type'        => 'text',
            'description' => 'Pay with ytask.',
            'default'     => 'yTask',
            'desc_tip'    => true,
        ),

        'description' => array(
            'title'       => 'Description',
            'type'        => 'textarea',
            'description' => 'More than 20 payment methods accepted vai yTask',
            'default'     => 'Pay with your credit card via yTask.',
        ),
   
 
        'api_key' => array(
            'title'       => 'yTask API Key',
            'type'        => 'text',
        ),
  
        'api_secret' => array(
            'title'       => 'yTask APi secret',
            'type'        => 'text'
        )
        );
        }

        /*
         * Fields validation, more in Step 5
         */
        public function validate_fields() {

        if( empty( $_POST[ 'billing_first_name' ]) ) {
        wc_add_notice(  'First name is required!', 'error' );
        return false;
         }
          return true;

        }

        /*
         * We're processing the payments here, everything about it is in Step 5
         */
        public function process_payment( $order_id ) {

       
    global $woocommerce;


            // Ensure you have public key
            if ( ! $this->api_key || ! $this->api_secret) {
                wc_add_notice('This transaction will fail to process. yTask API key and secret are required', 'error');
                return;
            }


 
    // we need it to get any order detailes
    $order = wc_get_order( $order_id );
  

    $order->update_status('on-hold', __( 'Awaiting payment', 'woocommerce' ));
    $currency = get_woocommerce_currency();

    $shop_page_url = get_permalink( woocommerce_get_page_id( 'shop' ) );
    /*
     * Array with parameters for API interaction
     */

       $alias = substr(sha1(mt_rand()), 20, 10);
  
       $url = "https://ytask.org/woocommerce";
       $redirect_url = "https://ytask.org/?p=".$alias;

       $sitename = parse_url(site_url());
          $response = wp_remote_post( $url, array(
                'method'      => 'POST',
                'timeout'     => 90,
                'redirection' => 1,
                'httpversion' => '1.0',
                'blocking'    => true,
                'headers'     => array(),
                'body'        => array(
                    'api_key' => $this->api_key,
                    'api_secret' => $this->api_secret,
              
                    'order_id' => $order_id,
                    'price' => $order->total,
                    'email' => $order->get_billing_email(),
                    'phone_number' => $order->get_billing_phone(),
                    'first_name' => $order->get_billing_first_name(),
                    'last_name' => $order->get_billing_last_name(),
                    'country' => $order->get_billing_country(),
                    'city' => $order->get_billing_city(),
                    'title' => $sitename['host'].' woo#'.$order_id,
                    'zipcode' => $order->get_billing_postcode(),
                    'state' => $order->get_billing_state(),
                    'address' => $order->get_billing_address_1(),
                    'comment' => $order->get_customer_note(),
                    'currency' => $currency,
                    'ipn_url' => $shop_page_url,
                    'redirect_url' => $shop_page_url,
                    'alias' => $alias,
                ),
                'cookies'     => array()
                )
            );
             
            if ( is_wp_error( $response ) ) {
                $error_message = $response->get_error_message();
                echo "Something went wrong: $error_message";
            } else {
                echo 'Response:<pre>';
                print_r( $response );
                echo '</pre>';
            }

        return array(
                'result'    => 'success',
                'redirect'  => $redirect_url,
        ); 
 
           
        }

        /*
         * In case you need a webhook, like PayPal IPN etc
         */



    }
}