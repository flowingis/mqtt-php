<?php

namespace MQTTv311\Security;

class AccessControlEntry
{
    const CAN_PUBLISH = 1;
    const CAN_SUBSCRIBE = 2;

    private $topic;
    private $grants;

    /**
     * AccessControlEntry constructor.
     * @param string $topic
     * @param array $grants
     */
    public function __construct($topic, array $grants)
    {
        $this->topic = $topic;
        foreach ($grants as $grant) {
            if ($grant !== self::CAN_PUBLISH && $grant !== self::CAN_SUBSCRIBE) {
                throw new \InvalidArgumentException("Invalid grant: ".$grant);
            }
        }
        $this->grants = \array_unique($grants);
    }

    private function can($grant, $topic)
    {
        return ($this->topic == $topic) && in_array($grant, $this->grants);
    }

    public function canPublish($topic)
    {
        return $this->can(self::CAN_PUBLISH, $topic);
    }

    public function canSubscribe($topic)
    {
        return $this->can(self::CAN_SUBSCRIBE, $topic);
    }
}
