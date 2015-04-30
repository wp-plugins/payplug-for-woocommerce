<?php
/**
 * Plugin Name: PayPlug for WooCommerce
 * Plugin URI: http://www.payplug.com/
 * Description: PayPlug is a payment gateway for WooCommerce
 * Author: PayPlug
 * Author URI: http://www.payplug.com/
 * Original Author: Boris Colombier
 * Original Author URI: https://wba.fr
 * Version: 1.0.0
 * Text Domain: woopayplug
 * Domain Path: /languages/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}
/**
 * Check if WooCommerce is active
 **/
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    
    /**
     * WooCommerce fallback notice.
     */
    function wcpayplug_woocommerce_fallback_notice() {
        $html = '<div class="error">';
            $html .= '<p>' . __( 'The WooCommerce PayPlug Gateway requires the latest version of <a href="http://wordpress.org/extend/plugins/woocommerce/" target="_blank">WooCommerce</a> to work!', 'woopayplug' ) . '</p>';
        $html .= '</div>';
        echo $html;
    }

    /**
     * define getallheaders function if not available.
     */
    if(!function_exists('getallheaders')){
        function getallheaders(){
            $headers = array();
            foreach ($_SERVER as $name => $value){
                if(substr($name, 0, 5) == 'HTTP_'){
                    $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                    $headers[$name] = $value;
                } else if($name == "CONTENT_TYPE") {
                    $headers["Content-Type"] = $value;
                } else if($name == "CONTENT_LENGTH") {
                    $headers["Content-Length"] = $value;
                } else{
                    $headers[$name]=$value;
                }
           }
           return $headers;
        }
    }

    /**
     * Load functions.
     */
    add_action( 'plugins_loaded', 'wcpayplug_gateway_load', 0 );

    function wcpayplug_gateway_load() {
        /**
         * Load textdomain.
         */
        load_plugin_textdomain( 'woopayplug', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

        if(!class_exists('WC_Payment_Gateway')){
            add_action('admin_notices', 'wcpayplug_woocommerce_fallback_notice');
            return;
        }

        /**
         * Add the gateway to WooCommerce.
         */
        add_filter( 'woocommerce_payment_gateways', 'wcpayplug_add_gateway' );

        function wcpayplug_add_gateway( $methods ) {
            $methods[] = 'WC_Gateway_Payplug';
            return $methods;
        }

        /**
         * PayPlug Payment Gateway
         *
         * Provides a PayPlug Payment Gateway.
         *
         * @class       WC_Gateway_Payplug
         * @extends     WC_Payment_Gateway
         */
        class WC_Gateway_Payplug extends WC_Payment_Gateway {

            /**
             * Constructor for the gateway.
             */
            public function __construct() {
                
                
                $this->id                  = 'woopayplug';
                $this->icon                = plugins_url( 'assets/images/payplug.png', __FILE__ );
                $this->has_fields          = false;
                $this->method_title        = __( 'PayPlug', 'woopayplug' );

                $this->notify_url   = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_Gateway_Payplug', home_url( '/' ) ) );

                // Load the form fields.
                $this->init_form_fields();

                // Load the settings.
                $this->init_settings();

                // Define user setting variables.
                $this->title                = $this->settings['title'];
                $this->description          = $this->settings['description'];
                $this->payplug_login        = $this->settings['payplug_login'];
                $this->payplug_password     = $this->settings['payplug_password'];
                $this->set_completed        = $this->settings['set_completed'];
                $this->parameters           = json_decode(rawurldecode($this->settings['payplug_parameters']), true);
                $this->payplug_privatekey   = $this->parameters['yourPrivateKey'];
                $this->payplug_url          = $this->parameters['url'];

                $this->payplug_publickey    = $this->parameters['payplugPublicKey'];
                
                // Actions.
                add_action( 'woocommerce_api_wc_gateway_payplug', array($this, 'check_ipn_response'));
                add_action( 'valid_payplug_ipn_request', array( &$this, 'successful_request' ) );
                add_action( 'woocommerce_receipt_payplug', array( &$this, 'receipt_page' ) );

                if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')){
                    add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
                }else{
                    add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
                }
            }

            /**
             * Checking if this gateway is enabled.
             */
            public function is_valid_for_use() {
                if (!in_array(get_woocommerce_currency(), array('EUR'))){
                    return false;
                }
                return true;
            }

            /**
             * Add specific infos on admin page.
             */
            public function admin_options() {
                wp_enqueue_style( 'wcpayplugStylesheet', plugins_url('assets/css/payplug.css', __FILE__) );
                ?>
                <div id="wc_get_started" class="payplug">
                    <?php _e( "<span>Add online payment by bank card to your website in a matter of clicks.</span><br/><b>No fixed monthly fees.</b> No customer account required to make a payment.<br>" , 'woopayplug' ); ?>
                    <div class="bt_hld">
                        <p>
                            <a href="http://www.payplug.com/signup" target="_blank" class="button button-primary"><?php _e( "Create a free account" , 'woopayplug' ); ?></a>
                            <a href="http://www.payplug.com/" target="_blank" class="button"><?php _e( "Find out more about PayPlug" , 'woopayplug' ); ?></a>
                        </p>
                    </div>

                </div>
                <table class="form-table parameters">
                    <?php $this->generate_settings_html(); ?>
                </table>
                <?php
                // load required javascript with parameters
                wp_enqueue_script('payplug-script', plugins_url('assets/js/payplug.js', __FILE__) );
                $js_params = array(
                  'checking' => __( 'Checking PayPlug settings...', 'woopayplug' ),
                  'warning_manual_setting' => __( "If you aren’t using your PayPlug login and password, run a test order to ensure PayPlug is functioning correctly.", 'woopayplug' ),
                  'error_connecting' => __( 'An error occurred while connecting to PayPlug.', 'woopayplug' ),
                  'error_login' => __( 'Please check your PayPlug login and password.', 'woopayplug' ),
                  'error_unknown' => __( 'An error has occurred. Please contact us at http://wba.fr', 'woopayplug' ),
                  'url' => site_url().'/?payplug=parameters'
                );
                wp_localize_script( 'payplug-script', 'PayPlugJSParams', $js_params );
            }

            /**
             * Start Gateway Settings Form Fields.
             */
            public function init_form_fields() {

                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __( 'Enable/Disable', 'woopayplug' ),
                        'type' => 'checkbox',
                        'label' => __( 'Enable PayPlug', 'woopayplug' ),
                        'description' => __( 'NB: This payment gateway can only be enabled if the currency used by the store is the euro.', 'woopayplug' ),
                        'default' => 'yes'
                    ),
                    'test_mode' => array(
                        'title' => __( 'Mode test', 'woopayplug' ),
                        'type' => 'checkbox',
                        'label' => __( 'Use PayPlug in TEST (Sandbox) Mode', 'woopayplug' ),
                        'default' => 'no',
                        'description' => __('Use test mode to test transactions with no real payment required', 'woopayplug'),
                    ),
                    'title' => array(
                        'title' => __( 'Title', 'woopayplug' ),
                        'type' => 'text',
                        'description' => __( 'This field defines the title seen by the user upon payment.', 'woopayplug' ),
                        'default' => __( 'PayPlug', 'woopayplug' )
                    ),
                    'description' => array(
                        'title' => __( 'Description', 'woopayplug' ),
                        'type' => 'textarea',
                        'description' => __( 'This field defines the description seen by the user upon payment.', 'woopayplug' ),
                        'default' => __( 'Make secure payments using your bank card with PayPlug.', 'woopayplug' )
                    ),
                    'payplug_login' => array(
                        'title' => __( 'PayPlug login', 'woopayplug' ),
                        'type' => 'text',
                        'description' => __( 'The email address used to log on to PayPlug.', 'woopayplug' ),
                        'default' => ''
                    ),
                    'payplug_password' => array(
                        'title' => __( 'PayPlug password', 'woopayplug' ),
                        'type' => 'password',
                        'description' => __( 'The password used to log on to PayPlug. This information is not saved.', 'woopayplug' ),
                        'default' => ''
                    ),
                    'set_completed' => array(
                        'title' => __( 'Mark the order as ‘completed’', 'woopayplug' ),
                        'type' => 'checkbox',
                        'label' => __("Mark the order as ‘completed’ upon payment confirmation by PayPlug instead of ‘in progress’", 'woopayplug'),
                        'default' => 'no',
                    ),
                    'payplug_parameters' => array(
                        'title' => '',
                        'type' => 'hidden',
                        'default' => '',
                        'description' => ''
                    )
                );
            }
            /**
             * Get PayPlugArgs
             */
            function get_payplug_url( $order_id ) {
                
                $order = new WC_Order( $order_id );
                $order_id = $order->id;

                $params = array(
                        'amount'        => number_format($order->order_total, 2, '.', '')*100,
                        'currency'      => get_woocommerce_currency(),
                        'ipn_url'       => $this->notify_url,
                        'return_url'    => $this->get_return_url($order),
                        'email'         => $order->billing_email,
                        'firstname'     => $order->billing_first_name,
                        'lastname'      => $order->billing_last_name,
                        'order'         => $order->id
                    );
                $url_params = http_build_query($params);
                
                $data = urlencode(base64_encode($url_params));
                $privatekey = openssl_pkey_get_private($this->payplug_privatekey);
                openssl_sign($url_params, $signature, $privatekey, OPENSSL_ALGO_SHA1);
                $signature = urlencode(base64_encode($signature));
                $payplug_url = $this->payplug_url ."?data=".$data."&sign=".$signature;
                $payplug_url = apply_filters( 'woocommerce_payplug_args', $payplug_url );
                return $payplug_url;
            }

            /**
             * Process the payment and return the result.
             */
            public function process_payment( $order_id ) {
                
                $payplug_adr = $this->get_payplug_url($order_id);
                return array(
                    'result'    => 'success',
                    'redirect'  => $payplug_adr
                );
            }

            /**
             * Output for the order received page.
             */
            public function receipt_page( $order ) {
                echo $this->generate_payplug_form( $order );
            }

            
            /**
             * Check ipn validation.
             */
            public function check_ipn_request_is_valid($headers, $body) {

                $signature = base64_decode($headers['PAYPLUG-SIGNATURE']);
                
                $publicKey = openssl_pkey_get_public($this->payplug_publickey);
                $isValid = openssl_verify($body , $signature, $publicKey, OPENSSL_ALGO_SHA1);
                if($isValid){
                    return True;
                }
                return False;
            }

            /**
             * Check API Response.
             */
            public function check_ipn_response() {
                $headers = getallheaders();
                $headers = array_change_key_case($headers, CASE_UPPER);
                $body = file_get_contents('php://input');
                $data = json_decode($body, true);
                
                @ob_clean();

                if (!empty($headers) && !empty($body) && $this->check_ipn_request_is_valid($headers, $body)){
                    header( 'HTTP/1.1 200 OK' );
                    do_action( "valid_payplug_ipn_request", $data);
                } else {
                    wp_die( "PayPlug IPN Request Failure" );
                }
            }


            /**
             * Successful Payment.
             */
            public function successful_request( $posted ) {
                

                $order_id = $posted['order'];
                $posted_status = $posted['state'];

                $order = new WC_Order($order_id);


                if($posted_status == 'paid'){

                    // Check order not already completed
                    if ($order->status == 'completed'){
                        exit;
                    }

                    if($this->set_completed == 'yes'){
                        $order->update_status('completed');
                    }
                    // Payment completed
                    // Reduce stock levels
                    $order->reduce_order_stock();
                    $order->add_order_note(__('Payment successfully completed', 'woocommerce'));
                    $order->payment_complete();
                    exit;
                }

                if($posted_status == 'refunded'){
                    $order->update_status( 'refunded', __( 'Payment refunded via IPN by PayPlug', 'woopayplug' ) );
                    $mailer = WC()->mailer();
                    $message = $mailer->wrap_message(
                        __( 'Order refunded', 'woocommerce' ),
                        sprintf( __( 'The order %s has been refunded via IPN by PayPlug', 'woopayplug' ), $order->get_order_number())
                    );
                    $mailer->send( get_option( 'admin_email' ), sprintf( __( 'Payment for order %s has been refunded', 'woocommerce' ), $order->get_order_number() ), $message );
                    exit;
                }
                
            }
        }
    }


    /*
    *
    * Get PayPlug parameters from login and password
    *
    */
    function payplug_parse_request($wp) {
        if ( array_key_exists( 'payplug', $wp->query_vars ) && ( $wp->query_vars['payplug'] == 'parameters' ) ) {
           if ( $_POST["test_mode"] == 'true' ) {
               $url = 'https://www.payplug.fr/portal/test/ecommerce/autoconfig';
           } else {
               $url = 'https://www.payplug.fr/portal/ecommerce/autoconfig';
           }
           $args = array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $_POST["login"] . ':' . $_POST["password"] )
               )
           );
           $answer = wp_remote_request( $url, $args );
           $jsonAnswer = json_decode( $answer['body'] );
           if( $jsonAnswer->status == 200 ) {
               die($answer['body']);
           } else {
               die("errorlogin");
           }
           die();
        }
    }
    add_action('parse_request', 'payplug_parse_request');

    function payplug_query_vars($vars) {
        $vars[] = 'payplug';
        return $vars;
    }
    add_filter('query_vars', 'payplug_query_vars');
}
