<?php
namespace Aplia\Content;

use Exception;
use Aplia\Content\Exceptions\ObjectAlreadyExist;
use Aplia\Content\Exceptions\UnsetValueError;
use Aplia\Content\Exceptions\ValueError;
use Aplia\Content\Exceptions\ObjectDoesNotExist;
use Aplia\Support\Arr;
use SimpleXMLElement;
use eZContentClass;
use eZContentObjectTreeNode;

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

    public function objectRelationSelectionTypeMap($valueOrKey, $getName = false)
    {
        $return = 0;
        $map = array(
            0 => 'browse_multi',
            1 => 'dropdown_single',
            2 => 'radio_buttons_single',
            3 => 'checkboxes_multi',
            4 => 'selection_list_multi',
            5 => 'template_based_multi',
            6 => 'template_based_single'
        );

        if ($getName && (is_numeric($valueOrKey) && isset($map[$valueOrKey]))) {
            $return = $map[$valueOrKey];
        } else {
            $key = array_search($valueOrKey, $map);
            $return = $key !== false ? $key : $valueOrKey;
        }

        return $return;
    }

    /**
     * Finds the node ID from an array or numeric value.
     * 
     * @param $arrayOrNodeId Array structure with uuid/node_id or a numerical ID
     * @return integer The node ID
     * @throws ValueError 
     */
    public function relatedNodeId($arrayOrNodeId)
    {
        $nodeId = null;

        if (is_array($arrayOrNodeId) && isset($arrayOrNodeId['uuid'])) {
            $uuid = $arrayOrNodeId['uuid'];
            $node = eZContentObjectTreeNode::fetchByRemoteID($uuid, false);
            if (!$node) {
                $nameErrorString = isset($arrayOrNodeId['name']) ? '(name: '.$arrayOrNodeId['name'].')' : '';
                throw new ValueError("Node for uuid $uuid $nameErrorString not defined for $this->type in $this->name");
            }
            $nodeId = $node['node_id'];
        } elseif (is_numeric($arrayOrNodeId)) {
            $nodeId = $arrayOrNodeId;
        } elseif (isset($arrayOrNodeId['node_id'])) {
            $nodeId = $arrayOrNodeId['node_id'];
        }

        return $nodeId;
    }

    /**
     * Looks up the node and returns an array with information about
     * UUID and node id.
     * 
     * If $nodeId is null it returns null.
     * 
     * @param $nodeId is either a numeric ID or an eZContentObjectTreeNode instance
     * @return array
     * @throws ObjectDoesNotExist If the referenced node does not exist
     * @throws ValueError If the input parameter does not have a supported value
     */
    public function makeNodeArray($nodeId)
    {
        if (!$nodeId) {
            return null;
        }
        if (is_numeric($nodeId)) {
            $node = eZContentObjectTreeNode::fetch($nodeId);
            if (!$node) {
                throw new ObjectDoesNotExist("Content node with ID $nodeId does not exist");
            }
        } else if ($nodeId instanceof eZContentObjectTreeNode) {
            $node = $nodeId;
        } else if (is_string($nodeId) && substr($nodeId, 0, 5) === 'uuid:') {
            $nodeId = substr($nodeId, 5);
            $node = eZContentObjectTreeNode::fetch($nodeId);
            if (!$node) {
                throw new ObjectDoesNotExist("Content node with ID $nodeId does not exist");
            }
        } else if (is_array($nodeId) && isset($nodeId['node_id'])) {
            $nodeId = $nodeId['node_id'];
            $node = eZContentObjectTreeNode::fetch($nodeId);
            if (!$node) {
                throw new ObjectDoesNotExist("Content node with ID $nodeId does not exist");
            }
        } else {
            throw new ValueError("Unsupported value for looking up node: " . var_export($nodeId, true));
        }
        return array(
            'node_id' => (int)$node->attribute('node_id'),
            'uuid' => $node->remoteID(),
            'name' => $node->getName($this->language),
        );
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
        } else if ($type == 'ezobjectrelation') {
            if (isset($value['selection_type'])) {
                $content['selection_type'] = $this->objectRelationSelectionTypeMap($value['selection_type']);
            } else if (!isset($content['selection_type'])) {
                $content['selection_type'] = $this->objectRelationSelectionTypeMap('browse_multi');
            }
            if (isset($value['default_selection_node'])) {
                $content['default_selection_node'] = $this->relatedNodeId($value['default_selection_node']);
            }
            if (isset($value['fuzzy_match'])) {
                $content['fuzzy_match'] = $value['fuzzy_match'];
            } else if (!isset($content['fuzzy_match'])) {
                $content['fuzzy_match'] = false;
            }
        } else if ($type == 'ezobjectrelationlist') {
            if (isset($value['selection_type'])) {
                $content['selection_type'] = $this->objectRelationSelectionTypeMap($value['selection_type']);
            } else if (!isset($content['selection_type'])) {
                $content['selection_type'] = $this->objectRelationSelectionTypeMap('browse_multi');
            }
            if (isset($value['default_placement'])) {
                $content['default_placement']['node_id'] = $this->relatedNodeId($value['default_placement']);
            }
            if (isset($value['type'])) {
                $content['type'] = $value['type'];
            }
            if (isset($value['object_class'])) {
                $classIdentifier = $this->findClassIdentifier($value['object_class']);
                if (!$classIdentifier) {
                    $classIdentifier = null;
                }
                $content['object_class'] = $classIdentifier;
            }
            if (isset($value['class_constraint_list']) && is_array($value['class_constraint_list'])) {
                $classList = array();
                foreach ($value['class_constraint_list'] as $classDef) {
                    $classIdentifier = $this->findClassIdentifier($classDef);
                    if ($classIdentifier) {
                        $classList[] = $classIdentifier;
                    }
                }
                $classList = array_unique($classList);
                $content['class_constraint_list'] = $classList;
            }
        } else {

            // Let the datatype set the values using class-content value
            // This requires that the datatype actually supports this
            // Data-types known to work for this are:
            $content = $value;
        }
    }

    public function findClassIdentifier($definition)
    {
        if (is_array($definition)) {
            $class = null;
            if (isset($definition['uuid'])) {
                $class = eZContentClass::fetchByRemoteID($definition['uuid']);
                if ($class) {
                    return $class->attribute('identifier');
                }
            }
            if (!$class && isset($definition['identifier'])) {
                return $definition['identifier'];
            }
        } else if (is_string($definition)) {
            return $definition;
        } else if ($definition instanceof eZContentClass) {
            return $definition->attribute('identifier');
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
        } else if ($type == 'ezobjectrelation') {
            $content = $attribute->content();
            return array(
                'selection_type' => $this->objectRelationSelectionTypeMap(Arr::get($content, 'selection_type'), /*getName*/true),
                'default_selection_node' => $this->makeNodeArray(Arr::get($content, 'default_selection_node')),
                'fuzzy_match' => (bool)Arr::get($content, 'fuzzy_match'),
            );
        } else if ($type == 'ezobjectrelationlist') {
            $content = $attribute->content();
            $classList = array();
            $classIdentifierList = Arr::get($content, 'class_constraint_list');
            foreach ($classIdentifierList as $classIdentifier) {
                $class = eZContentClass::fetchByIdentifier($classIdentifier);
                if ($class) {
                    $classList[] = array(
                        'uuid' => $class->remoteID(),
                        'identifier' => $class->attribute('identifier'),
                    );
                }
            }
            $objectClass = Arr::get($content, 'object_class');
            if ($objectClass) {
                $class = eZContentClass::fetchByIdentifier($objectClass);
                if ($class) {
                    $objectClass = array(
                        'uuid' => $class->remoteID(),
                        'identifier' => $class->attribute('identifier'),
                    );
                } else {
                    $objectClass = null;
                }
            } else {
                $objectClass = null;
            }
            return array(
                'selection_type' => $this->objectRelationSelectionTypeMap(Arr::get($content, 'selection_type'), /*getName*/true),
                'default_placement' => $this->makeNodeArray(Arr::get($content, 'default_placement')),
                'type' => Arr::get($content, 'type'),
                'object_class' => $objectClass,
                'class_constraint_list' => $classList,
            );
        } else {
            // Let the datatype set the values using class-content value
            // This requires that the datatype actually supports this
            // Data-types known to work for this are:
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
