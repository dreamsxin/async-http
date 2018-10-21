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

namespace Concurrent\Http;

use Concurrent\Task;
use Concurrent\Network\TcpServer;
use Concurrent\Network\TcpSocket;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class HttpServer extends HttpCodec
{
    protected $factory;

    protected $server;

    protected $handler;

    protected $logger;

    public function __construct(ServerRequestFactoryInterface $factory, TcpServer $server, RequestHandlerInterface $handler, ?LoggerInterface $logger = null)
    {
        $this->factory = $factory;
        $this->server = $server;
        $this->handler = $handler;
        $this->logger = $logger;
    }

    public function run(): void
    {
        while (true) {
            try {
                $socket = $this->server->accept();
            } catch (\Throwable $e) {
                continue;
            }

            Task::async(\Closure::fromCallable([
                $this,
                'handleSocket'
            ]), $socket);
        }
    }

    protected function handleSocket(TcpSocket $socket): void
    {
        $buffer = '';

        try {
            do {
                $socket->setNodelay(false);

                if (null === ($request = $this->readRequest($socket, $buffer))) {
                    break;
                }

                $response = $this->handler->handle($request);

                if ($request->getProtocolVersion() == '1.0') {
                    $close = ('keep-alive' !== \strtolower($request->getHeaderLine('Connection')));
                } else {
                    $close = ('close' === \strtolower($request->getHeaderLine('Connection')));
                }

                $this->sendResponse($socket, $request, $response, $close);
            } while (!$close);
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error(\sprintf('%s: %s', \get_class($e), $e->getMessage()), [
                    'exception' => $e
                ]);
            } else {
                \fwrite(STDERR, "$e\n\n");
            }

            throw $e;
        } finally {
            $socket->close();
        }
    }

    protected function readRequest(TcpSocket $socket, string & $buffer): ?ServerRequestInterface
    {
        $remaining = 0x4000;

        while (false === ($pos = \strpos($buffer, "\r\n\r\n"))) {
            if ($remaining == 0) {
                throw new \RuntimeException('Maximum HTTP header size exceeded');
            }

            $chunk = $socket->read($remaining);

            if ($chunk === null) {
                if ($buffer === '') {
                    return null;
                }

                throw new \RuntimeException('Failed to read next HTTP request');
            }

            $buffer .= $chunk;
            $remaining -= \strlen($chunk);
        }

        $header = \substr($buffer, 0, $pos + 2);
        $buffer = \substr($buffer, $pos + 4);

        $pos = \strpos($header, "\n");
        $line = \substr($header, 0, $pos);
        $m = null;

        if (!\preg_match("'^\s*(\S+)\s+(\S+)\s+HTTP/(1\\.[01])\s*$'is", $line, $m)) {
            throw new \RuntimeException('Invalid HTTP request line received');
        }

        $request = $this->factory->createServerRequest($m[1], $m[2]);
        $request = $request->withProtocolVersion($m[3]);
        $request = $this->populateHeaders($request, \substr($header, $pos + 1));

        return $this->decodeBody($socket, $request, $buffer);
    }

    protected function normalizeResponse(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        static $remove = [
            'Content-Length',
            'Keep-Alive',
            'TE',
            'Trailer',
            'Transfer-Encoding'
        ];

        foreach ($remove as $name) {
            $response = $response->withoutHeader($name);
        }

        $response = $response->withProtocolVersion($request->getProtocolVersion());
        $response = $response->withHeader('Date', \gmdate(self::DATE_RFC1123));

        return $response;
    }

    protected function sendResponse(TcpSocket $socket, ServerRequestInterface $request, ResponseInterface $response, bool & $close): void
    {
        $body = $request->getBody();

        try {
            while (!$body->eof()) {
                $body->read(0xFFFF);
            }
        } finally {
            $body->close();
        }

        $response = $this->normalizeResponse($request, $response);
        $body = $response->getBody();

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            if ($body->eof()) {
                $this->writeHeader($socket, $response, '', $close, 0, !$close);
                return;
            }

            $chunk = '';
            $eof = false;
            $i = 0;

            while (!$eof && ($i = \strlen($chunk)) < 0x8000) {
                $chunk .= $body->read(0x8000 - $i);
                $eof = $body->eof();
            }

            if ($eof) {
                $this->writeHeader($socket, $response, $chunk, $close, \strlen($chunk), !$close);
                return;
            }

            if ($request->getProtocolVersion() == '1.0') {
                $close = true;

                $this->writeHeader($socket, $response, $chunk, $close, -1);

                do {
                    $socket->write($chunk = $body->read(0xFFFF));
                } while (!$body->eof());

                return;
            }

            $this->writeHeader($socket, $response, \sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk), $close, -1);

            do {
                $chunk = $body->read(0xFFFF);

                if ($eof = $body->eof()) {
                    $chunk = \sprintf("%x\r\n%s\r\n0\r\n\r\n", \strlen($chunk), $chunk);
                    
                    if (!$close) {
                        $socket->setNodelay(true);
                    }
                } else {
                    $chunk = \sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk);
                }

                $socket->write($chunk);
            } while (!$eof);
        } finally {
            $body->close();
        }
    }
    
    protected function writeHeader(TcpSocket $socket, ResponseInterface $response, string $contents, bool $close, int $len, bool $nodelay = false): void
    {
        if ($close) {
            $response = $response->withHeader('Connection', 'close');
        } else {
            $response = $response->withHeader('Connection', 'keep-alive');
        }
        
        if ($len < 0) {
            if ($response->getProtocolVersion() != '1.0') {
                $response = $response->withHeader('Transfer-Encoding', 'chunked');
            }
        } else {
            $response = $response->withHeader('Content-Length', (string) $len);
        }

        $reason = \trim($response->getReasonPhrase());

        $buffer = \sprintf("HTTP/%s %u%s\r\n", $response->getProtocolVersion(), $response->getStatusCode(), \rtrim(' ' . $reason));

        foreach ($response->getHeaders() as $k => $values) {
            foreach ($values as $v) {
                $buffer .= \sprintf("%s: %s\r\n", $k, $v);
            }
        }
        
        $socket->write($buffer . "\r\n" . $contents);
        
        if ($nodelay) {
            $socket->setNodelay(true);
        }
    }
}
