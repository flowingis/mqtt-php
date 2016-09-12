<?php

namespace MQTTv311\ControlPacket;

use MQTTv311\Assert\Assert;
use MQTTv311\ControlPacket\FixedHeader;
use MQTTv311\StreamBuffer;
use MQTTv311\ControlPacket\Packet;

class Publish extends Packet
{
    public $topicName;
    public $messageIdentifier;
    public $data;
    public $qos2state;

    public function __construct(
        $buffer = null,
        $DUP = false,
        $QoS = 0,
        $retain = false,
        $msgId = 0,
        $TopicName = '',
        $Payload = ''
    ) {
        $this->fh = new FixedHeader(Packet::PUBLISH);
        $this->fh->DUP = $DUP;
        $this->fh->QoS = $QoS;
        $this->fh->Retain = $retain;
        $this->topicName = $TopicName;
        $this->messageIdentifier = $msgId;
        $this->data = $Payload;
        parent::__construct($buffer);
    }

    public function pack()
    {
        $buffer = StreamBuffer::writeUTF($this->topicName);
        if (($this->fh->QoS != 0)) {
            $buffer .= StreamBuffer::writeInt16($this->messageIdentifier);
        }
        $buffer .= $this->data;
        $buffer = $this->fh->pack(strlen($buffer)).$buffer;

        return $buffer;
    }

    public function unpack($buffer)
    {
        $this->fh->unpack($buffer, Packet::PUBLISH);
        $curlen = $this->fh->getLength();

        try {
            $this->topicName = StreamBuffer::readUTF(substr($buffer, $this->fh->getLength()), $this->fh->remainingLength);
        } catch (\Exception $e) {

            $this->logger->info('[MQTT-3.3.2-1] topic name in publish must be utf-8');
            throw new \Exception();
        }
        $curlen += strlen($this->topicName) + 2;
        if ($this->fh->QoS != 0) {
            $this->messageIdentifier = StreamBuffer::readInt16(substr($buffer, $curlen));
            $this->logger->info('[MQTT-2.3.1-1] packet indentifier must be in publish if QoS is 1 or 2');
            $curlen += 2;
            Assert::assert($this->messageIdentifier > 0);
        } else {
            $this->logger->info('[MQTT-2.3.1-5] no packet indentifier in publish if QoS is 0');
            $this->messageIdentifier = 0;
        }
        $this->data = substr($buffer, $curlen, $this->fh->getPacketLength() - $curlen);
        if (($this->fh->QoS == 0)) {
            Assert::assert(($this->fh->DUP == false));
        }

        return $this->fh->getPacketLength();
    }

    public function __toString()
    {
        $rc = (string)$this->fh;
        if (($this->fh->QoS != 0)) {
            $rc .= ', MsgId='.$this->messageIdentifier;
        }
        $rc .= ', TopicName='.$this->topicName.', Payload='.$this->data;

        return $rc;
    }
}
