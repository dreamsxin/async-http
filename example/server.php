<?php

/*
 * This file is part of Async PSR HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

use Concurrent\Http\HttpServer;
use Concurrent\Network\TcpServer;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(-1);
ini_set('display_errors', '1');

$logger = new Logger('HTTP', [], [
    new PsrLogMessageProcessor()
]);

$factory = new Psr17Factory();

$handler = new class($factory, $factory, $logger) implements RequestHandlerInterface {

    protected $factory;

    protected $stream;

    protected $logger;

    public function __construct(ResponseFactoryInterface $factory, StreamFactoryInterface $stream, LoggerInterface $logger)
    {
        $this->factory = $factory;
        $this->stream = $stream;
        $this->logger = $logger;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->logger->debug('{method} {target} HTTP/{version}', [
            'method' => $request->getMethod(),
            'target' => $request->getRequestTarget(),
            'version' => $request->getProtocolVersion()
        ]);

        if ($request->getUri()->getPath() == '/favicon.ico') {
            return $this->factory->createResponse(404);
        }

        $response = $this->factory->createResponse();
        $response = $response->withHeader('Content-Type', 'application/json');

        return $response->withBody($this->stream->createStream(\json_encode([
            'controller' => __FILE__,
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getQueryParams()
        ])));
    }
};

$tcp = TcpServer::listen('127.0.0.1', 8080);

$logger->info('Server listening on tcp://{address}:{port}', [
    'address' => $tcp->getHost(),
    'port' => $tcp->getPort()
]);

$server = new HttpServer($factory, $tcp, $handler, $logger);
$server->run();
