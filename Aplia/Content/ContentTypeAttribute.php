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
            } elseif (isset($fields['content'])) {
                $this->value = $fields['content'];
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
        foreach ($options as $id => $nameOrArray) {
            $optionXML = $xmlObj->addChild('option');
            if (is_array($nameOrArray)) {
                $optionXML->addAttribute('id', $nameOrArray['id']);
                $optionXML->addAttribute('name', $nameOrArray['name']);
            } elseif (is_string($nameOrArray)) {
                $optionXML->addAttribute('id', $id);
                $optionXML->addAttribute('name', $nameOrArray);
            }
        }
        return $xmlObj->asXML();
    }

    public function selectionTypeMap($value)
    {
        $map = array(
            0 => 'browse',
            1 => 'dropdown',
            2 => 'radio_button',
            3 => 'checkbox',
            4 => 'multiple_list',
            5 => 'template_multiple',
            6 => 'template_single'
        );
        $key = array_search($value, $map);
        return $key ? $key : $value;
    }

    public function makeObjectRelationListXml($options)
    {
        $xmlObj = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><related-objects/>');

        foreach ($options as $name => $value) {
            if ($name == "class_constraint_list") {
                $constraints = $xmlObj->addChild('constraints');
                foreach ($value as $index => $identifier) {
                    $constraint = $constraints->addChild('allowed-class');
                    $constraint->addAttribute('contentclass-identifier', $identifier);
                }
            } elseif ($name == 'default_placement') {
                $contentobjectPlacement = $xmlObj->addChild('contentobject-placement');
                $contentobjectPlacement->addAttribute('node-id', $this->relatedNodeId($value));
            } else {
                $child = $xmlObj->addChild($name);
                $child->addAttribute('value', $value);
            }
        }
    }

    public function relatedNodeId($arrayOrNodeId)
    {
        $nodeId = 0;

        if (is_array($arrayOrNodeId) && isset($arrayOrNodeId['uuid'])) {
            $uuid = $arrayOrNodeId['uuid'];
            $node = \eZContentObjectTreeNode::fetchByRemoteID($uuid, false);
            if ($node && isset($node['node_id'])) {
                $nodeId = $node['node_id'];
            } else {
                $nameErrorString = isset($arrayOrNodeId['name']) ? '(name: '.$arrayOrNodeId['name'].')' : '';
                throw new Exception("Node for uuid $uuid $nameErrorString not defined for $this->type in $this->name");
            }
        } elseif (is_numeric($arrayOrNodeId)) {
            $nodeId = $arrayOrNodeId;
        } elseif (isset($arrayOrNodeId['node_id'])) {
            $nodeId = $arrayOrNodeId['node_id'];
        }

        return $nodeId;
    }

    /**
     * Sets multiple fields on a content-class attribute, the fields which are supported
     * depends on the data-type used. Some data-type has special handling which converts
     * easy to use names/values from the eZ specific ones.
     * 
     * e.g. to set ezstring fields:
     * @code
     * $fields = array(
     *     'max' => 50,
     *     'default' => 'Foo',
     * );
     * setAttributeFields($fields, $content, $contentClass)
     * @endcode
     */
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
        } else if ($type == 'ezsobjectrelation') {
            if (isset($value['selection_type'])) {
                $fields['data_int1'] = $this->selectionTypeMap($value['selection_type']);
            }
            if (isset($value['default_selection_node'])) {
                $fields['data_int2'] = $this->relatedNodeId($value['default_selection_node']);
            }
            if (isset($value['fuzzy_match'])) {
                $fields['data_int3'] = $value['fuzzy_match'];
            }
        } else if ($type == 'ezsobjectrelationlist') {
            $fields['data_text5'] = $this->makeObjectRelationListXml($value);
        } else {
            // Let the datatype set the values using class-content value
            // This requires that the datatype actually supports this
            // Data-types known to work for this are:
            // - ezobjectrelation
            // - ezobjectrelationlist
            $content = $value;
        }
    }

    /**
     * Returns fields for attribute which can be exported.
     * 
     * If there are no fields it returns null.
     * 
     * For instance ezstring returns:
     * @code
     * array(
     *     'max' => 100,
     *     'default' => '',
     * );
     * @endcode
     */
    public function attributeFields()
    {
        $type = $this->type;
        $value = $this->value;
        $attribute = $this->classAttribute;

        // Special cases for datatypes which does not use the generic class-content
        // value to initialize the attribute.
        if ($type == 'ezstring') {
            return array(
                'max' => $attribute->attribute('data_int1'),
                'default' => $attribute->attribute('data_text1'),
            );
        } else if ($type == 'ezboolean') {
            return array(
                'default' => $attribute->attribute('data_int3') ? true : false,
            );
        } else if ($type == 'eztext') {
            return array(
                'columns' => $attribute->attribute('data_int1'),
            );
        } else if ($type == 'ezxmltext') {
            return array(
                'columns' => $attribute->attribute('data_int1'),
                'tag_preset' => $attribute->attribute('data_text2'),
            );
        } else if ($type == 'ezimage') {
            return array(
                'max_file_size' => $attribute->attribute('data_int1'),
            );
        } else if ($type == 'ezinteger') {
            return array(
                'min' => $attribute->attribute('data_int1'),
                'max' => $attribute->attribute('data_int2'),
                'default' => $attribute->attribute('data_int3'),
            );
        } else if ($type == 'ezurl') {
            return array(
                'default' => $attribute->attribute('data_text1'),
            );
        } else if ($type == 'ezselection') {
            $fields = $attribute->content();
            $fields['is_multiselect'] = (bool)$fields['is_multiselect'];
            return $fields;
        } else {
            // Let the datatype set the values using class-content value
            // This requires that the datatype actually supports this
            // Data-types known to work for this are:
            // - ezobjectrelation
            // - ezobjectrelationlist
            // throw new \Exception("Unsupported data-type $type, cannot export fields");
            return $attribute->content();
        }
    }

    /**
     * Returns an array of fields which supports translation on the class attribute.
     * If there are no translatable fields it returns null.
     * 
     * e.g.
     * @code
     * array(
     *    'data_text' => 'Some text',
     * )
     * @endcode
     */
    public function attributeTranslatableFields($language = null)
    {
        $type = $this->type;
        $attribute = $this->classAttribute;

        // Special handling of known data-types, most of them does not have any translatable fields.
        if ($type == 'ezstring') {
        } else if ($type == 'ezboolean') {
        } else if ($type == 'eztext') {
        } else if ($type == 'ezxmltext') {
        } else if ($type == 'ezimage') {
        } else if ($type == 'ezinteger') {
        } else if ($type == 'ezurl') {
        } else if ($type == 'ezselection') {
        } else {
            // date_text supports translation on content-class attribute, if it has a value we export it
            $data = $attribute->dataTextI18n($language === null ? $this->language : $language);
            if ($data) {
                return array(
                    'data_text' => $data,
                );
            }
        }
    }

    /**
     * Creates/updates a translation for the attribute.
     * 
     * The following translations can be set.
     * - name - Name of attribute
     * - description - Description for attribute
     * - data_text - Data text for data-type, depends on the type if it is used.
     */
    public function createTranslation($language, $translation)
    {
        $classAttribute = $this->classAttribute;
        $changed = false;
        if (isset($translation['name'])) {
            $classAttribute->setName($translation['name'], $language);
            $changed = true;
        }
        if (isset($translation['description'])) {
            $classAttribute->setDescription($translation['description'], $language);
            $changed = true;
        }
        if (isset($translation['data_text'])) {
            $classAttribute->setDataTextI18n($translation['data_text'], $language);
            $changed = true;
        }
        if ($changed) {
            $classAttribute->store();
        }
        // TODO: Support specific datatypes or a plugin system
    }

    /**
     * Update the attribute after it has been stored to the database.
     * For instance for updating external database tables.
     */
    public function postUpdateAttributeFields($attribute, $contentClass)
    {
    }
}
