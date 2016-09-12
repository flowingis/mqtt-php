<?php

namespace MQTTv311;

use MQTTv311\Client;
use MQTTv311\Connection\Connection;
use MQTTv311\ControlPacket\Unsubscribe;
use MQTTv311\MessageBroker;
use MQTTv311\ControlPacket\Connack;
use MQTTv311\ControlPacket\Connect;
use MQTTv311\ControlPacket\Disconnect;
use MQTTv311\Exception\MessageTooShortException;
use MQTTv311\Exception\MQTTException;
use MQTTv311\StreamBuffer;
use MQTTv311\ControlPacket\Packet;
use MQTTv311\ControlPacket\Pingreq;
use MQTTv311\ControlPacket\Pingresp;
use MQTTv311\ControlPacket\Pubcomp;
use MQTTv311\ControlPacket\Publish;
use MQTTv311\ControlPacket\Pubrec;
use MQTTv311\ControlPacket\Pubrel;
use MQTTv311\ControlPacket\Puback;
use MQTTv311\ControlPacket\Suback;
use MQTTv311\ControlPacket\Unsuback;
use MQTTv311\Repository\ClientRepository;
use MQTTv311\Security\SecurityCheckerInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

class Server
{
    use LoggerAwareTrait;

    private $broker;
    private $clients;
    private $streamBuffer;
    private $securityChecker;
    private $publishOnPubrel = true;
    private $zeroLengthClientIds = false;
    private $dropQoS0 = true;

    public function __construct(SecurityCheckerInterface $securityChecker = null, $overlappingSingle = true)
    {
        $this->setLogger(new NullLogger());
        $this->broker = new MessageBroker($overlappingSingle);
        $this->clients = new ClientRepository();
        $this->streamBuffer = new StreamBuffer();
        $this->securityChecker = $securityChecker;
    }

    public function reinitialize()
    {
        $this->logger->info("Reinitializing broker");
        $this->clients = new ClientRepository();
        $this->broker->reinitialize();
    }

    public function handleRequest($message, Connection $connection)
    {
        $terminate = false;
        $connectionId = $connection->resourceId;
        $this->streamBuffer->append($message, $connectionId);
        try {
            while ($this->streamBuffer->hasPendingPacket($connectionId)) {
                $packet = $this->streamBuffer->getNextPacket($connectionId);
                if ($packet) {
                    $terminate = $this->handlePacket($packet, $connection);
                } else {
                    throw new \Exception("Badly formed MQTT packet");
                }
            }

            return $terminate;
        } catch (MessageTooShortException $e) {
            $this->logger->warning(
                "Incomplete message, connection %s, message '%s'",
                [$connectionId, $e->getMessage()]
            );
        } catch (MQTTException $e) {
            $this->logger->error("[MQTT-4.8.0-1] 'transient error' reading packet, closing connection");
            $this->disconnect($connection, null, true);
        } catch (\Exception $e) {
            $this->logger->error(
                "Assert fails, connection %s, message '%s'",
                [$connectionId, $e->getMessage()]
            );
        }

        return false;
    }

    /**
     * @uses connect, connack, publish, puback, pubrec, pubrel, pubcomp, subscribe, suback, unsubscribe, unsuback, pingreq, pingresp, disconnect
     *
     * @param Packet $packet
     * @param \MQTTv311\Connection\Connection $sock
     * @return bool
     *
     * @throws MQTTException
     * @throws \Exception
     */
    public function handlePacket(Packet $packet, Connection $sock)
    {
        $this->logger->info("in: ".(string)($packet));
        if (!$this->clients->hasSocket($sock) && $packet->fh->MessageType != Packet::CONNECT) {
            $this->disconnect($sock, $packet);
            throw new MQTTException("[MQTT-3.1.0-1] Connect was not first packet on socket");
        }

        $method = strtolower(Packet::packetTypeName($packet->fh->MessageType));

        if (!is_callable([$this, $method])) {
            throw new \Exception("Broker does not support message: ".$packet->fh->MessageType);
        }

        $this->$method($sock, $packet);

        $this->clients->touch($sock);

        return $packet->fh->MessageType == Packet::DISCONNECT;
    }

    private function connect(Connection $sock, Connect $packet)
    {
        try {
            if ($packet->ProtocolName != "MQTT") {
                $this->disconnect($sock, null);
                throw new MQTTException("[MQTT-3.1.2-1] Wrong protocol name ".$packet->ProtocolName);
            }
            if ($packet->ProtocolVersion != 4) {
                $this->logger->error(sprintf("[MQTT-3.1.2-2] Wrong protocol version %d", $packet->ProtocolVersion));
                $resp = new Connack();
                $resp->returnCode = Connack::RETURN_CODE_REFUSED_PROTOCOL_VERSION;
                $this->sendPacket($sock, $resp);
                $this->disconnect($sock, null);

                return;
            }
            if ($this->clients->hasSocket($sock)) {
                $this->disconnect($sock, null);
                throw new MQTTException("[MQTT-3.1.0-2] Second connect $packet");
            }
            if (empty($packet->ClientIdentifier)) {
                if ($this->zeroLengthClientIds == false || $packet->CleanSession == false) {
                    if ($this->zeroLengthClientIds) {
                        $this->logger->info("[MQTT-3.1.3-8] Reject 0-length clientid with cleansession false");
                    }
                    $resp = new Connack();
                    $resp->returnCode = Connack::RETURN_CODE_REFUSED_ID_REJECTED;
                    $this->sendPacket($sock, $resp);
                    $this->disconnect($sock, null);

                    return;
                } else {
                    $this->logger->info("[MQTT-3.1.3-7] 0-length clientid must have cleansession true");
                    $packet->ClientIdentifier = uniqid('', true);
                    $this->logger->info(
                        "[MQTT-3.1.3-6] 0-length clientid must be assigned a unique id %s",
                        $packet->ClientIdentifier
                    );
                }
            }
            $this->logger->info(
                "[MQTT-3.1.3-5] The Server MUST allow ClientIds which are between 1 and 23 UTF-8 encoded bytes in length"
            );

            if ($this->clients->hasByClientId($packet->ClientIdentifier)) {
                $oldClient = $this->clients->getByClientId($packet->ClientIdentifier);
                $this->logger->info(sprintf("[MQTT-3.1.4-2] Disconnecting old client %s", $packet->ClientIdentifier));
                $this->disconnect($oldClient->socket, null);
            }
            $me = null;
            if (!$packet->CleanSession) {
                $me = $this->broker->getClient($packet->ClientIdentifier); # find existing state, if there is any
                if ($me) {
                    $this->logger->info("[MQTT-3.1.3-2] clientid used to retrieve client state");
                }
            }

            if (!$this->authenticate($packet->getUsername(), $packet->getPassword())) {
                $resp = new Connack();
                $resp->returnCode = Connack::RETURN_CODE_REFUSED_BAD_USERNAME_PASSWORD;
                $this->sendPacket($sock, $resp);
                $this->disconnect($sock, null);
                $this->logger->info(
                    "[MQTT-3.2.2-5] Close connection after CONNACK != 0 (0x04 Bad username or password)"
                );

                return;
            }

            $resp = new Connack();
            $resp->flags = $me ? 0x01 : 0x00;
            if ($me == null) {
                $me = new Client(
                    $packet->ClientIdentifier,
                    $packet->CleanSession,
                    $packet->KeepAliveTimer,
                    $sock,
                    $this->dropQoS0,
                    $this->publishOnPubrel,
                    $packet->getUsername()
                );
            } else {
                $me->socket = $sock;
                $me->cleansession = $packet->CleanSession;
                $me->keepalive = $packet->KeepAliveTimer;
                $me->setAuthToken($packet->getUsername());
            }
            $this->logger->info(
                "[MQTT-4.1.0-1] server must store data for at least as long as the network connection lasts"
            );
            $this->clients->add($me);
            $me->will = $packet->hasWill() ? $packet->getWill() : null;
            $this->broker->connect($me);
            $resp->returnCode = Connack::RETURN_CODE_ACCEPTED;
            $this->sendPacket($sock, $resp);
            $me->resend();
        } catch (\Exception $e) {
            $this->disconnect($sock, null);
        }
    }

    public function sockedClosed(Connection $sock)
    {
        $this->disconnect($sock, null, true);
    }

    private function disconnect(Connection $sock, $packet = null, $terminate = false)
    {
        $this->logger->info("[MQTT-3.14.4-2] Client must not send any more packets after disconnect");
        if ($this->clients->hasSocket($sock)) {
            if ($terminate) {
                $this->broker->terminate($this->clients->getBySocket($sock)->id);
            } else {
                $this->broker->disconnect($this->clients->getBySocket($sock)->id);
            }
            $this->clients->removeBySocket($sock);
        }

        try {
            $sock->close();
        } catch (\Exception $e) {
        }
    }

    private function subscribe($sock, $packet)
    {
        $this->logger->info("[MQTT-2.3.1-7][MQTT-3.8.4-2] Suback has same message id as subscribe");
        $this->logger->info("[MQTT-3.8.4-1] Must respond with suback");
        $this->logger->info("[MQTT-3.8.4-5] return code must be returned for each topic in subscribe");
        $this->logger->info("[MQTT-3.9.3-1] the order of return codes must match order of topics in subscribe");
        $resp = new Suback();
        $resp->messageIdentifier = $packet->messageIdentifier;
        $resp->data = [];

        $client = $this->clients->getBySocket($sock);
        foreach ($packet->data as $chunk) {
            $topic = $chunk[0];
            $qos = $chunk[1];

            if ($this->canSubscribe($client, $topic)) {
                $this->broker->subscribe($client->id, $topic, $qos);
                $resp->data[] = $qos;
            } else {
                $resp->data[] = Suback::SUBACK_FAIL;
            }
        }
        $this->sendPacket($sock, $resp);
    }

    private function unsubscribe($sock, Unsubscribe $packet)
    {
        $this->broker->unsubscribe($this->clients->getBySocket($sock)->id, $packet->getTopics());
        $resp = new Unsuback();
        $this->logger->info("[MQTT-2.3.1-7] Unsuback has same message id as unsubscribe");
        $this->logger->info("[MQTT-3.10.4-4] Unsuback must be sent - same message id as unsubscribe");
        $me = $this->clients->getBySocket($sock);
        if (count($me->outbound) > 0) {
            $this->logger->info("[MQTT-3.10.4-3] sending unsuback has no effect on outward inflight messages");
        }
        $resp->messageIdentifier = $packet->getMessageId();
        $this->sendPacket($sock, $resp);
    }

    private function publish($sock, Publish $packet)
    {
        if (mb_strpos($packet->topicName, "+") !== false || mb_strpos($packet->topicName, "#") !== false) {
            throw new MQTTException("[MQTT-3.3.2-2][MQTT-4.7.1-1] wildcards not allowed in topic name");
        }
        if ($packet->fh->QoS == 0) {
            $this->publishMessage($this->clients->getBySocket($sock), $packet);

            return;
        }

        if ($packet->fh->QoS == 1) {
            if ($packet->fh->DUP) {
                $this->logger->info("[MQTT-3.3.1-3] Incoming publish DUP 1 ==> outgoing publish with DUP 0");
                $this->logger->info("[MQTT-4.3.2-2] server must store message in accordance with QoS 1");
            }
            $this->publishMessage($this->clients->getBySocket($sock), $packet);
            $resp = new Puback();
            $this->logger->info("[MQTT-2.3.1-6] puback messge id same as publish");
            $resp->messageIdentifier = $packet->messageIdentifier;
            $this->sendPacket($sock, $resp);

            return;
        }

        if ($packet->fh->QoS == 2) {
            $client = $this->clients->getBySocket($sock);
            if (!key_exists($packet->messageIdentifier, $client->inbound)) {
                $client->inbound[$packet->messageIdentifier] = $packet;
            }
            if (!$this->publishOnPubrel) {
                $this->publishMessage($client, $packet);
            }
            $resp = new Pubrec();
            $this->logger->info("[MQTT-2.3.1-6] pubrec message id same as publish");
            $resp->messageIdentifier = $packet->messageIdentifier;
            $this->sendPacket($sock, $resp);
        }
    }

    private function pubrel($sock, Pubrel $packet)
    {
        $client = $this->clients->getBySocket($sock);
        $pub = $client->pubrel($packet->messageIdentifier);
        if ($pub) {
            $this->logger->info("Pre PUBREL QoS: ".$pub->fh->QoS);
            if ($this->publishOnPubrel) {
                $this->publishMessage($client, $pub);
            }
            unset($client->inbound[$packet->messageIdentifier]);
        }
        $resp = new Pubcomp();
        $this->logger->info("[MQTT-2.3.1-6] pubcomp messge id same as publish");
        $resp->messageIdentifier = $packet->messageIdentifier;
        $this->sendPacket($sock, $resp);
    }

    private function pingreq($sock, Pingreq $packet)
    {
        $resp = new Pingresp();
        $this->logger->info("[MQTT-3.12.4-1] sending pingresp in response to pingreq");
        $this->sendPacket($sock, $resp);
    }

    private function puback($sock, Puback $packet)
    {
        //"confirmed reception of qos 1"
        $this->clients->getBySocket($sock)->puback($packet->messageIdentifier);
    }

    private function pubrec($sock, Pubrec $packet)
    {
        //"confirmed reception of qos 2"
        $myclient = $this->clients->getBySocket($sock);
        if ($myclient->pubrec($packet->messageIdentifier)) {
            $this->logger->info("[MQTT-3.5.4-1] must reply with pubrel in response to pubrec");
            $resp = new Pubrel();
            $resp->messageIdentifier = $packet->messageIdentifier;
            $this->sendPacket($sock, $resp);
        }
    }

    /**
     * Confirmed reception of QoS 2
     *
     * @param $sock
     * @param Pubcomp $packet
     */
    private function pubcomp($sock, Pubcomp $packet)
    {
        $this->clients->getBySocket($sock)->pubcomp($packet->messageIdentifier);
    }

    public function keepalive(Connection $sock)
    {
        if ($this->clients->hasSocket($sock)) {
            $client = $this->clients->getBySocket($sock);
            if (($client->keepalive > 0) && ((time() - $client->lastPacket) > ($client->keepalive * 1.5))) {
                $this->logger->info("[MQTT-3.1.2-22] keepalive timeout for client %s", [$client->id]);
                $this->disconnect($sock, null, true);
            }
        }
    }

    /**
     * @param $sock
     * @param $resp
     */
    public function sendPacket(Connection $sock, Packet $resp)
    {
        $sock->send($resp->pack());
    }

    /**
     * @param \MQTTv311\Client $client
     * @param $message
     * @throws \Exception
     */
    private function publishMessage(Client $client, $message)
    {
        if ($this->securityChecker instanceof SecurityCheckerInterface &&
            !$this->securityChecker->canPublish($client->getAuthToken(), $message->topicName)
        ) {
            $this->logger->info(
                "[MQTT-3.3.5-2] close network connection, unauthorized client %s for topic %s",
                [$client->id, $message->topicName]
            );
            throw new \Exception(sprintf("Unauthorized client %s for topic %s", $client->id, $message->topicName));
        }

        $this->broker->publish(
            $client->id,
            $message->topicName,
            $message->data,
            $message->fh->QoS,
            $message->fh->Retain
        );
    }

    private function authenticate($username, $password):bool
    {
        if ($this->securityChecker instanceof SecurityCheckerInterface) {
            return $this->securityChecker->authenticate($username, $password);
        }

        return true;
    }

    /**
     * @return boolean
     */
    public function getDropQoS0():bool
    {
        return $this->dropQoS0;
    }

    /**
     * @return boolean
     */
    public function getPublishOnPubrel():bool
    {
        return $this->publishOnPubrel;
    }

    /**
     * @param Client $client
     * @param string $topicName
     * @return bool
     */
    private function canSubscribe(Client $client, $topicName):bool
    {
        if ($this->securityChecker instanceof SecurityCheckerInterface &&
            !$this->securityChecker->canSubscribe($client->getAuthToken(), $topicName)
        ) {
            $this->logger->info(
                "[MQTT-3.3.5-2] close network connection, unauthorized client %s for topic %s",
                [$client->id, $topicName]
            );

            return false;
        }

        return true;
    }
}
