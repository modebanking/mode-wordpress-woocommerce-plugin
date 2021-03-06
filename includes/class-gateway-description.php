<?php

add_filter( 'woocommerce_gateway_description', 'mode_gateway_description_fields', 20, 2 );
remove_filter('woocommerce_gateway_description', 'wpautop');
define('FONT_CSS', 'assets/css/');

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
		$result = json_decode(file_get_contents('https://auth.modeapp.com/oauth/token', false, $context), true);
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
		$result = json_decode(file_get_contents('https://api.modeforbusiness.com/merchants', false, $context));

		?>

		<style>
		@font-face {
			font-family: gilroySemibold;
			src: url("<?php echo plugin_dir_url( __FILE__ ).'assets/fonts/gilroy-semibold.otf' ?>") format("opentype");
		}
		@font-face {
			font-family: gilroyRegular;
			src: url("<?php echo plugin_dir_url( __FILE__ ).'assets/fonts/gilroy-regular.otf' ?>") format("opentype");
		}
		body {
			font-family: gilroyRegular!important;
		}
		</style>

		<?php if ($result->cashbackRatePercentage && $result->cashbackRatePercentage !== '0') {
			$cashbackValue = (int)$result->cashbackRatePercentage;
			$percentage = ($woocommerce->cart->total / 100) * $cashbackValue;

			echo '<div>';
				echo '<center>';
					echo '<img alt="Mode Cashback Logo" src="'.$modeCashbackEnabledLogo.'">';

					echo '<div style="margin-top: 20px;">';
						echo '<h4 style="font-size: 20px; display: inline;">Checkout to earn ??'.number_format((float) $percentage, 2, ".", "").' in <img style="margin: 0 0; vertical-align: initial; display: inline;" alt="Bitcoin Logo" src="'.$btcLogo.'"> Bitcoin</h4>';
					echo '</div>';

					echo '<p style="font-size: 16px; margin-top: 12px;">Get up to 10&#x25; Bitcoin Cashback. Pay instantly &amp; securely with your UK bank account. Follow the steps on the next screen to pay.</p>';
				echo '</center>';
			echo '</div>';
			} else {
			echo '<div>';
				echo '<center>';
					echo '<img style="float: none !important; max-height: inherit !important; padding: 10px !important;" alt="Mode Checkout Logo" src="'.$modeCashbackDisabledLogo.'">';
					echo '<h4 style="font-size: 20px; margin-top: 20px; font-family:gilroySemibold !important;">&#x26a1;&nbsp;Pay and get Bitcoin Cashback</h4>';
					echo '<p style="font-size: 16px; margin-top: 12px; font-family:gilroyRegular !important;">Get up to 10&#x25; Bitcoin Cashback. Pay instantly &amp; securely with your UK bank account. Follow the steps on the next screen to pay.</p>';
				echo '</center>';
			echo '</div>';
		}

		$description .= ob_get_clean();
		return $description;
	}
}

add_filter( 'woocommerce_gateway_icon', 'mode_gateway_icon_fields', 20, 2 );

function mode_gateway_icon_fields( $icon, $payment_id ) {
	$available_gateways = sizeof(WC()->payment_gateways->get_available_payment_gateways());

	if ($payment_id === 'mode_gateway') {
		if ($available_gateways > 1) {
			echo '<p>'.$icon.'</p>';
			$logoModeAccordion = plugin_dir_url( __FILE__ ).'assets/mode-accordion.svg';
			echo '<a onclick="window.open(`https://www.modeapp.com/pay-with-mode`, `_blank`).focus()">What is Pay with Mode?</a>';
			echo '<img style="max-width: 20%;" alt="Mode Cipashback Logo" src="'.$logoModeAccordion.'">';
		}
	} else {
		echo $icon;
	}
}
?>
