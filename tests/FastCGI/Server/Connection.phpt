<?php

/**
 * TEST: Server connection test.
 */

use AmK\FastCGI;
use Tester\Assert;
use Lisachenko\Protocol\FCGI;
use Psr\Http\Message\ResponseInterface;

require(__DIR__ . '/../../bootstrap.php');

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
		$this->emit('close');
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

$request = new FastCGI\Server\Connection($connection, function(React\Http\Io\ServerRequest $v) use(&$serverRequest): React\Http\Response {
	$serverRequest = $v;
	return new React\Http\Response(200, ['Content-Type' => 'text/plain']);
});

$begin = new FCGI\Record\BeginRequest(FCGI::RESPONDER, FCGI::KEEP_CONN);
$begin->setRequestId(5);

$connection->emit('data', [(string) $begin]);

$params = new FCGI\Record\Params([
	'REQUEST_METHOD' => 'GET',
	'REQUEST_URI' => '/',
	'SERVER_PROTOCOL' => '1.1',
	'HTTP_CONTENT_TYPE' => 'text/plain',
]);
$params->setRequestId(5);

$connection->emit('data', [(string) $params]);

$params = new FCGI\Record\Params;
$params->setRequestId(5);

$connection->emit('data', [(string) $params]);

$stdin = new FCGI\Record\Stdin;
$stdin->setRequestId(5);

$connection->emit('data', [(string) $stdin]);

/** @var $serverRequest RingCentral\Psr7\ServerRequest */
Assert::same('text/plain', $serverRequest->getHeaderLine('Content-Type'));

$errors = [];

$request->on('error', function(Throwable $e) use(&$errors): void {
	$errors[] = $e;
});

$connection->emit('data', [(string) new FCGI\Record\Stdout]);
$connection->emit('data', [(string) new FCGI\Record\Stdin]);
$connection->emit('data', [(string) new FCGI\Record\Params]);
$connection->emit('data', [(string) new FCGI\Record\AbortRequest]);
$connection->emit('data', [(string) new FCGI\Record\BeginRequest]);
$connection->emit('data', [(string) new FCGI\Record\BeginRequest]);

Assert::count(5, $errors);
Assert::same('Unknown frame.', $errors[0]->getMessage());
Assert::same('Request ID does not exist.', $errors[1]->getMessage());
Assert::same('Request ID does not exist.', $errors[2]->getMessage());
Assert::same('Request ID does not exist.', $errors[3]->getMessage());
Assert::same('Reused request ID.', $errors[4]->getMessage());

$request->on('close', function() use(&$closed): void {
	$closed = true;
});

$request->close();

Assert::true($closed);

$request = new FastCGI\Server\Connection($connection, function(){});

$request->on('error', function(Throwable $e) use(&$exception): void {
	$exception = $e;
});

for ($i = 0; $i < 5; $i++) {
	$connection->emit('data', [str_repeat('long cat is long', 1000)]);
}

Assert::same('Invalid FCGI record type 111 received', $exception->getMessage());

$request = new FastCGI\Server\Connection($connection, function(FastCGI\Request $request) use(&$aborted): React\Promise\PromiseInterface {
	$request->on('abort', function() use(&$aborted): void {
		$aborted = true;
	});
	return (new React\Promise\Deferred)->promise();
});

$connection->emit('data', [(string) new FCGI\Record\BeginRequest]);
$connection->emit('data', [(string) new FCGI\Record\Params]);
$connection->emit('data', [(string) new FCGI\Record\Stdin]);
$connection->emit('data', [(string) new FCGI\Record\AbortRequest]);

Assert::true($aborted);