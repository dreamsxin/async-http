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

class Connection
{
    public $id;

    public $key;

    public $socket;

    public $requests = 0;

    public $maxRequests = 0;
    
    public $time;

    public function __construct(string $key, TcpSocket $socket)
    {
        static $counter = 'a';

        $this->id = $counter++;
        $this->key = $key;
        $this->socket = $socket;
        $this->time = \time();
    }
}
