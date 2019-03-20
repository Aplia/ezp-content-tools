<?php

@include_once 'config.php';
require_once 'autoload.php';
set_time_limit( 0 );

$cli = \eZCLI::instance();
$script = \eZScript::instance(
    array(
        'description' =>
            "Finds all data_text entries containing search string. Pipe output to file for saving log. The script is default case sensitive.",
        'use-session' => false,
        'use-modules' => true,
        'use-extensions' => true,
        'site-access' => 'no',
    )
);

$script->initialize();
$script->startup();

$options = $script->getOptions( "[replace][ignore:][case-insensitive][print-urls]", "", array(
    'replace' => 'Perform replace action',
    'ignore' => 'Contentobject ids to ignore. Separate multiple ids with comma (\',\')',
    'case-insensitive' => 'Case insensitive search',
    'print-urls' => 'Print urls instead of path_identification_string (fetches every node when printing)',
));

$obj = new SearchAndReplace( $options );
$script->shutdown();

class SearchAndReplace
{
    function __construct($params) {
        $arguments = isset($params['arguments']) ? $params['arguments'] : array();

        $searchString = isset($arguments[0]) ? $arguments[0] : '';
        $replaceString = isset($arguments[1]) ? $arguments[1] : '';

        $this->ignoreIds = isset($params['ignore']) ? explode(',', $params['ignore']) : array();
        $this->binary = isset($params['case-insensitive']) ? '' : ' BINARY';
        $this->urls = isset($params['print-urls']);
        if ($searchString) {
            $objectIdentifiers = $this->search($searchString);
            $objectMatches = count($objectIdentifiers);

            if ($replaceString) {
                if ($objectMatches) {
                    if ($params['replace']) {
                        print("Replacing occurences of '$searchString' with '$replaceString' in $objectMatches objects ... \n");
                        $this->replace($objectIdentifiers, $searchString, $replaceString);
                    } else {
                        print("Run this command with --replace option to replace '$searchString' with '$replaceString' for $objectMatches objects \n");
                    }
                } else {
                    print("Found no matching objects. \n");
                }
            }
        }
    }

    private function search($string) {
        print("Searching for occurrences of $string ... \n");
        $db = \eZDB::instance();
        $search = $db->escapeString($string);
        $sql = "
            SELECT  o.id, c.identifier, ca.identifier, ot.path_identification_string, ot.node_id
            FROM    ezcontentobject_attribute oa, ezcontentobject o, ezcontentclass c,
                    ezcontentclass_attribute ca, ezcontentobject_tree ot
            WHERE   o.id=oa.contentobject_id
                AND o.id=ot.contentobject_id
                AND o.current_version=oa.version
                AND o.contentclass_id=c.id
                AND ca.contentclass_id=c.id
                AND ca.id=oa.contentclassattribute_id
                AND c.version=0
                AND$this->binary oa.data_text REGEXP '$search'
            ORDER BY o.id;
        ";

        $occurrences = $db->arrayQuery($sql);
        $matches = count($occurrences);
        $objectIdentifiers = array();

        if ($occurrences) {
            print("Found $matches occurrences:\n");
        }
        foreach ($occurrences as $occurrence) {
            $id = $occurrence['id'];
            $identifier = $occurrence['identifier'];
            $location = $occurrence['path_identification_string'];
            if ($this->urls) {
                $node = eZContentObjectTreeNode::fetch($occurrence['node_id']);
                if ($node) {
                    $url = $node->urlAlias();
                    \eZURI::transformURI($url);
                    $location = $url;
                }
            }
            if (!in_array($id, $this->ignoreIds)) {
                print("    Match:  $id, $identifier, $location \n");
                $objectIdentifiers[$id][] = $identifier;
            } else {
                print("    Ignore: $id, $identifier, $location \n");
            }
        }

        return $objectIdentifiers;
    }

    private function replace($objectIdentifiers, $searchString, $replaceString) {
        $objectIds = array_keys($objectIdentifiers);

        $contentObjects = eZContentObject::fetchIDArray($objectIds);
        $contentObjectsById = array();
        $failedObjectIds = array();
        foreach ($contentObjects as $contentObject) {
            $contentObjectsById[$contentObject->attribute('id')] = $contentObject;
        }

        foreach ($objectIdentifiers as $objectId => $identifiers) {
            if (isset($contentObjectsById[$objectId])) {
                $currentObject = $contentObjectsById[$objectId];
            } else {
                // Somehow, some objects could be fetched with fetch, but not fetchIDArray()
                // These objects also created errors with Aplia\Content\ContentObject
                // $retriedObject = eZContentObject::fetch($objectId);
                // if ($retriedObject) {
                //     $currentObject = $retriedObject;
                // } else {
                    $failedObjectIds[] = $objectId;
                // }
                $currentObject = null;
            }

            if ($currentObject) {
                $objectUpdater = new Aplia\Content\ContentObject(array('uuid' => $currentObject->attribute('remote_id')));

                foreach ($identifiers as $identifier) {
                    $old = $objectUpdater->getContentAttributeValue($identifier);
                    $new = $old;

                    // @TODO Parameter for printing excerpt of matched string before and after replace, with confirmation for replacement

                    if (!is_string($old)) {
                        // Does not work, and will give an error
                        if ($old instanceof \eZImageAliasHandler and isset($old->ContentObjectAttributeData['data_text'])) {
                            $new->ContentObjectAttributeData['data_text'] = str_replace($searchString, $replaceString, $old->ContentObjectAttributeData['data_text']);
                            $new = $new->ContentObjectAttributeData;
                        }
                    } else {
                        $new = str_replace($searchString, $replaceString, $old);
                    }

                    $objectUpdater->setAttribute($identifier, $new);
                }
                try {
                    $objectUpdater->update();
                } catch (Exception $e) {
                    print("Encountered error when updating '$identifier', object id: $objectId \n");
                    $failedObjectIds[] = $objectId;
                }
            }
        }

        if ($failedObjectIds) {
            print("Replace finished, but these objects failed:\n");
            foreach ($failedObjectIds as $objectId) {
                print(" -   $objectId\n");
            }
        } else {
            print("Replace finished. \n");
        }
    }
}
