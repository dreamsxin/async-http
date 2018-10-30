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

use Concurrent\Context;
use Concurrent\Deferred;
use Concurrent\Task;
use Concurrent\Timer;
use function Concurrent\gethostbyname;
use Concurrent\Network\ClientEncryption;
use Concurrent\Network\TcpSocket;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ConnectionManager
{
    protected $counts = [];

    protected $conns = [];

    protected $connecting = [];

    protected $expires;

    protected $interval;

    protected $lifetime;

    protected $timer;

    protected $logger;

    /**
     * Create a new connection manager with limited concurrency and socket lifetime.
     * 
     * @param int $lifetime Maximum idle time in seconds.
     * @param int $interval Garbage collection interval in seconds.
     * @param LoggerInterface $logger
     */
    public function __construct(int $lifetime = 60, int $interval = 5, ?LoggerInterface $logger = null)
    {
        if ($lifetime < 5) {
            throw new \InvalidArgumentException(\sprintf('Connection lifetime must not be less than 5 seconds'));
        }

        if ($interval < 5) {
            throw new \InvalidArgumentException(\sprintf('Expiry check interval must not be less than 5 seconds'));
        }

        $this->lifetime = $lifetime;
        $this->interval = $interval * 1000;
        $this->logger = $logger ?? new NullLogger();

        $this->expires = new \SplPriorityQueue();
        $this->expires->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    public function __destruct()
    {
        $this->close();

        $count = 0;

        foreach ($this->conns as $conns) {
            foreach ($conns as $conn) {
                $conn->socket->close();

                $count++;
            }
        }

        $e = new \RuntimeException('Connection manager has been disposed');

        foreach ($this->connecting as $attempts) {
            foreach ($attempts as $defer) {
                $defer->fail($e);
            }
        }

        $this->logger->debug('Disposed of {num} connections', [
            'num' => $count
        ]);
    }

    public function close(?\Throwable $e = null): void
    {
        if ($this->timer !== null) {
            $this->timer->close($e);
            $this->timer = null;
        }
    }

    public function checkout(string $host, int $port, bool $encrypted = false): Connection
    {
        if ($this->timer === null) {
            $this->timer = new Timer($this->interval);

            Task::asyncWithContext(Context::current()->background(), \Closure::fromCallable([
                $this,
                'gc'
            ]));
        }

        $key = \sprintf('%s|%u|%s', $ip = gethostbyname($host), $port, $encrypted ? $host : '');

        do {
            if (!empty($this->conns[$key])) {
                $this->logger->debug('Reuse connection tcp://{ip}:{port}', [
                    'ip' => $ip,
                    'port' => $port
                ]);

                $conn = \array_shift($this->conns[$key]);
                $conn->expires = 0;

                break;
            }

            if (($this->counts[$key] ?? 0) < 8) {
                $this->logger->debug('Connect to tcp://{ip}:{port}', [
                    'ip' => $ip,
                    'port' => $port
                ]);

                $conn = $this->connect($key);

                break;
            }

            $this->logger->debug('Await connection tcp://{ip}:{port}', [
                'ip' => $ip,
                'port' => $port
            ]);

            $this->connecting[$key][] = $defer = new Deferred();

            $conn = Task::await($defer->awaitable());
        } while ($conn === null);

        $conn->requests++;

        return $conn;
    }

    public function checkin(Connection $conn): void
    {
        if ($conn->maxRequests > 0 && $conn->requests >= $conn->maxRequests) {
            $this->release($conn);

            return;
        }

        list ($ip, $port) = \explode('|', $conn->key);

        $this->logger->debug('Checkin connection tcp://{ip}:{port}', [
            'ip' => $ip,
            'port' => (int) $port
        ]);

        if (empty($this->connecting[$conn->key])) {
            $this->conns[$conn->key][] = $conn;

            $conn->expires = \time() + $this->lifetime;
            $this->expires->insert($conn, -$conn->expires);
        } else {
            $defer = \array_shift($this->connecting[$conn->key]);

            if (empty($this->connecting[$conn->key])) {
                unset($this->connecting[$conn->key]);
            }

            $defer->resolve($conn);
        }
    }

    public function release(Connection $conn, ?\Throwable $e = null): void
    {
        list ($ip, $port) = \explode('|', $conn->key);

        $this->logger->debug('Release connection tcp://{ip}:{port}', [
            'ip' => $ip,
            'port' => (int) $port
        ]);

        $conn->expires = 0;

        $this->counts[$conn->key]--;

        if (empty($this->counts[$conn->key])) {
            unset($this->counts[$conn->key]);
        }

        if (false !== ($key = \array_search($conn, $this->conns[$conn->key], true))) {
            unset($this->conns[$conn->key][$key]);

            if (empty($this->conns[$conn->key])) {
                unset($this->conns[$conn->key]);
            }
        }

        if (!empty($this->connecting[$conn->key])) {
            $defer = \array_shift($this->connecting[$conn->key]);

            if (empty($this->connecting[$conn->key])) {
                unset($this->connecting[$conn->key]);
            }

            $defer->resolve();
        }

        $conn->socket->close($e);
    }

    protected function connect(string $key): Connection
    {
        if (isset($this->counts[$key])) {
            $this->counts[$key]++;
        } else {
            $this->counts[$key] = 1;
        }

        try {
            list ($host, $port, $encrypt) = \explode('|', $key);

            if ($encrypt !== '') {
                $tls = new ClientEncryption();
                $tls = $tls->withPeerName($encrypt);
            } else {
                $tls = null;
            }

            $socket = TcpSocket::connect($host, (int) $port, $tls);

            try {
                if ($encrypt) {
                    $socket->encrypt();
                }

                return new Connection($key, $socket);
            } catch (\Throwable $e) {
                $socket->close();

                throw $e;
            }
        } catch (\Throwable $e) {
            $this->counts[$key]--;

            if (empty($this->counts[$key])) {
                unset($this->counts[$key]);
            }

            throw $e;
        }
    }

    protected function gc()
    {
        while (true) {
            $this->timer->awaitTimeout();

            $time = \time();
            $purged = 0;

            while (!$this->expires->isEmpty()) {
                $entry = $this->expires->top();

                if ($entry['priority'] != -$entry['data']->expires) {
                    $this->expires->extract();

                    continue;
                }

                if ($entry['data']->expires < $time) {
                    $this->expires->extract();

                    $this->release($entry['data']);

                    $purged++;

                    continue;
                }

                break;
            }

            if ($purged) {
                $this->logger->debug('Disposed of {num} expired connections', [
                    'num' => $purged
                ]);
            }
        }
    }
}
