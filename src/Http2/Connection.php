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

use Concurrent\Context;
use Concurrent\Deferred;
use Concurrent\Task;
use Concurrent\Network\SocketStream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

class Connection
{
    /**
     * Connection preface that must be sent by clients.
     */
    public const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    
    public const INITIAL_WINDOW_SIZE = 0xFFFF;
    
    public const SETTING_HEADER_TABLE_SIZE = 0x01;
    
    public const SETTING_ENABLE_PUSH = 0x02;
    
    public const SETTING_MAX_CONCURRENT_STREAMS = 0x03;
    
    public const SETTING_INITIAL_WINDOW_SIZE = 0x04;
    
    public const SETTING_MAX_FRAME_SIZE = 0x05;
    
    public const SETTING_MAX_HEADER_LIST_SIZE = 0x06;
    
    protected $socket;
    
    protected $hpack;
    
    protected $buffer;
    
    protected $client;
    
    protected $streams;
    
    protected $nextStreamId;
    
    protected $receiveWindow = 0xFFFF;
    
    protected $sendWindow = 0xFFFF;

    protected function __construct(SocketStream $socket, HPack $hpack, ?Deferred $defer = null, string $buffer = '')
    {
        $this->socket = $socket;
        $this->hpack = $hpack;
        $this->buffer = $buffer;
        $this->client = $defer ? true : false;

        $streams = [];
        
        $this->streams = & $streams;
        $this->nextStreamId = $this->client ? 1 : 2;

        $task = Task::asyncWithContext(Context::background(), static function () use ($socket, $buffer, $defer, & $streams) {
            $len = \strlen($buffer);

            while (true) {
                while ($len < 9) {
                    if (null === ($chunk = $socket->read())) {
                        break;
                    }
                    
                    $buffer .= $chunk;
                    $len += \strlen($chunk);
                }

                $header = \substr($buffer, 0, 9);

                $buffer = \substr($buffer, 9);
                $len -= 9;

                $length = \unpack('N', "\x00" . $header)[1];
                $stream = \unpack('N', "\x7F\xFF\xFF\xFF" & \substr($header, 5, 4))[1];

                if ($length == 0) {
                    $frame = new Frame(\ord($header[3]), $stream, '', \ord($header[4]));
                } else {
                    while ($len < $length) {
                        if (null === ($chunk = $socket->read())) {
                            break;
                        }

                        $buffer .= $chunk;
                        $len += \strlen($chunk);
                    }

                    $frame = new Frame(\ord($header[3]), $stream, \substr($buffer, 0, $length), \ord($header[4]));

                    $buffer = \substr($buffer, $length);
                    $len -= $length;
                }

                \fwrite(STDERR, "IN << {$frame}\n");
                
                if ($frame->type == Frame::GOAWAY) {
                    break;
                }
                
                // Ignore upgrade response.
                if ($frame->stream == 1) {
                    continue;
                }

                if ($frame->type == Frame::SETTINGS && !($frame->flags & Frame::ACK)) {
                    $frame = new Frame(Frame::SETTINGS, 0, '', Frame::ACK);

                    \fwrite(STDERR, "OUT >> {$frame}\n");

                    $socket->write($frame->encode());

                    if ($defer) {
                        $tmp = $defer;
                        $defer = null;

                        $frame = new Frame(Frame::WINDOW_UPDATE, 0, \pack('N', 0x0FFFFFFF));

                        \fwrite(STDERR, "OUT >> {$frame}\n");

                        $socket->write($frame->encode());

                        $tmp->resolve();
                    }

                    continue;
                }
                
                if ($frame->type == Frame::WINDOW_UPDATE) {
                    $this->sendWindow += (int) \unpack('N', $frame->getPayload())[0];
                    
                    if ($this->sendDefer) {
                        $defer = $this->sendDefer;
                        $this->sendDefer = null;
                        
                        $defer->resolve();
                    }
                }

                if ($frame->stream == 0) {
                    continue;
                }

                if (empty($streams[$frame->stream])) {
                    break;
                }
                
                $streams[$frame->stream]->processFrame($frame);
            }
        });

        Deferred::transform($task, function (?\Throwable $e) {
            if ($e) {
                \fwrite(STDERR, $e->getMessage() . "\n\n");
            }
        });
    }
    
    public static function connect(SocketStream $socket, HPack $hpack, string $buffer = ''): Connection
    {
        $conn = new Connection($socket, $hpack, $defer = new Deferred(), $buffer);

        Task::await($defer->awaitable());

        return $conn;
    }

    public function __debugInfo(): array
    {
        return [
            'socket' => $this->socket
        ];
    }

    public function sendFrame(Frame $frame): void
    {
        \fwrite(STDERR, "OUT >> {$frame}\n");
        
        $this->socket->write($frame->encode());
    }
    
    public function sendFrameAsync(Frame $frame): void
    {
        \fwrite(STDERR, "OUT (Q) >> {$frame}\n");
        
        $this->socket->writeAsync($frame->encode());
    }

    public function sendFrames(array $frames): void
    {
        $buffer = '';

        foreach ($frames as $frame) {
            \fwrite(STDERR, "OUT >> {$frame}\n");
            
            $buffer .= $frame->encode();
        }

        $this->socket->write($buffer);
    }
    
    public function sendFramesAsync(array $frames): void
    {
        $buffer = '';

        foreach ($frames as $frame) {
            \fwrite(STDERR, "OUT (Q) >> {$frame}\n");

            $buffer .= $frame->encode();
        }

        $this->socket->writeAsync($buffer);
    }

    public function updateReceiveWindow(int $size, ?int $stream = null): void
    {
        $frame = new Frame(Frame::WINDOW_UPDATE, 0, \pack('N', $size));

        if ($stream === null) {
            $this->sendFrameAsync($frame);
        } else {
            $this->sendFramesAsync([
                $frame,
                new Frame(Frame::WINDOW_UPDATE, $stream, $frame->data)
            ]);
            
            $this->streams[$stream]->updateReceiveWindow($size);
        }
    }
    
    public function sendRequest(RequestInterface $request, ResponseFactoryInterface $factory): ResponseInterface
    {
        $this->nextStreamId += 2;
        
        $stream = new Stream($this->nextStreamId, $this, $this->hpack);
        $this->streams[$this->nextStreamId] = $stream;
        
        return $stream->sendRequest($request, $factory);
    }
}
