<?php
/**
 * Payzum WooCommerce payment gateway.
 *
 * Flow:
 *   process_payment() -> SDK payments->create() with pay_currency:"all" -> redirect the buyer to
 *   invoice_url (hosted checkout, where the buyer picks the crypto). Payzum -> signed IPN (POST)
 *   -> handle_ipn() verifies via the SDK's Verifier (raw body, fixed header, constant time),
 *   deduplicates by event id, maps payment_status to the WC order.
 *   `finished` = fully paid -> payment_complete().
 *
 * Built on the official payzum/payzum-php SDK: HTTP client, HMAC verification, decimal-exact
 * amounts and the status vocabulary all live there, written and tested once.
 *
 * The plugin never lists or validates currencies: `pay_currency:"all"` defers the choice to the
 * buyer, and the hosted checkout offers only the merchant's accepted-tokens allowlist, enforced
 * server-side (Payzum dashboard -> Merchants -> Settings -> Accepted tokens).
 *
 * The order is fulfilled from the IPN, never from the buyer's return, so a closed browser tab
 * can't lose a paid order. Non-custodial: funds settle to the merchant's own wallet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Payzum\Errors\ApiException;
use Payzum\Errors\PayzumException;
use Payzum\Errors\SignatureException;
use Payzum\Payzum;
use Payzum\PaymentStatus;
use Payzum\Webhooks\Verifier;

class WC_Gateway_Payzum extends WC_Payment_Gateway {

	/** WooCommerce API callback slug -> https://site/wc-api/wc_gateway_payzum */
	const IPN_ROUTE = 'wc_gateway_payzum';

	/** Order meta holding recently seen IPN event ids — retries reuse the id. */
	const EVENT_IDS_META = '_payzum_ipn_event_ids';

	/** How many past event ids to keep per order for deduplication. */
	const EVENT_IDS_KEEP = 20;

	/** Sentinel that defers the pay-currency choice to the buyer on the hosted checkout. */
	const PAY_CURRENCY_ANY = 'all';

	/**
	 * How far the settled amount may fall short of the order total before the order is held.
	 *
	 * Half a cent: the order total is a decimal string and price_amount arrives as a JSON number,
	 * so the two round differently and `==` on floats would reject good payments.
	 */
	const AMOUNT_TOLERANCE = 0.005;

	/** Seconds to wait for the per-order IPN lock before giving up and asking for a retry. */
	const LOCK_TIMEOUT = 10;

	/** Embeddable checkout widget (modal / inline render modes). */
	const WIDGET_URL = 'https://merchant.payzum.com/widget/v1/payzum.js';

	public function __construct() {
		$this->id                 = 'payzum';
		$this->method_title       = __( 'Payzum (Crypto & Stablecoins)', 'payzum-crypto-payments' );
		$this->method_description = __( 'Accept crypto and stablecoins (USDC/USDT, multi-chain) via Payzum. Non-custodial — funds settle to your own wallet. Buyers pick the coin on the Payzum checkout; which coins you accept is configured in your Payzum dashboard.', 'payzum-crypto-payments' );
		// No checkout fields: the buyer chooses the currency on the Payzum hosted checkout.
		$this->has_fields         = false;
		$this->icon               = apply_filters( 'payzum_wc_icon', plugins_url( 'assets/payzum-mark.svg', PAYZUM_WC_PLUGIN_FILE ) );
		// 'products' only, deliberately: this gateway takes a one-off payment and never creates a
		// subscription, because the Payzum REST API does not expose one. WooCommerce Subscriptions
		// checks supports('subscriptions'), so leaving it out is what keeps Payzum off a
		// subscription checkout instead of charging once and never renewing.
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Signed IPN callback (works for both logged-in and guest / server-to-server calls).
		add_action( 'woocommerce_api_' . self::IPN_ROUTE, array( $this, 'handle_ipn' ) );

		// Modal / inline render modes hand off on the order-pay page instead of redirecting.
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );

		// Tell the shop owner why the gateway is hidden, since is_available() cannot.
		add_action( 'admin_notices', array( $this, 'missing_credentials_notice' ) );
	}

	/**
	 * Credentials that must be present before the gateway can take a payment.
	 *
	 * @return string[] Names of the missing settings, empty when everything is configured.
	 */
	private function missing_credentials() {
		$missing = array();
		if ( '' === trim( (string) $this->get_option( 'api_key' ) ) ) {
			$missing[] = __( 'API key', 'payzum-crypto-payments' );
		}
		if ( '' === trim( (string) $this->get_option( 'webhook_secret' ) ) ) {
			$missing[] = __( 'webhook secret', 'payzum-crypto-payments' );
		}
		return $missing;
	}

	/**
	 * Hide the gateway until it can actually complete a payment.
	 *
	 * Without a webhook secret the IPN cannot be verified, so every genuine delivery is rejected
	 * and the order never leaves its pending state — the buyer pays and the shop never knows. That
	 * failure costs real money and gives the shop owner no signal at all, so the gateway refuses to
	 * appear at checkout rather than accept a payment it cannot settle. Same for the API key, which
	 * without it cannot create the invoice in the first place.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		return array() === $this->missing_credentials();
	}

	/** Admin notice naming what is missing — the checkout gives no clue on its own. */
	public function missing_credentials_notice() {
		if ( 'yes' !== $this->enabled || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$missing = $this->missing_credentials();
		if ( array() === $missing ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Payzum is enabled but cannot take payments.', 'payzum-crypto-payments' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of missing settings */
					__( 'Missing: %s. The payment method is hidden at checkout until these are set, because without them a buyer could pay an invoice that never settles.', 'payzum-crypto-payments' ),
					implode( ', ', $missing )
				)
			)
		);
	}

	/** Configured render mode, falling back to the redirect flow for any unknown value. */
	private function render_mode() {
		$mode = (string) $this->get_option( 'render_mode', 'redirect' );
		return in_array( $mode, array( 'redirect', 'modal', 'inline' ), true ) ? $mode : 'redirect';
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'payzum-crypto-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Payzum crypto payments', 'payzum-crypto-payments' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'payzum-crypto-payments' ),
				'type'        => 'text',
				'description' => __( 'What buyers see at checkout.', 'payzum-crypto-payments' ),
				'default'     => __( 'Crypto / Stablecoins (Payzum)', 'payzum-crypto-payments' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'   => __( 'Description', 'payzum-crypto-payments' ),
				'type'    => 'textarea',
				'default' => __( 'Pay with USDC/USDT or other crypto. You will be redirected to a secure Payzum checkout, where you choose the coin.', 'payzum-crypto-payments' ),
			),
			'api_key'        => array(
				'title'       => __( 'API key', 'payzum-crypto-payments' ),
				'type'        => 'password',
				'description' => __( 'Your Payzum API key (64-hex). Dashboard → Merchants → API key.', 'payzum-crypto-payments' ),
				'default'     => '',
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook secret', 'payzum-crypto-payments' ),
				'type'        => 'password',
				'description' => __( 'The IPN signing secret from your Payzum webhook settings. Used to verify HMAC-SHA-512 signatures.', 'payzum-crypto-payments' ),
				'default'     => '',
			),
			'environment'    => array(
				'title'       => __( 'Environment', 'payzum-crypto-payments' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'production',
				'options'     => array(
					'production' => __( 'Production — merchant.payzum.com', 'payzum-crypto-payments' ),
					'staging'    => __( 'Staging / sandbox — staging.payzum.com (separate API keys)', 'payzum-crypto-payments' ),
				),
				'description' => __( 'Staging is an isolated environment with its own API keys — a production key will not work there. Use it to test end to end without real funds at stake.', 'payzum-crypto-payments' ),
			),
			'ipn_url_help'   => array(
				'title'       => __( 'Your IPN URL', 'payzum-crypto-payments' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: the IPN callback URL */
					__( 'Paste this into your Payzum webhook settings: %s', 'payzum-crypto-payments' ),
					'<code>' . esc_html( WC()->api_request_url( self::IPN_ROUTE ) ) . '</code>'
				),
			),
			'currencies_help' => array(
				'title'       => __( 'Accepted coins', 'payzum-crypto-payments' ),
				'type'        => 'title',
				'description' => __( 'Which crypto/stablecoins you accept is configured in your Payzum dashboard, under <strong>Merchants → Settings → Accepted tokens</strong>. The buyer picks one of those on the Payzum checkout — there is nothing to configure here.', 'payzum-crypto-payments' ),
			),
			'currency_mode'  => array(
				'title'       => __( 'Currency mode', 'payzum-crypto-payments' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'fiat',
				'options'     => array(
					'fiat'   => __( 'Fiat — prices are in a normal currency (default)', 'payzum-crypto-payments' ),
					'crypto' => __( 'Crypto — prices are already denominated in a coin', 'payzum-crypto-payments' ),
				),
				'description' => __( 'Fiat: Payzum converts your order total to whichever coin the buyer picks. Crypto: your prices are already in a coin, so the buyer pays that exact amount with no conversion — and no coin choice.', 'payzum-crypto-payments' ),
			),
			'price_currency' => array(
				'title'       => __( 'Fiat price currency', 'payzum-crypto-payments' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => '',
				'options'     => $this->fiat_currency_options(),
				'description' => __( 'Fiat mode only. Leave on <em>store currency</em> unless you know what you are doing: the order total is sent <strong>as-is</strong>, with no conversion, so picking a different currency here re-denominates the price rather than converting it.', 'payzum-crypto-payments' ),
			),
			'crypto_symbol'  => array(
				'title'             => __( 'Crypto symbol', 'payzum-crypto-payments' ),
				'type'              => 'text',
				'default'           => '',
				'placeholder'       => 'usdc',
				'description'       => __( 'Crypto mode only. The bare coin symbol your prices are in — <code>usdc</code>, <code>usdt</code>, <code>btc</code>. Not the network-suffixed ticker (<code>usdcmatic</code> is set via the field below).', 'payzum-crypto-payments' ),
				'custom_attributes' => array( 'maxlength' => '8' ),
			),
			'crypto_network' => array(
				'title'       => __( 'Crypto network', 'payzum-crypto-payments' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'polygon',
				'description' => __( 'Crypto mode only. The chain for that symbol — <code>polygon</code>, <code>tron</code>, <code>ethereum</code>, <code>solana</code>… Leave empty for a native coin such as <code>btc</code>.', 'payzum-crypto-payments' ),
			),
			'render_mode'    => array(
				'title'       => __( 'Render mode', 'payzum-crypto-payments' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'redirect',
				'options'     => array(
					'redirect' => __( 'Redirect — send the buyer to the Payzum checkout (default)', 'payzum-crypto-payments' ),
					'modal'    => __( 'Modal — open the checkout in an overlay on your site', 'payzum-crypto-payments' ),
					'inline'   => __( 'Inline — embed the checkout in the order page', 'payzum-crypto-payments' ),
				),
				'description' => __( 'Modal and inline load the Payzum widget on the order-pay page. If your site sends a Content-Security-Policy header, allow <code>merchant.payzum.com</code> in <code>script-src</code>, <code>frame-src</code> and <code>connect-src</code>.', 'payzum-crypto-payments' ),
			),
			'debug'          => array(
				'title'   => __( 'Debug log', 'payzum-crypto-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Log gateway events to WooCommerce → Status → Logs (source: payzum).', 'payzum-crypto-payments' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Create the Payzum invoice and hand the buyer off to the hosted checkout.
	 *
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'payzum-crypto-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// A 100%-off coupon or a free product leaves nothing to charge, and the API answers
		// 400 INVALID_REQUEST ("price_amount: Number must be greater than 0"). Complete the order
		// here rather than sending the buyer to a checkout that cannot succeed.
		$total = (float) $order->get_total();
		if ( $total <= 0 ) {
			$this->log( 'order ' . $order_id . ' has a zero total — completing without an invoice' );
			$order->payment_complete();
			$order->add_order_note( __( 'Payzum: nothing to charge, order completed without a crypto invoice.', 'payzum-crypto-payments' ) );
			if ( function_exists( 'WC' ) && WC()->cart ) {
				WC()->cart->empty_cart();
			}
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		$pricing = $this->pricing_payload( $order );

		try {
			// The amount travels as a string end to end: the SDK writes it into the JSON as an
			// exact number (Json::encodeWithExactNumbers). Casting to float would round it on
			// the way out, which is the silent bug the SDK exists to prevent.
			$result = $this->payzum_client()->payments->create(
				priceAmount:    (string) $order->get_total(),
				priceCurrency:  $pricing['price_currency'],
				payCurrency:    $pricing['pay_currency'],
				orderId:        $this->order_reference( $order ),
				orderDescription: $this->order_description( $order ),
				network:        $pricing['network'],
				ipnCallbackUrl: WC()->api_request_url( self::IPN_ROUTE ),
				successUrl:     $this->get_return_url( $order ),
				cancelUrl:      $order->get_checkout_payment_url(),
				pricingMode:    $pricing['pricing_mode'],
			);
		} catch ( ApiException $e ) {
			// Branch on the typed code, never on the message — messages change between releases.
			$this->log( 'create failed for order ' . $order_id . ' [' . $e->rawCode . ']: ' . $e->getMessage() );
			wc_add_notice( $this->buyer_notice_for( $e->rawCode ), 'error' );
			return array( 'result' => 'failure' );
		} catch ( PayzumException $e ) {
			// Local validation or transport failure. The SDK never auto-retries invoice creation
			// without an Idempotency-Key: a blind retry could create a second real invoice.
			$this->log( 'create failed for order ' . $order_id . ': ' . $e->getMessage() );
			wc_add_notice( __( 'Unable to start the crypto payment. Please try again or pick another method.', 'payzum-crypto-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$invoice_url = isset( $result['invoice_url'] ) ? (string) $result['invoice_url'] : '';
		if ( '' === $invoice_url ) {
			$this->log( 'create returned no invoice_url for order ' . $order_id . ': ' . wp_json_encode( $result ) );
			wc_add_notice( __( 'Payzum did not return a checkout URL. Please try again.', 'payzum-crypto-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$payment_id = isset( $result['payment_id'] ) ? (string) $result['payment_id'] : '';
		if ( '' !== $payment_id ) {
			$order->update_meta_data( '_payzum_payment_id', sanitize_text_field( $payment_id ) );
		}
		// The invoice is a draft until the buyer picks a coin, and reads don't return the URL —
		// keep it so the order can link back to the checkout.
		$order->update_meta_data( '_payzum_invoice_url', esc_url_raw( $invoice_url ) );

		if ( 'redirect' === $this->render_mode() ) {
			$order->update_status(
				'on-hold',
				__( 'Awaiting Payzum crypto payment — the buyer picks the coin on the Payzum checkout.', 'payzum-crypto-payments' )
			);
		} else {
			// Modal/inline mount the widget on the order-pay page, and WooCommerce only renders
			// that page for a payable status (pending/failed) — moving to on-hold here would leave
			// the buyer on "this order cannot be paid for". The IPN advances it instead.
			$order->add_order_note(
				__( 'Payzum invoice created — awaiting payment through the on-site Payzum checkout.', 'payzum-crypto-payments' )
			);
		}
		$order->save();

		wc_reduce_stock_levels( $order_id );
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		// Redirect hands off to Payzum's own page; modal/inline keep the buyer on this site and
		// mount the widget on the order-pay page (see receipt_page()).
		return array(
			'result'   => 'success',
			'redirect' => 'redirect' === $this->render_mode()
				? $invoice_url
				: $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * The pricing half of the create call, in the SDK's vocabulary.
	 *
	 * Fiat (default): the buyer picks the coin on the Payzum checkout, so pay_currency is "all"
	 * and Payzum converts from the order currency.
	 *
	 * Crypto: prices are already denominated in a coin, so pricing_mode "direct" sends the total
	 * through unconverted. The API requires price_currency to equal the *bare* pay symbol
	 * (max 8 chars — `usdc`, not `usdcmatic`), with the chain in `network`.
	 *
	 * @return array{price_currency: string, pay_currency: string, pricing_mode: string, network: ?string}
	 */
	private function pricing_payload( WC_Order $order ) {
		if ( 'crypto' === $this->get_option( 'currency_mode', 'fiat' ) ) {
			$symbol  = $this->crypto_symbol();
			$network = strtolower( trim( (string) $this->get_option( 'crypto_network', '' ) ) );

			if ( '' !== $symbol ) {
				return array(
					'price_currency' => $symbol,
					'pay_currency'   => $symbol,
					'pricing_mode'   => 'direct',
					'network'        => '' !== $network ? $network : null,
				);
			}

			// Misconfigured: fall through to fiat rather than failing the checkout outright.
			$this->log( 'currency_mode=crypto but no crypto_symbol configured — falling back to fiat for order ' . $order->get_id() );
		}

		$configured = strtolower( trim( (string) $this->get_option( 'price_currency', '' ) ) );

		return array(
			// Empty setting = the order's own currency, which is the only always-correct value:
			// the total is sent as-is, never converted.
			'price_currency' => '' !== $configured ? $configured : strtolower( $order->get_currency() ),
			// "all" defers the coin choice to the buyer, limited to the merchant's allowlist.
			'pay_currency'   => self::PAY_CURRENCY_ANY,
			'pricing_mode'   => 'fiat',
			'network'        => null,
		);
	}

	/** An actionable checkout notice for the error codes a buyer can do something about. */
	private function buyer_notice_for( $raw_code ) {
		switch ( $raw_code ) {
			case 'AMOUNT_BELOW_MINIMUM':
				return __( 'This order total is below the minimum for crypto payment. Add more to your cart or choose another payment method.', 'payzum-crypto-payments' );
			case 'CURRENCY_NOT_SUPPORTED':
			case 'NO_ELIGIBLE_CURRENCIES':
				return __( 'Crypto payment is not available for this currency or amount. Please choose another payment method.', 'payzum-crypto-payments' );
			default:
				return __( 'Unable to start the crypto payment. Please try again or pick another method.', 'payzum-crypto-payments' );
		}
	}

	/** Sanitised bare coin symbol for crypto mode ("usdc"), or '' when unset. */
	private function crypto_symbol() {
		$symbol = strtolower( trim( (string) $this->get_option( 'crypto_symbol', '' ) ) );
		$symbol = preg_replace( '/[^a-z0-9]/', '', $symbol );
		return (string) substr( (string) $symbol, 0, 8 );
	}

	/** Fiat options for the price-currency select: store currency first, then WooCommerce's list. */
	private function fiat_currency_options() {
		$options = array( '' => __( 'Store currency (recommended)', 'payzum-crypto-payments' ) );
		if ( function_exists( 'get_woocommerce_currencies' ) ) {
			foreach ( get_woocommerce_currencies() as $code => $name ) {
				$options[ strtolower( $code ) ] = $code . ' — ' . $name;
			}
		}
		return $options;
	}

	/**
	 * Order-pay page for the modal / inline render modes: mount the Payzum widget against the
	 * invoice created in process_payment().
	 *
	 * The widget's callbacks are UI hints only — they move the buyer to the right page. Whether the
	 * order is actually paid is decided solely by the signed IPN.
	 *
	 * @param int $order_id
	 */
	public function receipt_page( $order_id ) {
		$mode = $this->render_mode();
		if ( 'redirect' === $mode ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$payment_id  = (string) $order->get_meta( '_payzum_payment_id' );
		$invoice_url = (string) $order->get_meta( '_payzum_invoice_url' );

		if ( '' === $payment_id ) {
			// Nothing to mount — offer the hosted checkout instead of a blank page.
			if ( '' !== $invoice_url ) {
				printf(
					'<p><a class="button" href="%1$s">%2$s</a></p>',
					esc_url( $invoice_url ),
					esc_html__( 'Pay with crypto', 'payzum-crypto-payments' )
				);
			}
			return;
		}

		$container = 'payzum-checkout-' . (int) $order_id;

		printf(
			'<div id="%1$s" class="payzum-checkout" data-mode="%2$s"></div>',
			esc_attr( $container ),
			esc_attr( $mode )
		);

		// Fallback for buyers with JS disabled or a CSP that blocks the widget.
		if ( '' !== $invoice_url ) {
			printf(
				'<noscript><p><a class="button" href="%1$s">%2$s</a></p></noscript>',
				esc_url( $invoice_url ),
				esc_html__( 'Pay with crypto', 'payzum-crypto-payments' )
			);
		}

		wp_enqueue_script( 'payzum-widget', self::WIDGET_URL, array(), PAYZUM_WC_VERSION, true );
		wp_add_inline_script( 'payzum-widget', $this->widget_bootstrap( $mode, $payment_id, $container, $order ) );
	}

	/**
	 * Inline JS that opens the widget once it has loaded.
	 *
	 * @param string   $mode       modal|inline
	 * @param string   $payment_id Payzum invoice id
	 * @param string   $container  DOM id to mount into
	 * @param WC_Order $order
	 * @return string
	 */
	private function widget_bootstrap( $mode, $payment_id, $container, WC_Order $order ) {
		$config = array(
			'mode'      => $mode,
			'paymentId' => $payment_id,
			'container' => $container,
			'returnUrl' => $this->get_return_url( $order ),
			'cancelUrl' => $order->get_checkout_payment_url(),
			'invoiceUrl'=> (string) $order->get_meta( '_payzum_invoice_url' ),
		);

		// The widget script is loaded in the footer and may still be parsing; poll briefly rather
		// than assuming window.Payzum exists the moment this runs.
		return '(function(){'
			. 'var cfg=' . wp_json_encode( $config ) . ';'
			. 'var tries=0;'
			. 'function go(){'
			. 'if(!window.Payzum||!window.Payzum.open){'
			. 'if(++tries>100){if(cfg.invoiceUrl){window.location.href=cfg.invoiceUrl;}return;}'
			. 'return window.setTimeout(go,100);'
			. '}'
			. 'var opts={'
			. 'onSuccess:function(){window.location.href=cfg.returnUrl;},'
			. 'onPartial:function(){window.location.href=cfg.returnUrl;},'
			. 'onExpired:function(){window.location.href=cfg.cancelUrl;},'
			. 'onCancel:function(){window.location.href=cfg.cancelUrl;}'
			. '};'
			. 'if(cfg.mode==="inline"){'
			. 'var el=document.getElementById(cfg.container);'
			. 'if(el){window.Payzum.openInline(cfg.paymentId,el,opts);}'
			. '}else{'
			. 'window.Payzum.open(cfg.paymentId,opts);'
			. '}'
			. '}'
			. 'go();'
			. '})();';
	}

	/**
	 * Handle a signed IPN.
	 *
	 * The SDK's Verifier does the dangerous parts: it reads the correct, fixed signature header
	 * itself (case-insensitively, CGI form included), verifies HMAC-SHA-512 over the RAW bytes in
	 * constant time, and enforces the 10-minute replay window on the signed event_at. On top of
	 * that this handler deduplicates by event id — delivery retries reuse it, so a second
	 * delivery must be a no-op, not a second fulfilment.
	 *
	 * Verification deliberately uses `new Verifier($secret)` and not the Payzum entry class:
	 * verifying an already-paid IPN must not depend on the API key being configured.
	 */
	public function handle_ipn() {
		$raw = file_get_contents( 'php://input' );
		if ( '' === $raw || false === $raw ) {
			$this->respond( 400, 'empty body' );
		}

		$secret = (string) $this->get_option( 'webhook_secret' );
		if ( '' === $secret ) {
			$this->log( 'IPN received but no webhook secret configured.' );
			$this->respond( 500, 'not configured' );
		}

		$headers = $this->request_headers();

		try {
			$verifier = new Verifier( $secret );
			$data     = $verifier->verifyPaymentIpn( $raw, $headers );
		} catch ( SignatureException $e ) {
			$this->log( 'IPN rejected (' . $e->reason . '): ' . $e->getMessage() );
			$this->respond( 401, 'bad signature' );
			return; // respond() exits; this keeps static analysis honest.
		} catch ( PayzumException $e ) {
			$this->log( 'IPN body unusable: ' . $e->getMessage() );
			$this->respond( 400, 'bad json' );
			return;
		}

		$reference = isset( $data['order_id'] ) ? (string) $data['order_id'] : '';
		$status    = isset( $data['payment_status'] ) ? (string) $data['payment_status'] : '';
		$order     = $this->resolve_order( $reference );

		if ( ! $order ) {
			$this->log( 'IPN for unknown order reference: ' . $reference );
			$this->respond( 404, 'order not found' );
		}

		// Everything from here to the release is one critical section: read the event ids, decide
		// the transition, write it, record the event id. Two deliveries carrying DIFFERENT event
		// ids (the first delivery and a retry of an earlier transition, say) both used to read
		// "not paid yet" and both called payment_complete() — two emails, stock reduced twice,
		// downloads granted twice. The dedup check has to be inside the lock as well: on its own
		// it only catches the same event id, and it is itself a read-then-write.
		$lock = $this->acquire_order_lock( $order->get_id() );
		if ( false === $lock ) {
			// Another delivery for this order is mid-transition. 503 rather than 200: this IPN is
			// not a duplicate, only late, and Payzum retrying it is exactly the right outcome.
			$this->log( 'IPN for order ' . $order->get_id() . ' could not take the order lock — asking for a retry' );
			$this->respond( 503, 'busy' );
		}

		// Re-read the order now that the lock is held: it was loaded before we waited, so a
		// concurrent delivery may have settled it since and this copy would still read "not
		// paid". WooCommerce caches order objects per request, so the cached entry has to go
		// first or the re-read hands back the same stale object. (HPOS keys by id, the legacy
		// CPT store by 'order-<id>'; dropping both is cheap and harmless.)
		wp_cache_delete( $order->get_id(), 'orders' );
		wp_cache_delete( 'order-' . $order->get_id(), 'orders' );
		$fresh = wc_get_order( $order->get_id() );
		if ( $fresh ) {
			$order = $fresh;
		}

		// Retries and multi-transition deliveries reuse the event id; a replay must be a no-op.
		$event_id = (string) $verifier->eventId( $headers );
		if ( '' !== $event_id && $this->is_duplicate_event( $order, $event_id ) ) {
			$this->log( 'IPN duplicate event ' . $event_id . ' for order ' . $order->get_id() . ' — ignored' );
			$this->release_order_lock( $lock );
			$this->respond( 200, 'duplicate' );
		}

		// Once the buyer has picked a coin the IPN carries it — record it for the shop manager.
		if ( ! empty( $data['pay_currency'] ) ) {
			$order->update_meta_data( '_payzum_pay_currency', sanitize_text_field( (string) $data['pay_currency'] ) );
		}

		$this->log( 'IPN verified via x-nowpayments-sig for order ' . $order->get_id() . ' status=' . $status . ( '' !== $event_id ? ' event=' . $event_id : '' ) );
		$this->apply_status( $order, $status, $data );

		if ( '' !== $event_id ) {
			$this->remember_event( $order, $event_id );
		}

		$this->release_order_lock( $lock );
		$this->respond( 200, 'ok' );
	}

	/**
	 * Take a cross-request lock for this order, for the length of the IPN transition.
	 *
	 * GET_LOCK is the only lock WordPress can count on here. wp_cache_add() is request-local
	 * unless a persistent object cache is installed, so it would look like a lock on every store
	 * that has none and protect nothing — the failure mode this guard exists to prevent.
	 *
	 * @param int $order_id
	 * @return string|null|false lock name when acquired; false when it timed out (another
	 *                           delivery holds it); null when the database offers no lock, in
	 *                           which case the caller proceeds unlocked rather than refusing a
	 *                           payment it can still process.
	 */
	private function acquire_order_lock( $order_id ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}
		// GET_LOCK names are scoped to the whole MySQL server, not to a database or a connection,
		// so every part of "which install, which site, which plugin, which record" has to be in
		// the name or unrelated stores block each other on a shared server.
		//
		// DB_NAME separates installs. $wpdb->prefix separates the sites of a multisite network
		// and the several WordPress installs people put in one database with different prefixes.
		// The 'wc-order' tag separates THIS plugin from the Payzum plugins for EDD, PMPro and
		// GiveWP: they all key by a small integer id, so without it WooCommerce order 500 and
		// PMPro order 500 on the same site hash to the same lock and 503 each other. Hashed to
		// stay inside GET_LOCK's 64-character limit.
		$name = 'payzum_' . substr( md5( DB_NAME . '|' . $wpdb->prefix . '|wc-order|' . $order_id ), 0, 32 );

		// 1 = acquired, 0 = timed out, NULL = error (or a server without GET_LOCK).
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT ) );
		if ( '1' === (string) $got ) {
			return $name;
		}
		if ( null === $got ) {
			$this->log( 'GET_LOCK unavailable — processing IPN for order ' . $order_id . ' without a lock' );
			return null;
		}
		return false;
	}

	/** Release a lock taken by acquire_order_lock(). Safe to call with null (nothing was taken). */
	private function release_order_lock( $name ) {
		global $wpdb;

		if ( ! is_string( $name ) || '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Request headers for the Verifier. getallheaders() when the SAPI provides it, otherwise the
	 * raw $_SERVER array — the Verifier accepts the CGI form (HTTP_X_NOWPAYMENTS_SIG) directly.
	 *
	 * @return array<string, string>
	 */
	private function request_headers() {
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				return $headers;
			}
		}
		return array_filter( $_SERVER, 'is_string' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- raw bytes needed for HMAC lookup; never echoed.
	}

	/** Whether this event id was already processed for the order. */
	private function is_duplicate_event( WC_Order $order, $event_id ) {
		$seen = $order->get_meta( self::EVENT_IDS_META );
		return is_array( $seen ) && in_array( $event_id, $seen, true );
	}

	/** Record a processed event id, keeping only the most recent ones. */
	private function remember_event( WC_Order $order, $event_id ) {
		$seen   = $order->get_meta( self::EVENT_IDS_META );
		$seen   = is_array( $seen ) ? $seen : array();
		$seen[] = $event_id;
		$order->update_meta_data( self::EVENT_IDS_META, array_slice( $seen, -self::EVENT_IDS_KEEP ) );
		$order->save();
	}

	/**
	 * Map a Payzum payment_status onto the WooCommerce order. Idempotent — repeated IPNs for an
	 * already-paid order are ignored.
	 *
	 * The SDK's PaymentStatus models the five values the contract promises (waiting,
	 * partially_paid, finished, expired, failed) and throws on anything else. The guard degrades
	 * an unknown value to an order note instead of a 500: a contract change should surface as a
	 * note to investigate, not as six failed delivery retries.
	 *
	 * @param WC_Order $order
	 * @param string   $status
	 * @param array    $data   the verified payload — `finished` is only honoured when the amount
	 *                         and currency in it match the order.
	 */
	private function apply_status( WC_Order $order, $status, array $data = array() ) {
		try {
			$mapped = PaymentStatus::fromMerchant( $status );
		} catch ( PayzumException $e ) {
			$this->log( 'IPN carried an unknown payment_status "' . $status . '" — contract change?' );
			$order->add_order_note( sprintf( /* translators: %s: status */ __( 'Payzum: status update — %s.', 'payzum-crypto-payments' ), $status ) );
			$order->save();
			return;
		}

		// A settled order must never be walked backwards.
		//
		// The previous form of this guard also required the INCOMING status to be paid, so it
		// stopped a duplicate `finished` and nothing else. Payzum retries a delivery up to six
		// times and fires on more than one transition, so a late `expired` or `failed` for an
		// invoice that was ultimately paid is an ordinary occurrence — and it was cancelling the
		// order. In WooCommerce that is destructive rather than cosmetic: `cancelled` restores
		// stock and can email the customer. `partially_paid` arriving late likewise pushed a paid
		// order back to on-hold.
		//
		// Verified against a real store: order 142, settled by a `finished` IPN, was cancelled by
		// a later signed `expired` and then failed by a `failed`.
		if ( $order->is_paid() ) {
			if ( ! $mapped->isPaid() ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: status */
						__( 'Payzum: %s received after the order was paid — ignored.', 'payzum-crypto-payments' ),
						$mapped->value
					)
				);
				$order->save();
			}
			return;
		}

		// Payzum retries a delivery up to five times, and fires on more than one transition, so
		// every branch has to be a no-op once the order already reads that way. Without this the
		// order collects the same note again on every retry.
		$current = $order->get_status();

		switch ( $mapped ) {
			case PaymentStatus::Finished:
				// `finished` only says the Payzum invoice settled — it says nothing about that
				// invoice having been for THIS order's total. Crediting on the status alone
				// fulfils an order paid with a smaller invoice, or one denominated in a cheaper
				// currency. The payload carries both numbers, so check them before shipping.
				$mismatch = $this->settlement_mismatch( $order, $data );
				if ( null !== $mismatch ) {
					$this->log( 'IPN finished for order ' . $order->get_id() . ' rejected: ' . $mismatch );
					if ( 'on-hold' !== $current ) {
						$order->update_status(
							'on-hold',
							sprintf(
								/* translators: %s: what did not match, e.g. "paid 3.00 usd, order total is 6.00 usd" */
								__( 'Payzum: reported as finished but %s — held for review, NOT completed.', 'payzum-crypto-payments' ),
								$mismatch
							)
						);
					} else {
						$order->add_order_note(
							sprintf(
								/* translators: %s: what did not match */
								__( 'Payzum: reported as finished but %s — held for review, NOT completed.', 'payzum-crypto-payments' ),
								$mismatch
							)
						);
					}
					break;
				}
				$order->payment_complete( (string) $order->get_meta( '_payzum_payment_id' ) );
				$order->add_order_note( __( 'Payzum: payment confirmed in full (finished).', 'payzum-crypto-payments' ) );
				break;

			case PaymentStatus::PartiallyPaid:
				if ( 'on-hold' === $current ) {
					return;
				}
				$order->update_status( 'on-hold', __( 'Payzum: partial payment received — awaiting the remainder.', 'payzum-crypto-payments' ) );
				break;

			case PaymentStatus::Expired:
				if ( 'cancelled' === $current ) {
					return;
				}
				$order->update_status( 'cancelled', __( 'Payzum: invoice expired before full payment.', 'payzum-crypto-payments' ) );
				break;

			case PaymentStatus::Failed:
				if ( 'failed' === $current ) {
					return;
				}
				$order->update_status( 'failed', __( 'Payzum: payment failed.', 'payzum-crypto-payments' ) );
				break;

			case PaymentStatus::Waiting:
			default:
				$order->add_order_note( sprintf( /* translators: %s: status */ __( 'Payzum: status update — %s.', 'payzum-crypto-payments' ), $mapped->value ) );
				break;
		}
		$order->save();
	}

	/**
	 * How the settled amount/currency differ from the order, as a human phrase, or null when they
	 * match.
	 *
	 * Compared against the same two values the create call was built from — the order total, and
	 * pricing_payload()'s price_currency rather than the raw order currency, because in crypto
	 * pricing mode the invoice is denominated in the coin symbol and the order currency would
	 * never match.
	 *
	 * An amount that cannot be read as a number is NOT a pass. price_amount is `required` in the
	 * contract, so null, "", an array or an object means either a contract break or a forged
	 * payload — and in both cases the one number that proves the invoice was raised for this
	 * order is missing. Skipping the comparison there would complete the order unverified, which
	 * is exactly how an attacker turns a 1-cent invoice into a paid order. Unverifiable is
	 * treated as mismatched.
	 *
	 * @param WC_Order $order
	 * @param array    $data verified payload
	 * @return string|null
	 */
	private function settlement_mismatch( WC_Order $order, array $data ) {
		$expected_amount   = (float) $order->get_total();
		$pricing           = $this->pricing_payload( $order );
		$expected_currency = strtolower( (string) $pricing['price_currency'] );

		$has_amount   = isset( $data['price_amount'] ) && is_numeric( $data['price_amount'] );
		$paid_amount  = $has_amount ? (float) $data['price_amount'] : 0.0;
		$paid_currency = isset( $data['price_currency'] ) ? strtolower( trim( (string) $data['price_currency'] ) ) : '';

		if ( ! $has_amount ) {
			$this->log( 'IPN for order ' . $order->get_id() . ' carried no usable price_amount — settlement could not be verified, not crediting' );
			return sprintf(
				/* translators: 1: order total, 2: order currency */
				__( 'it reported no readable settled amount, so it cannot be shown to cover the %1$s %2$s this order is for', 'payzum-crypto-payments' ),
				wc_format_decimal( $expected_amount, wc_get_price_decimals() ),
				strtoupper( $expected_currency )
			);
		}

		// Never `==` on floats, and never a strict "must be exact": an overpayment is still a
		// paid order, so only a SHORTFALL beyond half a cent counts.
		if ( ( $expected_amount - $paid_amount ) > self::AMOUNT_TOLERANCE ) {
			return sprintf(
				/* translators: 1: settled amount, 2: settled currency, 3: order total, 4: order currency */
				__( 'it settled %1$s %2$s while the order is for %3$s %4$s', 'payzum-crypto-payments' ),
				wc_format_decimal( $paid_amount, wc_get_price_decimals() ),
				'' !== $paid_currency ? strtoupper( $paid_currency ) : strtoupper( $expected_currency ),
				wc_format_decimal( $expected_amount, wc_get_price_decimals() ),
				strtoupper( $expected_currency )
			);
		}

		// Case-insensitive: the API echoes the currency in whatever case it stored it.
		if ( '' !== $paid_currency && '' !== $expected_currency && $paid_currency !== $expected_currency ) {
			return sprintf(
				/* translators: 1: settled currency, 2: expected currency */
				__( 'it settled in %1$s while the order was invoiced in %2$s', 'payzum-crypto-payments' ),
				strtoupper( $paid_currency ),
				strtoupper( $expected_currency )
			);
		}

		return null;
	}

	/**
	 * Find the order from the reference we sent as order_id.
	 *
	 * A reference whose suffix does not match is NOT this order: returning the order anyway (as
	 * this did) threw away the whole point of the suffix, since the numeric prefix alone is
	 * guessable. Pre-1.1 invoices sent the bare id, so that shape is still accepted — orphaning
	 * them would strand real payments.
	 */
	private function resolve_order( $reference ) {
		if ( '' === $reference ) {
			return false;
		}
		// We send "<id>-<order_key-ish>"; the numeric prefix is the WC order id.
		$order_id = (int) $reference;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			return false;
		}
		if ( $this->order_reference( $order ) === $reference || (string) $order_id === $reference ) {
			return $order;
		}
		$this->log( 'IPN reference ' . $reference . ' does not match order ' . $order_id . ' — rejected' );
		return false;
	}

	/**
	 * A per-merchant-unique order reference. WC order ids are unique; we suffix the order key so a
	 * guessed id can't be spoofed into matching.
	 */
	private function order_reference( WC_Order $order ) {
		return $order->get_id() . '-' . substr( (string) $order->get_order_key(), -8 );
	}

	/** Short human description shown on the Payzum hosted checkout (API caps this at 2000). */
	private function order_description( WC_Order $order ) {
		$text = sprintf(
			/* translators: 1: order number, 2: store name */
			__( 'Order %1$s at %2$s', 'payzum-crypto-payments' ),
			$order->get_order_number(),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		return mb_substr( $text, 0, 2000 );
	}

	/**
	 * The SDK entry point, pointed at the configured environment.
	 *
	 * Built per call rather than cached: the admin can save a new key or switch environment and
	 * the next request must honour it.
	 */
	private function payzum_client() {
		$api_key = trim( (string) $this->get_option( 'api_key' ) );

		return 'staging' === $this->get_option( 'environment', 'production' )
			? Payzum::sandbox( $api_key )
			: new Payzum( $api_key );
	}

	private function respond( $code, $message ) {
		status_header( $code );
		nocache_headers();
		echo esc_html( $message );
		exit;
	}

	public function log( $message ) {
		if ( 'yes' !== $this->get_option( 'debug' ) ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( $message, array( 'source' => 'payzum' ) );
		}
	}
}
