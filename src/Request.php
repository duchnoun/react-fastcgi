<?php

declare(strict_types=1);
namespace Adawolfa\FastCGI;
use Evenement\EventEmitterTrait;
use React\Http\Io\ServerRequest;

/**
 * Server request (exposes React HTTP implementation).
 *
 * @author Adam Klvač <adam@klva.cz>
 */
class Request extends ServerRequest
{

	use EventEmitterTrait;

}