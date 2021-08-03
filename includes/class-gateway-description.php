<?php

add_filter( 'woocommerce_gateway_description', 'mode_gateway_description_fields', 20, 2 );
remove_filter('woocommerce_gateway_description', 'wpautop');

function mode_gateway_description_fields( $description, $payment_id ) {
	if ($payment_id === 'mode_gateway') {
		global $woocommerce;
		ob_start();

		$data = array('grant_type' => 'client_credentials', 'client_id' => get_option('mode_client_id'), 'client_secret' => get_option('mode_secret_id'), 'audience' => 'https://merchants.modeapp.com');

		$options = array(
			'http' => array(
				'ignore_errors' => true,
				'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
				'method'  => 'POST',
				'content' => http_build_query($data)
			)
		);

		$context = stream_context_create($options);
		$result = json_decode(file_get_contents('https://dev-mode.eu.auth0.com/oauth/token', false, $context), true);
		update_option('mode_auth_token', $result['access_token']);

		$modeLogo = plugin_dir_url( __FILE__ ).'assets/logo.svg';
		$btcLogo = plugin_dir_url( __FILE__ ).'assets/bitcoin.svg';
		$modeCashbackEnabledLogo = plugin_dir_url( __FILE__ ).'assets/cb-enabled.svg';
		$modeCashbackDisabledLogo = plugin_dir_url( __FILE__ ).'assets/cb-disabled.svg';

		$options = array(
			'http' => array(
				'ignore_errors' => true,
				'header'  => array(
					'Content-Type: application/json',
					'Authorization: Bearer '.get_option('mode_auth_token')
				),
				'method'  => 'GET'
			)
		);

		$context = stream_context_create($options);
		$result = json_decode(file_get_contents('https://qa1-api.modeforbusiness.com/merchants', false, $context));

		if ($result->cashbackRatePercentage && $result->cashbackRatePercentage !== '0') {
			$cashbackValue = (int)$result->cashbackRatePercentage;
			$percentage = ($woocommerce->cart->total / 100) * $cashbackValue;

			echo '<div>';
				echo '<center>';
					echo '<img alt="Mode Cashback Logo" src="'.$modeCashbackEnabledLogo.'">';

					echo '<div style="margin-top: 20px;">';
						echo '<h4 style="font-size: 20px; display: inline;">Checkout to earn £'.number_format((float) $percentage, 2, ".", "").' in <img style="margin: 0 0; vertical-align: initial; display: inline;" alt="Bitcoin Logo" src="'.$btcLogo.'"> Bitcoin</h4>';
					echo '</div>';

					echo '<p style="font-size: 16px; margin-top: 12px;">After clicking "Place Order" you will use the Mode App to complete your purchase and earn BTC cashback</p>';
				echo '</center>';
			echo '</div>';
			} else {
			echo '<div>';
				echo '<center>';
					echo '<img alt="Mode Checkout Logo" src="'.$modeCashbackDisabledLogo.'">';
					echo '<h4 style="font-size: 20px; margin-top: 20px;">Frictionless payment at the next step</h4>';
					echo '<p style="font-size: 16px; margin-top: 12px;">After clicking "Place Order" you will use the Mode App to complete your purchase.</p>';
				echo '</center>';
			echo '</div>';
		}

		$description .= ob_get_clean();
		return $description;
	}
}

add_filter( 'woocommerce_gateway_icon', 'mode_gateway_icon_fields', 20, 2 );

function mode_gateway_icon_fields( $icon, $payment_id ) {
	if ($payment_id === 'mode_gateway') {
		$logoModeAccordion = plugin_dir_url( __FILE__ ).'assets/mode-accordion.svg';
		echo '<a onclick="window.open(`https://modeapp.com/payments-and-rewards`, `_blank`).focus()">What is Pay with Mode?</a>';
		echo '<img style="max-width: 20%;" alt="Mode Cashback Logo" src="'.$logoModeAccordion.'">';
	} else {
		echo $icon;
	}
}
?>