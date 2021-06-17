<?php

add_filter( 'woocommerce_gateway_description', 'mode_gateway_description_fields', 20, 2 );
remove_filter('woocommerce_gateway_description', 'wpautop');

function mode_gateway_description_fields( $description, $payment_id ) {
	if ($payment_id === 'mode_gateway') {
		global $woocommerce;
		ob_start();

		$cashbackRate = get_option('mode_merchant_cashback');
		$modeLogo = plugin_dir_url( __FILE__ ).'assets/logo.svg';
		$btcLogo = plugin_dir_url( __FILE__ ).'assets/bitcoin.svg';
		$modeCashbackEnabledLogo = plugin_dir_url( __FILE__ ).'assets/cb-enabled.svg';
		$modeCashbackDisabledLogo = plugin_dir_url( __FILE__ ).'assets/cb-disabled.svg';

		$cashbackAmount = $woocommerce->cart->total * 0.25;

		if ($cashbackRate !== 'absent' && $cashbackRate !== '0') {
			echo '<div>';
				echo '<center>';
					echo '<img alt="Mode Cashback Logo" src="'.$modeCashbackEnabledLogo.'">';

					echo '<div style="margin-top: 20px;">';
						echo '<h4 style="display: inline;">Checkout to earn £'.$cashbackAmount.' in <img style="margin: 0 0; vertical-align: initial; display: inline;" alt="Bitcoin Logo" src="'.$btcLogo.'"> Bitcoin</h4>';
					echo '</div>';

					echo '<p style="font-size: 16px; margin-top: 12px;">After clicking "Place Order" you will use the Mode App to complete your purchase and earn BTC cashback</p>';
				echo '</center>';
			echo '</div>';
			} else {
			echo '<div>';
				echo '<center>';
					echo '<img alt="Mode Checkout Logo" src="'.$modeCashbackDisabledLogo.'">';
					echo '<h4 style="margin-top: 20px;">Frictionless payment at the next step</h4>';
					echo '<p style="font-size: 16px; margin-top: 12px;">After clicking "Place Order" you will use the Mode App to complete your purchase.</p>';
				echo '</center>';
			echo '</div>';
		}

		$description .= ob_get_clean();
		return $description;
	}
}
?>