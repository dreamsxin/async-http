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

namespace Concurrent\Http\Http2;

use Concurrent\Channel;
use Concurrent\Deferred;
use Concurrent\Task;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

class Stream
{
    protected $id;

    protected $conn;

    protected $hpack;
    
    protected $defer;
    
    protected $buffer;
    
    protected $channel;
    
    protected $sendWindow = 0xFFFF;
    
    protected $sendDefer;

    public function __construct(int $id, Connection $conn, HPack $hpack)
    {
        $this->id = $id;
        $this->conn = $conn;
        $this->hpack = $hpack;
    }

    public function processFrame(Frame $frame): void
    {
        switch ($frame->type) {
            case Frame::HEADERS:
                $data = $frame->getPayload();

                if ($frame->flags & Frame::PRIORITY_FLAG) {
                    $data = \substr($data, 5);
                }

                $this->buffer = $data;

                if ($frame->flags & Frame::END_HEADERS) {
                    if (!($frame->flags & Frame::END_STREAM)) {
                        $this->channel = new Channel(\PHP_INT_MAX);
                    }

                    try {
                        $this->defer->resolve($this->buffer);
                    } finally {
                        $this->buffer = null;
                    }
                }
                break;
            case Frame::CONTINUATION:
                $this->buffer = $frame->getPayload();

                if ($frame->flags & Frame::END_HEADERS) {
                    try {
                        $this->defer->resolve($this->buffer);
                    } finally {
                        $this->buffer = null;
                    }
                }
                break;
            case Frame::DATA:
                $data = $frame->getPayload();
                
                try {
                    if ($data !== '') {
                        $this->channel->send($data);
                    }
                } finally {
                    if ($frame->flags & Frame::END_STREAM) {
                        $this->channel->close();
                    }
                }                
                break;
            case Frame::WINDOW_UPDATE:
                $this->sendWindow += (int) \unpack('N', $frame->getPayload())[0];

                if ($this->sendDefer) {
                    $defer = $this->sendDefer;
                    $this->sendDefer = null;

                    $defer->resolve();
                }
                break;
        }
    }
    
    public function updateReceiveWindow(int $size): void
    {
        
    }

    public function sendRequest(RequestInterface $request, ResponseFactoryInterface $factory): ResponseInterface
    {
        $uri = $request->getUri();
        $target = $request->getRequestTarget();

        if ($target === '*') {
            $path = '*';
        } else {
            $path = '/' . \ltrim($target, '/');
        }

        $headers = [
            ':method' => $request->getMethod(),
            ':scheme' => $uri->getScheme(),
            ':authority' => $uri->getAuthority(),
            ':path' => $path
        ];

        $this->sendHeaders($this->encodeHeaders($request, $headers, [
            'host'
        ]));

        $this->sendBody($request);

        $this->defer = new Deferred();

        try {
            $headers = $this->hpack->decode(Task::await($this->defer->awaitable()));
        } finally {
            $this->defer = null;
        }

        $response = $factory->createResponse((int) $this->getFirstHeader(':status', $headers));
        $response = $response->withProtocolVersion('2.0');

        foreach ($headers as $entry) {
            if (($entry[0][0] ?? null) !== ':') {
                $response = $response->withAddedHeader(...$entry);
            }
        }

        if ($this->channel !== null) {
            $response = $response->withBody(new EntityStream($this->id, $this->conn, $this->channel));
        }

        return $response;
    }

    protected function getFirstHeader(string $name, array $headers, string $default = ''): string
    {
        foreach ($headers as $header) {
            if ($header[0] === $name) {
                return $header[1];
            }
        }

        return $default;
    }

    protected function sendHeaders(string $headers, bool $nobody = false)
    {
        $flags = Frame::END_HEADERS | ($nobody ? Frame::END_STREAM : Frame::NOFLAG);
        $chunkSize = 8192;

        if (\strlen($headers) > $chunkSize) {
            $parts = \str_split($headers, $chunkSize);
            $frames = [];

            $frames[] = new Frame(Frame::HEADERS, $this->id, $parts[0]);

            for ($size = \count($parts) - 2, $i = 1; $i < $size; $i++) {
                $frames[] = new Frame(Frame::CONTINUATION, $this->id, $parts[$i]);
            }

            $frames[] = new Frame(Frame::CONTINUATION, $this->id, $parts[\count($parts) - 1], $flags);

            $this->conn->sendFrames($frames);
        } else {
            $this->conn->sendFrame(new Frame(Frame::HEADERS, $this->id, $headers, $flags));
        }
    }

    protected function sendBody(MessageInterface $message): int
    {
        $body = $message->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        try {
            $chunkSize = 8192;
            $sent = 0;

            while (!$body->eof()) {
                $chunk = $body->read($chunkSize);

                if ($chunk === '') {
                    continue;
                }

                $sent += \strlen($chunk);

                $this->conn->sendFrame(new Frame(Frame::DATA, $this->id, $chunk));
            }

            $this->conn->sendFrame(new Frame(Frame::DATA, $this->id, '', Frame::END_STREAM));

            return $sent;
        } finally {
            $body->close();
        }
    }

    protected function encodeHeaders(MessageInterface $message, array $headers, array $remove = [])
    {
        static $removeDefault = [
            'connection',
            'content-length',
            'keep-alive',
            'transfer-encoding',
            'te'
        ];

        foreach (\array_change_key_case($message->getHeaders(), \CASE_LOWER) as $k => $v) {
            if (!isset($headers[$k])) {
                $headers[$k] = $v;
            }
        }

        foreach ($removeDefault as $name) {
            unset($headers[$name]);
        }

        foreach ($remove as $name) {
            unset($headers[$name]);
        }

        $headerList = [];

        foreach ($headers as $k => $h) {
            if (\is_array($h)) {
                foreach ($h as $v) {
                    $headerList[] = [
                        $k,
                        $v
                    ];
                }
            } else {
                $headerList[] = [
                    $k,
                    $h
                ];
            }
        }

        return $this->hpack->encode($headerList);
    }
}
