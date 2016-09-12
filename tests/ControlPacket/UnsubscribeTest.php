<?php

namespace MQTTv311\ControlPacket;

class UnsubscribeTest extends \PHPUnit_Framework_TestCase
{

    public function testPackUnpack()
    {
        $packet = new Unsubscribe(null, 1024, ["topic/device/1", "topic2"]);

        $packet2 = new Unsubscribe();
        $packet2->unpack($packet->pack());

        $this->assertEquals($packet, $packet2);
    }
}
