<?php
namespace Aplia\Content;

use Exception;
use Aplia\Content\Exceptions\ValueError;
use Aplia\Content\Exceptions\UnsetValueError;
use Aplia\Content\Exceptions\ImproperlyConfigured;
use Aplia\Content\Exceptions\ObjectDoesNotExist;
use Aplia\Content\Exceptions\ObjectAlreadyExist;
use Aplia\Content\Exceptions\CreationError;
use Aplia\Content\Exceptions\ContentError;
use Aplia\Content\ContentObjectAttribute;
use eZContentClass;
use eZContentObject;
use eZContentObjectTreeNode;

class ContentObject
{
    public $id;
    public $uuid;
    public $identifier;
    public $languageCode;
    public $isInWorkflow = false;
    public $locations = array();
    public $attributes = array();
    public $attributesNew = array();
    public $attributesRemove = array();
    public $attributesChange = array();

    public $contentObject;
    public $contentClass;

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
            if (isset($params['languageCode'])) {
                $this->languageCode = $params['languageCode'];
            }
            if (isset($params['locations'])) {
                $this->locations = $params['locations'];
            }
            if (isset($params['contentObject'])) {
                if (!($params['contentObject'] instanceof eZContentObject)) {
                    throw new ValueError("Parameter 'contentObject' is not an instance of eZContentObject");
                }
                $this->contentObject = $params['contentObject'];
            }
            if (isset($params['contentClass'])) {
                if (!($params['contentClass'] instanceof eZContentClass)) {
                    throw new ValueError("Parameter 'contentClass' is not an instance of eZContentClass");
                }
                $this->contentClass = $params['contentClass'];
            }

            // Ensure the locations array has the correct structure, and figure out a main location
            $hasMain = false;
            foreach ($this->locations as $idx => $location) {
                if (!is_array($location)) {
                    $this->location[$idx] = $location = array('parent_id' => $location);
                }
                if (!isset($location['parent_id']) && !isset($location['parent_node'])) {
                    throw new UnsetValueError("No 'parent_id' or 'parent_node' value is set for location[$idx]");
                }
                if (!isset($location['is_main'])) {
                    $location['is_main'] = False;
                }
                if ($location['is_main']) {
                    if ($hasMain) {
                        throw new ImproperlyConfigured("Only one location can be marked as main");
                    }
                    $hasMain = true;
                }
            }
            if (!$hasMain && $this->locations) {
                $this->locations[0]['is_main'] = true;
            }
        }
    }

    public function resetPending()
    {
        $this->attributesNew = array();
        $this->attributesRemove = array();
        $this->attributesChange = array();
    }

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
                throw new ObjectAlreadyExist("Content Class with ID: '$this->id' already exists, cannot create");
            }
            $fields['id'] = $this->id;
        }
        if (!$this->contentClass) {
            $this->contentClass = eZContentClass::fetchByIdentifier($this->identifier);
            if (!is_object($this->contentClass)) {
                throw new ObjectDoesNotExist("Invalid content class identifier, no content class found: $this->identifier");
            }
        }

        if (!$this->locations) {
            throw new ImproperlyConfigured("No locations set, cannot create object");
        }

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

        $this->contentObject = self::createWithNodeAssignment(
            $this->locations,
            $contentClassID,
            $languageCode,
            $this->uuid ? $this->uuid : false
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
        // TODO: Update fields

        foreach ($this->attributesNew as $attr) {
            $attribute = $this->updateAttribute($attr);
            $contentAttribute = $attribute->contentAttribute;
            $this->attributes[$contentAttribute->attribute('contentclass_attribute_identifier')] = $attribute;
        }

        if ($publish) {
            // Getting the current transaction counter to check if all transactions are committed during content/publish operation (see below)
            $db = \eZDB::instance();
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

        return $this;
    }

    public function remove()
    {
        $this->_remove(true);
    }

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

    public function update()
    {
        foreach ($this->attributesNew as $attr) {
            $this->attributes[] = $this->updateAttribute($attr);
        }
        $this->attributesNew = array();
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
            if (is_array($content)) {
                $attr = $content;
                $content = $attr['content'];
            }
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
            $attr = new ContentObjectAttribute($identifier, $content, $attr);
        }
        $this->attributesNew[$identifier] = $attr;
        return $this;
    }

    public function updateAttribute($attr)
    {
        if (!($attr instanceof ContentObjectAttribute)) {
            $attr = new ContentObjectAttribute($attr['identifier'], $attr['value'], $attr);
        }
        $attr->update($this);
        return $attr;
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
    static function createWithNodeAssignment($locations, $contentClassID, $languageCode, $remoteID = false)
    {
        $class = eZContentClass::fetch( $contentClassID );
        // Check if the user has access to create a folder here
        if (!($class instanceof eZContentClass)) {
            throw new ObjectDoesNotExist("Could not fetch Content Class with ID '$contentClassID', cannot create object");
        }

        $mainParentNode = null;
        foreach ($locations as $idx => $location) {
            $parentNode = isset($location['parent_node']) ? $location['parent_node'] : null;
            if (!$parentNode) {
                $parentNodeId = $location['parent_id'];
                $parentNode = eZContentObjectTreeNode::fetch($parentNodeId);
                if (!$parentNode) {
                    throw new ObjectDoesNotExist("Invalid node ID, node not found: $parentNodeId");
                }
            }
            $locations[$idx]['parent_node'] = $parentNode;
            if ($location['is_main']) {
                $mainParentNode = $parentNode;
            }
        }

        // Set section of the newly created object to the section's value of it's parent object
        $sectionID = 0;
        if ($mainParentNode) {
            $mainParentObject = $mainParentNode->attribute( 'object' );
            $sectionID = $mainParentObject->attribute( 'section_id' );
        }

        $db = \eZDB::instance();
        $db->begin();
        $contentObject = $class->instantiateIn( $languageCode, false, $sectionID, false, \eZContentObjectVersion::STATUS_INTERNAL_DRAFT );
        if (!$contentObject) {
            throw new CreationError("Could not create Content Object");
        }

        foreach ($locations as $location) {
            $parentNode = $location['parent_node'];
            $isMain = $location['is_main'];
            $parentObject = $parentNode->attribute( 'object' );
            $nodeAssignment = $contentObject->createNodeAssignment(
                $parentNode->attribute( 'node_id' ),
                $isMain, $isMain ? $remoteID : false,
                $class->attribute( 'sort_field' ),
                $class->attribute( 'sort_order' )
            );
        }
        $db->commit();
        return $contentObject;
    }
}
