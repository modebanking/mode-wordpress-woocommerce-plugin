<?php

add_filter( 'woocommerce_gateway_description', 'mode_gateway_description_fields', 20, 2 );

function mode_gateway_description_fields( $description, $payment_id ) {
	if ($payment_id === 'mode_gateway') {
		ob_start();
		echo '<center>'; ?>
		<img src="<? echo plugin_dir_url( __FILE__ ).'assets/logo.svg' ?>">
		<? echo '<h5>You\'re almost there!</h5>';
			echo '<p>After clicking "Place Order" you will use the Mode App to complete your purchase ⚡.</p>';
		echo '</center>';

		$description .= ob_get_clean();
		return $description;
	}
}