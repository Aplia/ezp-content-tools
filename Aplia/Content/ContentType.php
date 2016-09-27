<?php
namespace Aplia\Content;

use Aplia\Content\Exceptions\ObjectDoesNotExist;
use Aplia\Content\Exceptions\ObjectAlreadyExist;
use Aplia\Content\Exceptions\UnsetValueError;
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
    public $groups = array();
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
            if (isset($fields['id'])) {
                $this->id = $fields['id'];
            }
            if (isset($fields['uuid'])) {
                $this->uuid = $fields['uuid'];
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
        }
    }

    public function resetPending()
    {
        $this->attributesNew = array();
        $this->attributesRemove = array();
        $this->attributesChange = array();
    }

    public function create()
    {
        $fields = [
            'name' => $this->name,
            'contentobject_name' => $this->contentobjectName,
            'identifier' => $this->identifier,
            'is_container' => $this->isContainer,
            'always_available' => $this->alwaysAvailable,
            'version' => $this->version,
        ];
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
        $this->contentClass = eZContentClass::create(false, $fields);
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
    }

    public function remove()
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
        if ($this->contentClass) {
            $this->contentClass->remove(true, $this->version);
        }
    }

    public function update()
    {
        foreach ($this->attributesNew as $attr) {
            $this->attributes[] = $this->createAttribute($attr);
        }
        $this->attributesNew = array();
    }

    public function addAttribute($type, $identifier=null, $name=null, $attr=null)
    {
        $argc = func_num_args();
        if ($argc == 1) {
            $attr = $type;
            $type = $attr['type'];
            $identifier = $attr['identifier'];
            $name = $attr['name'];
        } else if ($argc == 2) {
            $attr = $identifier;
            $identifier = $attr['identifier'];
            $name = $attr['name'];
        } else if ($argc == 3) {
            $attr = $name;
            $name = $attr['name'];
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
            $attr = new ContentTypeAttribute($identifier, $type, $name, $attr);
        }
        $this->attributesNew[] = $attr;
        return $this;
    }

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
}
