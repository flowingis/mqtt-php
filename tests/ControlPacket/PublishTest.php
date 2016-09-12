<?php

namespace MQTTv311\ControlPacket;

class PublishTest extends \PHPUnit_Framework_TestCase
{

    public function testPackUnpack()
    {
        $packet = new Publish(null, true, 1, true, 1024, "topic/device/1", "Payload 'ò§ Payload");

        $packet2 = new Publish();
        $packet2->unpack($packet->pack());

        $this->assertEquals($packet, $packet2);
    }
}
