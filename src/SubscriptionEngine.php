<?php

namespace MQTTv311;

use Doctrine\Common\Collections\ArrayCollection;
use MQTTv311\Assert\Assert;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

class SubscriptionEngine
{
    use LoggerAwareTrait;

    /** @var ArrayCollection */
    private $subscriptions;
    /** @var ArrayCollection */
    private $retained;
    /** @var ArrayCollection */
    private $dollarSubscriptions;
    /** @var ArrayCollection */
    private $dollarRetained;

    /**
     * SubscriptionEngines constructor.
     */
    public function __construct()
    {
        $this->setLogger(new NullLogger());
        $this->reinitialize();
    }

    public function reinitialize()
    {
        $this->subscriptions = new ArrayCollection();
        $this->retained = new ArrayCollection();
        $this->dollarSubscriptions = new ArrayCollection();
        $this->dollarRetained = new ArrayCollection();
    }

    public function subscribe($clientId, $topic, $qos)
    {
        if (is_array($topic)) {
            Assert::assert(count($topic) == count($qos));
            $rc = [];
            foreach ($topic as $aTopic) {
                $rc[] = $this->subscribeOneTopic($clientId, $aTopic, current($qos));
                next($qos);
            }
        } else {
            $rc = $this->subscribeOneTopic($clientId, $topic, $qos);
        }

        return $rc;
    }

    /**
     * subscribe to one topic
     *
     * @param $clientId
     * @param $topic
     * @param $qos
     * @return Subscription|null
     */
    private function subscribeOneTopic($clientId, $topic, $qos)
    {
        $rc = null;
        if (Topic::isValidTopicName($topic)) {
            $subscriptions = ($topic[0] != '$') ? $this->subscriptions : $this->dollarSubscriptions;
            foreach ($subscriptions as $subscription) {
                if (($subscription->getClientId() == $clientId) && ($subscription->getTopic() == $topic)) {
                    $subscription->resubscribe($qos);

                    return $subscription;
                }
            }
            $rc = new Subscription($clientId, $topic, $qos);
            $subscriptions->add($rc);
        }

        return $rc;
    }

    function unsubscribe($clientId, $topic)
    {
        $matched = false;
        if (is_array($topic)) {
            if (count($topic) > 1) {
                $this->logger->info('[MQTT-3.10.4-6] each topic must be processed in sequence');
            }
            foreach ($topic as $t) {
                if (!$matched) {
                    $matched = $this->unsubscribeOneTopic($clientId, $t);
                }
            }
        } else {
            $matched = $this->unsubscribeOneTopic($clientId, $topic);
        }
        if ((!$matched)) {
            $this->logger->info('[MQTT-3.10.4-5] Unsuback must be sent even if no topics are matched');
        }
    }

    /**
     * unsubscribe to one topic
     *
     * @param $clientId
     * @param $topic
     * @return bool
     */
    private function unsubscribeOneTopic($clientId, $topic)
    {
        $matched = false;
        if (Topic::isValidTopicName($topic)) {
            $subscriptions = ($topic[0] != '$') ? $this->subscriptions : $this->dollarSubscriptions;
            foreach ($subscriptions as $s) {
                if (($s->getClientId() == $clientId) && ($s->getTopic() == $topic)) {
                    $this->logger->info('[MQTT-3.10.4-1] topic filters must be compared byte for byte');
                    $this->logger->info('[MQTT-3.10.4-2] no more messages must be added after unsubscribe is complete');
                    $subscriptions->removeElement($s);
                    $matched = true;
                    break;
                }
            }
        }

        return $matched;
    }

    function clearSubscriptions($clientId)
    {
        foreach ($this->subscriptions as $subscription) {
            if ($subscription->getClientId() == $clientId) {
                $this->subscriptions->removeElement($subscription);
            }
        }
        foreach ($this->dollarSubscriptions as $subscription) {
            if ($subscription->getClientId() == $clientId) {
                $this->subscriptions->removeElement($subscription);
            }
        }
    }

    /**
     * return a list of subscriptions for this client
     *
     * @param $topic
     * @param string|null $clientId
     *
     * @return ArrayCollection|\Doctrine\Common\Collections\Collection|null
     */
    function getSubscriptions($topic, $clientId = null)
    {
        if (!Topic::isValidTopicName($topic)) {
            return null;
        }

        $subscriptions = ($topic[0] != '$') ? $this->subscriptions : $this->dollarSubscriptions;
        if ($clientId !== null) {
            return $subscriptions->filter(
                function (Subscription $sub) use ($topic, $clientId) {
                    return (($sub->getClientId() == $clientId) && Topic::topicMatches($sub->getTopic(), $topic));
                }
            );
        }

        return $subscriptions->filter(
            function (Subscription $sub) use ($topic) {
                return Topic::topicMatches($sub->getTopic(), $topic);
            }
        );
    }

    function qosOf($clientid, $topic)
    {
        $chosen = null;
        foreach ($this->getSubscriptions($topic, $clientid) as $sub) {
            if ($chosen == null) {
                $chosen = $sub->getQoS();
            } else {
                $this->logger->info('[MQTT-3.3.5-1] Overlapping subscriptions max QoS');
                $chosen = max($sub->getQoS(), $chosen);
            }
        }

        return $chosen;
    }

    /**
     * list all clients subscribed to this (non-wildcard) topic
     *
     * @param $topic
     * @return array clientIds
     */
    function getSubscribers($topic)
    {
        $result = new ArrayCollection();
        if (Topic::isValidTopicName($topic)) {
            $subscriptions = ($topic[0] != '$') ? $this->subscriptions : $this->dollarSubscriptions;
            foreach ($subscriptions as $s) {
                if (Topic::topicMatches($s->getTopic(), $topic)) {
                    if (!$result->contains($s->getClientId())) {
                        $result->add($s->getClientId());
                    }
                }
            }
        }

        return $result->toArray();
    }

    /**
     * set a retained message on a non-wildcard topic
     *
     * @param $topic
     * @param $message
     * @param $qos
     */
    function setRetained($topic, $message, $qos)
    {
        if (Topic::isValidTopicName($topic)) {
            /** @var ArrayCollection $retained */
            $retained = ($topic[0] != '$') ? ($this->retained) : ($this->dollarRetained);
            if ((mb_strlen($message) == 0)) {
                if ($retained->containsKey($topic)) {
                    $this->logger->info('[MQTT-3.3.1-11] Deleting zero byte retained message');
                    $retained->remove($topic);
                }
            } else {
                $retained[$topic] = [$message, $qos];
            }
        }
    }

    /**
     * returns (msg, QoS) for a topic
     *
     * @param $topic
     * @return string|null
     */
    function getRetained($topic)
    {
        if (Topic::isValidTopicName($topic)) {
            $retained = ($topic[0] != '$') ? $this->retained : $this->dollarRetained;
            if ($retained->containsKey($topic)) {
                return $retained->get($topic);
            }
        }

        return null;
    }

    /**
     * Returns a list of topics for which retained publications exist
     *
     * @param string $topic wildcard or non-wildcard topic
     * @return bool|null
     */
    function getRetainedTopics($topic)
    {
        if (Topic::isValidTopicName($topic)) {
            $retained = ($topic[0] != '$') ? $this->retained : $this->dollarRetained;
            $topics = new ArrayCollection($retained->getKeys());

            return $topics->filter(function($topicToFilter) use ($topic) {
               return Topic::topicMatches($topic, $topicToFilter);
            });
        }

        return null;
    }
}

