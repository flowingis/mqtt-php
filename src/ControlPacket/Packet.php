<?php

namespace MQTTv311\ControlPacket;

use MQTTv311\ControlPacket\FixedHeader;
use MQTTv311\Exception\MQTTException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

abstract class Packet
{
    const packetNames = [
        1 => "Connect",
        "Connack",
        "Publish",
        "Puback",
        "Pubrec",
        "Pubrel",
        "Pubcomp",
        "Subscribe",
        "Suback",
        "Unsubscribe",
        "Unsuback",
        "Pingreq",
        "Pingresp",
        "Disconnect"
    ];
    const CONNACK = 2;
    const PUBREC = 5;
    const SUBACK = 9;
    const UNSUBSCRIBE = 10;
    const CONNECT = 1;
    const PUBLISH = 3;
    const PUBREL = 6;
    const PUBCOMP = 7;
    const SUBSCRIBE = 8;
    const PINGRESP = 13;
    const UNSUBACK = 11;
    const DISCONNECT = 14;
    const PUBACK = 4;
    const PINGREQ = 12;

    use LoggerAwareTrait;

    /** @var FixedHeader */
    public $fh;

    /**
     * Packets constructor.
     * @param $buffer
     */
    public function __construct($buffer)
    {
        $this->setLogger(new NullLogger());
        if ($buffer != null) {
            $this->unpack($buffer);
        }
    }

    abstract function unpack($buffer);

    public static function packetType($byte)
    {
        if ($byte == null) {
            return null;
        }

        return ord($byte[0]) >> 4;
    }

    public static function packetTypeName($packetType)
    {
        $packetNames = self::packetNames;

        if (!key_exists($packetType, $packetNames)) {
            throw new MQTTException("Invalid message type: ".$packetType);
        }

        return $packetNames[$packetType];
    }

    public static function fromType($messageType)
    {
        $classNames = self::packetNames;

        if (!key_exists($messageType, $classNames)) {
            throw new MQTTException("Invalid message type: ".$messageType);
        }
        $className = '\\MQTTv311\\ControlPacket\\'.$classNames[$messageType];

        return new $className;
    }

    public function pack()
    {
        $buffer = $this->fh->pack(0);

        return $buffer;
    }

    public function __toString()
    {
        return (string)$this->fh;
    }

    public function getLength()
    {
        return $this->fh->getPacketLength();
    }

}
