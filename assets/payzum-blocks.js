/**
 * Registers Payzum with the WooCommerce Blocks checkout.
 *
 * The block checkout is a React app and will not show a gateway that has not registered here, no
 * matter that the gateway is enabled and works in the classic checkout.
 *
 * Payzum collects nothing on the form — the buyer picks the coin on the Payzum checkout — so the
 * content is just the gateway description. Creating the invoice, the redirect and the IPN all stay
 * server-side in WC_Gateway_Payzum::process_payment(), which the block checkout calls through the
 * ordinary store API path.
 */
( function ( registry, element, htmlEntities, i18n, settingsApi ) {
	'use strict';

	if ( ! registry || ! registry.registerPaymentMethod ) {
		return;
	}

	var createElement = element.createElement;
	var decodeEntities = htmlEntities.decodeEntities;
	var __ = i18n.__;

	var settings = settingsApi && settingsApi.getSetting
		? settingsApi.getSetting( 'payzum_data', {} )
		: ( window.wc && window.wc.wcSettings
			? window.wc.wcSettings.getSetting( 'payzum_data', {} )
			: {} );

	var title = decodeEntities( settings.title || __( 'Crypto / Stablecoins (Payzum)', 'payzum-crypto-payments' ) );

	function Label( props ) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		if ( ! PaymentMethodLabel ) {
			return createElement( 'span', null, title );
		}
		return createElement( PaymentMethodLabel, { text: title, icon: settings.iconUrl || undefined } );
	}

	function Content() {
		return createElement( 'div', null, decodeEntities( settings.description || '' ) );
	}

	registry.registerPaymentMethod( {
		name: 'payzum',
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		// The gateway is always usable when it is enabled: there are no fields to complete and no
		// card to validate, so there is nothing that could make it temporarily unavailable.
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: settings.supports || [ 'products' ]
		}
	} );
} )(
	window.wc && window.wc.wcBlocksRegistry,
	window.wp && window.wp.element,
	window.wp && window.wp.htmlEntities,
	window.wp && window.wp.i18n,
	window.wc && window.wc.wcSettings
);
