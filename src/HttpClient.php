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

use Concurrent\Network\TcpSocket;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class HttpClient extends HttpCodec implements ClientInterface
{
    protected $manager;

    protected $factory;

    protected $logger;

    public function __construct(ConnectionManager $manager, ResponseFactoryInterface $factory, ?LoggerInterface $logger = null)
    {
        $this->manager = $manager;
        $this->factory = $factory;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@inheritdoc}
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();

        $encrypted = ($uri->getScheme() == 'https') ? true : false;
        $host = $uri->getHost();

        if (false === ($i = \strrpos($host, ':'))) {
            $port = $encrypted ? 443 : 80;
        } else {
            $port = (int) \substr($host, $i + 1);
            $host = \substr($host, 0, $i);
        }

        $conn = $this->manager->checkout($host, $port, $encrypted);

        try {
            $this->writeRequest($conn, $request);

            $conn->socket->setNodelay(false);
        } catch (\Throwable $e) {
            $this->manager->release($conn, $e);

            throw $e;
        }

        return $this->readResponse($conn, $request);
    }

    protected function writeRequest(Connection $conn, RequestInterface $request): void
    {
        static $remove = [
            'Content-Length',
            'Expect',
            'Keep-Alive',
            'TE',
            'Trailer',
            'Transfer-Encoding'
        ];

        foreach ($remove as $name) {
            $request = $request->withoutHeader($name);
        }

        $body = $request->getBody();

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            if ($body->eof()) {
                $this->writeHeader($conn->socket, $request, '', false, 0, true);
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
                $this->writeHeader($conn->socket, $request, $chunk, false, \strlen($chunk), true);
                return;
            }

            if ($request->getProtocolVersion() == '1.0') {
                $chunk .= $body->getContents();

                $this->writeHeader($conn->socket, $request, $chunk, false, \strlen($chunk), true);
                return;
            }

            $this->writeHeader($conn->socket, $request, \sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk), false, -1);

            do {
                $chunk = $body->read(0xFFFF);

                if ($eof = $body->eof()) {
                    $chunk = \sprintf("%x\r\n%s\r\n0\r\n\r\n", \strlen($chunk), $chunk);

                    $conn->socket->setNodelay(true);
                } else {
                    $chunk = \sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk);
                }

                $conn->socket->write($chunk);
            } while (!$eof);
        } finally {
            $body->close();
        }
    }

    protected function writeHeader(TcpSocket $socket, RequestInterface $request, string $contents, bool $close, int $len, bool $nodelay = false): void
    {
        if ($close) {
            $request = $request->withHeader('Connection', 'close');
        } else {
            $request = $request->withHeader('Connection', 'keep-alive');
        }

        if ($len < 0) {
            if ($request->getProtocolVersion() != '1.0') {
                $request = $request->withHeader('Transfer-Encoding', 'chunked');
            }
        } else {
            $request = $request->withHeader('Content-Length', (string) $len);
        }

        $buffer = \sprintf("%s %s HTTP/%s\r\n", $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion());

        foreach ($request->getHeaders() as $k => $values) {
            foreach ($values as $v) {
                $buffer .= \sprintf("%s: %s\r\n", $k, $v);
            }
        }

        $socket->write($buffer . "\r\n" . $contents);

        if ($nodelay) {
            $socket->setNodelay(true);
        }
    }

    protected function readResponse(Connection $conn, RequestInterface $request): ResponseInterface
    {
        try {
            while (false === ($pos = \strpos($conn->buffer, "\r\n\r\n"))) {
                $chunk = $conn->socket->read();

                if ($chunk === null) {
                    throw new \RuntimeException('Failed to read next HTTP request');
                }

                $conn->buffer .= $chunk;
            }

            $header = \substr($conn->buffer, 0, $pos + 2);
            $conn->buffer = \substr($conn->buffer, $pos + 4);

            $pos = \strpos($header, "\n");
            $line = \substr($header, 0, $pos);
            $m = null;

            if (!\preg_match("'^\s*HTTP/(1\\.[01])\s+([1-5][0-9]{2})\s*(.*)$'is", $line, $m)) {
                throw new \RuntimeException('Invalid HTTP response line received');
            }

            $response = $this->factory->createResponse((int) $m[2], \trim($m[3]));
            $response = $response->withProtocolVersion($m[1]);
            $response = $this->populateHeaders($response, \substr($header, $pos + 1));

            if ($response->getProtocolVersion() == '1.0') {
                if ('keep-alive' !== \strtolower($response->getHeaderLine('Connection'))) {
                    $conn->maxRequests = 1;
                }
            } else {
                if ('close' === \strtolower($response->getHeaderLine('Connection'))) {
                    $conn->maxRequests = 1;
                }
            }
        } catch (\Throwable $e) {
            $this->manager->release($conn, $e);

            throw $e;
        }

        return $this->decodeBody(new ClientStream($this->manager, $conn), $response, $conn->buffer, true);
    }
}
