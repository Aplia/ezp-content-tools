<?php
namespace Starter;

use Aplia\Content\Exceptions\ObjectAlreadyExist;
use Aplia\Content\Exceptions\UnsetValueError;

class ContentTypeAttribute
{
    public $name;
    public $type;
    public $identifier;
    public $value;
    public $id;
    public $isRequired = false;
    public $isSearchable = true;
    public $canTranslate = true;

    public function __construct($identifier, $type, $name, $fields = null)
    {
        $this->identifier = $identifier;
        $this->name = $name;
        $this->type = $type;
        if ($fields) {
            if (isset($fields['id'])) {
                $this->id = $fields['id'];
            }
            if (isset($fields['value'])) {
                $this->value = $fields['value'];
            }
            if (isset($fields['isRequired'])) {
                $this->isRequired = $fields['isRequired'];
            }
            if (isset($fields['isSearchable'])) {
                $this->isSearchable = $fields['isSearchable'];
            }
            if (isset($fields['canTranslate'])) {
                $this->canTranslate = $fields['canTranslate'];
            }
        }
    }

    public function create($contentClass)
    {
        if (!$this->name) {
            throw new UnsetValueError("ContentClass attribute has no name, cannot create");
        }
        if (!$this->type) {
            throw new UnsetValueError("ContentClass attribute has no type, cannot create");
        }
        $fields = array(
            'name' => $this->name,
            'data_type_string' => $this->type,
            'identifier' => $this->identifier,
            'version' => $contentClass->attribute('version'),
            'contentclass_id' => $contentClass->attribute('id'),
            'is_required' => $this->isRequired,
        );
        if ($this->id) {
            $existing = \eZContentClassAttribute::fetchObject($this->id);
            if ($existing) {
                throw new ObjectAlreadyExist("Content Class Attribute with ID: '$this->id' already exists, cannot create");
            }
            $fields['id'] = $this->id;
        }
        $type = $this->type;
        $value = $this->value;

        if ($type == 'ezstring') {
            if (isset($value['max'])) {
                $fields['data_int1'] = $value['max'];
            }
        }

        $attribute = new \eZContentClassAttribute($fields);
        $this->classAttribute = $attribute;
        $attribute->store();
        return $attribute;
    }
}
