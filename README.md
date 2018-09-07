# FastCGI server and client

This is asynchronous FastCGI both server and client implementation for PHP, more specifically ReactPHP. CGI requests and responses are converted to standard HTTP interop message implementations developers are already familiar with. That makes writing a server or client very easy and pretty much the same like writing their HTTP counterpart.

## Installing

~~~
composer install adawolfa/fastcgi
~~~

## Writing client

Client must be obtained using connector. This is different from HTTP client, since FastCGI isn't limited to one request and response per connection. You can (and should) send multiple requests. Note that their parallel processing is limited by the server you are connecting to (for instance, native PHP CGI doesn't support it, but handles queued requests correctly).

~~~php
use Adawolfa\FastCGI;

$loop = React\EventLoop\Factory::create();
$connector = new FastCGI\Connector($loop);

$connector->connect('127.0.0.1:9000')->then(function(FastCGI\Client $client) {

    $request = new FastCGI\Request('GET', '/index.php');

    $client->send($request)->then(function(FastCGI\Response $response) {
        print (string) $response->getBody();
    });

});

$loop->run();
~~~

### Handling errors, aborts and timeouts

You can obtain request wrapper using listener:

~~~php
$client->on('begin', function(FastCGI\Client\Request $request) {

    $request->on('headers', function(FastCGI\Response $response) {
        // This is what promise handler is fulfilled with.
    });

    $request->on('error', function(Throwable $exception) {
        // Incorrect frame (protocol specific error, you can ignore those).
    });

    $request->on('abort', function(Throwable $exception) {
        // Called when server aborts the request.
    });

    $request->on('end', function() {
        // Called on response body end, always.
    });

});

$client->on('error', function(Throwable $exception) {
    // Protocol error, usually not important.
});

$client->on('close', function() {
    // This happens if server or client closes the connection.
});
~~~

If you need some sort of timeout, listen for `begin` event, set a timer and cancel it once `headers` occur.

~~~php
$client->on('begin', function(FastCGI\Client\Request $request) use($loop) {

    $timer = $loop->addTimer(10, function() use($request) {
        $request->abort();
    });

    $request->on('headers', function(FastCGI\Response $response) use($loop, $timer) {
        $loop->cancelTimer($timer);
    });

});
~~~

## Writing server

Writing the FastCGI server is very similar to writing its HTTP counterpart. Your handler is expected to return a HTTP response or a promise evaluating into one.

~~~php
use Adawolfa\FastCGI;

$loop = React\EventLoop\Factory::create();

$server = new FastCGI\Server(function(FastCGI\Request $request) {
    return new FastCGI\Response(200);
});

$socket = new React\Socket\Server('127.0.0.1:9000', $loop);
$server->listen($socket);

$loop->run();
~~~

### Advanced usage

Server emits a `connection` whenever a client connects. You can use this for writing a limiter or further request handling.

~~~php
$connected = 0;

$server->on('connection', function(FastCGI\Server\Connection $connection) use(&$connected) {

    $connection->on('close', function() use(&$connected) {
        $connected--;
    });

    if (++$connected > 10) {
        $connection->close();
    }

});
~~~

FastCGI request is wrapped in `FastCGI\Server\Connection\Request`, which emits various events you might be interested in.

~~~php
$server->on('connection', function(FastCGI\Server\Connection $connection) use(&$connected) {

    $connection->on('begin', function(FastCGI\Server\Connection\Request $request) {

        $request->on('abort', function() {
            // Called when request is aborted by server.
        });

        $request->on('error', function(Throwable $exception) {
            // Called on protocol violations, ...
        });

        $request->on('end', function() {
            // Called on response body end.
        });

    });

});
~~~

Detecting client abortion is not necessary, but encouraged. For ease of use, `FastCGI\Request` emits this event as well:

~~~php
$server = new FastCGI\Server(function(FastCGI\Request $request) {

    $deferred = new Deferred;

    $request->on('abort', function() use($deferred) {
        $deferred->reject();
    });

    // You should handle request here and call resolve() on deferred here.

    return $deferred->promise();

});
~~~

If you, for whatever reason, need to reject a server request, return `null` or `false` from your handler. Alternatively, return a promise and reject it. Keep in mind that this isn't meant to be used for dropping malformed client requests and your web server will probably return a gateway error.

~~~php
$server = new FastCGI\Server(function(FastCGI\Request $request) {

    // Not talking with anyone.
    return false;

});
~~~

## Tests

~~~
vendor/bin/tester tests
~~~

## License

Package is licensed as MIT, see [LICENSE.md](LICENSE.md).