<?php

declare(strict_types=1);
namespace AmK\FastCGI;
use Evenement\EventEmitter;
use Lisachenko\Protocol\FCGI;
use Psr\Http\Message\ServerRequestInterface;
use React;
use Exception;

/**
 * Asynchronous FastCGI client.
 *
 * @author Adam Klvač <adam@klva.cz>
 */
class Client extends EventEmitter
{

	/** @var React\Socket\ConnectionInterface */
	private $connection;

	/** @var int */
	private $id = 1;

	/** @var string */
	private $buffer = '';

	/** @var Client\Request[] */
	private $requests = [];

	/**
	 * Client constructor.
	 * @param React\Socket\ConnectionInterface $connection
	 */
	public function __construct(React\Socket\ConnectionInterface $connection)
	{
		$this->connection = $connection;

		$this->connection->on('data', function(string $buffer): void {
			$this->buffer .= $buffer;
			while (FCGI\FrameParser::hasFrame($this->buffer)) {
				$this->handle(FCGI\FrameParser::parseFrame($this->buffer));
			}
		});

		$this->connection->on('close', function(): void {
			$this->emit('close');
		});
	}

	/**
	 * Sends a server request to FastCGI server.
	 * @param ServerRequestInterface $serverRequest
	 * @return React\Promise\PromiseInterface
	 */
	public function send(ServerRequestInterface $serverRequest): React\Promise\PromiseInterface
	{
		$id = $this->id++;

		$frame = [
			new FCGI\Record\BeginRequest(FCGI::RESPONDER, FCGI::KEEP_CONN),
			new FCGI\Record\Params($this->buildParameters($serverRequest)),
			new FCGI\Record\Params,
			new FCGI\Record\Stdin,
		];

		foreach ($frame as $packet) {
			$packet->setRequestId($id);
		}

		$this->connection->write(implode('', $frame));

		$request = new Client\Request($id, $this->connection);
		$request->on('end', function() use($id): void {
			unset($this->requests[$id]);
		});

		$this->requests[$id] = $request;
		$this->emit('begin', [$request]);
		return $request->promise();
	}

	/**
	 * Builds FastCGI parameters.
	 * @param ServerRequestInterface $serverRequest
	 * @return array
	 */
	private function buildParameters(ServerRequestInterface $serverRequest): array
	{
		$parameters = $serverRequest->getServerParams();

		if (!isset($parameters['QUERY_STRING'])) {
			$parameters['QUERY_STRING'] = $serverRequest->getUri()->getQuery();
		}

		if (!isset($parameters['REQUEST_URI'])) {
			$parameters['REQUEST_URI'] = (string) $serverRequest->getUri();
		}

		if (!isset($parameters['REQUEST_METHOD'])) {
			$parameters['REQUEST_METHOD'] = $serverRequest->getMethod();
		}

		if (!isset($parameters['SERVER_PROTOCOL'])) {
			$parameters['SERVER_PROTOCOL'] = $serverRequest->getProtocolVersion();
		}

		// TODO: Split duplicate headers into their own packets? FCGI is not capable of this.
		foreach ($serverRequest->getHeaders() as $header => $_) {
			$key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
			$parameters[$key] = $serverRequest->getHeaderLine($header);
		}

		return $parameters;
	}

	/**
	 * Handles response frame.
	 * @param FCGI\Record $record
	 * @return void
	 */
	private function handle(FCGI\Record $record): void
	{
		$id = $record->getRequestId();

		switch (true) {

			case $record instanceof FCGI\Record\Stdout:
				$this->requests[$id]->writeBuffer($record->getContentData());
				break;

			case $record instanceof FCGI\Record\EndRequest:
				$this->requests[$id]->writeEnd($record->getProtocolStatus());
				break;

			default:

				$exception = new Exception('Unknown frame.');

				if (isset($this->requests[$id])) {
					$this->requests[$id]->emit('error', [$exception]);
				} else {
					$this->emit('error', [$exception]);
				}

		}
	}

}