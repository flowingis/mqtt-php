<?php

namespace MQTTv311\ControlPacket;

class PingrespTest extends \PHPUnit_Framework_TestCase
{

    public function testPackUnpack()
    {
        $packet = new Pingresp();

        $packet2 = new Pingresp();
        $packet2->unpack($packet->pack());

        $this->assertEquals($packet, $packet2);
    }
}
