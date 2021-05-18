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

require_once dirname( __FILE__ ) . '/class-checkout-builder.php';
require_once dirname( __FILE__ ) . '/class-gateway-service.php';
require_once dirname( __FILE__ ) . '/class-payment-gateway-cc.php';

class Mode_Gateway extends WC_Payment_Gateway {
	const ID = 'mode_gateway';

	const HOSTED_SESSION = 'hostedsession';
	const HOSTED_CHECKOUT = 'hostedcheckout';

	const HC_TYPE_REDIRECT = 'redirect';
	const HC_TYPE_MODAL = 'modal';

	const AUTH_URL = 'https://dev-mode.eu.auth0.com/oauth/token';
	const API_CALLBACK_URL = 'https://hpxjxq5no8.execute-api.eu-west-2.amazonaws.com/production/merchants/callbacks';
	const API_SIGNATURE_URL = 'https://hpxjxq5no8.execute-api.eu-west-2.amazonaws.com/production/merchants/payments/sign';

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
		$this->has_fields         = true;
		$this->method_description = __( 'Accept payments on your WooCommerce store using the Mode Payment Gateway.',
			'mode' );

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
	 * @throws \Http\Client\Exception
	 */
	public function process_capture() {
		$order = new WC_Order( $_REQUEST['post_ID'] );
		if ( $order->get_payment_method() != $this->id ) {
			throw new Exception( 'Wrong payment method' );
		}
		if ( $order->get_status() != 'processing' ) {
			throw new Exception( 'Wrong order status, must be \'processing\'' );
		}
		if ( $order->get_meta( '_mpgs_order_captured' ) ) {
			throw new Exception( 'Order already captured' );
		}

		$result = $this->service->captureTxn( $order->get_id(), time(), $order->get_total(), $order->get_currency() );

		$txn = $result['transaction'];
		$order->add_order_note( sprintf( __( 'Mode payment CAPTURED (ID: %s, Auth Code: %s)', 'Mode' ),
			$txn['id'], $txn['authorizationCode'] ) );

		$order->update_meta_data( '_mpgs_order_captured', true );
		$order->save_meta_data();

		wp_redirect( wp_get_referer() );
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
	 * @param int $order_id
	 * @param float|null $amount
	 * @param string $reason
	 *
	 * @return bool
	 * @throws \Http\Client\Exception
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order  = new WC_Order( $order_id );
		$result = $this->service->refund( $order_id, (string) time(), $amount, $order->get_currency() );
		$order->add_order_note( sprintf(
			__( 'Mode registered refund %s %s (ID: %s)', 'Mode' ),
			$result['transaction']['amount'],
			$result['transaction']['currency'],
			$result['transaction']['id']
		) );

		return true;
	}

	/**
	 * @return array|void
	 * @throws \Http\Client\Exception
	 */
	public function return_handler() {
		@ob_clean();
		header( 'HTTP/1.1 200 OK' );

		if ( $this->method === self::HOSTED_SESSION ) {
//			WC()->cart->empty_cart();
			$this->process_hosted_session_payment();
		}

		if ( $this->method === self::HOSTED_CHECKOUT ) {
//			WC()->cart->empty_cart();
			$this->process_hosted_checkout_payment();
		}
	}

	/**
	 * @throws \Http\Client\Exception
	 */
	protected function process_hosted_checkout_payment() {
		$order_id          = $_REQUEST['order_id'];
		$result_indicator  = $_REQUEST['resultIndicator'];
		$order             = new WC_Order( $order_id );
		$success_indicator = $order->get_meta( '_mpgs_success_indicator' );

		try {
			if ( $success_indicator !== $result_indicator ) {
				throw new Exception( 'Result indicator mismatch' );
			}

			$mpgs_order = $this->service->retrieveOrder( $order_id );
			if ( $mpgs_order['result'] !== 'SUCCESS' ) {
				throw new Exception( 'Payment was declined.' );
			}

			$txn = $mpgs_order['transaction'][0];
			$this->process_wc_order( $order, $mpgs_order, $txn );

			wp_redirect( $this->get_return_url( $order ) );
			exit();
		} catch ( Exception $e ) {
			$order->update_status( 'failed', $e->getMessage() );
			wc_add_notice( $e->getMessage(), 'error' );
			wp_redirect( wc_get_checkout_url() );
			exit();
		}
	}

	/**
	 * @return array
	 */
	protected function get_token_from_request() {
		$token_key = $this->get_token_key();
		$tokenId   = null;
		if ( isset( $_REQUEST[ $token_key ] ) ) {
			$token_id = $_REQUEST[ $token_key ];
		}
		$tokens = $this->get_tokens();
		if ( $token_id && isset( $tokens[ $token_id ] ) ) {
			return array(
				'token' => $tokens[ $token_id ]->get_token()
			);
		}

		return array();
	}

	/**
	 * @return string
	 */
	protected function get_token_key() {
		return 'wc-' . $this->id . '-payment-token';
	}

	/**
	 * @throws \Http\Client\Exception
	 */
	protected function process_hosted_session_payment() {
		$order_id        = $_REQUEST['order_id'];
		$session_id      = $_REQUEST['session_id'];
		$session_version = $_REQUEST['session_version'];

		$session            = array(
			'id'      => $session_id,
			'version' => $session_version
		);
		$order              = new WC_Order( $order_id );
		$check_3ds          = isset( $_REQUEST['check_3ds_enrollment'] ) ? $_REQUEST['check_3ds_enrollment'] == '1' : false;
		$process_acl_result = isset( $_REQUEST['process_acs_result'] ) ? $_REQUEST['process_acs_result'] == '1' : false;
		$tds_id             = null;

		if ( isset( $_REQUEST[ 'wc-' . $this->id . '-new-payment-method' ] ) ) {
			$order->update_meta_data( '_save_card', $_REQUEST[ 'wc-' . $this->id . '-new-payment-method' ] === 'true' );
			$order->save_meta_data();
		}

		if ( $check_3ds ) {
			$data      = array(
				'authenticationRedirect' => array(
					'pageGenerationMode' => 'CUSTOMIZED',
					'responseUrl'        => $this->get_payment_return_url( $order_id, array(
						'status' => '3ds_done'
					) )
				)
			);
			$session   = array(
				'id' => $session_id
			);
			$orderData = array(
				'amount'   => $order->get_total(),
				'currency' => $order->get_currency()
			);

			$source_of_funds = $this->get_token_from_request();

			$response = $this->service->check3dsEnrollment( $data, $orderData, $session, $source_of_funds );

			if ( $response['response']['gatewayRecommendation'] !== 'PROCEED' ) {
				$order->update_status( 'failed', __( 'Payment was declined.', 'Mode' ) );
				wc_add_notice( __( 'Payment was declined.', 'Mode' ), 'error' );
				wp_redirect( wc_get_checkout_url() );
				exit();
			}

			if ( isset( $response['3DSecure']['authenticationRedirect'] ) ) {
				$tds_auth  = $response['3DSecure']['authenticationRedirect']['customized'];
				$token_key = $this->get_token_key();

				set_query_var( 'authenticationRedirect', $tds_auth );
				set_query_var( 'returnUrl', $this->get_payment_return_url( $order_id, array(
					'3DSecureId'         => $response['3DSecureId'],
					'process_acs_result' => '1',
					'session_id'         => $session_id,
					'session_version'    => $session_version,
					$token_key           => isset( $_REQUEST[ $token_key ] ) ? $_REQUEST[ $token_key ] : null
				) ) );

				set_query_var( 'order', $order );
				set_query_var( 'gateway', $this );

				load_template( dirname( __FILE__ ) . '/../templates/3dsecure/form.php' );
				exit();
			}

			$this->pay( $session, $order );
		}

		if ( $process_acl_result ) {
			$pa_res = $_POST['PaRes'];
			$tds_id = $_REQUEST['3DSecureId'];

			$response = $this->service->process3dsResult( $tds_id, $pa_res );

			if ( $response['response']['gatewayRecommendation'] !== 'PROCEED' ) {
				$order->update_status( 'failed', __( 'Payment was declined.', 'Mode' ) );
				wc_add_notice( __( 'Payment was declined.', 'Mode' ), 'error' );
				wp_redirect( wc_get_checkout_url() );
				exit();
			}

			$this->pay( $session, $order, $tds_id );
		}

		if ( ! $check_3ds && ! $process_acl_result && ! $this->threedsecure ) {
			$this->pay( $session, $order );
		}

		$order->update_status( 'failed', __( 'Unexpected payment condition error.', 'Mode' ) );
		wc_add_notice( __( 'Unexpected payment condition error.', 'Mode' ), 'error' );
		wp_redirect( wc_get_checkout_url() );
		exit();
	}

	/**
	 * @param array $session
	 * @param WC_Order $order
	 * @param string|null $tds_id
	 *
	 * @throws \Http\Client\Exception
	 */
	protected function pay( $session, $order, $tds_id = null ) {
		try {
			if ( ! $order->meta_exists( '_txn_id' ) ) {
				$txn_id = '1';
				$order->add_meta_data( '_txn_id', $txn_id );
			} else {
				$txn_id = (string) ( (int) $order->get_meta( '_txn_id' ) + 1 );
				$order->update_meta_data( '_txn_id', $txn_id );
			}
			$order->save_meta_data();

			$order_builder = new Mode_CheckoutBuilder( $order );
			if ( $this->capture ) {
				$mpgs_txn = $this->service->pay(
					$txn_id,
					$order->get_id(),
					$order_builder->getOrder(),
					$tds_id,
					$session,
					$order_builder->getCustomer(),
					$order_builder->getBilling(),
					$order_builder->getShipping(),
					$this->get_token_from_request()
				);
			} else {
				$mpgs_txn = $this->service->authorize(
					$txn_id,
					$order->get_id(),
					$order_builder->getOrder(),
					$tds_id,
					$session,
					$order_builder->getCustomer(),
					$order_builder->getBilling(),
					$order_builder->getShipping(),
					$this->get_token_from_request()
				);
			}

			if ( $mpgs_txn['result'] !== 'SUCCESS' ) {
				throw new Exception( __( 'Payment was declined.', 'Mode' ) );
			}

			$this->process_wc_order( $order, $mpgs_txn['order'], $mpgs_txn );

			if ( $this->saved_cards && $order->get_meta( '_save_card' ) ) {
				$this->process_saved_cards( $session );
			}

			wp_redirect( $this->get_return_url( $order ) );
			exit();
		} catch ( Exception $e ) {
			$order->update_status( 'failed', $e->getMessage() );
			wc_add_notice( $e->getMessage(), 'error' );
			wp_redirect( wc_get_checkout_url() );
			exit();
		}
	}

	/**
	 * @param array $session
	 *
	 * @throws \Http\Client\Exception
	 */
	protected function process_saved_cards( $session ) {
		$response = $this->service->createCardToken( $session['id'] );

		if ( ! isset( $response['token'] ) || empty( $response['token'] ) ) {
			throw new Exception( 'Token not present in reponse' );
		}

		$token = new WC_Payment_Token_CC();
		$token->set_token( $response['token'] );
		$token->set_gateway_id( $this->id );
		$token->set_card_type( $response['sourceOfFunds']['provided']['card']['brand'] );

		$last4 = substr(
			$response['sourceOfFunds']['provided']['card']['number'],
			- 4
		);
		$token->set_last4( $last4 );

		$m = [];
		preg_match( '/^(\d{2})(\d{2})$/', $response['sourceOfFunds']['provided']['card']['expiry'], $m );

		$token->set_expiry_month( $m[1] );
		$token->set_expiry_year( '20' . $m[2] );
		$token->set_user_id( get_current_user_id() );

		$token->save();
	}

	/**
	 * @param WC_Order $order
	 * @param array $order_data
	 * @param array $txn_data
	 *
	 * @throws Exception
	 */
	protected function process_wc_order( $order, $order_data, $txn_data ) {
		$this->validate_order( $order, $order_data );

		$captured = $order_data['status'] === 'CAPTURED';
		$order->add_meta_data( '_mpgs_order_captured', $captured );

		$order->payment_complete( $txn_data['transaction']['id'] );

		if ( $captured ) {
			$order->add_order_note( sprintf( __( 'Mode payment CAPTURED (ID: %s, Auth Code: %s)', 'Mode' ),
				$txn_data['transaction']['id'], $txn_data['transaction']['authorizationCode'] ) );
		} else {
			$order->add_order_note( sprintf( __( 'Mode payment AUTHORIZED (ID: %s, Auth Code: %s)',
				'Mode' ), $txn_data['transaction']['id'], $txn_data['transaction']['authorizationCode'] ) );
		}
	}

	/**
	 * @param WC_Order $order
	 * @param array $mpgs_order
	 *
	 * @return bool
	 * @throws Exception
	 */
	protected function validate_order( $order, $mpgs_order ) {
		if ( $order->get_currency() !== $mpgs_order['currency'] ) {
			throw new Exception( 'Currency mismatch' );
		}
		if ( (float) $order->get_total() !== $mpgs_order['amount'] ) {
			throw new Exception( 'Amount mismatch' );
		}

		return true;
	}

	/**
	 * @param int $order_id
	 * @param array $params
	 *
	 * @return string
	 */
	public function get_payment_return_url( $order_id, $params = array() ) {
		$params = array_merge( array(
			'order_id' => $order_id
		), $params );

		return add_query_arg( 'wc-api', self::class, home_url( '/' ) ) . '&' . http_build_query( $params );
	}

	/**
	 * @return string
	 */
	public function get_webhook_url() {
		return rest_url( "mode/v1/webhook/" );
	}

	/**
	 * @return string
	 */
	public function get_hosted_checkout_js() {
		return sprintf( 'https://%s/checkout/%s/checkout.js', $this->get_gateway_url(), self::MPGS_API_VERSION );
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

		if ( $this->method === self::HOSTED_SESSION ) {
			$display_tokenization = $this->supports( 'tokenization' ) && is_checkout() && $this->saved_cards;
			set_query_var( 'display_tokenization', $display_tokenization );

			$cc_form     = new Mode_Payment_Gateway_CC();
			$cc_form->id = $this->id;

			$support = $this->supports;
			if ( $this->saved_cards == false ) {
				foreach ( array_keys( $support, 'tokenization', true ) as $key ) {
					unset( $support[ $key ] );
				}
			}
			$cc_form->supports = $support;

			set_query_var( 'cc_form', $cc_form );

			load_template( dirname( __FILE__ ) . '/../templates/checkout/hostedsession.php' );
		} else {
			load_template( dirname( __FILE__ ) . '/../templates/checkout/hostedcheckout.php' );
		}
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
