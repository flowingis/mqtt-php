<?php

namespace MQTTv311;

class Topic
{
    static public function isValidTopicName($aName)
    {
        if (mb_strlen($aName) < 1) {
            // ("MQTT-4.7.3-1] all topic names and filters must be at least 1 char");

            return false;
        }
        if (mb_strlen($aName) > 65535) {
            // ("[MQTT-4.7.3-3] all topic names and filters must be <= 65535 bytes long");

            return false;
        }

        # '#' wildcard can be only at the end of a topic (used to be beginning as well)
        if ((mb_strpos($aName, '#') !== false) && (mb_strpos($aName, '#') != (mb_strlen($aName) - 1))) {
            // ("[MQTT-4.7.1-2] # must be last, and next to /");
            return false;
        }

        # '#' or '+' only next to a slash separator or end of name
        $wilds = ['#', '+'];
        foreach ($wilds as $c) {
            $pos = 0;
            $pos = mb_strpos($aName, $c, $pos);
            while ($pos !== false) {
                if ($pos > 0) { # check previous char is '/'
                    if (mb_strpos($aName, '/', $pos - 1 ) != $pos - 1) {
                        // ("[MQTT-4.7.1-3] + can be used at any complete level");
                        return false;
                    }
                }
                if ($pos < mb_strlen($aName) - 1) { # check that subsequent char is '/'
                    if (mb_strpos($aName, '/', $pos + 1 ) != $pos + 1) {
                        // ("[MQTT-4.7.1-3] + can be used at any complete level");
                        return false;
                    }
                }
                $pos = mb_strpos($aName, $c, $pos + 1);
            }
        }

        return true;
    }

    /**
     * @param $wild
     * @param $nonwild
     * @return bool
     * @throws \Exception
     */
    static public function topicMatches($wild, $nonwild)
    {
        if (!self::isValidTopicName($wild)) {
            throw new \Exception("wild topic error: ".$nonwild);
        }
        if (!self::isValidTopicName($nonwild)) {
            throw new \Exception("nonWild topic error: ".$nonwild);
        }
        if ((mb_strpos($wild, '+') === false) && (mb_strpos($wild, '#') === false)) {
            return ($wild == $nonwild);
        }

        $wild = mb_ereg_replace('(\\\|\.|\^|\$|\*|\?|\{|\[|\]|\||\(|\)|)', '\\1', $wild);

        if ($wild == '#') {
            $wild = '.*';
        } elseif ($wild == '/#') {
            $wild = '/.*';
        } else {
            $wild = strtr($wild, ['#/' => '(.*?/|^)', '/#' => '(/.*?|$)']);
        }
        $wild = strtr($wild, ['+' => '[^/]+?']);
        $wild = '^'.$wild.'$';

        return (bool)(mb_ereg_match($wild, $nonwild));
    }
}
