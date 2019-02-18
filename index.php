<?php
/**
 * Plugin Name: BitPay Checkout Plugin
 * Plugin URI: http://www.bitpay.com
 * Description: Create Invoices and process through BitPay.  Configure in your <a href ="admin.php?page=wc-settings&tab=checkout&section=bitpay_gateway">WooCommerce->Payments plugin</a>.
 * Version: 1.0
 * Author: Joshua Lewis
 * Author URI: http://www.bitpay.com
 */

global $current_user;
error_log(plugins_url('bitpay.css',__FILE__ ));

function bitpay_css() {
    wp_register_style('bitpay_css', plugins_url('/bitpay.css',__FILE__ ));
    wp_enqueue_style('bitpay_css');
   # wp_register_script( 'your_namespace', plugins_url('your_script.js',__FILE__ ));
   # wp_enqueue_script('your_namespace');
}

add_action( 'init','bitpay_css');


add_action('plugins_loaded', 'wc_bitpay_gateway_init', 11);

#create the table if it doesnt exist
function bitpay_install() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'bitpay_transactions';
	
	$charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table_name(
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `order_id` int(11) NOT NULL,
        `transaction_id` varchar(255) NOT NULL,
        `transaction_status` varchar(50) NOT NULL DEFAULT 'new',
        `date_added` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
        ) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );

}
register_activation_hook( __FILE__, 'bitpay_install' );

function insert_bitpay($order_id,$transaction_id){
    global $wpdb;
    $table_name = $wpdb->prefix . 'bitpay_transactions';
    
    $wpdb->insert( 
	$table_name, 
        array( 
            'order_id' => $order_id, 
            'transaction_id' => $transaction_id, 
        ) 
    );
}

function update_bitpay($order_id,$transaction_id,$transaction_status){
    global $wpdb;
    $table_name = $wpdb->prefix . 'bitpay_transactions';
    $wpdb->update($table_name,array( 'transaction_status' => $transaction_status),array("order_id" => $order_id,'transaction_id'=>$transaction_id));
}

function get_bitpay($order_id,$transaction_id){
    global $wpdb;
    $table_name = $wpdb->prefix . 'bitpay_transactions';
    $rowcount = $wpdb->get_var( "SELECT COUNT(order_id) FROM $table_name WHERE order_id = '$order_id' 
    AND transaction_id = '$transaction_id' LIMIT 1" );
    return $rowcount;
    
}

function delete_bitpay($order_id){
    global $wpdb;
    $table_name = $wpdb->prefix . 'bitpay_transactions';
    $wpdb->query("DELETE FROM $table_name WHERE order_id = '$order_id'");
              
}



function wc_bitpay_gateway_init()
{
    class WC_Gateway_BitPay extends WC_Payment_Gateway
    {

        public function __construct()
        {
            $bitpay_options = get_option('woocommerce_bitpay_gateway_settings');
            #print_r($bitpay_options);

            $this->id = 'bitpay_gateway';
            #$this->icon = getLogo($bitpay_options['bitpay_endpoint']);
            #$this->icon = getLogo();
            $this->has_fields = true;
            $this->method_title = __('BitPay Checkout', 'wc-bitpay');
            $this->method_label = __('BitPay Checkout', 'wc-bitpay');
            $this->method_description = __('Expand your payment options by accepting instant BTC and BCH payments without risk or price fluctuations.', 'wc-bitpay');

            if (empty($_GET['woo-bitpay-return'])) {
                $this->order_button_text = __('Pay with BitPay', 'woocommerce-gateway-bitpay_gateway');
               
            }

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description').'<br>';
            $this->description.= '<img src="'.getLogo().'" style = "cursor:pointer;min-height: 150px;margin-top: 25px;margin-bottom: 25px;" alt="BitPay" onclick = "jQuery(\'#place_order\').click();">';
            /*
            */
            //<img src="//bitpay.com/cdn/en_US/bp-btn-pay-currencies.svg" alt="BitPay">
            $this->instructions = $this->get_option('instructions', $this->description);

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

            // Customer Emails
            add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'label' => __('Enable BitPay Checkout', 'woocommerce'),
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no',
                ),
                'bitpay_info' => array(
                    'description' => __('You should not ship any products until BitPay has finalized your transaction.<br>The order will stay in a <b>Hold</b> and/or <b>Processing</b> state, and will automatically change to <b>Completed</b> after the payment has been confirmed.', 'woocommerce'),
                    'type' => 'title',
                ),

                'bitpay_merchant_info' => array(
                    'description' => __('If you have not created a BitPay Merchant Token, you can create one on your BitPay Dashboard.<br><a href = "https://test.bitpay.com/dashboard/merchant/api-tokens" target = "_blank">(Test)</a>  or <a href= "https://www.bitpay.com/dashboard/merchant/api-tokens" target = "_blank">(Production)</a> </p>', 'woocommerce'),
                    'type' => 'title',
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Pay With BitPay', 'woocommerce'),

                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This is the message box that will appear on the <b>checkout page</b> when they select BitPay.', 'woocommerce'),
                    'default' => 'Checkout with BitPay',

                ),

                'bitpay_token_dev' => array(
                    'title' => __('Development Token', 'woocommerce'),
                    'label' => __('Development Token', 'woocommerce'),
                    'type' => 'text',
                    'description' => 'Your <b>development</b> merchant token.  <a href = "https://test.bitpay.com/dashboard/merchant/api-tokens" target = "_blank">Create one here</a> and <b>uncheck</b> `Require Authentication`.',
                    'default' => '',

                ),
                'bitpay_token_prod' => array(
                    'title' => __('Production Token', 'woocommerce'),
                    'label' => __('Production Token', 'woocommerce'),
                    'type' => 'text',
                    'description' => 'Your <b>production</b> merchant token.  <a href = "https://www.bitpay.com/dashboard/merchant/api-tokens" target = "_blank">Create one here</a> and <b>uncheck</b> `Require Authentication`.',
                    'default' => '',

                ),

                'bitpay_endpoint' => array(
                    'title' => __('Endpoint', 'woocommerce'),
                    'type' => 'select',
                    'description' => __('Select <b>Test</b> for testing the plugin, <b>Production</b> when you are ready to go live.'),
                    'options' => array(
                        'production' => 'Production',
                        'test' => 'Test',
                    ),
                    'default' => 'test',
                ),
                'bitpay_currency' => array(
                    'title' => __('Accepted Cryptocurrencies', 'woocommerce'),
                    'type' => 'select',
                    'description' => __('Bitcoin (BTC), Bitcoin Cash (BCH), or All.'),
                    'options' => array(
                        '1' => '--All--',
                        'BTC' => 'BTC',
                        'BCH' => 'BCH',
                    ),
                    'default' => '1',
                ),

                'bitpay_flow' => array(
                    'title' => __('Checkout Flow', 'woocommerce'),
                    'type' => 'select',
                    'description' => __('If this is set to <b>Redirect</b>, then the customer will be redirected to <b>BitPay</b> to checkout, and return to the checkout page once the payment is made.<br>If this is set to <b>Modal</b>, the user will stay on <b>' . get_bloginfo('name', null) . '</b> and complete the transaction.', 'woocommerce'),
                    'options' => array(
                        '1' => 'Modal',
                        '2' => 'Redirect',
                    ),
                    'default' => '2',
                ),

                'bitpay_brand' => array(
                    'title' => __('Branding', 'woocommerce'),
                    'type' => 'select',
                    'description' => __('Choose from one of our branded buttons<br>'.getBrands(), 'woocommerce'),
                    'options' => getBrandOptions()
                ),
                'bitpay_ux' => array(
                    'title' => __('Auto-Resize Buttons', 'woocommerce'),
                    'type' => 'select',
                    'description' => __('If <b>Yes</b>, the plugin will attempt to automatically resize the branding button on the checkout page.  If <b>No</b>, then you will need to edit your CSS file.', 'woocommerce'),
                    'options' => array(
                        '1' => 'Yes',
                        '0' => 'No',
                      
                    ),
                    'default' => '1',
                ),
                'bitpay_capture_email' => array(
                    'title' => __('Auto-Capture Email', 'woocommerce'),
                    'type' => 'select',
                    'description' => __('Should BitPay Checkout try to auto-add the client\'s email address?  If <b>Yes</b>, the client will not be able to change the email address on the BitPay Checkout invoice.  If <b>No</b>, they will be able to add their own email address when paying the invoice.', 'woocommerce'),
                    'options' => array(
                        '1' => 'Yes',
                        '0' => 'No',
                      
                    ),
                    'default' => '1',
                ),
                'bitpay_checkout_message' => array(
                    'title' => __('Checkout Message', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Insert your custom message for the <b>Order Received</b> page, so the customer knows that the order will not be completed until BitPay releases the funds.', 'woocommerce'),
                    'default' => 'Thank you.  We will notify you when BitPay has processed your transaction.',
                ),
            );
        }

        function process_payment($order_id)
        {
            #this is the one that is called intially when someone checks out
            global $woocommerce;
            $order = new WC_Order($order_id);

            // Mark as on-hold (we're awaiting the cheque)
            $order->update_status('on-hold', __('Awaiting BitPay payment', 'woocommerce'));
            // Return thankyou redirect
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }
    } // end \WC_Gateway_Offline class
}

//this is an error message incase a token isnt set
add_action('admin_notices', 'no_token_set');

function no_token_set()
{
    
    //lookup the token based on the environment
    $bitpay_options = get_option('woocommerce_bitpay_gateway_settings');
    //dev or prod token
    $bitpay_token = getToken($bitpay_options['bitpay_endpoint']);
    $bitpay_endpoint = $bitpay_options['bitpay_endpoint'];
    if (empty($bitpay_token)): ?>

   
    <div class="error notice">
        <p>
            <?php _e('There is no token set for your <b>' . strtoupper($bitpay_endpoint) . '</b> environment.  <b>BitPay Checkout</b> will not function if this is not set.');?>
        </p>
    </div>
<?php 
##check and see if the token is valid
else: 
    if($_POST && !empty($bitpay_token) && !empty($bitpay_endpoint)){
         if(!checkToken($bitpay_token,$bitpay_endpoint)):?>
        <div class="error notice">
        <p>
            <?php _e('The token for <b>'.strtoupper($bitpay_endpoint).'</b> is invalid.  Please verify your settings.');?>
        </p>
    </div>
         <?php endif;
    } 
   
?>    
<?php endif;
}


//http://bp.local.wpbase.com/wp-json/bitpay/ipn/status
add_action('rest_api_init', function () {
    register_rest_route('bitpay/ipn', '/status', array(
        'methods' => 'POST,GET',
        'callback' => 'bitpay_ipn',
    ));
    register_rest_route('bitpay/cartfix', '/restore', array(
        'methods' => 'POST,GET',
        'callback' => 'bitpay_cart_restore',
    ));
});

function bitpay_cart_restore(WP_REST_Request $request)
{
    global $woocommerce;
    $data = $request->get_params();
    $order_id = $data['orderid'];
    $order = new WC_Order($order_id);
    $items = $order->get_items();

    //clear the cart first so things dont double up

    $woocommerce->cart->empty_cart();
    foreach ($items as $item) {
        //now insert for each quantity
        $item_count = $item->get_quantity();
        for ($i = 0; $i < $item_count; $i++):
            WC()->cart->add_to_cart($item->get_product_id());
        endfor;
    }
    //delete the previous order
    wp_delete_post($order_id, true);
    delete_bitpay($order_id);
    setcookie("bitpay-invoice-id", "", time() - 3600);
}

//http://bp.local.wpbase.com/wp-json/bitpay/ipn/status
function bitpay_ipn(WP_REST_Request $request)
{
    global $woocommerce;

    $data = $request->get_body();
  
    #$data = json_decode($data);
    $data = json_decode($data);
    $event = $data->event;
    $data = $data->data;
   #print_r($data);die();

    $orderid = $data->orderId;
    $order_status = $data->status;
    $invoiceID = $data->id;
    #verify the ipn matches the status of the actual invoice

   if(get_bitpay($orderid,$invoiceID)):
        require 'classes/Config.php';
        require 'classes/Client.php';
        require 'classes/Item.php';
        require 'classes/Invoice.php';
    
        $bitpay_options = get_option('woocommerce_bitpay_gateway_settings');
        //dev or prod token
        $bitpay_token = getToken($bitpay_options['bitpay_endpoint']);
        $config = new Configuration($bitpay_token, $bitpay_options['bitpay_endpoint']);
        $bitpay_endpoint = $bitpay_options['bitpay_endpoint'];

        $params = new stdClass();
        $params->extension_version = getInfo();
        $params->invoiceID = $invoiceID;

        $item = new Item($config, $params);

        $invoice = new Invoice($item); //this creates the invoice with all of the config params
        $orderStatus = json_decode($invoice->checkInvoiceStatus($invoiceID));
        
        #update the lookup table
        update_bitpay($orderid,$invoiceID,$order_status);

        switch($event->name){
        case 'invoice_completed':
        $order = new WC_Order($orderid);
        //private order note with the invoice id
        $order->add_order_note('BitPay Invoice ID: <a target = "_blank" href = "'.getDashboardLink($bitpay_endpoint,$invoiceID).'">' . $invoiceID.'</a> processing has been completed.' );
        // Mark as on-hold (we're awaiting the cheque)
        $order->update_status('completed', __('BitPay payment complete', 'woocommerce'));
        // Reduce stock levels
        $order->reduce_order_stock();

        // Remove cart
        $woocommerce->cart->empty_cart();
        break;

        case 'invoice_confirmed':
        case 'invoice_paidInFull':
        default:
        $order = new WC_Order($orderid);
        //private order note with the invoice id
        $order->add_order_note('BitPay Invoice ID: <a target = "_blank" href = "'.getDashboardLink($bitpay_endpoint,$invoiceID).'">' . $invoiceID.'</a> is now processing.');
        // Mark as on-hold (we're awaiting the cheque)
        $order->update_status('processing', __('BitPay payment processing', 'woocommerce'));
        break;

        case 'invoice_failedToConfirm':
        $order = new WC_Order($orderid);
        //private order note with the invoice id
        $order->add_order_note('BitPay Invoice ID: <a target = "_blank" href = "'.getDashboardLink($bitpay_endpoint,$invoiceID).'">' . $invoiceID.'</a> has become invalid because of network congestion.  Order will automatically update when the status changes.');
        // Mark as on-hold (we're awaiting the cheque)
        $order->update_status('failed', __('BitPay payment invalid', 'woocommerce'));
        break;
        case 'invoice_expired':
        $order = new WC_Order($orderid);
        //private order note with the invoice id
        $order->add_order_note('BitPay Invoice ID: <a target = "_blank" href = "'.getDashboardLink($bitpay_endpoint,$invoiceID).'"> has been cancelled.' . $invoiceID.'</a>');
        // Mark as on-hold (we're awaiting the cheque)
        $order->update_status('cancelled', __('BitPay payment cancelled', 'woocommerce'));
        break;
    }
   die();
   endif;
}

add_action('template_redirect', 'woo_custom_redirect_after_purchase');
function woo_custom_redirect_after_purchase()
{
    global $wp;
    $bitpay_options = get_option('woocommerce_bitpay_gateway_settings');
    $bitpay_token = getToken($bitpay_options['bitpay_endpoint']);

    if (is_checkout() && !empty($wp->query_vars['order-received'])) {

        require 'classes/Config.php';
        require 'classes/Client.php';
        require 'classes/Item.php';
        require 'classes/Invoice.php';
        $order_id = $wp->query_vars['order-received'];
        $order = new WC_Order($order_id);
        $order->update_status('pending-payment', __('BitPay payment pending', 'woocommerce'));

        //this means if the user is using bitpay AND this is not the redirect
        $show_bitpay = true;

        if (isset($_GET['redirect']) && $_GET['redirect'] == 'false'):
            $show_bitpay = false;
            $invoiceID = $_COOKIE['bitpay-invoice-id'];

            //clear the cookie
            setcookie("bitpay-invoice-id", "", time() - 3600);
            #updateOrderStatus($invoiceID, $order_id);
        endif;

        if ($order->payment_method == 'bitpay_gateway' && $show_bitpay == true):
            $config = new Configuration($bitpay_token, $bitpay_options['bitpay_endpoint']); 
            //sample values to create an item, should be passed as an object'
            $params = new stdClass();
            #$params->fullNotifications = 'true';
            $params->extension_version = getInfo();
            $params->price = $order->total;
            $params->currency = $order->currency; //set as needed
            if($bitpay_options['bitpay_capture_email'] == 1):
                $current_user = wp_get_current_user();
                
                if($current_user->user_email):
                    $params->buyers_email = $current_user->user_email;
                endif;
            endif;
           

            //use other fields as needed from API Doc
            //which crytpo does the merchant accept? set it in the config
            $bitpay_currency = $bitpay_options['bitpay_currency'];
            switch ($bitpay_currency) {
                default:
                case 1:
                    break;
                case 'BTC':
                    $params->buyerSelectedTransactionCurrency = 'BTC';
                    break;
                case 'BCH':
                    $params->buyerSelectedTransactionCurrency = 'BCH';
                    break;
            }
            //orderid
            $params->orderId = trim($order_id);
            //redirect and ipn stuff
            $params->redirectURL = get_home_url() . '/checkout/order-received/' . $order_id . '/?key=' . $order->order_key . '&redirect=false';

            $params->notificationURL = get_home_url() . '/wp-json/bitpay/ipn/status';
            //http://bp.local.wpbase.com/wp-json/bitpay/ipn/status
            $params->extendedNotifications = true;

            $item = new Item($config, $params);
            $invoice = new Invoice($item);

            //this creates the invoice with all of the config params from the item
            $invoice->createInvoice();
            $invoiceData = json_decode($invoice->getInvoiceData());
            //now we have to append the invoice transaction id for the callback verification
            error_log(print_r($invoice,true));
            error_log('-----------------');
             error_log(print_r($invoiceData,true));
            $invoiceID = $invoiceData->data->id;
            //set a cookie for redirects and updating the order status
            $cookie_name = "bitpay-invoice-id";
            $cookie_value = $invoiceID;
            setcookie($cookie_name, $cookie_value, time() + (86400 * 30), "/");

            $bitpay_options = get_option('woocommerce_bitpay_gateway_settings');
            $use_modal = intval($bitpay_options['bitpay_flow']);

            #insert into the database
            insert_bitpay($order_id,$invoiceID);

            //use the modal if '1', otherwise redirect
            if ($use_modal == 2):
                wp_redirect($invoice->getInvoiceURL());
            else:
                wp_redirect($params->redirectURL);

            endif;
            exit;
        endif;
    }
}


// Replacing the Place order button when total volume exceed 68 m3
add_filter( 'woocommerce_order_button_html', 'replace_order_button_html', 10, 2 );
function replace_order_button_html( $order_button,$override = false ) {
    // Only when total volume is up to 68
    
    if($override):
        return;
    else:
        return $order_button;
    endif;
}

function getInfo(){
    $plugin_data = get_file_data(__FILE__, array('Version' => 'Version','Plugin Name' => 'Plugin Name'), false);
    $plugin_version = $plugin_data['Plugin Name'].' - '.$plugin_data['Version'];
    return $plugin_version;
}
//retrieves the token based on the endpoint

function getDashboardLink($endpoint,$invoiceID)
{     //dev or prod token
    switch ($endpoint) {
        case 'test':
        default:
            return '//test.bitpay.com/dashboard/payments/'.$invoiceID;
            break;
        case 'production':
        return '//bitpay.com/dashboard/payments/'.$invoiceID;
        break;
    }
}


function getBrandOptions(){
    require_once 'classes/Buttons.php';
    $buttonObj = new Buttons;
    $buttons = json_decode($buttonObj->getButtons());
   /*
   'options' => array(
                        '1' => 'Yes',
                        '0' => 'No',
                      
                    ),
   */
    $output = [];
    $x = 0;
     foreach($buttons->data as $key=>$b):  
       
        $names = preg_split('/(?=[A-Z])/',$b->name);
        $names = implode(" ",$names);
        $names = ucwords($names);

        $output['//'.$b->url] = $names;
        $x++;
     endforeach;
    return $output;
   
}


#brand returned from API
function getBrands(){
    require_once 'classes/Buttons.php';
    $buttonObj = new Buttons;
    $buttons = json_decode($buttonObj->getButtons());
    $brand = '<div>';
    foreach($buttons->data as $key=>$b):  
        $names = preg_split('/(?=[A-Z])/',$b->name);
        $names = implode(" ",$names);
        $names = ucwords($names);
        $brand.= '<figure style = "float:left;"><img src = "//'.$b->url.'"  style = "width:150px;padding:1px;">';
        $brand.= '<figcaption style = "text-align:left;font-style:italic"><b>'.$names.'</b><br>'.$b->description.'</figcaption>';
        $brand.='</figure>';
    endforeach;

    $brand.= '</div>';
    return $brand;

}

function getLogo($endpoint = null)
{  
    require_once 'classes/Buttons.php';
    $buttonObj = new Buttons;
    $buttons = $buttonObj->getButtons();
    $bitpay_options = get_option('woocommerce_bitpay_gateway_settings');
    $brand = $bitpay_options['bitpay_brand'];
    if($brand == ''):
        return  $buttons[0];
    else:
        return $brand;
    endif;
    
}

function getToken($endpoint)
{
    $bitpay_options = get_option('woocommerce_bitpay_gateway_settings');
    //dev or prod token
    switch ($bitpay_options['bitpay_endpoint']) {
        case 'test':
        default:
            return $bitpay_options['bitpay_token_dev'];
            break;
        case 'production':
            return $bitpay_options['bitpay_token_prod'];
            break;
    }

}

function checkToken($bitpay_token,$bitpay_endpoint){
   
    require 'classes/Config.php';
    require 'classes/Client.php';
    require 'classes/Item.php';
    require 'classes/Invoice.php';
    
    #we're going to see if we can create an invoice
    $config = new Configuration($bitpay_token, $bitpay_endpoint); 
    //sample values to create an item, should be passed as an object'
    $params = new stdClass();
    $params->extension_version = getInfo();
    $params->price = '.50';
    $params->currency = 'USD'; //set as needed

    $item = new Item($config, $params);
    $invoice = new Invoice($item);

    //this creates the invoice with all of the config params from the item
    $invoice->createInvoice();
    $invoiceData = json_decode($invoice->getInvoiceData());
    //now we have to append the invoice transaction id for the callback verification
    $invoiceID = $invoiceData->data->id;
    if(empty($invoiceID)):
       return false;
    else:
       return true;
    endif;   
}

//hook into the order recieved page and re-add to cart of modal canceled
add_action('woocommerce_thankyou', 'bitpay_thankyou', 10, 1);
function bitpay_thankyou($order_id)
{
   
    global $woocommerce;
    $order = new WC_Order($order_id);

    $bitpay_options = get_option('woocommerce_bitpay_gateway_settings');
    $use_modal = intval($bitpay_options['bitpay_flow']);
    $bitpay_test_mode = $bitpay_options['bitpay_endpoint'];
    $test_mode = false;
    $restore_url = get_home_url() . '/wp-json/bitpay/cartfix/restore';
    $cart_url = get_home_url() . '/cart';
    

    if ($bitpay_test_mode == 'test'):
        $test_mode = true;
    endif;

    //use the modal,
    if ($order->payment_method == 'bitpay_gateway' && $use_modal == 1):
        $invoiceID = $_COOKIE['bitpay-invoice-id'];
        ?>
	<script src="//bitpay.com/bitpay.js"></script>
	<script type='text/javascript'>
	    jQuery("#primary").hide()
	    var payment_status = null;
	    window.addEventListener("message", function (event) {
	        payment_status = event.data.status;
	    }, false);
	    //hide the order info
	    bitpay.onModalWillEnter(function () {
	        jQuery("primary").hide()
	    });
	    //show the order info
	    bitpay.onModalWillLeave(function () {
	        if (payment_status == 'paid') {
	            jQuery("#primary").fadeIn("slow");
	        } else {
	            var myKeyVals = {
	                orderid: '<?php echo $order_id; ?>'
	            }
	            var redirect = '<?php echo $cart_url;?>';
	            var api = '<?php echo $restore_url;?>';
	            var saveData = jQuery.ajax({
	                type: 'POST',
	                url: api,
	                data: myKeyVals,
	                dataType: "text",
	                success: function (resultData) {
	                    window.location = redirect;
	                }
	            });
	        }
	    });
	    //show the modal
	    bitpay.enableTestMode(<?php echo $test_mode; ?>)
	    bitpay.showInvoice('<?php echo $invoiceID; ?>');
	</script>
	<?php
endif;
}

add_action('woocommerce_review_order_before_payment', 'hide_place_order');
function hide_place_order(){
?>
<script type = "text/javascript">
        jQuery(document).ready(function(){
            var $payment_method;
             jQuery("input:radio").attr("checked", false);
            var paymentCheck =  setInterval(function(){ 
                var blockOverlay = jQuery(".blockOverlay").css("display");
                    if(blockOverlay == undefined){
                          
                            clearInterval(paymentCheck);
                        
                    }
                    jQuery("#place_order").css('opacity',1)
                    console.log('aaa')
                    },100);

             jQuery('form[name="checkout"]').change(function(){
               $payment_method =  jQuery('input[name=payment_method]:checked').val();
              if($payment_method == 'bitpay_gateway'){
                   jQuery("#place_order").css('opacity',0)

              }else{
                  jQuery("#place_order").css('opacity',1)

              }


        });
       
    });
    
    
</script>
<?php
}

add_action('woocommerce_review_order_before_payment', 'bitpay_button_fix');
function bitpay_button_fix()
{
    $bitpay_options = get_option('woocommerce_bitpay_gateway_settings');
    //dev or prod token
    $bitpay_ux = $bitpay_options['bitpay_ux'];
    if($bitpay_ux):
    echo '<script type "text/javascript">';
    echo 'var img = jQuery("#payment .payment_methods li img");';
    echo 'setTimeout(function(){ ';
    echo 'var img = jQuery("#payment .payment_methods li img");';
    echo 'img.css("max-height","50px");return;';
    echo'  }, 900);';
    echo '</script>';
    endif;
  
}

//custom info for bitpay checkout
add_action('woocommerce_thankyou', 'bitpay_custom_message');
function bitpay_custom_message()
{
    $bitpay_options = get_option('woocommerce_bitpay_gateway_settings');
    echo '<hr><b>' . $bitpay_options['bitpay_checkout_message'] . '</b>';
}

//add the gatway to woocommerce
add_filter('woocommerce_payment_gateways', 'wc_bitpay_add_to_gateways');
function wc_bitpay_add_to_gateways($gateways)
{
    $gateways[] = 'WC_Gateway_BitPay';
    return $gateways;
}

?>
