<?php
namespace Aplia\Content;

use Exception;
use Aplia\Content\Exceptions\AttributeError;
use Aplia\Content\Exceptions\ValueError;
use Aplia\Content\Exceptions\UnsetValueError;

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
            var_dump(array_keys($dataMap));
            throw new AttributeError("Object with ID '{$object->contentObject->ID}' does not have an attribute with identifier '{$this->identifier}'");
        }
        $attribute = $dataMap[$this->identifier];

        $type = $attribute->attribute('data_type_string');
        $value = $this->value;

        $asContent = false;
        if ($type == 'ezxmltext') {
            if ($value instanceof RawXmltext) {
                $attribute->setAttribute('data_text', $value->rawText);
            } else {
                throw new ValueError("Cannot update attribute data for '{$this->identifier}', unsupported content value: $value");
            }
        } else if ($type == 'ezselection' && is_int($value)) {
            $attribute->setAttribute( 'data_text', $value );
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
}
