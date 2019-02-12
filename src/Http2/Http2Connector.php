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

use Concurrent\Network\SocketStream;
use Psr\Http\Message\RequestInterface;

class Http2Connector
{
    public function getProtocols(): array
    {
        return [
            'h2'
        ];
    }

    public function isRequestSupported(RequestInterface $request): bool
    {
        if ($request->getUri()->getScheme() != 'https') {
            return false;
        }

        return (float) $request->getProtocolVersion() >= 2;
    }
    
    public function isSupported(string $protocol): bool
    {
        return $protocol == 'h2';
    }

    protected $localSettings = [
        Connection::SETTING_ENABLE_PUSH => 0,
        Connection::SETTING_MAX_CONCURRENT_STREAMS => 256,
        Connection::SETTING_INITIAL_WINDOW_SIZE => Connection::INITIAL_WINDOW_SIZE,
        Connection::SETTING_MAX_FRAME_SIZE => 16384,
        Connection::SETTING_HEADER_TABLE_SIZE => 0
    ];

    public function connect(SocketStream $socket): Connection
    {
        $settings = '';

        foreach ($this->localSettings as $k => $v) {
            $settings .= \pack('nN', $k, $v);
        }

        $header = Connection::PREFACE;
        $header .= (new Frame(Frame::SETTINGS, 0, $settings))->encode();
        $header .= (new Frame(Frame::WINDOW_UPDATE, 0, \pack('N', 0x0FFFFFFF)))->encode();

        $socket->write($header);

        return Connection::connect($socket, new HPack(new HPackClientContext()));
    }
    
    public function upgrade(SocketStream $socket, string $host): Connection
    {
        $settings = '';

        foreach ($this->localSettings as $k => $v) {
            $settings .= \pack('nN', $k, $v);
        }

        $frame = (new Frame(Frame::SETTINGS, 0, $settings))->encode();
        $frame = \rtrim(\strtr(\base64_encode($frame), [
            '+' => '-',
            '/' => '_'
        ]), '=');

        $data = \implode("\r\n", [
            'OPTIONS / HTTP/1.1',
            'Host: ' . $host,
            'Connection: Upgrade, HTTP2-Settings',
            'Upgrade: h2c',
            'HTTP2-Settings: ' . $frame
        ]) . "\r\n\r\n";

        $socket->write($data);

        $buffer = '';
        $i = null;

        do {
            $buffer .= $socket->read();
        } while (false === ($i = \strpos($buffer, "\r\n\r\n")));

        $header = \preg_split("'\s*\n\s*'", \trim(\substr($buffer, 0, $i)));
        $buffer = \substr($buffer, $i + 4);

        if (!preg_match("'^HTTP/1\\.1\s+101(?:$|\s)'i", $header[0])) {
            throw new \RuntimeException('HTTP/2 upgrade failed');
        }

        for ($count = \count($header), $i = 1; $i < $count; $i++) {
            list ($k, $v) = \array_map('trim', \explode(':', $header[$i], 2));

            switch (\strtolower($k)) {
                case 'upgrade':
                    if ($v != 'h2c') {
                        throw new \RuntimeException('HTTP/2 upgrade failed');
                    }
                    break;
            }
        }
        
        $header = Connection::PREFACE;
        $header .= (new Frame(Frame::SETTINGS, 0, $settings))->encode();
        $header .= (new Frame(Frame::WINDOW_UPDATE, 0, \pack('N', 0x0FFFFFFF)))->encode();
        
        $socket->write($header);

        return Connection::connect($socket, new HPack(new HPackClientContext()), $buffer);
    }
}
