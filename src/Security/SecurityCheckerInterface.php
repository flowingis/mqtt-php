<?php

namespace MQTTv311\Security;

interface SecurityCheckerInterface
{
    public function authenticate($username, $password);
    public function canPublish($username, $topic);
    public function canSubscribe($username, $topic);
}
