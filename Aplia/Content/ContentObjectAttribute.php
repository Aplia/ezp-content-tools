<?php
namespace Aplia\Content;

use Exception;
use Aplia\Support\Arr;
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
    public $language;
    public $isDirty;

    public function __construct($identifier, $value, $fields = null)
    {
        $this->identifier = $identifier;
        $this->value = $value;
        $this->id = Arr::get($fields, 'id');
        $this->language = Arr::get($fields, 'language');
        $this->contentAttribute = Arr::get($fields, 'contentAttribute');
        $this->isDirty = false;
    }

    public function setValue($value)
    {
        $this->value = $value;
        $this->isDirty = true;
    }

    /**
     * Load content value from referenced content attribute.
     */
    public function loadValue($object)
    {
        if (!$this->contentAttribute) {
            throw new UnsetValueError("ContentObjectAttribute has no content-attribute set, cannot load value");
        }
        $attribute = $this->contentAttribute;
        $type = $attribute->attribute('data_type_string');

        $value = null;
        if ($type == 'ezboolean') {
            $value = $attribute->attribute("data_int");
            if ($value !== null) {
                $value = (bool)$value;
            }
        } else if ($type == 'eztext' || $type == 'ezstring') {
            $value = $attribute->attribute("data_text");
        } else if ($type == 'ezxmltext') {
            $value = $attribute->attribute("data_text");
        } else if ($type == 'ezfloat') {
            $value = $attribute->attribute("data_float");
            $value = $value ? (float)$value : null;
        } else if ($type == 'ezinteger') {
            $value = $attribute->attribute("data_int");
            $value = $value ? (int)$value : null;
        } else if ($type == 'ezurl') {
            $content = $attribute->content();
            if ($content) {
                if (is_object($content)) {
                    $value = array(
                        'url' => $content->attribute('url'),
                        'text' => $attribute->attribute('data_text'),
                    );
                } else {
                    $value = array(
                        'url' => $content,
                    );
                }
            }
        } else if ($type == 'ezselection') {
            // ezselection returns an array with ids
            $value = $attribute->content();
        } else if ($type == 'ezdate' || $type == 'ezdatetime') {
            $value = $attribute->toString();
            if ($value) {
                $value = new DateTime('@' . $value);
            }
        } else if ($type == 'ezauthor' || $type == 'ezbinaryfile' ||  $type == 'ezimage' ||
                   $type == 'ezcountry' || $type == 'ezemail' || $type == 'ezidentifier' ||
                   $type == 'ezkeyword' || $type == 'ezobjectrelation' ||
                   $type == 'ezobjectrelationlist' || $type == 'ezprice' || $type === 'ezuser' ||
                   $type == 'eztags') {
            // Datatypes that only need to use content():
            // ezauthor -> eZAuthor
            // ezbinaryfile -> eZBinaryFile
            // ezimage -> eZImageAliasHandler
            // ezcountry -> array(array)
            // ezemail -> string
            // ezidentifier -> string
            // ezkeyword -> eZKeyword
            // ezobjecrelation -> eZContentObject
            // ezobjectrelationlist -> array(array)
            // ezprice -> eZPrice
            // ezuser -> eZUser

            // non-standard datatypes which also used content():
            // eztags -> eZTags
            $value = $attribute->content();
        } else {
            // Other unsupported data-types use content() as a fallback
            // to get a value that can be read/used.

            // TODO: Decide if rarely used datatypes should be supported:
            // ezenum, ezinisetting, ezisbn, ezmatrix, ezmedia, ezmultioption, ezmultioption2, ezmultiprice, ezoption,
            // ezpackage, ezrangeoption, ezproductcategory, ezsubtreesubscription
            \eZDebug::writeWarning("ContentTypeAttribute::loadValue: Unsupported data-type $type, falling back to loading value from content()");
            $value = $attribute->content();
        }
        $this->value = $value;
    }

    /**
     * Load content value from referenced content attribute.
     */
    public function attributeFields($object)
    {
        // loadValue() takes care of extracting data from the attribute into the $this->value property.
        $this->loadValue($object);
        $attribute = $this->contentAttribute;
        $type = $attribute->attribute('data_type_string');

        if ($type == 'ezstring' || $type == 'ezboolean' || $type == 'eztext' ||
            $type == 'ezinteger' || $type == 'ezurl' || $type == 'ezemail' || $type == 'ezfloat') {
            return $this->value;
        } else if ($type == 'ezbinaryfile') {
            return $this->exportBinaryFileType($attribute, $object);
        } else if ($type == 'ezimage') {
            return $this->exportImageType($attribute, $object);
        } else if ($type == 'ezauthor') {
            $authors = $this->value;
            if (!$authors) {
                return null;
            }
            $value = array();
            foreach ($authors->attribute('author_list') as $author) {
                $value[] = array(
                    'name' => $author['name'],
                    'email' => $author['email'],
                );
            }
            return $value;
        } else if ($type == 'ezcountry') {
            if (!is_array($this->value)) {
                return null;
            }
            $values = array();
            foreach ($this->value['value'] as $country) {
                $values[] = array(
                    'identifier' => $country['Alpha2'],
                    'name' => $country['Name'],
                );
            }
            return $values;
        } else if ($type == 'ezdate') {
            return $this->value ? $this->value->format("Y-m-d") : null;
        } else if ($type == 'ezdatetime') {
            return $this->value ? $this->value->format(\DateTime::RFC3339) : null;
        } else if ($type == 'ezidentifier') {
            return $this->value ? $this->value : null;
        } else if ($type == 'ezkeyword') {
            $values = array();
            foreach ($this->value->keywordArray() as $keyword) {
                $values[] = $keyword;
            }
            return $values;
        } else if ($type == 'ezobjectrelation') {
            return !$this->value ? null : array(
                'object_id' => $this->value->attribute('id'),
                'object_uuid' => $this->value->remoteId(),
                'name' => $this->value->name(),
            );
        } else if ($type == 'ezobjectrelationlist') {
            if (!$this->value) {
                return null;
            }
            $values = array();
            usort($this->value['relation_list'], function ($a, $b) {
                return $a['priority'] > $b['priority'] ? 1 : ($a['priority'] < $b['priority'] ? -1 : 0);
            });
            foreach ($this->value['relation_list'] as $item) {
                if ($item['in_trash']) {
                    continue;
                }
                $relatedObject = \eZContentObject::fetch($item['contentobject_id']);
                if (!$relatedObject) {
                    continue;
                }
                $values[] = array(
                    'object_id' => $relatedObject->attribute('id'),
                    'object_uuid' => $relatedObject->remoteId(),
                    'name' => $relatedObject->name(),
                );
            }
            return $values;
        } else if ($type == 'ezprice') {
            if (!$this->value) {
                return null;
            }
            $data = array(
                'amount' => (string)$this->value->attribute('price'),
                'is_vat_included' => (bool)$this->value->attribute('is_vat_included'),
            );
            $vat = $this->value->attribute('selected_vat_type');
            if ($vat) {
                $data['vat'] = array(
                    'id' => $vat->attribute('id'),
                    'name' => $vat->attribute('name'),
                    'percentage' => $vat->attribute('percentage'),
                );
            }
            return $data;
        } else if ($type == 'ezxmltext') {
            return $this->exportXmlTextType($attribute, $object);
        } else if ($type == 'ezselection') {
            // When nothing is selected it may still contain array(""), avoid sending that
            if (!$this->value || (is_array($this->value) && array_slice($this->value, 0, 1)[0] === "")) {
                return null;
            }
            return array(
                'selection' => $this->value,
            );
        } else if ($type === 'ezuser') {
            if (!$this->value) {
                return null;
            }
            $hashType = \eZUser::passwordHashTypeName($this->value->attribute('password_hash_type'));
            return array(
                'login' => $this->value->attribute('login'),
                'email' => $this->value->attribute('email'),
                'password_hash' => $hashType . '$' . $this->value->attribute('password_hash'),
            );
        } else if ($type == 'eztags') {
            if (!$this->value) {
                return null;
            }
            $values = array();
            foreach ($this->value->tags() as $tag) {
                $tagValue = array(
                    'id' => (int)$tag->attribute('id'),
                    'uuid' => $tag->attribute('remote_id'),
                    'keyword' => $tag->attribute('keyword'),
                );
                $values[] = $tagValue;
            }
            return $values;
        }

        if ($this->value === null) {
            return null;
        } else if (is_integer($this->value) || is_bool($this->value) || is_string($this->value)) {
            // If value is a scalar value it can exported as-is, if not we need to call toString()
            return array(
                'content' => $this->value,
            );
        } else {
            \eZDebug::writeWarning("ContentTypeAttribute::attributeFeilds: Unsupported data-type $type, falling back to export using toString()");
            return array(
                'content' => $attribute->toString(),
            );
        }
    }

    public function update($object)
    {
        if (!$this->identifier) {
            throw new UnsetValueError("ContentObjectAttribute has no identifier set, cannot update");
        }
        if ($this->contentAttribute) {
            $attribute = $this->contentAttribute;
        } else {
            $dataMap = $object->attributeMap();
            if (!isset($dataMap[$this->identifier])) {
                throw new AttributeError("Object with ID '{$object->contentObject->ID}' does not have an attribute with identifier '{$this->identifier}'");
            }
            $attribute = $dataMap[$this->identifier];
        }
        if (!$this->isDirty) {
            return false;
        }

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
        $this->isDirty = false;
        return $attribute;
    }

    public function exportBinaryFileType($attribute, $object)
    {
        if (!$this->value) {
            return null;
        }
        return array(
            'original_filename' => $this->value->attribute('original_filename'),
            'path' => $this->value->filePath(),
        );
    }

    public function exportImageType($attribute, $object)
    {
        $content = $this->value;
        if (!$content) {
            return null;
        }
        $version = $object->currentVersion();
        $path = $content->imagePath($attribute, $version);
        return array(
            'alternative_text' => $content->attribute('alternative_text'),
            'original_filename' => $content->attribute('original_filename'),
            'path' => $path,
        );
    }

    static public function domRenameElement(DOMElement $node, $name, $skipAttributeCopy=false) {
        $renamed = $node->ownerDocument->createElement($name);

        if (!$skipAttributeCopy) {
            foreach ($node->attributes as $attribute) {
                $renamed->setAttribute($attribute->nodeName, $attribute->nodeValue);
            }
        }

        while ($node->firstChild) {
            $renamed->appendChild($node->firstChild);
        }

        $node->parentNode->replaceChild($renamed, $node);
        return $renamed;
    }

    public function exportXmlTextType($attribute, $object) {
        // TODO: Replace link tags with url from id
        // TODO: Handle embed tags, add object as relation
        // TODO: 
        $dom = new \DOMDocument('1.0', 'utf-8');
        if (!@$dom->loadXML($this->value)) {
            return null;
        }

        $xpath = new \DOMXPath($dom);

        // Links must include the full url as it is stored, to be transferred to new site
        $links = $xpath->query('//link');
        foreach ($links as $link) {
            $urlId = $link->getAttribute('url_id');
            if ($urlId) {
                $url = \eZURL::fetch($urlId);
                $link->setAttribute('href', $url->URL);
            } else {
                // TODO: Write warning/error
            }
        }

        // Embedded objects must include references to uuid and optionally added to exported items
        $embedObjects = array();
        $embeds = $xpath->query('//embed');
        foreach ($embeds as $embed) {
            $objectId = $embed->getAttribute('object_id');
            if (!$objectId) {
                continue;
            }
            $embedObject = \eZContentObject::fetch($objectId);
            if (!$embedObject) {
                continue;
            }
            $identifier = $embedObject->attribute('class_identifier');
            $uuid = $embedObject->remoteId();
            $embed->setAttribute('uuid', $uuid);
            $embed->setAttribute('class_identifier', $identifier);
            $embed->setAttribute('name', $embedObject->name());
            $embedObjects[] = $embedObject;
        }

        $xml = $dom->saveXML();

        return array(
            // reference_objects are to be used by exporter, and will be removed before export
            'referenced_objects' => $embedObjects,
            'xml' => $xml,
        );
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
