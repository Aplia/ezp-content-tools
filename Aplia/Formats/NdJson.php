<?php
namespace Aplia\Formats;

class NdJson
{
    public static function encode($data)
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES) . "\n";
    }
}
