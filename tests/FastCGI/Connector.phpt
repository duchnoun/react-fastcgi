<?php

/**
 * TEST: Connector test.
 */

use Adawolfa\FastCGI;
use Tester\Assert;

require(__DIR__ . '/../bootstrap.php');

$loop = React\EventLoop\Factory::create();

$server = new FastCGI\Server(function(Psr\Http\Message\ServerRequestInterface $serverRequest) use($loop): React\Promise\PromiseInterface {

	$deferred = new React\Promise\Deferred;

	$loop->addTimer(.1, function() use($deferred, $loop): void {
		$stream = new React\Stream\ThroughStream;
		$response = new React\Http\Response(200, ['Content-Type' => 'text/plain'], $stream);
		$deferred->resolve($response);
		$stream->end(file_get_contents(__FILE__));
	});

	return $deferred->promise();

});

$socket = new React\Socket\Server('127.0.0.1:9009', $loop);
$server->listen($socket);

$connector = new FastCGI\Connector($loop);
$connector->connect('127.0.0.1:9009')->then(function(FastCGI\Client $client) use($loop, &$response, &$body): void {
	$loop->addTimer(.5, function() use($client, $loop, &$response, &$body): void {

		$serverRequest = new React\Http\Io\ServerRequest('GET', '/');

		$client->send($serverRequest)->then(function(React\Http\Response $v) use($loop, &$response, &$body): void {

			$response = $v;
			$body = '';

			$stream = $response->getBody();
			assert($stream instanceof React\Http\Io\HttpBodyStream);

			$stream->on('data', function(string $data) use(&$body) {
				$body .= $data;
			});

			$stream->on('end', function() use($loop) {
				$loop->stop();
			});

		});

	});
});

$loop->run();

/** @var $response Psr\Http\Message\ResponseInterface */
Assert::same('text/plain', $response->getHeaderLine('Content-Type'));
Assert::same(file_get_contents(__FILE__), $body);