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

use Concurrent\Stream\ReadableStream;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

abstract class HttpCodec
{
    protected const DATE_RFC1123 = 'D, d M Y H:i:s \G\M\T';
        
    protected const HEADER_REGEX = "(^([^()<>@,;:\\\"/[\]?={}\x01-\x20\x7F]++):[ \t]*+((?:[ \t]*+[\x21-\x7E\x80-\xFF]++)*+)[ \t]*+\r\n)m";

    protected const HEADER_FOLD_REGEX = "(\r\n[ \t]++)";
    
    protected function decodeBody(ReadableStream $stream, MessageInterface $message, string & $buffer, bool $close = false): MessageInterface
    {
        if ($message->hasHeader('Content-Length')) {
            if (($len = (int) $message->getHeaderLine('Content-Length')) > 0) {
                $message = $message->withBody(new IteratorStream($this->readLengthDelimitedBody($stream, $len, $buffer, $close)));
            } elseif ($close) {
                $stream->close();
            }
        } elseif ('chunked' == \strtolower($message->getHeaderLine('Transfer-Encoding'))) {
            $message = $message->withoutHeader('Transfer-Encoding');
            $message = $message->withBody(new IteratorStream($this->readChunkEncodedBody($stream, $buffer, $close)));
        } elseif ($close) {
            $stream->close();
        }

        if ('' !== ($encoding = $message->getHeaderLine('Content-Encoding'))) {
            switch (\strtolower($encoding)) {
                case 'gzip':
                    $message = $message->withBody(new IteratorStream($this->decompressBody($message->getBody(), \ZLIB_ENCODING_GZIP)));
                    break;
                case 'deflate':
                    $message = $message->withBody(new IteratorStream($this->decompressBody($message->getBody(), \ZLIB_ENCODING_DEFLATE)));
                    break;
                default:
                    throw new \RuntimeException(\sprintf('Invalid content encoding: "%s"', $encoding));
            }

            $message = $message->withoutHeader('Content-Encoding');
        }

        return $message;
    }

    protected function readLengthDelimitedBody(ReadableStream $socket, int $len, string & $buffer, bool $close = false): \Generator
    {
        try {
            while ($len > 0) {
                if ($buffer === '') {
                    $buffer = $socket->read();

                    if ($buffer === null) {
                        throw new \RuntimeException('Unexpected end of HTTP body stream');
                    }
                }

                $chunk = \substr($buffer, 0, $len);
                $buffer = \substr($buffer, \strlen($chunk));

                $len -= \strlen($chunk);

                yield $chunk;
            }
        } finally {
            if ($close) {
                $socket->close();
            }
        }
    }

    protected function readChunkEncodedBody(ReadableStream $socket, string & $buffer, bool $close = false): \Generator
    {
        try {
            while (true) {
                while (false === ($pos = \strpos($buffer, "\n"))) {
                    if (null === ($chunk = $socket->read())) {
                        throw new \RuntimeException('Unexpected end of HTTP body stream');
                    }

                    $buffer .= $chunk;
                }

                $line = \trim(\preg_replace("';.*$'", '', \substr($buffer, 0, $pos)));
                $buffer = \substr($buffer, $pos + 1);

                if (!\ctype_xdigit($line) || \strlen($line) > 7) {
                    throw new \RuntimeException(\sprintf('Invalid HTTP chunk length received: "%s"', $line));
                }

                $remainder = \hexdec($line);

                if ($remainder === 0) {
                    $buffer = \substr($buffer, 2);
                    break;
                }

                while ($remainder > 0) {
                    if ($buffer === '') {
                        if (null === ($buffer = $socket->read())) {
                            throw new \RuntimeException('Unexpected end of HTTP body stream');
                        }
                    }

                    $chunk = \substr($buffer, 0, $remainder);
                    $buffer = \substr($buffer, \strlen($chunk));

                    $remainder -= \strlen($chunk);

                    yield $chunk;
                }

                $buffer = \substr($buffer, 2);
            }
        } finally {
            if ($close) {
                $socket->close();
            }
        }
    }

    protected function decompressBody(StreamInterface $body, int $encoding): \Generator
    {
        try {
            $context = \inflate_init($encoding);

            if ($body->isSeekable()) {
                $body->rewind();
            }

            while (!$body->eof()) {
                yield \inflate_add($context, $body->read(4096), \ZLIB_SYNC_FLUSH);
            }

            yield \inflate_add($context, '', \ZLIB_FINISH);
        } finally {
            $body->close();
        }
    }
}
