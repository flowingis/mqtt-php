<?php

namespace MQTTv311\ControlPacket;

class DisonnectTest extends \PHPUnit_Framework_TestCase
{

    public function testPackUnpack()
    {
        $packet = new Disconnect();

        $packet2 = new Disconnect();
        $packet2->unpack($packet->pack());

        $this->assertEquals($packet, $packet2);
    }
}
