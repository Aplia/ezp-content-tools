<?php
namespace Aplia\Content;

use Aplia\Support\Arr;

/**
 * Represents eZ publish XML text used by the ezxmltext datatype.
 * Contains the text and optionall url objects, related objects
 * and linked objects.
 */
class RawXmlText
{
    public $rawText;
    public $urlObjectLinks = array();
    public $relatedObjects = array();
    public $linkedObjects = array();

    public function __construct($text, array $params = array())
    {
        $this->rawText = $text;
        if ($params) {
            $this->urlObjectLinks = Arr::get($params, 'urlObjectLinks', array());
            $this->relatedObjects = Arr::get($params, 'relatedObjects', array());
            $this->linkedOjects = Arr::get($params, 'linkedOjects', array());
        }
    }
}
