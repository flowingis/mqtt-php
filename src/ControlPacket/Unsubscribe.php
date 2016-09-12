<?php

namespace MQTTv311\ControlPacket;

use MQTTv311\Assert\Assert;
use MQTTv311\ControlPacket\FixedHeader;
use MQTTv311\StreamBuffer;
use MQTTv311\ControlPacket\Packet;

class Unsubscribe extends Packet
{
    private $messageIdentifier;
    private $data;

    /**
     * Unsubscribe constructor.
     * @param null $buffer
     * @param int $msgId
     * @param array $data array of topics
     */
    public function __construct($buffer = null, $msgId = 0, $data = array())
    {
        $this->fh = new FixedHeader(Packet::UNSUBSCRIBE);
        $this->fh->DUP = false;
        $this->fh->QoS = 1;
        $this->fh->Retain = false;
        $this->messageIdentifier = $msgId;
        $this->data = $data;
        parent::__construct($buffer);
    }

    public function pack()
    {
        $buffer = StreamBuffer::writeInt16($this->messageIdentifier);
        foreach ($this->data as $d) {
            $buffer .= StreamBuffer::writeUTF($d);
        }
        $buffer = $this->fh->pack(strlen($buffer)).$buffer;

        return $buffer;
    }

    public function unpack($buffer)
    {
        $this->fh->unpack($buffer, Packet::UNSUBSCRIBE);
        $this->messageIdentifier = StreamBuffer::readInt16(substr($buffer, $this->fh->getLength()));
        Assert::assert(($this->messageIdentifier > 0));
        $leftlen = $this->fh->remainingLength - 2;
        $this->data = [];
        while ($leftlen > 0) {
            $topic = StreamBuffer::readUTF(substr($buffer, -$leftlen), $leftlen);
            $leftlen -= (strlen($topic) + 2);
            $this->data[] = $topic;
        }
        Assert::assert($leftlen == 0);
        Assert::assert($this->fh->DUP == false);
        Assert::assert($this->fh->QoS == 1);
        Assert::assert($this->fh->Retain == false);
    }

    public function __toString()
    {
        return $this->fh.', MsgId='.$this->messageIdentifier.', Data='.json_encode($this->data);
    }

    public function getTopics()
    {
        return $this->data;
    }

    public function getMessageId()
    {
        return $this->messageIdentifier;
    }
}
