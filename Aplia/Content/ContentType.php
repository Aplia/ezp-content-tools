<?php
namespace Aplia\Content;

use DateTime;
use Aplia\Support\Arr;
use Aplia\Content\Exceptions\ObjectDoesNotExist;
use Aplia\Content\Exceptions\ObjectAlreadyExist;
use Aplia\Content\Exceptions\UnsetValueError;
use Aplia\Content\Exceptions\TypeError;
use Aplia\Content\Exceptions\ImproperlyConfigured;
use Aplia\Content\ContentTypeAttribute;
use Aplia\Content\ContentObject;
use eZContentClass;
use eZContentClassGroup;
use eZContentClassClassGroup;
use eZContentObject;
use eZContentObjectTreeNode;
use eZINI;

/**
 * Wrapper around eZContentClass to make it easier to create and modify content classes.
 * 
 * Ownership can be modified with 'ownerId', 'ownerUuid' or 'ownerIdentifier' parameter which
 * references the user object that should own it.
 * 
 * @property $contentClass The eZContentClass object that is being referenced or created
 * @property $attributes All defined attributes for new class or existing attributs for existing class.
 */
class ContentType
{
    public $id;
    public $name;
    public $uuid;
    public $identifier;
    public $created;
    public $contentObjectName;
    public $urlAliasName;
    public $isContainer;
    public $alwaysAvailable = false;
    public $sortField;
    public $sortOrder;
    public $language = false;
    public $groups = array();
    /**
     * Set a new owner for content class, or null to leave.
     * 
     * Owner is an ID of user object.
     */
    public $ownerId;
    /**
     * Controls whether existing groups are removed before adding new ones.
     */
    public $groupReset = false;
    public $description = null;
    public $version = eZContentClass::VERSION_STATUS_DEFINED;
    public $_attributes = array();
    public $attributesNew = array();
    public $attributesRemove = array();
    public $attributesChange = array();
    public $objects = array();
    /**
     * Pending translations, the key is the language and the value is an array with attributes to translate on class.
     * If it contains 'remove' => true then the translation is removed.
     */
    public $translations = array();

    /**
     * Set to true too see extra debug messages.
     */
    public $debug = false;
    /**
     * Object that is responsible for writing debug messages, must have a write() method.
     * If null debug is written to stderr
     */
    public $debugWriter;

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
            if (isset($fields['created'])) {
                $created = $fields['created'];
                if (!($created instanceof DateTime || is_numeric($created))) {
                    $created = new DateTime($created);
                }
                $this->created = $created;
            }
            if (isset($fields['language'])) {
                $this->language = $fields['language'];
            }
            if (isset($fields['groups'])) {
                $this->groups = array();
                foreach ($fields['groups'] as $group) {
                    if (!is_array($group)) {
                        $group = array(
                            'group' => $group,
                        );
                    }
                    $this->groups[] = $group;
                }
            }
            if (isset($fields['contentObjectName'])) {
                $this->contentObjectName = $fields['contentObjectName'];
            }
            if (isset($fields['urlAliasName'])) {
                $this->urlAliasName = $fields['urlAliasName'];
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
            if (isset($fields['ownerId'])) {
                $this->ownerId = $fields['ownerId'];
                if ($this->ownerId) {
                    $ownerObject = eZContentObject::fetch($this->ownerId, false);
                    if (!$ownerObject) {
                        throw new ObjectDoesNotExist("Owner was specified with ID " . $this->ownerId . " but the content-object does not exist");
                    }
                }
            } else if (isset($fields['ownerUuid'])) {
                $ownerUuid = $fields['ownerUuid'];
                if ($ownerUuid) {
                    $ownerObject = eZContentObject::fetchByRemoteID($ownerUuid, false);
                    if (!$ownerObject) {
                        throw new ObjectDoesNotExist("Owner was specified with UUID $ownerUuid but the content-object does not exist");
                    }
                    $this->ownerId = $ownerObject['id'];
                }
            } else if (isset($fields['ownerIdentifier'])) {
                $ownerIdentifier = $fields['ownerIdentifier'];
                // Support mapping 'admin' user to what is stored in site.ini
                $siteIni = eZINI::instance();
                if ($ownerIdentifier === 'admin') {
                    $this->ownerId = $siteIni->variable('UserSettings', 'UserCreatorID');
                } else if ($ownerIdentifier === 'anon') {
                    $this->ownerId = $siteIni->variable('UserSettings', 'AnonymousUserID');
                } else {
                    throw new ObjectDoesNotExist("Owner was specified with identifier '${ownerIdentifier}' but no user is known with the identifier");
                }
            }
    
            // Sorting is specified as a single string (Django style) or as two separate fields.
            if (isset($fields['sortBy'])) {
                $sortBy = $fields['sortBy'];
                list($sortField, $sortOrder) = self::decodeSortBy($fields['sortBy']);
                $this->sortField = $sortField;
                $this->sortOrder = $sortOrder;
            } else {
                if (isset($fields['sortField'])) {
                    $this->sortField = $fields['sortField'];
                }
                if (isset($fields['sortOrder'])) {
                    $this->sortOrder = $fields['sortOrder'];
                }
            }
            if (isset($fields['description'])) {
                $this->description = $fields['description'];
            }
            if (isset($fields['translations'])) {
                foreach ($fields['translations'] as $language => $translation) {
                    $this->addTranslation($language, $translation);
                }
            }
            $this->debug = Arr::get($fields, 'debug', false);
            $this->debugWriter = Arr::get($fields, 'debugWriter');
        }
        return $this;
    }

    /**
     * Checks if the content class exists and returns the ContentType for it.
     * If the class does not exist returns null or the default value.
     *
     * @return ContentType or null
     */
    public static function get($identifier, $default=null)
    {
        $type = new ContentType($identifier);
        if (!$type->exists()) {
            return $default;
        }
        return $type;
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
     * Make a sort-by string out of the sort field and sort order integers
     * stored on the content class.
     */
    public static function encodeSortBy($field, $order)
    {
        $sortId = eZContentObjectTreeNode::sortFieldName($field);
        if (!$sortId) {
            $sortId = (string)$field;
        }
        if (!$order) {
            $sortId = "-" . $sortId;
        }
        return $sortId;
    }

    /**
     * Decode a sort-by string into the field and order, returns an array
     * with fieldId and order.
     * 
     * @return array
     */
    public static function decodeSortBy($sortBy)
    {
        $order = 1;
        if (substr($sortBy, 0, 1) == '-') {
            $order = 0;
            $sortBy = substr($sortBy, 1);
        }
        $sortId = eZContentObjectTreeNode::sortFieldID($sortBy);
        if ($sortId === null) {
            throw new ValueError("Unknown sort field $sortBy");
        }
        return array($sortId, $order);
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
        if (isset($this->attributesNew[$identifier]) || isset($this->attributesChange[$identifier]) || isset($this->_attributes[$identifier])) {
            return true;
        }
        $this->loadAttributeMap();
        return isset($this->_attributes[$identifier]);
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
        $attribute = $this->_attributes[$identifier];
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
            $this->attributesNew[$identifier] = $attr;
            return $attr;
        }
        if (isset($this->attributesChange[$identifier])) {
            $attr = $this->attributesChange[$identifier];
            if (!($attr instanceof ContentTypeAttribute)) {
                $attr = new ContentTypeAttribute($attr['identifier'], Att::get($attr, 'type'), Attr::get($attr, 'name'), $attr);
            }
            $this->attributesChange[$identifier] = $attr;
            return $attr;
        }
        return $this->_attributes[$identifier];
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
        $this->name = null;
        $this->created = null;
        $this->contentObjectName = null;
        $this->urlAliasName = null;
        $this->isContainer = null;
        $this->alwaysAvailable = null;
        $this->sortField = null;
        $this->sortOrder = null;
        $this->groups = array();
        $this->description = null;
        $this->translations = array();

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
            'url_alias_name' => $this->urlAliasName,
            'identifier' => $this->identifier,
            'is_container' => $this->isContainer,
            'always_available' => $this->alwaysAvailable,
            'version' => $this->version,
        );
        // Update owner if specified
        $ownerId = false;
        if ($this->ownerId !== null) {
            $fields['creator_id'] = $this->ownerId;
            $fields['modifier_id'] = $this->ownerId;
            $ownerId = $this->ownerId;
        }
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
        $this->_contentClass = eZContentClass::create($ownerId, $fields, $language);
        $this->_contentClass->setName($this->name, $language);
        if ($this->description !== null) {
            $this->_contentClass->setDescription($this->description, $language);
        }
        $this->_contentClass->store();

        $isDirty = false;
        if ($this->created) {
            $created = $this->created;
            if ($created instanceof DateTime) {
                $created = $created->getTimestamp();
            }
            $this->_contentClass->setAttribute('created', $created);
            $isDirty = true;
        }

        foreach ($this->attributesNew as $attr) {
            $this->createAttribute($attr);
        }
        $this->attributesNew = array();
        $this->attributesRemove = array();

        $this->updateGroupAssignment();

        $this->isAttributeMapLoaded = true;

        // Translate content if needed
        if ($this->translations) {
            $translations = $this->translations;
            foreach ($translations as $language => $translation) {
                if (isset($translation['remove']) && $translation['remove']) {
                    $this->deleteTranslation($language);
                } else {
                    $this->createTranslation($language, $translation);
                }
            }
            // Store class again to update translation data
            $isDirty = true;
        }

        if ($isDirty) {
            $this->_contentClass->store();
        }

        $this->resetPending();

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
            $this->resetPending();
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
        $isDirty = false;

        foreach ($this->attributesNew as $attrData) {
            $attr = $this->createAttribute($attrData);
            $this->_attributes[$attr->identifier] = $attr;
        }
        foreach ($this->attributesChange as $identifier => $attrData) {
            if ($attrData instanceof ContentTypeAttribute) {
                $attr = $attrData;
            } else {
                $attr = new ContentTypeAttribute($attrData['identifier'], Att::get($attrData, 'type'), Attr::get($attrData, 'name'), $attrData);
            }
            $attr->update($contentClass);
            $this->_attributes[$attr->identifier] = $attr;
        }
        foreach ($this->attributesRemove as $identifier => $attrData) {
            $classAttribute = $contentClass->fetchAttributeByIdentifier($identifier);
            if (!$classAttribute) {
                throw new ObjectDoesNotExist("The attribute '$identifier' does not exist on content-class '" . $contentClass->attribute('identifier') . "'");
            }
            $classAttribute->removeThis();
            unset($this->_attributes[$identifier]);
        }
        $this->attributesNew = array();
        $this->attributesChange = array();
        $this->attributesRemove = array();

        // Translate content if needed
        if ($this->translations) {
            $translations = $this->translations;
            foreach ($translations as $language => $translation) {
                if (isset($translation['remove']) && $translation['remove']) {
                    $this->deleteTranslation($language);
                } else {
                    $this->createTranslation($language, $translation);
                }
            }
        }

        $this->updateGroupAssignment();

        if ($this->name !== null) {
            $contentClass->setName($this->name, $this->language);
            $isDirty = true;
        }
        if ($this->description !== null) {
            $contentClass->setDescription($this->description, $this->language);
        }
        if ($this->created) {
            $created = $this->created;
            if ($created instanceof DateTime) {
                $created = $created->getTimestamp();
            }
            $contentClass->setAttribute('created', $created);
            $isDirty = true;
        }
        if ($this->contentObjectName !== null) {
            $contentClass->setAttribute('content_object_name', $this->contentObjectName);
            $isDirty = true;
        }
        if ($this->urlAliasName !== null) {
            $contentClass->setAttribute('url_alias_name', $this->urlAliasName);
            $isDirty = true;
        }
        if ($this->isContainer !== null) {
            $contentClass->setAttribute('isContainer', $this->isContainer);
            $isDirty = true;
        }
        if ($this->alwaysAvailable !== null) {
            $contentClass->setAttribute('always_available', $this->alwaysAvailable);
            $isDirty = true;
        }
        if ($this->sortField !== null) {
            $contentClass->setAttribute('sort_field', $this->sortField);
            $isDirty = true;
        }
        if ($this->sortOrder !== null) {
            $contentClass->setAttribute('sort_order', $this->sortOrder);
            $isDirty = true;
        }
        // Update owner if specified
        if ($this->ownerId !== null) {
            $contentClass->setAttribute('creator_id', $this->ownerId);
            $contentClass->setAttribute('modifier_id', $this->ownerId);
        }

        $this->sortField = null;
        $this->sortOrder = null;
        $this->groups = array();
        $this->translations = array();

        if ($isDirty) {
            $contentClass->store();
        }

        $this->resetPending();

        \eZExpiryHandler::registerShutdownFunction();
        $handler = \eZExpiryHandler::instance();
        $time = time();
        $handler->setTimestamp( 'user-class-cache', $time );
        $handler->setTimestamp( 'class-identifier-cache', $time );
        $handler->setTimestamp( 'sort-key-cache', $time );
        $handler->store();

        \eZContentCacheManager::clearAllContentCache();

        return $this;
    }

    /**
     * Saves the changes to class and attributes back to database.
     * If the class exists the data is update, if not it creates new class.
     * 
     * @return self
     */
    public function save()
    {
        if ($this->exists()) {
            return $this->update();
        } else {
            return $this->create();
        }
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
        unset($this->attributesChange[$identifier]);
        unset($this->attributesRemove[$identifier]);
        return $this;
    }

    /**
     * Change an existing attribute.
     *
     * This method can be called with different amount of parameters.
     *
     * With one parameter it expects an associative array, the array
     * contains all the parameters by name. Or it can be a
     * ContentTypeAttribute object, or a string which is used for identifier.
     *
     * With two parameters, the first is the identifier, and the second is
     * an associative array with the rest of the named parameters. The
     * second may also be a CotentTypeAttribute object.
     * 
     * If 'type' is specified in attribute fields then it will change the
     * type of the attribute and any object using this class. Optionally
     * set the 'typeTransform' to a callback function to allow it to be
     * called when changing the class-attribute.
     * For objects use 'objectTransform'.
     *
     * Note: The attribute will only be updated when the content-class is updated.
     *
     * @throws ObjectDoesNotExist If the specified attribute does not exist
     * @return self
     */
    public function setAttribute($identifier=null, $attr=null)
    {
        $argc = func_num_args();
        if ($argc == 1) {
            if (is_array($identifier)) {
                if (!isset($identifier['identifier'])) {
                    throw new ImproperlyConfigured("Required fields 'name' and 'identifier' not specified");
                }
                $attr = $identifier;
                $identifier = $attr['identifier'];
            } else if (is_string($identifier)) {
                $attr = array();
            } else if ($identifier instanceof ContentTypeAttribute) {
                $attr = $identifier;
                $identifier = $attr->identifier;
            } else {
                throw new ImproperlyConfigured("Parameter \$identifier is not a valid string, got: " . var_export($identifier, true));
            }
        } else if ($argc >= 2) {
            if (is_array($attr)) {
                // pass
            } else if ($attr === null) {
                $attr = array();
            } else if ($attr instanceof ContentTypeAttribute) {
                // pass
            } else {
                throw new ImproperlyConfigured("Required field 'identifier' not specified");
            }
        }
        $contentClass = $this->getContentClass();
        if (!isset($attr['classAttribute'])) {
            $classAttribute = $contentClass->fetchAttributeByIdentifier($identifier);
            if (!$classAttribute) {
                $classIdentifier = $contentClass->identifier;
                throw new ObjectDoesNotExist("The content-class '${classIdentifier}' does not have an attribute with identifier '${identifier}'");
            }
            $attr['classAttribute'] = $classAttribute;
        }
        if (!$attr instanceof ContentTypeAttribute) {
            $type = Arr::get($attr, 'type');
            $name = Arr::get($attr, 'name');
            if (!isset($attr['language'])) {
                $attr['language'] = $this->language;
            }
            $attr = new ContentTypeAttribute($identifier, $type, $name, $attr);
        }
        $this->attributesChange[$attr->identifier] = $attr;
        unset($this->attributesNew[$identifier]);
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
        unset($this->attributesChange[$identifier]);
        $this->attributesRemove[$identifier] = array();
        return $this;
    }

    /**
     * Creates the class-attribute in the DB and updates the internal attribute map.
     * $attr is either an array with key/value for the attribute or an ContentObjectAttribute.
     * 
     * @return ContentTypeAttribute
     */
    public function createAttribute($attr)
    {
        if (!$attr instanceof ContentTypeAttribute) {
            $attr = new ContentTypeAttribute($attr['identifier'], $attr['type'], $attr['name'], $attr);
        }
        $classAttribute = $attr->create($this->_contentClass);
        $this->_attributes[$classAttribute->attribute('identifier')] = $attr;
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
     * Schedules addition of content-class to specified class-group, if the group assignment already exists it will
     * not be added again.
     * If $group is an array it is assumed to contain the 'group' key.
     * 
     * Calling create(), update() or save() executes the scheduled change.
     * 
     * @param $group Group to add to, either numeric ID, string (for name) or a eZContentClassGroup instance.
     * @return self;
     */
    public function addToGroup($group)
    {
        if (!is_array($group)) {
            $group = array(
                'group' => $group,
            );
        }
        // Remove any existing adds/removes of same group
        foreach ($this->groups as $idx => $existing) {
            if ($existing['group'] === $group) {
                unset($this->groups[$idx]);
            }
        }
        $this->groups[] = $group;
        return $this;
    }

    /**
     * Schedules removal of content-class from specified class-group, if the group assignment does not exist
     * nothing happens.
     * 
     * Calling create(), update() or save() executes the scheduled change.
     * 
     * @param $group Group to remove from, either numeric ID, string (for name) or a eZContentClassGroup instance.
     * @return self;
     */
    public function removeFromGroup($group)
    {
        // Remove any existing adds/removes of same group
        foreach ($this->groups as $idx => $existing) {
            if ($existing['group'] === $group) {
                unset($this->groups[$idx]);
            }
        }
        $this->groups[] = array(
            'group' => $group,
            'remove' => true,
        );
        return $this;
    }

    /**
     * Schedules adding of content-class to specified class-group, any existing assignments will be removed.
     * 
     * Calling create(), update() or save() executes the scheduled change.
     * 
     * @param $group Group to add to, either numeric ID, string (for name) or a eZContentClassGroup instance.
     * @return self;
     */
    public function setGroups($groups)
    {
        $this->groupReset = true;
        $this->groups[] = array();
        foreach ($groups as $group) {
            if (!is_array($group)) {
                $group = array(
                    'group' => $group,
                );
            }
            $this->groups[] = $group;
        }
        return $this;
    }

    /**
     * Returns an array of current active group assignments. The array is in the same
     * format as used by setGroups().
     * 
     * e.g.
     * @code
     * $type->currentGroupAssignments();
     * // array(
     * //   array(
     * //     'group' => 'Content',
     * //   ),
     * // )
     * @endcode
     */
    public function currentGroupAssignments()
    {
        $groups = array();
        foreach ($this->contentClass->fetchGroupList() as $groupAssignment) {
            $groups[] = array(
                'group' => $groupAssignment->attribute('group_name'),
            );
        }
        return $groups;
    }

    /**
     * Creates the content-class group named $name if it does not already exist.
     */
    public static function createGroup($name)
    {
        if (eZContentClassGroup::fetchByName($name, false)) {
            return;
        }
        $group = eZContentClassGroup::create();
        $group->setAttribute('name', $name);
        $group->store();
    }

    /**
     * Deletes the content-class group named $name if exist. Any group assignments
     * are removed.
     */
    public static function deleteGroup($name)
    {
        $group = eZContentClassGroup::fetchByName($name);
        if (!$group) {
            return;
        }
        eZContentClassClassGroup::removeGroupMembers($group->attribute('id'));
    }

    /**
     * Updates class-group assignment by creating/removing the class from selected groups.
     */
    protected function updateGroupAssignment()
    {
        // If there is nothing to do an no reset is needed simply return
        if (!$this->groups && !$this->groupReset) {
            return;
        }

        $classId = $this->contentClass->attribute('id');
        $version = $this->contentClass->attribute('version');
        if ($this->groupReset) {
            // Removing existing group assignments before adding new ones
            eZContentClassClassGroup::removeClassMembers($classId, $version);
        }
        foreach ($this->groups as $idx => $group) {
            if (is_array($group)) {
                $remove = isset($group['remove']) ? $group['remove'] : false;
                if (!isset($group['group'])) {
                    throw new ValueError("Group assignment was specified with an array but the 'group' entry is missing");
                }
                $groupItem = $group['group'];
            }
            if ($groupItem instanceof eZContentClassClassGroup) {
                $groupId = $groupItem->attribute('group_id');
            } elseif ($groupItem instanceof eZContentClassGroup) {
                $groupId = $groupItem->attribute('id');
            } elseif (is_numeric($groupItem)) {
                $group = eZContentClassGroup::fetch($groupItem);
                if (!$group) {
                    throw new ObjectDoesNotExist("Could not fetch eZ Content Class Group with ID '$groupItem'");
                }
                $groupId = $group->attribute('id');
            } else {
                $group = eZContentClassGroup::fetchByName($groupItem);
                if (!$group) {
                    throw new ObjectDoesNotExist("Could not fetch eZ Content Class Group with name '$groupItem'");
                }
                $groupId = $group->attribute('id');
            }

            if ($remove) {
                eZContentClassClassGroup::removeGroup(
                    $classId, $version, $groupId
                );
            } else {
                if (!eZContentClassClassGroup::classInGroup($classId, $version, $groupId)) {
                    $group->appendClass($this->contentClass);
                }
            }
        }
        $this->groupReset = false;
        $this->groups = array();
    }

    /**
     * Add/update a translation of the content-class and its attributes.
     * 
     * The translation will be applied on create(), update()/save().
     * 
     * @return self
     */
    public function addTranslation($language, $data)
    {
        $translation = array();
        if (isset($data['name'])) {
            $translation['name'] = $data['name'];
        }
        if (isset($data['description'])) {
            $translation['description'] = $data['description'];
        }
        if (isset($data['attributes'])) {
            foreach ($data['attributes'] as $identifier => $attributeData) {
                $attributeTranslation = array();
                if (isset($attributeData['name'])) {
                    $attributeTranslation['name'] = $attributeData['name'];
                }
                if (isset($attributeData['description'])) {
                    $attributeTranslation['description'] = $attributeData['description'];
                }
                $translation['attributes'][$identifier] = $attributeTranslation;
            }
        }
        $this->translations[$language] = $translation;
        return $this;
    }

    /**
     * Remove a translation from the content-class and its attributes.
     * 
     * The translation will be removed on update()/save().
     * 
     * @return self
     */
    public function removeTranslation($language)
    {
        $this->translations[$language] = array(
            'remove' => true,
        );
        return $this;
    }

    /**
     * Reset a scheduled translation change from the content-class.
     * 
     * @return self
     */
    public function resetTranslation($language)
    {
        unset($this->translations[$language]);
        return $this;
    }

    /**
     * Create/updates a translation on the content-class.
     * 
     * e.g.
     * $type->createTranslation('nor-NO');
     */
    public function createTranslation($language, $translation)
    {
        $contentClass = $this->contentClass;
        if (isset($translation['name'])) {
            $contentClass->setName($translation['name'], $language);
        }
        if (isset($translation['description'])) {
            $contentClass->setDescription($translation['description'], $language);
        }
        if (isset($translation['attributes'])) {
            foreach ($translation['attributes'] as $identifier => $attributeTranslation) {
                $attribute = $this->getAttribute($identifier);
                $attribute->createTranslation($language, $attributeTranslation);
            }
        }
        unset($this->translations[$language]);
    }

    /**
     * Deletes a translation from the content-class and its attributes.
     * 
     * e.g.
     * $type->deleteTranslation('nor-NO');
     */
    public function deleteTranslation($language)
    {
        $contentClass = $this->contentClass;
        $contentClass->removeTranslation($language);
        unset($this->translations[$language]);
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
        if ($name === "contentClass") {
            return $this->getContentClass();
        } else if ($name === "attributes") {
            $this->loadAttributeMap();
            return $this->_attributes;
        }
        throw new \Exception("Property $name does not exist");
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
    public function contentObject(array $params=null)
    {
        if ($params === null) {
            $params = array();
        }
        $params['identifier'] = $this->identifier;
        $params['contentClass'] = $this->getContentClass();
        $params['debug'] = $this->debug;
        $params['debugWriter'] = $this->debugWriter;
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
     * Writes text to the debug writer or stderr.
     * A newline is appended to the text.
     */
    public function writeDebugLn($text)
    {
        if ($this->debugWriter) {
            $this->debugWriter->write("${text}\n");
        } else {
            fwrite(STDERR, "${text}\n");
        }
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
            $attr = new ContentTypeAttribute($classAttribute->attribute('identifier'), $classAttribute->attribute('data_type_string'), $classAttribute->attribute('name'));
            $attr->classAttribute = $classAttribute;
            $this->_attributes[$identifier] = $attr;
        }
        $this->isAttributeMapLoaded = true;
    }
}
