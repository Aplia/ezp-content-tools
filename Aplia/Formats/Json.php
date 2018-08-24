<?php
namespace Aplia\Formats;

class Json
{
    public static function encode($data, $prettyPrint=false)
    {
        $opts = JSON_UNESCAPED_SLASHES;
        if ($prettyPrint) {
            $opts |= JSON_PRETTY_PRINT;
        }
        return json_encode($data, $opts);
    }
}
