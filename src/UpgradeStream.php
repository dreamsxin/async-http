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
use Concurrent\Stream\DuplexStream;
use Concurrent\Stream\ReadableStream;
use Concurrent\Stream\WritableStream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class UpgradeStream implements ReadableStream, WritableStream
{
    protected $request;

    protected $response;

    protected $stream;

    protected $buffer;

    public function __construct(RequestInterface $request, ResponseInterface $response, DuplexStream $stream, string $buffer = '')
    {
        $this->request = $request;
        $this->response = $response;
        $this->stream = $stream;
        $this->buffer = $buffer;
    }

    public function __destruct()
    {
        $this->stream->close();
    }
    
    public function getProtocol(): string
    {
        return \strtolower($this->response->getHeaderLine('Upgrade'));
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * {@inheritdoc}
     */
    public function close(?\Throwable $e = null): void
    {
        $this->stream->close($e);
        $this->buffer = '';
    }

    /**
     * {@inheritdoc}
     */
    public function read(?int $length = null): ?string
    {
        if ($this->buffer === '') {
            $this->buffer = $this->stream->read();
        }

        if ($this->buffer === '') {
            return null;
        }

        $chunk = \substr($this->buffer, 0, $length ?? 8192);
        $this->buffer = \substr($this->buffer, \strlen($chunk));

        return $chunk;
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $data): void
    {
        $this->stream->write($data);
    }

    /**
     * {@inheritdoc}
     */
    public function writeAsync(string $data, ?int $size = null): bool
    {
        if ($this->stream instanceof TcpSocket) {
            return $this->stream->writeAsync($data, $size);
        }

        $this->stream->write($data);

        return true;
    }
}
