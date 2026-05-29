<?php

/**
 * Trait for handling Elementor custom purchase summary page.
 *
 * Adds support for processing payment returns on Elementor's custom
 * purchase summary page where woocommerce_before_thankyou may not fire.
 *
 * Usage: Use this trait in any gateway adapter/class that has a process_return() method,
 * then call $this->register_elementor_purchase_summary_hook() in your init/constructor.
 */
trait Monri_WC_Elementor_Purchase_Summary {

	/**
	 * Register the wp hook for Elementor purchase summary page detection.
	 *
	 * @return void
	 */
	protected function register_elementor_purchase_summary_hook() {
		add_action( 'wp', [ $this, 'process_return_on_elementor_summary' ] );
	}

	/**
	 * Trigger process_return when on the Elementor purchase summary page.
	 * This is needed because woocommerce_before_thankyou may not fire on Elementor's
	 * custom purchase summary page.
	 *
	 * @return void
	 */
	public function process_return_on_elementor_summary() {
		$order_identifier = $this->get_elementor_summary_order_identifier();

		if ( ! $order_identifier ) {
			return;
		}

		$purchase_summary_page_id = get_option( 'elementor_woocommerce_purchase_summary_page_id' );

		if ( ! $purchase_summary_page_id || (int) $purchase_summary_page_id !== get_queried_object_id() ) {
			return;
		}

		$this->process_return( $order_identifier );
	}

	/**
	 * Get the order identifier from the current request for Elementor summary page detection.
	 * Override this method in adapters that use different GET parameters.
	 *
	 * @return false|string Order ID or identifier, or false if not a valid return.
	 */
	protected function get_elementor_summary_order_identifier() {
		// Webpay (form/lightbox): order_number
		if ( ! empty( $_GET['order_number'] ) ) {
			$order_id = sanitize_text_field( $_GET['order_number'] );

			if ( $this->is_test_mode() ) {
				$order_id = Monri_WC_Utils::resolve_real_order_id( $order_id );
			}

			return $order_id;
		}

		// WSPay (form/iframe): ShoppingCartID
		if ( ! empty( $_GET['ShoppingCartID'] ) ) {
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
	private function is_test_mode() {
		if ( isset( $this->payment ) && method_exists( $this->payment, 'get_option_bool' ) ) {
			return $this->payment->get_option_bool( 'test_mode' );
		}

		if ( method_exists( $this, 'get_option_bool' ) ) {
			return $this->get_option_bool( 'test_mode' );
		}

		return false;
	}
}
