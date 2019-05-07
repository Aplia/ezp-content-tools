<?php
namespace Aplia\Content;

use Exception;
use Aplia\Content\Exceptions\ObjectAlreadyExist;
use Aplia\Content\Exceptions\UnsetValueError;
use Aplia\Content\Exceptions\ValueError;
use Aplia\Content\Exceptions\ObjectDoesNotExist;
use Aplia\Support\Arr;
use SimpleXMLElement;
use eZPersistentObject;
use eZContentClass;
use eZContentClassAttribute;
use eZContentObjectAttribute;
use eZContentObjectTreeNode;

/**
 * Defines an attribute for a content-class, either a new one or for an existing.
 * 
 * The following properties may be set with the constructor:
 * - type - The data-type for the attribute, e.g. 'ezstring'
 * - identifier - The unique (per class) identifier for the attribute, e.g. 'title'
 * - value - A value or values to set for attribute, value type depends on 'type' used
 * - id - ID of existing attribute to update, or null to create new
 * - language - The language to use for new or updated attribute
 * - name - Name of attribute in specified language
 * - description - Description of attribute in specified language
 * - isRequired - If true then the object attribute must be filled in by user
 * - isSearchable - If true then the object contents may be found when searching
 * - isInformationCollector - Whether object attribute is used for information-collector system or not
 * - canTranslate - If true then object attribute may be translated
 * - placeAfter - Identifier of attribute, new attribute is placed after this.
 * - placeBefore - Identifier of attribute, new attribute is placed before this.
 */
class ContentTypeAttribute
{
    public $name;
    public $type;
    public $identifier;
    public $value;
    public $id;
    public $description;
    public $category;
    /**
     * If set places the new attribute after this attribute, specified with an identifier
     */
    public $placeAfter;
    /**
     * If set places the new attribute before this attribute, specified with an identifier
     */
    public $placeBefore;
    public $language;
    public $isRequired;
    public $isSearchable;
    public $isInformationCollector;
    public $canTranslate;

    public $classAttribute;

    protected $objectTransform;

    public function __construct($identifier, $type, $name, $fields = null)
    {
        $this->identifier = $identifier;
        $this->name = $name;
        $this->type = $type;
        if ($fields) {
            if (isset($fields['classAttribute'])) {
                $this->classAttribute = $fields['classAttribute'];
            }
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
            if (isset($fields['category'])) {
                $this->category = $fields['category'];
            }
            if (isset($fields['language'])) {
                $this->language = $fields['language'];
            }
            if (isset($fields['placeAfter'])) {
                $this->placeAfter = $fields['placeAfter'];
            } else if (isset($fields['placeBefore'])) {
                $this->placeBefore = $fields['placeBefore'];
            }
            $this->objectTransform = Arr::get($fields, 'objectTransform');
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
            'name' => $name,
            'version' => $contentClass->attribute('version'),
            'is_required' => $this->isRequired !== null ? $this->isRequired : false,
            'is_searchable' => $this->isSearchable !== null ? $this->isSearchable : true,
            'can_translate' => $this->canTranslate !== null ? $this->canTranslate : true,
            'is_information_collector' => $this->isInformationCollector !== null ? $this->isInformationCollector : false,
            'category' => $this->category !== null ? $this->category : '',
        );
        if ($this->id) {
            $existing = eZContentClassAttribute::fetchObject($this->id);
            if ($existing) {
                throw new ObjectAlreadyExist("Content Class Attribute with ID: '$this->id' already exists, cannot create");
            }
            $fields['id'] = $this->id;
        }

        $content = null;
        $this->setAttributeFields($fields, $content, $contentClass);

        $attribute = eZContentClassAttribute::create($contentClass->attribute('id'), $this->type, $fields, $this->language);
        $this->setName($name, $attribute);

        if ($this->description !== null) {
            $this->setDescription($this->description, $attribute);
        }

        if ($this->placeAfter) {
            $attributes = $contentClass->fetchAttributes();
            $placement = null;
            $adjustedPlacement = 1;
            // Reassign placement values to all attributes while leaving a gap for the new attribute
            foreach ($attributes as $existingAttribute) {
                $existingAttribute->setAttribute('placement', $adjustedPlacement);
                $existingAttribute->sync(array('placement'));
                if ($placement === null && $existingAttribute->attribute('identifier') === $this->placeAfter) {
                    // Use next placement for the new attribute and skip one entry for existing
                    $adjustedPlacement = $adjustedPlacement + 1;
                    $placement = $adjustedPlacement;
                }
                $adjustedPlacement = $adjustedPlacement + 1;
            }
            // If the specified attribute was not found, place it after the last one
            if ($placement === null) {
                $placement = $adjustedPlacement;
            }
        } else if ($this->placeBefore) {
            $attributes = $contentClass->fetchAttributes();
            $placement = null;
            $adjustedPlacement = 1;
            // Reassign placement values to all attributes while leaving a gap for the new attribute
            foreach ($attributes as $existingAttribute) {
                if ($placement === null && $existingAttribute->attribute('identifier') === $this->placeBefore) {
                    // Use current placement for the new attribute and skip one entry for existing
                    $placement = $adjustedPlacement;
                    $adjustedPlacement = $adjustedPlacement + 1;
                }
                $existingAttribute->setAttribute('placement', $adjustedPlacement);
                $existingAttribute->sync(array('placement'));
                $adjustedPlacement = $adjustedPlacement + 1;
            }
            // If the specified attribute was not found, place it after the last one
            if ($placement === null) {
                $placement = $adjustedPlacement;
            }
        } else {
            // Figure out next placement ID, the one calculated by eZ publish
            // is wrong since it assumes that the version is 1
            $placement = eZPersistentObject::newObjectOrder(
                eZContentClassAttribute::definition(),
                'placement',
                array(
                    'version' => $contentClass->attribute('version'),
                    'contentclass_id' => $contentClass->attribute('id'),
                )
            );
        }
        $this->classAttribute = $attribute;
        $dataType = $attribute->dataType();
        $dataType->initializeClassAttribute($attribute);
        $attribute->setAttribute('placement', $placement);
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

        // Now create all object attributes
        $objects = null;
        $attribute->initializeObjectAttributes($objects);

        return $attribute;
    }

    /**
     * A local function which sets the attribute name on the class attribute for the prioritized language
     * and all untranslated languages, to avoid empty ('') fallbacks. For a specific locale, use createTranslation(),
     * or the class attribute functions directly (eZContentClassAttribute instance)
     */
    public function setName($name, $attribute)
    {
        if ($attribute instanceof eZContentClassAttribute) {
            $attribute->setName($name);
            $untranslatedLanguagesNames = $attribute->NameList->untranslatedLanguages();
            foreach ($untranslatedLanguagesNames as $locale => $languageObject) {
                $attribute->setName($name, $locale);
            }
        }
    }

    /**
     * A local function which sets the attribute description on the class attribute for the prioritized language
     * and all untranslated languages, to avoid empty ('') fallbacks. For a specific locale, use createTranslation(),
     * or the class attribute functions directly (eZContentClassAttribute instance)
     */
    public function setDescription($description, $attribute)
    {
        if ($attribute instanceof eZContentClassAttribute) {
            $attribute->setDescription($this->description);
            $untranslatedLanguagesDescriptions = $attribute->DescriptionList->untranslatedLanguages();
            foreach ($untranslatedLanguagesDescriptions as $locale => $languageObject) {
                $attribute->setDescription($this->description, $locale);
            }
        }
    }

    public function update($contentClass)
    {
        $name = $this->name;
        $identifier = $this->identifier;
        $attribute = $this->classAttribute;

        $fields = array();
        if ($this->isRequired !== null) {
            $fields['is_required'] = $this->isRequired;
        }
        if ($this->isSearchable !== null) {
            $fields['is_searchable'] = $this->isSearchable;
        }
        if ($this->canTranslate !== null) {
            $fields['can_translate'] = $this->canTranslate;
        }
        if ($this->isInformationCollector !== null) {
            $fields['is_information_collector'] = $this->isInformationCollector;
        }
        if ($this->category !== null) {
            $fields['category'] = $this->category;
        }

        // If type is not changed then load from the class attribute
        if ($this->type === null) {
            $this->type = $oldType = $attribute->attribute('data_type_string');
        } else {
            $oldType = $attribute->attribute('data_type_string');
        }

        $content = null;
        $this->setAttributeFields($fields, $content, $contentClass);

        // Write a new type if it differs from the old type, object attributes
        // will be updated later on in function
        if ($oldType !== null && $oldType !== $this->type) {
            $fields['data_type_string'] = $this->type;
        }
        foreach ($fields as $attributeName => $attributeValue) {
            $attribute->setAttribute($attributeName, $attributeValue);
        }
        if ($name !== null) {
            $this->setName($name, $attribute);
        }

        if ($this->description !== null) {
            $this->setDescription($description, $attribute);
        }
        $placement = null;
        if ($this->placeAfter) {
            $attributes = $contentClass->fetchAttributes();
            $placement = null;
            $adjustedPlacement = 1;
            // Reassign placement values to all attributes while leaving a gap for the new attribute
            foreach ($attributes as $existingAttribute) {
                $existingAttribute->setAttribute('placement', $adjustedPlacement);
                $existingAttribute->sync(array('placement'));
                if ($placement === null && $existingAttribute->attribute('identifier') === $this->placeAfter) {
                    // Use next placement for the new attribute and skip one entry for existing
                    $adjustedPlacement = $adjustedPlacement + 1;
                    $placement = $adjustedPlacement;
                }
                $adjustedPlacement = $adjustedPlacement + 1;
            }
            // If the specified attribute was not found, place it after the last one
            if ($placement === null) {
                $placement = $adjustedPlacement;
            }
        } else if ($this->placeBefore) {
            $attributes = $contentClass->fetchAttributes();
            $placement = null;
            $adjustedPlacement = 1;
            // Reassign placement values to all attributes while leaving a gap for the new attribute
            foreach ($attributes as $existingAttribute) {
                if ($placement === null && $existingAttribute->attribute('identifier') === $this->placeBefore) {
                    // Use current placement for the new attribute and skip one entry for existing
                    $placement = $adjustedPlacement;
                    $adjustedPlacement = $adjustedPlacement + 1;
                }
                $existingAttribute->setAttribute('placement', $adjustedPlacement);
                $existingAttribute->sync(array('placement'));
                $adjustedPlacement = $adjustedPlacement + 1;
            }
            // If the specified attribute was not found, place it after the last one
            if ($placement === null) {
                $placement = $adjustedPlacement;
            }
        }
        $this->classAttribute = $attribute;
        if ($placement !== null) {
            $attribute->setAttribute('placement', $placement);
        }
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

        // Since the type has changed on the class attribute and object
        // attributes with this type needs to change
        if ($oldType !== null && $oldType !== $this->type) {
            $newType = $this->type;
            $attributeId = $attribute->attribute('id');
            // Update type for existing attributes
            $db = \eZDB::instance();
            $newTypeSql = "'" . $db->escapeString($newType) . "'";
            $db->query("UPDATE ezcontentobject_attribute SET data_type_string=$newTypeSql WHERE contentclassattribute_id=${attributeId}");

            if ($this->objectTransform) {
                $this->transformObjectAttributes($contentClass, $oldType, $newType);
            } else {
                // No transform defined, assume that the content does not have to change
            }
        }

        return $attribute;
    }

    /**
     * Goes over all object attributes using the current class attribute
     * and applies the transform function on each one.
     * The transform function receives object attribute, class attribute
     * , class, old type and new type as parameters. The function must
     * store the object attribute if it has changed.
     */
    protected function transformObjectAttributes($contentClass, $oldType, $newType)
    {
        $attribute = $this->classAttribute;
        $conditions = array(
            "contentclassattribute_id" => $attribute->attribute('id'),
        );
        $chunkSize = 100;
        $limit = array(
            'offset' => 0,
            'limit' => $chunkSize,
        );
        $objectTransform = $this->objectTransform;
        // Fetch all object attributes using this class-attribute, fetch
        // in chunks 100 at a time
        while (true) {
            $attributes = eZPersistentObject::fetchObjectList(
                 eZContentObjectAttribute::definition(),
                 /*fields*/null,
                 /*conds*/$conditions,
                 /*sorts*/null,
                 $limit,
                 true);
            if (!$attributes) {
                break;
            }
            foreach ($attributes as $objectAttribute) {
                $objectTransform($objectAttribute, $attribute, $contentClass, $oldType, $newType);
            }
            $limit['offset'] += $chunkSize;
        }
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

    public function makeSelection2Xml(array $value)
    {
        $xmlObj = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><content/>');

        if (isset($value['delimiter'])) {
            $d = $xmlObj->addChild('delimiter');
            $node = dom_import_simplexml($d);
            $no = $node->ownerDocument;
            $node->appendChild($no->createCDATASection($value['delimiter']));
        }

        if (isset($value['is_checkbox'])) {
            $xmlObj->addChild('checkbox')[0] = $value['is_checkbox'];
        }

        if (isset($value['is_multiselect'])) {
            $xmlObj->addChild('multiselect')[0] = $value['is_multiselect'];
        }

        if (isset($value['options']) && $value['options']) {
            $options = $value['options'];
            $optionsXML = $xmlObj->addChild('options');
            foreach ($options as $option) {
                $optionXML = $optionsXML->addChild('option');
                $optionXML->addAttribute('identifier', $option['identifier']);
                $optionXML->addAttribute('name', $option['name']);
                $optionXML->addAttribute('value', $option['is_selected']);
            }
        }

        if (isset($value['use_identifier_name_pattern'])) {
            $xmlObj->addChild('use_identifier_name_pattern')[0] = $value['use_identifier_name_pattern'];
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
        } else if ($type == 'ezselection2') {
            if (is_array($value)) {
                $fields['data_text5'] = $this->makeSelection2Xml($value);
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
        } else if ($type == 'ezselection2') {
            $fields = $attribute->content();
            if (is_array($fields['options']) and $fields['options']) {
                $_options = [];
                foreach ($fields['options'] as $o) {
                    $o['is_selected'] = $o['value'];
                    unset($o['value']);
                    $_options[] = $o;
                }
                $fields['options'] = $_options;
            }
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
