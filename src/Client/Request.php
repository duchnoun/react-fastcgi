<?php

declare(strict_types=1);
namespace AmK\FastCGI\Client;
use AmK\FastCGI\Response;
use Evenement\EventEmitter;
use Lisachenko\Protocol\FCGI;
use React;
use RingCentral\Psr7\Response as HttpResponse;
use function RingCentral\Psr7\parse_response;

/**
 * Client request.
 *
 * @author Adam Klvač <adam@klva.cz>
 */
class Request extends EventEmitter
{

	/** @var int */
	private $id;

	/** @var React\Socket\ConnectionInterface */
	private $connection;

	/** @var React\Promise\Deferred */
	private $deferred;

	/** @var string */
	private $buffer = '';

	/** @var React\Stream\ThroughStream */
	private $stream;

	/** @var Response|null */
	private $response;

	/** @var bool */
	private $aborted = false;

	/** @var bool */
	private $completed = false;

	/**
	 * Request constructor.
	 * @param int $id
	 * @param React\Socket\ConnectionInterface $connection
	 */
	public function __construct(int $id, React\Socket\ConnectionInterface $connection)
	{
		$this->id = $id;
		$this->connection = $connection;
		$this->deferred = new React\Promise\Deferred;
	}

	/**
	 * Writes response buffer.
	 * @param string $buffer
	 * @return void
	 * @internal
	 */
	public function writeBuffer(string $buffer): void
	{
		if ($this->response === null) {

			$this->buffer .= $buffer;

			if (($pos = strpos($this->buffer, "\r\n\r\n")) !== false) {

				$lines = explode("\r\n", substr($this->buffer, 0, $pos));
				$headers = [];
				$status = 200;

				foreach ($lines as $line) {

					$p = strpos($line, ':');

					if ($p === false) {
						continue;
					}

					$header = substr($line, 0, $p);
					$value = substr($line, $p + 2);

					if (strcasecmp($header, 'status') !== 0) {
						$headers[$header] = $value;
					}

				}

				$response = new HttpResponse($status, $headers);
				$this->stream = new React\Stream\ThroughStream;
				$this->response = new Response(
					$response->getStatusCode(),
					$response->getHeaders(),
					$this->stream,
					$response->getProtocolVersion(),
					$response->getReasonPhrase()
				);

				$buffer = substr($this->buffer, $pos + 4);
				$this->buffer = '';

				$this->emit('headers', [$this->response]);
				$this->deferred->resolve($this->response);

			}

		}

		if (strlen($buffer) > 0) {
			$this->stream->write($buffer);
		}
	}

	/**
	 * Writes end of buffer.
	 * @param int $status
	 * @return void
	 * @internal
	 */
	public function writeEnd(int $status): void
	{
		// Request might be aborted.
		if ($this->stream !== null) {
			$this->stream->end();
		}

		if (!$this->completed) {
			$this->emit('end');
			$this->completed = true;

			if ($status === FCGI::ABORT_REQUEST) {
				$this->aborted = true;
				$this->emit('abort');
			}
		}
	}

	/**
	 * Aborts the request.
	 * @return void
	 */
	public function abort(): void
	{
		if (!$this->aborted) {
			$abort = new FCGI\Record\EndRequest(FCGI::ABORT_REQUEST);
			$abort->setRequestId($this->id);
			$this->connection->write((string) $abort);
			$this->writeEnd(FCGI::ABORT_REQUEST);
		}
	}

	/**
	 * Returns request promise.
	 * @return React\Promise\PromiseInterface
	 */
	public function promise(): React\Promise\PromiseInterface
	{
		return $this->deferred->promise();
	}

}