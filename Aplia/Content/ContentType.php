<?php
namespace Aplia\Content;

use Aplia\Content\Exceptions\ObjectDoesNotExist;
use Aplia\Content\Exceptions\ObjectAlreadyExist;
use Aplia\Content\Exceptions\UnsetValueError;
use Aplia\Content\Exceptions\TypeError;
use Aplia\Content\Exceptions\ImproperlyConfigured;
use Aplia\Content\ContentTypeAttribute;
use Aplia\Content\ContentObject;
use eZContentClass;

/**
 * @property $contentClass The eZContentClass object that is being referenced or created
 */
class ContentType
{
    public $id;
    public $name;
    public $uuid;
    public $identifier;
    public $contentObjectName;
    public $isContainer;
    public $alwaysAvailable = false;
    public $sortField;
    public $sortOrder;
    public $language = false;
    public $groups = array();
    public $description = null;
    public $version = eZContentClass::VERSION_STATUS_DEFINED;
    public $attributes = array();
    public $attributesNew = array();
    public $attributesRemove = array();
    public $attributesChange = array();
    public $objects = array();

    /**
     * Controls whether the datamap for attributes has been loaded
     * from the DB or not.
     */
    protected $isAttributeMapLoaded = false;

    protected $_contentClass;

    public function __construct($identifier, $name=null, $fields = null)
    {
        $this->identifier = $identifier;
        $this->name = $name ? $name : $identifier;
        if ($fields) {
            $this->set($fields);
        }
    }

    /**
     * Set multiple field values from an array.
     * 
     * e.g.
     * @code
     * $type->set(array(
     *     "name" => "Folder",
     *     "isContainer" => true,
     * ))
     * @endcode
     * 
     * @return self
     */
    public function set($fields = null)
    {
        if ($fields) {
            if (isset($fields['id'])) {
                $this->id = $fields['id'];
            }
            if (isset($fields['name'])) {
                $this->name = $fields['name'];
            }
            if (isset($fields['uuid'])) {
                $this->uuid = $fields['uuid'];
            }
            if (isset($fields['language'])) {
                $this->language = $fields['language'];
            }
            if (isset($fields['groups'])) {
                $this->groups = $fields['groups'];
            }
            if (isset($fields['contentObjectName'])) {
                $this->contentObjectName = $fields['contentObjectName'];
            }
            if (isset($fields['isContainer'])) {
                $this->isContainer = $fields['isContainer'];
            }
            if (isset($fields['alwaysAvailable'])) {
                $this->alwaysAvailable = $fields['alwaysAvailable'];
            }
            if (isset($fields['version'])) {
                $this->version = $fields['version'];
            }
            if (isset($fields['sortField'])) {
                $this->sortField = $fields['sortField'];
            }
            if (isset($fields['sortOrder'])) {
                $this->sortOrder = $fields['sortOrder'];
            }
            if (isset($fields['description'])) {
                $this->description = $fields['description'];
            }
        }
        return $this;
    }

    /**
     * Checks if the content class exists in eZ publish.
     * This requires that either the uuid, id or identifier has been set.
     *
     * @return true if it exists, false otherwise
     */
    public function exists()
    {
        $this->getContentClass(false);
        return $this->_contentClass !== null;
    }

    /**
     * Checks if the attribute with identifier $identifier exists.
     *
     * @throws ObjectDoesNotExist if the content class does not exist
     * @return true if it exists, false otherwise
     */
    public function hasAttribute($identifier)
    {
        if (!$this->exists()) {
            $idText = $this->identifierText();
            throw new ObjectDoesNotExist("$idText does not exist, cannot check if attribute exists");
        }
        // If attribute is meant to be removed, return false
        if (isset($this->attributesRemove[$identifier])) {
            return false;
        }
        if (isset($this->attributesNew[$identifier]) || isset($this->attributes[$identifier])) {
            return true;
        }
        $this->loadAttributeMap();
        return isset($this->attributes[$identifier]);
    }

    /**
     * Checks if the attribute with identifier $identifier exists and has
     * the expected data-type.
     *
     * @throws ObjectDoesNotExist if the content class does not exist
     * @throws ObjectDoesNotExist if the attribute does not exist
     * @throws TypeError if the attribute has the wrong data-type
     * @return true if it exists, false otherwise
     */
    public function checkAttribute($identifier, $type)
    {
        if (!$this->hasAttribute($identifier)) {
            $idText = $this->identifierText();
            throw new ObjectDoesNotExist("$idText does not have attribute with identifier: $identifier");
        }
        $attribute = $this->attributes[$identifier];
        $existingType = $attribute->attribute('data_type_string');
        if ($existingType != $type) {
            throw new TypeError("$idText and attribute with identifier: $identifier has the wrong type $existingType, expected $type");
        }
    }

    /**
     * Returns the ContentTypeAttribute object for the class-attribute with identifier $identifier.
     *
     * @throws ObjectDoesNotExist if the content class does not exist
     * @throws ObjectDoesNotExist if the attribute does not exist
     * @return ContentTypeAttribute
     */
    public function getAttribute($identifier)
    {
        if (!$this->hasAttribute($identifier)) {
            $idText = $this->identifierText();
            throw new ObjectDoesNotExist("$idText does not have attribute with identifier: $identifier");
        }
        if (isset($this->attributesNew[$identifier])) {
            $attr = $this->attributesNew[$identifier];
            if (!($attr instanceof ContentTypeAttribute)) {
                $attr = new ContentTypeAttribute($attr['identifier'], $attr['type'], $attr['name'], $attr);
            }
            return $attr;
        }
        return $this->attributes[$identifier];
    }

    /**
     * Returns the eZContentClassAttribute object for the identifier $identifier.
     * 
     * @return eZContentClassAttribute
     */
    public function classAttribute($identifier)
    {
        $typeAttribute = $this->getAttribute($identifier);
        return $typeAttribute->classAttribute;
    }

    /**
     * Reset any pending attributes to be created or removed.
     */
    public function resetPending()
    {
        $this->attributesNew = array();
        $this->attributesRemove = array();
        $this->attributesChange = array();
    }

    /**
     * Create the content-class in eZ publish and return the class instance.
     *
     * @throws ObjectAlreadyExist if the content-class already exists.
     */
    public function create()
    {
        if ($this->isContainer === null) {
            $this->isContainer = false;
        }
        $fields = array(
            'name' => $this->name,
            'contentobject_name' => $this->contentObjectName,
            'identifier' => $this->identifier,
            'is_container' => $this->isContainer,
            'always_available' => $this->alwaysAvailable,
            'version' => $this->version,
        );
        if ($this->sortField !== null) {
            $fields['sort_field'] = $this->sortField;
        }
        if ($this->sortOrder !== null) {
            $fields['sort_order'] = $this->sortOrder;
        }
        $existing = null;
        if ($this->uuid) {
            $existing = eZContentClass::fetchByRemoteID($this->uuid);
            if ($existing) {
                throw new ObjectAlreadyExist("Content Class with UUID: '$this->uuid' already exists, cannot create");
            }
            $fields['remote_id'] = $this->uuid;
        }
        if (!$existing && $this->id) {
            $existing = eZContentClass::fetchObject($this->id);
            if ($existing) {
                throw new ObjectAlreadyExist("Content Class with ID: '$this->id' already exists, cannot create");
            }
            $fields['id'] = $this->id;
        }
        if (!$existing) {
            $existing = eZContentClass::fetchByIdentifier($this->identifier);
            if ($existing) {
                throw new ObjectAlreadyExist("Content Class with identifier: '$this->identifier' already exists, cannot create");
            }
        }
        $language = $this->language;
        $this->_contentClass = eZContentClass::create(false, $fields, $language);
        if ($this->description !== null) {
            $this->_contentClass->setDescription($this->description);
        }
        $this->_contentClass->store();

        foreach ($this->attributesNew as $attr) {
            $this->createAttribute($attr);
        }
        $this->attributesNew = array();
        $this->attributesRemove = array();

        if ($this->groups) {
            foreach ($this->groups as $idx => $group) {
                if ($group instanceof \eZContentClassClassGroup) {
                    continue;
                } elseif ($group instanceof \eZContentClassGroup) {
                } elseif (is_numeric($group)) {
                    $group = \eZContentClassGroup::fetch($group);
                    if (!$group) {
                        throw new ObjectDoesNotExist("Could not fetch eZ Content Class Group with ID '$group'");
                    }
                } else {
                    $group = \eZContentClassGroup::fetchByName($group);
                    if (!$group) {
                        throw new ObjectDoesNotExist("Could not fetch eZ Content Class Group with name '$group'");
                    }
                }

                $relation = \eZContentClassClassGroup::create(
                    $this->_contentClass->attribute('id'), $this->_contentClass->attribute('version'),
                    $group->attribute('id'), $group->attribute('name')
                );
                $relation->store();
                $this->groups[$idx] = $relation;
            }
        }

        $this->isAttributeMapLoaded = true;

        return $this->_contentClass;
    }

    /**
     * Removes the content-class from the eZ publish.
     * If $allowNoExists is true it will not throw an exception if it does not
     * exist, but rather return false.
     *
     * @param $allowNoExists Whether to require the content-class existing or not.
     * @throws ObjectDoesNotExist if the content-class does not exist.
     */
    public function remove($allowNoExists=false)
    {
        if (!$this->_contentClass) {
            if ($this->id) {
                $this->_contentClass = eZContentClass::fetch($this->id);
                if (!$this->_contentClass) {
                    if ($allowNoExists) {
                        return false;
                    }
                    throw new ObjectDoesNotExist("Failed to fetch eZ Content Class with ID '{$this->id}'");
                }
            } elseif ($this->identifier) {
                $this->_contentClass = eZContentClass::fetchByIdentifier($this->identifier);
                if (!$this->_contentClass) {
                    if ($allowNoExists) {
                        return false;
                    }
                    throw new ObjectDoesNotExist("Failed to fetch eZ Content Class with identifier '{$this->identifier}'");
                }
            } else {
                throw new UnsetValueError("No ID or identifier set for eZ Content Class");
            }
        }
        if ($this->_contentClass) {
            $this->_contentClass->remove(true, $this->version);
            $this->_contentClass = null;
            $this->isAttributeMapLoaded = false;
            $this->attributesNew = array();
            $this->attributesRemove = array();
            return true;
        }
        return false;
    }

    /**
     * Updates content-class in database and creates any new attributes.
     * 
     * @return self
     */
    public function update()
    {
        $contentClass = $this->getContentClass();

        foreach ($this->attributesNew as $attrData) {
            $attr = $this->createAttribute($attrData);
            $this->attributes[$attr->identifier] = $attr;
        }
        foreach ($this->attributesRemove as $identifier => $attrData) {
            $classAttribute = $contentClass->fetchAttributeByIdentifier($identifier);
            $classAttribute->removeThis();
            unset($this->attributes[$identifier]);
        }
        $this->attributesNew = array();
        $this->attributesRemove = array();
        return $this;
    }

    /**
     * Saves the changes to class and attributes back to database.
     * 
     * @return self
     */
    public function save()
    {
        return $this->update();
    }

    /**
     * Adds a new attribute to the content-type.
     *
     * This method can be called with different amount of parameters.
     *
     * With one parameter it expects an associative array, the array
     * contains all the parameters by name.
     *
     * With two parameters, the first is the type, and the second is
     * an associative array with the rest of the named parameters.
     *
     * With three parameters, the first is the type, the second is
     * the identifier, and the third is an associative array with
     * the rest of the named parameters.
     *
     * With four parameters, the first is the type, the second is
     * the identifier, the third is the name, and the fourth is an
     * associative array with the rest of the named parameters.
     *
     * Note: The attribute will only be created when the content-class is created.
     *
     * @return self
     */
    public function addAttribute($type, $identifier=null, $name=null, $attr=null)
    {
        $argc = func_num_args();
        if ($argc == 1) {
            if (is_array($type)) {
                $attr = $type;
                $type = $attr['type'];
                $identifier = $attr['identifier'];
                $name = $attr['name'];
            } else {
                throw new ImproperlyConfigured("Required fields 'name' and 'identifier' not specified");
            }
        } else if ($argc == 2) {
            if (is_array($identifier)) {
                $attr = $identifier;
                $identifier = $attr['identifier'];
                $name = $attr['name'];
            } else {
                throw new ImproperlyConfigured("Required field 'name' not specified");
            }
        } else if ($argc == 3) {
            if (is_array($name)) {
                $attr = $name;
                $name = $attr['name'];
            } else {
                $attr = array();
            }
        } else {
            if (!$attr) {
                $attr = array();
            }
            if ($name === null) {
                $name = $attr['name'];
            }
            if ($identifier === null) {
                $identifier = $attr['identifier'];
            }
        }
        if (!$attr instanceof ContentTypeAttribute) {
            if (!isset($attr['language'])) {
                $attr['language'] = $this->language;
            }
            $attr = new ContentTypeAttribute($identifier, $type, $name, $attr);
        }
        $this->attributesNew[$attr->identifier] = $attr;
        unset($this->attributesRemove[$identifier]);
        return $this;
    }

    /**
     * Removes a content-class attribute with identifier $identifier by scheduling
     * for the next call to save() or update().
     * 
     * @return self
     */
    public function removeAttribute($identifier)
    {
        $typeAttribute = null;
        if ($identifier instanceof ContentTypeAttribute) {
            $typeAttribute = $identifier;
            $identifier = $typeAttribute->identifier;
        }
        if (isset($this->attributesRemove[$identifier])) {
            // Already scheduled for removal
            return $this;
        }
        unset($this->attributesNew[$identifier]);
        $this->attributesRemove[$identifier] = array();
        return $this;
    }

    /**
     * Creates the class-attribute in the DB and updates the internal attribute map.
     * $attr is either an array with key/value for the attribute or an ContentObjectAttribute.
     */
    public function createAttribute($attr)
    {
        if (!$attr instanceof ContentTypeAttribute) {
            $attr = new ContentTypeAttribute($attr['identifier'], $attr['type'], $attr['name'], $attr);
        }
        $classAttribute = $attr->create($this->_contentClass);
        $this->attributes[$classAttribute->attribute('identifier')] = $classAttribute;
        return $attr;
    }

    /**
     * Deletes a content-class attribute from database and removes any scheduled removals.
     *
     * Note: This removes the attribute directly.
     * 
     * @return self
     */
    public function deleteAttribute($identifier)
    {
        $typeAttribute = null;
        if ($identifier instanceof ContentTypeAttribute) {
            $typeAttribute = $identifier;
            $identifier = $typeAttribute->identifier;
        }
        unset($this->attributesRemove[$identifier]);
        unset($this->attributesNew[$identifier]);
        $contentClass = $this->getContentClass();
        $classAttribute = $contentClass->fetchAttributeByIdentifier($identifier);
        $classAttribute->removeThis();
        return $this;
    }

    /**
     * Returns dynamic properties
     * 
     * - contentClass - Returns the content-class this object refers to.
     * 
     * @return self
     */
    public function __get($name)
    {
        if ($name == "contentClass") {
            return $this->getContentClass();
        }
        throw Exception("Property $name does not exist");
    }

    /**
     * Returns the content-class object.
     * If it does not exist in memory it is then fetched from DB using
     * either the uuid (remote id), id or identifier.
     * 
     * @param $ensureExists If true it throws an exception if it does not exist, otherwise returns null.
     * 
     * @throws ObjectDoesNotExist if the content-class could not be fetched from DB
     */
    public function getContentClass($ensureExists=true)
    {
        if (!$this->_contentClass) {
            $contentClass = null;
            if ($this->uuid) {
                $contentClass = eZContentClass::fetchByRemoteID($this->uuid);
            }
            if (!$contentClass && $this->id) {
                $contentClass = eZContentClass::fetchObject($this->id);
            }
            if (!$contentClass && $this->identifier) {
                $contentClass = eZContentClass::fetchByIdentifier($this->identifier);
            }
            if (!$contentClass && $ensureExists) {
                throw new ObjectDoesNotExist("No Content Class could be fetched using uuid, id or identifier");
            }
            $this->_contentClass = $contentClass;
        }
        return $this->_contentClass;
    }

    /**
     * Returns a proxy object (ContentObject) for a new content-object based on this
     * content-class. The actual content-object is not created and may
     * even exist in the DB.
     */
    public function contentObject($params)
    {
        $params['identifier'] = $this->identifier;
        $params['contentClass'] = $this->getContentClass();
        $object = new ContentObject($params);
        return $object;
    }

    /**
     * Returns a text string uniquely identifying the content class.
     */
    public function identifierText()
    {
        if ($this->uuid) {
            $idText = "with UUID: $this->uuid";
        } else if ($this->id) {
            $idText = "with ID: $this->id";
        } else if ($this->identifier) {
            $idText = "with identifier: $this->identifier";
        } else {
            return "Unknown Content Class";
        }
        return "Content Class $idText";
    }

    /**
     * Ensures that the attribute map is complete, if incomplete loads the attributes
     * from the DB.
     */
    protected function loadAttributeMap()
    {
        if ($this->isAttributeMapLoaded) {
            return;
        }
        $this->getContentClass();
        $dataMap = $this->_contentClass->dataMap();
        foreach ($dataMap as $identifier => $classAttribute) {
            $attr = new ContentTypeAttribute($attr->attribute('identifier'), $attr->attribute('data_type_string'), $attr->attribute('name'));
            $attr->classAttribute = $classAttribute;
            $this->attributes[$identifier] = $attr;
        }
        $this->isAttributeMapLoaded = true;
    }
}
