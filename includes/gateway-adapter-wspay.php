<?php

/**
 * @todo: popravit thankyou poruke + vidit kako je bolje
 * switchanje tokenizacijskih kljuceva - kako je u magentu? mislim da njihovo nije dobro
 */
class Monri_WC_Gateway_Adapter_Wspay {

	public const ADAPTER_ID = 'wspay';

	public const ENDPOINT_TEST = 'https://formtest.wspay.biz';
	public const ENDPOINT = 'https://form.wspay.biz';

	/**
	 * @var Monri_WC_Gateway
	 */
	private $payment;

	/**
	 * @var string
	 */
	private $shop_id;

	/**
	 * @var string
	 */
	private $secret;

	/**
	 * @param Monri_WC_Gateway $payment
	 *
	 * @return void
	 */
	public function init( $payment ) {
		$this->payment = $payment;

		$this->shop_id = $this->payment->get_option(
			'monri_ws_pay_form_shop_id'
		);
		$this->secret  = $this->payment->get_option(
			'monri_ws_pay_form_secret'
		);

		// add tokenization support
		if ( $this->tokenization_enabled() ) {
			$this->payment->supports[] = 'tokenization';

			require_once __DIR__ . '/payment-token-wspay.php';

			add_filter( 'woocommerce_payment_token_class', function ($value, $type) {
				if ($type === 'Monri_Wspay') {
					return Monri_WC_Payment_Token_Wspay::class;
				}
				return $value;
			}, 0, 2 );
		}

		add_action( 'woocommerce_thankyou_monri', [ $this, 'thankyou_page' ] );
		//add_action( 'woocommerce_thankyou', [ $this, 'process_return' ] );

		/*
		add_filter( 'woocommerce_thankyou_order_received_text', function() {
			return '121212';
		}, 10, 2 );
		*/
	}

	public function use_tokenization_credentials() {
		$this->shop_id = $this->payment->get_option(
			$this->tokenization_enabled() ?
				'monri_ws_pay_form_tokenization_shop_id' :
				'monri_ws_pay_form_shop_id'
		);
		$this->secret  = $this->payment->get_option(
			$this->tokenization_enabled() ?
				'monri_ws_pay_form_tokenization_secret' :
				'monri_ws_pay_form_secret'
		);
	}

	/**
	 * @return bool
	 */
	public function tokenization_enabled() {
		return $this->payment->get_option_bool( 'monri_ws_pay_form_tokenization_enabled' );
	}

	/**
	 * @return void
	 */
	public function payment_fields() {

		if ( $this->tokenization_enabled() && is_checkout() && is_user_logged_in() ) {
			$this->payment->tokenization_script();
			$this->payment->saved_payment_methods();
			$this->payment->save_payment_method_checkbox();
		}
	}

	/**
	 * @param int $order_id
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {

		$order = wc_get_order( $order_id );

		$order_number = (string) $order->get_id();
		$order_number .= '-test' . time();

        $domain = 'monri';

		$req = [];

		if ( $this->tokenization_enabled() && is_checkout() && is_user_logged_in() ) {

			$use_token = null;
			if ( isset( $_POST['wc-monri-payment-token'] ) &&
			     ! in_array($_POST['wc-monri-payment-token'], ['not-selected', 'new', ''], true)
			) {
				$token_id = $_POST['wc-monri-payment-token'];
				$tokens = $this->payment->get_tokens();

				if (!isset($tokens[$token_id])) {
					return [
						'result'  => 'failure',
						'message' => __( 'Token does not exist.', $domain),
					];
				}

				/** @var Monri_WC_Payment_Token_Wspay $use_token */
				$use_token = $tokens[$token_id];
			}

			$new_token = isset( $_POST['wc-monri-new-payment-method'] ) &&
			             $_POST['wc-monri-new-payment-method'] === 'true';

			// paying with tokenized card
			if ( $use_token ) {

				//$decoded_card       = json_decode( base64_decode( $tokenized_card ) );
				$req['Token']       = $use_token->get_token();
				$req['TokenNumber'] = $use_token->get_last4();

				$order->update_meta_data('_monri_order_token_used', 1);
				$order->save_meta_data();

				// use different shop_id/secret for tokenization
				$this->use_tokenization_credentials();

			} else {

				// tokenize/save new card
				if ( $new_token ) {
					$req['IsTokenRequest'] = '1';
				}

				if ( $order->get_meta( '_monri_order_token_used' ) ) {
					$order->delete_meta_data( '_monri_order_token_used' );
					$order->save_meta_data();
				}
			}

		}

		$req['shopID']         = $this->shop_id;
		$req['shoppingCartID'] = $order_number;

		$amount             = number_format( $order->get_total(), 2, ',', '' );
		$req['totalAmount'] = $amount;

		$req['signature'] = $this->sign_transaction( $order_number, $amount );

		//$req['returnURL'] = site_url() . '/ws-pay-redirect'; // directly to success
		$req['returnURL'] = $order->get_checkout_order_received_url();

		// TODO: implement this in a different way
		//$req['returnErrorURL'] = $order->get_cancel_endpoint();
		//$req['cancelURL']      = $order->get_cancel_endpoint();

		$cancel_url            = str_replace( '&amp;', '&', $order->get_cancel_order_url() );
		$req['returnErrorURL'] = $cancel_url;
		$req['cancelURL']      = $cancel_url;

		$req['version']           = '2.0';
		$req['customerFirstName'] = $order->get_billing_first_name();
		$req['customerLastName']  = $order->get_billing_last_name();
		$req['customerAddress']   = $order->get_billing_address_1();
		$req['customerCity']      = $order->get_billing_city();
		$req['customerZIP']       = $order->get_billing_postcode();
		$req['customerCountry']   = $order->get_billing_country();
		$req['customerPhone']     = $order->get_billing_phone();
		$req['customerEmail']     = $order->get_billing_email();

		$response = $this->api( '/api/create-transaction', $req );

		if ( isset( $response['PaymentFormUrl'] ) ) {
			return [
				'result'   => 'success',
				'redirect' => $response['PaymentFormUrl']
			];

		} else {
			Monri_WC_Logger::log( $response, __METHOD__ );

			return array(
				'result'  => 'failure',
				'message' => __( 'Gateway currently not available.', $domain),
			);
		}
	}


	public function show_message( $message, $class = '' ) {
		return '<div class="box ' . $class . '-box">' . $message . '</div>';
	}

	/**
	 * @return void
	 */
	public function thankyou_page() {

		//echo $this->show_message('wqewqeqe', 'woocommerce_message woocommerce_error');
		//echo 12345;
		//return;

		$order_id = $_REQUEST['ShoppingCartID']; // is there wp param?
		$order_id = strstr( $order_id, '-test', true );
		//$order_id = wc_get_order_id_by_order_key($_REQUEST['key']); // load by wp key?

        $domain = 'monri';

		$order = wc_get_order( $order_id );

		if ( ! $order || $order->get_payment_method() !== $this->payment->id ) {
			return;
		}

		$is_tokenization = $order->get_meta( '_monri_order_token_used', true );
		if ($is_tokenization) {
			$this->use_tokenization_credentials();
		}

		if ( ! $this->validate_return( $_REQUEST ) ) {
			// throw error? redirect to error?
			return;
		}


		//wp_enqueue_style('thankyou-page', plugins_url() . '/woocommerce-monri/assets/style/thankyou-page.css');

		if ( $order->get_status() === 'completed' ) {

			$this->msg['message'] = __('Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon.', $domain);
			$this->msg['class']   = 'woocommerce_message';

		} else {

			$success        = $_REQUEST['Success'] ?? '0';
			$approval_code  = $_REQUEST['ApprovalCode'] ?? null;
			$trx_authorized = $success === '1' && ! empty( $approval_code );

			if ( $trx_authorized ) {
				$this->msg['message'] = __('Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon.', $domain);
				$this->msg['class']   = 'woocommerce_message';

				$order->payment_complete();
				$order->add_order_note( __("Monri payment successful<br/>Approval code: ", $domain) . $approval_code );
				//$order->add_order_note($this->msg['message']);
				WC()->cart->empty_cart();

				//$tokenized = $this->save_token_details_ws_pay();

				if ( $this->tokenization_enabled() && $order->get_user_id() ) {
					$this->save_user_token( $order->get_user_id(), $_REQUEST );
				}

			} else {
				$this->msg['class']   = 'woocommerce_error';
				$this->msg['message'] = __('Thank you for shopping with us. However, the transaction has been declined.', $domain);

				$order->update_status( 'failed' );
				$order->add_order_note( 'Failed' );
				//$order->add_order_note($message);
			}

		}

	}

	/**
	 * @param string $shoppingCartId
	 * @param string $totalAmount
	 *
	 * @return string
	 */
	private function sign_transaction( $shoppingCartId, $totalAmount ) {
		$shopId    = $this->shop_id;
		$secretKey = $this->secret;
		$amount    = preg_replace( '~\D~', '', $totalAmount );

		return hash( 'sha512', $shopId . $secretKey . $shoppingCartId . $secretKey . $amount . $secretKey );
	}

	/**
	 * @param array $request
	 *
	 * @return bool
	 */
	private function validate_return( $request ) {

		if ( ! isset( $request['ShoppingCartID'], $request['Signature'] ) ) {
			return false;
		}

		$order_id      = $request['ShoppingCartID'];
		$digest        = $request['Signature'];
		$success       = $request['Success'] ?? '0';
		$approval_code = $request['ApprovalCode'] ?? '';

		$shop_id    = $this->shop_id;
		$secret_key = $this->secret;

		$digest_parts = array(
			$shop_id,
			$secret_key,
			$order_id,
			$secret_key,
			$success,
			$secret_key,
			$approval_code,
			$secret_key,
		);
		$check_digest = hash( 'sha512', implode( '', $digest_parts ) );

		return $digest === $check_digest;
	}

	/**
	 * Send POST request to $url with $params as a field
	 *
	 * @param string $path
	 * @param array $params
	 *
	 * @return array
	 */
	private function api( $path, $params ) {

		$url = $this->payment->get_option_bool( 'test_mode' ) ? self::ENDPOINT_TEST : self::ENDPOINT;
		$url .= $path;

		$result = wp_remote_post( $url, [
				'body'      => json_encode( $params ),
				'headers'   => [
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json'
				],
				'method'    => 'POST',
				'timeout'   => 15,
				'sslverify' => false
			]
		);

		if ( is_wp_error( $result ) || ! isset( $result['body'] ) ) {
			return [];
		}

		return json_decode( $result['body'], true );
	}

	/**
	 * @param int $user_id
	 * @param array $data
	 *
	 * @return void
	 */
	private function save_user_token( $user_id, $data ) {

		if ( ! isset( $data['Token'], $data['TokenNumber'], $data['TokenExp'] ) ) {
			return null;
		}

		$wc_token = new Monri_WC_Payment_Token_Wspay();

		$wc_token->set_gateway_id( $this->payment->id );
		$wc_token->set_token( $data['Token'] );
		$wc_token->set_user_id( $user_id );

		$wc_token->set_last4( $data['TokenNumber'] );
		$ccType = $data['PaymentType'] ?? ($data['CreditCardName'] ?? '');
		$wc_token->set_card_type($ccType);
		$wc_token->set_expiry_year( substr( $data['TokenExp'] , 0, 2) );
		$wc_token->set_expiry_month( substr( $data['TokenExp'] , 2, 2) );

		$wc_token->save();
	}

}
