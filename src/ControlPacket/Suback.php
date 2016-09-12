<?php

namespace MQTTv311\ControlPacket;

use MQTTv311\Assert\Assert;
use MQTTv311\ControlPacket\FixedHeader;
use MQTTv311\StreamBuffer;
use MQTTv311\ControlPacket\Packet;

class Suback extends Packet
{
    const SUBACK_FAIL = 0x80;

    public $messageIdentifier;
    public $data;

    function __construct($buffer = null, $msgId = 0, $data = [])
    {
        $this->fh = new FixedHeader(Packet::SUBACK);
        $this->fh->DUP = false;
        $this->fh->QoS = 0;
        $this->fh->Retain = false;
        $this->messageIdentifier = $msgId;
        $this->data = $data;
        parent::__construct($buffer);
    }

    public function pack()
    {
        $buffer = StreamBuffer::writeInt16($this->messageIdentifier);
        foreach ($this->data as $d) {
            $buffer .= chr($d);
        }
        $buffer = $this->fh->pack(strlen($buffer)).$buffer;

        return $buffer;
    }

    public function unpack($buffer)
    {
        $this->fh->unpack($buffer, Packet::SUBACK);
        $this->messageIdentifier = StreamBuffer::readInt16(substr($buffer, $this->fh->getLength()));
        $leftlen = ($this->fh->remainingLength - 2);
        $this->data = [];
        while ($leftlen > 0) {
            $qos = ord($buffer[strlen($buffer) - $leftlen]);
            Assert::assert(in_array($qos, [0, 1, 2, 128]));
            $leftlen -= 1;
            $this->data[] = $qos;
        }
        Assert::assert($leftlen == 0);
        Assert::assert($this->fh->DUP == false);
        Assert::assert($this->fh->QoS == 0);
        Assert::assert($this->fh->Retain == false);
    }

    public function __toString()
    {
        return $this->fh.', MsgId='.$this->messageIdentifier.', Data='.$this->data;
    }
}
