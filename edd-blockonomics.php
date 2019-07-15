<?php
/**
 * Plugin Name: EDD - Blockonomics
 * Description: Accept Bitcoin Payments on your Easy Digital Downloads powered website with Blockonomics
 * Version: 1.1
 * Author: Blockonomics
 * Author URI: https://www.blockonomics.co
 * License: MIT
 * Text Domain: edd-blockonomics
 * Domain Path: /languages/
 */

/*  Copyright 2017 Blockonomics Inc.

MIT License

Permission is hereby granted, free of charge, to any person obtaining
a copy of this software and associated documentation files (the
"Software"), to deal in the Software without restriction, including
without limitation the rights to use, copy, modify, merge, publish,
distribute, sublicense, and/or sell copies of the Software, and to
permit persons to whom the Software is furnished to do so, subject to
the following conditions:

The above copyright notice and this permission notice shall be
included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class EDD_Blockonomics
{
  public function __construct()
  {
    if( ! function_exists( 'edd_get_option' ) )
    {
      return;
    }

    if( class_exists( 'EDD_License' ) && is_admin() )
    {
      $license = new EDD_License( __FILE__, 'Blockonomics Payment Gateway', '1.0.0', 'Blockonomics' );
    }

    $this->includes();
    $this->generate_secret_and_callback();

    add_action( 'init',                         array( $this, 'textdomain' ) );
    add_action( 'edd_gateway_blockonomics',         array( $this, 'process_payment' ) );
    add_action( 'init',                         array( $this, 'listener' ) );
    add_action( 'edd_blockonomics_cc_form',         '__return_false' );
    add_action( 'admin_notices',        array($this, 'edd_admin_messages') );
    //Ajax for user checkouts through Woocommerce
    add_action( 'wp_ajax_save_uuid', array($this, 'save_uuid') );
    add_action( 'wp_ajax_send_email', array($this, 'refund_email') );
    //Ajax for guest checkouts through Woocommerce
    add_action( 'wp_ajax_nopriv_save_uuid', array($this, 'save_uuid') );
    add_action( 'wp_ajax_nopriv_send_email', array($this, 'refund_email') );

    add_filter( 'edd_payment_gateways',         array( $this, 'register_gateway' ) );
    add_filter( 'edd_currencies',               array( $this, 'currencies' ) );
    add_filter( 'edd_sanitize_amount_decimals', array( $this, 'btc_decimals' ) );
    add_filter( 'edd_format_amount_decimals',   array( $this, 'btc_decimals' ) );
    add_filter( 'edd_settings_gateways',        array( $this, 'settings' ) );
    add_filter( 'edd_settings_sections_gateways', array( $this, 'register_gateway_section') );
    add_filter( 'edd_accepted_payment_icons',  array($this, 'pw_edd_payment_icon'));
    add_filter( 'edd_view_order_details_payment_meta_after', array( $this, 'action_edd_view_order_details_payment_meta_after'), 10, 1 );
  }

  public function includes()
  {
    if( ! class_exists( 'BlockonomicsAPI' ) )
    {
      require_once( plugin_dir_path( __FILE__ ) . 'php/Blockonomics.php' );
    }
  }

  public function action_edd_view_order_details_payment_meta_after( $payment_id ) 
  {
    $payment = new EDD_Payment( $payment_id );
    $meta_data = $payment->get_meta();
    if ( !empty($meta_data['blockonomics_txid']) )
    {
?>
    <div class="edd-order-tx-id edd-admin-box-inside">
      <p>
        <span class="label"><?php _e( 'Bitcoin Transaction ID:', 'edd-blockonomics' ); ?></span>&nbsp;
        <span><?php echo $meta_data['blockonomics_txid']; ?></span>
      </p>
    </div>
<?php
    }

    if ( !empty($meta_data['bitcoin_address']) )
    {
?>
    <div class="edd-order-tx-id edd-admin-box-inside">
      <p>
        <span class="label"><?php _e( 'Bitcoin Address:', 'edd-blockonomics' ); ?></span>&nbsp;
        <span><?php echo $meta_data['bitcoin_address']; ?></span>
      </p>
    </div>
<?php
    }

    if ( !empty($meta_data['expected_btc_amount']) )
    {
?>
    <div class="edd-order-tx-id edd-admin-box-inside">
      <p>
        <span class="label"><?php _e( 'Expected BTC Amount:', 'edd-blockonomics' ); ?></span>&nbsp;
        <span><?php echo $meta_data['expected_btc_amount']; ?></span>
      </p>
    </div>
<?php
    }

    if ( !empty($meta_data['paid_btc_amount']) )
    {
?>
    <div class="edd-order-tx-id edd-admin-box-inside">
      <p>
        <span class="label"><?php _e( 'Actual BTC Amount:', 'edd-blockonomics' ); ?></span>&nbsp;
        <span><?php echo $meta_data['paid_btc_amount']; ?></span>
      </p>
    </div>
<?php
    }

    if ( !empty($meta_data['flyp_uuid']) )
    {
?>
    <div class="edd-order-tx-id edd-admin-box-inside">
      <p>
        <span class="label"><?php _e( 'Flyp uuid:', 'edd-blockonomics' ); ?></span>&nbsp;
        <span><?php echo $meta_data['flyp_uuid']; ?></span>
      </p>
    </div>
<?php
    }
  }
  
  public function edd_admin_messages() 
  {
    $edd_action = sanitize_key($_GET['edd-action']);
    $settings_updated = sanitize_key($_GET['settings-updated']);

    if ( $edd_action == 'testsetup' && empty($settings_updated) )
    {
      $setup_errors = $this->testSetup();

      if($setup_errors)
      {
        $message = $setup_errors;
        $type = 'error';
        add_settings_error( 'edd-blockonomics-notices', 'edd_blockonomics_setup_failed', $message , $type);
      }
      else
      {
        $message = __('Congrats ! Setup is all done', 'edd-blockonomics');
        $type = 'updated';
        add_settings_error( 'edd-blockonomics-notices', 'edd_blockonomics_setup_success', $message , $type);
      }
    }

    settings_errors( 'edd-blockonomics-notices' );
  } 

  public function textdomain()
  {
    // Set filter for plugin's languages directory
    $lang_dir = dirname( plugin_basename( __FILE__ ) ) . '/languages/';
    $lang_dir = apply_filters( 'edd_blockonomics_languages_directory', $lang_dir );

    // Traditional WordPress plugin locale filter
    $locale        = apply_filters( 'plugin_locale',  get_locale(), 'edd-blockonomics' );
    $mofile        = sprintf( '%1$s-%2$s.mo', 'edd-blockonomics', $locale );

    // Setup paths to current locale file
    $mofile_local  = $lang_dir . $mofile;
    $mofile_global = WP_LANG_DIR . '/edd-blockonomics/' . $mofile;

    if ( file_exists( $mofile_global ) )
    {
      load_textdomain( 'edd-blockonomics', $mofile_global );
    }
    elseif( file_exists( $mofile_local ) )
    {
      load_textdomain( 'edd-blockonomics', $mofile_local );
    }
    else
    {
      // Load the default language files
      load_plugin_textdomain( 'edd-blockonomics', false, $lang_dir );
    }
  }

  public function register_gateway( $gateways )
  {

    $gateways['blockonomics'] = array(
      'checkout_label'  => __( 'Bitcoin', 'edd-blockonomics' ),
      'admin_label'     => __( 'Blockonomics', 'edd-blockonomics' ),
      'supports'        => array( 'buy_now' )
    );

    return $gateways;
  }

  function pw_edd_payment_icon($icons)
  {
    $icon_url = plugins_url('img/bitcoin.png', __FILE__);
    $icons[$icon_url] = 'Bitcoin';
    return $icons;
  }

  public function register_gateway_section( $gateway_sections )
  {
    $gateway_sections['blockonomics'] = __( 'Blockonomics', 'edd-blockonomics' );
    return $gateway_sections;
  }

  function generate_secret_and_callback($generate_new = false)
  {
    $callback_secret = edd_get_option('edd_blockonomics_callback_secret', '');
    if ( empty( $callback_secret) || $generate_new )
    {
      $callback_secret = sha1(openssl_random_pseudo_bytes(20));
      edd_update_option("edd_blockonomics_callback_secret", $callback_secret);
    }

    $callback_url = add_query_arg( array( 'edd-listener' => 'blockonomics', 'secret' => $callback_secret ), home_url() );
    edd_update_option('edd_blockonomics_callback_url', $callback_url);
  }

  public function process_payment( $purchase_data )
  {
    global $edd_options;

    $api_key = trim(edd_get_option('edd_blockonomics_api_key', ''));
    if( empty ($api_key) )
    {
      edd_set_error( 'edd_blockonomics_api_invalid', __( 'Please enter your Blockonomics API key and secret in Settings', 'edd-blockonomics' ) );
      edd_send_back_to_checkout( '?payment-mode=blockonomics' );
    }

    // Collect payment data
    $payment_data = array(
      'price'         => $purchase_data['price'],
      'date'          => $purchase_data['date'],
      'user_email'    => $purchase_data['user_email'],
      'purchase_key'  => $purchase_data['purchase_key'],
      'currency'      => edd_get_currency(),
      'downloads'     => $purchase_data['downloads'],
      'user_info'     => $purchase_data['user_info'],
      'cart_details'  => $purchase_data['cart_details'],
      'gateway'       => 'blockonomics',
      'status'        => 'pending'
    );

    // Record the pending payment
    $payment_id = edd_insert_payment( $payment_data );

    // Check payment
    if ( ! $payment_id )
    {
      // Record the error
      edd_record_gateway_error( __( 'Payment Error', 'edd-blockonomics' ), sprintf( __( 'Payment creation failed, Payment data: %s', 'edd-blockonomics' ), json_encode( $payment_data ) ), $payment_id );
      // Problems? send back
      edd_send_back_to_checkout( '?payment-mode=blockonomics' );

    }
    else
    {
      try
      {
        $callback_secret = trim(edd_get_option('edd_blockonomics_callback_secret', ''));
        $blockonomics = new BlockonomicsAPI;
        $responseObj = $blockonomics->new_address($api_key, $callback_secret);
        $price = $blockonomics->get_price(edd_get_currency());

        if($responseObj->response_code != 200)
        {
          edd_record_gateway_error( __( 'Error while getting BTC Address', 'edd-blockonomics' ) );
          return;
        }

        $address = $responseObj->address;

        $blockonomics_orders = edd_get_option('edd_blockonomics_orders');
        $order = array(
          'value'              => $purchase_data['price'],
          'satoshi'            => intval(1.0e8*$purchase_data['price']/$price),
          'currency'           => edd_get_currency(),
          'order_id'            => $payment_id,
          'status'             => -1,
          'timestamp'          => time(),
          'txid'               => ''
        );

        $blockonomics_orders[$address] = $order;
        edd_update_option('edd_blockonomics_orders', $blockonomics_orders);

        //Update post parameters to make them available in the listner method.
        update_post_meta($payment_id, 'blockonomics_address', $address);
        $invoice_url = add_query_arg( array( 'edd-listener' => 'blockonomics', 'show_order' => $address ), home_url() );
        wp_redirect($invoice_url);
        exit;

      }
      catch ( Blockonomics_Exception $e )
      {
        $error  = json_decode( $e->getResponse() );

        if ( isset( $error->errors ) && is_array( $error->errors ) )
        {
          foreach( $error->errors as $error )
          {
            edd_set_error( 'edd_blockonomics_exception', sprintf( __( 'Error: %s', 'edd-blockonomics' ), $error ) );
          }
        }
        elseif( isset( $error->error ) )
        {
          edd_set_error( 'edd_blockonomics_exception', $error->error );
        }

        edd_send_back_to_checkout( '?payment-mode=blockonomics' );
      }
    }
  }

  function update_callback_url($callback_url, $xPub, $blockonomics)
  {
    $blockonomics->update_callback(
      edd_get_option('edd_blockonomics_api_key'),
      $callback_url,
      $xPub
    );
  }

  function testSetup()
  { 
    $api_key = edd_get_option('edd_blockonomics_api_key');
    $blockonomics = new BlockonomicsAPI;
    $response = $blockonomics->get_callbacks($api_key);
    $error_str = '';
    $responseBody = json_decode(wp_remote_retrieve_body($response));
    $callback_secret = edd_get_option("edd_blockonomics_callback_secret");
    $api_url = add_query_arg('edd-listener', 'blockonomics', home_url() );
    $callback_url = add_query_arg('secret', $callback_secret, $api_url);
    // Remove http:// or https:// from urls
    $api_url_without_schema = preg_replace('/https?:\/\//', '', $api_url);
    $callback_url_without_schema = preg_replace('/https?:\/\//', '', $callback_url);
    $response_callback_without_schema = preg_replace('/https?:\/\//', '', $responseBody[0]->callback);
    //TODO: Check This: WE should actually check code for timeout
    if (!wp_remote_retrieve_response_code($response)) {
        $error_str = __('Your server is blocking outgoing HTTPS calls', 'edd-blockonomics');
    }
    elseif (wp_remote_retrieve_response_code($response)==401)
        $error_str = __('API Key is incorrect', 'edd-blockonomics');
    elseif (wp_remote_retrieve_response_code($response)!=200)  
        $error_str = $response->data;
    elseif (!isset($responseBody) || count($responseBody) == 0)
    {
        $error_str = __('You have not entered an xpub', 'edd-blockonomics');
    }
    elseif (count($responseBody) == 1)
    {
        if(!$responseBody[0]->callback || $responseBody[0]->callback == null)
        {
          //No callback URL set, set one 
          $blockonomics->update_callback($api_key, $callback_url, $responseBody[0]->address);   
        }
        elseif($response_callback_without_schema != $callback_url_without_schema)
        {
          $base_url = get_bloginfo('wpurl');
          $base_url = preg_replace('/https?:\/\//', '', $base_url);
          // Check if only secret differs
          if(strpos($responseBody[0]->callback, $base_url) !== false)
          {
            //Looks like the user regenrated callback by mistake
            //Just force Update_callback on server
            $blockonomics->update_callback($api_key, $callback_url, $responseBody[0]->address);  
          }
          else
          {
            $error_str = __("You have an existing callback URL. Refer instructions on integrating multiple websites", 'edd-blockonomics');
          }
        }
    }
    else 
    {
        // Check if callback url is set
        foreach ($responseBody as $resObj)
         if(preg_replace('/https?:\/\//', '', $resObj->callback) == $callback_url_without_schema)
            return "";
        $error_str = __("You have an existing callback URL. Refer instructions on integrating multiple websites", 'edd-blockonomics');
    }  
    if (!$error_str)
    {
        //Everything OK ! Test address generation
        $response= $blockonomics->new_address($api_key, $callback_secret, true);
        if ($response->response_code!=200){
          $error_str = $response->response_message;
        }
    }
    if($error_str) {
        $error_str = $error_str . __('<p>For more information, please consult <a href="https://blockonomics.freshdesk.com/support/solutions/articles/33000215104-troubleshooting-unable-to-generate-new-address" target="_blank">this troubleshooting article</a></p>', 'edd-blockonomics');
        return $error_str;
    }

    // No errors
    return false;
  }

  public function redirect_to_template($template){
      $this->enqueue_stylesheets();
      $this->enqueue_scripts();
      if($template == 'order.php'){
          $theme_template = 'blockonomics_checkout.php';
      }else if ($template == 'track.php') {
          $theme_template = 'blockonomics_altcoin.php';
      }
      if ( $overridden_template = locate_template( $theme_template ) ) {
          // locate_template() returns path to file
          // if either the child theme or the parent theme have overridden the template
          load_template( $overridden_template );
      } else {
          // If neither the child nor parent theme have overridden the template,
          // we load the template from the 'templates' sub-directory of the directory this file is in
          load_template( plugin_dir_path(__FILE__).$template );
      }
      exit();
  }

  public function listener()
  {
    $listener = sanitize_key($_GET['edd-listener']);
    if( $listener != 'blockonomics' )
    {
      return;
    }

    $action = sanitize_key($_REQUEST["action"]);
    if( !empty($action) )
    {
      $settings_page = admin_url( 'edit.php?post_type=download&page=edd-settings&tab=gateways&section=blockonomics');
      if($action == "update_callback")
      {
        $this->generate_secret_and_callback(true);
        wp_redirect($settings_page);
        exit;
      }
      
      
    }

    $orders = edd_get_option('edd_blockonomics_orders');
    $address = sanitize_text_field($_REQUEST["show_order"]);
    $uuid = sanitize_text_field($_REQUEST["uuid"]);
    if ($address)
    {
      $this->redirect_to_template("order.php");
    }else if ($uuid){
      $this->redirect_to_template("track.php");
    }

    $address = sanitize_text_field($_REQUEST["finish_order"]);
    if ($address)
    {
      $order = $orders[$address];
      wp_redirect(edd_get_success_page_uri());
      exit;
    }

    $address = sanitize_text_field($_REQUEST['get_order']);

    if ($address)
    {
      header("Content-Type: application/json");
      exit(json_encode($orders[$address]));
    }

    try
    {
      $callback_secret = edd_get_option("edd_blockonomics_callback_secret");
      $secret = sanitize_text_field($_REQUEST['secret']);

      if ($callback_secret  && $callback_secret == $secret)
      {
        $addr = sanitize_text_field($_REQUEST['addr']);
        $order = $orders[$addr];
        $order_id = $order['order_id'];

        if ($order_id)
        {
          $status = intval(sanitize_key($_REQUEST['status']));
          $existing_status = $order['status'];
          $timestamp = $order['timestamp'];
          $time_period = edd_get_option("edd_blockonomics_timeperiod", 10) *60;
          $network_confirmations = edd_get_option("edd_blockonomics_confirmations", 2);
          if($network_confirmations == 'zero'){
            $network_confirmations = 0;
          }
          if ($status == 0 && time() > $timestamp + $time_period)
          {
            $minutes = (time() - $timestamp)/60;
            edd_record_gateway_error(__("Warning: Payment arrived after $minutes minutes. Received BTC may not match current bitcoin price", 'edd-blockonomics'));
          }
          elseif ($status >= $network_confirmations)
          {
            $payment = new EDD_Payment( $order_id );
            $value = intval(sanitize_key($_REQUEST['value']));
            $meta_data = $payment->get_meta();
            $meta_data['paid_btc_amount'] = $value/1.0e8;
            $payment->update_meta( '_edd_payment_meta', $meta_data ); 
      
            if ($order['satoshi'] > $value)
            {
              $status = -2; //Payment error , amount not matching
              edd_insert_payment_note($order_id, __('Paid BTC amount less than expected.','edd-blockonomics'));
              edd_update_payment_status($order_id, 'failed');
            }
            else
            {
              if ($order['satoshi'] < $value)
              {
                edd_insert_payment_note($order_id, __('Overpayment of BTC amount', 'edd-blockonomics'));
              }

              edd_insert_payment_note($order_id, __('Payment completed', 'edd-blockonomics'));
              edd_update_payment_status($order_id, 'publish' );
            }
          }

          $order['txid'] =  sanitize_key($_REQUEST['txid']);
          $order['status'] = $status;
          $orders[$addr] = $order;
      
          if ($existing_status == -1)
          {
            $payment = new EDD_Payment( $order_id );
            $meta_data = $payment->get_meta();
            $meta_data['blockonomics_txid'] = $order['txid'];
            $meta_data['expected_btc_amount'] = $order['satoshi']/1.0e8;
            $meta_data['bitcoin_address'] =  $addr;
            $payment->update_meta( '_edd_payment_meta', $meta_data ); 
          }
      
          edd_update_option('edd_blockonomics_orders', $orders);
        }
      }
    }
    catch ( Blockonomics_Exception $e )
    {
      $error = json_decode( $e->getResponse() );

      if( isset( $error->errors ) )
      {
        foreach( $error->errors as $error )
        {
          edd_record_gateway_error( __( 'Blockonomics Error', 'edd-blockonomics' ), 'Message: ' . $error );
        }
      } elseif( isset( $error->error ) )
      {
        edd_record_gateway_error( __( 'Blockonomics Error', 'edd-blockonomics' ), 'Message: ' . $error->error );
      }

      die('blockonomics exception error');
    }
  }

  public function currencies( $currencies )
  {
    $currencies['BTC'] = __( 'Bitcoin', 'edd-blockonomics' );
    return $currencies;
  }

  function btc_decimals( $decimals = 2 )
  {
    global $edd_options;
    $currency = edd_get_currency();

    switch ( $currency )
    {
    case 'BTC' :
      $decimals = 8;
      break;
    }

    return $decimals;
  }


  public function settings( $settings )
  {
    $callback_update_url = add_query_arg(array( 'edd-listener' => 'blockonomics', 'action' => 'update_callback') ,home_url());
    $callback_refresh = __( 'CALLBACK URL', 'edd-blockonomics' ).'<a href="'.$callback_update_url.'"
      id="generate-callback" style="font:400 20px/1 dashicons;margin-left: 7px; top: 4px;position:relative;text-decoration: none;" title="Generate New Callback URL">&#xf463;<a>';

    //$settings_page_testsetup = add_query_arg(array( 'edd-listener' => 'blockonomics', 'action' => 'test_setup') ,home_url());
    $settings_page_testsetup = admin_url( 'edit.php?post_type=download&page=edd-settings&tab=gateways&section=blockonomics&edd-action=testsetup');
    $settings_page = admin_url( 'edit.php?post_type=download&page=edd-settings&tab=gateways&section=blockonomics');
    $test_setup = '<p><b><i>'.__('Use below button to test the configuration.', 'edd-blockonomics').'</i></b></p>
      <p> <a id="edd-blockonomics-test-setup"  href="javascript:testSetupFunc();" class="button button-small" style="max-width:90px;">Test Setup</a> </p>

      <script type="text/javascript">
      var api_key = $("input[name=\'edd_settings[edd_blockonomics_api_key]\']").attr(\'value\');

      history.pushState({}, document.title, "'.$settings_page.'");
      if(api_key.length == 0)
      {
        var setting_table = $("input[name=\'edd_settings[edd_blockonomics_api_key]\']").closest("table");
        setting_table.before("'.__('<p>You are few clicks away from accepting bitcoin payments</p>', 'edd-blockonomics')
                      .__('<p>Click on <b>Get Started for Free</b> on <a href=\'https://www.blockonomics.co/merchants\' target=\'_blank\'>Blockonomics Merchants</a>. Complete the Wizard, Copy the API Key when shown here</p>', 'edd-blockonomics').'");
      }

      var network_confirmations = $("select[name=\'edd_settings[edd_blockonomics_confirmations]\']").find(":selected").text();
      if(network_confirmations < 2)
      {
        if($("#setting-error-edd_blockonomics_confirmations").length == 0) 
        {
          /* create notice div */
          var div = document.createElement( "div" );
          div.classList.add( "error", "settings-warning", "notice", "is-dismissible" );
          div.setAttribute( "id", "setting-error-edd_blockonomics_confirmations" );

          /* create paragraph element to hold message */
          var p = document.createElement( "p" );

          p.innerHTML = "<b>'.__('Blockonomics recommends 2 confirmations, to ensure the payment has arrived in your wallet.', 'edd-blockonomics').'</b>";
          div.appendChild( p );

          /* Create Dismiss icon */
          var b = document.createElement( "button" );
          b.setAttribute( "type", "button" );
          b.classList.add( "notice-dismiss" );

          /* Add screen reader text to Dismiss icon */
          var bSpan = document.createElement( "span" );
          bSpan.classList.add( "screen-reader-text" );
          bSpan.appendChild( document.createTextNode( "Dismiss this notice." ) );
          b.appendChild( bSpan );

          /* Add Dismiss icon to notice */
          div.appendChild( b );

          /* Insert notice after the first h2 */
          var h2 = document.getElementsByTagName( "h2" )[0];
          h2.parentNode.insertBefore( div, h2.nextSibling);

          /* Make the notice dismissable when the Dismiss icon is clicked */
          b.addEventListener( "click", function () 
          {
            div.parentNode.removeChild( div );
          });
        }
      }

      var testSetupFunc = function() 
      {
        var current_api_key = $("input[name=\'edd_settings[edd_blockonomics_api_key]\']").attr(\'value\');
        if( (current_api_key == api_key && api_key.length == 0 ) 
                || current_api_key != api_key ) 
        { 
          if($("#setting-error-edd_blockonomics_api_key_changed").length == 0) 
          {
            /* create notice div */
            var div = document.createElement( "div" );
            div.classList.add( "error", "settings-warning", "notice", "is-dismissible" );
            div.setAttribute( "id", "setting-error-edd_blockonomics_api_key_changed" );

            /* create paragraph element to hold message */
            var p = document.createElement( "p" );

            /* Add message text */
            if( current_api_key == api_key && api_key.length == 0)
            {
              p.innerHTML = "<b>'.__('Please enter your Blockonomics API key and save changes.', 'edd-blockonomics').'</b>";
            }
            else
            {
              p.innerHTML = "<b>'.__('API Key has changed. Click on Save Changes first.', 'edd-blockonomics').'</b>";
            }
            div.appendChild( p );

            /* Create Dismiss icon */
            var b = document.createElement( "button" );
            b.setAttribute( "type", "button" );
            b.classList.add( "notice-dismiss" );

            /* Add screen reader text to Dismiss icon */
            var bSpan = document.createElement( "span" );
            bSpan.classList.add( "screen-reader-text" );
            bSpan.appendChild( document.createTextNode( "Dismiss this notice." ) );
            b.appendChild( bSpan );

            /* Add Dismiss icon to notice */
            div.appendChild( b );

            /* Insert notice after the first h2 */
            var h2 = document.getElementsByTagName( "h2" )[0];
            h2.parentNode.insertBefore( div, h2.nextSibling);

            /* Make the notice dismissable when the Dismiss icon is clicked */
            b.addEventListener( "click", function () 
            {
              div.parentNode.removeChild( div );
            });
          }
        }
        else 
        {
          window.location = "'.$settings_page_testsetup.'";
        }
      };
</script>
';

    $blockonomics_settings = array(
      array(
        'id'      => 'edd_blockonomics_api_key',
        'name'    => __( 'BLOCKONOMICS API KEY', 'edd-blockonomics' ),
        'type'    => 'text'
      ),
      array(
        'id'      => 'edd_blockonomics_callback_url',
        'name'    => $callback_refresh,
        'readonly' => true,
        'type'    => 'text'
      ),
      array(
        'id'      => 'edd_blockonomics_altcoins',
        'name'    => __('Accept Altcoin Payments (Using Flyp.me)', 'edd-blockonomics'),
        'type'    => 'checkbox'
      ),
      array(
        'id'      => 'edd_blockonomics_payment_countdown_time',
        'name'    => __('Time period of countdown timer on payment page (in minutes)', 'edd-blockonomics'),
        'type'    => 'select',
        'options' => array(
          '10' => '10',
          '15' => '15',
          '20' => '20',
          '25' => '25',
          '30' => '30'
        )
      ),
      array(
        'id'      => 'edd_blockonomics_confirmations',
        'name'    => __('Network Confirmations required for payment to complete', 'edd-blockonomics'),
        'type'    => 'select',
        'options' => array(
          '2' => '2',
          '1' => '1',
          'zero' => '0'
        )       
      ),
      array(
        'id'      => 'edd_blockonomics_testsetup',
        'name'    => $test_setup,
        'readonly' => true,
        'type'    => 'testsetup',
      )
    );

    $blockonomics_settings = apply_filters('edd_blockonomics_settings', $blockonomics_settings);
    $settings['blockonomics'] = $blockonomics_settings;
    return $settings;
  }

  public function save_uuid(){
      $orders = edd_get_option('edd_blockonomics_orders');
      $address = $_REQUEST['address'];
      $uuid = $_REQUEST['uuid'];
      $order = $orders[$address];
      $payment = new EDD_Payment( $order['order_id'] );
      $meta_data = $payment->get_meta();
      $meta_data['flyp_uuid'] =  $uuid;
      $payment->update_meta( '_edd_payment_meta', $meta_data ); 
      wp_die();
  }
  public function refund_email(){
      $orders = edd_get_option('edd_blockonomics_orders');
      $order_id = $_REQUEST['order_id'];
      $order_link = home_url().$_REQUEST['order_link'];
      $order_coin = $_REQUEST['order_coin'];
      $order_coin_sym = $_REQUEST['order_coin_sym'];
      $order = $orders[$address];
      $payment = new EDD_Payment( $order['order_id'] );
      $payment_email = $payment->email;
      $email = $payment_email;
      $subject = $order_coin . ' ' . __('Refund', 'blockonomics-bitcoin-payments');
      $message = __('Your order couldn\'t be processed as you paid less than expected.<br>The amount you paid will be refunded.<br>Visit the link below to enter your refund address.<br>', 'blockonomics-bitcoin-payments').'<a href="'.$order_link.'">'.$order_link.'</a>';
      $emails = new EDD_Emails();
      $emails->send( $email, $subject, $message );
      wp_die();
  }

  public function enqueue_stylesheets(){
      wp_enqueue_style('bnomics-style', plugin_dir_url(__FILE__) . "css/order.css");
      wp_enqueue_style( 'bnomics-altcoins', plugin_dir_url(__FILE__) . "css/cryptofont/cryptofont.min.css");
      wp_enqueue_style( 'bnomics-icons', plugin_dir_url(__FILE__) . "css/icons/icons.css");
  }

  public function enqueue_scripts(){
      wp_enqueue_script( 'angular', plugins_url('js/angular.min.js', __FILE__), array('jquery') );
      wp_enqueue_script( 'angular-resource', plugins_url('js/angular-resource.min.js', __FILE__), array('jquery') );
      wp_enqueue_script( 'app', plugins_url('js/app.js', __FILE__), array('jquery') );
                        wp_localize_script( 'app', 'my_ajax_object',
                            array( 'ajax_url' => admin_url( 'admin-ajax.php' ) ) );
      wp_enqueue_script( 'angular-qrcode', plugins_url('js/angular-qrcode.js', __FILE__), array('jquery') );
      wp_enqueue_script( 'vendors', plugins_url('js/vendors.min.js', __FILE__), array('jquery') );
      wp_enqueue_script( 'reconnecting-websocket', plugins_url('js/reconnecting-websocket.min.js', __FILE__), array('jquery') );
  }

}

/*Call back method for the setting 'testsetup'*/
function edd_testsetup_callback()
{
  printf("");
}

function edd_blockonomics_init()
{
  $edd_blockonomics = new EDD_Blockonomics;
}
add_action( 'plugins_loaded', 'edd_blockonomics_init' );

