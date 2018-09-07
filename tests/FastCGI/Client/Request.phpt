<?php

/**
 * TEST: Client request test.
 */

use Adawolfa\FastCGI;
use Tester\Assert;

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

$request = new FastCGI\Client\Request(10, $connection);

$request->promise()->then(function(React\Http\Response $v) use(&$response): void {
	$response = $v;
});

$finished = false;
$request->on('end', function() use(&$finished): void {
	$finished = true;
});

$request->writeBuffer("HTTP/1.1 200 OK\r\n\r\n");

/** @var $response React\Http\Response */
$body = $response->getBody();

assert($body instanceof React\Http\Io\HttpBodyStream);
$body->on('data', function($data) use(&$contents): void {
	$contents = $data;
});

$request->writeBuffer('Hello World!');

Assert::same(200, $response->getStatusCode());
Assert::same('Hello World!', $contents);

Assert::false($finished);
$request->writeEnd(Lisachenko\Protocol\FCGI::REQUEST_COMPLETE);
Assert::true($finished);

$request = new FastCGI\Client\Request(10, $connection);

$connection->on('write', function(string $data) use(&$end): void {
	$end = Lisachenko\Protocol\FCGI\FrameParser::parseFrame($data);
});

$request->abort();
Assert::true($end instanceof Lisachenko\Protocol\FCGI\Record\EndRequest);