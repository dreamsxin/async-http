<?php

/*
 * This file is part of Concurrent PHP HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

use Phalcon\Async\Context;
use Phalcon\Async\Signal;
use Phalcon\Async\Task;
use Phalcon\Async\Timer;
use Phalcon\Async\Http\HttpServer;
use Phalcon\Async\Http\HttpServerConfig;
use Phalcon\Async\Http\Event\Event;
use Phalcon\Async\Http\Event\EventServer;
use Phalcon\Async\Http\Event\EventServerClient;
use Phalcon\Async\Network\TcpServer;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(-1);
ini_set('display_errors', (DIRECTORY_SEPARATOR == '\\') ? '0' : '1');

$logger = new Logger('HTTP', [], [
    new PsrLogMessageProcessor()
]);

$factory = new Psr17Factory();

$handler = new class($factory, $logger) implements RequestHandlerInterface {

    protected $factory;

    protected $logger;
    
    protected $events;
    
    protected $sse;

    public function __construct(Psr17Factory $factory, LoggerInterface $logger)
    {
        $this->factory = $factory;
        $this->logger = $logger;

        $this->sse = file_get_contents(__DIR__ . '/sse.html');
        $this->events = new EventServer($factory);

        Task::asyncWithContext(Context::background(), function () {
            $timer = new Timer(500);
            $count = 0;

            try {
                while (!$this->events->isClosed()) {
                    $timer->awaitTimeout();

                    $this->events->broadcast(new Event(sprintf('Hello #%u', ++$count)));
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to broadcast event', [
                    'exception' => $e
                ]);
            }
        });
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /*
        $this->logger->debug('{method} {target} HTTP/{version}', [
            'method' => $request->getMethod(),
            'target' => $request->getRequestTarget(),
            'version' => $request->getProtocolVersion()
        ]);
        */
        $path = $request->getUri()->getPath();

        if ($path == '/favicon.ico') {
            return $this->factory->createResponse(404);
        }

        if ($path == '/sse') {
            $response = $this->factory->createResponse();
            $response = $response->withHeader('Content-Type', 'text/html');
            $response = $response->withBody($this->factory->createStream(strtr($this->sse, [
                '###URL###' => '/stream'
            ])));

            return $response;
        }

        if ($path == '/stream') {
            $client = $this->events->connect(function (EventServerClient $client) {
                $this->events->broadcast(new Event([
                    'type' => 'DISCONNECT',
                    'id' => $client->getId()
                ], 'presence'));
            });

            $id = $client->getId();

            $client->send(new Event('Welcome client ' . $id));

            $this->events->broadcast(new Event([
                'type' => 'CONNECT',
                'id' => $id
            ], 'presence'), [
                $id => true
            ]);

            return $this->events->createResponse($client);
        }

        $response = $this->factory->createResponse();
        $response = $response->withHeader('Content-Type', 'application/json');

        return $response->withBody($this->factory->createStream(json_encode([
            'controller' => __FILE__,
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getQueryParams()
        ])));
    }
};

$config = new HttpServerConfig($factory, $factory);
// $config = $config->withHttp2Driver(new Http2Driver([], $logger));

$server = new HttpServer($config, $logger);
$signal = new Signal(Signal::SIGINT);

$tls = null;

// $tls = $server->createEncryption();
// $tls = $tls->withDefaultCertificate(__DIR__ . '/cert/localhost.pem', null, 'localhost');

$tcp = TcpServer::listen('127.0.0.1', 8080);

$total = 1;
$listeners = [];
for ($i = 0; $i < $total; $i++) {
    $listeners[$i] = $server->run($tcp, $handler);
}

$logger->info('HTTP server listening on tcp://{address}:{port}', [
	'address' => $tcp->getAddress(),
	'port' => $tcp->getPort()
]);

$signal->awaitSignal();

$logger->info('HTTP server shutdown requested');

for ($i = 0; $i < $total; $i++) {
    $listener = $listeners[$i];
	$listener->shutdown();

    Task::async(function () use ($listener, $logger) {
        try {
            $listener->join();
        } finally {
            $logger->info('Shutdown completed');
        }
    });
}
