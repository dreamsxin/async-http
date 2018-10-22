<?php

/*
 * This file is part of Async PSR HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Concurrent\Http;

use Concurrent\AsyncTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;

class HttpClientTest extends AsyncTestCase
{
    protected $logger;

    protected $manager;

    protected $factory;

    protected $client;

    protected function setUp()
    {
        parent::setUp();

        $this->manager = new ConnectionManager($this->logger);
        $this->factory = new Psr17Factory();

        $this->client = new HttpClient($this->manager, $this->factory, $this->logger);
    }
    
    protected function tearDown()
    {
        $this->manager = null;
        $this->client = null;
        
        parent::tearDown();
    }
    
    public function testStatusCode()
    {
        $request = $this->factory->createRequest('GET', 'https://httpbin.org/status/201');
        $response = $this->client->sendRequest($request);
        
        $this->assertEquals(201, $response->getStatusCode());
        
        $request = $this->factory->createRequest('GET', 'https://httpbin.org/status/204');
        $response = $this->client->sendRequest($request);
        
        $this->assertEquals(204, $response->getStatusCode());
    }
    
    public function testResponseBody()
    {
        $request = $this->factory->createRequest('GET', 'https://httpbin.org/headers');
        $response = $this->client->sendRequest($request);

        $this->assertEquals(200, $response->getStatusCode());

        $body = \json_decode($response->getBody()->getContents(), true);

        $this->assertEquals('httpbin.org', $body['headers']['Host']);
    }
}
