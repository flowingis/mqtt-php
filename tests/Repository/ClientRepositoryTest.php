<?php

namespace MQTTv311\Repository;

use MQTTv311\Client;

class ClientRepositoryTest extends \PHPUnit_Framework_TestCase
{
    public function testGet()
    {
        $sock = $this->prophesize('MQTTv311\Connection\Connection');
        $sock->resourceId = 123;

        $sock2 = $this->prophesize('MQTTv311\Connection\Connection');
        $sock2->resourceId = 23;

        $sock = $sock->reveal();
        $sock2 = $sock2->reveal();

        $client1 = new Client(1, $sock);
        $client2 = new Client(2, $sock2);

        $repo = new ClientRepository();
        $this->assertEquals(null, $repo->getBySocket($sock));

        $repo->add($client1);
        $repo->add($client2);
        $this->assertEquals($client1, $repo->getBySocket($sock));
        $this->assertEquals($client1, $repo->getByClientId($client1->id));
    }

    public function testAddAndRemoveClient()
    {
        $sock = $this->prophesize('MQTTv311\Connection\Connection');
        $sock->resourceId = 123;

        $sock2 = $this->prophesize('MQTTv311\Connection\Connection');
        $sock2->resourceId = 23;

        $sock = $sock->reveal();
        $sock2 = $sock2->reveal();

        $client1 = new Client(1, $sock);
        $client2 = new Client(2, $sock2);

        $repo = new ClientRepository();
        $this->assertFalse($repo->has($client1));
        $this->assertFalse($repo->hasByClientId($client1->id));
        $this->assertFalse($repo->hasSocket($sock));
        $this->assertFalse($repo->hasByClientId($client2->id));
        $this->assertFalse($repo->hasSocket($sock2));

        $repo->add($client1);
        $this->assertTrue($repo->has($client1));
        $this->assertTrue($repo->hasByClientId($client1->id));
        $this->assertTrue($repo->hasSocket($sock));
        $this->assertFalse($repo->has($client2));
        $this->assertFalse($repo->hasByClientId($client2->id));
        $this->assertFalse($repo->hasSocket($sock2));

        $repo->removeBySocket($sock);
        $this->assertFalse($repo->has($client1));
        $this->assertFalse($repo->hasByClientId($client1->id));
        $this->assertFalse($repo->hasSocket($sock));
        $this->assertFalse($repo->has($client2));
        $this->assertFalse($repo->hasByClientId($client2->id));
        $this->assertFalse($repo->hasSocket($sock2));

        $repo->add($client1);
        $repo->add($client2);
        $this->assertTrue($repo->has($client1));
        $this->assertTrue($repo->hasByClientId($client1->id));
        $this->assertTrue($repo->hasSocket($sock));
        $this->assertTrue($repo->has($client2));
        $this->assertTrue($repo->hasByClientId($client2->id));
        $this->assertTrue($repo->hasSocket($sock2));
    }
}
