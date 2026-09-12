<?php
/**
 * Plugin Name: Payzum Crypto & Stablecoin Payments for WooCommerce
 * Plugin URI:  https://payzum.com
 * Description: Accept crypto and stablecoins (USDC/USDT, multi-chain) in WooCommerce with Payzum. Buyers choose the coin on the Payzum checkout. Non-custodial — funds settle to your own wallet.
 * Version:     1.5.0
 * Author:      Payzum
 * Author URI:  https://payzum.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: payzum-crypto-payments
 * Requires at least: 5.6
 * Requires PHP: 8.1
 * WC requires at least: 5.0
 * WC tested up to: 11.0
 *
 * Disclosure: contributed by Payzum. Opt-in payment gateway; does not change default behaviour.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'PAYZUM_WC_VERSION', '1.5.0' );
define( 'PAYZUM_WC_PLUGIN_FILE', __FILE__ );
define( 'PAYZUM_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Register the gateway with WooCommerce once WC is loaded.
 */
add_action( 'plugins_loaded', 'payzum_wc_init', 11 );

function payzum_wc_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'payzum_wc_missing_wc_notice' );
		return;
	}

	// WordPress only checks `Requires PHP` on activation, not after a host downgrade —
	// and the SDK does not even parse below 8.1.
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		add_action( 'admin_notices', 'payzum_wc_php_version_notice' );
		return;
	}

	// The official SDK (payzum/payzum-php) ships in vendor/. The guard keeps a shared
	// autoloader (Bedrock, composer-managed sites) from double-loading it.
	if ( ! class_exists( \Payzum\Payzum::class ) ) {
		$autoload = PAYZUM_WC_PLUGIN_DIR . 'vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			add_action( 'admin_notices', 'payzum_wc_missing_sdk_notice' );
			return;
		}
		require_once $autoload;
	}

	require_once PAYZUM_WC_PLUGIN_DIR . 'includes/class-wc-gateway-payzum.php';

	payzum_wc_maybe_upgrade();

	add_filter( 'woocommerce_payment_gateways', 'payzum_wc_add_gateway' );
	add_filter(
		'plugin_action_links_' . plugin_basename( __FILE__ ),
		'payzum_wc_settings_link'
	);
}

function payzum_wc_add_gateway( $gateways ) {
	$gateways[] = 'WC_Gateway_Payzum';
	return $gateways;
}

function payzum_wc_settings_link( $links ) {
	$url  = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payzum' );
	$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'payzum-crypto-payments' ) . '</a>';
	array_unshift( $links, $link );
	return $links;
}

function payzum_wc_missing_wc_notice() {
	echo '<div class="notice notice-error"><p>'
		. esc_html__( 'Payzum Crypto Payments requires WooCommerce to be installed and active.', 'payzum-crypto-payments' )
		. '</p></div>';
}

function payzum_wc_php_version_notice() {
	echo '<div class="notice notice-error"><p>'
		. sprintf(
			/* translators: %s: current PHP version */
			esc_html__( 'Payzum Crypto Payments requires PHP 8.1 or newer; this site runs %s. The gateway is disabled.', 'payzum-crypto-payments' ),
			esc_html( PHP_VERSION )
		)
		. '</p></div>';
}

function payzum_wc_missing_sdk_notice() {
	echo '<div class="notice notice-error"><p>'
		. esc_html__( 'Payzum Crypto Payments is missing its bundled SDK (vendor/autoload.php). Reinstall the plugin from an official release, or run `composer install` in the plugin directory.', 'payzum-crypto-payments' )
		. '</p></div>';
}

/**
 * One-time upgrade routine.
 *
 * 1.1.0 moved coin selection out of the plugin: the buyer now picks on the Payzum hosted checkout,
 * limited to the merchant's allowlist (Payzum dashboard -> Merchants -> Settings -> Accepted tokens).
 * The old per-store selection is therefore meaningless — drop it rather than leave dead settings
 * that no longer affect anything.
 */
function payzum_wc_maybe_upgrade() {
	if ( get_option( 'payzum_wc_version' ) === PAYZUM_WC_VERSION ) {
		return;
	}

	$settings = get_option( 'woocommerce_payzum_settings' );
	if ( is_array( $settings ) ) {
		$obsolete = array(
			'pay_currencies',   // multiselect of accepted coins
			'pay_currency',     // pre-1.1 single-currency text field
			'currency_tools',   // "Quick fill" buttons
			'signature_header', // the IPN header is fixed, never merchant-configurable
		);
		$changed = false;
		foreach ( $obsolete as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				unset( $settings[ $key ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( 'woocommerce_payzum_settings', $settings );
		}
	}

	// Cached GET /v1/currencies list — the plugin no longer reads that endpoint.
	delete_transient( 'payzum_supported_currencies' );

	update_option( 'payzum_wc_version', PAYZUM_WC_VERSION );
}

/**
 * Declare compatibility with the WooCommerce Cart & Checkout blocks, and register the block
 * payment-method type.
 *
 * The block checkout has been the default for new stores since WooCommerce 8.3 and ignores classic
 * gateway rendering entirely, so without this the gateway is simply absent from most modern stores
 * even though it is enabled and works in the classic checkout.
 */
add_action( 'woocommerce_blocks_loaded', 'payzum_wc_register_blocks_support' );

function payzum_wc_register_blocks_support() {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	require_once PAYZUM_WC_PLUGIN_DIR . 'includes/class-payzum-blocks-support.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( $registry ) {
			$registry->register( new Payzum_Blocks_Support() );
		}
	);
}

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			__FILE__,
			true
		);
	}
} );
