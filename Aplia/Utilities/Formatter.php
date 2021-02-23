<?php

namespace Aplia\Utilities;

class Formatter
{
    /**
     * @param string $string
     * @return string
     */
    public static function underscoreToCamelCase($string)
    {
        $str = str_replace(' ', '', ucwords(str_replace('_', " ", $string)));
        $str = lcfirst($str);
        return $str;
    }
}