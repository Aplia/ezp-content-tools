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

class ContentType
{
    public $id;
    public $name;
    public $uuid;
    public $identifier;
    public $contentObjectName;
    public $isContainer = false;
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

    public $contentClass;

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
    }

    /**
     * Checks if the content class exists in eZ publish.
     * This requires that either the uuid, id or identifier has been set.
     *
     * @return true if it exists, false otherwise
     */
    public function exists()
    {
        if ($this->contentClass === null) {
            $existing = null;
            if ($this->uuid) {
                $existing = eZContentClass::fetchByRemoteID($this->uuid);
                if (!$existing) {
                    return false;
                }
            }
            if (!$existing && $this->id) {
                $existing = eZContentClass::fetchObject($this->id);
                if (!$existing) {
                    return false;
                }
            }
            if (!$existing) {
                if (!$this->identifier) {
                    throw new ImproperlyConfigured("No id, uuid or identifier has been set, cannot check for existance");
                }
                $existing = eZContentClass::fetchByIdentifier($this->identifier);
                if (!$existing) {
                    return false;
                }
            }
            $this->contentClass = $existing;
        }
        return true;
    }

    /**
     * Checks if the attribute with identifier $identifier exists.
     *
     * @throw ObjectDoesNotExist if the content class does not exist
     * @return true if it exists, false otherwise
     */
    public function hasAttribute($identifier)
    {
        if (!$this->exists()) {
            $idText = $this->identifierText();
            throw new ObjectDoesNotExist("$idText does not exist, cannot check if attribute exists");
        }
        $dataMap = $this->contentClass->dataMap();
        return isset($dataMap[$identifier]);
    }

    /**
     * Checks if the attribute with identifier $identifier exists and has
     * the expected data-type.
     *
     * @throw ObjectDoesNotExist if the content class does not exist
     * @throw ObjectDoesNotExist if the attribute does not exist
     * @throw TypeError if the attribute has the wrong data-type
     * @return true if it exists, false otherwise
     */
    public function checkAttribute($identifier, $type)
    {
        if (!$this->hasAttribute($identifier)) {
            $idText = $this->identifierText();
            throw new ObjectDoesNotExist("$idText does not have attribute with identifier: $identifier");
        }
        $dataMap = $this->contentClass->dataMap();
        $attribute = $dataMap[$identifier];
        $existingType = $attribute->attribute('data_type_string');
        if ($existingType != $type) {
            throw new TypeError("$idText and attribute with identifier: $identifier has the wrong type $existingType, expected $type");
        }
    }

    public function resetPending()
    {
        $this->attributesNew = array();
        $this->attributesRemove = array();
        $this->attributesChange = array();
    }

    /**
     * Create the content-class in eZ publish and return the class instance.
     *
     * @throw ObjectAlreadyExist if the content-class already exists.
     */
    public function create()
    {
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
        $this->contentClass = eZContentClass::create(false, $fields, $language);
        if ($this->description !== null) {
            $this->contentClass->setDescription($this->description);
        }
        $this->contentClass->store();

        foreach ($this->attributesNew as $attr) {
            $this->createAttribute($attr);
        }

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
                    $this->contentClass->attribute('id'), $this->contentClass->attribute('version'),
                    $group->attribute('id'), $group->attribute('name')
                );
                $relation->store();
                $this->groups[$idx] = $relation;
            }
        }

        return $this->contentClass;
    }

    /**
     * Removes the content-class from the eZ publish.
     * If $allowNoExists is true it will not throw an exception if it does not
     * exist, but rather return false.
     *
     * @param $allowNoExists Whether to require the content-class existing or not.
     * @throw ObjectDoesNotExist if the content-class does not exist.
     */
    public function remove($allowNoExists=false)
    {
        if (!$this->contentClass) {
            if ($this->id) {
                $this->contentClass = eZContentClass::fetch($this->id);
                if (!$this->contentClass) {
                    if ($allowNoExists) {
                        return false;
                    }
                    throw new ObjectDoesNotExist("Failed to fetch eZ Content Class with ID '{$this->id}'");
                }
            } elseif ($this->identifier) {
                $this->contentClass = eZContentClass::fetchByIdentifier($this->identifier);
                if (!$this->contentClass) {
                    if ($allowNoExists) {
                        return false;
                    }
                    throw new ObjectDoesNotExist("Failed to fetch eZ Content Class with identifier '{$this->identifier}'");
                }
            } else {
                throw new UnsetValueError("No ID or identifier set for eZ Content Class");
            }
        }
        if ($this->contentClass) {
            $this->contentClass->remove(true, $this->version);
            $this->contentClass = null;
            return true;
        }
        return false;
    }

    public function update()
    {
        $existing = null;
        if ($this->uuid) {
            $existing = eZContentClass::fetchByRemoteID($this->uuid);
            if (!$existing) {
                throw new ObjectDoesNotExist("Content Class with UUID: '$this->uuid' does not exist, cannot update");
            }
        }
        if (!$existing && $this->id) {
            $existing = eZContentClass::fetchObject($this->id);
            if (!$existing) {
                throw new ObjectDoesNotExist("Content Class with ID: '$this->id' does not exist, cannot update");
            }
        }
        if (!$existing) {
            $existing = eZContentClass::fetchByIdentifier($this->identifier);
            if (!$existing) {
                throw new ObjectAlreadyExist("Content Class with identifier: '$this->identifier' does not exist, cannot update");
            }
        }
        $this->contentClass = $existing;

        foreach ($this->attributesNew as $attr) {
            $this->attributes[] = $this->createAttribute($attr);
        }
        $this->attributesNew = array();
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
     * @return This instance, allows for chaining multiple calls.
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
        $this->attributesNew[] = $attr;
        return $this;
    }

    /**
     * Removes a content-class attribute with identifier $identifier.
     *
     * Note: This removes the attribute directly.
     */
    public function removeAttribute($identifier)
    {
        if (!$this->contentClass) {
            if ($this->id) {
                $this->contentClass = eZContentClass::getch($this->id);
                if (!$this->contentClass) {
                    throw new ObjectDoesNotExist("Failed to fetch eZ Content Class with ID '{$this->id}'");
                }
            } elseif ($this->identifier) {
                $this->contentClass = eZContentClass::fetchByIdentifier($this->identifier);
                if (!$this->contentClass) {
                    throw new ObjectDoesNotExist("Failed to fetch eZ Content Class with identifier '{$this->identifier}'");
                }
            } else {
                throw new UnsetValueError("No ID or identifier set for eZ Content Class");
            }
        }
        $classAttribute = $this->contentClass->fetchAttributeByIdentifier($identifier);
    }

    public function createAttribute($attr)
    {
        if (!$attr instanceof ContentTypeAttribute) {
            $attr = new ContentTypeAttribute($attr['identifier'], $attr['type'], $attr['name'], $attr);
        }
        $attr->create($this->contentClass);
        return $attr;
    }

    public function getContentClass()
    {
        if (!$this->contentClass) {
            $contentClass = null;
            if ($this->uuid) {
                $contentClass = eZContentClass::fetchByRemoteID($this->uuid);
                if (!$contentClass) {
                    throw new ObjectDoesNotExist("No Content Class with UUID: '$this->uuid'");
                }
            }
            if (!$contentClass && $this->id) {
                $contentClass = eZContentClass::fetchObject($this->id);
                if (!$contentClass) {
                    throw new ObjectDoesNotExist("No Content Class with ID: '$this->id'");
                }
            }
            if (!$contentClass && $this->identifier) {
                $contentClass = eZContentClass::fetchByIdentifier($this->identifier);
                if (!$contentClass) {
                    throw new ObjectDoesNotExist("No Content Class with identifier: '$this->identifier'");
                }
            }
            if (!$contentClass) {
                throw new ObjectDoesNotExist("No Content Class could be fetched");
            }
            $this->contentClass = $contentClass;
        }
        return $this->contentClass;
    }

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
}
