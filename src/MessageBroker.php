<?php

namespace MQTTv311;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class MessageBroker
{
    private $se;
    private $clients;
    public $overlappingSingle;
    private $logger;

    public function __construct($overlappingSingle = true, LoggerInterface $logger = null)
    {
        $this->se = new SubscriptionEngine();
        $this->clients = [];
        $this->overlappingSingle = $overlappingSingle;
        $this->logger = $logger ?: new NullLogger();
    }

    public function reinitialize()
    {
        $this->clients = [];
        $this->se->reinitialize();
    }

    public function getClient($clientId)
    {
        return empty($this->clients[$clientId]) ? null : $this->clients[$clientId];
    }

    public function cleanSession($clientId)
    {
        if (count($this->se->getRetainedTopics("#")) > 0) {
            $this->logger->info(
                "[MQTT-3.1.2-7] retained messages not cleaned up as part of session state for client %s",
                [$clientId]
            );
        }
        $this->se->clearSubscriptions($clientId);
    }

    public function connect($client)
    {
        $client->connected = true;
        $client->timestamp = time();
        $this->clients[$client->id] = $client;
        if ($client->cleansession) {
            $this->cleanSession($client->id);
        }
    }

    /**
     * Abrupt disconnect which also causes a will msg to be sent out
     *
     * @param $clientId
     */
    public function terminate($clientId)
    {
        if (empty($this->clients[$clientId]) || !$this->clients[$clientId]->connected) {
            return;
        }

        $this->logger->info("TERMINATE OK %s", [$clientId]);
        if ($this->clients[$clientId]->will != null) {
            $this->logger->info("TERMINATE HAS WILL %s", [$clientId]);
            $this->logger->info("[MQTT-3.1.2-8] sending will message for client %s", [$clientId]);

            list($willtopic, $willQoS, $willmsg, $willRetain) = $this->clients[$clientId]->will;
            if ($willRetain) {
                $this->logger->info("[MQTT-3.1.2-17] sending will message retained for client %s", [$clientId]);
            } else {
                $this->logger->info("[MQTT-3.1.2-16] sending will message non-retained for client %s", [$clientId]);
            }
            $this->publish($clientId, $willtopic, $willmsg, $willQoS, $willRetain);
        }
        $this->disconnect($clientId);
    }

    public function disconnect($clientId)
    {
        if (empty($this->clients[$clientId])) {
            return;
        }

        $this->clients[$clientId]->connected = false;
        if ($this->clients[$clientId]->cleansession) {
            $this->logger->info("[MQTT-3.1.2-6] broker must discard the session data for client %s", [$clientId]);
            $this->cleanSession($clientId);
            unset($this->clients[$clientId]);
        } else {
            $this->logger->info("[MQTT-3.1.2-4] broker must store the session data for client %s", [$clientId]);
            $this->clients[$clientId]->timestamp = time();
            $this->clients[$clientId]->connected = false;
            $this->logger->info(
                "[MQTT-3.1.2-10] will message is deleted after use or disconnect, for client %s", [$clientId]
            );
            $this->logger->info("[MQTT-3.14.4-3] on receipt of disconnect, will message is deleted");
            $this->clients[$clientId]->will = null;
        }
    }

    /**
     * publish to all subscribed connected clients also to any disconnected non-cleansession clients with qos in [1,2]
     *
     * @param $clientId
     * @param $topic
     * @param $message
     * @param $qos
     * @param bool $retained
     */
    public function publish($clientId, $topic, $message, $qos, $retained = false)
    {
        if ($retained) {
            $this->logger->info("[MQTT-2.1.2-6] store retained message and QoS");
            $this->se->setRetained($topic, $message, $qos);
        } else {
            $this->logger->info("[MQTT-2.1.2-12] non-retained message - do not store");
        }

        foreach ($this->se->getSubscribers($topic) as $subscriber) {
            $this->logger->info("Subscriber ".$subscriber." topic ".$topic);
            if (count($this->se->getSubscriptions($topic, $subscriber)) > 1) {
                $this->logger->info("[MQTT-3.3.5-1] overlapping subscriptions");
            }
            if ($this->overlappingSingle) {
                $out_qos = $this->se->qosOf($subscriber, $topic);
                $this->logger->info("PUBLISH => QOS ".$out_qos);
                $this->clients[$subscriber]->publishArrived($topic, $message, $out_qos);
            } else {
                foreach ($this->se->getSubscriptions($topic, $subscriber) as $subscription) {
                    $out_qos = min($subscription->getQoS(), $qos);
                    $this->clients[$subscriber]->publishArrived($topic, $message, $out_qos);
                }
            }
        }
    }

    /**
     * @param string|array $clientId
     * @param int|array $topic
     * @param $qos
     */
    private function doRetained($clientId, $topic, $qos)
    {
        if (!is_array($topic)) {
            $topic = [$topic];
            $qos = [$qos];
        }

        // t is a wildcard subscription topic
        foreach ($topic as $i => $t) {
            $topicsUsed = [];
            // s is a non-wildcard retained topic
            foreach ($this->se->getRetainedTopics($t) as $s) {
                if (empty($topicsUsed[$s])) {
                    $topicsUsed[$s] = true;
                    list($ret_msg, $retQos) = $this->se->getRetained($s);
                    $thisQos = min($retQos, $qos[$i]);
                    $this->clients[$clientId]->publishArrived($s, $ret_msg, $thisQos, true);
                }
            }
        }
    }

    public function subscribe($clientId, $topic, $qos)
    {
        $rc = $this->se->subscribe($clientId, $topic, $qos);
        $this->doRetained($clientId, $topic, $qos);

        return $rc;
    }

    public function unsubscribe($clientId, $topic)
    {
        $this->se->unsubscribe($clientId, $topic);
    }

    public function getSubscriptions($clientId = null)
    {
        return $this->se->getSubscriptions($clientId);
    }
}
