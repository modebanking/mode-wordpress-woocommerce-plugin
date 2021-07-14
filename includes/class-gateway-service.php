<?php
/**
 * Copyright (c) 2019-2020 Mode
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

use Http\Client\Common\Exception\ClientErrorException;
use Http\Client\Common\Exception\ServerErrorException;
use Http\Client\Common\HttpClientRouter;
use Http\Client\Common\Plugin;
use Http\Client\Common\Plugin\AuthenticationPlugin;
use Http\Client\Common\Plugin\ContentLengthPlugin;
use Http\Client\Common\Plugin\HeaderSetPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\Exception;
use Http\Discovery\HttpClientDiscovery;
use Http\Message\Authentication\BasicAuth;
use Http\Message\Formatter;
use Http\Message\Formatter\SimpleFormatter;
use Http\Message\MessageFactory\GuzzleMessageFactory;
use Http\Message\RequestMatcher\RequestMatcher;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class Mode_ApiErrorPlugin implements Plugin {
	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var Formatter
	 */
	private $formatter;

	/**
	 * @inheritdoc
	 */
	public function __construct( LoggerInterface $logger, Formatter $formatter = null ) {
		$this->logger    = $logger;
		$this->formatter = $formatter ?: new SimpleFormatter();
	}

	/**
	 * @inheritdoc
	 */
	public function handleRequest( \Psr\Http\Message\RequestInterface $request, callable $next, callable $first ) {
		$promise = $next( $request );

		return $promise->then( function ( ResponseInterface $response ) use ( $request ) {
			return $this->transformResponseToException( $request, $response );
		} );
	}

	/**
	 * @param RequestInterface $request
	 * @param ResponseInterface $response
	 *
	 * @return ResponseInterface
	 */
	protected function transformResponseToException( RequestInterface $request, ResponseInterface $response ) {
		if ( $response->getStatusCode() >= 400 && $response->getStatusCode() < 500 ) {
			$responseData = @json_decode( $response->getBody(), true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				throw new ServerErrorException( "Response not valid JSON", $request, $response );
			}

			$msg = '';
			if ( isset( $responseData['error']['cause'] ) ) {
				$msg .= $responseData['error']['cause'] . ': ';
			}
			if ( isset( $responseData['error']['explanation'] ) ) {
				$msg .= $responseData['error']['explanation'];
			}

			$this->logger->error( $msg );
			throw new ClientErrorException( $msg, $request, $response );
		}

		if ( $response->getStatusCode() >= 500 && $response->getStatusCode() < 600 ) {
			$this->logger->error( $response->getReasonPhrase() );
			throw new ServerErrorException( $response->getReasonPhrase(), $request, $response );
		}

		return $response;
	}
}

class Mode_ApiLoggerPlugin implements Plugin {
	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var Formatter
	 */
	private $formatter;

	/**
	 * @inheritdoc
	 */
	public function __construct( LoggerInterface $logger, Formatter $formatter = null ) {
		$this->logger    = $logger;
		$this->formatter = $formatter ?: new SimpleFormatter();
	}

	/**
	 * @inheritdoc
	 */
	public function handleRequest( \Psr\Http\Message\RequestInterface $request, callable $next, callable $first ) {
		$reqBody = @json_decode( $request->getBody(), true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$reqBody = $request->getBody();
		}

		$this->logger->info( sprintf( 'Emit request: "%s"', $this->formatter->formatRequest( $request ) ),
			[ 'request' => $reqBody ] );

		return $next( $request )->then( function ( ResponseInterface $response ) use ( $request ) {
			$body = @json_decode( $response->getBody(), true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$body = $response->getBody();
			}
			$this->logger->info(
				sprintf( 'Receive response: "%s" for request: "%s"', $this->formatter->formatResponse( $response ),
					$this->formatter->formatRequest( $request ) ),
				[
					'response' => $body,
				]
			);

			return $response;
		}, function ( \Exception $exception ) use ( $request ) {
			if ( $exception instanceof Exception\HttpException ) {
				$this->logger->error(
					sprintf( 'Error: "%s" with response: "%s" when emitting request: "%s"', $exception->getMessage(),
						$this->formatter->formatResponse( $exception->getResponse() ),
						$this->formatter->formatRequest( $request ) ),
					[
						'request'   => $request,
						'response'  => $exception->getResponse(),
						'exception' => $exception,
					]
				);
			} else {
				$this->logger->error(
					sprintf( 'Error: "%s" when emitting request: "%s"', $exception->getMessage(),
						$this->formatter->formatRequest( $request ) ),
					[
						'request'   => $request,
						'exception' => $exception,
					]
				);
			}

			throw $exception;
		} );
	}
}


class Mode_GatewayResponseException extends \Exception {

}

class Mode_GatewayService {
	/**
	 * @var string
	 */
	protected $authUrl;

	/**
	 * @var string
	 */
	protected $callbackUrl;

	/**
	 * @var string
	 */
	protected $merchantId;

	/**
	 * @var string
	 */
	protected $webhookUrl;

	/**
	 * @var string
	 */
	protected $clientId;

	/**
	 * @var string
	 */
	protected $secretId;


	/**
	 * GatewayService constructor.
	 *
	 * @param string $authUrl
	 * @param string $callbackUrl
	 * @param string $merchantId
	 * @param string $clientId
	 * @param string $secretId
	 * @param string $webhookUrl
	 * @param int $loggingLevel
	 *
	 * @throws \Exception
	 */
	public function __construct(
		$authUrl,
		$callbackUrl,
		$merchantId,
		$clientId,
		$secretId,
		$webhookUrl,
		$loggingLevel = \Monolog\Logger::DEBUG
	) {
		$this->authUrl = $authUrl;
		$this->callbackUrl = $callbackUrl;
		$this->merchantId = $merchantId;
		$this->clientId = $clientId;
		$this->secretId = $secretId;
		$this->webhookUrl = $webhookUrl;

		$logger = new Logger( 'mode' );
		$logger->pushHandler( new StreamHandler(
			WP_CONTENT_DIR . '/mode.log',
			$loggingLevel
		) );
	}

	/**
	 * Request to retrieve the options available for processing a payment, for example, the credit cards and currencies.
	 * https://mtf.gateway.Mode.com/api/rest/version/51/merchant/{merchantId}/paymentOptionsInquiry
	 */
	public function paymentOptionsInquiry() {
		update_option('mode_client_id', get_option('woocommerce_mode_gateway_settings')['clientid']);
		update_option('mode_secret_id', get_option('woocommerce_mode_gateway_settings')['secretid']);

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
		$result = json_decode(file_get_contents($this->authUrl, false, $context), true);

		if ($result === FALSE) {
			return _e('Couldn\'t validate credentials. Try again.');
		}

		update_option('mode_auth_token', $result['access_token']);
		update_option('mode_merchant_id', get_option('woocommerce_mode_gateway_settings')['merchantid']);

		$data = array('url' => get_site_url().'/wp-json/mode/v1/set-callback');

		$options = array(
			'http' => array(
				'ignore_errors' => true,
				'header'  => array(
					'Content-Type: application/json',
					'Authorization: Bearer '.get_option("mode_auth_token")
				),
				'method'  => 'POST',
				'content' => json_encode($data)
			)
		);

		$context = stream_context_create($options);
		$result = file_get_contents($this->callbackUrl, false, $context);

		return $result;
	}
}
