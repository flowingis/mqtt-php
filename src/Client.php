<?php

namespace MQTTv311;

use Doctrine\Common\Collections\ArrayCollection;
use MQTTv311\Connection\Connection;
use MQTTv311\ControlPacket\Connect;
use MQTTv311\ControlPacket\Disconnect;
use MQTTv311\ControlPacket\Packet;
use MQTTv311\ControlPacket\Publish;
use MQTTv311\ControlPacket\Pubrel;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

class Client
{
    use LoggerAwareTrait;

    public $id;
    public $cleansession = true;
    /** @var Connection */
    public $socket;
    protected $msgid = 1;
    public $outbound;
    protected $outmsgs = [];
    public $inbound = [];
    public $connected = false;
    public $will;
    public $keepalive = 60;
    public $lastPacket;
    protected $dropQoS0 = true;
    protected $publishOnPubrel = true;
    protected $authToken = '';

    public function __construct(
        $clientId,
        Connection $socket
    ) {
        $this->id = $clientId;
        $this->socket = $socket;
        $this->outbound = new ArrayCollection();
        $this->setLogger(new NullLogger());
    }

    public function connect($username = null, $password = null)
    {
        $connect = new Connect();
        $connect->KeepAliveTimer = $this->keepalive;
        $connect->ClientIdentifier = $this->id;
        if ($username !== null) {
            $connect->setUsername($username);
        }
        if ($password !== null) {
            $connect->setPassword($password);
        }
        $this->sendPacket($this->socket, $connect);
        $this->connected = true;
    }

    public function disconnect()
    {
        $packet = new Disconnect();
        $this->sendPacket($this->socket, $packet);
        $this->socket->close();
    }

    public function resend()
    {
        $this->logger->debug("resending unfinished publications #".count($this->outbound));
        if (count($this->outbound) > 0) {
            $this->logger->info("[MQTT-4.4.0-1] resending inflight qos 1 and 2 messages");
        }
        foreach ($this->outbound as $pub) {
            $this->logger->debug("resending", [$pub]);
            $this->logger->info("[MQTT-4.4.0-2] dup flag must be set on in re-publish");
            if ($pub->fh->QoS == 0) {
                $this->sendPacket($this->socket, $pub);
            } elseif ($pub->fh->QoS == 1) {
                $pub->fh->DUP = 1;
                $this->logger->info("[MQTT-2.1.2-3] Dup when resending qos 1 publish id %d", [$pub->messageIdentifier]);
                $this->logger->info("[MQTT-2.3.1-4] Message id same as original publish on resend");
                $this->logger->info("[MQTT-4.3.2-1] Resending qos 1 with DUP flag");
                $this->sendPacket($this->socket, $pub);
            } elseif ($pub->fh->QoS == 2) {
                if ($pub->qos2state == "PUBREC") {
                    $this->logger->info(
                        "[MQTT-2.1.2-3] Dup when resending qos 2 publish id %d",
                        [$pub->messageIdentifier]
                    );
                    $pub->fh->DUP = 1;
                    $this->logger->info("[MQTT-2.3.1-4] Message id same as original publish on resend");
                    $this->logger->info("[MQTT-4.3.3-1] Resending qos 2 with DUP flag");
                    $this->sendPacket($this->socket, $pub);
                } else {
                    $resp = new Pubrel();
                    $this->logger->info("[MQTT-2.3.1-4] Message id same as original publish on resend");
                    $resp->messageIdentifier = $pub->messageIdentifier;
                    $this->sendPacket($this->socket, $resp);
                }
            }
        }
    }

    public function publishArrived($topic, $msg, $qos, $retained = false)
    {
        $pub = new Publish();
        $this->logger->info("[MQTT-3.2.3-3] topic name must match the subscription's topic filter");
        $pub->topicName = $topic;
        $pub->data = $msg;
        $pub->fh->QoS = $qos;
        $pub->fh->Retain = $retained;
        if ($retained) {
            $this->logger->info("[MQTT-2.1.2-7] Last retained message on matching topics sent on subscribe");
        }
        if ($pub->fh->Retain) {
            $this->logger->info("[MQTT-2.1.2-9] Set retained flag on retained messages");
        }
        if ($qos == 2) {
            $pub->qos2state = "PUBREC";
        }
        if (($qos == 1) || ($qos == 2)) {
            $pub->messageIdentifier = $this->msgid;
            $this->logger->info("client id: %d msgid: %d", [$this->id, $this->msgid]);
            if ($this->msgid == 65535) {
                $this->msgid = 1;
            } else {
                $this->msgid += 1;
            }
            $this->outbound->add($pub);
            $this->outmsgs[$pub->messageIdentifier] = $pub;
        }
        $this->logger->info("[MQTT-4.6.0-6] publish packets must be sent in order of receipt from any given client");
        if ($this->connected) {
            $this->sendPacket($this->socket, $pub);
        } else {
            if ($qos == 0 && !$this->dropQoS0) {
                $this->outbound->add($pub);
            }
            if ($qos == 1 || $qos == 2) {
                $this->logger->info(
                    "[MQTT-3.1.2-5] storing of qos 1 and 2 messages for disconnected client %s",
                    [$this->id]
                );
            }
        }
    }

    public function puback($msgid)
    {
        if (key_exists($msgid, $this->outmsgs)) {
            $pub = $this->outmsgs[$msgid];
            if ($pub->fh->QoS == 1) {
                $this->outbound->removeElement($pub);
                unset($this->outmsgs[$msgid]);
            } else {
                $this->logger->error(
                    "%s: Puback received for msgid %d, but qos is %d",
                    [$this->id, $msgid, $pub->fh->QoS]
                );
            }
        } else {
            $this->logger->error("%s: Puback received for msgid %d, but no message found", [$this->id, $msgid]);
        }
    }

    public function pubrec($msgid)
    {
        if (key_exists($msgid, $this->outmsgs)) {
            $pub = $this->outmsgs[$msgid];
            if ($pub->fh->QoS == 2) {
                if ($pub->qos2state == "PUBREC") {
                    $pub->qos2state = "PUBCOMP";

                    return true;
                } else {
                    $this->logger->error(
                        "%s: Pubrec received for msgid %d, but message in wrong state",
                        [$this->id, $msgid]
                    );
                }
            } else {
                $this->logger->error(
                    "%s: Pubrec received for msgid %d, but qos is %d",
                    [$this->id, $msgid, $pub->fh->QoS]
                );
            }
        } else {
            $this->logger->error("%s: Pubrec received for msgid %d, but no message found", [$this->id, $msgid]);
        }

        return false;
    }

    public function pubcomp($msgid)
    {
        if (key_exists($msgid, $this->outmsgs)) {
            $pub = $this->outmsgs[$msgid];
            if ($pub->fh->QoS == 2) {
                if ($pub->qos2state == "PUBCOMP") {
                    $this->outbound->removeElement($pub);
                    unset($this->outmsgs[$msgid]);
                } else {
                    $this->logger->error("Pubcomp received for msgid %d, but message in wrong state", [$msgid]);
                }
            } else {
                $this->logger->error("Pubcomp received for msgid %d, but qos is %d", [$msgid, $pub->fh->QoS]);
            }
        } else {
            $this->logger->error("Pubcomp received for msgid %d, but no message found", [$msgid]);
        }
    }

    public function pubrel($msgid)
    {
        if (!key_exists($msgid, $this->inbound)) {
            return null;
        }

        $rc = null;
        if ($this->publishOnPubrel) {
            $pub = $this->inbound[$msgid];
            if ($pub->fh->QoS == 2) {
                $rc = $pub;
            } else {
                $this->logger->error("Pubrel received for msgid %d, but qos is %d", [$msgid, $pub->fh->QoS]);
            }
        } else {
            $rc = $this->inbound[$msgid];
        }
        if (!$rc) {
            $this->logger->error("Pubrel received for msgid %d, but no message found", [$msgid]);
        }

        return $rc;
    }

    /**
     * @param Connection $sock
     * @param Packet $resp
     */
    public function sendPacket(Connection $sock, Packet $resp)
    {
        $this->logger->info('Send packet: %s', [get_class($resp)]);
        $sock->send($resp->pack());
    }

    /**
     * @param mixed $authToken
     */
    public function setAuthToken($authToken)
    {
        $this->authToken = $authToken;
    }

    /**
     * @return mixed
     */
    public function getAuthToken()
    {
        return $this->authToken;
    }

    /**
     * @param bool $dropQoS0
     */
    public function setDropQoS0(bool $dropQoS0)
    {
        $this->dropQoS0 = $dropQoS0;
    }

    /**
     * @param bool $publishOnPubrel
     */
    public function setPublishOnPubrel(bool $publishOnPubrel)
    {
        $this->publishOnPubrel = $publishOnPubrel;
    }
}
