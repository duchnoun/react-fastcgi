<?php

declare(strict_types=1);
namespace Adawolfa\FastCGI;
use Evenement\EventEmitter;
use React;

/**
 * Asynchronous FastCGI server.
 *
 * @author Adam Klvač <adam@klva.cz>
 */
class Server extends EventEmitter
{

	/** @var callable */
	private $handler;

	/**
	 * Server constructor.
	 * @param callable $handler
	 */
	public function __construct(callable $handler)
	{
		$this->handler = $handler;
	}

	/**
	 * Listens on socket.
	 * @param React\Socket\Server $socket
	 * @return void
	 */
	public function listen(React\Socket\Server $socket): void
	{
		$socket->on('connection', function(React\Socket\ConnectionInterface $socket): void {
			$connection = new Server\Connection($socket, $this->handler);
			$this->emit('connection', [$connection]);
		});
	}

}