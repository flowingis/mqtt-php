<?php

namespace MQTTv311;

use MQTTv311\Connection\Connection;
use MQTTv311\ControlPacket\Packet;
use MQTTv311\ControlPacket\Pingreq;
use MQTTv311\ControlPacket\Subscribe;
use MQTTv311\Exception\MessageTooShortException;
use MQTTv311\Exception\MQTTException;
use Ratchet\RFC6455\Messaging\Frame;

abstract class MQTTClient extends Client
{
    private $streamBuffer;

    public function __construct(
        $clientId,
        Connection $socket
    ) {
        parent::__construct($clientId, $socket);
        $this->streamBuffer = new StreamBuffer();
    }

    /**
     * @param $message string MQTT message or part of it
     * @return bool
     */
    public function handleRequest($message)
    {
        $terminate = false;
        $connectionId = $this->socket->resourceId;
        $this->streamBuffer->append($message, $connectionId);
        try {
            while ($this->streamBuffer->hasPendingPacket($connectionId)) {
                $packet = $this->streamBuffer->getNextPacket($connectionId);
                if ($packet) {
                    $terminate = $this->handlePacket($packet);
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
            $this->disconnect();
        } catch (\Exception $e) {
            $this->logger->error(
                "Assert fails, connection %s, message '%s'",
                [$connectionId, $e->getMessage()]
            );
        }

        return false;
    }

    /**
     * @param Packet $packet
     * @return bool
     *
     * @throws MQTTException
     * @throws \Exception
     */
    public function handlePacket(Packet $packet)
    {
        $method = "on".ucfirst(strtolower(Packet::packetTypeName($packet->fh->MessageType)));

        if (!is_callable([$this, $method])) {
            throw new \Exception("Broker does not support message: ".$packet->fh->MessageType);
        }

        $this->$method($packet);

        return $packet->fh->MessageType == Packet::DISCONNECT;
    }

    public function onConnack()
    {
        $this->logger->info("Connack received");
    }

    public function onSuback($packet)
    {
        $this->logger->info("Suback received msgId: ".$packet->messageIdentifier);
    }

    public function onPuback($packet)
    {
        $this->logger->info("Puback received msgId: ".$packet->messageIdentifier);
    }

    public function onPubrec($packet)
    {
        $this->pubrec($packet->messageIdentifier);
    }

    public function onPubcomp($packet)
    {
        $this->pubcomp($packet->messageIdentifier);
    }

    public function onPubrel($packet)
    {
        $this->pubrel($packet->messageIdentifier);
    }

    public function onPingresp($packet)
    {
        $this->logger->info("Pingresp received msgId: ".$packet->messageIdentifier);
    }

    abstract public function onPublish($packet);

    public function publish($topic, $msg = null, $qos = 0, $retained = false)
    {
        $this->publishArrived($topic, $msg, $qos, $retained);
    }

    public function subscribe($topic, $qos)
    {
        $sub = new Subscribe(null, null, [[$topic, $qos]]);
        if (($qos == 1) || ($qos == 2)) {
            $sub->messageIdentifier = $this->msgid;
            $this->logger->info("client id: %d msgid: %d", [$this->id, $this->msgid]);
            $this->msgid += 1;
            if ($this->msgid == 65536) {
                $this->msgid = 1;
            }
            $this->outbound->add($sub);
            $this->outmsgs[$sub->messageIdentifier] = $sub;
        }
        if ($this->connected) {
            $this->sendPacket($this->socket, $sub);
        } else {
            if ($qos == 0 && !$this->dropQoS0) {
                $this->outbound->add($sub);
            }
            if ($qos == 1 || $qos == 2) {
                $this->logger->info(
                    "[MQTT-3.1.2-5] storing of qos 1 and 2 messages for disconnected client %s",
                    [$this->id]
                );
            }
        }
    }

    public function ping()
    {
        $this->sendPacket($this->socket, new Pingreq());
    }

    /**
     * @param Connection $sock
     * @param Packet $resp
     */
    public function sendPacket(Connection $sock, Packet $resp)
    {
        $this->logger->info('Send packet: %s', [get_class($resp)]);
        $sock->send(new Frame($resp->pack(), true, Frame::OP_BINARY));
    }
}
