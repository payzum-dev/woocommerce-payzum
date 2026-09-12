<?php
/**
 * WooCommerce Blocks (Cart & Checkout) support for the Payzum gateway.
 *
 * The block checkout is a React app that ignores classic gateway rendering entirely: a gateway is
 * absent from it unless it registers a payment-method type here AND ships a script that calls
 * wc.wcBlocksRegistry.registerPaymentMethod(). The block checkout has been the default for new
 * stores since WooCommerce 8.3, so without this the gateway is invisible to most of them.
 *
 * Payzum collects nothing at checkout — the buyer picks the coin on the Payzum checkout — so the
 * content component renders only the gateway description. Everything that matters (creating the
 * invoice, the redirect, the IPN) happens server-side in WC_Gateway_Payzum, which the block
 * checkout reaches through the ordinary process_payment() path.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Payzum_Blocks_Support extends AbstractPaymentMethodType {

	/** Must match WC_Gateway_Payzum::$id. */
	protected $name = 'payzum';

	/** @var WC_Gateway_Payzum|null */
	private $gateway = null;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_payzum_settings', array() );
	}

	/**
	 * Whether to offer the method in the block checkout. Defer to the gateway itself so the
	 * "Enable/Disable" toggle and any availability rule keep working in one place.
	 */
	public function is_active() {
		$gateway = $this->gateway();
		return $gateway ? $gateway->is_available() : false;
	}

	/**
	 * Register and return the handles of the scripts the block checkout needs.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		$handle = 'payzum-blocks-checkout';

		wp_register_script(
			$handle,
			plugins_url( 'assets/payzum-blocks.js', PAYZUM_WC_PLUGIN_FILE ),
			array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			PAYZUM_WC_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'payzum-crypto-payments' );
		}

		return array( $handle );
	}

	/**
	 * Data handed to the front-end component.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data() {
		$gateway = $this->gateway();

		return array(
			'title'       => $gateway ? $gateway->get_title() : __( 'Crypto / Stablecoins (Payzum)', 'payzum-crypto-payments' ),
			'description' => $gateway ? $gateway->get_description() : '',
			'iconUrl'     => $gateway ? (string) $gateway->icon : '',
			// The block checkout hides a method whose gateway does not support the cart's contents.
			'supports'    => $gateway ? array_filter( $gateway->supports, array( $gateway, 'supports' ) ) : array( 'products' ),
		);
	}

	/** Resolve the live gateway instance rather than building a second one. */
	private function gateway() {
		if ( null !== $this->gateway ) {
			return $this->gateway;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways      = WC()->payment_gateways()->payment_gateways();
		$this->gateway = isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ] : null;
		return $this->gateway;
	}
}
