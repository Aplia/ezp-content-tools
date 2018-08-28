<?php
namespace Aplia\Content;
use Aplia\Support\Arr;
use Aplia\Content\Exceptions\ImportDenied;
use Aplia\Content\Exceptions\UnsetValueError;
use Aplia\Content\Exceptions\TypeError;
use Aplia\Content\Exceptions\ValueError;

class ContentImporter
{
    public $startNode;
    public $lastRecordType;
    public $hasIndex = false;
    public $hasBundle = false;

    public $askOverwrite = true;
    public $askNew = true;
    public $verbose = true;

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
    
    // property $sectionIndex = array();
    // property $languageIndex = array();
    // property $stateIndex = array();

    public function __construct(array $options = null) {
        if (isset($options['start_node'])) {
            $this->startNode = $options['start_node'];
        }
        if (!$this->startNode) {
            throw new UnsetValueError("ContentImporter requires start_node set");
        }
    }

    public function __isset($name)
    {
        return $name === 'sectionIndex' || $name === 'languageIndex' || $name == 'stateIndex';
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
        }
    }

    public function prompt($prompt, $default) {
        $result = readline($prompt);
        if (!trim($result)) {
            return $default;
        }
        return trim(strtolower($result));
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
    }

    /**
     * Loads all existing eZSection identifiers and registers them in the index.
     */
    public function loadSections()
    {
        foreach (\eZSection::fetchList(false) as $section) {
            $this->sectionIndex[$section['identifier']] = array(
                'id' => $section['id'],
                'new' => false,
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
                'new' => false,
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
            $this->stateIndex[$stateGroup->attribute('identifier')] = array(
                'id' => $stateGroup->attribute('id'),
                'new' => false,
                'identifier' => $stateGroup->attribute('identifier'),
            );
        }
        return $this->stateIndex;
    }

    public function importSection($sectionData)
    {
        if (!isset($sectionData['identifier'])) {
            throw new TypeError("Key 'identifier' missing from section record");
        }
        $identifier = $sectionData['identifier'];
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
        $name = Arr::get($sectionData, 'name');
        $part = Arr::get($sectionData, "navigation_part_identifier");
        if (isset($this->sectionIndex[$identifier])) {
            $existing = $this->sectionIndex[$identifier];
            if ($name == $existing['name'] && $part == $existing['navigation_part_identifier']) {
                // Same as existing section, skip
                if ($this->verbose) {
                    echo "Section $identifier already exist\n";
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
                if ($this->promptYesOrNo("Section '$identifier' does not exist, do you wish to import it? [yes|no] ") !== "yes") {
                    throw new ImportDenied("Section '$identifier' not imported, cannot continue");
                }
            }
        }
        if (!$name) {
            $name = $identifier;
        }
        if (!$part) {
            $part = "ezcontentnavigationpart";
        }
        $section = new eZSection(array(
            'identifier' => $identifier,
            'name' => $name,
            'navigation_part_identifier' => $part,
        ));
        $section->store();
        // Store in index with ID
        $this->sectionIndex[$identifier] = array(
            'id' => $section->attribute('id'),
            'new' => true,
        );

        if ($this->verbose) {
            echo "Imported section $identifier\n";
        }
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
            'new' => true,
        );

        if ($this->verbose) {
            echo "Imported content language $identifier\n";
        }
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

        // Store in index with ID
        $this->stateIndex[$identifier] = array(
            'id' => $stateGroup->attribute('id'),
            'new' => true,
        );
        if ($this->verbose) {
            echo "Imported content object state group $identifier\n";
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
        echo "Import contains: ", implode(", ", $types), "\n";
        if ($this->promptYesOrNo("Do you wish to continue with import? [yes|no] ") !== "yes") {
            throw new ImportDenied("Import stopped");
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
        echo "Import contains: ", implode(", ", $types), "\n";
        if ($this->promptYesOrNo("Do you wish to continue with import? [yes|no] ") !== "yes") {
            throw new ImportDenied("Import stopped");
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
                }
            }
            if (isset($data['sections'])) {
                foreach ($data as $record) {
                    $this->importSection($record);
                }
            }
            if (isset($data['content_states'])) {
                foreach ($data as $record) {
                    $this->importState($record);
                }
            }
            if (isset($data['content_classes'])) {
            }
            if (isset($data['content_objects'])) {
            }
        } else if ($type == 'ez_section') {
            $this->importSection($data);
        } else if ($type == 'ez_contentlanguage') {
            $this->importContentLanguage($data);
        } else if ($type == 'ez_contentstate') {
            $this->importState($data);
        } else if ($type == 'ez_contentclass') {
        } else if ($type == 'ez_contentobject') {
        } else {
            throw new TypeError("Unsupported record type $type");
        }
        $this->lastRecordType = $type;
    }
}
