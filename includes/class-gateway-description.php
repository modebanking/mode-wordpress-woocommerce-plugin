<?php

add_filter( 'woocommerce_gateway_description', 'mode_gateway_description_fields', 20, 2 );

function mode_gateway_description_fields( $description, $payment_id ) {
	if ($payment_id === 'mode_gateway') {
		ob_start();

		echo '<div>';
			echo '<center>'; ?>
			<img src="<? echo plugin_dir_url( __FILE__ ).'assets/logo.svg' ?>">
			<? echo '<h4>You\'re nearly there!</h4>';
				echo '<p>After clicking "Place Order" you will use the Mode App to complete your purchase ⚡.</p>';
			echo '</center>';
		echo '</div>';

		$description .= ob_get_clean();
		return $description;
	}
}

add_filter( 'woocommerce_gateway_icon', 'mode_gateway_description_logo', 19, 2);

function mode_gateway_description_logo($icon, $payment_id) {
	if ($payment_id === 'mode_gateway') {
	?>
	<img alt="Mode App logo" src="<? echo plugin_dir_url( __FILE__ ).'assets/logo.png' ?>">
	<?
	} else {
		return $icon;
	}
}