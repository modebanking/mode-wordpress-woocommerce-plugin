<?php

add_filter( 'woocommerce_gateway_description', 'mode_gateway_description_fields', 20, 2 );

function mode_gateway_description_fields( $description, $payment_id ) {
	ob_start();

	echo '<center>'; ?>

	<img src="<? echo plugin_dir_url( __FILE__ ).'images/logo.png' ?>">
<?  echo '<h5>You are almost there!</h5>';
		echo '<p>After clicking "Place Order" you will use the Mode App to complete your purchase ⚡.</p>';
	echo '</center>';

	$description .= ob_get_clean();
	return $description;
}