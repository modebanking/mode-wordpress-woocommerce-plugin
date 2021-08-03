<?php
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

define( 'MPGS_MODULE_VERSION', '1.1.0' );

require_once dirname( __FILE__ ) . '/class-gateway-service.php';
require_once dirname( __FILE__ ) . '/class-gateway-description.php';

class Mode_Gateway extends WC_Payment_Gateway {
	const ID = 'mode_gateway';

	const HOSTED_CHECKOUT = 'hostedcheckout';

	const HC_TYPE_REDIRECT = 'redirect';
	const HC_TYPE_MODAL = 'modal';

	const AUTH_URL = 'https://dev-mode.eu.auth0.com/oauth/token';
	const API_CALLBACK_URL = 'https://qa1-api.modeforbusiness.com/merchants/callbacks';
	const API_SIGNATURE_URL = 'https://qa1-api.modeforbusiness.com/merchants/payments/sign';

	/**
	 * @var Mode_GatewayService
	 */
	protected $service;

	/**
	 * Mode_Gateway constructor.
	 * @throws Exception
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->title              = __( 'Pay with Mode', 'mode' );
		$this->method_title       = __( 'Pay with Mode', 'mode' );
		$this->order_button_text  = __( 'Proceed to Mode', 'mode' );
		$this->has_fields         = true;
		$this->method_description = __( 'Accept payments on your WooCommerce store using the Mode Payment Gateway.',
			'mode' );

		$this->supports = array(
			'refunds'
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->service = $this->init_service();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options'
		) );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'woocommerce_order_action_mpgs_capture_order', array( $this, 'process_capture' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_api_Mode_Gateway', array( $this, 'return_handler' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * @return Mode_GatewayService
	 * @throws Exception
	 */
	protected function init_service() {
		$this->merchantid = $this->get_option( 'merchantid' );
		$this->clientid = $this->get_option( 'clientid' );
		$this->secretid = $this->get_option( 'secretid' );

		$loggingLevel = $this->get_debug_logging_enabled()
			? \Monolog\Logger::DEBUG
			: \Monolog\Logger::ERROR;

		return new Mode_GatewayService(
			self::AUTH_URL,
			self::API_CALLBACK_URL,
			$this->merchantid,
			$this->clientid,
			$this->secretid,
			$this->get_webhook_url(),
			$loggingLevel
		);
	}

		/**
	 * @param int $order_id
	 * @param float|null $amount
	 * @param string $reason
	 *
	 * @return bool
	 * @throws \Http\Client\Exception
	 */
	public function process_refund($orderId, $amount = NULL, $reason = '') {
		$order = new WC_Order($orderId);

		$paymentId = $order->get_meta('mode_paymentid');

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
		$result = json_decode(file_get_contents('https://qa1-api.modeforbusiness.com/merchants/payments/'.$paymentId, false, $context));
		$userId = $result->userId;
		$currency = $order->get_currency();
		$currencySymbol = get_woocommerce_currency_symbol($currency);

		$requestDataRefund = array(
			'userId' => $userId,
			'paymentId' => $paymentId,
			'amount' => array(
				'value' => $amount,
				'currency' => $currency
			)
		);

		$options = array(
			'http' => array(
				'ignore_errors' => true,
				'header'  => array(
					'Content-Type: application/json',
					'Authorization: Bearer '.get_option('mode_auth_token')
				),
				'method'  => 'POST',
				'content' => json_encode($requestDataRefund)
			)
		);

		$context = stream_context_create($options);

		$getFileRequest = file_get_contents('https://qa1-api.modeforbusiness.com/merchants/payments/refunds', false, $context);
		$result = json_decode($getFileRequest);

		if ($result->error) {
			$order->add_order_note('Refund unsuccessful. This user has already been refunded.');
			return false;
		} else {
			$order->add_order_note('Refund successful. Refunded '.$currencySymbol.$amount.' to Mode user '.$userId);
			return true;
		}
	}

	/**
	 * @return bool
	 */
	protected function get_debug_logging_enabled() {
		if ( $this->sandbox === 'yes' ) {
			return $this->get_option( 'debug', false ) === 'yes';
		}

		return false;
	}

	/**
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		try {
			$service = $this->init_service();
			$service->paymentOptionsInquiry();
		} catch ( Exception $e ) {
			$this->add_error(
				sprintf( __( 'Error communicating with payment gateway API: "%s"', 'mode' ), $e->getMessage() )
			);
		}

		return $saved;
	}

	/**
	 * @return void
	 */
	public function admin_scripts() {
		if ( 'woocommerce_page_wc-settings' !== get_current_screen()->id ) {
			return;
		}
	}

	/**
	 * admin_notices
	 */
	public function admin_notices() {
		if ( ! $this->enabled ) {
			return;
		}

		if ( ! $this->merchantid || ! $this->secretid || ! $this->clientid ) {
			echo '<div class="error"><p>' . __( 'API credentials are not valid. To activate the payment methods please your details to the forms below.' ) . '</p></div>';
		}

		$this->display_errors();
	}

	/**
	 * @return string
	 */
	public function get_webhook_url() {
		return rest_url( "mode/v1/webhook/" );
	}

	/**
	 * @param int $order_id
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = new WC_Order( $order_id );

		$order->update_status( 'pending', __( 'Pending payment', 'Mode' ) );

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true )
		);
	}

	/**
	 * @param int $order_id
	 */
	public function receipt_page( $order_id ) {
		$order = new WC_Order( $order_id );

		set_query_var( 'order', $order );
		set_query_var( 'gateway', $this );

		load_template( dirname( __FILE__ ) . '/../templates/checkout/hostedcheckout.php' );
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'merchantid'           => array(
				'title'   => __( 'Merchant ID', 'mode' ),
				'type'    => 'text',
				'default' => '',
			),
			'clientid'           => array(
				'title'   => __( 'Client ID', 'mode' ),
				'type'    => 'text',
				'default' => '',
			),
			'secretid'           => array(
				'title'   => __( 'Client Secret', 'mode' ),
				'type'    => 'password',
				'default' => '',
			)
		);
	}

	/**
	 * @return bool
	 */
	public function is_available() {
		$is_available = parent::is_available();

		if ( ! $this->merchantid || ! $this->clientid || ! $this->secretid ) {
			return false;
		}

		return $is_available;
	}
}
