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

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class CompressingClient implements ClientInterface
{
    protected $client;
    
    protected $enabled;

    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
        $this->enabled = \function_exists('inflate_init');
    }

    /**
     * {@inheritdoc}
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->enabled) {
            $request = $request->withAddedHeader('Accept-Encoding', 'gzip, deflate');
        }

        $response = $this->client->sendRequest($request);

        while ($this->enabled && '' !== ($encoding = \strtolower($response->getHeaderLine('Content-Encoding')))) {
            switch ($encoding) {
                case 'gzip':
                    $response = $response->withBody(new InflateStream($response->getBody(), \ZLIB_ENCODING_GZIP));
                    break;
                case 'deflate':
                    $response = $response->withBody(new InflateStream($response->getBody(), \ZLIB_ENCODING_DEFLATE));
                    break;
                default:
                    break 2;
            }

            $response = $response->withoutHeader('Content-Encoding');
            $response = $response->withoutHeader('Content-Length');

            break;
        }

        return $response;
    }
}
