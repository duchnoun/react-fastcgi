<?php

declare(strict_types=1);
namespace Adawolfa\FastCGI\Server;
use Evenement\EventEmitter;
use Lisachenko\Protocol\FCGI;
use React;
use Exception;

/**
 * Server connection.
 *
 * @author Adam Klvač <adam@klva.cz>
 */
class Connection extends EventEmitter
{

	/** @var React\Socket\ConnectionInterface */
	private $connection;

	/** @var callable */
	private $handler;

	/** @var string */
	private $buffer = '';

	/** @var Connection\Request[] */
	private $requests = [];

	/**
	 * Connection constructor.
	 * @param React\Socket\ConnectionInterface $connection
	 * @param callable $handler
	 */
	public function __construct(React\Socket\ConnectionInterface $connection, callable $handler)
	{
		$this->connection = $connection;
		$this->handler = $handler;

		$this->connection->on('data', function(string $data): void {

			$this->buffer .= $data;

			try {
				// Frame parser ensures buffer won't exhaust memory.
				while (FCGI\FrameParser::hasFrame($this->buffer)) {
					$this->handle(FCGI\FrameParser::parseFrame($this->buffer));
				}
			} catch (Exception $exception) {
				$this->emit('error', [$exception]);
				$this->connection->close();
			}

		});

		$this->connection->on('close', function(): void {
			$this->emit('close');
		});
	}

	/**
	 * Closes connection.
	 * @return void
	 */
	public function close(): void
	{
		$this->connection->close();
	}

	/**
	 * Handles incoming frame.
	 * @param FCGI\Record $frame
	 * @throws Exception
	 */
	private function handle(FCGI\Record $frame): void
	{
		switch (true) {

			case $frame instanceof FCGI\Record\BeginRequest:
				$this->begin($frame);
				break;

			case $frame instanceof FCGI\Record\Params:
				$this->writeParameters($frame);
				break;

			case $frame instanceof FCGI\Record\Stdin:
				$this->writeBuffer($frame);
				break;

			case $frame instanceof FCGI\Record\AbortRequest:
				$this->writeAbort($frame);
				break;

			default:
				$this->emit('error', [new Exception('Unknown frame.')]);

		}
	}

	/**
	 * Begins a request.
	 * @param FCGI\Record\BeginRequest $frame
	 * @return void
	 * @throws Exception
	 */
	private function begin(FCGI\Record\BeginRequest $frame): void
	{
		$id = $frame->getRequestId();

		if (isset($this->requests[$id])) {
			$this->emit('error', [new Exception('Reused request ID.')]);
		}

		$request = new Connection\Request($id, $this->connection, $this->handler);
		$request->on('end', function() use($id): void {
			$this->emit('end', [$this->requests[$id]]);
			unset($this->requests[$id]);
		});

		$this->requests[$id] = $request;

		$this->emit('begin', [$request]);
	}

	/**
	 * Writes parameters to request.
	 * @param FCGI\Record\Params $parameters
	 * @return void
	 */
	private function writeParameters(FCGI\Record\Params $parameters): void
	{
		if (!$this->exists($parameters)) {
			return;
		}

		$this->requests[$parameters->getRequestId()]->writeParameters($parameters->getValues());
	}

	/**
	 * Writes buffer to request.
	 * @param FCGI\Record\Stdin $buffer
	 * @return void
	 */
	private function writeBuffer(FCGI\Record\Stdin $buffer): void
	{
		if (!$this->exists($buffer)) {
			return;
		}

		$this->requests[$buffer->getRequestId()]->writeBuffer($buffer->getContentData());
	}

	/**
	 * Writes abort to request.
	 * @param FCGI\Record\AbortRequest $abort
	 * @return void
	 */
	private function writeAbort(FCGI\Record\AbortRequest $abort): void
	{
		if (!$this->exists($abort)) {
			return;
		}

		$this->requests[$abort->getRequestId()]->writeAbort();
	}

	/**
	 * Validates request ID.
	 * @param FCGI\Record $record
	 * @return bool
	 */
	private function exists(FCGI\Record $record): bool
	{
		$id = $record->getRequestId();

		if (isset($this->requests[$id])) {
			return true;
		}

		$this->emit('error', [new Exception('Request ID does not exist.')]);
		return false;
	}

}