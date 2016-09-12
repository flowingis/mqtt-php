<?php

namespace MQTTv311\Connection;

class Socket extends Connection
{
    protected $socket;

    /**
     * Socket constructor.
     * @param $socket
     */
    public function __construct($socket)
    {
        if (!$this->isSocket($socket)) {
            throw new \InvalidArgumentException();
        }

        $this->socket = $socket;
    }

    public function isSocket($socket)
    {
        if (!is_resource($socket) || get_resource_type($socket) !== "stream") {
            return false;
        }

        $meta = @stream_get_meta_data($socket);
        if (!strpos($meta['stream_type'], 'socket')) {
            return false;
        }

        return true;
    }

    public function send($data)
    {
        socket_write($this->socket, $data);
    }

    public function close()
    {
        socket_close($this->socket);
    }
}
