<?php

namespace MQTTv311\ControlPacket;

use MQTTv311\Assert\Assert;
use MQTTv311\ControlPacket\FixedHeader;
use MQTTv311\StreamBuffer;
use MQTTv311\ControlPacket\Packet;

class Pingresp extends Packet
{
    public function __construct($buffer = null)
    {
        $this->fh = new FixedHeader(Packet::PINGRESP);
        $this->fh->DUP = false;
        $this->fh->QoS = 0;
        $this->fh->Retain = false;
        parent::__construct($buffer);
    }

    public function unpack($buffer)
    {
        $this->fh->unpack($buffer, Packet::PINGRESP);
        Assert::assert($this->fh->remainingLength == 0);
        Assert::assert($this->fh->DUP == false);
        Assert::assert($this->fh->QoS == 0);
        Assert::assert($this->fh->Retain == false);
    }

    public function __toString()
    {
        return (string)$this->fh;
    }
}
