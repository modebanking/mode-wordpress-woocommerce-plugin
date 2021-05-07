all :
	composer.phar install --no-ansi --no-dev --no-interaction --no-plugins --no-progress --no-scripts --no-suggest --optimize-autoloader &&\
	git archive HEAD -o ./woocommerce-Mode.zip &&\
	zip -rq ./woocommerce-Mode.zip ./vendor &&\
	echo "\nCreated woocommerce-Mode.zip\n"
