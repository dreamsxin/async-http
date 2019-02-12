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
use Concurrent\Http\StreamAdapter;

class EntityStream extends StreamAdapter
{
    protected $id;
    
    protected $conn;
    
    protected $channel;
    
    protected $it;
    
    public function __construct(int $id, Connection $conn, Channel $channel)
    {
        $this->id = $id;
        $this->conn = $conn;
        $this->channel = $channel;
        
        $this->it = $channel->getIterator();
    }
    
    public function close()
    {
        $this->buffer = null;
        $this->channel->close();
    }
    
    protected function readNextChunk(): string
    {
        while ($this->it->valid()) {
            $chunk = $this->it->current();
            $this->it->next();

            if ($chunk !== '') {
                $this->conn->updateReceiveWindow(strlen($chunk), $this->id);

                return $chunk;
            }
        }

        return '';
    }
}
