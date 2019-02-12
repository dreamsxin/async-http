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

use Concurrent\Http\StreamAdapter;

class EntityStream extends StreamAdapter
{
    protected $stream;
    
    protected $channel;
    
    protected $it;
    
    public function __construct(Stream $stream, \Iterator $it)
    {
        $this->stream = $stream;
        $this->it = $it;
    }

    public function close()
    {
        if ($this->buffer !== null) {
            $this->buffer = null;
            $this->stream->close();
        }
    }

    protected function readNextChunk(): string
    {
        while ($this->it->valid()) {
            $chunk = $this->it->current();
            $this->it->next();

            if ($chunk !== '') {
                $this->stream->updateReceiveWindow(\strlen($chunk));

                return $chunk;
            }
        }

        return '';
    }
}
