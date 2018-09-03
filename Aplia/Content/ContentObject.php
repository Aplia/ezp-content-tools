<?php
namespace Aplia\Content;

use Exception;
use DateTime;
use Aplia\Support\Arr;
use Aplia\Content\Exceptions\ValueError;
use Aplia\Content\Exceptions\UnsetValueError;
use Aplia\Content\Exceptions\ImproperlyConfigured;
use Aplia\Content\Exceptions\ObjectDoesNotExist;
use Aplia\Content\Exceptions\ObjectAlreadyExist;
use Aplia\Content\Exceptions\CreationError;
use Aplia\Content\Exceptions\ContentError;
use Aplia\Content\Exceptions\AttributeError;
use Aplia\Content\ContentObjectAttribute;
use eZINI;
use eZContentClass;
use eZContentObject;
use eZContentObjectTreeNode;
use eZNodeAssignment;
use eZSection;
use eZContentCache;
use eZContentCacheManager;
use eZContentObjectState;
use eZContentObjectStateGroup;

/**
 * Manager for eZContentObject instances, makes it easy to create new objects
 * or modify existing ones.
 * 
 * To create a new object do:
 * @code
 * $object = new ContentObject(array('identifier' => 'folder'));
 * $object->setAttibute('title', 'My folder');
 * $object->create();
 * @endcode
 * 
 * To update an existing object pass the id or uuid (remote id):
 * @code
 * $object = new ContentObject(array('uuid' => 'abcdef'));
 * $object->setAttibute('title', 'My folder');
 * $object->update();
 * @endcode
 * 
 * To manage locations use addLocation, syncLocation and removeLocation
 * @code
 * $object = new ContentObject(array('uuid' => 'abcdef'));
 * $object->addLocation(5); // Adds to Users node
 * $object->removeLocation(2); // Removes from Content
 * $object->update();
 * @endcode
 * 
 * Ownership can be modified with 'ownerId' or 'ownerUuid' parameter which
 * references the user object that should own it.
 */
class ContentObject
{
    public $id;
    public $uuid;
    public $languageCode;
    /**
     * Whether object is available in all other languages even without a translation.
     * 
     * null means to not update, true set available, false set unavailable.
     */
    public $alwaysAvailable;
    /**
     * Which section to set on object, or null to use default
     * 
     * @type string
     */
    public $sectionIdentifier;
    /**
     * Array of content states to apply to objects or null leave as-is
     * 
     * Each state is specified as either ID or content state, string with '<group>/<state>' identifiers
     * or an eZContentObjectState object.
     */
    public $states;
    /**
     * Force a specific published date on objects, or null to leave.
     * Date is either specified as DateTime object or a timestamp number.
     */
    public $publishedDate;
    /**
     * Set a new owner for object, or null to leave.
     * 
     * Owner is an ID of user object.
     */
    public $ownerId;
    public $isInWorkflow = false;
    public $clearCache = true;
    public $updateNodePath = true;
    public $newVersion;
    public $attributes = array();
    public $attributesChange = array();

    public $contentObject;
    public $contentVersion;
    public $dataMap;

    /*
     Properties:
     $contentClass;
     $identifier;
     $locations;
     $stateObjects;
    */

    protected $_contentClass = 'unset';
    protected $_identifier;
    protected $_locations;
    protected $_nodes;
    protected $_stateObjects = 'unset';

    /**
     * Wraps the procedure for creating or updating content objects into a
     * simpler interface.
     *
     * To update an existing object use either contentObject or contentNode.
     *
     * @code
     * $content = new ContentObject(array('contentObject' => $object));
     * @endcode
     *
     * @code
     * $content = new ContentObject(array('contentNode' => $node));
     * @endcode
     *
     * To create a new object supply contentClass and locations.
     *
     * @code
     * $content = new ContentObject(array('contentClass' => $class));
     * @endcode
     *
     * Updating attributes is then done with setAttribute.
     *
     * @code
     * $content->setAttribute('title', 'My title');
     * @endcode
     *
     * Then finally call update() to update existing object or create() to create a new object.
     */
    public function __construct($params = null)
    {
        if ($params) {
            if (isset($params['id'])) {
                $this->id = $params['id'];
            }
            if (isset($params['uuid'])) {
                $this->uuid = $params['uuid'];
            }
            if (isset($params['identifier'])) {
                $this->identifier = $params['identifier'];
            }
            if (isset($params['language'])) {
                $this->languageCode = $params['language'];
            } else if (isset($params['languageCode'])) {
                $this->languageCode = $params['languageCode'];
            }
            if (isset($params['alwaysAvailable'])) {
                $this->alwaysAvailable = $params['alwaysAvailable'];
            }
            if (isset($params['sectionIdentifier'])) {
                $this->sectionIdentifier = $params['sectionIdentifier'];
            } else if (isset($params['section'])) {
                $this->sectionIdentifier = $params['section'];
            }
            if (isset($params['states'])) {
                $this->states = $params['states'];
            }
            if (isset($params['publishedDate'])) {
                $this->publishedDate = $params['publishedDate'];
            }
            if (isset($params['ownerId'])) {
                $this->ownerId = $params['ownerId'];
                if ($this->ownerId) {
                    $ownerObject = eZContentObject::fetch($this->ownerId, false);
                    if (!$ownerObject) {
                        throw new ObjectDoesNotExist("Owner was specified with ID " . $this->ownerId . " but the content-object does not exist");
                    }
                }
            } else if (isset($params['ownerUuid'])) {
                $ownerUuid = $params['ownerUuid'];
                if ($ownerUuid) {
                    $ownerObject = eZContentObject::fetchByRemoteID($ownerUuid, false);
                    if (!$ownerObject) {
                        throw new ObjectDoesNotExist("Owner was specified with UUID $ownerUuid but the content-object does not exist");
                    }
                    $this->ownerId = $ownerObject['id'];
                }
            }
            if (isset($params['clearCache'])) {
                $this->clearCache = $params['clearCache'];
            }
            if (isset($params['updateNodePath'])) {
                $this->updateNodePath = $params['updateNodePath'];
            }
            $this->newVersion = Arr::get($params, 'newVersion', false);
            if (isset($params['contentNode']) && !isset($params['contentObject'])) {
                if (!($params['contentNode'] instanceof eZContentObjectTreeNode)) {
                    throw new ValueError("Parameter 'contentNode' is not an instance of eZContentObjectTreeNode");
                }
                $params['contentObject'] = $params['contentNode']->object();
            }
            if (isset($params['contentObject'])) {
                if (!($params['contentObject'] instanceof eZContentObject)) {
                    throw new ValueError("Parameter 'contentObject' is not an instance of eZContentObject");
                }
                $this->contentObject = $params['contentObject'];
                $params['contentClass'] = $this->contentObject->contentClass();
            }
            if (isset($params['contentClass'])) {
                if (!($params['contentClass'] instanceof eZContentClass)) {
                    throw new ValueError("Parameter 'contentClass' is not an instance of eZContentClass");
                }
                $this->_contentClass = $params['contentClass'];
                $this->_identifier = $this->_contentClass->attribute('identifier');
            }
            if (isset($params['locations'])) {
                $locations = $params['locations'];
                foreach ($locations as $idx => $location) {
                    $this->addLocation($location);
                }
            }
        }

        if ($this->contentObject) {
            $this->loadContentObject();
        }
    }

    /**
     * Remove content object and related locations without adding to archive.
     * 
     * @return self
     */
    public function exists()
    {
        if ($this->contentObject) {
            return true;
        }
        if ($this->id) {
            return eZContentObject::exists($this->id);
        }
        if ($this->uuid) {
            return (bool)eZContentObject::fetchByRemoteID($this->uuid, false);
        }
        return false;
    }

    /**
     * Add a new location to object, the parent node must not already have a
     * child node for the given object.
     * 
     * The simplest way is to just specify the parent node ID
     * @code
     * addLocation(42, array())
     * @endcode
     * 
     * To set addition fields use a secondary array.
     * @code
     * addLocation(42, array(
     *   'sort_by' => 'name',
     * ))
     * @endcode
     * 
     * If you need set multiple entries or use uuid for parent just pass one array
     * @code
     * addLocation(array(
     *   'parent_uuid' => 'abcdef',
     *   'sort_by' => 'name',
     * ))
     * @endcode
     * 
     * @return self
     */
    public function addLocation($location, $fields=null)
    {
        $argc = func_num_args();
        if ($argc >= 2) {
            $location = $this->processLocationValue($location);
            if ($fields) {
                $location = array_merge($fields, $location);
            }
        } else if ($argc === 1) {
            $location = $this->processLocationValue($location);
        }
        $location = $this->processLocationEntry($location);
        if ($this->_locations === null) {
            $this->_locations = array();
        }
        $this->_locations[] = $location;

        return $this;
    }

    /**
     * Remove a location from the object.
     * 
     * The simplest way is to just specify the parent node ID
     * @code
     * removeLocation(42)
     * @endcode
     * 
     * If you need specifiy uuid or pass a node object use an array
     * @code
     * removeLocation(array(
     *   'parent_uuid' => 'abcdef',
     * ))
     * @endcode
     * 
     * @code
     * removeLocation(array(
     *   'node' => $node,
     * ))
     * @endcode
     * 
     * @return self
     */
    public function removeLocation($location)
    {
        $location = $this->processLocationValue($location);
        $location = $this->processLocationEntry($location, /*checkExisting*/true);
        if (!isset($location['node'])) {
            throw new CreationError("Cannot remove location without a node or parent");
        }
        $location['status'] = 'remove';

        if ($this->_locations === null) {
            $this->_locations = array();
        }
        $this->_locations[] = $location;

        return $this;
    }

    /**
     * Add a new location or update an existing one.
     * The location is determined by parent_id, parent_uuid or parent_node.
     * 
     * If 'node' is passed it is assumed to already exist.
     * 
     * The simplest way is to just specify the parent node ID
     * @code
     * syncLocation(42, array())
     * @endcode
     * 
     * To set addition fields use a secondary array.
     * @code
     * syncLocation(42, array(
     *   'sort_by' => 'name',
     * ))
     * @endcode
     * 
     * If you need set multiple entries or use uuid for parent just pass one array
     * @code
     * syncLocation(array(
     *   'parent_uuid' => 'abcdef',
     *   'sort_by' => 'name',
     * ))
     * @endcode
     * 
     * @return self
     */
    public function syncLocation($location, $fields=null)
    {
        $argc = func_num_args();
        if ($argc >= 2) {
            $location = $this->processLocationValue($location);
            if ($fields) {
                $location = array_merge($fields, $location);
            }
        } else if ($argc === 1) {
            $location = $this->processLocationValue($location);
        }
        $location = $this->processLocationEntry($location, /*checkExisting*/true);
        if ($this->_locations === null) {
            $this->_locations = array();
        }
        $this->_locations[] = $location;

        return $this;
    }

    /**
     * Update an existing location.
     * The location is determined by parent_id, parent_uuid or parent_node.
     * 
     * If 'node' is passed it is used as location.
     * 
     * The simplest way is to just specify the parent node ID and fields
     * @code
     * updateLocation(42, array(
     *   'sort_by' => 'name',
     * ))
     * @endcode
     * 
     * If you need set multiple entries or use uuid for parent just pass one array
     * @code
     * updateLocation(array(
     *   'parent_uuid' => 'abcdef',
     *   'sort_by' => 'name',
     * ))
     * @endcode
     * 
     * @throw ValueError If the location does not have a node present
     * @return self
     */
    public function updateLocation($location, array $fields)
    {
        $location = $this->processLocationValue($location);
        if ($fields) {
            $location = array_merge($fields, $location);
        }
        $location = $this->processLocationEntry($location, /*checkExisting*/true);
        if (!isset($location['node'])) {
            throw new ValueError("Cannot update location as the node for the location does not exist");
        }
        if ($this->_locations === null) {
            $this->_locations = array();
        }
        $this->_locations[] = $location;

        return $this;
    }

    /**
     * Process the location parameter by check for the type, if it is already
     * an array it is simply returned. Otherwise it tries to detect the type
     * of location that is passed.
     * 
     * - number - Used as parent node ID
     * - eZContentObjectTreeNode - Used as parent, parent node id is extracted
     * - eZContentObject - Used as parent object, parent node and parent node id is extracted
     * 
     * @return array Location entry with processed information
     */
    protected function processLocationValue($location)
    {
        if (!is_array($location)) {
            $value = $location;
            $location = array();
            if ($value instanceof \eZContentObjectTreeNode) {
                $parentNode = $value;
                $location['parent_id'] = $parentNode->attribute('node_id');
                $location['parent_node'] = $parentNode;
            } elseif ($value instanceof \eZContentObject) {
                $location['parent_id'] = $value->attribute('main_node_id');
            } else {
                $location['parent_id'] = $value;
            }
        }
        return $location;
    }

    /**
     * Process information by taking implicit data and setting the explicit
     * data as is needed by other methods in this class.
     * Parent id is determined by checking parent_id, parent_uuid, parent_node or node.
     * 
     * @return array Location entry with processed information
     */
    protected function processLocationEntry(array $location, $checkExisting=false)
    {
        $objectId = null;
        $contentObject = null;
        if ($checkExisting) {
            try {
                $this->loadContentObject();
                $contentObject = $this->contentObject;
                if ($contentObject) {
                    $objectId = $contentObject->attribute('id');
                }
            } catch (ObjectDoesNotExist $e) {
                if (isset($location['node'])) {
                    throw ValueError("Location has 'node' entry but object does not exist");
                }
                // Object does not exist, assuming location is meant to be created
            }
        }

        if (!isset($location['parent_id']) && !isset($location['parent_node']) && !isset($location['parent_uuid']) && !isset($location['node'])) {
            throw new UnsetValueError("No 'parent_id', 'parent_uuid', 'parent_node' or 'node' value is set for location");
        }

        // If a node was passed the location should not be created but rather updated or moved
        if (isset($location['node'])) {
            if (!($location['node'] instanceof eZContentObjectTreeNode)) {
                throw TypeError("Location has 'node' entry but it is not a eZContentObjectTreeNode instance");
            }
            // If we have a node but no parent info is given use the nodes parent
            if (!isset($location['parent_id']) && !isset($location['parent_node']) || !isset($location['parent_uuid'])) {
                $location['parent_node'] = $location['node']->parentNode();
                $location['parent_id'] = $location['parent_node']->attribute('node_id');
            }
        }

        if ($checkExisting && !isset($location['parent_id'])) {
            if (isset($location['parent_node'])) {
                $location['parent_id'] = $location['parent_node']->attribute('node_id');
            } else if (isset($location['parent_uuid'])) {
                $parentNode = eZContentObjectTreeNode::fetchByRemoteID($location['parent_uuid']);
                if (!$parentNode) {
                    throw new ValueError("Location has parent_uuid=" . $location['parent_uuid'] . " but the node does not exist");
                }
                $location['parent_id'] = $parentNode->attribute('node_id');
                $location['parent_node'] = $parentNode;
            } 
        }
        if (!isset($location['is_main'])) {
            $location['is_main'] = false;
        }

        $node = null;
        if (isset($location['node'])) {
            $node = $location['node'];
        }
        if ($checkExisting && !$node && $contentObject) {
            if (isset($location['node_id'])) {
                $node = eZContentObjectTreeNode::fetch($location['node_id']);
                if (!$node) {
                    throw new ValueError("Location has node_id=" . $location['node_id'] . " but the node does not exist");
                }
            }
            if (!$node) {
               $node = eZContentObjectTreeNode::fetchNode($objectId, $location['parent_id']);
            }
            $location['node'] = $node;
        }

        if ($node) {
            // If the parent of the existing node is different than the specified parent then
            // it should be moved, otherwise it only needs update of attributes (if any)
            if ($node->attribute('parent_node_id') == $location['parent_id']) {
                $location['status'] = 'update';
            } else {
                $location['status'] = 'move';
            }
        } else {
            $location['status'] = 'new';
        }

        // Decode sort_by into sort_field and sort_order
        if (isset($location['sort_by'])) {
            list($sortField, $sortOrder) = ContentType::decodeSortBy($location['sort_by']);
            $location['sort_field'] = $sortField;
            $location['sort_order'] = $sortOrder;
        }

        return $location;
    }

    public function resetPending()
    {
        $this->attributesChange = array();
    }

    /**
     * Figures out the tree identifier for the given node.
     * For instance a nodeId of 2 would return 'content'.
     * 
     * If the node is not a top-level node or is unknown it returns null
     * 
     * @return string The identifier of the tree structure for the top-level node
     */
    public static function mapNodeToTreeIdentifier($nodeId)
    {
        $contentIni = eZINI::instance('content.ini');
        $identifier = null;
        if ($contentIni->variable('NodeSettings', 'RootNode') == $nodeId) {
            $identifier = 'content';
        } else if ($contentIni->variable('NodeSettings', 'UserRootNode') == $nodeId) {
            $identifier = 'users';
        } else if ($contentIni->variable('NodeSettings', 'MediaNode') == $nodeId) {
            $identifier = 'content';
        } else if ($contentIni->variable('NodeSettings', 'SetupRootNode') == $nodeId) {
            $identifier = 'setup';
        } else if ($contentIni->variable('NodeSettings', 'DesignRootNode') == $nodeId) {
            $identifier = 'content';
        }
        return $identifier;
    }

    /**
     * Figures out the node ID of the tree identifier.
     * For instance 'content' would return 2.
     * 
     * @return int The node ID for the tree or null if no match
     */
    public static function mapTreeIdentifierToNode($identifier)
    {
        $contentIni = eZINI::instance('content.ini');
        if ($treeId === 'content') {
            return $contentIni->variable('NodeSettings', 'RootNode');
        } else if ($treeId === 'media') {
            return $contentIni->variable('NodeSettings', 'MediaRootNode');
        } else if ($treeId === 'user' || $treeId === 'users') {
            return $contentIni->variable('NodeSettings', 'UserRootNode');
        } else if ($treeId === 'top') {
            return 1;
        } else {
            if ($contentIni->hasVariable('NodeSettings', 'RootNodes')) {
                $rootNodes = $contentIni->variable('NodeSettings', 'RootNodes');
                if (isset($rootNodes[$treeId])) {
                    return $rootNodes[$treeId];
                }
            }
        }
    }

    /**
     * Creates the content object using current fields, attributes and locations.
     * If the content object already exists it throws an exception.
     * 
     * @param $publish If true it publishes the new version after creating it
     * @return self
     */
    public function create($publish = true)
    {
        $fields = array();
        $existing = null;
        if ($this->uuid) {
            $existing = eZContentObject::fetchByRemoteID($this->uuid);
            if ($existing) {
                throw new ObjectAlreadyExist("Content Object with UUID/Remote ID: '$this->uuid' already exists, cannot create");
            }
            $fields['remote_id'] = $this->uuid;
        }
        if (!$existing && $this->id) {
            $existing = eZContentObject::fetch($this->id);
            if ($existing) {
                throw new ObjectAlreadyExist("Content Object with ID: '$this->id' already exists, cannot create");
            }
            $fields['id'] = $this->id;
        }
        if (!$this->contentClass) {
            if (!is_object($this->contentClass)) {
                throw new ObjectDoesNotExist("Invalid content class identifier, no content class found: $this->identifier");
            }
        }

        if (!$this->_locations) {
            throw new ImproperlyConfigured("No locations set, cannot create object");
        }
        $this->adjustMainLocation(/*requireMain*/true);

        $contentClassID = $this->contentClass->attribute('id');
        $languageCode = $this->languageCode;
        if (!$languageCode) {
            $ini = \eZINI::instance();
            $languageCode = $ini->variable('RegionalSettings', 'ContentObjectLocale');
            if (!$languageCode) {
                throw new ImproperlyConfigured("No languageCode set, neither as parameter nor in site.ini");
            }
        }
        $languageID = \eZContentLanguage::idByLocale($languageCode);
        if ($languageID === false) {
            throw new ImproperlyConfigured("Language code '$languageCode' does not exist in the system");
        }

        $sectionId = null;
        if ($this->sectionIdentifier) {
            if (is_numeric($this->sectionIdentifier)) {
                $sectionId = $this->sectionIdentifier;
            } else {
                $section = eZSection::fetchByIdentifier($this->sectionIdentifier);
                $sectionId = $section->attribute('id');
            }
        }
        $db = \eZDB::instance();
        $db->begin();
        $this->contentObject = self::createWithNodeAssignment(
            $this->_locations,
            $contentClassID,
            $languageCode,
            $this->uuid ? $this->uuid : false,
            $this->contentObject,
            $this->alwaysAvailable,
            $sectionId
        );
        if (!$this->contentObject) {
            throw new CreationError('Failed to create content object for class: ' . $this->identifier);
        }
        if ($this->uuid) {
            $this->contentObject->setAttribute('remote_id', $this->uuid);
            $this->contentObject->sync(array('remote_id'));
            $assignments = \eZNodeAssignment::fetchForObject($this->contentObject->attribute('id'), $this->contentObject->attribute('current_version'), true);
            if (!$assignments) {
                throw new ObjectDoesNotExist("No node assignments found for Content Object: {$this->contentObject->attribute('id')}");
            }
            foreach ($assignments as $assignment) {
                $assignment->setAttribute('remote_id', $this->uuid);
                $assignment->sync(array('remote_id'));
            }
        }

        foreach ($this->attributesChange as $attr) {
            $attribute = $this->updateAttribute($attr);
            $contentAttribute = $attribute->contentAttribute;
            $this->attributes[$contentAttribute->attribute('contentclass_attribute_identifier')] = $attribute;
        }
        $this->attributesChange = array();

        // Update name entries for this version/language
        $name = $this->contentClass->contentObjectName($this->contentObject, $this->contentObject->attribute('current_version'), $languageCode);
        $this->contentObject->setName($name, $this->contentObject->attribute('current_version'), $languageCode);
        if ($this->publishedDate !== null) {
            if ($this->publishedDate instanceof DateTime) {
                $publishedDate = $this->publishedDate->getTimestamp();
            } else {
                $publishedDate = $this->publishedDate;
            }
            $this->contentObject->setAttribute('published', $publishedDate);
        }
        // Update owner if specified
        if ($this->ownerId !== null) {
            $this->contentObject->setAttribute('owner_id', $this->ownerId);
            $contentVersion = $this->contentObject->currentVersion();
            if ($contentVersion) {
                $contentVersion->setAttribute('creator_id', $this->ownerId);
                $contentVersion->sync(array('creator_id'));
            }
        }
        $this->contentObject->store();
        $db->commit();

        if ($publish) {
            // Getting the current transaction counter to check if all transactions are committed during content/publish operation (see below)
            $transactionCounter = $db->transactionCounter();
            $operationResult = \eZOperationHandler::execute(
                'content', 'publish', array(
                    'object_id' => $this->contentObject->attribute('id'),
                    'version'   => $this->contentObject->attribute('current_version'),
            ));
            if ($operationResult === null) {
                throw new ContentError("Failed to publish content object with ID: " . $this->contentObject->attribute('id') . " and version: " . $this->contentObject->attribute('current_version'));
            }
            if ($operationResult['status'] == 3) {
                $this->isInWorkflow = true;

                // Check if publication related transaction counter is clean.
                // If not, operation is probably in STATUS_CANCELLED, STATUS_HALTED, STATUS_REPEAT or STATUS_QUEUED
                // and final commit was not done as it's part of the operation body (see commit-transaction action in content/publish operation definition).
                // Important note: Will only be committed transactions that weren't closed during the content/publish operation
                $transactionDiff = $db->transactionCounter() - $transactionCounter;
                for ( $i = 0; $i < $transactionDiff; ++$i ) {
                    $db->commit();
                }

            } else {
                $mainNode = $this->contentObject->mainNode();
                if (!$mainNode) {
                    throw new CreationError("Failed to create a main node for the Content Object");
                }
                if ($this->uuid) {
                    $mainNode->setAttribute('remote_id', $this->uuid);
                    $mainNode->sync(array('remote_id'));
                }
            }
        }

        $db->begin();
        foreach ($this->locations as $location) {
            if ($location['status'] !== 'new') {
                continue;
            }
            if (!isset($location['uuid'])) {
                // No uuid, nothing to do
                continue;
            }
            $node = isset($location['node']) ? $location['node'] : null;
            if (!$node) {
                $parentId = isset($location['parent_id']) ? $location['parent_id'] : null;
                if (isset($location['parent_node'])) {
                    $parentId = $location['parent_node']->attribute('node_id');
                } else if (isset($location['parent_uuid'])) {
                    $parentNode = eZContentObjectTreeNode::fetchByRemoteID($location['parent_uuid']);
                    $parentId = $parentNode->attribute('node_id');
                } else {
                    // Cannot determine parent id, skipping uuid change
                    continue;
                }
                $node = eZContentObjectTreeNode::fetchNode($this->contentObject->attribute('id'), $parentId);
            }
            if ($node) {
                $node->setAttribute('remote_id', $location['uuid']);
                $node->sync(array('remote_id'));
            }
        }

        if ($this->stateObjects !== null) {
            foreach ($this->stateObjects as $state) {
                $this->contentObject->assignState($state);
            }
        }
        $db->commit();

        $this->_locations = null;

        $this->_nodes = null;

        return $this;
    }

    /**
     * Remove content object and related locations without adding to archive.
     * 
     * @return self
     */
    public function remove()
    {
        $this->_remove(true);
        return $this;
    }

    /**
     * Remove content object and place it in archive.
     * 
     * @return self
     */
    public function archive()
    {
        $this->_remove(false);
    }

    protected function _remove($purge = true)
    {
        $contentObject = null;
        if ($this->uuid) {
            $contentObject = eZContentObject::fetchByRemoteID($this->uuid);
            if (!$contentObject) {
                throw new ObjectDoesNotExist("No Content Object with UUID/Remote ID: '$this->uuid', cannot remove");
            }
        } elseif ($this->id) {
            $contentObject = eZContentObject::fetch($this->id);
            if ($contentObject) {
                throw new ObjectDoesNotExist("No Content Object with ID: '$this->id', cannot remove");
            }
        } else {
            throw new UnsetValueError("No ID or UUID/Remote ID set, cannot remove Content Object");
        }
        if ($purge) {
            $nodes = $contentObject->assignedNodes();
            foreach ($nodes as $node) {
                $node->removeThis();
            }
            $contentObject->purge();
        } else {
            $contentObject->removeThis();
        }
    }

    public function loadContentObject()
    {
        if ($this->contentObject === null) {
            if ($this->uuid) {
                $this->contentObject = eZContentObject::fetchByRemoteID($this->uuid);
                if (!$this->contentObject) {
                    throw new ObjectDoesNotExist("Content Object with UUID/Remote ID: '$this->uuid' does not exist");
                }
            } elseif ($this->id) {
                $this->contentObject = eZContentObject::fetch($this->id);
                if ($this->contentObject) {
                    throw new ObjectDoesNotExist("Content Object with ID: '$this->id' does not exist");
                }
            }
        } else {
            $this->uuid = null;
            $this->id = null;
        }
        if (!is_object($this->_contentClass)) {
            $this->_contentClass = $this->contentObject->contentClass();
        }
        if ($this->dataMap === null) {
            if ($this->contentVersion !== null) {
                $this->dataMap = $this->contentVersion->dataMap();
            } else {
                $languageCode = $this->languageCode ? $this->languageCode : false;
                $this->dataMap = $this->contentObject->fetchDataMap(/*version*/false, $languageCode);
            }
        }
        if ($this->attributes === null) {
            foreach ($this->dataMap as $identifier => $contentAttribute) {
                $this->attributes[$identifier] = new ContentObjectAttribute($identifer, null);
            }
        }
    }

    /**
     * Load locations from content object and adds them as locations.
     */
    protected function loadLocations()
    {
        if ($this->_locations === null) {
            $this->_locations = array();
        }
        $nodes = $this->contentObject->assignedNodes();
        foreach ($nodes as $node) {
            $this->_locations[] = array(
                'parent_id' => $node->attribute('parent_node_id'),
                'node' => $node,
                'is_main' => $node->isMain(),
                'status' => 'update',
            );
        }
    }

    /**
     * Load nodes from content object and adds them to $nodes.
     */
    protected function loadNodes()
    {
        if ($this->_nodes === null) {
            $this->_nodes = array();
        }
        $nodes = $this->contentObject->assignedNodes();
        foreach ($nodes as $node) {
            $nodeUuid = $node->attribute('remote_id');
            $this->_nodes[$nodeUuid] = array(
                'uuid' => $nodeUuid,
                'parent_id' => $node->attribute('parent_node_id'),
                'id' => $node->attribute('node_id'),
                'node' => $node,
                'is_main' => $node->isMain(),
            );
        }
    }

    /**
     * Update an existing object by modifying the attributes.
     *
     * If newVersion is true then it will first create a new version
     * and update the fields in that version, the new version will
     * then be published.
     *
     * @return self The current instance, allows for chaining calls.
     */
    public function update()
    {
        // Make sure we have a content object, locations and data map
        $this->loadContentObject();

        // If no locations has been set load from the object
        if ($this->_locations === null) {
            $this->loadLocations();
        }

        $contentObject = $this->contentObject;
        $objectId = $contentObject->attribute('id');
        $languageCode = $this->languageCode ? $this->languageCode : $contentObject->currentLanguage();
        $availableLanguages = $contentObject->availableLanguages();
        if ($languageCode && !in_array($languageCode, $availableLanguages)) {
            // If we require a language the object does not currently a new version must be created
            $this->newVersion = true;
        }

        $modifiedObject = false;
        $syncFields = array();
        if ($this->uuid && $this->uuid != $contentObject->attribute('remote_id')) {
            $contentObject->setAttribute('remote_id', $this->uuid);
            $syncFields[] = 'remote_id';
            $modifiedObject = true;
        }
        if ($syncFields) {
            $contentObject->sync($syncFields);
        }
        $publish = false;
        $contentVersionNo = $contentObject->attribute('current_version');

        $db = \eZDB::instance();
        $db->begin();
        try {
            // Create a new version and update dataMap with new attributes
            if ($this->newVersion) {
                $copyFromLanguageCode = false;
                $this->contentVersion = $contentObject->createNewVersion(
                    /*$copyFromVersion*/false, /*$versionCheck*/true, $languageCode, $copyFromLanguageCode);
                $this->dataMap = $this->contentVersion->dataMap();
                $contentVersionNo = $this->contentVersion->attribute('version');
                $publish = true;
            }

            if ($this->sectionIdentifier) {
                if (is_numeric($this->sectionIdentifier)) {
                    $sectionId = $this->sectionIdentifier;
                } else {
                    $section = eZSection::fetchByIdentifier($this->sectionIdentifier);
                    $sectionId = $section->attribute('id');
                }
                $contentObject->setAttribute('section_id', $sectionId);
            }

            if ($this->attributesChange) {
                $modifiedObject = true;
            }
            foreach ($this->attributesChange as $attr) {
                $attribute = $this->updateAttribute($attr);
                $contentAttribute = $attribute->contentAttribute;
                $this->attributes[$contentAttribute->attribute('contentclass_attribute_identifier')] = $attribute;
            }
            $this->attributesChange = array();

            // Update name entries for this version/language
            $name = $this->contentClass->contentObjectName($contentObject, $contentVersionNo, $languageCode);
            $contentObject->setName($name, $this->contentObject->attribute('current_version'), $languageCode);
            if ($this->publishedDate !== null) {
                if ($this->publishedDate instanceof DateTime) {
                    $publishedDate = $this->publishedDate->getTimestamp();
                } else {
                    $publishedDate = $this->publishedDate;
                }
                $contentObject->setAttribute('published', $publishedDate);
                $modifiedObject = true;
            }
            // Update owner if specified
            if ($this->ownerId !== null) {
                if ($contentObject->attribute('owner_id') != $this->ownerId) {
                    $contentObject->setAttribute('owner_id', $this->ownerId);
                    $modifiedObject = true;
                }
                if ($this->contentVersion && $this->contentVersion->attribute('creator_id') != $this->ownerId) {
                    $this->contentVersion->setAttribute('creator_id', $this->ownerId);
                    $this->contentVersion->sync(array('creator_id'));
                    $modifiedObject = true;
                }
            }
            $contentObject->store();
        } catch (\Exception $e) {
            $db->rollback();
            throw $e;
        }
        $db->commit();

        if ($publish) {
            // Getting the current transaction counter to check if all transactions are committed during content/publish operation (see below)
            $transactionCounter = $db->transactionCounter();
            $operationResult = \eZOperationHandler::execute(
                'content', 'publish', array(
                    'object_id' => $contentObject->attribute('id'),
                    'version'   => $contentVersionNo,
            ));
            if ($operationResult === null) {
                throw new ContentError("Failed to publish content object with ID: " . $contentObject->attribute('id') . " and version: " . $contentVersionNo);
            }
            if ($operationResult['status'] == 3) {
                $this->isInWorkflow = true;

                // Check if publication related transaction counter is clean.
                // If not, operation is probably in STATUS_CANCELLED, STATUS_HALTED, STATUS_REPEAT or STATUS_QUEUED
                // and final commit was not done as it's part of the operation body (see commit-transaction action in content/publish operation definition).
                // Important note: Will only be committed transactions that weren't closed during the content/publish operation
                $transactionDiff = $db->transactionCounter() - $transactionCounter;
                for ( $i = 0; $i < $transactionDiff; ++$i ) {
                    $db->commit();
                }

            } else {
                $mainNode = $contentObject->mainNode();
                if (!$mainNode) {
                    throw new CreationError("Failed to create a main node for the Content Object");
                }
            }
            $modifiedObject = true;
        }

        // Make sure we no more than 1 main node
        $this->adjustMainLocation(/*requireMain*/false);

        // Load parent nodes and determine main node
        $mainParentNode = self::prepareLocations($this->_locations);

        $newLocations = array();
        $removeLocations = array();
        $updateLocations = array();
        $modifiedNodes = array();
        foreach ($this->_locations as $idx => $location) {
            if ($location['status'] == 'new') {
                if (isset($location['node'])) {
                    throw new ValueError("Location index $idx is a new location but already has a 'node' entry");
                }
                $newLocations[$idx] = $location;
            } else if ($location['status'] == 'remove') {
                $removeLocations[$idx] = $location;
            } else if ($location['status'] == 'update' || $location['status'] == 'move') {
                if (!isset($location['node'])) {
                    throw new UnsetValueError("Location index $idx contains '" . $location['status'] . "' entry but no 'node' was found");
                }
                $updateLocations[$idx] = $location;
            } else if ($location['status'] == 'nop') {
                // Ignore the entry
            } else {
                throw new ValueError("Unknown location status '" . $location['status'] . "' for index $idx");
            }
        }

        $db->begin();
        try {
            $newMainNode = null;
            $newMainNodeId = false;
            $newMainParentNodeId = null;

            if ($newLocations) {
                foreach ($newLocations as $idx => $location) {
                    $parentNode = $location['parent_node'];
                    $parentNodeId = $parentNode->attribute('node_id');
                    $existingNode = eZContentObjectTreeNode::fetchNode($objectId, $parentNodeId);
                    if ($existingNode) {
                        throw new CreationError("Cannot create new node as parent of $parentNodeId for object $objectId, a node already exists");
                    }
                    $isMain = $location['is_main'];
                    $parentObject = $parentNode->attribute( 'object' );
                    $node = $this->createLocation($parentNode);
                    $this->_locations[$idx]['node'] = $node;
                    $this->_locations[$idx]['status'] = 'existing';

                    $changes = $this->updateNode($node, $location);
                    if ($isMain && $node->attribute('main_node_id') != $node->attribute('node_id')) {
                        $newMainNode = $node;
                        $newMainNodeId = $node->attribute('node_id');
                        $newMainParentNodeId = $node->attribute('parent_node_id');
                    }
                    if ($changes) {
                        $node->sync($changes);
                    }
                    if ($this->updateNodePath) {
                        $node->updateSubTreePath();
                    }
                    $modifiedNodes[] = $node;
                    $this->locations[$idx]['node'] = $node;
                    $this->locations[$idx]['status'] = 'nop';
                }
            }

            if ($removeLocations) {
                foreach ($removeLocations as $idx => $location) {
                    $node = $location['node'];
                    $node->removeNodeFromTree();
                    $modifiedNodes[] = $node;
                    unset($this->_locations[$idx]);
                }
            }

            // TODO: Move locations
            if ($updateLocations) {
                foreach ($updateLocations as $idx => $location) {
                    $node = $location['node'];
                    $changes = $this->updateNode($node, $location);
                    $isMain = $location['is_main'];
                    if ($isMain && $node->attribute('main_node_id') != $node->attribute('node_id')) {
                        $newMainNode = $node;
                        $newMainNodeId = $node->attribute('node_id');
                        $newMainParentNodeId = $node->attribute('parent_node_id');
                    }
                    $parentNodeId = $node->attribute('parent_node_id');
                    $move = false;
                    if (isset($location['parent_node'])) {
                        $parentNode = $location['parent_node'];
                        $location['parent_id'] = $parentNode->attribute('node_id');
                        if ($parentNode->attribute('node_id') != $parentNodeId) {
                            $move = true;
                        }
                    } else if (isset($location['parent_id']) && $location['parent_id'] != $parentNodeId) {
                        $move = true;
                    } else if (isset($location['parent_uuid'])) {
                        $parentNode = eZContentObjectTreeNode::fetchByRemoteID($location['parent_uuid']);
                        $location['parent_id'] = $parentNode->attribute('node_id');
                        $location['parent_node'] = $node;
                        if ($parentNode && $parentNode->attribute('node_id') != $parentNodeId) {
                            $move = true;
                        }
                    }
                    if ($changes) {
                        $node->sync($changes);
                    }
                    $forceUpdate = isset($location['update']) ? $location['update'] : false;
                    if ($modifiedObject || $forceUpdate) {
                        $changes = true;
                    }
                    if ($move) {
                        $node->move($location['parent_id']);
                        $changes = true;
                    }
                    if ($changes) {
                        if ($this->updateNodePath) {
                            $node->updateSubTreePath();
                        }
                        $modifiedNodes[] = $node;
                    }
                    $this->_locations[$idx]['node'] = $node;
                    $this->_locations[$idx]['status'] = 'nop';
                }
            }

            // If main node has changed update the tree structure and assignments
            if ($newMainNodeId) {
                $modifiedNodes[] = $contentObject->mainNode();
                $modifiedNodes[] = $newMainNode;
                eZContentObjectTreeNode::updateMainNodeID($newMainNodeId, $objectId, $contentObject->attribute('current_version'), $newMainParentNodeId);
            }
        } catch (\Exception $e) {
            $db->rollback();
            throw $e;
        }
        $db->commit();

        $db->begin();
        // If always available is to be changed update object and nods
        if ($this->alwaysAvailable !== null) {
            if ($alwaysAvailable) {
                $contentObject->setAlwaysAvailableLanguageID($languageCode ? $languageCode : $contentObject->currentLanguage());
            } else {
                $contentObject->setAlwaysAvailableLanguageID(false);
            }
            $contentObject->sync(array('language_mask'));
            if ($this->contentVersion) {
                if ($alwaysAvailable) {
                    $this->contentVersion->setAlwaysAvailableLanguageID($languageCode ? $languageCode : $this->contentVersion->initialLanguageCode());
                } else {
                    $this->contentVersion->clearAlwaysAvailableLanguageID();
                }
                $this->contentVersion->sync(array('language_mask'));
            }
        }

        if ($this->stateObjects !== null) {
            foreach ($this->stateObjects as $state) {
                $contentObject->assignState($state);
            }
        }
        $db->commit();

        if ($this->clearCache && ($modifiedNodes || $modifiedObject)) {
            eZContentCacheManager::clearContentCacheIfNeeded($objectId);
            eZContentObject::clearCache(array($objectId));
            eZContentObject::expireComplexViewModeCache();
            if ($modifiedNodes) {
                $nodeIdList = array();
                foreach ($modifiedNodes as $modifiedNode) {
                    $nodeIdList[] = $modifiedNode->attribute('node_id');
                    $parentNode = $modifiedNode->fetchParent();
                    if ($parentNode) {
                        eZContentCacheManager::clearContentCacheIfNeeded($parentNode->attribute('contentobject_id'));
                    }
                }
                eZContentCache::cleanup($nodeIdList);
            }
        }

        $this->_nodes = null;

        return $this;
    }

    /**
     * Update node with new information from $location
     * 
     * @return array List of fields which are changed
     */
    protected function updateNode(eZContentObjectTreeNode $node, array $location)
    {
        $changes = array();
        if (isset($location['uuid']) && $location['uuid'] != $node->attribute('remote_id')) {
            $node->setAttribute('remote_id', $location['uuid']);
            $changes[] = 'remote_id';
        }
        if (isset($location['sort_field']) && $location['sort_field'] != $node->attribute('sort_field')) {
            $node->setAttribute('sort_field', $location['sort_field']);
            $changes[] = 'sort_field';
        }
        if (isset($location['sort_order']) && $location['sort_order'] != $node->attribute('sort_order')) {
            $node->setAttribute('sort_order', $location['sort_order']);
            $changes[] = 'sort_order';
        }
        if (isset($location['priority']) && $location['priority'] != $node->attribute('priority')) {
            $node->setAttribute('priority', $location['priority']);
            $changes[] = 'priority';
        }
        if (isset($location['visibility'])) {
            if ($location['visibility'] === 'visibile') {
                $isHidden = false;
                $isInvisible = false;
            } else if ($location['visibility'] === 'hidden') {
                $isHidden = true;
                $isInvisible = true;
            } else if ($location['visibility'] === 'invisible') {
                $isHidden = false;
                $isInvisible = true;
            } else {
                throw ValueError("Visibility for node " . $node->attribute('remote_id') . " has unknown type '" . $location['visibility'] . "'");
            }
            if ($isHidden != (bool)$node->attribute('is_hidden') || $isInvisible != (bool)$node->attribute('is_invisible')) {
                $node->setAttribute('is_hidden', $isHidden);
                $node->setAttribute('is_invisibile', $isInvisible);
                $changes[] = 'is_hidden';
                $changes[] = 'is_invisible';
            }
        }
        return $changes;
    }

    /**
     * Create a new node to parent node $parentNode
     * 
     * @return eZContentObjectTreeNode The newly created node
     */
    protected function createLocation($parentNode)
    {
        if ($parentNode instanceof eZContentObjectTreeNode) {
            $parentNodeId = $parentNode->attribute('node_id');
        } else {
            $parentNodeId = $parentNode;
        }
        $contentObject = $this->contentObject;
        $node = $contentObject->addLocation($parentNodeId, true);
        return $node;
    }

    /**
     * Set a attribute in the content-object.
     *
     * This method can be called with different amount of parameters.
     *
     * With one parameter it expects an associative array, the array
     * contains all the parameters by name.
     *
     * With two parameters, the first is the identifier, and the second is
     * an associative array with the rest of the named parameters.
     *
     * With three parameters, the first is the identifier, the second is
     * the content, and the third is an associative array with
     * the rest of the named parameters.
     *
     * Example 1:
     * @code
     * $object->setAttribute('title', 'My title');
     * @encode
     *
     * Example 2:
     * @code
     * $object->setAttribute(array(
     *     'identifier' => 'title',
     *     'content' => 'My title'
     * ));
     * @encode
     *
     * Note: The attribute will only be set when the content-object is created.
     *
     * @return This instance, allows for chaining multiple calls.
     */
    public function setAttribute($identifier, $content = null, $attr = null)
    {
        $argc = func_num_args();
        if ($argc == 1) {
            if (is_array($identifier)) {
                $attr = $identifier;
                $identifier = $attr['identifier'];
                $content = isset($attr['content']) ? $attr['content'] : null;
            }
        } else if ($argc == 2) {
            $attr = array(
                'content' => $content,
            );
        } else {
            if (!$attr) {
                $attr = array();
            }
            if ($identifier === null) {
                $identifier = $attr['identifier'];
            }
            if ($content === null) {
                $content = isset($attr['content']) ? $attr['content'] : null;
            }
        }
        if (!($attr instanceof ContentObjectAttribute)) {
            $attr = new ContentObjectAttribute($identifier, null, $attr);
        }
        $attr->setValue($content);
        $this->attributesChange[$identifier] = $attr;
        return $this;
    }

    public function updateAttribute($attr)
    {
        if (!($attr instanceof ContentObjectAttribute)) {
            $value = $attr['value'];
            $attr = new ContentObjectAttribute($attr['identifier'], null, $attr);
            $attr->setValue($value);
        }
        $attr->update($this);
        return $attr;
    }

    /**
     * Ensure the locations array has the correct structure, and figure out a main location.
     */
    protected function adjustMainLocation($requireMain)
    {
        if (!$this->_locations) {
            return;
        }
        $hasMain = false;
        foreach ($this->_locations as $idx => $location) {
            if ($location['is_main']) {
                if ($hasMain) {
                    throw new ImproperlyConfigured("Only one location can be marked as main");
                }
                $hasMain = true;
            }
        }
        // Only set a main node if it is required, for instance adding a new
        // location to an existing object does not require adjusting main node
        if (!$hasMain && $this->_locations && $requireMain) {
            $this->_locations[0]['is_main'] = true;
        }
    }

    /**
     * Creates object with nodeAssignment from given parent Node, class ID and language code.
     *
     * @param eZContentObjectTreeNode $parentNode
     * @param int $contentClassID
     * @param string $languageCode
     * @param string|bool $remoteID
     *
     * @return eZContentObject|null
     */
    static function createWithNodeAssignment(&$locations, $contentClassID, $languageCode, $remoteID = false, $contentObject=null, $alwaysAvailable=null, $sectionId=null)
    {
        if ($contentClassID instanceof eZContentClass) {
            $class = $contentClassID;
        } else if ($contentClassID instanceof ContentType) {
            $class = $contentClassID->contentClass;
        } else {
            $class = eZContentClass::fetch($contentClassID);
        }

        // Check if we actually got a eZContentClass object
        if (!($class instanceof eZContentClass)) {
            throw new ObjectDoesNotExist("Could not fetch Content Class with ID '$contentClassID', cannot create object");
        }

        // Load parent nodes and determine main node
        $mainParentNode = self::prepareLocations($locations);

        // Set section of the newly created object to the section's value of it's parent object
        if (!$contentObject && $sectionId === null) {
            if ($mainParentNode) {
                $mainParentObject = $mainParentNode->attribute( 'object' );
                $sectionId = $mainParentObject->attribute( 'section_id' );
            }
        }
        if ($sectionId === null) {
            $sectionId = 0;
        }

        $db = \eZDB::instance();
        $db->begin();
        if (!$contentObject) {
            $contentObject = $class->instantiateIn( $languageCode, false, $sectionId, false, \eZContentObjectVersion::STATUS_INTERNAL_DRAFT );
            if (!$contentObject) {
                throw new CreationError("Could not create Content Object");
            }
        }

        // If always available is to be changed update object and nods
        if ($alwaysAvailable !== null) {
            if ($alwaysAvailable) {
                $contentObject->setAttribute('language_mask', $contentObject->attribute('language_mask') | 1);
            } else {
                $contentObject->setAttribute('language_mask', $contentObject->attribute('language_mask') & ~1);
            }
            $contentObject->sync(array('language_mask'));
        }

        // Create node-assignments for objects, mainly used for creating nodes upon publishing the first version
        foreach ($locations as $idx => $location) {
            if ($location['status'] == 'new') {
            } else if ($location['status'] == 'remove' || $location['status'] == 'move') {
                // Makes no sense to remove or move node when object is new, skip it
                continue;
            } else if ($location['status'] == 'update') {
                if (!isset($location['node'])) {
                    throw new UnsetValueError("Location index $idx contains 'update' entry but no 'node' was found");
                }
                // The object is new so the node clearly does not belong to it,
                // but this could be used to swap object for node to this new node
                // For now skip it
                continue;
            } else if ($location['status'] === 'nop') {
                // Nothing to do
                continue;
            }else {
                throw new ValueError("Unknown location status '" . $location['status'] . "' for index $idx");
            }
            $parentNode = $location['parent_node'];
            $parentNodeId = $parentNode->attribute('node_id');
            $isMain = $location['is_main'];
            $parentObject = $parentNode->attribute( 'object' );
            $existingAssignment = eZNodeAssignment::fetch($contentObject->attribute('id'), $contentObject->attribute('current_version'), $parentNodeId);
            if (!$existingAssignment) {
                $sortField = isset($location['sort_field']) ? $location['sort_field'] : $class->attribute('sort_field');
                $sortOrder = isset($location['sort_order']) ? $location['sort_order'] : $class->attribute('sort_order');
                // Create assignment, remote id for node needs to updated later on
                $nodeAssignment = $contentObject->createNodeAssignment(
                    $parentNodeId,
                    $isMain, false,
                    $sortField,
                    $sortOrder
                );
            }
        }
        $db->commit();
        return $contentObject;
    }

    /**
     * Make sure all locations have their required keys set.
     * Parent node is loaded from DB and place in 'parent_node' and main
     * parent node is determined.
     * 
     * @return eZContentObjectTreeNode The main parent node
     */
    protected static function prepareLocations(array &$locations)
    {
        $mainParentNode = null;
        foreach ($locations as $idx => $location) {
            $parentNode = isset($location['parent_node']) ? $location['parent_node'] : null;
            if (!$parentNode) {
                if (isset($location['parent_id'])) {
                    $parentNodeId = $location['parent_id'];
                    $parentNode = eZContentObjectTreeNode::fetch($parentNodeId);
                } else if (isset($location['parent_uuid'])) {
                    $parentNodeId = $parentNodeUuid = $location['parent_uuid'];
                    $parentNode = eZContentObjectTreeNode::fetchByRemoteID($parentNodeUuid);
                } else {
                    throw new UnsetValueError("No 'parent_id', 'parent_uuid' or 'parent_node' value is set for location[$idx]");
                }
                if (!$parentNode) {
                    throw new ObjectDoesNotExist("Invalid node ID, node not found: $parentNodeId");
                }
            }
            $locations[$idx]['parent_node'] = $parentNode;
            if ($location['is_main']) {
                $mainParentNode = $parentNode;
            }
        }
        return $mainParentNode;
    }

    /**
     * Returns the attribute map for the current content object/version.
     * The map contains the identifier as the key and the eZContentObjectAttribute as the value.
     *
     * @throw ObjectDoesNotExist if called without having a content object set.
     */
    public function attributeMap()
    {
        if ($this->dataMap === null) {
            if ($this->contentObject === null) {
                throw new ObjectDoesNotExist("No content object has been set, cannot load attribute map");
            }
            if ($this->contentVersion !== null) {
                $this->dataMap = $this->contentVersion->dataMap();
            } else {
                $languageCode = $this->languageCode ? $this->languageCode : false;
                $this->dataMap = $this->contentObject->fetchDataMap(/*version*/false, $languageCode);
            }
        }
        return $this->dataMap;
    }

    /**
     * Creates a list of state objects from the input array of wanted states.
     * 
     * @return array of eZContentObjectState
     */
    protected function loadStateObjects()
    {
        if ($this->states === null) {
            return null;
        }
        $stateObjects = array();
        foreach ($this->states as $idx => $state) {
            if ($state instanceof eZContentObjectState) {
                $stateObjects[] = $state;
                continue;
            }
            if (is_numeric($state)) {
                $state = eZContentObjectState::fetchById($state);
                if (!$state) {
                    throw new ObjectDoesNotExist("Content state with ID $state does not exist");
                }
                $stateObjects[] = $state;
            } else if (preg_match("|^([a-z][a-z0-9_-]*)/([a-z][a-z0-9_-]*)$|i", $state, $matches)) {
                $groupIdentifier = $matches[1];
                $stateIdentifier = $matches[2];
                $group = eZContentObjectStateGroup::fetchByIdentifier($groupIdentifier);
                if (!$group) {
                    throw new ObjectDoesNotExist("Content state group with identifier '$groupIdentifier' does not exist");
                }
                $state = eZContentObjectState::fetchByIdentifier($stateIdentifier, $group->attribute('id'));
                if (!$state) {
                    throw new ObjectDoesNotExist("Content state with '$stateIdentifier' and group '$groupIdentifier' does not exist");
                }
                $stateObjects[] = $state;
            } else if (!is_numeric($idx) && !is_numeric($state)) {
                // Assume $idx is group and $state is state
                $groupIdentifier = $idx;
                $stateIdentifier = $state;
                $group = eZContentObjectStateGroup::fetchByIdentifier($groupIdentifier);
                if (!$group) {
                    throw new ObjectDoesNotExist("Content state group with identifier '$groupIdentifier' does not exist");
                }
                $state = eZContentObjectState::fetchByIdentifier($stateIdentifier, $group->attribute('id'));
                if (!$state) {
                    throw new ObjectDoesNotExist("Content state with '$stateIdentifier' and group '$groupIdentifier' does not exist");
                }
                $stateObjects[] = $state;
            } else {
                throw new ValueError("Unsupport value for content state: " . var_export($state, true));
            }
        }
        return $stateObjects;
    }

    public function getAttribute($identifier)
    {
        if ($this->attributes === null) {
            $this->loadContentObject();
        }
        if (isset($this->attributesChange[$identifer])) {
            return $this->attributesChange[$identifer]->value;
        }
        if (isset($this->attributes[$identifer]) && $this->attributes[$identifer]->isDirty) {
            return $this->attributes[$identifer]->value;
        }
        if (isset($this->dataMap[$identifer])) {
            if ($this->dataMap[$identifer]->hasContent()) {
                return $this->dataMap[$identifer]->content();
            }
            return null;
        }
        throw new AttributeError("No such content attribute: $identifer");
    }

    public function __isset($name)
    {
        return $name === 'contentClass' || $name === 'identifier' || $name == 'locations' ||
               $name == 'nodes' || $name === 'stateObjects';
    }

    public function __get($name)
    {
        if ($name === 'contentClass') {
            if ($this->_contentClass === 'unset') {
                if (isset($this->identifier)) {
                    $this->_contentClass = eZContentClass::fetchByIdentifier($this->identifier);
                } else {
                    $this->_contentClass = null;
                }
            }
            return $this->_contentClass;
        } else if ($name === 'identifier') {
            return $this->_identifier;
        } else if ($name === 'locations') {
            if ($this->_locations === null) {
                $this->loadLocations();
            }
            return $this->_locations;
        } else if ($name === 'nodes') {
            if ($this->_nodes === null) {
                $this->loadNodes();
            }
            return $this->_nodes;
        } else if ($name === 'stateObjects') {
            if ($this->_stateObjects === 'unset') {
                $this->_stateObjects = $this->loadStateObjects();
            }
            return $this->_stateObjects;
        } else {
            throw new AttributeError("Unknown attribute $name on ContentObject instance");
        }
    }

    public function __set($name, $value)
    {
        if ($name === 'identifier') {
            if ($this->_identifier == $value) {
                return;
            }
            $this->_identifier = $value;
            $this->_contentClass = 'unset';
        } else {
            if (isset($this->$name)) {
                throw new AttributeError("Attribute $name cannot be modified on ContentObject instance");
            } else {
                throw new AttributeError("Unknown attribute $name on ContentObject instance");
            }
        }
    }
}
