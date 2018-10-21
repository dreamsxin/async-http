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

use Concurrent\Network\ClientEncryption;
use Concurrent\Network\TcpSocket;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class HttpClient extends HttpCodec implements ClientInterface
{
    protected $factory;

    protected $logger;
    
    protected $zlib;

    public function __construct(ResponseFactoryInterface $factory, ?LoggerInterface $logger = null)
    {
        $this->factory = $factory;
        $this->logger = $logger;
        
        $this->zlib = \function_exists('inflate_init');
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

        if ($encrypted) {
            $encryption = new ClientEncryption();
        } else {
            $encryption = null;
        }

        $socket = TcpSocket::connect($host, $port, $encryption);

        try {
            if ($encryption) {
                $socket->encrypt();
            }

            if ($this->zlib) {
                $request = $request->withAddedHeader('Accept-Encoding', 'gzip, deflate');
            }
            
            $this->writeRequest($socket, $request);
            
            $socket->setNodelay(false);

            return $this->readResponse($socket, $request);
        } catch (\Throwable $e) {
            $socket->close($e);

            throw $e;
        }
    }

    protected function writeRequest(TcpSocket $socket, RequestInterface $request): void
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
                $this->writeHeader($socket, $request, '', true, 0, true);
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
                $this->writeHeader($socket, $request, $chunk, true, \strlen($chunk), true);
                return;
            }

            if ($request->getProtocolVersion() == '1.0') {
                $chunk .= $body->getContents();

                $this->writeHeader($socket, $request, $chunk, true, \strlen($chunk), true);
                return;
            }
            
            $this->writeHeader($socket, $request, \sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk), true, -1);
            
            do {
                $chunk = $body->read(0xFFFF);
                
                if ($eof = $body->eof()) {
                    $chunk = \sprintf("%x\r\n%s\r\n0\r\n\r\n", \strlen($chunk), $chunk);
                    
                    $socket->setNodelay(true);
                } else {
                    $chunk = \sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk);
                }
                
                $socket->write($chunk);
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

    protected function readResponse(TcpSocket $socket, RequestInterface $request): ResponseInterface
    {
        $buffer = '';

        while (false === ($pos = \strpos($buffer, "\r\n\r\n"))) {
            $chunk = $socket->read();

            if ($chunk === null) {
                throw new \RuntimeException('Failed to read next HTTP request');
            }

            $buffer .= $chunk;
        }

        $header = \substr($buffer, 0, $pos + 2);
        $buffer = \substr($buffer, $pos + 4);

        $pos = \strpos($header, "\n");
        $line = \substr($header, 0, $pos);
        $m = null;
        if (!\preg_match("'^\s*HTTP/(1\\.[01])\s+([1-5][0-9]{2})\s*(.*)$'is", $line, $m)) {
            throw new \RuntimeException('Invalid HTTP response line received');
        }
        
        $response = $this->factory->createResponse((int) $m[2], \trim($m[3]));
        $response = $response->withProtocolVersion($m[1]);
        $response = $this->populateHeaders($response, \substr($header, $pos + 1));
        
        return $this->decodeBody($socket, $response, $buffer, true);
    }
}
