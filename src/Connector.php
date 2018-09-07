<?php

declare(strict_types=1);
namespace Adawolfa\FastCGI;
use Psr\Http\Message\ServerRequestInterface;
use React;

/**
 * Asynchronous FastCGI client.
 *
 * @author Adam Klvač <adam@klva.cz>
 */
class Connector
{

	/** @var React\EventLoop\LoopInterface */
	private $loop;

	/**
	 * Client constructor.
	 * @param React\EventLoop\LoopInterface $loop
	 */
	public function __construct(React\EventLoop\LoopInterface $loop)
	{
		$this->loop = $loop;
	}

	/**
	 * Connects to a FastCGI server.
	 * @param string $uri
	 * @return React\Promise\PromiseInterface
	 */
	public function connect(string $uri): React\Promise\PromiseInterface
	{
		$connector = new React\Socket\Connector($this->loop);
		$deferred = new React\Promise\Deferred;

		$connector->connect($uri)->then(
			function(React\Socket\ConnectionInterface $connection) use($deferred): void {
				$deferred->resolve(new Client($connection));
			},
			[$deferred, 'reject']
		);

		return $deferred->promise();
	}

}