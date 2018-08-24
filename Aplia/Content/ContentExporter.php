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
        $data = array(
            '__type__' => 'ez_contentnode',
            'node_id' => (int)$node->attribute('node_id'),
            'parent_node_id' => (int)$node->attribute('parent_node_id'),
            'uuid' => $node->remoteId(),
            'parent_node_uuid' => $parent->remoteId(),
            'original_depth' => (int)$node->attribute('depth'),
            'original_path' => $path,
            'url_alias' => $node->urlAlias(),
            'sort_by' => ContentType::encodeSortBy($node->attribute('sort_field'), $node->attribute('sort_order')),
            'priority' => (int)$node->attribute('priority'),
            'visibility' => $visibility,
        );
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
            'modified_date' => $modifiedDate->format(DateTime::RFC3339),
            'published_date' => $publishedDate->format(DateTime::RFC3339),
            'is_always_available' => (bool)$contentObject->isAlwaysAvailable(),
            'related' => array(),
            'main_node' => null,
            'states' => array(),
            'attributes' => array(),
            'translations' => array(),
        );
        if ($mainNode) {
            $data['main_node'] = array(
                'node_id' => (int)$mainNode->attribute('node_id'),
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
            $data['states'][$group->attribute('identifier')] = $state->attribute('identifier');
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
            foreach ($contentObject->relatedContentObjectList() as $relatedObject) {
                $this->addObject($relatedObject);
            }
        }
    }

    public function addNode($node) {
        $objectId = $node->attribute('contentobject_id');
        $nodeId = $node->attribute('node_id');
        if (isset($this->objectMap[$objectId]['locations'][$nodeId])) {
            return;
        }
        if (!isset($this->objectMap[$objectId])) {
            $this->addObject($node->object());
        }
        $this->objectMap[$objectId]['locations'][$nodeId] = $this->exportNode($node);
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

    public function finalizeAttribute($type, $identifier, $attributeData, $language=null)
    {
        if ($type === 'ezbinaryfile') {
            $path = $attributeData['path'];
            $uuid = sha1($path);
            if (isset($this->fileMap[$uuid])) {
                $attributeData['uuid'] = $uuid;
                return $attributeData;
            }
            $file = \eZFileHandler::instance(false);
            if ($file->isFile($path)) {
                $attributeData['uuid'] = $uuid;
                $this->fileMap[$uuid] = array(
                    '__type__' => 'file',
                    'original_path' => $path,
                );
                if ($this->embedFileData) {
                    $file->open($path, "r");
                    try {
                        $binaryData = $file->read();
                        $file->close();
                    } catch (\Exception $e) {
                        $file->close();
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
                }
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
                $this->addObject($referenceObject);
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
            'types' => array(),
        );
        if ($this->languageMap) {
            $index['types'][] = 'content_language';
        }
        if ($this->sectionMap) {
            $index['types'][] = 'section';
        }
        if ($this->stateMap) {
            $index['types'][] = 'content_state';
        }
        if ($this->fileMap) {
            $index['types'][] = 'file';
        }
        if ($this->tagMap) {
            $index['types'][] = 'tag';
        }
        if ($this->classMap) {
            $index['types'][] = 'content_class';
        }
        if ($this->objectMap) {
            $index['types'][] = 'content_object';
        }
        return $index;
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
        if ($this->fileMap) {
            $data['files'] = $this->fileMap;
        }
        if ($this->tagMap) {
            $data['tags'] = $this->tagMap;
        }
        if ($this->classMap) {
            $data['content_classes'] = $this->classMap;
        }
        if ($this->objectMap) {
            $data['content_objects'] = $this->objectMap;
        }
        return $data;
    }
}
