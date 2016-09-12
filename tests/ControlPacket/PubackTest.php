<?php

namespace MQTTv311\ControlPacket;

class PubackTest extends \PHPUnit_Framework_TestCase
{

    public function testPackUnpack()
    {
        $packet = new Puback();

        $packet2 = new Puback();
        $packet2->unpack($packet->pack());

        $this->assertEquals($packet, $packet2);
    }
}
