<?php

namespace Aplia\Utilities;

use Aplia\Content\ContentObject;
use Exception;
use eZContentObject;
use eZContentObjectTreeNode;
use eZImageAliasHandler;

class SearchAndReplace
{
    function __construct($params) {
        $arguments = isset($params['arguments']) ? $params['arguments'] : array();

        $searchString = isset($arguments[0]) ? $arguments[0] : '';
        $replaceString = isset($arguments[1]) ? $arguments[1] : '';

        $this->ignoreIds = isset($params['ignore']) ? explode(',', $params['ignore']) : array();
        $this->caseInsensitive = isset($params['case-insensitive']);
        $this->binary = isset($params['case-insensitive']) ? '' : ' BINARY';
        $this->urls = isset($params['print-urls']);
        $this->newVersion = isset($params['new-version']);
        $this->firstUpper = isset($params['first-upper']);
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
                $failedObjectIds[] = $objectId;
                $currentObject = null;
            }

            if ($currentObject) {
                $params = array('uuid' => $currentObject->attribute('remote_id'));
                if ($this->newVersion) {
                    $params["ownerId"] = 14;
                    $params["newVersion"] = true;
                }
                $objectUpdater = new ContentObject($params);

                foreach ($identifiers as $identifier) {
                    $old = $objectUpdater->getContentAttributeValue($identifier);
                    $new = $old;

                    // @TODO Parameter for printing excerpt of matched string before and after replace, with confirmation for replacement

                    if (!is_string($old)) {
                        // Does not work, and will give an error
                        if ($old instanceof eZImageAliasHandler and isset($old->ContentObjectAttributeData['data_text'])) {
                            $new->ContentObjectAttributeData['data_text'] = str_replace($searchString, $replaceString, $old->ContentObjectAttributeData['data_text']);
                            $new = $new->ContentObjectAttributeData;
                        }
                    } else {
                        if ($this->caseInsensitive) {
                            if (stripos($old, $searchString) === 0 && $this->firstUpper) {
                                $new = str_ireplace($searchString, ucfirst($replaceString), $old);
                            } else {
                                $new = str_ireplace($searchString, $replaceString, $old);
                            }
                        } else {
                            if (strpos($old, $searchString) === 0 && $this->firstUpper) {
                                $new = str_replace($searchString, ucfirst($replaceString), $old);
                            } else {
                                $new = str_replace($searchString, $replaceString, $old);
                            }
                        }
                    }

                    $objectUpdater->setAttribute($identifier, $new);
                }
                try {
                    $objectUpdater->update();
                } catch (Exception $e) {
                    print("Encountered error when updating '$identifier', object id: $objectId. Update this manually.\n");
                    $failedObjectIds[] = $objectId;
                }
            }
        }

        if ($failedObjectIds) {
            print("Replace finished, but these objects failed, and require manual update:\n");
            foreach ($failedObjectIds as $objectId) {
                print(" -   $objectId\n");
            }
        } else {
            print("Replace finished. \n");
        }
    }
}
