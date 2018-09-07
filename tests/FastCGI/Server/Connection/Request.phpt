<?php

/**
 * TEST: Server request test.
 */

use Adawolfa\FastCGI;
use Tester\Assert;
use Lisachenko\Protocol\FCGI;
use Psr\Http\Message\ResponseInterface;

require(__DIR__ . '/../../../bootstrap.php');

$connection = new class extends Evenement\EventEmitter implements React\Socket\ConnectionInterface
{

	public function getRemoteAddress()
	{
		return '127.0.0.1';
	}

	public function getLocalAddress()
	{
		return '127.0.0.1';
	}

	public function isReadable()
	{
		return true;
	}

	public function pause()
	{
	}

	public function resume()
	{
	}

	public function pipe(React\Stream\WritableStreamInterface $dest, array $options = [])
	{
	}

	public function close()
	{
	}

	public function isWritable()
	{
		return true;
	}

	public function write($data)
	{
		$this->emit('write', [$data]);
		return true;
	}

	public function end($data = null)
	{
	}

};

$request = new FastCGI\Server\Connection\Request(5, $connection, function(React\Http\Io\ServerRequest $v) use(&$serverRequest): React\Http\Response {
	$serverRequest = $v;
	return new React\Http\Response(200, ['Content-Type' => 'text/plain']);
});

$request->on('error', function(Throwable $e) use(&$exception): void {
	$exception = $e;
});

$request->writeBuffer('');
Assert::same('Parameters not set.', $exception->getMessage());

$request->writeParameters([
	'REQUEST_METHOD' => 'GET',
	'REQUEST_URI' => '/',
	'SERVER_PROTOCOL' => '1.1',
	'HTTP_CONTENT_TYPE' => 'text/plain',
]);

$request->writeParameters([]);

$request->writeParameters([]);
Assert::same('Parameters have already been set.', $exception->getMessage());

$request->writeBuffer('Hello World!');
$request->writeBuffer('');

/** @var $serverRequest RingCentral\Psr7\ServerRequest */
Assert::same('text/plain', $serverRequest->getHeaderLine('Content-Type'));

$request = new FastCGI\Server\Connection\Request(5, $connection, function(React\Http\Io\ServerRequest $v) use(&$serverRequest): string {
	return 'Hello!';
});

$request->on('error', function(Throwable $e) use(&$exception): void {
	$exception = $e;
});

$request->writeParameters([]);

Assert::same('Server request handler should return ' . ResponseInterface::class . ' or promise.', $exception->getMessage());

$request = new FastCGI\Server\Connection\Request(5, $connection, function(React\Http\Io\ServerRequest $v) use(&$serverRequest): ?ResponseInterface {
	return null;
});

$request->on('end', function() use(&$aborted) {
	$aborted = true;
});

$request->writeParameters([]);

Assert::true($aborted);

$request = new FastCGI\Server\Connection\Request(5, $connection, function(React\Http\Io\ServerRequest $v) use(&$serverRequest): ?React\Promise\PromiseInterface {
	return new React\Promise\RejectedPromise;
});

$aborted = false;
$request->on('end', function() use(&$aborted) {
	$aborted = true;
});

$request->writeParameters([]);

Assert::true($aborted);

$request = new FastCGI\Server\Connection\Request(5, $connection, function(React\Http\Io\ServerRequest $v) use(&$serverRequest): ?React\Http\Response {
	$stream = RingCentral\Psr7\stream_for(function() {
		yield '';
	});
	return new React\Http\Response('GET', [], $stream);
});

$request->on('error', function(Throwable $e) use(&$exception): void {
	$exception = $e;
});

$request->writeParameters([]);

Assert::same('Response body should be instance of ' . React\Stream\ReadableStreamInterface::class . ' or should have content present.', $exception->getMessage());