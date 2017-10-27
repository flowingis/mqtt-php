<?php

namespace MQTTv311\Connection;

use Ratchet\RFC6455\Messaging\Frame;

class WebSocket extends Socket
{
    public function send($data)
    {
        parent::send(new Frame($data, true, Frame::OP_BINARY));
    }
}
