<?php

namespace MQTTv311\Assert;

class Assert
{
    static public function assert($shouldTrue, $message = null)
    {
        if (!$shouldTrue) {
            $message = $message ?: "Assert fails";
            $message.= "\n".self::getBacktrace();

            throw new \Exception($message);
        }
    }

    static private function getBacktrace()
    {
        ob_start();
        debug_print_backtrace(0, 2);
        $trace = ob_get_contents();
        ob_end_clean();

        return $trace;
    }
}