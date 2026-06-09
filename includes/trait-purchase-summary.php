<?php

trait Monri_WC_Purchase_Summary {

	/**
	 * Register the wp hook for purchase thank-you page detection.
	 *
	 * @return void
	 */
	protected function register_purchase_summary_hook() {
		add_action( 'template_redirect', [ $this, 'process_return_on_summary' ] );
	}

	/**
	 * Trigger process_return when on the thank-you page.
	 *
	 * @return void
	 */
	public function process_return_on_summary() {
		$order_id = $this->get_summary_order_identifier();

		if ( ! $order_id ) {
			return;
		}

		$this->process_return( $order_id );
	}

	/**
	 * Get the order identifier from the current request for thank-you page detection.
	 * Override this method in adapters that use different GET parameters.
	 *
	 * @return false|string Order ID or identifier, or false if not a valid return.
	 */
	protected function get_summary_order_identifier() {
		// Only process requests on the order-received endpoint.
		if ( ! is_wc_endpoint_url( 'order-received' ) ) {
			return false;
		}

		// Webpay (form/lightbox)
		if ( ! empty( $_GET['order_number'] ) || ! empty( $_GET['digest'] )) {
			$order_id = sanitize_text_field( $_GET['order_number'] );

			if ( $this->is_test_mode() ) {
				$order_id = Monri_WC_Utils::resolve_real_order_id( $order_id );
			}

			return $order_id;
		}

		// WSPay (form/iframe)
		if ( ! empty( $_GET['ShoppingCartID'] ) || ! empty( $_GET['Signature'] )) {
			$order_id = sanitize_text_field( $_GET['ShoppingCartID'] );

			if ( $this->is_test_mode() ) {
				$order_id = Monri_WC_Utils::resolve_real_order_id( $order_id );
			}

			return $order_id;
		}

		return false;
	}

	/**
	 * Check if test mode is enabled.
	 * Handles both adapter pattern ($this->payment) and direct gateway pattern ($this).
	 *
	 * @return bool
	 */
	protected function is_test_mode() {
		if ( isset( $this->payment ) && method_exists( $this->payment, 'get_option_bool' ) ) {
			return $this->payment->get_option_bool( 'test_mode' );
		}

		if ( method_exists( $this, 'get_option_bool' ) ) {
			return $this->get_option_bool( 'test_mode' );
		}

		return false;
	}
}
