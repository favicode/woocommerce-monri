<?php

require_once __DIR__ . '/api.php';

class Monri_WC_Gateway_Adapter_Webpay_Components
{
	public const ADAPTER_ID = 'webpay_components';

	public const TRANSACTION_ENDPOINT_TEST = 'https://ipgtest.monri.com/v2/transaction';
	public const TRANSACTION_ENDPOINT = 'https://ipg.monri.com/v2/transaction';

	public const SCRIPT_ENDPOINT_TEST = 'https://ipgtest.monri.com/dist/components.js';
	public const SCRIPT_ENDPOINT = 'https://ipg.monri.com/dist/components.js';

	/**
	 * @var Monri_WC_Gateway
	 */
	private $payment;

	public function __construct() {
	}

	/**
	 * @param Monri_WC_Gateway $payment
	 *
	 * @return void
	 */
	public function init($payment) {
		$this->payment = $payment;
		$this->payment->has_fields = true;

		//$this->check_monri_response();
		add_action('woocommerce_receipt_' . $this->payment->id, [$this, 'process_redirect']);
		add_action('woocommerce_thankyou_' . $this->payment->id, [$this, 'check_3dsecure_response']);
	}

	/**
	 * @return void
	 */
	public function payment_fields() {

		$script_url = $this->payment->get_option_bool('test_mode') ? self::SCRIPT_ENDPOINT_TEST : self::SCRIPT_ENDPOINT;

		wp_enqueue_script('monri-components', $script_url, array('jquery'), MONRI_WC_VERSION);
		wp_enqueue_script('monri-installments', MONRI_WC_PLUGIN_URL . 'assets/js/installments.js', array('jquery'), MONRI_WC_VERSION);

		$lang = Monri_WC_i18n::get_translation();
		$order_total = (float) WC()->cart->total;

		/*
		$price_increase_message = "<span id='price-increase-1' class='price-increase-message' style='display: none; color: red;'></span>";

		for ($i = 2; $i <= self::MAX_NUMBER_OF_INSTALLMENTS; $i++) {
			$installment = "price_increase_$i";
			if ($this->$installment != 0) {
				$amount = $order_total + ($order_total * $this->$installment / 100);
				$price_increase_message .= "<span id='price-increase-$i' class='price-increase-message' style='display: none; color: red;'> " . $lang["PAYMENT_INCREASE"] . " " . $this->$installment . "% = " . $amount . "</span>";
			} else {
				$price_increase_message .= "<span id='price-increase-$i' class='price-increase-message' style='display: none; color: red;'></span>";
			}
		}

		if ($this->paying_in_installments && $this->number_of_allowed_installments && $order_total >= $this->bottom_limit) {
			$options_string = "";
			for ($i = 1; $i <= $this->number_of_allowed_installments; $i++) {
				$options_string .= "<option value='$i'>$i</option>";
			}
		}
		*/

		//$installments key/value array to template
		$installments = array();

		$radnom_token = wp_generate_uuid4();
		$timestamp = (new DateTime())->format('c');
		$digest = hash('SHA512', $this->payment->get_option('monri_merchant_key') . $radnom_token . $timestamp);

		wc_get_template('components.php', array(
			'config' => array(
				'authenticity_token' => $this->payment->get_option('monri_authenticity_token'),
				'random_token' => $radnom_token,
				'digest' => $digest,
				'timestamp' => $timestamp
			),
			'installments' => $installments
		), basename(MONRI_WC_PLUGIN_PATH), MONRI_WC_PLUGIN_PATH . 'templates/' );
	}

	/**
	 * @param int $order_id
	 *
	 * @return array
	 */
	public function process_payment($order_id) {

		$monri_token = $_POST['monri-token'] ?? '';

		if (empty($monri_token)) {
			wc_add_notice(Monri_WC_i18n::get_translation('TRANSACTION_FAILED'), 'error');

			/*
			return array(
				'result'   => 'failure',
				'redirect' => $order->get_checkout_payment_url( true ),
				'message'  => $e->getMessage(),
			);
			*/

			return;
		}

		$number_of_installments = isset($_POST['monri-card-installments']) ? (int)$_POST['monri-card-installments'] : 1;
		$number_of_installments = min(max($number_of_installments, 1), 24);

		$order = new WC_Order($order_id);
		$amount = $order->get_total();

		//Check transaction type
		$transaction_type = $this->payment->get_option('transaction_type') ? 'authorize' : 'purchase';

		//Check if paying in installments, if yes set transaction_type to purchase
		if ($number_of_installments > 1) {
			$transaction_type = 'purchase';

			// @todo move to installments class
			$installment = (float) $this->payment->get_option("price_increase_$number_of_installments", 0);
			if ($installment != 0) {
				$amount = $order->get_total() + ($order->get_total() * $installment / 100);
			}
			//
		}

		//Convert order amount to number without decimals
		$amount = ceil($amount * 100);

		$currency = $order->get_currency();
		if ($currency === 'KM') {
			$currency = 'BAM';
		}

		//Generate digest key
		$digest = hash('sha512', $this->payment->get_option('monri_merchant_key') . $order->get_id() . $amount . $currency);

		//Array of order information
		$order_number = $order->get_id();

		$params = array(
			'ch_full_name' => $order->get_billing_first_name() . " " . $order->get_billing_last_name(),
			'ch_address' => $order->get_billing_address_1(),
			'ch_city' => $order->get_billing_city(),
			'ch_zip' => $order->get_billing_postcode(),
			'ch_country' => $order->get_billing_country(),
			'ch_phone' => $order->get_billing_phone(),
			'ch_email' => $order->get_billing_email(),

			'order_info' => $order_number . '_' . date('dmy'),
			'order_number' => $order_number,
			'amount' => $amount,
			'currency' => $currency,

			'ip' => $_SERVER['REMOTE_ADDR'],
			'language' => $this->payment->get_option('form_language'),
			'transaction_type' => $transaction_type,
			'authenticity_token' => $this->payment->get_option('monri_authenticity_token'),
			'digest' => $digest,
			'temp_card_id' => $monri_token,
		);

		if ($number_of_installments > 1) {
			$params['number_of_installments'] = $number_of_installments;
		}

		$result = $this->request($params);
		//@todo log repsonse (resultJSON) here?

		$lang = Monri_WC_i18n::get_translation();

		//check if cc have 3Dsecure validation
		if (isset($result['secure_message'])) {
			//this is 3dsecure card
			//show user 3d secure form the
			$result = $result['secure_message'];

			//$order->get_checkout_order_received_url()
			$thank_you_page = $this->payment->get_return_url($order); //$this->settings['thankyou_page']

			$payment_token = $this->base64url_encode(
				json_encode([$result['authenticity_token'], $order_number, $thank_you_page])
			);

			$urlEncode = array(
				'acsUrl' => $result['acs_url'],
				'pareq' => $result['pareq'],
				'returnUrl' => site_url() . '/monri-3ds-payment-result?payment_token=' . $payment_token,
				'token' => $result['authenticity_token']
			);

			$redirect = MONRI_WC_PLUGIN_URL . '3dsecure.php?' . http_build_query($urlEncode);
			return array(
				'result' => 'success',
				'redirect' => $redirect,
			);

		} elseif (isset($result['transaction']) && $result['transaction']['status'] === 'approved') {

			$transactionResult = $result['transaction'];

			$order = new WC_Order($transactionResult['order_number']);

			//Payment has been successful
			$order->add_order_note(__($lang['PAYMENT_COMPLETED'], 'monri'));
			$monri_order_amount1 = $transactionResult['amount'] / 100;
			$monri_order_amount2 = number_format($monri_order_amount1, 2);
			if ($monri_order_amount2 != $order->get_total()) {
				$order->add_order_note($lang['MONRI_ORDER_AMOUNT'] . ": " . $monri_order_amount2, true);
			}
			if (isset($params['number_of_installments']) && $params['number_of_installments'] > 1) {
				$order->add_order_note($lang['NUMBER_OF_INSTALLMENTS'] . ": " . $params['number_of_installments']);
			}

			// Mark order as Paid
			$order->payment_complete();

			// Empty the cart (Very important step)
			WC()->cart->empty_cart();

			// Redirect to thank you page
			return array(
				'result' => 'success',
				'redirect' => $this->payment->get_return_url($order),
			);
		} else {
			//nope
			wc_add_notice($lang['TRANSACTION_FAILED'], 'error');
			return false;
		}

	}

	/**
	 * Check for valid 3dsecure response
	 *
	 * @return void
	 **/
	public function check_3dsecure_response()
	{
		$lang = Monri_WC_i18n::get_translation();

		// @todo what if not isset?

		if (!isset($_POST['PaRes'])) {
			return;
		}

		/** @var SimpleXMLElement $resultXml */
		$resultXml = Monri_WC_Api::instance()->pares($_POST);
		if ( is_wp_error($resultXml) ) {
			return; //??
		}

		if (isset($resultXml->status) && $resultXml->status == 'approved') {

			$resultXml = (array) $resultXml;
			$order = new WC_Order($resultXml['order-number']);

			// Payment has been successful
			$order->add_order_note(__($lang['PAYMENT_COMPLETED'], 'monri'));

			// Mark order as Paid
			$order->payment_complete();

			// Empty the cart (Very important step)
			WC()->cart->empty_cart();

		} else {
			$resultXml = (array) $resultXml;
			$order = new WC_Order($resultXml['order-number']);

			$order->update_status('failed');
			$order->add_order_note('Failed');
			//$order->add_order_note($this->msg['message']);
		}
	}

	/**
	 *
	 * @param array $params
	 * @return array
	 */
	protected function request($params)
	{
		$url = $this->payment->get_option_bool('test_mode') ? self::TRANSACTION_ENDPOINT_TEST : self::TRANSACTION_ENDPOINT;

		$result = wp_remote_post($url, [
				'body' => json_encode($params),
				'headers' => [
					'Accept' =>  'application/json',
					'Content-Type' => 'application/json'
				],
				'timeout' => 15,
				'sslverify' => false
			]
		);

		return json_decode($result['body'], true);
	}

	/**
	 * @param string $data
	 *
	 * @return string
	 */
	private function base64url_encode($data)
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

}