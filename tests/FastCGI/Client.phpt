<?php

/**
 * TEST: Client test.
 */

use AmK\FastCGI;
use Tester\Assert;
use Lisachenko\Protocol\FCGI;
use Psr\Http\Message\ResponseInterface;

require(__DIR__ . '/../bootstrap.php');

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

$writeBuffer = '';

$connection->on('write', function($data) use(&$writeBuffer): void {
	$writeBuffer .= $data;
});

$client = new FastCGI\Client($connection);

$response = null;

$client->on('begin', function(FastCGI\Client\Request $v) use(&$request) {
	$request = $v;
});

$client->send(new React\Http\Io\ServerRequest('GET', '/', ['Content-Type' => 'text/plain']))
	->then(function(React\Http\Response $v) use(&$response, &$body): void {
		$response = $v;
		$stream = $v->getBody();
		assert($stream instanceof React\Stream\ReadableStreamInterface);
		$stream->on('data', function(string $data) use(&$body): void {
			$body = $data;
		});
	});

$written = [];

while (FCGI\FrameParser::hasFrame($writeBuffer)) {
	$written[] = FCGI\FrameParser::parseFrame($writeBuffer);
}

Assert::count(4, $written);

Assert::true($written[0] instanceof FCGI\Record\BeginRequest);
Assert::same(1, $written[0]->getRequestId());

Assert::true($written[1] instanceof FCGI\Record\Params);
Assert::same([
	'QUERY_STRING' => '',
	'REQUEST_URI' => '/',
	'REQUEST_METHOD' => 'GET',
	'SERVER_PROTOCOL' => '1.1',
	'HTTP_CONTENT_TYPE' => 'text/plain',
], $written[1]->getValues());

Assert::null($response);

$stdout = new FCGI\Record\Stdout("HTTP/1.1 200 OK\r\nContent-Length: 12\r\n\r\nHello World!");
$stdout->setRequestId(1);

$connection->emit('data', [(string) $stdout]);

$stdout = new FCGI\Record\Stdout;
$stdout->setRequestId(1);

$connection->emit('data', [(string) $stdout]);

$begin = new FCGI\Record\Stdin;
$begin->setRequestId(1);

$request->on('error', function(Throwable $e) use(&$exception) {
	$exception = $e;
});

$connection->emit('data', [(string) $begin]);
Assert::same('Unknown frame.', $exception->getMessage());

$end = new FCGI\Record\EndRequest;
$end->setRequestId(1);

$connection->emit('data', [(string) $end]);

/** @var $response ResponseInterface */
Assert::true($response instanceof ResponseInterface);
Assert::same('12', $response->getHeaderLine('Content-Length'));
Assert::same('Hello World!', $body);

$client->on('error', function(Throwable $e) use(&$exception) {
	$exception = $e;
});

$exception = null;
$connection->emit('data', [(string) new FCGI\Record\BeginRequest]);
Assert::same('Unknown frame.', $exception->getMessage());

$client->on('close', function() use(&$closed): void {
	$closed = true;
});

$connection->emit('close');

Assert::true($closed);