<?php
namespace Aplia\Content;
use DateTime;
use eZContentObjectTreeNode;
use eZContentObject;
use eZContentLanguage;
use eZSection;

class ContentExporter
{
    public $sparse = true;
    public $embedFileData = true;
    public $fileStorage = '.';
    public $startDepth = 1;
    public $includeOwners = false;
    public $includeEmbeds = false;
    public $includeRelations = false;
    // If true then all parents of all visited objects are included
    public $includeParents = false;
    public $excludedNodes = array();
    public $classMap = array();
    public $objectMap = array();
    public $languageMap = array();
    public $sectionMap = array();
    public $stateMap = array();
    public $fileMap = array();
    public $tagMap = array();

    protected $stateGroupIdMap = array();
    protected $stateIdMap = array();

    public function __construct(array $options = null) {
        if (isset($options['start_depth'])) {
            $this->startDepth = $options['start_depth'];
        }
        if (isset($options['file_storage'])) {
            $this->fileStorage = $options['file_storage'];
            $this->embedFileData = false;
        }
        if (isset($options['include_owners'])) {
            $this->includeOwners = $options['include_owners'];
        }
        if (isset($options['include_relations'])) {
            $this->includeRelations = $options['include_relations'];
        }
        if (isset($options['include_embeds'])) {
            $this->includeEmbeds = $options['include_embeds'];
        }
        if (isset($options['include_parents'])) {
            $this->includeParents = $options['include_parents'];
        }
        if (isset($options['excluded_nodes'])) {
            $this->excludedNodes = $options['excluded_nodes'];
        }
    }

    function exportNode(eZContentObjectTreeNode $node)
    {
        $parent = $node->fetchParent();
        $path = $node->pathArray();
        $visibility = 'visible';
        if ($node->attribute('is_hidden')) {
            // Explicitly hidden on node
            $visibility = 'hidden';
        } else if ($node->attribute('is_invisible')) {
            // Implicitly hidden by parent nodes
            $visibility = 'invisible';
        }
        $nodeId = (int)$node->attribute('node_id');
        $parentNodeId = (int)$node->attribute('parent_node_id');
        $data = array(
            '__type__' => 'ez_contentnode',
            'node_id' => $nodeId,
            'parent_node_id' => $parentNodeId,
            'uuid' => $node->remoteId(),
            'parent_node_uuid' => $parent->remoteId(),
            'original_depth' => (int)$node->attribute('depth'),
            'original_path' => $path,
            'url_alias' => $node->urlAlias(),
            'sort_by' => ContentType::encodeSortBy($node->attribute('sort_field'), $node->attribute('sort_order')),
            'priority' => (int)$node->attribute('priority'),
            'visibility' => $visibility,
        );
        // Mark top-level nodes as start of a tree-structure
        if ($parentNodeId == 1) {
            $data['node_type'] = 'tree-root';
        }
        $treeIdentifier = ContentObject::mapNodeToTreeIdentifier($nodeId);
        if ($treeIdentifier) {
            $data['tree_identifier'] = $treeIdentifier;
        }
        return $data;
    }

    function exportContentObject(eZContentObject $contentObject)
    {
        $contentClass = $contentObject->contentClass();
        $modifiedDate = new DateTime("@" . $contentObject->attribute('modified'));
        $publishedDate = new DateTime("@" . $contentObject->attribute('published'));
        $mainNode = $contentObject->mainNode();
        $data = array(
            '__type__' => 'ez_contentobject',
            'object_id' => (int)$contentObject->attribute('id'),
            'uuid' => $contentObject->remoteId(),
            'owner' => null,
            'section_identifier' => $contentObject->sectionIdentifier(),
            'class_identifier' => $contentClass->attribute('identifier'),
            'name' => $contentObject->attribute('name'),
            'modified_date' => $modifiedDate->format(DateTime::RFC3339),
            'published_date' => $publishedDate->format(DateTime::RFC3339),
            'is_always_available' => (bool)$contentObject->isAlwaysAvailable(),
            'related' => array(),
            'main_node' => null,
            'status' => ContentObject::statusToIdentifier($contentObject->attribute('status')),
            'states' => array(),
            'attributes' => array(),
            'translations' => array(),
        );
        $mainNodeId = null;
        if ($mainNode) {
            $mainNodeId = (int)$mainNode->attribute('node_id');
            $data['main_node'] = array(
                'node_id' => $mainNodeId,
                'uuid' => $mainNode->remoteId(),
            );
        }
        foreach ($contentObject->stateIDArray() as $groupId => $stateId) {
            if (!isset($this->stateGroupIdMap[$groupId])) {
                $this->stateGroupIdMap[$groupId] = $group = \eZContentObjectStateGroup::fetchById($groupId);
            } else {
                $group = $this->stateGroupIdMap[$groupId];
            }
            if (!isset($this->stateIdMap[$stateId])) {
                $this->stateIdMap[$stateId] = $state = \eZContentObjectState::fetchById($stateId);
            } else {
                $state = $this->stateIdMap[$stateId];
            }
            if ($state) {
                $data['states'][$group->attribute('identifier')] = $state->attribute('identifier');
            }
        }

        // Add related objects, referenced by UUID, includes ID, name and class identifier to make
        // it easier for human inspection
        foreach ($contentObject->relatedContentObjectList() as $relatedObject) {
            $data['related'][] = array(
                'object_id' => (int)$relatedObject->attribute('id'),
                'uuid' => $relatedObject->remoteId(),
                'name' => $relatedObject->name(),
                'class_identifier' => $relatedObject->contentClassIdentifier(),
            );
        }
        if (!$data['related']) {
            unset($data['related']);
        }

        // Add ownership, referenced by ID and UUID
        $owner = $contentObject->owner();
        if ($owner) {
            $data['owner'] = array(
                'object_id' => (int)$owner->attribute('id'),
                'uuid' => $owner->remoteId(),
                'name' => $owner->name(),
            );
        }

        // Add translations for name and attributes
        foreach ($contentObject->languages() as $language) {
            $locale = $language->attribute('locale');
            $langData = array(
                'name' => $contentObject->name(false, $locale),
                'attributes' => array(),
            );
            foreach ($contentObject->contentObjectAttributes(/*asObject*/true, /*version*/false, /*language*/$locale) as $contentAttribute) {
                if (!$contentAttribute->contentClassAttributeCanTranslate()) {
                    continue;
                }
                $typeIdentifier = $contentAttribute->attribute('identifier');
                $fieldIdentifier = $contentAttribute->contentClassAttributeIdentifier();
                $attribute = new ContentObjectAttribute($typeIdentifier, null,  array(
                    'contentAttribute' => $contentAttribute,
                    'language' => $locale,
                ));
                $attributeData = $attribute->attributeFields($contentObject);
                $langData['attributes'][$fieldIdentifier] = $attributeData;
            }
            $data['translations'][$locale] = $langData;
        }

        // Add non-translatable attributes
        foreach ($contentObject->contentObjectAttributes() as $contentAttribute) {
            if ($contentAttribute->contentClassAttributeCanTranslate()) {
                continue;
            }
            $typeIdentifier = $contentAttribute->attribute('data_type_string');
            $fieldIdentifier = $contentAttribute->contentClassAttributeIdentifier();
            $attribute = new ContentObjectAttribute($typeIdentifier, null,  array(
                'contentAttribute' => $contentAttribute,
                'language' => $locale,
            ));
            $attributeData = $attribute->attributeFields($contentObject);
            $data['attributes'][$fieldIdentifier] = $attributeData;
        }

        return $data;
    }

    public function addContentClass($contentClass)
    {
        $identifier = $contentClass->attribute('identifier');
        if (isset($this->classMap[$identifier])) {
            return;
        }
        $data = array(
            '__type__' => 'ez_contentclass',
            'identifier' => $identifier,
            'name' => $contentClass->name(),
            // The structure only contains the minimal to allow for identifier/type matching
            'sparse' => true,
            'type_map' => array(),
        );
        foreach ($contentClass->fetchAttributes() as $attribute) {
            $data['type_map'][$attribute->attribute('identifier')] = $attribute->attribute('data_type_string');
        }
        $this->classMap[$identifier] = $data;
    }

    public function addObject($contentObject, $withLocations=false)
    {
        $objectId = $contentObject->attribute('id');
        if (isset($this->objectMap[$objectId])) {
            return;
        }

        $objectData = $this->exportContentObject($contentObject);
        $this->objectMap[$objectId] = $objectData;

        $sectionIdentifier = $objectData['section_identifier'];
        if (!isset($this->sectionMap[$sectionIdentifier])) {
            $this->sectionMap[$sectionIdentifier] = array();
        }
        foreach ($objectData['states'] as $stateGroup => $stateId) {
            if (!isset($this->stateMap[$stateGroup])) {
                $this->stateMap[$stateGroup] = array();
            }
        }
        foreach ($objectData['translations'] as $locale => $translation) {
            if (!isset($this->languageMap[$locale])) {
                $this->languageMap[$locale] = array();
            }
        }
        $this->objectMap[$objectId]['locations'] = array();
        if ($withLocations) {
            foreach ($contentObject->assignedNodes() as $node) {
                $this->addNode($node);
            }
        }

        $this->addContentClass($contentObject->contentClass());

        if ($this->includeOwners && $objectData['owner']) {
            $this->addObject($contentObject->owner(), /*$withLocations*/true);
        }

        if ($this->includeRelations) {
            foreach ($contentObject->relatedContentObjectList(false, false, 0, false, array('AllRelations' => true)) as $relatedObject) {
                $this->addObject($relatedObject, /*withLocations*/true);
            }
        }
    }

    public function addNode($node) {
        $nodeId = $node->attribute('node_id');
        // Never export the top-most root node, or any marked as excluded
        if ($nodeId == 1) {
            return;
        }
        $objectId = $node->attribute('contentobject_id');
        if (isset($this->objectMap[$objectId]['locations'][$nodeId])) {
            return;
        }
        $nodeUuid = $node->attribute('remote_id');
        if (!isset($this->excludedNodes[$nodeUuid])) {
            if (!isset($this->objectMap[$objectId])) {
                $contentObject = $node->object();
                $this->addObject($contentObject, /*withLocations*/true);
                $this->objectMap[$objectId]['locations'][$nodeId] = $this->exportNode($node);

                foreach ($contentObject->assignedNodes() as $assignedNode) {
                    $assignedNodeId = $assignedNode->attribute('node_id');
                    if (!isset($this->objectMap[$objectId]['locations'][$assignedNodeId])) {
                        $this->addNode($assignedNode);
                    }
                }
            } else {
                $this->objectMap[$objectId]['locations'][$nodeId] = $this->exportNode($node);
            }
        }
        if ($this->includeParents && $node->attribute('parent_node_id') != 1) {
            $parentNode = $node->fetchParent();
            if ($parentNode) {
                // echo "add parent ", $parentNode->attribute('node_id'), "\n";
                $this->addNode($parentNode);
            }
        }
    }

    /**
     * Adds a file entry to the export, the file is referenced by a uuid and
     * points to a path. If the path does not exist or isn't a file no file
     * is added.
     * 
     * @return true if the file was added, false otherwise
     */
    public function addFile($uuid, $path)
    {
        $file = \eZFileHandler::instance(false);
        if ($file->isFile($path)) {
            $fileSize = is_file($path) ? filesize($path) : null;
            $this->fileMap[$uuid] = array(
                '__type__' => 'file',
                'uuid' => $uuid,
                'original_path' => $path,
                'size' => $fileSize,
            );
            if ($this->embedFileData) {
                $file->open($path, "r");
                try {
                    $binaryData = $file->read();
                    $file->close();
                } catch (\Exception $e) {
                    $file->close();
                    return false;
                }
                $this->fileMap[$uuid]['md5'] = md5($binaryData);
                $this->fileMap[$uuid]['content_b64'] = base64_encode($binaryData);
            } else {
                $newPath = $this->fileStorage . '/' . $uuid;
                if (!file_exists($this->fileStorage)) {
                    \eZDir::mkdir($this->fileStorage, false, true);
                }
                \eZFileHandler::copy($path, $newPath);
                $this->fileMap[$uuid]['path'] = $newPath;
                $this->fileMap[$uuid]['md5'] = md5_file($newPath);
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * Add all nodes in query-set to export.
     */
    public function addQuery($query) {
        // Contains all objects that are found with nodes added as locations
        // This basically reverses the list of nodes into an object/location.
        foreach ($query as $node) {
            $this->addNode($node);
        }
    }

    /**
     * Finalize export by finding extra information on references and other
     * identifiers that are used.
     */
    public function finalize() {
        // Gather info on languages that are used
        foreach ($this->languageMap as $locale => $language) {
            $language = eZContentLanguage::fetchByLocale($locale);
            $this->languageMap[$locale] = array(
                '__type__' => 'ez_contentlanguage',
                'locale' => $locale,
                'name' => $language->attribute('name'),
            );
            if ($language->attribute('disabled') || !$this->sparse) {
                $this->languageMap[$locale]['is_disabled'] = (bool)$language->attribute('disabled');
            }
        }

        // Gather info on sections that are used
        foreach ($this->sectionMap as $sectionIdentifier => $section) {
            $section = eZSection::fetchByIdentifier($sectionIdentifier);
            $this->sectionMap[$sectionIdentifier] = array(
                '__type__' => 'ez_section',
                'identifier' => $sectionIdentifier,
                'name' => $section->attribute('name'),
                'navigation_part_identifier' => $section->attribute('navigation_part_identifier'),
            );
            if (!$this->sparse || $section->attribute('locale')) {
                $this->sectionMap[$sectionIdentifier]['locale'] = $section->attribute('locale');
            }
        }

        // Gather info on states that are used
        $stateMap = $this->stateMap;
        $this->stateMap = array();
        foreach ($stateMap as $groupIdentifier => $group) {
            $group = \eZContentObjectStateGroup::fetchByIdentifier($groupIdentifier);
            $groupData = array(
                '__type__' => 'ez_contentstate',
                'identifier' => $groupIdentifier,
                'translations' => array(),
                'states' => array(),
            );
            foreach ($group->translations() as $translation) {
                $translationData = array(
                    'name' => $translation->attribute('name'),
                );
                if (!$this->sparse || $translation->attribute('description')) {
                    $translationData['description'] = $translation->attribute('description');
                }
                $groupData['translations'][$translation->language()->attribute('locale')] = $translationData;
            }
            foreach ($group->states() as $state) {
                $stateData = array(
                    'translations' => array(),
                );
                foreach ($state->translations() as $translation) {
                    $translationData = array(
                        'name' => $translation->attribute('name'),
                    );
                    if (!$this->sparse || $translation->attribute('description')) {
                        $translationData['description'] = $translation->attribute('description');
                    }
                    $stateData['translations'][$translation->language()->attribute('locale')] = $translationData;
                }
                $groupData['states'][$state->attribute('identifier')] = $stateData;
            }
            $this->stateMap[$group->attribute('identifier')] = $groupData;
        }

        // See if there references from attributs/data-types that need to be added to export
        $this->finalizeAttributes();
    }

    /**
     * Finalize information about attribute/data-type that reference external definitions,
     * for instance files, images, vats and tags.
     */
    public function finalizeAttributes()
    {
        foreach ($this->objectMap as $idx => $objectData) {
            if (!isset($objectData['attributes']) || !isset($objectData['translations'])) {
                // In case the object entry only has locations set
                continue;
            }
            $class = $this->classMap[$objectData['class_identifier']];
            foreach ($objectData['attributes'] as $identifier => $attributeData) {
                $type = $class['type_map'][$identifier];
                $newData = $this->finalizeAttribute($type, $identifier, $attributeData, null);
                if ($newData !== null) {
                    $this->objectMap[$idx]['attributes'][$identifier] = $newData;
                }
            }
            foreach ($objectData['translations'] as $language => $translation) {
                foreach ($translation['attributes'] as $identifier => $attributeData) {
                    $type = $class['type_map'][$identifier];
                    $newData = $this->finalizeAttribute($type, $identifier, $attributeData, $language);
                    if ($newData !== null) {
                        $this->objectMap[$idx]['translations'][$language]['attributes'][$identifier] = $newData;
                    }
                }
            }
        }
    }

    /**
     * Finalize information about one specific attribute, can for instance add files, relations
     * or modify data in some manner.
     */
    public function finalizeAttribute($type, $identifier, $attributeData, $language=null)
    {
        if ($type === 'ezbinaryfile' || $type === 'ezimage') {
            $path = $attributeData['path'];
            $uuid = sha1($path);
            if (isset($this->fileMap[$uuid])) {
                $attributeData['uuid'] = $uuid;
                return $attributeData;
            }
            if ($this->addFile($uuid, $path)) {
                $attributeData['uuid'] = $uuid;
            } else {
                $attributeData['found'] = false;
            }
            return $attributeData;
        } else if ($type == 'ezxmltext') {
            if (!$attributeData) {
                return;
            }
            $references = $attributeData['referenced_objects'];
            unset($attributeData['referenced_objects']);
            if (!$this->includeEmbeds) {
                return $attributeData;
            }
            foreach ($references as $referenceObject) {
                $this->addObject($referenceObject, /*withLocations*/true);
            }
            return $attributeData;
        } if ($type == 'eztags') {
            if (!$attributeData) {
                return;
            }
            foreach ($attributeData as $tagData) {
                if (isset($this->tagMap[$tagData['uuid']])) {
                    continue;
                }
                $tag = \eZTagsObject::fetchByRemoteID($tagData['uuid']);
                $this->addTag($tag);
            }
        }
    }

    public function addTag($tag)
    {
        if (!$tag) {
            return;
        }
        $uuid = $tag->attribute('remote_id');
        if (isset($this->tagMap[$uuid])) {
            return;
        }
        $data = array(
            '__type__' => 'eztag',
            'uuid' => $uuid,
            'id' => $tag->attribute('id'),
            'parent_id' => $tag->attribute('parent_id'),
            'keyword' => $tag->attribute('keyword'),
        );
        $parent = null;
        if ($tag->attribute('parent_id')) {
            $parent = $tag->getParent();
            $data['parent_uuid'] = $parent->attribute('remote_id');
        }
        foreach ($tag->getTranslations() as $translation) {
            $locale = $translation->languageName();
            $locale = $locale['locale'];
            $data['translations'][$locale] = $translation->attribute('keyword');
        }

        $this->tagMap[$uuid] = $data;
        if ($parent) {
            $this->addTag($parent);
        }
    }

    public function createIndex()
    {
        $index = array(
            "__type__" => "index",
            'export_date' => (new DateTime())->format(DateTime::RFC3339),
            'types' => $this->createTypeList(),
            'type_counts' => $this->createTypeCountList(),
        );
        return $index;
    }

    public function createTypeList()
    {
        $types = array();
        if ($this->languageMap) {
            $types[] = 'content_language';
        }
        if ($this->sectionMap) {
            $types[] = 'section';
        }
        if ($this->stateMap) {
            $types[] = 'content_state';
        }
        if ($this->tagMap) {
            $types[] = 'tag';
        }
        if ($this->classMap) {
            $types[] = 'content_class';
        }
        if ($this->fileMap) {
            $types[] = 'file';
        }
        if ($this->objectMap) {
            $types[] = 'content_object';
        }
        return $types;
    }

    public function createTypeCountList()
    {
        $typeCounts = array();
        if ($this->languageMap) {
            $typeCounts['content_language'] = count($this->languageMap);
        }
        if ($this->sectionMap) {
            $typeCounts['section'] = count($this->sectionMap);
        }
        if ($this->stateMap) {
            $typeCounts['content_state'] = count($this->stateMap);
        }
        if ($this->tagMap) {
            $typeCounts['tag'] = count($this->tagMap);
        }
        if ($this->classMap) {
            $typeCounts['content_class'] = count($this->classMap);
        }
        if ($this->fileMap) {
            $typeCounts['file'] = count($this->fileMap);
        }
        if ($this->objectMap) {
            $typeCounts['content_object'] = count($this->objectMap);
        }
        return $typeCounts;
    }

    public function getExportItems()
    {
        $data = array();
        if ($this->languageMap) {
            $data['content_languages'] = $this->languageMap;
        }
        if ($this->sectionMap) {
            $data['sections'] = $this->sectionMap;
        }
        if ($this->stateMap) {
            $data['content_states'] = $this->stateMap;
        }
        if ($this->tagMap) {
            $data['tags'] = $this->tagMap;
        }
        if ($this->classMap) {
            $data['content_classes'] = $this->classMap;
        }
        if ($this->fileMap) {
            $data['files'] = $this->fileMap;
        }
        if ($this->objectMap) {
            $data['content_objects'] = $this->objectMap;
        }
        return $data;
    }
}
