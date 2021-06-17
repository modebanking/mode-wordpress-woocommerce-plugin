<?php

add_filter( 'woocommerce_gateway_description', 'mode_gateway_description_fields', 20, 2 );

function mode_gateway_description_fields( $description, $payment_id ) {
	if ($payment_id === 'mode_gateway') {
		global $order;
		ob_start();
		$cb = true;
		$android = get_option('mode_android_flag');

		$modeLogo = plugin_dir_url( __FILE__ ).'assets/logo.svg';
		$androidLogo = plugin_dir_url( __FILE__ ).'assets/logo.svg'; // Change to Android
		$modeCashbackEnabledLogo = plugin_dir_url( __FILE__ ).'assets/logo.svg'; // Change to cb-enabled.svg
		$modeCashbackDisabledLogo = plugin_dir_url( __FILE__ ).'assets/logo.svg'; // Change to cb-disabled.svg

		print_r($order);

		if ($cb) {
			echo '<div>';
				echo '<center>';
					echo '<img alt="Mode Cashback Logo" src="'.$modeCashbackEnabledLogo.'">';
					echo '<h4>Checkout to earn '.$cbAmount.' in Bitcoin</h4>';
					echo '<p>After clicking "Place Order" you will use the Mode App to complete your purchase and earn BTC cashback</p>';
		} else {
			echo '<div>';
				echo '<center>';
					echo '<img alt="Mode Checkout Logo" src="'.$modeCashbackDisabledLogo.'">';
					echo '<h4>Frictionless payment at the next step</h4>';
					echo '<p>After clicking "Place Order" you will use the Mode App to complete your purchase.</p>';
		}

				echo '<img alt="Android Logo" src="'.$androidLogo.'"><h6>Android only. iOS coming soon.</h6>';
			echo '</center>';
		echo '</div>';

		$description .= ob_get_clean();
		return $description;
	}
}
?>