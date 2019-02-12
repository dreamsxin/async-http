<?php

/*
 * This file is part of Concurrent PHP HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Concurrent\Http\Http2;

use Concurrent\AsyncTestCase;
use Concurrent\Network\TcpSocket;
use Nyholm\Psr7\Factory\Psr17Factory;

class ConnectorTest extends AsyncTestCase
{
    public function test()
    {
        $factory = new Psr17Factory();

        $connector = new Http2Connector();
        $socket = TcpSocket::connect('127.0.0.1', 80);

        $request = $factory->createRequest('GET', 'http://localhost/composer.phar');

        $conn = $connector->upgrade($socket, $request->getUri()->getHost());
        $response = $conn->sendRequest($request, $factory);

        $this->assertEquals(200, $response->getStatusCode());
        
        $stream = $response->getBody();

        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            while (!$stream->eof()) {
                $stream->read(0xFFFF);
            }
        } finally {
            $stream->close();
        }
    }
}
