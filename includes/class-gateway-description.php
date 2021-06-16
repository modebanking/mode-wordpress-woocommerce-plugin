<?php

add_filter( 'woocommerce_gateway_description', 'mode_gateway_description_fields', 20, 2 );

function mode_gateway_description_fields( $description, $payment_id ) {
	if ($payment_id === 'mode_gateway') {
		ob_start();

		$filepath = plugin_dir_url( __FILE__ ).'assets/logo.svg';

		echo '<div>';
			echo '<center>';
				echo '<img alt="Mode Logo" src="'.$filepath.'">';
				echo '<h4>You\'re nearly there!</h4>';
				echo '<p>After clicking "Place Order" you will use the Mode App to complete your purchase ⚡.</p>';
			echo '</center>';
		echo '</div>';

		$description .= ob_get_clean();
		return $description;
	}
}
?>