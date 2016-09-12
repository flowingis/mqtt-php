<?php

namespace MQTTv311\ControlPacket;

use MQTTv311\Assert\Assert;
use MQTTv311\ControlPacket\FixedHeader;
use MQTTv311\StreamBuffer;
use MQTTv311\ControlPacket\Packet;

class Connack extends Packet
{
    public $flags;
    public $returnCode;

    const RETURN_CODE_ACCEPTED = 0x00;
    const RETURN_CODE_REFUSED_PROTOCOL_VERSION = 0x01;
    const RETURN_CODE_REFUSED_ID_REJECTED = 0x02;
    const RETURN_CODE_REFUSED_SERVER_UNAVAILABLE = 0x03;
    const RETURN_CODE_REFUSED_BAD_USERNAME_PASSWORD = 0x04;
    const RETURN_CODE_REFUSED_NOT_AUTHORIZED = 0x05;

    function __construct($buffer = null, $returnCode = 0)
    {
        $this->fh = new FixedHeader(Packet::CONNACK);
        $this->fh->DUP = false;
        $this->fh->QoS = 0;
        $this->fh->Retain = false;
        $this->flags = 0;
        $this->returnCode = $returnCode;
        parent::__construct($buffer);
    }

    function pack()
    {
        $buffer = chr($this->flags).chr($this->returnCode);
        $buffer = $this->fh->pack(strlen($buffer)).$buffer;

        return $buffer;
    }

    function unpack($buffer)
    {
        Assert::assert(strlen($buffer) >= 4);
        $this->fh->unpack($buffer, Packet::CONNACK);
        Assert::assert($this->fh->remainingLength == 2);
        Assert::assert($buffer[2] == 0 || $buffer[2] == 1);
        $this->returnCode = $buffer[3];
        Assert::assert($this->fh->DUP == false);
        Assert::assert($this->fh->QoS == 0);
        Assert::assert($this->fh->Retain == false);
    }

    function __toString()
    {
        return (string)$this->fh.
        ', Session present='.((($this->flags & 1) == 1) ? 'true' : 'false').
        ', ReturnCode='.$this->returnCode.')';
    }
}
