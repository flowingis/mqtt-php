<?php

namespace MQTTv311\ControlPacket;

class SubscribeTest extends \PHPUnit_Framework_TestCase
{

    public function testPackUnpack()
    {
        $packet = new Subscribe(null, 1024, [["topic/device/1", 0]]);

        $packet2 = new Subscribe();
        $packet2->unpack($packet->pack());

        $this->assertEquals($packet, $packet2);
    }
}
