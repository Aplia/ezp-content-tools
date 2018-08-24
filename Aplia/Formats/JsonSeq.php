<?php
namespace Aplia\Formats;

class JsonSeq
{
    public static function encode($data)
    {
        return "\x1e" . json_encode($data, JSON_UNESCAPED_SLASHES);
    }
}
