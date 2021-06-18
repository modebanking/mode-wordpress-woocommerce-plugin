<?php

add_filter( 'woocommerce_gateway_description', 'mode_gateway_description_fields', 20, 2 );
remove_filter('woocommerce_gateway_description', 'wpautop');

function mode_gateway_description_fields( $description, $payment_id ) {
	if ($payment_id === 'mode_gateway') {
		global $woocommerce;
		ob_start();

		$modeLogo = plugin_dir_url( __FILE__ ).'assets/logo.svg';
		$btcLogo = plugin_dir_url( __FILE__ ).'assets/bitcoin.svg';
		$modeCashbackEnabledLogo = plugin_dir_url( __FILE__ ).'assets/cb-enabled.svg';
		$modeCashbackDisabledLogo = plugin_dir_url( __FILE__ ).'assets/cb-disabled.svg';

		// $options = array(
		// 	'http' => array(
		// 		'ignore_errors' => true,
		// 		'header'  => array(
		// 			'Content-Type: application/json',
		// 			'Authorization: Bearer '.get_option('mode_auth_token')
		// 		),
		// 		'method'  => 'GET'
		// 	)
		// );

		// $context = stream_context_create($options);
		// $result = json_decode(file_get_contents('https://hpxjxq5no8.execute-api.eu-west-2.amazonaws.com/production/merchants/cashback', false, $context));
		// $cashbackValue = strval($result->cashbackRatePercentage);

		$percentage = ($woocommerce->cart->total / 100) * 25; // Change 25 to $cashbackValue

		// if ($cashbackValue !== 'absent' && $cashbackValue !== '0') {
			echo '<div>';
				echo '<center>';
					echo '<img alt="Mode Cashback Logo" src="'.$modeCashbackEnabledLogo.'">';

					echo '<div style="margin-top: 20px;">';
						echo '<h4 style="display: inline;">Checkout to earn £'.number_format((float) $percentage, 2, ".", "").' in <img style="margin: 0 0; vertical-align: initial; display: inline;" alt="Bitcoin Logo" src="'.$btcLogo.'"> Bitcoin</h4>';
					echo '</div>';

					echo '<p style="font-size: 16px; margin-top: 12px;">After clicking "Place Order" you will use the Mode App to complete your purchase and earn BTC cashback</p>';
				echo '</center>';
			echo '</div>';
		// 	} else {
			// echo '<div>';
			// 	echo '<center>';
			// 		echo '<img alt="Mode Checkout Logo" src="'.$modeCashbackDisabledLogo.'">';
			// 		echo '<h4 style="margin-top: 20px;">Frictionless payment at the next step</h4>';
			// 		echo '<p style="font-size: 16px; margin-top: 12px;">After clicking "Place Order" you will use the Mode App to complete your purchase.</p>';
			// 	echo '</center>';
			// echo '</div>';
		// }

		// Uncomment the commented out code
		$description .= ob_get_clean();
		return $description;
	}
}
?>