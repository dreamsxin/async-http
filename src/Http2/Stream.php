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
use Concurrent\Stream\StreamClosedException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

class Stream
{
    protected $id;

    protected $state;
    
    protected $defer;
    
    protected $buffer;
    
    protected $channel;
    
    protected $receiveWindow;
    
    protected $receiveDefer;
    
    protected $sendWindow;
    
    protected $sendDefer;

    public function __construct(int $id, ConnectionState $state)
    {
        $this->id = $id;
        $this->state = $state;

        $this->receiveWindow = $state->localSettings[Connection::SETTING_INITIAL_WINDOW_SIZE];
        $this->sendWindow = $state->remoteSettings[Connection::SETTING_INITIAL_WINDOW_SIZE];
    }

    public function close(?\Throwable $e = null): void
    {
        if (empty($this->state->streams[$this->id])) {
            return;
        }
        
        unset($this->state->streams[$this->id]);

        if ($this->channel !== null) {
            $channel = $this->channel;
            $this->channel = null;

            $channel->close($e);
        }

        if ($this->sendDefer !== null) {
            $defer = $this->sendDefer;
            $this->sendDefer = null;

            $defer->fail($e ?? new StreamClosedException('Stream has been closed'));
        }

        if ($this->receiveDefer !== null) {
            $defer = $this->receiveDefer;
            $this->receiveDefer = null;

            $defer->fail($e ?? new StreamClosedException('Stream has been closed'));
        }
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
                $len = \strlen($data);

                $this->receiveWindow -= $len;
                $this->state->receiveWindow -= $len;

                if ($this->state->receiveWindow < 0) {
                    if ($this->state->receiveDefer === null) {
                        $this->state->receiveDefer = new Deferred();
                    }

                    Task::await($this->state->receiveDefer);
                }

                if ($this->receiveWindow < 0) {
                    $this->receiveDefer = new Deferred();

                    Task::await($this->receiveDefer->awaitable());
                }
                
                try {
                    if ($data !== '') {
                        $this->channel->send($data);
                    }
                } finally {
                    if ($frame->flags & Frame::END_STREAM) {
                        $this->close();
                    }
                }                
                break;
            case Frame::WINDOW_UPDATE:
                $this->sendWindow += (int) \unpack('N', $frame->getPayload())[1];

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
        $frame = new Frame(Frame::WINDOW_UPDATE, 0, \pack('N', $size));

        $this->state->sendFramesAsync([
            $frame,
            new Frame(Frame::WINDOW_UPDATE, $this->id, $frame->data)
        ]);

        $this->receiveWindow += $size;
        $this->state->receiveWindow += $size;

        if ($this->state->receiveDefer !== null) {
            $defer = $this->state->receiveDefer;
            $this->state->receiveDefer = null;

            $defer->resolve();
        }

        if ($this->receiveDefer !== null) {
            $defer = $this->receiveDefer;
            $this->receiveDefer = null;

            $defer->resolve();
        }
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
            $headers = $this->state->hpack->decode(Task::await($this->defer->awaitable()));
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
            $response = $response->withBody(new EntityStream($this, $this->channel->getIterator()));
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

        if (\strlen($headers) > 0x4000) {
            $parts = \str_split($headers, 0x4000);
            $frames = [];

            $frames[] = new Frame(Frame::HEADERS, $this->id, $parts[0]);

            for ($size = \count($parts) - 2, $i = 1; $i < $size; $i++) {
                $frames[] = new Frame(Frame::CONTINUATION, $this->id, $parts[$i]);
            }

            $frames[] = new Frame(Frame::CONTINUATION, $this->id, $parts[\count($parts) - 1], $flags);

            $this->state->sendFrames($frames);
        } else {
            $this->state->sendFrame(new Frame(Frame::HEADERS, $this->id, $headers, $flags));
        }
    }

    protected function sendBody(MessageInterface $message): int
    {
        $body = $message->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }
        
        $sent = 0;

        try {
            while (!$body->eof()) {
                $chunk = $body->read(0x4000);

                if ($chunk === '') {
                    continue;
                }

                $len = \strlen($chunk);

                do {
                    if ($len > $this->sendWindow) {
                        $this->sendDefer = new Deferred();

                        Task::await($this->sendDefer);

                        continue;
                    }

                    if ($len > $this->state->sendWindow) {
                        if ($this->state->sendDefer === null) {
                            $this->state->sendDefer = new Deferred();
                        }

                        Task::await($this->sendDefer);

                        continue;
                    }
                } while (false);

                $sent += $len;

                $this->sendWindow -= $len;
                $this->state->sendWindow -= $len;

                $this->state->sendFrame(new Frame(Frame::DATA, $this->id, $chunk));
            }

            $this->state->sendFrame(new Frame(Frame::DATA, $this->id, '', Frame::END_STREAM));
        } finally {
            $body->close();
        }

        return $sent;
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

        return $this->state->hpack->encode($headerList);
    }
}
