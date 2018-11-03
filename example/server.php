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

use Concurrent\Http\HttpServer;
use Concurrent\Network\TcpServer;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(-1);
ini_set('display_errors', '1');

$logger = new Logger('HTTP', [], [
    new PsrLogMessageProcessor()
]);

$factory = new Psr17Factory();

$handler = new class($factory, $logger) implements RequestHandlerInterface {

    protected $factory;

    protected $logger;

    public function __construct(Psr17Factory $factory, LoggerInterface $logger)
    {
        $this->factory = $factory;
        $this->logger = $logger;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->logger->debug('{method} {target} HTTP/{version}', [
            'method' => $request->getMethod(),
            'target' => $request->getRequestTarget(),
            'version' => $request->getProtocolVersion()
        ]);
        
        $path = $request->getUri()->getPath();

        if ($path == '/favicon.ico') {
            return $this->factory->createResponse(404);
        }

        $response = $this->factory->createResponse();
        $response = $response->withHeader('Content-Type', 'application/json');

        return $response->withBody($this->factory->createStream(\json_encode([
            'controller' => __FILE__,
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getQueryParams()
        ])));
    }
};

$tcp = TcpServer::listen('127.0.0.1', 8080);

$logger->info('Server listening on tcp://{address}:{port}', [
    'address' => $tcp->getAddress(),
    'port' => $tcp->getPort()
]);

$server = new HttpServer($factory, $factory, $tcp, $handler, $logger);
$server->run();
