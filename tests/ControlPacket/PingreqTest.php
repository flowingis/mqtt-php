<?php

namespace MQTTv311\ControlPacket;

class PingreqTest extends \PHPUnit_Framework_TestCase
{

    public function testPackUnpack()
    {
        $packet = new Pingreq();

        $packet2 = new Pingreq();
        $packet2->unpack($packet->pack());

        $this->assertEquals($packet, $packet2);
    }
}
