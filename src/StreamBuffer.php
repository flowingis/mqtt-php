<?php

namespace MQTTv311;

use MQTTv311\ControlPacket\Packet;
use MQTTv311\Exception\MQTTException;

class StreamBuffer
{
    private $buffer = [];
    private $current;

    public function getContent($connectionId)
    {
        return $this->buffer[$connectionId];
    }

    static public function writeInt16($length)
    {
        return pack('n', $length);
    }

    static public function readInt16($buffer)
    {
        if (strlen($buffer) < 2) {
            throw new \Exception("Packet malformed or incomplete");
        }

        return (ord($buffer[0]) * 256) + ord($buffer[1]);
    }

    static public function writeUTF($data)
    {
        return pack('n', strlen($data)).$data;
    }

    static public function readUTF($buffer, $maxlen)
    {
        if ($maxlen >= 2) {
            $length = self::readInt16($buffer);
        } else {
            throw new MQTTException('Not enough data to read string length');
        }
        $maxlen -= 2;
        if ($length > $maxlen) {
            throw new MQTTException('Length delimited string too long');
        }
        $buf = substr($buffer, 2, $length);

        if (mb_strpos($buf, "\x00") !== false) {
            throw new MQTTException('[MQTT-1.5.3-2] Null found in UTF data '.$buf);
        }

        $c1 = \IntlChar::chr(55296);
        $c2 = \IntlChar::chr(57343);
        for ($i = 0; $i < \mb_strlen($buf); $i++) {
            $c = \mb_substr($buf, $i, 1);
            if (($c >= $c1) && ($c <= $c2)) {
                throw new MQTTException(('[MQTT-1.5.3-1] D800-DFFF found in UTF data '.$buf));
            }
        }

        if (mb_strpos($buf, "\u{FEFF}") !== false) {
            throw new MQTTException('[MQTT-1.5.3-3] U+FEFF in UTF string');
        }

        return $buf;
    }

    static public function writeBytes($buffer)
    {
        return self::writeInt16(mb_strlen($buffer)).$buffer;
    }

    static public function readBytes($buffer)
    {
        $length = self::readInt16($buffer);

        return substr($buffer, 2, $length);
    }

    public function append($buffer, $resourceId)
    {
        if (!key_exists($resourceId, $this->buffer)) {
            $this->buffer[$resourceId] = '';
        }
        $this->buffer[$resourceId] .= $buffer;
    }

    public function getNextPacket($resourceId)
    {
        return $this->current = $this->unpackPacket($resourceId);
    }

    public function unpackPacket($connectionId)
    {
        $packetType = Packet::packetType($this->buffer[$connectionId]);
        if ($packetType === null) {
            return null;
        }

        $packet = Packet::fromType($packetType);
        $packet->unpack($this->buffer[$connectionId]);
        $this->buffer[$connectionId] = substr($this->buffer[$connectionId], $packet->getLength());

        return $packet;
    }

    public function hasPendingPacket($resourceId)
    {
        return !empty($this->buffer[$resourceId]);
    }

    static function hexDump($data, $newline = "\n")
    {
        if (empty($data)) {
            echo "Empty data".$newline;
        }
        $from = '';
        $to = '';
        $width = 16;
        $pad = '.';

        if ($from === '') {
            for ($i = 0; $i <= 0xFF; $i++) {
                $from .= chr($i);
                $to .= ($i >= 0x20 && $i <= 0x7E) ? chr($i) : $pad;
            }
        }

        $hex = str_split(bin2hex($data), $width * 2);
        $chars = str_split(strtr($data, $from, $to), $width);

        $offset = 0;
        foreach ($hex as $i => $line) {
            echo sprintf('%6X', $offset).' : '.implode(' ', str_split($line, 2)).' ['.$chars[$i].']'.$newline;
            $offset += $width;
        }
    }
}
