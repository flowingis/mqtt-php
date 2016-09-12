<?php

namespace MQTTv311\ControlPacket;

class ConnectTest extends \PHPUnit_Framework_TestCase
{

    public function testPackUnpack()
    {
        $packet = new Connect();
        $packet->setUsername('usér');
        $packet->setPassword('pwd');

        $packet2 = new Connect();
        $packet2->unpack($packet->pack());

        $this->assertEquals($packet, $packet2);
    }
}
