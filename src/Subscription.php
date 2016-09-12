<?php

namespace MQTTv311;

use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

class Subscription
{
    use LoggerAwareTrait;

    private $clientId;
    private $topic;
    private $qos;

    public function __construct($clientId, $aTopic, $aQos)
    {
        $this->clientId = $clientId;
        $this->topic = $aTopic;
        $this->qos = $aQos;

        $this->setLogger(new NullLogger());
    }

    public function getClientId()
    {
        return $this->clientId;
    }

    public function getTopic()
    {
        return $this->topic;
    }

    public function getQoS()
    {
        return $this->qos;
    }

    public function resubscribe($qos)
    {
        $this->qos = $qos;
    }

    public function __toString()
    {
        return json_encode(['clientid' => $this->clientId, 'topic' => $this->topic, 'qos' => $this->qos]);
    }
}

