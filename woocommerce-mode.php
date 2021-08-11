<?php
/**
 * Plugin Name: Mode Payment Gateway Services
 * Description: Accept payments on your WooCommerce store using Mode Payment Gateway Services.
 * Plugin URI: https://github.com/modebanking/mode-wordpress-woocommerce-plugin.git
 * Author: firemind Ltd.
 * Author URI: https://firemind.io/
 * Version: 1.6.2
 */

/**
 * Copyright (c) 2019-2020 Mode
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

class WC_Mode {
	/**
	 * @var WC_Mode
	 */
	private static $instance;

	/**
	 * WC_Mode constructor.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * @return void
	 */
	public function init() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		define( 'MPGS_ISO3_COUNTRIES', include plugin_basename( '/iso3.php' ) );
		require_once plugin_basename( '/vendor/autoload.php' );
		require_once plugin_basename( '/includes/class-gateway.php' );

		load_plugin_textdomain( 'Mode', false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) ) . 'i18n/' );

		add_filter( 'woocommerce_order_actions', function ( $actions ) {
			$order = new WC_Order( $_REQUEST['post'] );
			if ( $order->get_payment_method() == Mode_Gateway::ID ) {
				if ( ! $order->get_meta( '_mpgs_order_captured' ) ) {
					if ( $order->get_status() == 'processing' ) {
						$actions['mpgs_capture_order'] = __( 'Capture payment', 'Mode' );
					}
				}
			}

			return $actions;
		} );

		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );

		add_action( 'rest_api_init', function () {
			register_rest_route( 'mode', '/v1/set-callback', array(
				'methods'  => 'POST',
				'callback' => [ $this, 'rest_route_set_callback' ]
			) );
		} );

		add_action( 'rest_api_init', function () {
			register_rest_route( 'mode', '/v1/payment-signature', array(
				'methods'  => 'POST',
				'callback' => [ $this, 'rest_route_payment_signature_callback' ]
			) );
		} );

		add_action( 'rest_api_init', function () {
			register_rest_route( 'mode', '/v1/check-payment', array(
				'methods'  => 'POST',
				'callback' => [ $this, 'rest_route_check_payment_callback' ]
			) );
		} );

		add_action( 'rest_api_init', function () {
			register_rest_route( 'mode', '/v1/refund-payment', array(
				'methods'  => 'POST',
				'callback' => [ $this, 'rest_route_refund_payment_callback' ]
			) );
		} );

		add_action( 'rest_api_init', function () {
			register_rest_route( 'mode', '/v1/check-cashback', array(
				'methods'  => 'POST',
				'callback' => [ $this, 'rest_route_check_cashback' ]
			) );
		} );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array
	 * @throws Mode_GatewayResponseException
	 * @throws \Http\Client\Exception
	 */
	public function rest_route_set_callback( $request ) {
		header('Content-Type: application/json');

		$orderArray = json_decode($request->get_body(), true);

		$orderid = wc_get_order_id_by_order_key($orderArray['orderRef']);
		$order = new WC_Order($orderid);

		if ($orderArray['status'] === 'SUCCESSFUL') {
			$order->update_meta_data('mode_paymentid', $orderArray['paymentId']);
			$order->add_order_note('Mode Gateway Payment ID: '.$orderArray['paymentId']);
			$order->update_status('processing', 'Paid with Mode Gateway.');
		}

		return json_decode($request->get_body());
	}

	public function rest_route_check_cashback( $request ) {
		$options = array(
			'http' => array(
				'ignore_errors' => true,
				'header'  => array(
					'Content-Type: application/json',
					'Authorization: Bearer '.get_option('mode_auth_token')
				),
				'method'  => 'GET'
			)
		);

		$context = stream_context_create($options);
		return $result = json_decode(file_get_contents('https://api.modeforbusiness.com/merchants', false, $context));
	}

	public function rest_route_check_payment_callback( $request ) {
		header('Content-Type: application/json');
		$orderArray = json_decode($request->get_body(), true);

		$orderid = wc_get_order_id_by_order_key($orderArray['orderRef']);
		$order = new WC_Order($orderid);

		$status = $order->get_status();
		return array('status' => $status);
	}

	public function rest_route_payment_signature_callback( $request ) {
		$data = array('grant_type' => 'client_credentials', 'client_id' => get_option('mode_client_id'), 'client_secret' => get_option('mode_secret_id'), 'audience' => 'https://merchants.modeapp.com');

		$options = array(
			'http' => array(
				'ignore_errors' => true,
				'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
				'method'  => 'POST',
				'content' => http_build_query($data)
			)
		);

		$context = stream_context_create($options);
		$result = json_decode(file_get_contents('https://auth.modeapp.com/oauth/token', false, $context), true);
		update_option('mode_auth_token', $result['access_token']);

		$responseObj = $request->get_body();
		$options = array(
			'http' => array(
				'ignore_errors' => true,
				'header'  => array(
					'Content-Type: application/json',
					'Authorization: Bearer '.get_option('mode_auth_token')
				),
				'method'  => 'POST',
				'content' => $responseObj
			)
		);

		$context = stream_context_create($options);
		$result = json_decode(file_get_contents('https://api.modeforbusiness.com/merchants/payments/sign', false, $context));
		return $result;
	}

	/**
	 * @param array $methods
	 *
	 * @return array
	 */
	public function add_gateways( $methods ) {
		$methods[] = 'Mode_Gateway';

		return $methods;
	}

	/**
	 * @return WC_Mode
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * @return void
	 */
	public static function activation_hook() {
		$environment_warning = self::get_env_warning();
		if ( $environment_warning ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( $environment_warning );
		}
	}

	/**
	 * @return bool
	 */
	public static function get_env_warning() {
		// @todo: Add some php version and php library checks here
		return false;
	}

	/**
	 * @param array $links
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		// todo: Add 'Support'

		//
		// array_unshift( $links, '<a href="https://www.ontapgroup.com/uk/helpdesk/ticket/">' . __( 'Support', 'Mode' ) . '</a>' );
		// array_unshift( $links, '<a href="http://wiki.ontapgroup.com/display/MPGS">' . __( 'Docs', 'Mode' ) . '</a>' );
		array_unshift( $links, '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=mode_gateway' ) . '">' . __( 'Settings', 'Mode' ) . '</a>' );

		return $links;
	}
}

WC_Mode::get_instance();
register_activation_hook( __FILE__, array( 'WC_Mode', 'activation_hook' ) );
