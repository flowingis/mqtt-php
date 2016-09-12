<?php

namespace MQTTv311\Connection;

abstract class Connection
{
    public $resourceId;

    abstract public function send($data);
    abstract public function close();
}
