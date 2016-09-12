<?php

namespace MQTTv311\Repository;

use Doctrine\Common\Collections\ArrayCollection;
use MQTTv311\Client;

class ClientRepository
{
    /** @var ArrayCollection */
    private $clients;

    /**
     * ClientRepository constructor.
     */
    public function __construct()
    {
        $this->clients = new ArrayCollection();
    }

    public function getBySocket($socket)
    {
        if ($this->clients->containsKey($socket->resourceId)) {
            return $this->clients[$socket->resourceId];
        }

        return null;
    }

    public function add(Client $client)
    {
        $this->clients[$client->socket->resourceId] = $client;
    }

    public function has($client)
    {
        return $this->clients->containsKey($client->socket->resourceId);
    }

    public function getAll()
    {
        return $this->clients;
    }

    public function hasByClientId($clientId)
    {
        $filtered = $this->clients->filter(function($client) use ($clientId) {
            return $client->id == $clientId;
        });

        return !$filtered->isEmpty();
    }

    public function getByClientId($clientId)
    {
        $filtered = $this->clients->filter(function($client) use ($clientId) {
            return $client->id == $clientId;
        });

        return $filtered->first();
    }

    public function removeBySocket($socket)
    {
        $this->clients->remove($socket->resourceId);
    }

    public function removeByClientId($clientId)
    {
        $this->clients->removeElement($this->getByClientId($clientId));
    }

    public function hasSocket($socket)
    {
        return $this->clients->containsKey($socket->resourceId);
    }

    public function touch($sock)
    {
        if ($this->hasSocket($sock)) {
            $this->getBySocket($sock)->lastPacket = time();
        }
    }
}
