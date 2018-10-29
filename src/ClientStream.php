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

class ClientStream implements ReadableStream
{
    protected $conn;
    
    protected $manager;
    
    public function __construct(ConnectionManager $manager, Connection $conn)
    {
        $this->manager = $manager;
        $this->conn = $conn;
    }

    public function __destruct()
    {
        if ($this->conn !== null) {
            $this->manager->release($this->conn);
        }
    }
    
    public function markDisposed(): void
    {
        if ($this->conn !== null) {
            $this->conn->maxRequests = 1;
        }
    }
    
    public function release(): void
    {
        if ($this->conn !== null) {
            $this->conn = $this->manager->checkin($this->conn);
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function close(?\Throwable $e = null): void
    {
        $this->conn = $this->manager->release($this->conn, $e);
    }

    /**
     * {@inheritdoc}
     */
    public function read(?int $length = null): ?string
    {
        if ($this->conn === null) {
            return null;
        }

        if ($this->conn->buffer === '') {
            $chunk = $this->conn->socket->read($length ?? 8192);

            if ($chunk === null) {
                $this->conn = $this->manager->checkin($this->conn);

                return null;
            }

            $this->conn->buffer = $chunk;
        }

        $chunk = \substr($this->conn->buffer, 0, $length ?? 8192);
        $this->conn->buffer = \substr($this->conn->buffer, \strlen($chunk));

        return $chunk;
    }
}
