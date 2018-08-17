<?php
namespace Aplia\Content;

use Aplia\Content\Exceptions\ObjectAlreadyExist;
use Aplia\Content\Exceptions\UnsetValueError;
use SimpleXMLElement;

class ContentTypeAttribute
{
    public $name;
    public $type;
    public $identifier;
    public $value;
    public $id;
    public $description;
    public $language;
    public $isRequired = false;
    public $isSearchable = true;
    public $isInformationCollector = false;
    public $canTranslate = true;

    public $classAttribute;

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
            if (isset($fields['isInformationCollector'])) {
                $this->isInformationCollector = $fields['isInformationCollector'];
            }
            if (isset($fields['canTranslate'])) {
                $this->canTranslate = $fields['canTranslate'];
            }
            if (isset($fields['description'])) {
                $this->description = $fields['description'];
            }
            if (isset($fields['language'])) {
                $this->language = $fields['language'];
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

        $trans = \eZCharTransform::instance();
        $name = $this->name;
        $identifier = $this->identifier;
        if (!$identifier) {
            $identifier = $name;
        }
        $identifier = $trans->transformByGroup( $identifier, 'identifier' );

        $fields = array(
            'identifier' => $identifier,
            'version' => $contentClass->attribute('version'),
            'is_required' => $this->isRequired,
            'is_searchable' => $this->isSearchable,
            'can_translate' => $this->canTranslate,
            'is_information_collector' => $this->isInformationCollector,
        );
        if ($this->id) {
            $existing = \eZContentClassAttribute::fetchObject($this->id);
            if ($existing) {
                throw new ObjectAlreadyExist("Content Class Attribute with ID: '$this->id' already exists, cannot create");
            }
            $fields['id'] = $this->id;
        }

        $content = null;
        $this->setAttributeFields($fields, $content, $contentClass);

        $attribute = \eZContentClassAttribute::create($contentClass->attribute('id'), $this->type, $fields, $this->language);
        $attribute->setName($name);
        if ($this->description !== null) {
            $attribute->setDescription($this->description);
        }
        $this->classAttribute = $attribute;
        $dataType = $attribute->dataType();
        $dataType->initializeClassAttribute($attribute);
        $attribute->store();

        if ($content !== null) {
            $existingContent = $attribute->content();
            if (is_array($existingContent) && is_array($content)) {
                // Merge the defaults with the new values, this ensures that
                // the array contains all the values the the data-type
                // expects.
                $content = array_merge($existingContent, $content);
            }
            $attribute->setContent($content);
            $attribute->store();
        }

        $this->postUpdateAttributeFields($attribute, $contentClass);

        return $attribute;
    }

    /*!
     * Generates an xml string from an array of options. Expects array of options as a list of names.
     * E.g.:
     *      array( 'name 1', 'name 2' )
     * Here, the id-attributes will be set to the corresponding array indices
     */
    public function makeSelectionXml(array $options)
    {
        $xmlObj = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><ezselection/>');
        foreach ($options as $id => $name) {
            $optionXML = $xmlObj->addChild('option');
            $optionXML->addAttribute('id', $id);
            $optionXML->addAttribute('name', $name);
        }
        return $xmlObj->asXML();
    }

    public function setAttributeFields(&$fields, &$content, $contentClass)
    {
        $type = $this->type;
        $value = $this->value;

        // Special cases for datatypes which does not use the generic class-content
        // value to initialize the attribute.
        if ($type == 'ezstring') {
            if (isset($value['max'])) {
                $fields['data_int1'] = $value['max'];
            }
            if (isset($value['default'])) {
                $fields['data_text1'] = $value['default'];
            }
        } else if ($type == 'ezboolean') {
            if (isset($value['default'])) {
                $fields['data_int3'] = $value['default'];
            }
        } else if ($type == 'eztext') {
            if (isset($value['columns'])) {
                $fields['data_int1'] = $value['columns'];
            }
        } else if ($type == 'ezxmltext') {
            if (isset($value['columns'])) {
                $fields['data_int1'] = $value['columns'];
            }
            if (isset($value['tag_preset'])) {
                $fields['data_text2'] = $value['tag_preset'];
            }
        } else if ($type == 'ezimage') {
            if (isset($value['max_file_size'])) {
                $fields['data_int1'] = $value['max_file_size'];
            }
        } else if ($type == 'ezinteger') {
            if (isset($value['min'])) {
                $fields['data_int1'] = $value['min'];
            }
            if (isset($value['max'])) {
                $fields['data_int2'] = $value['max'];
            }
            if (isset($value['default'])) {
                $fields['data_int3'] = $value['default'];
            }
        } else if ($type == 'ezurl') {
            if (isset($value['default'])) {
                $fields['data_text1'] = $value['default'];
            }
        } else if ($type == 'ezselection') {
            if (isset($value['is_multiselect'])) {
                $fields['data_int1'] = $value['is_multiselect'];
            } else {
                $fields['data_int1'] = "0";
            }

            if (isset($value['options'])) {
                $fields['data_text5'] = $this->makeSelectionXml($value['options']);
            }
        } else {
            // Let the datatype set the values using class-content value
            // This requires that the datatype actually supports this
            // data-types known to work this are:
            // - ezobjectrelation
            // - ezobjectrelationlist
            $content = $value;
        }
    }

    /**
     * Update the attribute after it has been stored to the database.
     * For instance for updating external database tables.
     */
    public function postUpdateAttributeFields($attribute, $contentClass)
    {
    }
}
