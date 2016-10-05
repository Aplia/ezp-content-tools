<?php
namespace Aplia\Content;

use Exception;
use Aplia\Content\Exceptions\AttributeError;
use Aplia\Content\Exceptions\ValueError;
use Aplia\Content\Exceptions\UnsetValueError;
use Aplia\Content\Exceptions\HtmlError;

class ContentObjectAttribute
{
    public $identifier;
    public $value;
    public $id;
    public $contentAttribute;

    public function __construct($identifier, $value, $fields = null)
    {
        $this->identifier = $identifier;
        $this->value = $value;
        if ($fields) {
            if (isset($fields['id'])) {
                $this->id = $fields['id'];
            }
        }
    }

    public function update($object)
    {
        if (!$this->identifier) {
            throw new UnsetValueError("ContentClass attribute has no identifier, cannot create");
        }
        $dataMap = $object->contentObject->dataMap();
        if (!isset($dataMap[$this->identifier])) {
            throw new AttributeError("Object with ID '{$object->contentObject->ID}' does not have an attribute with identifier '{$this->identifier}'");
        }
        $attribute = $dataMap[$this->identifier];

        $type = $attribute->attribute('data_type_string');
        $value = $this->value;

        $asContent = false;
        if ($type == 'ezxmltext') {
            $this->updateXmlTextType($attribute, $value, $object);
        } else if ($type == 'ezselection' && is_int($value)) {
            $attribute->setAttribute( 'data_text', $value );
        } else if ($type == 'ezimage') {
            $this->updateImageType($attribute, $value);
        } else {
            $asContent = true;
        }

        if ($asContent) {
            $attribute->fromString($value);
        }
        $attribute->store();
        $this->contentAttribute = $attribute;
        return $attribute;
    }

    /**
     * Updates the attribute containing an ezimage datatype with the given
     * value. The value can be one of:
     *
     * - HttpFile - Contains the identifier of the POST variable containing
     *              the uploaded file. Is transferred to the attribute.
     *
     * @note This does not store the attribute content to the database.
     * @throws ValueError If $value is not one of the supported types above.
     */
    public function updateImageType($attribute, $value)
    {
        if ($value instanceof HttpFile) {
            $content = $attribute->attribute('content');
            if ($value->hasFile && $value->isValid) {
                $httpFile = \eZHTTPFile::fetch($value->name);
                if ($httpFile && $content) {
                    $content->setHTTPFile($httpFile);
                }
            }
        } else {
            throw new ValueError("Cannot update attribute data for '{$this->identifier}', unsupported content value: $value");
        }
    }

    /**
     * Updates the attribute containing an ezxmltext datatype with the given
     * value. The value can be one of:
     *
     * - HtmlText - The HTML content is parsed and turned into RawXmlText.
     * - RawXmlText - The xml text is set directly in the attribute and
     *                and any relations/links updated.
     *
     * @note This does not store the attribute content to the database.
     * @throws ValueError If $value is not one of the supported types above.
     */
    public function updateXmlTextType($attribute, $value, $object)
    {
        // If we have HTML content convert it to XML text first
        if ($value instanceof HtmlText) {
            $value = $this->parseHtmlToXml($value->text, $object);
        }

        if ($value instanceof RawXmlText) {
            // Update links/relations first
            if ($value->urlObjectLinks) {
                $this->updateUrlObjectLinks($attribute, $value->urlObjectLinks);
            }

            if ($value->relatedObjects || $value->linkedObjects) {
                $contentObject = $attribute->attribute('object');
                if ($value->relatedObjects) {
                    $contentObject->appendInputRelationList($value->relatedObjects, eZContentObject::RELATION_EMBED);
                }
                if ($value->linkedObjects) {
                    $contentObject->appendInputRelationList($value->linkedObjects, eZContentObject::RELATION_LINK);
                }
            }

            // Then store the xml text
            $attribute->setAttribute('data_text', $value->rawText);
        } else {
            throw new ValueError("Cannot update attribute data for '{$this->identifier}', unsupported content value: $value");
        }
    }

    /**
     * Updates url object links related to a content attribute.
     *
     * @param $attribute The content attribute to create links for.
     * @param $urlObjectLinks Array of URL ids
     */
    public function updateUrlObjectLinks($attribute, $urlObjectLinks)
    {
        $objectAttributeID = $attribute->attribute('id');
        $objectAttributeVersion = $attribute->attribute('version');

        foreach ($urlObjectLinks as $urlID) {
            $linkObjectLink = \eZURLObjectLink::fetch($urlID, $objectAttributeID, $objectAttributeVersion);
            if ($linkObjectLink == null) {
                $linkObjectLink = \eZURLObjectLink::create($urlID, $objectAttributeID, $objectAttributeVersion);
                $linkObjectLink->store();
            }
        }
    }

    /**
     * Parses the HTML content $text and turns it into RawXmlText.
     * Any links or relations are also passed in the RawXmlText object.
     *
     * This is similar to the parsing done by the ezxmltext datatype
     * but stores the result in a separate xml container than the
     * attribute itself.
     *
     * @throw HtmlError If it fails to parse the HTML.
     */
    public function parseHtmlToXml($text, $object)
    {
        $contentObjectID = $object->contentObject->attribute('id');
        $text = preg_replace('/\r/', '', $text);
        $text = preg_replace('/\t/', ' ', $text);

        // first empty paragraph
        $text = preg_replace('/^\n/', '<p></p>', $text);

        $parser = new \eZSimplifiedXMLInputParser($contentObjectID, true, \eZXMLInputParser::ERROR_ALL, true);
        $document = $parser->process($text);

        if (!is_object($document)) {
            $errorMessage = implode(' ', $parser->getMessages());
            throw new HtmlError("Failed parsing HTML to XML: $errorMessage");
        }

        $xmlString = \eZXMLTextType::domString($document);
        $urlObjectLinks = $parser->getUrlIDArray();

        return new RawXmlText($xmlString, array(
            'urlObjectLinks' => $urlObjectLinks,
            'relatedObjects' => $parser->getRelatedObjectIDArray(),
            'linkedObjects' => $parser->getLinkedObjectIDArray(),
        ));
    }
}
