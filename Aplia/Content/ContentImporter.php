<?php
namespace Aplia\Content;
use DateTime;
use Aplia\Support\Arr;
use Aplia\Content\BinaryFile;
use Aplia\Content\ImageFile;
use Aplia\Content\Exceptions\ImportDenied;
use Aplia\Content\Exceptions\UnsetValueError;
use Aplia\Content\Exceptions\TypeError;
use Aplia\Content\Exceptions\ValueError;
use eZContentObject;
use eZContentObjectTreeNode;
use Aplia\Content\Exceptions\ObjectDoesNotExist;

class ContentImporter
{
    public $startNode;
    public $lastRecordType;
    public $hasIndex = false;
    public $hasBundle = false;
    public $recordCount = 0;

    public $interactive = true;
    public $askOverwrite = true;
    public $askNew = true;
    public $verbose = true;
    public $fileStorage;
    public $cli;

    // Maps section identifier to an object/class that will transform the content
    public $transformSection = array();
    // Maps section identifier to a new data structure, for instance to use an existing section
    public $mapSection = array();

    // Maps content language identifier to an object/class that will transform the content
    public $transformLanguage = array();
    // Maps content language identifier to a new data structure, for instance to use an existing content language
    public $mapLanguage = array();

    // Maps content state identifier to an object/class that will transform the content
    public $transformState = array();
    // Maps content state identifier to a new data structure, for instance to use an existing content state
    public $mapState = array();

    // Maps content content-class identifier to an object/class that will transform the content
    public $transformClass = array();
    // Maps content content-class identifier to a new data structure, for instance to use an existing content content-class
    public $mapClass = array();

    // Maps content object uuid to an object/class that will transform the content
    public $transformObjectByUuid = array();
    // Maps content object class identifier to an object/class that will transform the content
    public $transformObjectByClass = array();
    // Transformations to run all content objects
    public $transformObjects = array();
    // Transformations for links to owners
    public $transformObjectOwner = array();
    // Maps an object UUID to a new object structure, the structure either has the
    // object data for the replacement object, or has 'removed' => true to remove it.
    // This is used for nodes, parents, ownership and relations.
    public $mapObject = array();
    // Maps nodes from an old uuid to a new
    public $nodeUuidMap = array();
    
    // property $sectionIndex = array();
    // property $languageIndex = array();
    // property $stateIndex = array();
    // property $classIndex = array();
    public $objectIndex = array();
    public $nodeIndex = array();
    public $fileIndex = array();
    // Keeps track of new nodes which are missing the parent nodes
    // If it contains entries after finalize() these entries needs to be remapped to the startNode
    public $nodeMissingIndex = array();
    public $nodeIdIndex = array();
    // Keeps track of nodes which are the starting point of tree structures, see also nodeMissingIndex
    public $nodeStartIndex = array();

    // Queues, processed after import has collected data
    // List of uuids of all objects that are to be created, references $objectIndex
    public $newObjectQueue = array();
    // Contains a list of files which are created during import and should
    // be removed after import is completed.
    public $tempFiles = array();

    /**
     * Maps uuid of owner object to a list of object uuids the object owns. The list
     * uses the object uuid as the key and not the value, the value does not matter.
     * Using a key avoids duplicates and has quicker check for existence.
     * 
     * e.g. if admin user (id=14) owns two objects, the objects would have the structures:
     * @code
     * array(
     *   'uuid' => 'cafebabe',
     *   'owner' => array(
     *     'uuid' => 'abcdef123456',
     *     'object_id' => 14,
     *   )
     * )
     * array(
     *   'uuid' => '0987654321abc',
     *   'owner' => array(
     *     'uuid' => 'abcdef123456',
     *     'object_id' => 14,
     *   )
     * )
     * @endcode
     * 
     * From this $mapOwnerToObjects would then contain:
     * @code
     * array('abcdef123456' => array('cafebabe' => null, '0987654321abc' => null));
     * @endcode
     */
    public $mapOwnerToObjects = array();
    /**
     * Reverse relation between objects. Maps uuid of object being pointed to, to a list of
     * object uuids that point to the relation. The list uses the object uuid as the key
     * and not the value, the value does not matter.
     * Using a key avoids duplicates and has quicker check for existence.
     * 
     * e.g. say object1 is related to object2, the objects would have the structures:
     * @code
     * array(
     *   'uuid' => 'cafebabe',
     *   'related' => array(
     *     array(
     *       'uuid' => '0987654321abc',
     *       'object_id' => 14,
     *     )
     *   )
     * )
     * array(
     *   'uuid' => '0987654321abc',
     * )
     * @endcode
     * 
     * From this $mapReverseRelationToObject would then contain:
     * @code
     * array('0987654321abc' => array('0987654321abc' => null));
     * @endcode
     */
    public $mapReverseRelationToObject = array();

    // Used to resolve relative file imports
    protected $importPath = null;
    protected $importCounts = array(
        'section_create' => 0,
        'contentlanguage_create' => 0,
        'contentstate_create' => 0,
        'contentobject_update' => 0,
        'contentobject_create' => 0,
    );

    // 
    public $rootNode;

    public function __construct(array $options = null) {
        if (isset($options['startNode'])) {
            $this->startNode = $options['startNode'];
        } else if (isset($options['start_node'])) {
            $this->startNode = $options['start_node'];
        }
        if (isset($options['fileStorage'])) {
            $this->fileStorage = $options['fileStorage'];
        }
        if (isset($options['cli'])) {
            $this->cli = $options['cli'];
        }
        if (!$this->startNode) {
            throw new UnsetValueError("ContentImporter requires startNode/start_node set");
        }
        $this->addExistingNode($this->startNode, /*nodeUuid*/null, /*children*/null, true);
        $this->rootNode = eZContentObjectTreeNode::fetch(1);
        $this->addExistingNode($this->rootNode, /*nodeUuid*/null, /*children*/null, true);
        $this->contentNode = eZContentObjectTreeNode::fetch(ContentObject::mapTreeIdentifierToNode('content'));
        $this->addExistingNode($this->contentNode, /*nodeUuid*/null, /*children*/null, true);
        $this->usersNode = eZContentObjectTreeNode::fetch(ContentObject::mapTreeIdentifierToNode('users'));
        $this->addExistingNode($this->usersNode, /*nodeUuid*/null, /*children*/null, true);
        $this->mediaNode = eZContentObjectTreeNode::fetch(ContentObject::mapTreeIdentifierToNode('media'));
        $this->addExistingNode($this->mediaNode, /*nodeUuid*/null, /*children*/null, true);
    }

    public function __isset($name)
    {
        return $name === 'sectionIndex' || $name === 'languageIndex' || $name == 'stateIndex' ||
               $name == 'classIndex';
    }

    public function __get($name)
    {
        if ($name == 'sectionIndex') {
            $this->sectionIndex = array();
            return $this->loadSections();
        } else if ($name == 'languageIndex') {
            $this->languageIndex = array();
            return $this->loadLanguages();
        } else if ($name == 'stateIndex') {
            $this->stateIndex = array();
            return $this->loadStates();
        } else if ($name == 'classIndex') {
            $this->classIndex = array();
            return $this->loadClasses();
        }
    }

    public function prompt($prompt, $default) {
        $result = readline($prompt);
        if (!trim($result)) {
            return $default;
        }
        return trim(strtolower($result));
    }

    public function promptRaw($prompt, $default) {
        $result = readline($prompt);
        if (!trim($result)) {
            return $default;
        }
        return trim($result);
    }

    public function promptRequired($prompt, $values, $aliases=null) {
        while (true) {
            $result = readline($prompt);
            if (trim($result)) {
                $result = trim(strtolower($result));
                if (isset($aliases[$result])) {
                    $result = $aliases[$result];
                }
                if (in_array($result, $values)) {
                    return $result;
                }
            }
        }
    }

    public function promptYesOrNo($prompt)
    {
        return $this->promptRequired($prompt, array('yes', 'no'), array('y' => 'yes', 'n' => 'no'));
    }

    public function loadConfiguration($iniFilename)
    {
        if (!file_exists($iniFilename)) {
            return;
        }
        $dirPath = dirname($iniFilename);
        $iniFilename = basename($iniFilename);
        $ini = new \eZINI($iniFilename, $dirPath, /*$useTextCodec*/null, /*$useCache*/false, /*$useLocalOverrides*/false);

        // Handle section mapping/transforms
        if ($ini->hasVariable('Section', 'SectionMap')) {
            $sectionMap = $ini->variable('Section', 'SectionMap');
            foreach ($sectionMap as $newIdentifier => $existingIdentifier) {
                $section = \eZSection::fetchByIdentifier($existingIdentifier);
                if (!$section) {
                    throw new ImportDenied("Mapping of section from $newIdentifier to $existingIdentifier not possible as $existingIdentifier does not exist");
                }
                $this->mapSection[$newIdentifier] = array(
                    'identifier' => $existingIdentifier,
                    'name' => $section->attribute('name'),
                    'navigation_part_identifier' => $section->attribute('navigation_part_identifier'),
                );
            }
        }

        if ($ini->hasVariable('Section', 'Transform')) {
            $transforms = $ini->variable('Section', 'Transform');
            foreach ($transforms as $newIdentifier => $className) {
                if (!class_exists($className)) {
                    throw new ImportDenied("Transform class $className used for section $newIdentifier does not exist");
                }
                $this->transformSection[$newIdentifier] = new $className($ini);
            }
        }

        // Handle language mapping/transforms
        if ($ini->hasVariable('Language', 'LanguageMap')) {
            $languageMap = $ini->variable('Language', 'LanguageMap');
            foreach ($languageMap as $newLanguage => $existingLanguage) {
                $language = \eZContentLanguage::fetchByLocale($existingLanguage);
                if (!$language) {
                    throw new ImportDenied("Mapping of language from $newLanguage to $existingLanguage not possible as $existingLanguage does not exist");
                }
                $this->mapLanguage[$newLanguage] = array(
                    'locale' => $existingLanguage,
                    'name' => $language->attribute('name'),
                );
            }
        }

        if ($ini->hasVariable('Language', 'Transform')) {
            $transforms = $ini->variable('Language', 'Transform');
            foreach ($transforms as $newLanguage => $className) {
                if (!class_exists($className)) {
                    throw new ImportDenied("Transform class $className used for content language $newLanguage does not exist");
                }
                $this->transformLanguage[$newLanguage] = new $className($ini);
            }
        }

        // Handle content state mapping/transforms
        if ($ini->hasVariable('State', 'StateMap')) {
            $stateMap = $ini->variable('State', 'StateMap');
            foreach ($stateMap as $newState => $existingState) {
                $stateGroup = \eZContentObjectStateGroup::fetchByIdentifier($existingState);
                if (!$stateGroup) {
                    throw new ImportDenied("Mapping of content object state from $newState to $existingState not possible as $existingState does not exist");
                }
                $this->mapState[$newState] = array(
                    'identifier' => $existingState,
                );
            }
        }

        if ($ini->hasVariable('State', 'Transform')) {
            $transforms = $ini->variable('State', 'Transform');
            foreach ($transforms as $newState => $className) {
                if (!class_exists($className)) {
                    throw new ImportDenied("Transform class $className used for content object state $newState does not exist");
                }
                $this->transformState[$newState] = new $className($ini);
            }
        }

        // Handle content class mapping/transforms
        if ($ini->hasVariable('Class', 'ClassMaps')) {
            $classMaps = $ini->variable('Class', 'ClassMaps');
            foreach ($classMaps as $newClass) {
                $iniGroup = 'Class-' . $newClass;
                if (!$ini->hasGroup($iniGroup)) {
                    throw new ImportDenied("Class mapping for '$newClass' does not exist in INI file, group '$iniGroup' not found");
                }
                if ($ini->hasVariable($iniGroup, 'Class')) {
                    $existingClass = $ini->variable($iniGroup, 'Class');
                } else {
                    $existingClass = $newClass;
                }
                $classAction = $ini->hasVariable($iniGroup, 'Action') ? $ini->variable($iniGroup, 'Action') : 'use';
                if ($classAction !== 'skip' && $classAction !== 'use') {
                    throw new ImportDenied("Class mapping for '$newClass': Action='${classAction}' is not valid, use either 'use' or 'skip'");
                }
                $attributeMap = array();
                if ($ini->hasVariable($iniGroup, 'AttributeMap')) {
                    $attributeMapSettings = $ini->variable($iniGroup, 'AttributeMap');
                    foreach ($attributeMapSettings as $key => $data) {
                        if ($data === ':skip') {
                            $attributeMap[$key] = array(
                                'action' => 'skip',
                            );
                        } else {
                            $attributeMap[$key] = array(
                                'action' => 'use',
                                'identifier' => $data,
                            );
                        }
                    }
                }
                $contentClass = \eZContentClass::fetchByIdentifier($existingClass);
                if (!$contentClass) {
                    throw new ImportDenied("Mapping of content class from '$newClass' to '$existingClass' not possible as '$existingClass' does not exist");
                }
                $attributes = array();
                foreach ($contentClass->fetchAttributes() as $classAttribute) {
                    $attributeIdentifier = $classAttribute->attribute('identifier');
                    $attributeType = $classAttribute->attribute('data_type_string');
                    $attributes[$attributeIdentifier] = array(
                        'type' => $attributeType,
                    );
                }
                foreach ($attributeMap as $importAttribute => $mapping) {
                    if ($mapping['action'] === 'skip') {
                        if ($this->verbose) {
                            echo "Class attribute ${existingClass}/${importAttribute} should be skipped\n";
                        }
                        $attributeMap[$importAttribute]['type'] = array(
                            'action' => 'skip',
                            'identifier' => null,
                            'type' => null,
                        );
                        continue;
                    }
                    $existingAttribute = $mapping['identifier'];
                    if (!isset($attributes[$existingAttribute])) {
                        throw new ImportDenied("Cannot map content class attribute '$newClass/$importAttribute' to '$existingClass/$existingAttribute', '$existingClass/$existingAttribute' does not exist");
                    }
                    $attributeMap[$importAttribute]['type'] = array(
                        'action' => 'transform',
                        'identifier' => $existingAttribute,
                        'type' => $attributes[$existingAttribute]['type'],
                    );
                }
                $this->mapClass[$newClass] = array(
                    'identifier' => $existingClass,
                    'attributeMap' => $attributeMap,
                    'action' => $classAction,
                );
            }
        }

        if ($ini->hasVariable('Class', 'Transform')) {
            $transforms = $ini->variable('Class', 'Transform');
            foreach ($transforms as $newClass => $className) {
                if (!class_exists($className)) {
                    throw new ImportDenied("Transform class $className used for content class $newClass does not exist");
                }
                $this->transformClass[$newClass] = new $className($this, $ini);
            }
        }

        /////////////////////////////////////////////
        // Objects and nodes
        /////////////////////////////////////////////

        // Mapping for node, from old uuid to new uuid
        if ($ini->hasVariable('Object', 'NodeMap')) {
            $nodeMaps = $ini->variable('Object', 'NodeMap');
            foreach ($nodeMaps as $importNodeUuid => $remappedNodeUuid) {
                $node = eZContentObjectTreeNode::fetchByRemoteID($remappedNodeUuid);
                if (!$node) {
                    throw new ImportDenied("Transform of parent UUID from $importNodeUuid to $remappedNodeUuid not possible as the new object does not exist");
                }
                $this->nodeUuidMap[$importNodeUuid] = $remappedNodeUuid;
                $this->addExistingNode($node, $remappedNodeUuid);
            }
        }

        // Handle object mapping/transforms
        if ($ini->hasVariable('Object', 'ObjectMap')) {
            $objectMap = $ini->variable('Object', 'ObjectMap');
            foreach ($objectMap as $importUuid => $remappedUuid) {
                $contentObject = \eZContentObject::fetchByRemoteID($remappedUuid);
                if (!$contentObject) {
                    throw new ImportDenied("Mapping of content object from $importUuid to $remappedUuid not possible as $remappedUuid does not exist");
                }
                $this->mapObject[$importUuid] = array(
                    'uuid' => $remappedUuid,
                    'name' => $contentObject->attribute('name'),
                    'class_identifier' => $contentObject->contentClassIdentifier(),
                    'section_identifier' => $contentObject->sectionIdentifier(),
                );
            }
        }

        if ($ini->hasVariable('Object', 'TransformByUuid')) {
            $transforms = $ini->variable('Object', 'TransformByUuid');
            foreach ($transforms as $importUuid => $className) {
                if (!class_exists($className)) {
                    throw new ImportDenied("Transform class $className used for content object ${importUuid} does not exist");
                }
                $this->transformObjectByUuid[$importUuid] = new $className($this, $ini);
            }
        }

        if ($ini->hasVariable('Object', 'TransformByClass')) {
            $transforms = $ini->variable('Object', 'TransformByClass');
            foreach ($transforms as $importClass => $className) {
                if (!class_exists($className)) {
                    throw new ImportDenied("Transform class $className used for content objects with class '${importClass}' does not exist");
                }
                $this->transformObjectByClass[$importClass] = new $className($this, $ini);
            }
        }

        if ($ini->hasVariable('Object', 'TransformOwner')) {
            $transforms = $ini->variable('Object', 'TransformOwner');
            foreach ($transforms as $className) {
                if (!class_exists($className)) {
                    throw new ImportDenied("Transform class $className used for content objects owner does not exist");
                }
                $this->transformObjectOwner[] = new $className($this, $ini);
            }
        }

        if ($ini->hasVariable('Object', 'Transform')) {
            $transforms = $ini->variable('Object', 'Transform');
            foreach ($transforms as $className) {
                if (!class_exists($className)) {
                    throw new ImportDenied("Transform class $className used for content objects does not exist");
                }
                $this->transformObjects[] = new $className($this, $ini);
            }
        }
    }

    /**
     * Changes the import path for future imports.
     * The path is used when looking up relative paths, such as for
     * imported files. This allows the paths to adjusted relative to
     * the import path.
     * 
     * @param $path String with path or empty to use relative path
     */
    public function setImportPath($path=null)
    {
        $this->importPath = ($path && $path !== '.' && $path !== './') ? $path : "";
    }

    /**
     * Loads all existing eZSection identifiers and registers them in the index.
     */
    public function loadSections()
    {
        foreach (\eZSection::fetchList(false) as $section) {
            $this->sectionIndex[$section['identifier']] = array(
                'id' => $section['id'],
                'status' => 'reference',
                'identifier' => $section['identifier'],
                'name' => $section['name'],
                'navigation_part_identifier' => $section['navigation_part_identifier'],
            );
        }
        return $this->sectionIndex;
    }

    /**
     * Loads all existing eZContentLanguage identifiers and registers them in the index.
     */
    public function loadLanguages()
    {
        foreach (\eZContentLanguage::fetchList() as $language) {
            $this->languageIndex[$language->attribute('locale')] = array(
                'locale' => $language->attribute('locale'),
                'status' => 'reference',
                'name' => $language->attribute('name'),
            );
        }
        return $this->languageIndex;
    }

    /**
     * Loads all existing eZContentObjectStateGroup identifiers and registers them in the index.
     */
    public function loadStates()
    {
        foreach (\eZContentObjectStateGroup::fetchObjectList(\eZContentObjectStateGroup::definition()) as $stateGroup) {
            $stateIdentifiers = array();
            $states = $stateGroup->states();
            foreach ($states as $state) {
                $stateIdentifiers[] = $state->attribute('identifier');
            }
            $this->stateIndex[$stateGroup->attribute('identifier')] = array(
                'id' => $stateGroup->attribute('id'),
                'status' => 'reference',
                'identifier' => $stateGroup->attribute('identifier'),
                'states' => $stateIdentifiers,
            );
        }
        return $this->stateIndex;
    }

    /**
     * Loads all existing eZContentClass identifiers and registers them in the index.
     */
    public function loadClasses()
    {
        foreach (\eZContentClass::fetchAllClasses() as $class) {
            $attributes = array();
            $dataMap = $class->dataMap();
            $identifier = $class->attribute('identifier');
            foreach ($dataMap as $attributeIdentifier => $attribute) {
                $attributes[$attributeIdentifier] = array(
                    'id' => $attribute->attribute('id'),
                    'identifier' => $attributeIdentifier,
                    'type' => $attribute->attribute('data_type_string'),
                    'can_translate' => $attribute->attribute('can_translate') && $attribute->dataType()->isTranslatable(),
                );
            }
            $this->classIndex[$identifier] = array(
                'id' => $class->attribute('id'),
                'status' => 'reference',
                'identifier' => $identifier,
                'attributes' => $attributes,
                'status' => 'reference',
            );
        }
        return $this->classIndex;
    }

    public function remapSectionIdentifier($identifier)
    {
        $sectionData = array(
            'identifier' => $identifier,
        );
        if (isset($this->transformSection[$identifier])) {
            $newData = $this->transformSection[$identifier]->transform($sectionData);
            if ($newData) {
                $identifier = $newData['identifier'];
            }
        } else if (isset($this->transformSection['*'])) {
            $newData = $this->transformSection['*']->transform($sectionData);
            if ($newData) {
                $sectionData = $newData;
                $identifier = $newData['identifier'];
            }
        } else if (isset($this->mapSection[$identifier])) {
            $newData = $this->mapSection[$identifier];
            if ($newData) {
                $identifier = $newData['identifier'];
            }
        }
        return $identifier;
    }

    /**
     * Transforms content object data by applying remapping of
     * object uuid and node uuid as well running any Transformation
     * classes.
     * 
     * Returns the transformed object data.
     * 
     * @return array
     */
    public function transformContentObject($objectData)
    {
        $uuid = $objectData['uuid'];
        $identifier = $objectData['class_identifier'];

        // See if object needs to be remapped
        if (isset($this->mapObject[$uuid])) {
            $newData = $this->mapObject[$uuid];
            if ($this->verbose) {
                echo "Object $uuid remapped to ${newData['uuid']}\n";
            }
            if (isset($newData['removed'])) {
                $objectData['removed'] = true;
            }
            if (isset($newData['id'])) {
                $objectData['object_id'] = $newData['id'];
            }
            $objectData['uuid'] = $newData['uuid'];
        }

        // Remap main node
        $mainNodeUuid = Arr::get(Arr::get($objectData, 'main_node'), 'uuid');
        if ($mainNodeUuid && isset($this->nodeUuidMap[$mainNodeUuid])) {
            $newMainNodeUuid = $this->nodeUuidMap[$mainNodeUuid];
            if ($this->verbose) {
                echo "Object with UUID $uuid, main node UUID remapped from $mainNodeUuid to $newMainNodeUuid\n";
            }
            $objectData['main_node']['uuid'] = $newMainNodeUuid;
        }

        // Remap owner
        $owner = Arr::get($objectData, 'owner');
        $ownerUuid = Arr::get($owner, 'uuid');
        $owner['original_uuid'] = $ownerUuid;
        if ($ownerUuid && isset($this->mapObject[$ownerUuid])) {
            $newOwner = $this->mapObject[$ownerUuid];
            $newOwnerUuid = $newOwner['uuid'];
            $ownerName = Arr::get($owner, 'name', '<no-name>');
            $newOwnerName = Arr::get($newOwner, 'name', '<no-name>');
            if ($this->verbose) {
                echo "Object with UUID $uuid, owner UUID (name=${ownerName}) remapped from $ownerUuid to $newOwnerUuid (name=${newOwnerName})\n";
            }
            $objectData['owner']['uuid'] = $newOwnerUuid;
        }

        // Remap section
        if (isset($objectData['section_identifier'])) {
            $objectData['section_identifier'] = $this->remapSectionIdentifier($objectData['section_identifier']);
        }

        // Remap relations
        $relations = Arr::get($objectData, 'related');
        if ($relations) {
            foreach ($relations as $idx => $relation) {
                $relationUuid = Arr::get($relation, 'uuid');
                if (isset($this->mapObject[$relation['uuid']])) {
                    $newRelation = $this->mapObject[$relation['uuid']];
                    if (isset($newRelation['removed'])) {
                        if ($this->verbose) {
                            echo "Object with UUID $uuid, relation UUID (name=${relationName}) $relationUuid was removed\n";
                        }
                        unset($objectData['related'][$idx]);
                        continue;
                    }
                    $newRelationUuid = $newRelation['uuid'];
                    $relationName = Arr::get($relation, 'name', '<no-name>');
                    if ($this->verbose) {
                        echo "Object with UUID $uuid, relation UUID (name=${relationName}) remapped from $relationUuid to $newRelationUuid\n";
                    }
                    $objectData['related'][$idx]['uuid'] = $newRelationUuid;
                }
            }
        }

        // Remap locations
        $locations = Arr::get($objectData, 'locations');
        if ($locations) {
            foreach ($locations as $idx => $location) {
                $nodeUuid = $location['uuid'];
                $parentUuid = $location['parent_node_uuid'];
                $parentId = $location['parent_node_id'];
                // Store original values in separate fields
                $preLocations[$idx] = array(
                    'original_uuid' => $nodeUuid,
                    'original_parent_node_uuid' => $parentUuid,
                );
                // Then see if parent is remapped
                // Special case when parent is 1, the root node
                if ($parentId == 1) {
                    $newParentUuid = $this->rootNode->attribute('remote_id');
                    if ($this->verbose) {
                        echo "Object with UUID $uuid, location $nodeUuid remapped root-parent from $parentUuid to $newParentUuid\n";
                    }
                    $objectData['locations'][$idx]['parent_node_uuid'] = $newParentUuid;
                    continue;
                } else if (isset($this->nodeUuidMap[$parentUuid])) {
                    $newParentUuid = $this->nodeUuidMap[$parentUuid];
                    if ($this->verbose) {
                        echo "Object with UUID $uuid, location $nodeUuid remapped parent from $parentUuid to $newParentUuid\n";
                    }
                    $parentUuid = $newParentUuid;
                    $objectData['locations'][$idx]['parent_node_uuid'] = $parentUuid;
                }
                // And if node uuid needs remap
                if (isset($this->nodeUuidMap[$nodeUuid])) {
                    $newNodeUuid = $this->nodeUuidMap[$nodeUuid];
                    if ($this->verbose) {
                        echo "Object with UUID $uuid, location remapped UUID from $nodeUuid to $newNodeUuid\n";
                    }
                    $objectData['locations'][$idx]['uuid'] = $newNodeUuid;
                }
            }
        }

        if (isset($this->transformObjectByUuid[$uuid])) {
            $newData = $this->transformObjectByUuid[$uuid]->transformContentObject($objectData);
            if ($newData) {
                if ($newData['uuid'] !== $objectData['uuid'] &&
                    !isset($this->mapObject[$objectData['uuid']])) {
                    // Store remapping for quicker lookups later on
                    $this->mapObject[$objectData['uuid']] = array(
                        'uuid' => $newData['uuid'],
                        'name' => Arr::get($newData, 'name'),
                        'class_identifier' => Arr::get($newData, 'class_identifier'),
                        'section_identifier' => Arr::get($newData, 'section_identifier'),
                    );
                    if (isset($newData['removed'])) {
                        $this->mapObject[$objectData['uuid']]['removed'] = true;
                    }
                }
                $objectData = $newData;
            }
        } else if (isset($this->transformObjectByClass[$identifier])) {
            $newData = $this->transformObjectByClass[$identifier]->transformContentObject($objectData);
            if ($newData) {
                if ($newData['uuid'] !== $objectData['uuid'] &&
                    !isset($this->mapObject[$objectData['uuid']])) {
                    // Store remapping for quicker lookups later on
                    $this->mapObject[$objectData['uuid']] = array(
                        'uuid' => $newData['uuid'],
                        'name' => Arr::get($newData, 'name'),
                        'class_identifier' => Arr::get($newData, 'class_identifier'),
                        'section_identifier' => Arr::get($newData, 'section_identifier'),
                    );
                    if (isset($newData['removed'])) {
                        $this->mapObject[$objectData['uuid']]['removed'] = true;
                    }
                }
                $objectData = $newData;
            }
        }

        if ($this->transformObjects) {
            foreach ($this->transformObjects as $transformObject) {
                $newData = $transformObject->transformContentObject($objectData);
                if ($newData) {
                    if ($newData['uuid'] !== $objectData['uuid'] &&
                        !isset($this->mapObject[$objectData['uuid']])) {
                        // Store remapping for quicker lookups later on
                        $this->mapObject[$objectData['uuid']] = array(
                            'uuid' => $newData['uuid'],
                            'name' => Arr::get($newData, 'name'),
                            'class_identifier' => Arr::get($newData, 'class_identifier'),
                            'section_identifier' => Arr::get($newData, 'section_identifier'),
                        );
                        if (isset($newData['removed'])) {
                            $this->mapObject[$objectData['uuid']]['removed'] = true;
                        }
                    }
                    $objectData = $newData;
                }
            }
        }
        return $objectData;
    }

    public function importFile($fileData)
    {
        if (!isset($fileData['uuid'])) {
            throw new TypeError("Key 'uuid' missing from file record");
        }
        $uuid = $fileData['uuid'];
        $isTemporary = false;
        $originalPath = isset($fileData['original_path']) ? $fileData['original_path'] : null;
        $fileSize = Arr::get($fileData, 'size');
        $fileSizeText = $fileSize === null ? 'unknown size' : "${fileSize} bytes";
        $md5 = isset($fileData['md5']) ? $fileData['md5'] : null;
        $originalFilename = $originalPath ? basename($originalPath) : null;
        if (isset($fileData['content_b64'])) {
            if ($this->fileStorage === null) {
                $fileStorage = null;
                if ($this->interactive) {
                    echo "Import contains embedded file data and no storage folder has been set\n";
                    $fileStorage = $this->promptRaw("Path to temporary storage (empty to quit): ", "");
                }
                if (!$fileStorage) {
                    throw new ImportDenied("Cannot import embedde file data without a storage folder");
                }
                $this->fileStorage = $fileStorage;
                if (!is_dir($this->fileStorage)) {
                    mkdir($this->fileStorage, 0777, true);
                }
            }
            $filePath = $this->fileStorage . '/' . $uuid;
            if ($originalPath && ($pos = strrpos($originalPath, "."))) {
                $filePath = $filePath . substr($originalPath, $pos);
            }
            if (file_exists($filePath) && filesize($filePath) === $fileSize && md5_file($filePath) == $md5) {
                if ($this->verbose) {
                    echo "Using stored file for ${originalFilename}, ${fileSizeText}";
                    if ($md5) {
                        echo ", md5=${md5}";
                    }
                    echo "\n";
                }
            } else {
                if ($this->verbose) {
                    echo "Importing file ${originalFilename}, ${fileSizeText}";
                    if ($md5) {
                        echo ", md5=${md5}";
                    }
                    echo "\n";
                }
                file_put_contents($filePath, base64_decode($fileData['content_b64'], true));
                $isTemporary = true;
            }
            unset($fileData['content_b64']);
        } else {
            if (!isset($fileData['path'])) {
                throw new TypeError("File record has neither 'path' nor 'content_b64' set, cannot import");
            }
            $filePath = $fileData['path'];
            if (preg_match("#^(/|[a-z]:[/\\\\])#", $filePath)) {
                // Abs path, keep as-is
            } else if ($this->importPath) {
                // Adjust relative path to import path
                $filePath = $this->importPath . "/" . $filePath;
            }
            if (!file_exists($filePath)) {
                throw new ImportDenied("Imported file '${filePath}' with uuid $uuid does not exist");
            }
            $filePath = realpath($filePath);
            if ($this->verbose) {
                echo "Using stored file for ${originalFilename}, ${fileSizeText}";
                if ($md5) {
                    echo ", md5=${md5}";
                }
                echo "\n";
            }
        }
        $mimeType = @mime_content_type($filePath);
        $this->fileIndex[$uuid] = array(
            'uuid' => $uuid,
            'status' => 'new',
            'path' => $filePath,
            'md5' => $md5,
            'mime_type' => $mimeType,
            'size' => $fileSize,
            'original_path' => $originalPath,
            'has_temp_file' => $isTemporary,
        );
        if ($isTemporary) {
            $this->tempFiles[$uuid] = $filePath;
        }
    }

    public function cleanupTemporary()
    {
        foreach ($this->tempFiles as $idx => $filePath) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            unset($this->tempFiles[$idx]);
        }
    }

    public function importSection($sectionData)
    {
        if (!isset($sectionData['identifier'])) {
            throw new TypeError("Key 'identifier' missing from section record");
        }
        $identifier = $sectionData['identifier'];
        $originalIdentifier = $identifier;
        if (isset($this->transformSection[$identifier])) {
            $newData = $this->transformSection[$identifier]->transform($sectionData);
            if ($newData) {
                $sectionData = $newData;
                $identifier = $sectionData['identifier'];
            }
        } else if (isset($this->transformSection['*'])) {
            $newData = $this->transformSection['*']->transform($sectionData);
            if ($newData) {
                $sectionData = $newData;
                $identifier = $sectionData['identifier'];
            }
        } else if (isset($this->mapSection[$identifier])) {
            $newData = $this->mapSection[$identifier];
            if ($newData) {
                $sectionData = $newData;
                $identifier = $sectionData['identifier'];
            }
        }
        $wasText = '';
        if ($identifier != $originalIdentifier) {
            $wasText = " (was '${originalIdentifier}')";
        }
        $name = Arr::get($sectionData, 'name');
        $part = Arr::get($sectionData, "navigation_part_identifier");
        if (isset($this->sectionIndex[$identifier])) {
            $existing = $this->sectionIndex[$identifier];
            if ($name == $existing['name'] && $part == $existing['navigation_part_identifier']) {
                // Same as existing section, skip
                if ($this->verbose) {
                    echo "Section '${identifier}'${wasText} already exist\n";
                }
                return;
            } else if ($this->askOverwrite) {
                echo "Section '$identifier' already exist\n",
                     "Name: ", $existing['name'], "\n",
                     "Navigation Part: ", $existing['navigation_part_identifier'], "\n";
                if ($this->promptYesOrNo("Do you wish to overwrite it? [yes|no] ") !== "yes") {
                    return;
                }
                // TODO: Overwrite existing
                return;
            } else {
                return;
            }
        } else {
            if ($this->askNew) {
                if ($this->promptYesOrNo("Section '${identifier}'${wasText} does not exist, do you wish to import it? [yes|no] ") !== "yes") {
                    throw new ImportDenied("Section '${identifier}'${wasText}  not imported, cannot continue");
                }
            }
        }
        if (!$name) {
            $name = $identifier;
        }
        if (!$part) {
            $part = "ezcontentnavigationpart";
        }
        $section = new \eZSection(array(
            'identifier' => $identifier,
            'name' => $name,
            'navigation_part_identifier' => $part,
        ));
        $section->store();
        // Store in index with ID
        $this->sectionIndex[$identifier] = array(
            'id' => $section->attribute('id'),
            'status' => 'new',
        );

        if ($this->verbose) {
            echo "Imported section $identifier (was $originalIdentifier)\n";
        }
        $this->importCounts['section_create'] = $this->importCounts['section_create'] + 1;
    }

    public function importContentLanguage($languageData)
    {
        if (!isset($languageData['locale'])) {
            throw new TypeError("Key 'locale' missing from content language record");
        }
        $identifier = $languageData['locale'];
        if (isset($this->transformLanguage[$identifier])) {
            $newData = $this->transformLanguage[$identifier]->transform($languageData);
            if ($newData) {
                $languageData = $newData;
                $identifier = $languageData['locale'];
            }
        } else if (isset($this->transformLanguage['*'])) {
            $newData = $this->transformLanguage['*']->transform($languageData);
            if ($newData) {
                $languageData = $newData;
                $identifier = $languageData['locale'];
            }
        } else if (isset($this->mapLanguage[$identifier])) {
            $newData = $this->mapLanguage[$identifier];
            if ($newData) {
                $languageData = $newData;
                $identifier = $languageData['locale'];
            }
        }
        $name = Arr::get($languageData, 'name');
        if (isset($this->languageIndex[$identifier])) {
            $existing = $this->languageIndex[$identifier];
            if ($name == $existing['name']) {
                // Same as existing language, skip
                if ($this->verbose) {
                    echo "Content language $identifier already exist\n";
                }
                return;
            } else if ($this->askOverwrite) {
                echo "Content language '$identifier' already exist\n",
                     "Name: ", $existing['name'], "\n";
                if ($this->promptYesOrNo("Do you wish to overwrite it? [yes|no] ") !== "yes") {
                    return;
                }
                // TODO: Overwrite existing
                return;
            } else {
                return;
            }
        } else {
            if ($this->askNew) {
                if ($this->promptYesOrNo("Content language '$identifier' does not exist, do you wish to import it? [yes|no] ") !== "yes") {
                    throw new ImportDenied("Content language '$identifier' not imported, cannot continue");
                }
            }
        }
        if (!$name) {
            $name = $identifier;
        }
        $language = eZContentLanguage::addLanguage($identifier, $name);
        // Store in index with ID
        $this->languageIndex[$identifier] = array(
            'id' => $language->attribute('id'),
            'locale' => $identifier,
            'name' => $language->attribute('name'),
            'status' => 'new',
        );

        if ($this->verbose) {
            echo "Imported content language $identifier\n";
        }
        $this->importCounts['contentlanguage_create'] = $this->importCounts['contentlanguage_create'] + 1;
    }

    public function importState($stateData)
    {
        if (!isset($stateData['identifier'])) {
            throw new TypeError("Key 'identifier' missing from state record");
        }
        $identifier = $stateData['identifier'];
        if (isset($this->transformState[$identifier])) {
            $newData = $this->transformState[$identifier]->transform($stateData);
            if ($newData) {
                $stateData = $newData;
                $identifier = $stateData['identifier'];
            }
        } else if (isset($this->transformState['*'])) {
            $newData = $this->transformState['*']->transform($stateData);
            if ($newData) {
                $stateData = $newData;
                $identifier = $stateData['identifier'];
            }
        } else if (isset($this->mapState[$identifier])) {
            $newData = $this->mapState[$identifier];
            if ($newData) {
                $stateData = $newData;
                $identifier = $stateData['identifier'];
            }
        }
        $part = Arr::get($stateData, "navigation_part_identifier");
        if (isset($this->stateIndex[$identifier])) {
            $existing = $this->stateIndex[$identifier];
            if (!isset($stateData['states']) || !is_array($stateData['states'])) {
                // No states defined, assume it is the same
                return;
            }
            // See if the states that are required by import exist in the current content state
            if (!array_diff(array_keys($stateData['states']), $this->stateIndex[$identifier]['states'])) {
                // All required states exist, no change
                return;
            }
            var_dump(array_keys($stateData['states']), $this->stateIndex[$identifier]['states'], array_diff(array_keys($stateData['states']), $this->stateIndex[$identifier]['states']));
            // TODO: Check if all states are the same, if so skip it
            if ($this->askOverwrite) {
                echo "Content object state '$identifier' already exist\n";
                if ($this->promptYesOrNo("Do you wish to overwrite it? [yes|no] ") !== "yes") {
                    return;
                }
                // TODO: Overwrite existing
                return;
            } else {
                return;
            }
        } else {
            if ($this->askNew) {
                if ($this->promptYesOrNo("Content object state '$identifier' does not exist, do you wish to import it? [yes|no] ") !== "yes") {
                    throw new ImportDenied("Content object state '$identifier' not imported, cannot continue");
                }
            }
        }
        $stateGroup = new \eZContentObjectStateGroup(array(
            'identifier' => $identifier,
        ));
        $translationObjects = $stateGroup->allTranslations();

        $translations = Arr::get($stateData, 'translations');
        $locales = array();
        if ($translations) {
            foreach ($translations as $locale => $translation) {
                $language = \eZContentLanguage::fetchByLocale($locale);
                if (!$language) {
                    throw new ImportDenied("Content language $locale does not exist, cannot translate state $identifier");
                }
                $languageId = $language->attribute('id');
                $locales[] = $locale;
                foreach ($translationObjects as $translationObject) {
                    if ($translationObject->attribute('real_language_id') == $languageId) {
                        $translationObject->setAttribute('name',  Arr::get($translation, 'name', $identifier));
                        $translationObject->setAttribute('description', Arr::get($translation, 'description'));
                        break;
                    }
                }
            }
        }

        $stateGroup->store();
        $id = $stateGroup->attribute('id');

        // $stateGroup->setAttribute('language_mask', \eZContentLanguage::maskByLocale($locales));
        // if ($locales) {
        //     $stateGroup->setAttribute('default_language_id', \eZContentLanguage::idByLocale($locales[0]));
        // }
        // $stateGroup->store();

        // Import all states and translations for each state
        $states = Arr::get($stateData, 'states');
        if ($states) {
            foreach ($states as $stateIdentifier => $stateValue) {
                $state = new \eZContentObjectState(array(
                    'group_id' => $id,
                    'identifier' => $stateIdentifier,
                ));
                $translationObjects = $stateGroup->allTranslations();
                $locales = array();
                foreach (Arr::get($stateValue, 'translations', array()) as $locale => $translation) {
                    $language = \eZContentLanguage::fetchByLocale($locale);
                    if (!$language) {
                        throw new ImportDenied("Content language $locale does not exist, cannot translate state $identifier");
                    }
                    $languageId = $language->attribute('id');
                    $locales[] = $locale;
                    foreach ($translationObjects as $translationObject) {
                        if ($translationObject->attribute('real_language_id') == $languageId) {
                            $translationObject->setAttribute('name', Arr::get($translation, 'name', $identifier));
                            $translationObject->setAttribute('description', Arr::get($translation, 'description'));
                        }
                    }
                }
                $state->store();

                // $state->setAttribute('language_mask', \eZContentLanguage::maskByLocale($locales));
                // if ($locales) {
                //     $state->setAttribute('default_language_id', \eZContentLanguage::idByLocale($locales[0]));
                // }
                // $state->store();
            }
        }
        $this->importCounts['contentstate_create'] = $this->importCounts['contentstate_create'] + 1;

        // Store in index with ID
        $this->stateIndex[$identifier] = array(
            'id' => $stateGroup->attribute('id'),
            'status' => 'new',
        );
        if ($this->verbose) {
            echo "Imported content object state group $identifier\n";
        }
    }

    public function importContentClass($classData)
    {
        if (!isset($classData['identifier'])) {
            throw new TypeError("Key 'identifier' missing from content-class record");
        }
        $identifier = $classData['identifier'];
        $remappedTypeMap = null;
        $skipMap = array();
        if (isset($this->mapClass[$identifier])) {
            $newData = $this->mapClass[$identifier];
            if ($newData) {
                if ($newData['action'] === 'skip') {
                    if ($this->verbose) {
                        echo "Content class ${identifier} should not be imported, skipped\n";
                    }
                    return;
                }
                if (isset($newData['identifier'])) {
                    $classData['identifier'] = $newData['identifier'];
                }
                if (Arr::get($classData, 'sparse', false)) {
                    if (isset($newData['attributeMap'])) {
                        $typeMap = Arr::get($classData, "type_map");
                        if (!is_array($typeMap)) {
                            throw new ImportDenied("Import of sparse content-class $identifier failed, no type_map defined");
                        }
                        // Rewrite type_map to use the newly assigned attribute names/types
                        $newTypeMap = array();
                        foreach ($typeMap as $newIdentifier => $newType) {
                            if (isset($newData['attributeMap'][$newIdentifier])) {
                                $mapping = $newData['attributeMap'][$newIdentifier];
                                if ($mapping['action'] === 'skip') {
                                    $skipMap[$newIdentifier] = true;
                                } else if ($mapping['action'] === 'change') {
                                    if (isset($mapping['type'])) {
                                        $newType = $mapping['type'];
                                    }
                                    $newTypeMap[$mapping['identifier']] = $newType;
                                }
                            } else {
                                $newTypeMap[$newIdentifier] = $newType;
                            }
                        }
                        $remappedTypeMap = $newTypeMap;
                    }
                }
                $identifier = $classData['identifier'];
            }
        }
        if (isset($this->transformClass[$identifier])) {
            $newData = $this->transformClass[$identifier]->transformContentClass($classData);
            if ($newData) {
                $classData = $newData;
                $identifier = $classData['identifier'];
            }
        }
        if (isset($this->transformClass['*'])) {
            $newData = $this->transformClass['*']->transformContentClass($classData);
            if ($newData) {
                $classData = $newData;
                $identifier = $classData['identifier'];
            }
        }
        $sparse = Arr::get($classData, 'sparse', false);
        if (!$sparse) {
            throw new ImportDenied("Import of full content-class definitions are not yet supported");
        }
        else {
            if (isset($this->mapClass[$identifier]) && $this->mapClass[$identifier]['action'] === 'skip') {
                if ($this->verbose) {
                    echo "Content class ${identifier} should not be imported, skipped\n";
                }
                return;
            }
            if (!isset($this->classIndex[$identifier])) {
                throw new ImportDenied("Import requires content-class $identifier but it does not exist on site, cannot import content");
            }

            $classDefinition = $this->classIndex[$identifier];
            if ($remappedTypeMap !== null) {
                $typeMap = $remappedTypeMap;
            } else {
                $typeMap = Arr::get($classData, "type_map");
                if (!is_array($typeMap)) {
                    throw new ImportDenied("Import of sparse content-class $identifier failed, no type_map defined");
                }
            }
            // Verify that all required attributes exist in class and are of required type
            // TODO: Should be a way to allow certain data-type mismatches, e.g. ezstring to eztext, maybe via transforms?
            foreach ($typeMap as $attributeIdentifier => $attributeType) {
                if (isset($skipMap[$identifier])) {
                    continue;
                } else if (!isset($classDefinition['attributes'][$attributeIdentifier])) {
                    throw new ImportDenied("Import of sparse content-class $identifier failed, attribute '$attributeIdentifier' does not exist");
                }
                $attributeDefinition = $classDefinition['attributes'][$attributeIdentifier];
                if ($attributeDefinition['type'] != $attributeType) {
                    throw new ImportDenied("Import of sparse content-class $identifier failed, attribute '$attributeIdentifier' was expected to be of type $attributeType but is actually " . $attributeDefinition['type']);
                }
            }
            $this->classIndex[$identifier]['remapping'] = $remappedTypeMap;
        }
    }

    public function contentObjectExists($uuid)
    {
        $db = eZDB::instance();
        $remoteID = $db->escapeString( $remoteID );
        $resultArray = $db->arrayQuery('SELECT id FROM ezcontentobject WHERE remote_id=\'' . $remoteID . '\'');
        return count($resultArray) == 1;
    }

    /**
     * Helper method for getting the attribute by identifier from the object data.
     * The attribute is fetched either from translated attributes or non-translated.
     * 
     * @param $data Object data
     * @param $identifier Identifier for attribute to fetch
     * @return any The content for the attribute or null if it does not exist
     */
    public function getObjectAttribute($data, $identifier)
    {
        foreach ($data['translations'] as $lang => $langData) {
            if (isset($data['translations'][$lang]['attributes'][$identifier])) {
                return $data['translations'][$lang]['attributes'][$identifier];
            }
        }
        if (isset($data['attributes'][$identifier])) {
            return $data['attributes'][$identifier];
        }
    }

    /**
     * Helper method for setting the content of an attribute in the object data.
     * The attribute is set either in the translated attributes or non-translated.
     * 
     * @param $data Object data to modfiy
     * @param $identifier Identifier for attribute to modify
     * @param $value The value to set as attribute content
     */
    public function setObjectAttribute(&$data, $identifier, $value)
    {
        if (isset($data['attributes'][$identifier])) {
            $data['attributes'][$identifier] = $value;
            return;
        }
        foreach ($data['translations'] as $lang => $langData) {
            if (isset($data['translations'][$lang]['attributes'][$identifier])) {
                $data['translations'][$lang]['attributes'][$identifier] = $value;
                return;
            }
        }
    }

    /**
     * Helper method for adding a new attribute to the object data.
     * If $language is false the attribute is added to non-translatable
     * attributes, otherwise it is added to the attributes in the specified
     * language.
     * 
     * @param $data Object data to modfiy
     * @param $identifier Identifier for attribute to modify
     * @param $language string with locale or false
     * @param $value The value to set as attribute content
     */
    public function addObjectAttribute(&$data, $identifier, $language, $value)
    {
        if (!$language) {
            $data['attributes'][$identifier] = $value;
            return;
        }
        $data['translations'][$language]['attributes'][$identifier] = $value;
    }

    protected static function remapAttributes($attributes, $mapping)
    {
        $newAttributes = array();
        foreach ($attributes as $attributeIdentifier => $attributeData) {
            if (!isset($mapping[$attributeIdentifier])) {
                // No change to attribute
                $newAttributes[$attributeIdentifier] = $attributeData;
                continue;
            }
            $attributeTransform = $mapping[$attributeIdentifier];
            if ($attributeTransform['action'] === 'skip') {
                // Skip it
            } else if ($attributeTransform['action'] === 'transform') {
                $newIdentifier = $attributeTransform['identifier'];
                $newAttributes[$newIdentifier] = $attributeData;
            } else {
                throw new ImportDenied("Mapping for class attribute $attributeIdentifier has unknown 'action' value: " . var_export($attributeTransform['action'], true));
            }
        }
        return $newAttributes;
    }

    public function importContentObject($objectData)
    {
        if (!isset($objectData['uuid'])) {
            throw new TypeError("Key 'uuid' missing from content-object record");
        }
        if (!isset($objectData['class_identifier'])) {
            throw new TypeError("Key 'class_identifier' missing from content-object record");
        }
        // TODO: Handle remapping/transforms
        $uuid = $objectData['uuid'];
        $preData = array(
            'original_uuid' => $objectData['uuid'],
            'original_section_identifier' => Arr::get($objectData, 'section_identifier'),
        );
        $preOwner = array(
            'original_uuid' => Arr::get(Arr::get($objectData, 'owner'), 'uuid'),
        );
        $preMain = array(
            'original_uuid' => Arr::get(Arr::get($objectData, 'main_node'), 'uuid'),
        );
        $preRelations = array();
        $relations = Arr::get($objectData, 'related');
        if ($relations) {
            foreach ($relations as $idx => $related) {
                $preRelations[$idx] = array(
                    'original_uuid' => Arr::get($related, 'uuid'),
                );
            }
        }

        $preLocations = array();
        unset($objectData['action_update_object']);
        unset($objectData['update_attributes']);
        $objectData = $this->transformContentObject($objectData);
        $uuid = $objectData['uuid'];

        $classIdentifier = Arr::get($objectData, 'class_identifier');
        $classMap = null;
        if (isset($this->mapClass[$classIdentifier])) {
            $classMap = $this->mapClass[$classIdentifier];
            if ($classMap['action'] === 'skip') {
                if ($this->verbose) {
                    echo "Content-object with UUID $uuid using class ${classIdentifier} should not be imported, skipped\n";
                }
                return;
            }
            if ($classIdentifier !== $classMap['identifier']) {
                $newClassIdentifier = $classMap['identifier'];
                if ($this->verbose) {
                    echo "Content-object with UUID $uuid using class ${classIdentifier} switch class to ${newClassIdentifier}\n";
                }
                $classIdentifier = $newClassIdentifier;
            }
        }

        // If the object already exist then no objectIndex record is performed,
        // however the any new locations will still be added further down
        $isInIndex = false;
        if (isset($this->objectIndex[$uuid])) {
            if ($this->verbose) {
                echo "Content-object with uuid $uuid already exists, skipping\n";
            }
            $objectEntry = $this->objectIndex[$uuid];
            $mainNodeUuid = Arr::get($objectEntry, 'main_node_uuid');
            $isInIndex = true;
        } else {
            $mainNodeUuid = Arr::get(Arr::get($objectData, 'main_node'), 'uuid');

            $attributes = Arr::get($objectData, 'attributes');
            $translations = Arr::get($objectData, 'translations');
            if ($classMap) {
                $attributeMaps = $classMap['attributeMap'];
                // Remove and rename attributes
                if ($attributes) {
                    $attributes = self::remapAttributes($attributes, $attributeMaps);
                }
                if ($translations) {
                    foreach ($translations as $language => $translationData) {
                        if (isset($translationData['attributes'])) {
                            $translations[$language]['attributes'] = self::remapAttributes($translationData['attributes'], $attributeMaps);
                        }
                    }
                }
            }
            $objectEntry = array(
                'uuid' => $uuid,
                'status' => 'new',
                'object_status' => Arr::get($objectData, 'status'),
                'class_identifier' => $classIdentifier,
                'original_uuid' => Arr::get($objectData, 'original_uuid'),
                'original_id' => Arr::get($objectData, 'object_id'),
                'original_section_identifier' => Arr::get($objectData, 'original_section_identifier'),
                'owner' => Arr::get($objectData, 'owner'),
                'section_identifier' => Arr::get($objectData, 'section_identifier'),
                'is_always_available' => Arr::get($objectData, 'is_always_available'),
                'states' => Arr::get($objectData, 'states'),
                'published_date' => Arr::get($objectData, 'published_date'),
                'translations' => $translations,
                'attributes' => $attributes,
                'main_node_uuid' => null,
                'original_main_node_uuid' => $preMain['original_uuid'],
                'relations' => array(),
                // Locations is an array of uuid that point to the nodeIndex
                'locations' => array(),
            );
            if (!$objectEntry['owner'] && $objectEntry['owner']['original_uuid']) {
                $objectEntry['owner'] = array();
            }
            $objectEntry['owner']['original_uuid'] = $preOwner['original_uuid'];
            if (isset($objectEntry['owner']['uuid'])) {
                $ownerUuid = $objectEntry['owner']['uuid'];
                if (!isset($this->mapOwnerToObjects[$ownerUuid])) {
                    $this->mapOwnerToObjects[$ownerUuid] = array();
                }
                $this->mapOwnerToObjects[$ownerUuid][$uuid] = null;
            }
            $classData = $this->classIndex[$classIdentifier];
            if ($objectEntry['translations']) {
                foreach ($objectEntry['translations'] as $language => $languageData) {
                    if (!isset($languageData[$language])) {
                        continue;
                    }
                    foreach ($languageData[$language]['attributes'] as $attributeIdentifier => $attributeValue) {
                        $this->importContentObjectAttribute($objectEntry, $classData, $language, $attributeIdentifier, $attributeValue);
                    }
                }
            }
            if ($objectEntry['attributes']) {
                foreach ($objectEntry['attributes'] as $attributeIdentifier => $attributeValue) {
                    $this->importContentObjectAttribute($objectEntry, $classData, $language, $attributeIdentifier, $attributeValue);
                }
            }
        }

        // Include relations
        $relations = Arr::get($objectData, 'related');
        if ($relations) {
            foreach ($relations as $idx => $related) {
                $relatedUuid = Arr::get($related, 'uuid');
                if (!$relatedUuid) {
                    $relatedUuid = eZRemoteIdUtility::generate(Arr::get($related, 'object_id'));
                }
                // Skip if relation already exists
                if (isset($objectEntry['relations'][$relatedUuid])) {
                    continue;
                }
                $relatedEntry = array(
                    'uuid' => Arr::get($related, 'uuid'),
                    'name' => Arr::get($related, 'name'),
                    'class_identifier' => Arr::get($related, 'class_identifier'),
                    'original_uuid' => $preRelations[$idx]['original_uuid'],
                    'status' => 'new',
                );
                $objectEntry['relations'][$relatedUuid] = $relatedEntry;
                // Store reverse-relation for later lookups
                if (!isset($this->mapReverseRelationToObject[$relatedEntry['uuid']])) {
                    $this->mapReverseRelationToObject[$relatedEntry['uuid']] = array();
                }
                $this->mapReverseRelationToObject[$relatedEntry['uuid']][$uuid] = null;
            }
        }
        $this->objectIndex[$uuid] = $objectEntry;

        if (!$isInIndex) {
            $this->objectIndex[$uuid] = array_merge($this->objectIndex[$uuid], $preData);
        }
        if (isset($objectData['update_attributes'])) {
            $this->objectIndex[$uuid]['update_attributes'] = $objectData['update_attributes'];
        }
        if (isset($objectData['action_update_object'])) {
            $this->objectIndex[$uuid]['action_update_object'] = $objectData['action_update_object'];
        }
        if (!$isInIndex) {
            if ($this->objectIndex[$uuid]['translations']) {
                $translations = array_keys($this->objectIndex[$uuid]['translations']);
                $mainLanguage = array_shift($translations);
                $this->objectIndex[$uuid]['main_language'] = $mainLanguage;
                $this->objectIndex[$uuid]['main_name'] = $this->objectIndex[$uuid]['translations'][$mainLanguage]['name'];
            }
            // Check if object already exists
            $contentObject = eZContentObject::fetchByRemoteID($uuid);
            if ($contentObject) {
                // Object already exists, should only be updated
                $this->objectIndex[$uuid]['status'] = 'present';
            } else if ($this->objectIndex[$uuid]['object_status'] !== null && $this->objectIndex[$uuid]['object_status'] !== 'published') {
                // Do not import drafts and archived objects by default
                $this->objectIndex[$uuid]['status'] = 'removed';
            }
            if ($this->objectIndex[$uuid]['status'] === 'new') {
                $this->newObjectQueue[$uuid] = $uuid;
            }
        }

        $locations = Arr::get($objectData, 'locations');
        if ($locations) {
            $hasMain = false;
            $locations = array_values($locations);
            foreach ($locations as $idx => $location) {
                if (!isset($location['uuid'])) {
                    throw new TypeError("Key 'uuid' missing from content-object location $idx on object $uuid");
                }
                if (!isset($location['parent_node_uuid'])) {
                    throw new TypeError("Key 'parent_node_uuid' missing from content-object location $idx on object $uuid");
                }
                $locations[$idx]['is_main'] = $location['uuid'] == $mainNodeUuid;
                if ($locations[$idx]['is_main']) {
                    $hasMain = true;
                }
            }
            if (!$this->objectIndex[$uuid]['main_node_uuid'] && !$hasMain) {
                // No main location found and object does not have one set, pick the first one
                $locations[0]['is_main'] = true;
                $mainNodeUuid = $locations[0]['uuid'];
                $this->objectIndex[$uuid]['main_node_uuid'] = $mainNodeUuid;
            }
            foreach ($locations as $idx => $location) {
                $nodeUuid = $location['uuid'];
                $parentUuid = $location['parent_node_uuid'];
                if (isset($this->nodeIndex[$nodeUuid])) {
                    if (!$isInIndex) {
                        if ($this->verbose) {
                            echo "Content-node with uuid $nodeUuid already exists, skipping\n";
                        }
                    } else {
                        if ($this->verbose) {
                            echo "Content-node with uuid $nodeUuid already imported, skipping node\n";
                        }
                    }
                    continue;
                }
                $originalNodeId = Arr::get($location, 'node_id');
                // Store the node/location, referenced by uuid
                $this->nodeIndex[$nodeUuid] = array(
                    'uuid' => $nodeUuid,
                    'status' => 'new',
                    'parent_uuid' => $parentUuid,
                    'object_uuid' => $uuid,
                    'original_uuid' => Arr::get($location, 'original_uuid'),
                    'original_parent_node_uuid' => Arr::get($location, 'original_parent_node_uuid'),
                    'original_node_id' => $originalNodeId,
                    'sort_by' => Arr::get($location, 'sort_by'),
                    'priority' => Arr::get($location, 'priority'),
                    'visibility' => Arr::get($location, 'visibility'),
                    'is_main' => $location['is_main'],
                    'url_alias' => Arr::get($location, 'url_alias'),
                    'children' => array(),
                );
                $this->objectIndex[$uuid]['locations'][$nodeUuid] = array(
                    'uuid' => $nodeUuid,
                    'parent_node_uuid' => $parentUuid,
                    'priority' => Arr::get($location, 'priority'),
                    'visibility' => Arr::get($location, 'visibility'),
                );
                // Fill in pre-transform information
                if (isset($preLocations[$idx])) {
                    $this->nodeIndex[$nodeUuid] = array_merge($this->nodeIndex[$nodeUuid], $preLocations[$idx]);
                }
                $node = eZContentObjectTreeNode::fetchByRemoteID($nodeUuid);
                if ($node) {
                    // If node already exists then only update it
                    $this->nodeIndex[$nodeUuid]['status'] = 'present';
                }
                // Store mapping from original node_id to uuid, can be used to fix imported content references
                if ($originalNodeId) {
                    $this->nodeIdIndex[$originalNodeId] = $nodeUuid;
                }
                // Connect to existing parents, or place in index for missing parents
                if (isset($this->nodeIndex[$parentUuid])) {
                    $this->nodeIndex[$parentUuid]['children'][] = $nodeUuid;
                } else {
                    if (!isset($this->nodeMissingIndex[$parentUuid])) {
                        $this->nodeMissingIndex[$parentUuid] = array();
                    }
                    $this->nodeMissingIndex[$parentUuid][] = $nodeUuid;
                }
                // Check if there are nodes that references this node as parent, if so fix relationship
                // by adding all references as children
                if (isset($this->nodeMissingIndex[$nodeUuid])) {
                    $this->nodeIndex[$nodeUuid]['children'] = array_merge($this->nodeIndex[$nodeUuid]['children'], $this->nodeMissingIndex[$nodeUuid]);
                    // Parent is no longer missing so remove index entry
                    unset($this->nodeMissingIndex[$nodeUuid]);
                }
            }
        }

        if (!$isInIndex) {
            // If the object uuid was remapped then store the remap so that any
            // future references to the original uuid gets the new uuid
            $objectEntry = $this->objectIndex[$uuid];
            $originalUuid = Arr::get($objectEntry, 'original_uuid');
            if ($originalUuid && $uuid != $originalUuid) {
                $existingObject = eZContentObject::fetchByRemoteID($uuid);
                $remapData = array(
                    'uuid' => $uuid,
                    'name' => Arr::get($objectEntry, 'name'),
                    'class_identifier' => Arr::get($objectEntry, 'class_identifier'),
                    'section_identifier' => Arr::get($objectEntry, 'section_identifier'),
                );
                if ($existingObject) {
                    $remapData = array_merge($remapData, array(
                        'name' => $existingObject->attribute('name'),
                        'class_identifier' => $existingObject->contentClassIdentifier(),
                        'section_identifier' => $existingObject->sectionIdentifier(),
                    ));
                }
                $this->mapObject[$originalUuid] = $remapData;

                $this->remapExistingObjects($uuid, $originalUuid);
            }
        }

    }

    /**
     * Called on each attribute on imported objects, may updated extra indexs such as relations.
     *
     * @param array $objectEntry
     * @param array $class
     * @param string $language
     * @param string $attributeIdentifier
     * @param mixed $attributeValue
     * @return void
     */
    public function importContentObjectAttribute($objectEntry, $class, $language, $attributeIdentifier, $attributeValue)
    {
        if (!isset($class['attributes'][$attributeIdentifier])) {
            throw new ObjectDoesNotExist("Content attribute '$attributeIdentifier' does not exist, cannot import object attribute");
        }
        $dataType = $class['attributes'][$attributeIdentifier]['type'];
        $objectUuid = $objectEntry['uuid'];
        if ($dataType === 'ezobjectrelation') {
            if (!$attributeValue || !is_array($attributeValue)) {
                return;
            }
            $relatedUuid = $attributeValue['object_uuid'];
            if (!isset($this->mapReverseRelationToObject[$relatedUuid])) {
                $this->mapReverseRelationToObject[$relatedUuid] = array();
            }
            $this->mapReverseRelationToObject[$relatedUuid][$objectUuid] = null;
        } else if ($dataType === 'ezobjectrelationlist') {
            if (!$attributeValue || !isset($attributeValue['relations']) || !is_array($attributeValue['relations'])) {
                return;
            }
            foreach ($attributeValue['relations'] as $relationData) {
                $relatedUuid = $relationData['object_uuid'];
                if (!isset($this->mapReverseRelationToObject[$relatedUuid])) {
                    $this->mapReverseRelationToObject[$relatedUuid] = array();
                }
                $this->mapReverseRelationToObject[$relatedUuid][$objectUuid] = null;
            }
        } else if ($dataType === 'ezxmltext') {
            if (!isset($attributeValue['xml'])) {
                return;
            }
            $dom = new \DOMDocument('1.0', 'utf-8');
            if (!@$dom->loadXML($attributeValue)) {
                return;
            }
            $xpath = new \DOMXPath($dom);
            $embedObjects = array();
            $embeds = $xpath->query('//embed');
            // Find all embedded objects and record the original uuid as a relation, this will later on be used
            // to find the new uuid if it has changed
            foreach ($embeds as $embed) {
                $embedUuid = $embed->getAttribute('uuid');
                if (!$objectUuid) {
                    continue;
                }
                if (!isset($this->mapReverseRelationToObject[$embedUuid])) {
                    $this->mapReverseRelationToObject[$embedUuid] = array();
                }
                $this->mapReverseRelationToObject[$embedUuid][$objectUuid] = null;
            }
        }
    }

    /**
     * Goes over all objects and finds references to $originalUuid and remaps it to $newUuid.
     * It checks ownership, relations and attributes.
     */
    public function remapExistingObjects($newUuid, $originalUuid)
    {
        // If the object is used as owner of other objects update the owner references
        if (isset($this->mapOwnerToObjects[$originalUuid])) {
            if (!isset($this->mapOwnerToObjects[$newUuid])) {
                $this->mapOwnerToObjects[$newUuid] = array();
            }
            foreach ($this->mapOwnerToObjects[$originalUuid] as $objectUuid => $ignore) {
                // Move owner objects to new uuid
                $this->mapOwnerToObjects[$newUuid][$objectUuid] = null;
                $objectData = $this->objectIndex[$objectUuid];
                // Since the object is in $mapOwnerToObjects then it is assumed to have an 'owner' entry
                $objectData['owner']['uuid'] = $newUuid;
                if (!isset($objectData['owner']['original_object_id'])) {
                    $objectData['owner']['original_object_id'] = $objectData['owner']['object_id'];
                }
                $objectData['owner']['object_id'] = $newUuid;
                $this->objectIndex[$objectUuid] = $objectData;
            }
            unset($this->mapOwnerToObjects[$originalUuid]);
        }

        // If there are other objects with relations to this object then update the reference
        if (isset($this->mapReverseRelationToObject[$originalUuid])) {
            if (!isset($this->mapReverseRelationToObject[$newUuid])) {
                $this->mapReverseRelationToObject[$newUuid] = array();
            }
            foreach ($this->mapReverseRelationToObject[$originalUuid] as $objectUuid => $ignore) {
                // Move relation to new uuid
                $this->mapReverseRelationToObject[$newUuid][$objectUuid] = null;
                $objectData = $this->objectIndex[$objectUuid];
                $classData = $this->classIndex[$objectData['class_identifier']];
                // Since the object is in mapReverseRelationToObject then it is assumed to have a 'related' entry
                foreach($objectData['relations'] as $idx => $relation) {
                    // Remap if it matches
                    if ($relation['uuid'] !== $originalUuid) {
                        continue;
                    }
                    $relation['uuid'] = $newUuid;
                    $objectData['relations'][$idx] = $relation;
                }

                if ($objectData['translations']) {
                    foreach ($objectData['translations'] as $language => $languageData) {
                        if (!isset($languageData['attributes'])) {
                            continue;
                        }
                        foreach ($languageData['attributes'] as $attributeIdentifier => $attributeValue) {
                            $newValue = $this->remapAttributeObjects($newUuid, $originalUuid, $objectData, $classData, $language, $attributeIdentifier, $attributeValue);
                            if ($newValue !== null) {
                                $languageData['attributes'][$attributeIdentifier] = $newValue;
                            }
                        }
                        $objectData['translations'][$language] = $languageData;
                    }
                }
                if ($objectData['attributes']) {
                    foreach ($objectData['attributes'] as $attributeIdentifier => $attributeValue) {
                        $newValue = $this->remapAttributeObjects($newUuid, $originalUuid, $objectData, $classData, $language, $attributeIdentifier, $attributeValue);
                        if ($newValue !== null) {
                            $objectData['attributes'][$attributeIdentifier] = $newValue;
                        }
                    }
                }
                $this->objectIndex[$objectUuid] = $objectData;
            }
            unset($this->mapReverseRelationToObject[$originalUuid]);
        }
    }

    /**
     * Called on each attribute on imported objects, may updated extra indexs such as relations.
     *
     * @param array $objectEntry
     * @param array $class
     * @param string $language
     * @param string $attributeIdentifier
     * @param mixed $attributeValue
     * @return void
     */
    public function remapAttributeObjects($newUuid, $originalUuid, $objectEntry, $class, $language, $attributeIdentifier, $attributeValue)
    {
        $dataType = $class['attributes'][$attributeIdentifier]['type'];
        $objectUuid = $objectEntry['uuid'];
        if ($dataType === 'ezobjectrelation') {
            if (!$attributeValue || !is_array($attributeValue)) {
                return;
            }
            $relatedUuid = $attributeValue['object_uuid'];
            // Check if it is the same object
            if ($relatedUuid !== $originalUuid) {
                return;
            }
            $attributeValue['object_uuid'] = $newUuid;
            unset($attributeValue['object_id']);
            unset($attributeValue['name']);
            return $attributeValue;
        } else if ($dataType === 'ezobjectrelationlist') {
            if (!$attributeValue || !isset($attributeValue['relations']) || !is_array($attributeValue['relations'])) {
                return;
            }
            $isModified = false;
            foreach ($attributeValue['relations'] as $idx => $relationData) {
                $relatedUuid = $relationData['object_uuid'];
                if ($relatedUuid !== $originalUuid) {
                    continue;
                }
                $relationData['object_uuid'] = $newUuid;
                unset($relationData['object_id']);
                unset($relationData['name']);
                $attributeValue['relations'][$idx] = $relationData;
                $isModified = true;
            }
            if ($isModified) {
                return $attributeValue;
            }
        } else if ($dataType === 'ezxmltext') {
            if (!isset($attributeValue['xml'])) {
                return;
            }
            $dom = new \DOMDocument('1.0', 'utf-8');
            if (!@$dom->loadXML($attributeValue['xml'])) {
                return null;
            }
            $xpath = new \DOMXPath($dom);
            $isModified = false;
            $embeds = $xpath->query('//embed');
            // Find embedded objects which has the original uuid and change it to point to the new uuid
            foreach ($embeds as $embed) {
                $embedUuid = $embed->getAttribute('uuid');
                if ($embedUuid !== $originalUuid) {
                    continue;
                }
                $embed->setAttribute('uuid', $newUuid);
                $isModified = true;
            }
            if ($isModified) {
                $xml = $dom->saveXML();
                $attributeValue['xml'] = $xml;
                return $attributeValue;
            }
        }
    }

    public function importIndex($data)
    {
        $date = Arr::get($data, 'export_date');
        echo "Import created at ", $date ? $date : "unknown date", "\n";
        $types = Arr::get($data, 'types');
        if (!$types) {
            throw new ImportDenied("No types defined in import");
        }
        $type_counts = Arr::get($data, 'type_counts');
        if ($type_counts) {
            $counts = array();
            foreach ($types as $type) {
                $count = Arr::get($type_counts, $type);
                $counts[] = "${type}: ${count}";
            }
            echo "Import contains: ", implode(", ", $counts), "\n";
        } else {
            echo "Import contains: ", implode(", ", $types), "\n";
        }
        $this->hasIndex = true;
    }

    public function importBundle($data)
    {
        $date = Arr::get($data, 'export_date');
        echo "Import created at ", $date ? $date : "unknown date", "\n";
        $types = Arr::get($data, 'types');
        if (!$types) {
            $types = array_diff(array_keys($data), array('__type__', 'export', 'export_date'));
        }
        $type_counts = Arr::get($data, 'type_counts');
        if ($type_counts) {
            $counts = array();
            foreach ($types as $type) {
                $count = Arr::get($type_counts, $type);
                $counts[] = "${type}: ${count}";
            }
            echo "Import contains: ", implode(", ", $counts), "\n";
        } else {
            echo "Import contains: ", implode(", ", $types), "\n";
        }
        $this->hasBundle = true;
    }

    public function importRecord($data)
    {
        if (!isset($data['__type__'])) {
            throw new TypeError("No type available on record, cannot import");
        }
        $type = $data['__type__'];
        if ($type == 'index') {
            $this->importIndex($data);
        } else if ($type == 'ezc_content_bundle') {
            // The bundle contains all records so iterate over and import
            $this->importBundle($data);
            if (isset($data['content_languages'])) {
                foreach ($data as $record) {
                    $this->importContentLanguage($record);
                    $this->recordCount += 1;
                }
            }
            if (isset($data['sections'])) {
                foreach ($data as $record) {
                    $this->importSection($record);
                    $this->recordCount += 1;
                }
            }
            if (isset($data['content_states'])) {
                foreach ($data as $record) {
                    $this->importState($record);
                    $this->recordCount += 1;
                }
            }
            if (isset($data['content_classes'])) {
                foreach ($data as $record) {
                    $this->importContentClass($record);
                    $this->recordCount += 1;
                }
            }
            if (isset($data['content_objects'])) {
                foreach ($data as $record) {
                    $this->importContentObject($record);
                    $this->recordCount += 1;
                }
            }
        } else if ($type == 'file') {
            $this->importFile($data);
            $this->recordCount += 1;
        } else if ($type == 'ez_section') {
            $this->importSection($data);
            $this->recordCount += 1;
        } else if ($type == 'ez_contentlanguage') {
            $this->importContentLanguage($data);
            $this->recordCount += 1;
        } else if ($type == 'ez_contentstate') {
            $this->importState($data);
            $this->recordCount += 1;
        } else if ($type == 'ez_contentclass') {
            $this->importContentClass($data);
            $this->recordCount += 1;
        } else if ($type == 'ez_contentobject') {
            $this->importContentObject($data);
            $this->recordCount += 1;
        } else {
            throw new TypeError("Unsupported record type $type");
        }
        $this->lastRecordType = $type;
    }

    public function verify()
    {
        // Check all missing parents and see if they exist in the database
        foreach ($this->nodeMissingIndex as $nodeUuid => $children) {
            $node = eZContentObjectTreeNode::fetchByRemoteID($nodeUuid);
            if (!$node) {
                continue;
            }
            $this->addExistingNode($node, $nodeUuid, $children);
        }

        // Check if there are still missing parent nodes
        if ($this->nodeMissingIndex) {
            $missingPaths = array();
            foreach (array_slice($this->nodeMissingIndex, 0, 5) as $nodeUuid => $children) {
                $missingPaths[] = $nodeUuid;
            }
            echo "There are still missing " . count($this->nodeMissingIndex) . " parent nodes\n";
            foreach ($missingPaths as $missingPath) {
                echo "- ${missingPath}\n";
            }
            if ($this->promptYesOrNo("Do you wish to reassign them to start node? [yes/no] ") === 'yes') {
                $startNodeUuid = $this->startNode->remoteID();
                $startNodeId = $this->startNode->attribute('node_id');
                foreach ($this->nodeMissingIndex as $nodeUuid => $children) {
                    $this->nodeIndex[$startNodeUuid]['children'] = array_merge($this->nodeIndex[$startNodeUuid]['children'], $children);
                    foreach ($children as $childNodeUuid) {
                        $this->nodeIndex[$childNodeUuid]['parent_uuid'] = $startNodeUuid;
                    }
                    unset($this->nodeMissingIndex[$nodeUuid]);
                    if ($this->verbose) {
                        echo "Reassigned parent node $nodeUuid to $startNodeUuid (" . $startNodeId . ")\n";
                    }
                }
            } else {
                throw new ImportDenied("Parent nodes missing, aborting import");
            }
        }

        if ($this->verbose) {
            echo "Verifying content\n";
        }
        // Go over all nodes and objects and verify references, relations and other data
        foreach ($this->nodeStartIndex as $nodeUuidList) {
            foreach ($nodeUuidList as $nodeUuid) {
                $nodeData = $this->nodeIndex[$nodeUuid];
                $this->verifyNodeContent($nodeData);
            }
        }
    }

    public function sync()
    {
        if ($this->verbose) {
            echo "Creating node structure\n";
        }
        // Go over all starting nodes and starting building the tree structure
        // for any new nodes/objects which are found, attributes and ownership is handled later
        foreach ($this->nodeStartIndex as $nodeUuidList) {
            foreach ($nodeUuidList as $nodeUuid) {
                $nodeData = $this->nodeIndex[$nodeUuid];
                $this->createNodeStructure($nodeData);
            }
        }

        if ($this->verbose) {
            echo "Updating content\n";
        }
        // Now go over all objects (via nodes) and fill in information on object and attributes.
        // TODO: Instead of iterating over the node structure again for updating nodes/objects
        // the createNodeStructure should add all objects that are created/found to a new
        // queue, then iterate over that and simply update the object with locations
        // Avoids the need to check against duplicate updates
        foreach ($this->nodeStartIndex as $nodeUuidList) {
            foreach ($nodeUuidList as $nodeUuid) {
                $nodeData = $this->nodeIndex[$nodeUuid];
                $this->updateNodeContent($nodeData);
            }
        }
    }

    public function finalize()
    {
        $this->verify();
        $this->sync();
    }

    /**
     * Verify the nodes and its object.
     */
    public function verifyNodeContent($nodeData)
    {
        if (isset($this->objectIndex[$nodeData['object_uuid']])) {
            $objectData = $this->objectIndex[$nodeData['object_uuid']];
            $objectName = Arr::get($objectData, 'main_name', "<no-name>");
            $objectUuid = $objectData['uuid'];
        } else {
            $object = eZContentObject::fetchByRemoteID($nodeData['object_uuid']);
            if ($object) {
                $objectName = $object->attribute('name');
            } else {
                if ($nodeData['uuid'] === $this->rootNode->remoteID() || Arr::get($nodeData, 'node_id') === 1) {
                    $objectName = '<root>';
                } else {
                    $objectName = "<no-name>";
                }
            }
            $objectUuid = null;
        }
        if ($nodeData['status'] === 'new' || $nodeData['status'] === 'created') {
            if ($this->verbose) {
                echo "Verifying node ${nodeData['uuid']} (name=${objectName})\n";
            }
            $objectData = $this->objectIndex[$nodeData['object_uuid']];
            $this->verifyObjectContent($objectData);
        } else if ($nodeData['status'] === 'present') {
            if ($this->verbose) {
                echo "Node ${nodeData['uuid']} (name=${objectName}) already exists\n";
            }
            if (isset($this->objectIndex[$nodeData['object_uuid']])) {
                $objectData = $this->objectIndex[$nodeData['object_uuid']];
                $this->verifyObjectContent($objectData);
            }
        } else if ($nodeData['status'] === 'reference') {
            if ($this->verbose) {
                echo "Node ${nodeData['uuid']} (name=${objectName}) is a reference\n";
            }
        } else {
            throw new ImportDenied("Reached node with unknown status='" . $nodeData['status'] . "'");
        }

        // Create child nodes
        foreach ($nodeData['children'] as $childUuid) {
            $childData = $this->nodeIndex[$childUuid];
            $this->verifyNodeContent($childData);
        }
    }

    /**
     * Verifies the object information.
     */
    public function verifyObjectContent($objectData)
    {
        $objectUuid = $objectData['uuid'];
        if ($objectData['status'] === 'reference') {
            return;
        }
        if ($this->verbose) {
            echo "Verifying object uuid=${objectUuid}\n";
        }
        $translations = $objectData['translations'];
        $languages = array_keys($translations);
        if (!$languages) {
            throw new ImportDenied("No translations found on object with uuid=" . $objectUuid);
        }
        $allLanguages = $languages;
        $mainLanguage = array_shift($languages);
        if (isset($objectData['translations'][$mainLanguage]['name'])) {
            $objectName = $objectData['translations'][$mainLanguage]['name'];
        } else {
            $objectName = '<unknown-name>';
        }
        $ownerUuid = null;
        // Add owner if it exists in database
        if (isset($objectData['owner']['uuid']) && $objectData['owner']['uuid']) {
            $removeOwner = false;
            $owner = $objectData['owner'];
            $ownerUuid = $owner['uuid'];
            foreach ($this->transformObjectOwner as $ownerTransformer) {
                $newOwner = $ownerTransformer->transformOwner($owner);
                if ($newOwner) {
                    $owner = $newOwner;
                    $objectData['owner'] = $newOwner;
                    $ownerUuid = $owner['uuid'];
                }
            }
            // Check remapping of ownership again, in case it was changed after imported
            if (isset($this->mapObject[$ownerUuid])) {
                if (isset($this->mapObject[$ownerUuid]['removed'])) {
                    $this->objectIndex[$objectUuid]['owner'] = null;
                    $removeOwner = true;
                } else {
                    $objectData['owner'] = $this->objectIndex[$objectUuid]['owner'] = array(
                        'uuid' => $this->mapObject[$ownerUuid]['uuid'],
                        'id' => Arr::get($this->mapObject[$ownerUuid], 'id'),
                        'name' => Arr::get($this->mapObject[$ownerUuid], 'name'),
                    );
                    $ownerUuuid = $this->mapObject[$ownerUuid]['uuid'];
                }
            }
            if (!$removeOwner) {
                $ownerName = Arr::get($objectData['owner'], 'name', '<unknown-owner>');
                $owner = null;
                $foundOwner = false;
                if (isset($this->objectIndex[$ownerUuid])) {
                    // Owner will be imported
                    $foundOwner = true;
                } else {
                    $owner = eZContentObject::fetchByRemoteID($ownerUuid, false);
                    $foundOwner = (bool)$owner;
                }
                if (!$foundOwner && $this->interactive) {
                    echo "Object UUID ${objectUuid} (name=${objectName}) has owner UUID ${ownerUuid} (name=${ownerName}), but owner object was not found\n";
                    if ($this->promptYesOrNo("Do you wish to reset ownership for this owner? [yes/no] ") === 'yes') {
                        $removeOwner = true;
                    } else {
                        throw new ImportDenied("Object UUID ${objectUuid} has owner UUID ${ownerUuid} (name=${ownerName}), but owner object was not found");
                    }
                } else if (!$foundOwner) {
                    $removeOwner = true;
                }
            } 
            if ($removeOwner) {
                $this->objectIndex[$objectUuid]['owner'] = null;
                // Mark the object uuid as removed, any future entries with the same owner
                // will automatically remove it
                $this->mapObject[$ownerUuid] = array(
                    'removed' => true,
                );
            }
        }

        // Verify relations
        if (isset($objectData['relations'])) {
            foreach ($objectData['relations'] as $idx => $relation) {
                $removeRelation = false;
                $relationUuid = $relation['uuid'];
                if (isset($this->mapObject[$relationUuid])) {
                    if (isset($this->mapObject[$relationUuid]['removed'])) {
                        $removeRelation = true;
                    } else if (isset($this->mapObject[$relationUuid])) {
                        $relationUuuid = $this->mapObject[$relationUuid]['uuid'];
                    }
                }
                if (!$removeRelation) {
                    $relationName = Arr::get($relation, 'name', '<unknown-relation>');
                    $relation = null;
                    $foundRelation = false;
                    if (isset($this->objectIndex[$relationUuid])) {
                        // Relation will be imported
                        $foundRelation = true;
                    } else {
                        $relationObject = eZContentObject::fetchByRemoteID($relationUuid, false);
                        $foundRelation = (bool)$relationObject;
                    }
                    if (!$foundRelation && $this->interactive) {
                        echo "Object UUID ${objectUuid} (name=${objectName}) has relation UUID ${relationUuid} (name=${relationName}), but relation object was not found\n";
                        if ($this->promptYesOrNo("Do you wish to remove this relation? [yes/no] ") === 'yes') {
                            $removeRelation = true;
                        } else {
                            throw new ImportDenied("Object UUID ${objectUuid} has relation UUID ${relationUuid} (name=${relationName}), but relation object was not found");
                        }
                    } else if (!$foundRelation) {
                        $removeRelation = true;
                    }
                }
                if ($removeRelation) {
                    unset($this->objectIndex[$objectUuid]['relations'][$idx]);
                    // Mark the object uuid as removed, any future entries with the same relation
                    // will automatically remove it
                    $this->mapObject[$relationUuid] = array(
                        'removed' => true,
                    );
                }
            }
        }

        // Verify all attributes
        $classData = $this->classIndex[$objectData['class_identifier']];
        $isModified = false;
        if (isset($objectData['attributes']) && $objectData['attributes']) {
            foreach ($objectData['attributes'] as $identifier => $attributeData) {
                $dataType = $classData['attributes'][$identifier]['type'];
                // Verify attribute, and if it returns a new value used that for attribute data
                $attributeData = $this->verifyAttributeData($identifier, $dataType, $attributeData);
                if (is_array($attributeData) && array_key_exists('value', $attributeData)) {
                    $objectData['attributes'][$identifier] = $attributeData['value'];
                    $isModified = true;
                }
            }
        }
        if ($isModified) {
            $this->objectIndex[$objectUuid]['attributes'] = $objectData['attributes'];
        }

        // Verify all translated attributes
        $isModified = false;
        foreach ($allLanguages as $language) {
            if (isset($translations[$language]['attributes']) &&
                $translations[$language]['attributes']) {
                foreach ($translations[$language]['attributes'] as $identifier => $attributeData) {
                    $dataType = $classData['attributes'][$identifier]['type'];
                    // Verify attribute, and if it returns a new value used that for attribute data
                    $attributeData = $this->verifyAttributeData($identifier, $dataType, $attributeData);
                    if (is_array($attributeData) && array_key_exists('value', $attributeData)) {
                        $translations[$language]['attributes'][$identifier] = $attributeData['value'];
                        $isModified = true;
                    }
                }
            }
        }
        if ($isModified) {
            $this->objectIndex[$objectUuid]['translations'] = $translations;
        }
    }

    /**
     * Check data structure for attribute content and verify that it is valid.
     * For instance by checking that referenced objects exists.
     */
    public function verifyAttributeData($identifier, $dataType, $attributeData)
    {
        if ($dataType === 'ezimage') {
            if (isset($attributeData['found']) && !$attributeData['found']) {
                // Exporter did not find the image, so there is nothing to import
            } else if (isset($attributeData['uuid'])) {
                $uuid = $attributeData['uuid'];
                if (!isset($this->fileIndex[$uuid])) {
                    throw new ImportDenied("ezbinaryfile attribute '${identifier}' references file with UUID $uuid but it does not exist");
                }
                $mimeType = $this->fileIndex[$uuid]['mime_type'];
                $baseName = $this->fileIndex[$uuid]['original_path'];
                if ($baseName !== null) {
                    $baseName = basename($baseName);
                } else {
                    $baseName = basename($this->fileIndex[$uuid]['path']);
                }
                if ($mimeType) {
                    list($group, $type) = explode("/", $mimeType, 2);
                    if ($group !== 'image') {
                        // This is not part of the image group, most likely not an image
                        if ($this->verbose) {
                            echo "Warning: Tried to insert non-image file '${baseName}' to ezimage attribute '${identifier}', ignoring file\n";
                            echo "Mime-Type was '${mimeType}'\n";
                        }
                        return array(
                            'value' => null,
                        );
                    }
                } else {
                    // Unknown file, most likely not an image, remove it
                    if ($this->verbose) {
                        echo "Warning: Tried to insert unknown file '${baseName}' to ezimage attribute '${identifier}', ignoring file\n";
                        echo "Mime-Type was '${mimeType}'\n";
                    }
                    return array(
                        'value' => null,
                    );
                }
            } else {
                // No uuid so nothing to import
            }
            return;
        } else if ($dataType === 'ezbinaryfile') {
            if (isset($attributeData['found']) && !$attributeData['found']) {
                // Exporter did not find the file, so there is nothing to import
            } else if (isset($attributeData['uuid'])) {
                $uuid = $attributeData['uuid'];
                if (!isset($this->fileIndex[$uuid])) {
                    throw new ImportDenied("ezbinaryfile attribute $identifier references file with UUID $uuid but it does not exist");
                }
            } else {
                // No uuid so nothing to import
            }
            return;
        } else if ($dataType === 'ezobjectrelation') {
            if (isset($attributeData['object_uuid'])) {
                $objectUuid = $attributeData['object_uuid'];
                $objectId = Arr::get($attributeData, 'object_id');
                $objectName = Arr::get($attributeData, 'name', '<unknown-name>');
                $objectStatus = Arr::get($attributeData, 'status');
                $hasRelation = false;
                if (isset($this->objectIndex[$objectUuid])) {
                    $hasRelation = true;
                } else {
                    $object = eZContentObject::fetchByRemoteID($objectUuid);
                    $hasRelation = (bool)$object;
                }
                if (!$hasRelation) {
                    $failed = false;
                    if ($objectStatus !== null && $objectStatus !== 'published') {
                        // If the object is a draft or is archived then there is no point in
                        // storing a reference to the object, instead empty the relation
                        return array(
                            'value' => null,
                        );
                    } else if ($this->interactive) {
                        echo "Object attribute $identifier with type $dataType has a relation to object with UUID $objectUuid (ID=$objectId, name=$objectName), but the object does not exist in import nor in DB\n";
                        if ($this->promptYesOrNo("Do you wish to remove the relation? [yes/no] ") !== 'yes') {
                            $failed = true;
                        } else {
                            return array(
                                'value' => null,
                            );
                        }
                    } else {
                        $failed = true;
                    }
                    if ($failed) {
                        throw new ImportDenied("Failed to find object relation for attribute $identifier, the object with UUID $objectUuid (ID=$objectId, name=$objectName) does not exist");
                    }
                }
            }
            return;
        } else if ($dataType === 'ezobjectrelationlist') {
            if (isset($attributeData['relations'])) {
                $relations = $attributeData['relations'];
            } else {
                $relations = $attributeData;
            }
            $newRelations = array();
            $isChanged = false;
            foreach ($relations as $relationData) {
                if (!isset($relationData['object_uuid'])) {
                    $isChanged = true;
                    continue;
                }
                $objectUuid = $relationData['object_uuid'];
                $objectId = Arr::get($relationData, 'object_id');
                $objectName = Arr::get($relationData, 'name', '<unknown-name>');
                $objectStatus = Arr::get($relationData, 'status');
                $hasRelation = false;
                if (isset($this->objectIndex[$objectUuid])) {
                    $hasRelation = true;
                } else {
                    $object = eZContentObject::fetchByRemoteID($objectUuid);
                    $hasRelation = (bool)$object;
                }
                if (!$hasRelation) {
                    $failed = false;
                    if ($objectStatus !== null && $objectStatus !== 'published') {
                        // If the object is a draft or is archived then there is no point in
                        // storing a reference to the object, instead empty the relation
                        $isChanged = true;
                        continue;
                    } else if ($this->interactive) {
                        echo "Object attribute $identifier with type $dataType has a relation to object with UUID $objectUuid (ID=$objectId, name=$objectName), but the object does not exist\n";
                        if ($this->promptYesOrNo("Do you wish to remove the relation? [yes/no] ") !== 'yes') {
                            $failed = true;
                        } else {
                            $isChanged = true;
                            continue;
                        }
                    } else {
                        $failed = true;
                    }
                    if ($failed) {
                        throw new ImportDenied("Failed to find object relation for attribute $identifier, the object with UUID $objectUuid (ID=$objectId, name=$objectName) does not exist");
                    }
                }
                $newRelations[] = $relationData;
            }
            if ($isChanged) {
                return array(
                    'value' => $newRelations,
                );
            }
            return;
        } else if ($dataType === 'ezxmltext') {
            if (isset($attributeData['xml'])) {
                $xml = $attributeData['xml'];
                $dom = new \DOMDocument('1.0', 'utf-8');
                if (!@$dom->loadXML($xml)) {
                    throw new ImportDenied("Attribute ${identifier} has invalid XML data: " . var_export(substr(str_replace("\n", " ", $xml), 0, 80), true));
                }
                $xpath = new \DOMXPath($dom);

                // Embedded objects must include references to uuid and optionally added to exported items
                $embedObjects = array();
                $embeds = $xpath->query('//embed');
                foreach ($embeds as $embed) {
                    $embedUuid = $embed->getAttribute('uuid');
                    $embedName = $embed->getAttribute('name');
                    if (!$embedName) {
                        $embedName = '<no-name>';
                    }
                    $embedObjectStatus = $embed->getAttribute('status');
                    if ($embedUuid && isset($this->mapObject[$embedUuid])) {
                        $newEmbed = $this->mapObject[$embedUuid];
                        $newEmbedUuid = $newEmbed['uuid'];
                        $newEmbedName = Arr::get($newEmbed, 'name', '<no-name>');
                        if ($this->verbose) {
                            echo "Object attribute $identifier, embedded object UUID (name=${embedName}) remapped from $embedUuid to $newEmbedUuid (name=${newEmbedName})\n";
                        }
                        $embedUuid = $newEmbedUuid;
                        $embedName = $newEmbedName;
                        $embedObjectStatus = Arr::get($newEmbed, 'object_status');
                    }
                    $hasEmbedObject = false;
                    if (isset($this->objectIndex[$embedUuid])) {
                        $hasEmbedObject = true;
                    } else {
                        $embedObject = \eZContentObject::fetchByRemoteID($embedUuid);
                        $hasEmbedObject = (bool)$embedObject;
                    }
                    if (!$hasEmbedObject) {
                        if ($embedObjectStatus !== null && $embedObjectStatus !== 'published') {
                            // If the object is a draft or is archived then there is no point in
                            // storing a reference to the object, instead empty the embed
                            $isChanged = true;
                            continue;
                        } else if ($this->interactive) {
                            echo "XML: Embedded object with UUID ${embedUuid} was not found\n";
                            if ($this->promptYesOrNo("Do you wish to remove embed entry? [yes/no] ") !== 'no') {
                                throw new ImportDenied("XML content for attribute ${identifier} has embedded object with UUID ${embedUuid} which does not exist");
                            }
                        }
                        $parentNode = $embed->parentNode;
                        $parentNode->removeChild($embed);
                        continue;
                        // $embed->removeAttribute("object_id");
                        // $embed->removeAttribute("uuid");
                    }
                }

                $xml = $dom->saveXML();
                $attributeData['xml'] = $xml;
                return array(
                    'value' => $attributeData,
                );
            }
            return;
        }
        return;
    }

    /**
     * Create objects with empty data and their locations, this is just to
     * get an object id and node id.
     */
    public function createNodeStructure($nodeData)
    {
        if (isset($this->objectIndex[$nodeData['object_uuid']])) {
            $objectData = $this->objectIndex[$nodeData['object_uuid']];
            $objectName = Arr::get($objectData, 'main_name', "<no-name>");
            $objectUuid = $objectData['uuid'];
        } else {
            $object = eZContentObject::fetchByRemoteID($nodeData['object_uuid']);
            if ($object) {
                $objectName = $object->attribute('name');
            } else {
                if ($nodeData['uuid'] === $this->rootNode->remoteID() || Arr::get($nodeData, 'node_id') === 1) {
                    $objectName = '<root>';
                } else {
                    $objectName = "<no-name>";
                }
            }
            $objectUuid = null;
        }
        if ($nodeData['status'] === 'new') {
            $nodeUuid = $nodeData['uuid'];
            $parentUuid = $nodeData['parent_uuid'];
            if ($this->verbose) {
                echo "Creating node skeleton ", $nodeData['uuid'], " (name='", $objectName, "', parent=$parentUuid)\n";
            }
            $translations = $objectData['translations'];
            $languages = array_keys($translations);
            if (!$languages) {
                throw new ImportDenied("No translations found on object with uuid=" . $objectUuid);
            }
            $mainLanguage = array_shift($languages);
            // See if content states are to be set
            $states = null;
            if (isset($objectData['states'])) {
                $states = array();
                foreach ($objectData['states'] as $groupIdentifier => $stateIdentifier) {
                    if (!is_string($groupIdentifier)) {
                        throw new ImportDenied("Content state group of object $objectUuid must be a string, got: " . var_export($groupIdentifier, true));
                    }
                    if (!is_string($stateIdentifier)) {
                        throw new ImportDenied("Content state of object $objectUuid must be a string, got: " . var_export($stateIdentifier, true));
                    }
                    $states[$groupIdentifier] = $stateIdentifier;
                }
            }
            // Use published date if set, parsed from ISO format
            $publishedDate = null;
            if (isset($objectData['published_date']) && $objectData['published_date']) {
                $publishedDate = (new DateTime($objectData['published_date']))->getTimestamp();
            }

            $sectionIdentifier = null;
            if (isset($objectData['section_identifier'])) {
                $sectionIdentifier = $objectData['section_identifier'];
            }
            $objectFields = array(
                'uuid' => $objectUuid,
                'identifier' => $objectData['class_identifier'],
                'language' => $mainLanguage,
                // Do not create url alias yet
                'updateNodePath' => false,
            );
            $updateTypes = isset($objectData['action_update_object']) ? $objectData['action_update_object'] : null;
            if (!$updateTypes) {
                $updateTypes = array('object', 'attribute', 'relation', 'location');
            }
            // Check if object data should be updated or not, a transformation may have stopped the update
            // Note: Even if update is off, the call to update() below needs to happen to sync the node
            if (in_array('object', $updateTypes)) {
                $objectFields = array_merge($objectFields, array(
                    'alwaysAvailable' => isset($objectData['is_always_available']) ? $objectData['is_always_available'] : null,
                    'sectionIdentifier' => $sectionIdentifier,
                    'states' => $states,
                    'publishedDate' => $publishedDate,
                ));
            }
            $objectManager = new ContentObject($objectFields);
            $hasObject = false;
            if ($objectManager->exists()) {
                $hasObject = true;
            }
            if ($objectData['status'] === 'new') {
                if ($hasObject) {
                    throw new ImportDenied("Tried to create object uuid=" . $objectUuid . " but it already exists");
                }
            } else if ($objectData['status'] === 'created' || $objectData['status'] === 'present') {
                $hasObject = true;
            } else if ($objectData['status'] === 'removed') {
                // Object was marked as removed, do not create it.
                if ($this->verbose) {
                    echo "Skipping node ", $nodeData['uuid'], " ", $nodeData['status'], " ", Arr::get($nodeData, 'name', ''), ", the object is marked as removed\n";
                }
                return;
            } else {
                $hasObject = false;
            }
            $location = array(
                'parent_uuid' => $nodeData['parent_uuid'],
                'uuid' => $nodeUuid,
            );
            if (isset($nodeData['is_main'])) {
                $location['is_main'] = $nodeData['is_main'];
            }
            if ($nodeData['sort_by']) {
                $location['sort_by'] = $nodeData['sort_by'];
            }
            if ($nodeData['priority']) {
                $location['priority'] = $nodeData['priority'];
            }
            if ($nodeData['visibility']) {
                $location['visibility'] = $nodeData['visibility'];
            }
            $objectManager->syncLocation($location);
            if ($hasObject) {
                $objectManager->update();
                $contentObject = $objectManager->contentObject;
                if ($this->verbose) {
                    echo "Updated object id=", $contentObject->attribute('id'), ", uuid=", $contentObject->attribute('remote_id'), "\n";
                }
                $this->importCounts['contentobject_update'] = $this->importCounts['contentobject_update'] + 1;
            } else {
                $objectManager->create();
                $contentObject = $objectManager->contentObject;
                $this->objectIndex[$objectUuid]['id'] = $contentObject->attribute('id');
                if ($this->verbose) {
                    echo "Created object skeleton: id=", $contentObject->attribute('id'), ", uuid=", $contentObject->attribute('remote_id'), ", name='", $objectName, "'\n";
                }
                $this->objectIndex[$nodeData['object_uuid']]['status'] = 'created';
                $this->importCounts['contentobject_create'] = $this->importCounts['contentobject_create'] + 1;
            }
            $nodes = $objectManager->nodes;
            if (!isset($nodes[$nodeUuid])) {
                $db = \eZDB::instance();
                // var_dump(array(
                //     'uuid' => $nodeUuid,
                // ));
                // var_dump($db->arrayQuery("SELECT node_id, remote_id FROM ezcontentobject_tree WHERE contentobject_id=" . $contentObject->attribute('id')));
                throw new ImportDenied("Failed to create node with uuid=" . $nodeUuid);
            }
            $this->nodeIndex[$nodeUuid]['node_id'] = $nodes[$nodeUuid]['id'];
            $this->nodeIndex[$nodeUuid]['status'] = 'created';
            unset($this->newObjectQueue[$objectUuid]);
        } else if ($nodeData['status'] === 'created') {
            if ($this->verbose) {
                echo "Node ${nodeData['node_id']} (name=${objectName}) has already been created\n";
            }
        } else if ($nodeData['status'] === 'present') {
            if ($this->verbose) {
                echo "Node ${nodeData['uuid']} (name=${objectName}) already exists\n";
            }
        } else if ($nodeData['status'] === 'reference') {
            if ($this->verbose) {
                echo "Node ${nodeData['uuid']} (name=${objectName}) is a reference\n";
            }
        } else {
            throw new ImportDenied("Reached node with unknown status='" . $nodeData['status'] . "'");
        }

        // Create child nodes
        foreach ($nodeData['children'] as $childUuid) {
            $childData = $this->nodeIndex[$childUuid];
            $this->createNodeStructure($childData);
        }
    }

    /**
     * Updates the nodes and objects with information such as attributes and names.
     * The updating is recursively trough all nodes and their children.
     */
    public function updateNodeContent($nodeData)
    {
        if ($nodeData['status'] === 'new') {
            throw ImportDenied("Reached a node with status 'new' while updating node content");
        }
        $objectData = null;
        if (isset($this->objectIndex[$nodeData['object_uuid']])) {
            $objectData = $this->objectIndex[$nodeData['object_uuid']];
            $objectName = Arr::get($objectData, 'main_name', "<no-name>");
            $objectUuid = $objectData['uuid'];
        } else {
            $object = eZContentObject::fetchByRemoteID($nodeData['object_uuid']);
            if ($object) {
                $objectName = $object->attribute('name');
            } else {
                if ($nodeData['uuid'] === $this->rootNode->remoteID() || Arr::get($nodeData, 'node_id') === 1) {
                    $objectName = '<root>';
                } else {
                    $objectName = "<no-name>";
                }
            }
            $objectUuid = null;
        }
        $updateObject = false;
        if ($nodeData['status'] === 'created') {
            if ($this->verbose) {
                echo "Updating node ${nodeData['uuid']}, name=${objectName}\n";
            }
            if (!$objectData) {
                throw new ImportDenied("Reached a new node (UUID=${$nodeData['uuid']}) without any data on the object (UUID=${objectUuid})");
            }
            $this->updateObjectContent($objectData);
            $this->nodeIndex[$nodeData['uuid']]['status'] = 'present';
            $updateObject = true;
        } else if ($nodeData['status'] === 'present') {
            if ($objectData) {
                $objectUpdateTypes = isset($objectData['action_update_object']) ? $objectData['action_update_object'] : null;
                if (!is_array($objectUpdateTypes)) {
                    $objectUpdateTypes = array('object', 'attribute', 'location');
                }
                if ($objectData['status'] === 'new' || $objectData['status'] === 'created') {
                    $updateObject = true;
                }
                else if ($objectData['status'] === 'present' && $objectUpdateTypes) {
                    $updateObject = true;
                }
            }
            if ($updateObject) {
                if ($this->verbose) {
                    echo "Node ${nodeData['uuid']} (name=${objectName}) already exists, but content needs update\n";
                }
            } else {
                if ($this->verbose) {
                    echo "Node ${nodeData['uuid']} (name=${objectName}) already exists, no object change\n";
                }
            }
        } else if ($nodeData['status'] === 'reference') {
            if ($this->verbose) {
                echo "Node ${nodeData['uuid']} (name=${objectName}) is a reference\n";
            }
        } else {
            throw new ImportDenied("Reached node with unknown status='" . $nodeData['status'] . "'");
        }

        if ($updateObject) {
            $this->updateObjectContent($objectData);
        }

        // Create child nodes
        foreach ($nodeData['children'] as $childUuid) {
            $childData = $this->nodeIndex[$childUuid];
            $this->updateNodeContent($childData);
        }
    }

    /**
     * Updates the objects with information such as attributes and names.
     */
    public function updateObjectContent($objectData)
    {
        $objectUuid = $objectData['uuid'];
        $updateTypes = isset($objectData['action_update_object']) ? $objectData['action_update_object'] : null;
        if ($objectData['status'] === 'present') {
            if (!$updateTypes) {
                // Object has already been updated from via another location
                if ($this->verbose) {
                    echo "Object with UUID $objectUuid has already been updated, skipping\n";
                }
                return;
            } else {
                if ($this->verbose) {
                    echo "Object with UUID $objectUuid exists but needs an update for " . implode(", ", $updateTypes) . "\n";
                }
            }
        } else if ($objectData['status'] !== 'created' && $objectData['status'] !== 'new') {
            throw new ImportDenied("Tried to update object content on an object which has not been created/needs-update, status=${objectData['status']}, UUID=${objectUuid}");
        }
        if (!is_array($updateTypes)) {
            $updateTypes = array('object', 'attribute', 'relation', 'location');
        }
        $translations = $objectData['translations'];
        $languages = array_keys($translations);
        if (!$languages) {
            throw new ImportDenied("No translations found on object with uuid=" . $objectUuid);
        }
        $mainLanguage = array_shift($languages);
        $ownerUuid = null;
        // Add owner if it exists in database
        if (isset($objectData['owner']['uuid']) && $objectData['owner']['uuid']) {
            $owner = eZContentObject::fetchByRemoteID($objectData['owner']['uuid'], false);
            if ($owner) {
                $ownerUuid = $objectData['owner']['uuid'];
            }
        }
        $objectManager = new ContentObject(array(
            'uuid' => $objectUuid,
            'language' => $mainLanguage,
            'ownerUuid' => $ownerUuid,
        ));
        if ($objectData['status'] === 'new') {
            if ($objectManager->exists()) {
                throw new ImportDenied("Tried to update object content on an object which has not been created/needs-update, status=${objectData['status']}, UUID=${objectUuid}");
            }
        }
        $allowedAttributes = isset($objectData['update_attributes']) ? $objectData['update_attributes'] : null;
        if ($allowedAttributes !== null && $this->verbose) {
            if ($this->verbose) {
                echo "Limiting update of object ${objectUuid} to attributes: " . implode(", ", $allowedAttributes) . "\n";
            }
        }
        // Update all attributes
        if (in_array('attribute', $updateTypes)) {
            $classData = $this->classIndex[$objectData['class_identifier']];
            if (isset($objectData['attributes']) && $objectData['attributes']) {
                foreach ($objectData['attributes'] as $identifier => $attributeData) {
                    if ($allowedAttributes !== null && !in_array($identifier, $allowedAttributes)) {
                        continue;
                    }
                    $dataType = $classData['attributes'][$identifier]['type'];
                    $attributeData = $this->processAttributeData($identifier, $dataType, $attributeData);
                    $objectManager->setAttribute($identifier, $attributeData);
                }
            }
            if (isset($translations[$mainLanguage]['attributes']) &&
                $translations[$mainLanguage]['attributes']) {
                foreach ($translations[$mainLanguage]['attributes'] as $identifier => $attributeData) {
                    if ($allowedAttributes !== null && !in_array($identifier, $allowedAttributes)) {
                        continue;
                    }
                    $dataType = $classData['attributes'][$identifier]['type'];
                    $attributeData = $this->processAttributeData($identifier, $dataType, $attributeData);
                    $objectManager->setAttribute($identifier, $attributeData);
                }
            }
        }
        if (in_array('location', $updateTypes)) {
            $mainUuid = null;
            if (isset($objectData['main_node']['uuid'])) {
                $mainUuid = $objectData['main_node']['uuid'];
            }
            // Now that all nodes are present the main node and visibility can be set
            foreach ($objectData['locations'] as $locationData) {
                $location = array(
                    'parent_uuid' => $locationData['parent_node_uuid'],
                    'uuid' => $locationData['uuid'],
                );
                if (isset($locationData['visibility'])) {
                    $location['visibility'] = $locationData['visibility'];
                }
                if ($mainUuid !== null) {
                    $location['is_main'] = $locationData['uuid'] == $mainUuid;
                }
                if ($locationData['priority']) {
                    $location['priority'] = $locationData['priority'];
                }
                $objectManager->updateLocation($location);
            }
        }
        $objectManager->update();
        $contentObject = $objectManager->contentObject;

        // Manage relations
        if (in_array('relation', $updateTypes) && isset($objectData['relations'])) {
            foreach ($objectData['relations'] as $idx => $relation) {
                $relatedObject = eZContentObject::fetchByRemoteID($relation['uuid'], false);
                if (!$relatedObject) {
                    throw new ImportDenied("Object with UUID ${objectUuid} has a relation to object with UUID ${relation['uuid']} but the object does not exist");
                }
                $contentObject->addContentObjectRelation($relatedObject['id']);
                $objectData['relations'][$idx]['status'] = 'created';
            }
        }

        // Now update the other languages
        foreach ($languages as $language) {
            if (!isset($translations[$language]['attributes']) ||
                !$translations[$language]['attributes']) {
                continue;
            }
            $objectLanguageManager = new ContentObject(array(
                'uuid' => $objectUuid,
                'language' => $language,
            ));
            foreach ($translations[$language]['attributes'] as $identifier => $attributeData) {
                if ($allowedAttributes !== null && !in_array($identifier, $allowedAttributes)) {
                    continue;
                }
                $objectLanguageManager->setAttribute($identifier, $attributeData);
            }
            // Include locations to get all url aliases updated
            foreach ($objectData['locations'] as $locationData) {
                $location = array(
                    'parent_uuid' => $locationData['parent_node_uuid'],
                    'uuid' => $locationData['uuid'],
                );
                $objectLanguageManager->updateLocation($location);
            }
            $objectLanguageManager->update();
        }

        if ($this->verbose) {
            echo "Updated object id=", $contentObject->attribute('id'), ", uuid=", $contentObject->attribute('remote_id'), "\n";
        }

        // Mark object as present, and stop any updates from other nodes
        $this->objectIndex[$objectUuid]['status'] = 'present';
        $this->objectIndex[$objectUuid]['action_update_object'] = array();
    }

    /**
     * Process data structure for attribute content before it is passed to
     * ContentObjectAttribute/setAttribute. This allows for looking up identifiers
     * and transforming into values that the data-types expect.
     */
    public function processAttributeData($identifier, $dataType, $attributeData)
    {
        if ($dataType === 'ezimage') {
            if (isset($attributeData['found']) && !$attributeData['found']) {
                // Exporter did not find the image, so there is nothing to import
                return null;
            } else if (isset($attributeData['uuid'])) {
                $uuid = $attributeData['uuid'];
                if (!isset($this->fileIndex[$uuid])) {
                    throw new ImportDenied("ezbinaryfile attribute $identifier references file with UUID $uuid but it does not exist");
                }
                $file = $this->fileIndex[$uuid];
                $filePath = $file['path'];
                return new ImageFile(array(
                    'alternative_text' => Arr::get($attributeData, 'alternative_text'),
                    'original_filename' => Arr::get($attributeData, 'original_filename'),
                    'path' => $filePath,
                ));
            } else {
                // No uuid so nothing to import
                return null;
            }
        } else if ($dataType === 'ezbinaryfile') {
            if (isset($attributeData['found']) && !$attributeData['found']) {
                // Exporter did not find the file, so there is nothing to import
                return null;
            } else if (isset($attributeData['uuid'])) {
                $uuid = $attributeData['uuid'];
                if (!isset($this->fileIndex[$uuid])) {
                    throw new ImportDenied("ezbinaryfile attribute $identifier references file with UUID $uuid but it does not exist");
                }
                $file = $this->fileIndex[$uuid];
                $filePath = $file['path'];
                return new BinaryFile(array(
                    'original_filename' => Arr::get($attributeData, 'original_filename'),
                    'path' => $filePath,
                ));
            } else {
                // No uuid so nothing to import
                return null;
            }
        } else if ($dataType === 'ezobjectrelation') {
            if (isset($attributeData['object_uuid'])) {
                $objectUuid = $attributeData['object_uuid'];
                $objectId = Arr::get($attributeData, 'object_id');
                $objectName = Arr::get($attributeData, 'name', '<unknown-name>');
                $objectStatus = Arr::get($attributeData, 'status');
                $object = eZContentObject::fetchByRemoteID($objectUuid);
                if (!$object) {
                    $failed = false;
                    if ($this->interactive) {
                        echo "Object attribute $identifier with type $dataType has a relation to object with UUID $objectUuid (ID=$objectId, name=$objectName), but the object does not exist\n";
                        if ($this->promptYesOrNo("Do you wish to remove the relation? [yes/no] ") !== 'yes') {
                            $failed = true;
                        } else {
                            return null;
                        }
                    } else {
                        $failed = true;
                    }
                    if ($failed) {
                        throw new ImportDenied("Failed to find object relation for attribute $identifier, the object with UUID $objectUuid (ID=$objectId, name=$objectName) does not exist");
                    }
                }
                return $attributeData;
            }
        } else if ($dataType === 'ezobjectrelationlist') {
            if (isset($attributeData['relations'])) {
                $relations = $attributeData['relations'];
            } else {
                $relations = $attributeData;
            }
            $newRelations = array();
            foreach ($relations as $relationData) {
                if (!isset($relationData['object_uuid'])) {
                    continue;
                }
                $objectUuid = $relationData['object_uuid'];
                $objectId = Arr::get($relationData, 'object_id');
                $objectName = Arr::get($relationData, 'name', '<unknown-name>');
                $object = eZContentObject::fetchByRemoteID($objectUuid);
                if (!$object) {
                    $failed = false;
                    if ($this->interactive) {
                        echo "Object attribute $identifier with type $dataType has a relation to object with UUID $objectUuid (ID=$objectId, name=$objectName), but the object does not exist\n";
                        if ($this->promptYesOrNo("Do you wish to remove the relation? [yes/no] ") !== 'yes') {
                            $failed = true;
                        } else {
                            continue;
                        }
                    } else {
                        $failed = true;
                    }
                    if ($failed) {
                        throw new ImportDenied("Failed to find object relation for attribute $identifier, the object with UUID $objectUuid (ID=$objectId, name=$objectName) does not exist");
                    }
                }
                $newRelations[] = array(
                    'object_uuid' => $relationData['object_uuid'],
                    'object_id' => $object->attribute('id'),
                    'name' => $objectName,
                );
            }
            return $newRelations;
        }
        return $attributeData;
    }

    /**
     * Adds an existing node object to the internal indexes.
     * 
     * @param $node The eZContentObjectTreeNode object to add
     * @param $nodeUuid if null then uuid is fetched from $node
     * @param $children Optional array of children uuids to add to node
     */
    public function addExistingNode($node, $nodeUuid=null, array $children=null, $isReference=false)
    {
        if ($nodeUuid === null) {
            $nodeUuid = $node->remoteID();
        }
        $parentNode = $node->attribute('node_id') != 1 ? $node->fetchParent() : null;
        $parentUuid = $parentNode ? $parentNode->remoteID() : null;
        $contentObject = $node->object();
        $objectUuid = $contentObject->remoteID();
        $this->nodeIndex[$nodeUuid] = array(
            'uuid' => $nodeUuid,
            'status' => $isReference ? 'reference' : 'present',
            'parent_uuid' => $parentUuid,
            'object_uuid' => $contentObject ? $contentObject->attribute('remote_id') : null,
            'node_id' => $node->attribute('node_id'),
            'children' => $children ? $children : array(),
            'url_alias' => $node->urlAlias(),
        );
        if (!isset($this->objectIndex[$objectUuid])) {
            $this->objectIndex[$objectUuid] = array(
                'uuid' => $objectUuid,
                'main_name' => $node->attribute('node_id') == 1 ? '<root>' : $contentObject->attribute('name'),
                'status' => 'reference',
                'object_status' => ContentObject::statusToIdentifier($contentObject->attribute('status')),
                'class_identifier' => $contentObject->attribute('class_identifier'),
                'original_uuid' => $objectUuid,
                'original_id' => $contentObject->attribute('id'),
                'original_section_identifier' => $contentObject->sectionIdentifier(),
                'section_identifier' => $contentObject->sectionIdentifier(),
                'is_always_available' => $contentObject->isAlwaysAvailable(),
                'published_date' => null,
                'translations' => array(),
                'attributes' => array(),
                'main_node_uuid' => null,
                'relations' => array(),
                'locations' => array(),
            );
        }
        if ($this->objectIndex[$objectUuid]['status'] === 'reference') {
            $this->objectIndex[$objectUuid]['locations'][$nodeUuid] = array(
                'uuid' => $nodeUuid,
                'parent_node_uuid' => $parentUuid,
            );
        }
        // Connect to existing parents, or place in index for missing parents
        if ($parentUuid && isset($this->nodeIndex[$parentUuid])) {
            $this->nodeIndex[$parentUuid]['children'][] = $nodeUuid;
        } else {
            // If parent is not found it means this node is a starting point for tree traversal
            // Mark the node in the index
            if (!isset($this->nodeStartIndex[$parentUuid])) {
                $this->nodeStartIndex[$parentUuid] = array();
            }
            $this->nodeStartIndex[$parentUuid][] = $nodeUuid;
        }
        if (isset($this->nodeStartIndex[$nodeUuid])) {
            unset($this->nodeStartIndex[$nodeUuid]);
        }
        // Remove missing entry if it exists
        unset($this->nodeMissingIndex[$nodeUuid]);
    }

    public function printTree()
    {
        foreach ($this->nodeStartIndex as $nodeUuidList) {
            foreach ($nodeUuidList as $nodeUuid) {
                $nodeData = $this->nodeIndex[$nodeUuid];
                $this->printNode($nodeData);
            }
        }
    }

    public function printNode($nodeData, $level=0)
    {
        $prefix = str_repeat(" ", $level*2);
        $nodeUuid = $nodeData['uuid'];
        $objectUuid = $nodeData['object_uuid'];
        $symbol = "";
        if (Arr::get($nodeData, 'node_id') === 1) {
            $nodeName = '<root>';
        } else if (isset($this->objectIndex[$objectUuid])) {
            $objectData = $this->objectIndex[$objectUuid];
            if ($objectData['status'] === 'new') {
                $symbol = "+";
            } else if ($objectData['status'] === 'present') {
                $symbol = "*";
            } else if ($objectData['status'] === 'removed') {
                $symbol = "-";
            }
            $nodeName = Arr::get($objectData, 'main_name', '<no-name>');
        } else {
            $nodeName = Arr::get($nodeData, 'name', '<no-name>');
        }
        echo "${prefix}`- ${symbol}${nodeName} (${nodeUuid}, o=${objectUuid})\n";
        foreach ($nodeData['children'] as $childUuid) {
            $this->printNode($this->nodeIndex[$childUuid], $level + 1);
        }
    }

    public function warning($text)
    {
        if ($this->cli) {
            $this->cli->warning($text);
        } else {
            echo "${text}\n";
        }
    }

    /**
     * Figures out which types of objects have been created and returns
     * an associative array with type as key and count as value. Only
     * types which have created something is included.
     * 
     * If nothing is created it returns an empty array.
     * 
     * @return array
     */
    public function getCreatedCounts()
    {
        $counts = array();
        if ($this->importCounts['section_create']) {
            $counts['section'] = $this->importCounts['section_create'];
        }
        if ($this->importCounts['contentlanguage_create']) {
            $counts['contentlanguage'] = $this->importCounts['contentlanguage_create'];
        }
        if ($this->importCounts['contentstate_create']) {
            $counts['contentstate'] = $this->importCounts['contentstate_create'];
        }
        if ($this->importCounts['contentobject_create']) {
            $counts['contentobject'] = $this->importCounts['contentobject_create'];
        }
        return $counts;
    }

    /**
     * Figures out which types of objects have been created and returns
     * an associative array with type as key and count as value. Only
     * types which have created something is included.
     * 
     * If nothing is created it returns an empty array.
     * 
     * @return array
     */
    public function getUpdatedCounts()
    {
        $counts = array();
        if ($this->importCounts['contentobject_update']) {
            $counts['contentobject'] = $this->importCounts['contentobject_update'];
        }
        return $counts;
    }
}
