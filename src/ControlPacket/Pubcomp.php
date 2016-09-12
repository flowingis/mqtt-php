<?php

namespace MQTTv311\ControlPacket;

use MQTTv311\Assert\Assert;
use MQTTv311\ControlPacket\FixedHeader;
use MQTTv311\StreamBuffer;
use MQTTv311\ControlPacket\Packet;

class Pubcomp extends Packet
{
    public $messageIdentifier;

    public function __construct($buffer = null, $msgId = 0)
    {
        $this->fh = new FixedHeader(Packet::PUBCOMP);
        $this->fh->DUP = false;
        $this->fh->QoS = 0;
        $this->fh->Retain = false;
        $this->messageIdentifier = $msgId;
        parent::__construct($buffer);
    }

    public function pack()
    {
        $buffer = StreamBuffer::writeInt16($this->messageIdentifier);
        $buffer = $this->fh->pack(strlen($buffer)).$buffer;

        return $buffer;
    }

    public function unpack($buffer)
    {
        $this->fh->unpack($buffer, Packet::PUBCOMP);
        Assert::assert($this->fh->remainingLength == 2);
        $this->messageIdentifier = StreamBuffer::readInt16(substr($buffer, $this->fh->getLength()));
        Assert::assert($this->fh->DUP == false);
        Assert::assert($this->fh->QoS == 0);
        Assert::assert($this->fh->Retain == false);
    }

    public function __toString()
    {
        return $this->fh.', MsgId='.$this->messageIdentifier;
    }
}
