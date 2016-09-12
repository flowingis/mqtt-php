<?php

namespace MQTTv311\ControlPacket;

use MQTTv311\Assert\Assert;
use MQTTv311\Exception\MessageTooShortException;
use MQTTv311\StreamBuffer;
use MQTTv311\ControlPacket\Packet;

class FixedHeader
{
    public $MessageType;
    public $DUP = false;
    public $QoS = 0;
    public $Retain = false;
    public $remainingLength = 0;
    private $length = 0;

    public function __construct($aMessageType)
    {
        $this->MessageType = $aMessageType;
    }

    public function __toString()
    {
        //'return printable representation of our data';
        return $this->MessageType.' DUP='.$this->DUP.', QoS='.$this->QoS.', Retain='.$this->Retain;
    }

    /**
     * Pack data into string buffer ready for transmission down socket
     *
     * @param $length
     * @return string
     */
    public function pack($length)
    {
        $buffer = chr(($this->MessageType << 4) | ($this->DUP << 3) | ($this->QoS << 1) | $this->Retain);
        $this->remainingLength = $length;
        list($lengthEncoded, $this->length) = $this->lengthEncode($length);
        $this->length++;

        return $buffer.$lengthEncoded;
    }

    public function unpack($buffer, $messageType)
    {
        Assert::assert(strlen($buffer) >= 2);
        Assert::assert(Packet::packetType($buffer) == $messageType);
        $b0 = ord($buffer[0]);
        $this->MessageType = ($b0 >> 4);
        $this->DUP = ((($b0 >> 3) & 1) == 1);
        $this->QoS = (($b0 >> 1) & 3);
        $this->Retain = (($b0 & 1) == 1);
        list($this->remainingLength, $this->length) = $this->lengthDecode(substr($buffer, 1));

        $this->length++;

        Assert::assert(in_array($this->QoS, array(0, 1, 2)));
        $packlen = $this->getPacketLength();
        if (strlen($buffer) < $packlen) {
            throw new MessageTooShortException();
        }
    }

    /**
     * @return int
     */
    public function getLength()
    {
        return $this->length;
    }

    public function getPacketLength()
    {
        return $this->remainingLength + $this->length;
    }

    public function lengthEncode($x)
    {
        Assert::assert(0 <= $x && $x <= 268435455);
        $buffer = '';
        $bytes = 0;
        while (1) {
            $digit = ($x % 128);
            $x = floor($x / 128);
            if (($x > 0)) {
                $digit |= 128;
            }
            $buffer .= chr($digit);
            $bytes++;
            if ($x == 0) {
                break;
            }
        }

        return [$buffer, $bytes];
    }

    private function lengthDecode($buffer)
    {
        $tmpBuffer = $buffer;
        $multiplier = 1;
        $value = 0;
        $bytes = 0;
        while (1) {
            if (!isset($tmpBuffer[0])) {
                throw new MessageTooShortException();
            }
            $bytes++;
            $digit = ord($tmpBuffer[0]);
            $tmpBuffer = substr($tmpBuffer, 1);
            $value += ($digit & 127) * $multiplier;
            if (($digit & 128) == 0) {
                break;
            }
            $multiplier *= 128;
        }

        return [$value, $bytes];
    }
}
