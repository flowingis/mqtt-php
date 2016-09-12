<?php

namespace MQTTv311\ControlPacket;

use MQTTv311\Assert\Assert;
use MQTTv311\ControlPacket\FixedHeader;
use MQTTv311\StreamBuffer;
use MQTTv311\ControlPacket\Packet;

class Connect extends Packet
{
    public $ProtocolName = 'MQTT';
    public $ProtocolVersion = 4;
    public $CleanSession = true;
    private $WillFlag = false;
    private $WillQoS = 0;
    private $WillRetain = false;
    private $WillTopic = null;
    private $WillMessage = null;
    public $KeepAliveTimer = 30;
    public $usernameFlag = false;
    public $passwordFlag = false;
    public $ClientIdentifier = '';
    private $username = null;
    private $password = null;

    public function __construct($buffer = null)
    {
        $this->fh = new FixedHeader(Packet::CONNECT);
        parent::__construct($buffer);
        if ($buffer !== null) {
            $this->unpack($buffer);
        }
    }

    public function pack()
    {
        $connectFlags = ($this->CleanSession ? 2 : 0) | ($this->WillFlag ? 4 : 0)
            | ($this->WillQoS << 3) | ($this->WillRetain ? 32 : 0)
            | ($this->usernameFlag ? 64 : 0) | ($this->passwordFlag ? 128 : 0);

        $buffer = StreamBuffer::writeUTF($this->ProtocolName).chr($this->ProtocolVersion).chr($connectFlags).StreamBuffer::writeInt16($this->KeepAliveTimer);
        $buffer .= StreamBuffer::writeUTF($this->ClientIdentifier);
        if ($this->WillFlag) {
            $buffer .= StreamBuffer::writeUTF($this->WillTopic);
            $buffer .= StreamBuffer::writeUTF($this->WillMessage);
        }
        if ($this->usernameFlag) {
            $buffer .= StreamBuffer::writeUTF($this->username);
        }
        if ($this->passwordFlag) {
            $buffer .= StreamBuffer::writeUTF($this->password);
        }
        $buffer = $this->fh->pack(strlen($buffer)).$buffer;

        return $buffer;
    }

    public function unpack($buffer)
    {
        $this->fh->unpack($buffer, Packet::CONNECT);
        $fhlen = $this->fh->getLength();
        try {
            $packlen = $this->fh->getPacketLength();
            $curlen = $fhlen;
            Assert::assert($this->fh->DUP == false);
            Assert::assert($this->fh->QoS == 0);
            Assert::assert($this->fh->Retain == false);
            $this->ProtocolName = StreamBuffer::readUTF(substr($buffer, $curlen), $this->fh->remainingLength);
            $curlen += strlen($this->ProtocolName) + 2;
            Assert::assert($this->ProtocolName == 'MQTT', "Assert protocol name:  'MQTT' != ".$this->ProtocolName);
            $this->ProtocolVersion = ord($buffer[$curlen]);
            Assert::assert($this->ProtocolVersion == 4);
            $curlen += 1;
            $connectFlags = ord($buffer[$curlen]);
            Assert::assert(($connectFlags & 1) == 0);
            $this->CleanSession = (($connectFlags >> 1) & 1) == 1;
            $this->WillFlag = (($connectFlags >> 2) & 1) == 1;
            $this->WillQoS = ($connectFlags >> 3) & 3;
            $this->WillRetain = (($connectFlags >> 5) & 1) == 1;
            $this->passwordFlag = (($connectFlags >> 6) & 1) == 1;
            $this->usernameFlag = (($connectFlags >> 7) & 1) == 1;
            $curlen += 1;
            if ($this->WillFlag) {
                Assert::assert(in_array($this->WillQoS, array(0, 1, 2)));
            } else {
                Assert::assert($this->WillQoS == 0);
                Assert::assert($this->WillRetain == false);
            }
            $this->KeepAliveTimer = StreamBuffer::readInt16(substr($buffer, $curlen));
            $curlen += 2;
            $this->logger->info('[MQTT-3.1.3-3] Clientid must be present, and first field');
            $this->logger->info('[MQTT-3.1.3-4] Clientid must be Unicode, and between 0 and 65535 bytes long');
            $this->ClientIdentifier = StreamBuffer::readUTF(substr($buffer, $curlen), $packlen - $curlen);
            $curlen += strlen($this->ClientIdentifier) + 2;
            if ($this->WillFlag) {
                $this->WillTopic = StreamBuffer::readUTF(substr($buffer, $curlen), ($packlen - $curlen));
                $curlen += strlen($this->WillTopic) + 2;
                $this->WillMessage = StreamBuffer::readBytes(substr($buffer, $curlen));
                $curlen += strlen($this->WillMessage) + 2;
                $this->logger->info('[[MQTT-3.1.2-9] will topic and will message fields must be present');
            } else {
                $this->WillTopic = $this->WillMessage = null;
            }
            if ($this->usernameFlag) {
                Assert::assert(strlen($buffer) > ($curlen + 2));
                $this->username = StreamBuffer::readUTF(substr($buffer, $curlen), ($packlen - $curlen));
                $curlen += strlen($this->username) + 2;
                $this->logger->info('[MQTT-3.1.2-19] username must be in payload if user name flag is 1');
            } else {
                $this->logger->info('[MQTT-3.1.2-18] username must not be in payload if user name flag is 0');
                Assert::assert(($this->passwordFlag == false));
            }
            if ($this->passwordFlag) {
                Assert::assert(strlen($buffer) > ($curlen + 2));
                $this->password = StreamBuffer::readBytes(substr($buffer, $curlen));
                $curlen += (strlen($this->password) + 2);
                $this->logger->info('[MQTT-3.1.2-21] password must be in payload if password flag is 0');
            } else {
                $this->logger->info('[MQTT-3.1.2-20] password must not be in payload if password flag is 0');
            }
            if (($this->WillFlag && $this->usernameFlag && $this->passwordFlag)) {
                $this->logger->info('[MQTT-3.1.3-1] clientid, will topic, will message, username and password all present');
            }
            Assert::assert(($curlen == $packlen));
        } catch (\Exception $e) {

            $this->logger->error(
                '[MQTT-3.1.4-1] server must validate connect packet and close connection without connack if it does not conform'
            );

            throw new \Exception($e->getMessage());
        }
    }

    function __toString()
    {
        $buf = (string)($this->fh).
            ', ProtocolName='.(string)$this->ProtocolName.
            ', ProtocolVersion='.$this->ProtocolVersion.
            ', CleanSession='.(string)$this->CleanSession.
            ', WillFlag='.(string)$this->WillFlag.
            ', KeepAliveTimer='.(string)$this->KeepAliveTimer.
            ', ClientId='.$this->ClientIdentifier.
            ', usernameFlag='.(string)$this->usernameFlag.
            ', passwordFlag='.(string)$this->passwordFlag;
        if ($this->WillFlag) {
            $buf .= ', WillQoS='.($this->WillQoS).
                ', WillRetain='.$this->WillRetain.
                ", WillTopic='".$this->WillTopic.
                "', WillMessage='".$this->WillMessage."'";
        }
        if ($this->username) {
            $buf .= ', username='.$this->username;
        }
        if ($this->password) {
            $buf .= ', password='.$this->password;
        }

        return $buf;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function getPassword()
    {
        return $this->password;
    }

    public function setUsername($username)
    {
        $this->username = $username;
        $this->usernameFlag = !is_null($username);
    }

    public function setPassword($password)
    {
        $this->password = $password;
        $this->passwordFlag = !is_null($password);
    }

    public function hasWill()
    {
        return (bool)$this->WillFlag;
    }

    public function getWill()
    {
        return [
            $this->WillTopic,
            $this->WillQoS,
            $this->WillMessage,
            $this->WillRetain,
        ];
    }
}
