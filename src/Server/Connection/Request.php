<?php

declare(strict_types=1);
namespace AmK\FastCGI\Server\Connection;
use AmK\FastCGI;
use Evenement\EventEmitter;
use Lisachenko\Protocol\FCGI;
use Psr\Http\Message\ResponseInterface;
use React;
use RingCentral\Psr7\BufferStream;
use Exception;
use RingCentral\Psr7\Uri;

/**
 * Server request.
 *
 * @author Adam Klvač <adam@klva.cz>
 */
class Request extends EventEmitter
{

	/** @var int */
	private $id;

	/** @var React\Socket\ConnectionInterface */
	private $connection;

	/** @var callable */
	private $handler;

	/** @var array|null */
	private $parameters = [];

	/** @var FastCGI\Request|null */
	private $serverRequest;

	/**
	 * Request constructor.
	 * @param int $id
	 * @param React\Socket\ConnectionInterface $connection
	 * @param callable $handler
	 */
	public function __construct(int $id, React\Socket\ConnectionInterface $connection, callable $handler)
	{
		$this->id = $id;
		$this->connection = $connection;
		$this->handler = $handler;
	}

	/**
	 * Writes parameters.
	 * @param array $parameters
	 */
	public function writeParameters(array $parameters): void
	{
		try {

			if ($this->serverRequest !== null) {
				throw new Exception('Parameters have already been set.');
			}

			if (!empty($parameters)) {
				$this->parameters = array_merge($this->parameters, $parameters);
			} else {
				$this->createServerRequest();
			}

		} catch (Exception $exception) {
			$this->emit('error', [$exception]);
		}
	}

	/**
	 * Writes request buffer.
	 * @param string $buffer
	 * @return void
	 */
	public function writeBuffer(string $buffer): void
	{
		try {

			if ($this->serverRequest === null) {
				throw new Exception('Parameters not set.');
			}

			if ($buffer !== '') {

				if ($this->serverRequest->getBody()->isWritable()) {
					$this->serverRequest->getBody()->write($buffer);
				}

			} else {
				$this->serverRequest->getBody()->close();
			}

		} catch (Exception $exception) {
			$this->emit('error', [$exception]);
		}
	}

	/**
	 * Aborts request processing.
	 * @return void
	 */
	public function writeAbort(): void
	{
		$this->emit('abort');

		if ($this->serverRequest !== null) {
			$this->serverRequest->emit('abort');
		}
	}

	/**
	 * Creates server request.
	 * @return void
	 * @throws Exception
	 */
	private function createServerRequest(): void
	{
		$headers = [];

		foreach ($this->parameters as $parameter => $value) {
			if (strpos($parameter, 'HTTP_') === 0) {
				$header = str_replace(' ', '-', ucwords(str_replace('_', ' ', substr($parameter, 5))));
				$headers[$header] = [$value];
			}
		}

		$this->serverRequest = new FastCGI\Request(
			$this->parameters['REQUEST_METHOD'] ?? null,
			$this->parameters['REQUEST_URI'] ?? new Uri,
			$headers,
			new BufferStream,
			$this->parameters['SERVER_PROTOCOL'] ?? '1.1',
			$this->parameters
		);

		$this->emit('headers', [$this->serverRequest]);

		$return = call_user_func($this->handler, $this->serverRequest);

		if ($return instanceof ResponseInterface) {
			$this->respond($return);
		} elseif ($return instanceof React\Promise\PromiseInterface) {
			$return->then(
				function (ResponseInterface $response) {
					$this->respond($response);
				},
				function ($error = null) {
					$this->abort();
				}
			);
		} elseif ($return === null || $return === false) {
			$this->abort();
		} else {
			throw new Exception('Server request handler should return ' . ResponseInterface::class . ' or promise.');
		}
	}

	/**
	 * Sends a response.
	 * @param ResponseInterface $response
	 * @return void
	 * @throws Exception
	 */
	private function respond(ResponseInterface $response): void
	{
		$this->emit('response', [$response]);

		$headers = 'HTTP/' . $response->getProtocolVersion();
		$headers .= ' ' . $response->getStatusCode();
		$headers .= ' ' . $response->getReasonPhrase();

		foreach ($response->getHeaders() as $header => $values) {
			foreach ($values as $value) {
				$headers .= "\r\n$header: $value";
			}
		}

		$headers .= "\r\n\r\n";

		$frame = new FCGI\Record\Stdout($headers);
		$frame->setRequestId($this->id);

		$this->connection->write((string) $frame);

		$stream = $response->getBody();

		if (!$stream instanceof React\Stream\ReadableStreamInterface) {

			if ($stream->getSize() === null) {
				throw new Exception('Response body should be instance of ' . React\Stream\ReadableStreamInterface::class . ' or should have content present.');
			}

			$stream = new React\Stream\ThroughStream;

		}

		$stream->on('data', function(string $data): void {
			$frame = new FCGI\Record\Stdout($data);
			$frame->setRequestId($this->id);
			$this->connection->write((string) $frame);
		});

		$stream->on('end', function(): void {

			$frame = new FCGI\Record\Stdout;
			$frame->setRequestId($this->id);

			$this->connection->write((string) $frame);

			$frame = new FCGI\Record\EndRequest;
			$frame->setRequestId($this->id);

			$this->connection->write((string) $frame);

			$this->emit('end');

		});

		if (!$response->getBody() instanceof React\Stream\ReadableStreamInterface) {
			$stream->end($response->getBody()->getContents());
		}
	}

	/**
	 * Aborts rejected response.
	 */
	private function abort(): void
	{
		$end = new FCGI\Record\EndRequest(FCGI::UNKNOWN_ROLE);
		$end->setRequestId($this->id);
		$this->connection->write((string) $end);
		$this->emit('end');
	}

}