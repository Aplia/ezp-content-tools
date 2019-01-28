<?php
namespace Aplia\Content;
use Aplia\Support\Arr;

/**
 * Exports content object data to PHP code.
 */
class ContentPhpExporter
{
    public $exporter;
    public $useNamespace = false;
    public $contentTypeClass = '\\Aplia\\Content\\ContentType';

    public function __construct($exporter, array $params = null)
    {
        $this->exporter = $exporter;
        $this->useNamespace = Arr::get($params, 'useNamespace', false);
    }

    public function write($text)
    {
        if ($this->writer) {
            $this->writer->write($text);
        } else {
            echo $text;
        }
    }

    public function generatePreamble()
    {
        $this->write(<<<'EOT'
<?php


EOT
        );

        if ($this->useNamespace) {
            $this->write(<<<'EOT'
use Aplia\Content\ContentType;


EOT
            );
        }

        $this->write(<<<'EOT'
require 'config.php';
require 'autoload.php';

$cli = \eZCLI::instance();
$script = \eZScript::instance(
    array(
        'description' =>
            "Defines content object definitions",
        'use-session' => false,
        'use-modules' => true,
        'use-extensions' => true,
    )
);
$script->startup();
$options = $script->getOptions("", "", array());
$script->initialize();
$db = \eZDB::instance();
$db->begin();


EOT
        );
    }

    public function generateContentObjectCode($contentObjectId, $contentObject, $language, $withLocation=false, $isUpdate=false)
    {
        $commonObjectAttributes = (isset($contentObject['attributes']) && count($contentObject['attributes'])) ? $contentObject['attributes'] : array();
        $objectName = $contentObject['name'];
        $params = array();
        if (isset($contentObject['uuid'])) {
            $params['uuid'] = $contentObject['uuid'];
        }
        $params['language'] = $language;
        $uuidString = isset($params['uuid']) ? ", uuid: ".$params['uuid'] : "";
        $className = $contentObject['class_identifier'];
        $classNameRepr = "\$" . underscoreToCamelCase($className) . "Type";
        $objectNameRepr = "\$" . lcfirst(str_replace(" ", "", trim($objectName))) . ucfirst(underscoreToCamelCase($className));
        $objectNameRepr = str_replace("-", "", $objectNameRepr);
        $contentTypeClass = $this->contentTypeClass;

        if ($isUpdate) {
            $objectFields = array();
        } else {
            $objectFields = array(
                'sectionIdentifier' => $contentObject['section_identifier'],
                'publishedDate' => $contentObject['published_date'],
                'states' => array_values($contentObject['states']),
            );
            if (isset($contentObject['owner'])) {
                $objectFields['ownerUuid'] = $contentObject['owner']['uuid'];
            }
            if (!$contentObject['is_always_available']) {
                $objectFields['alwaysAvailable'] = false;
            }
            if ($contentObject['status'] !== 'published') {
                $objectFields['status'] = $contentObject['status'];
            }
        }

        if (isset($initializedClasses[$className])) {
            $initializedClasses[$className][] = $objectName . $uuidString;
        } else {
            $initializedClasses[$className] = array($objectName . $uuidString);
            echo "\n${classNameRepr} = new ${contentTypeClass}('${className}');\n";
        }

        echo "${objectNameRepr} = ${classNameRepr}->contentObject(\n" . indentTextLines(var_export($params, true))."\n)\n";
        if ($objectFields) {
            echo "    ->set(" . indentTextLines(var_export($objectFields, true), 4, false) .")\n";
        }

        if ($withLocation && isset($contentObject['locations'])) {
            foreach ($contentObject['locations'] as $nodeId => $contentNode) {
                if (!isset($contentNode['parent_node_uuid'])) {
                    $script->shutdown(1, "Parent node uuid not defined in content object $contentObjectId");
                }

                $parentTreeIdentifier = \Aplia\Content\ContentObject::mapNodeToTreeIdentifier($contentNode['parent_node_id']);
                $locationFields = array();
                if ($parentTreeIdentifier === 'top') {
                    $locationFields['parent_node_id'] = 1;
                } else {
                    $locationFields['parent_uuid'] = $contentNode['parent_node_uuid'];
                }
                $locationFields['uuid'] = $contentNode['uuid'];
                $locationFields['sort_by'] = $contentNode['sort_by'];
                if ($contentNode['priority']) {
                    $locationFields['priority'] = $contentNode['priority'];
                }
                if ($contentNode['visibility'] !== 'visible') {
                    $locationFields['visibility'] = $contentNode['visibility'];
                }
                $treeIdentifier = \Aplia\Content\ContentObject::mapNodeToTreeIdentifier($contentNode['node_id']);
                if ($treeIdentifier) {
                    $locationFields['tree_identifier'] = $treeIdentifier;
                }
                if ($parentTreeIdentifier) {
                    $locationFields['parent_tree_identifier'] = $parentTreeIdentifier;
                }
                $locationData = indentTextLines(var_export($locationFields, true), 4, false);
                echo "    ->addLocation($locationData)\n";
            }
        }

        if (isset($contentObject['translations'][$language])) {
            $languageData = $contentObject['translations'][$language];
            $objectAttributes = $commonObjectAttributes;
            if (isset($languageData['attributes'])) {
                $objectAttributes = array_merge($objectAttributes, $languageData['attributes']);
            }
        }

        if ($objectAttributes) {
            foreach ($objectAttributes as $attrIdentifier => $attribute) {
                if ($attribute && (isset($attribute['found']) ? $attribute['found'] : true)) {
                    echo "    ->setAttribute(" . var_export($attrIdentifier, true) . ", " . var_export($attribute, true) . ")\n";
                }
            }
        }

        if ($isUpdate) {
            echo "    ->update();\n\n";
        } else {
            echo "    ->create();\n\n";
        }
    }
}
