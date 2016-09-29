<?php
namespace Aplia\Content;

/**
 * Represents unprocessed HTML text which may be sent from a browser
 * form field or set directly from PHP code.
 */
class HtmlText
{
    public $text;

    public function __construct($text)
    {
        $this->text = $text;
    }
}
