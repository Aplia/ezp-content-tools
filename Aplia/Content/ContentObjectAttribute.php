<?php
namespace Aplia\Content;

use Exception;
use Aplia\Support\Arr;
use Aplia\Content\ImageFile;
use Aplia\Content\HttpFile;
use Aplia\Content\Exceptions\AttributeError;
use Aplia\Content\Exceptions\ValueError;
use Aplia\Content\Exceptions\TypeError;
use Aplia\Content\Exceptions\UnsetValueError;
use Aplia\Content\Exceptions\HtmlError;
use Aplia\Content\Exceptions\FileDoesNotExist;
use Aplia\Content\Exceptions\ContentError;

class ContentObjectAttribute
{
    public $identifier;
    public $value;
    public $id;
    public $contentAttribute;
    public $language;
    public $isDirty;

    protected $_contentIni;
    protected $_contentInputHandler;

    public function __construct($identifier, $value, $fields = null)
    {
        $this->identifier = $identifier;
        $this->value = $value;
        $this->id = Arr::get($fields, 'id');
        $this->language = Arr::get($fields, 'language');
        $this->contentAttribute = Arr::get($fields, 'contentAttribute');
        $this->isDirty = false;
    }

    public function setValue($value)
    {
        $this->value = $value;
        $this->isDirty = true;
    }

    /**
     * Load content value from referenced content attribute.
     */
    public function loadValue($object)
    {
        if (!$this->contentAttribute) {
            throw new UnsetValueError("ContentObjectAttribute has no content-attribute set, cannot load value");
        }
        $attribute = $this->contentAttribute;
        $type = $attribute->attribute('data_type_string');

        $value = null;
        if ($type == 'ezboolean') {
            $value = $attribute->attribute("data_int");
            if ($value !== null) {
                $value = (bool)$value;
            }
        } else if ($type == 'eztext' || $type == 'ezstring') {
            $value = $attribute->attribute("data_text");
        } else if ($type == 'ezxmltext') {
            $value = $attribute->attribute("data_text");
        } else if ($type == 'ezfloat') {
            $value = $attribute->attribute("data_float");
            $value = $value ? (float)$value : null;
        } else if ($type == 'ezinteger') {
            $value = $attribute->attribute("data_int");
            $value = $value ? (int)$value : null;
        } else if ($type == 'ezurl') {
            $content = $attribute->content();
            if ($content) {
                if (is_object($content)) {
                    $value = array(
                        'url' => $content->attribute('url'),
                        'text' => $attribute->attribute('data_text'),
                    );
                } else {
                    $value = array(
                        'url' => $content,
                    );
                }
            }
        } else if ($type == 'ezselection') {
            // ezselection returns an array with ids
            $value = $attribute->content();
        } else if ($type == 'ezdate' || $type == 'ezdatetime') {
            $value = $attribute->toString();
            if ($value) {
                $value = new \DateTime('@' . $value);
            }
        } else if ($type == 'ezauthor' || $type == 'ezbinaryfile' ||  $type == 'ezimage' ||
                   $type == 'ezcountry' || $type == 'ezemail' || $type == 'ezidentifier' ||
                   $type == 'ezkeyword' || $type == 'ezobjectrelation' ||
                   $type == 'ezobjectrelationlist' || $type == 'ezprice' || $type === 'ezuser' ||
                   $type == 'eztags') {
            // Datatypes that only need to use content():
            // ezauthor -> eZAuthor
            // ezbinaryfile -> eZBinaryFile
            // ezimage -> eZImageAliasHandler
            // ezcountry -> array(array)
            // ezemail -> string
            // ezidentifier -> string
            // ezkeyword -> eZKeyword
            // ezobjecrelation -> eZContentObject
            // ezobjectrelationlist -> array(array)
            // ezprice -> eZPrice
            // ezuser -> eZUser

            // non-standard datatypes which also used content():
            // eztags -> eZTags
            $value = $attribute->content();
        } else {
            // Other unsupported data-types use content() as a fallback
            // to get a value that can be read/used.

            // TODO: Decide if rarely used datatypes should be supported:
            // ezenum, ezinisetting, ezisbn, ezmatrix, ezmedia, ezmultioption, ezmultioption2, ezmultiprice, ezoption,
            // ezpackage, ezrangeoption, ezproductcategory, ezsubtreesubscription
            \eZDebug::writeWarning("ContentTypeAttribute::loadValue: Unsupported data-type $type, falling back to loading value from content()");
            $value = $attribute->content();
        }
        $this->value = $value;
    }

    /**
     * Load content value from referenced content attribute.
     */
    public function attributeFields($object)
    {
        // loadValue() takes care of extracting data from the attribute into the $this->value property.
        $this->loadValue($object);
        $attribute = $this->contentAttribute;
        $type = $attribute->attribute('data_type_string');

        if ($type == 'ezstring' || $type == 'ezboolean' || $type == 'eztext' ||
            $type == 'ezinteger' || $type == 'ezurl' || $type == 'ezemail' || $type == 'ezfloat') {
            return $this->value;
        } else if ($type == 'ezbinaryfile') {
            return $this->exportBinaryFileType($attribute, $object);
        } else if ($type == 'ezimage') {
            return $this->exportImageType($attribute, $object);
        } else if ($type == 'ezauthor') {
            $authors = $this->value;
            if (!$authors) {
                return null;
            }
            $value = array();
            foreach ($authors->attribute('author_list') as $author) {
                $value[] = array(
                    'name' => $author['name'],
                    'email' => $author['email'],
                );
            }
            return $value;
        } else if ($type == 'ezcountry') {
            if (!is_array($this->value)) {
                return null;
            }
            $values = array();
            foreach ($this->value['value'] as $country) {
                $values[] = array(
                    'identifier' => $country['Alpha2'],
                    'name' => $country['Name'],
                );
            }
            return $values;
        } else if ($type == 'ezdate') {
            return $this->value ? $this->value->format("Y-m-d") : null;
        } else if ($type == 'ezdatetime') {
            return $this->value ? $this->value->format(\DateTime::RFC3339) : null;
        } else if ($type == 'ezidentifier') {
            return $this->value ? $this->value : null;
        } else if ($type == 'ezkeyword') {
            $values = array();
            foreach ($this->value->keywordArray() as $keyword) {
                $values[] = $keyword;
            }
            return $values;
        } else if ($type == 'ezobjectrelation') {
            return !$this->value ? null : array(
                'object_id' => $this->value->attribute('id'),
                'object_uuid' => $this->value->remoteId(),
                'name' => $this->value->name(),
                'status' => ContentObject::statusToIdentifier($this->value->attribute('status')),
            );
        } else if ($type == 'ezobjectrelationlist') {
            if (!$this->value) {
                return null;
            }
            $values = array();
            usort($this->value['relation_list'], function ($a, $b) {
                return $a['priority'] > $b['priority'] ? 1 : ($a['priority'] < $b['priority'] ? -1 : 0);
            });
            foreach ($this->value['relation_list'] as $item) {
                if ($item['in_trash']) {
                    continue;
                }
                $relatedObject = \eZContentObject::fetch($item['contentobject_id']);
                if (!$relatedObject) {
                    continue;
                }
                $values[] = array(
                    'object_id' => $relatedObject->attribute('id'),
                    'object_uuid' => $relatedObject->remoteId(),
                    'name' => $relatedObject->name(),
                    'status' => ContentObject::statusToIdentifier($relatedObject->attribute('status')),
                );
            }
            return $values;
        } else if ($type == 'ezprice') {
            if (!$this->value) {
                return null;
            }
            $data = array(
                'amount' => (string)$this->value->attribute('price'),
                'is_vat_included' => (bool)$this->value->attribute('is_vat_included'),
            );
            $vat = $this->value->attribute('selected_vat_type');
            if ($vat) {
                $data['vat'] = array(
                    'id' => $vat->attribute('id'),
                    'name' => $vat->attribute('name'),
                    'percentage' => $vat->attribute('percentage'),
                );
            }
            return $data;
        } else if ($type == 'ezxmltext') {
            return $this->exportXmlTextType($attribute, $object);
        } else if ($type == 'ezselection') {
            // When nothing is selected it may still contain array(""), avoid sending that
            if (!$this->value || (is_array($this->value) && array_slice($this->value, 0, 1)[0] === "")) {
                return null;
            }
            return array(
                'selection' => $this->value,
            );
        } else if ($type === 'ezuser') {
            if (!$this->value) {
                return null;
            }
            $hashType = \eZUser::passwordHashTypeName($this->value->attribute('password_hash_type'));
            $value = array(
                'login' => $this->value->attribute('login'),
                'email' => $this->value->attribute('email'),
                'password_hash' => $hashType . '$' . $this->value->attribute('password_hash'),
            );
            $userSetting = \eZUserSetting::fetch(
                $this->value->attribute( 'contentobject_id' )
            );
            if ($userSetting) {
                $value['is_enabled'] = (bool)$userSetting->attribute('is_enabled');
            }
            return $value;
        } else if ($type == 'eztags') {
            if (!$this->value) {
                return null;
            }
            $values = array();
            foreach ($this->value->tags() as $tag) {
                $tagValue = array(
                    'id' => (int)$tag->attribute('id'),
                    'uuid' => $tag->attribute('remote_id'),
                    'keyword' => $tag->attribute('keyword'),
                );
                $values[] = $tagValue;
            }
            return $values;
        }

        if ($this->value === null) {
            return null;
        } else if (is_integer($this->value) || is_bool($this->value) || is_string($this->value)) {
            // If value is a scalar value it can exported as-is, if not we need to call toString()
            return array(
                'content' => $this->value,
            );
        } else {
            \eZDebug::writeWarning("ContentTypeAttribute::attributeFeilds: Unsupported data-type $type, falling back to export using toString()");
            return array(
                'content' => $attribute->toString(),
            );
        }
    }

    public function update($object)
    {
        if (!$this->identifier) {
            throw new UnsetValueError("ContentObjectAttribute has no identifier set, cannot update");
        }
        if ($this->contentAttribute) {
            $attribute = $this->contentAttribute;
        } else {
            $dataMap = $object->attributeMap();
            if (!isset($dataMap[$this->identifier])) {
                throw new AttributeError("Object with ID '{$object->contentObject->ID}' does not have an attribute with identifier '{$this->identifier}'");
            }
            $attribute = $dataMap[$this->identifier];
        }
        if (!$this->isDirty) {
            return false;
        }

        $type = $attribute->attribute('data_type_string');
        $dataType = $attribute->dataType();
        $value = $this->value;

        // Updating the content for an object attribute requires either setting
        // the fields directly (e.g. data_int or data_text) or passing value as
        // a string to be decoded with fromString(), or a mixture.
        // The exact behaviour depends on the data-type as there is no API for
        // setting the content of an attribute and the behaviour varies so much.
        //
        // If the content can be import as a string set $asString to true and
        // make sure $value contains the string value.
        //
        // The default for unknown data-types is to try and import as a string
        $asString = false;
        // If true then $value is set with setContent(), false then it consideres $asString
        $asContent = false;
        if ($type === 'ezxmltext') {
            $this->updateXmlTextType($attribute, $value, $object);
        } else if ($type === 'ezselection' && is_int($value)) {
            $attribute->setAttribute('data_text', $value);
        } else if ($type === 'ezimage') {
            $this->updateImageType($object, $attribute, $value);
        } else if ($type === 'ezbinaryfile') {
            $this->updateBinaryFileType($object, $attribute, $value);
        } else if ($type === 'ezuser' ) {
            if (is_array($value)) {
                if (isset($value['login']) && isset($value['email'])) {
                    $userData = array($value['login'], $value['email']);
                    if (isset($value['password_hash'])) {
                        $hashValues = explode('$', $value['password_hash'], 2);
                        if (count($hashValues) != 2) {
                            throw new ValueError("'password_hash' entry is of unsupported format: " . var_export($value['password_hash'], true));
                        }
                        $hashType = \eZUser::passwordHashTypeID($hashValues[0]);
                        if ($hashType === null) {
                            throw new ValueError("'password_hash' entry has unsupported hash-type '${hashValues[0]}'");
                        }
                        $userData[] = $hashValues[1];
                        $userData[] = $hashType;
                    } else {
                        $userData[] = '';
                        $userData[] = '0';
                    }
                    if (isset($value['is_enabled'])) {
                        $userData[] = (int)(bool)$value['is_enabled'];
                    }
                    $value = implode("|", $userData);
                    $origValue = $attribute->toString();
                    if ($origValue == $value) {
                        // Same values, don't do anything
                        return;
                    }

                    $objectId = $attribute->attribute('contentobject_id');
                    $user = \eZUser::fetch($objectId);
                    // ezuser type does not like inserting the same email as it already has using fromString
                    // If the eZUser already exists then update the object directly.
                    if ($user) {
                        $user->setAttribute('login', $userData[0]);
                        $user->setAttribute('email', $userData[1]);
                        if (isset($userData[2])) {
                            $user->setAttribute('password_hash', $userData[2]);
                        }
                        if (isset($userData[3])) {
                            $user->setAttribute('password_hash_type', \eZUser::passwordHashTypeID($userData[3]));
                        }
                        if (isset($userData[4])) {
                            $userSetting = \eZUserSetting::fetch($objectId);
                            $userSetting->setAttribute("is_enabled", (int)(bool)$userData[4]);
                            $userSetting->store();
                        }
                        $user->store();
                        // Data has been stored, skip content/string import
                    } else {
                        // Use fromString() to setup eZUser object
                        $asString = true;
                    }
                } else {
                    throw new ValueError("Array passed to ezuser attribute '" . $attribute->attribute('identifier') . "' does not have a login and email set");
                }
            } else if (is_string($value)) {
                $asString = true;
            } else if ($value) {
                throw new TypeError("Value passed to ezuser attribute '" . $attribute->attribute('identifier') . "' is not supported: " . var_export($value, true));
            }
        } else if ($type === 'ezobjectrelation') {
            if (is_array($value)) {
                if (isset($value['object_uuid'])) {
                    $object = \eZContentObject::fetchByRemoteID($value['object_uuid']);
                    if (!$object) {
                        throw new ValueError("Cannot set ezobjectrelation content, object with UUID '" . $value['object_uuid'] . "' does not exists");
                    }
                    $value = $object->attribute('id');
                } else {
                    $value = null;
                }
            } else if ($value instanceof \eZContentObject) {
                $value = $object->attribute('id');
            } else if ($value instanceof \eZContentObjectTreeNode) {
                $value = $object->attribute('contentobject_id');
            } else if ($value === null) {
            } else {
                throw new TypeError("Unsupported value for ezobjectrelation attribute '" . $attribute->attribute('identifier') . "', value=" . var_export($value, true));
            }
            if ($value === null) {
                $attribute->setAttribute('data_int', null);
            } else {
                $asString = true;
            }
        } else if ($type === 'ezobjectrelationlist') {
            $objectIds = array();
            if (is_array($value)) {
                foreach ($value as $objectData) {
                    if (is_array($objectData)) {
                        if (isset($objectData['object_uuid'])) {
                            $object = \eZContentObject::fetchByRemoteID($objectData['object_uuid']);
                            if (!$object) {
                                throw new ValueError("Cannot set ezobjectrelation content, object with UUID '" . $objectData['object_uuid'] . "' does not exists");
                            }
                            $objectIds[] = $object->attribute('id');
                        } else if (isset($objectData['object_id'])) {
                            $object = \eZContentObject::fetch($objectData['object_id']);
                            if (!$object) {
                                throw new ValueError("Cannot set ezobjectrelation content, object with id '" . $objectData['object_id'] . "' does not exists");
                            }
                            $objectIds[] = $object->attribute('id');
                        }
                    } else if ($objectData instanceof \eZContentObject) {
                        $objectIds[] = $object->attribute('id');
                    } else if ($objectData instanceof \eZContentObjectTreeNode) {
                        $objectIds[] = $object->attribute('contentobject_id');
                    } else if ($objectData === null) {
                        continue;
                    } else {
                        throw new TypeError("Unsupported value for ezobjectrelation attribute '" . $attribute->attribute('identifier') . "', value=" . var_export($objectData, true));
                    }
                }
            } else if ($value instanceof \eZContentObject) {
                $objectIds[] = $object->attribute('id');
            } else if ($value instanceof \eZContentObjectTreeNode) {
                $objectIds[] = $object->attribute('contentobject_id');
            } else if ($value === null) {
            } else {
                throw new TypeError("Unsupported value for ezobjectrelation attribute '" . $attribute->attribute('identifier') . "', value=" . var_export($value, true));
            }
            $value = implode("-", $objectIds);
            $asString = true;
        } else if ($type === 'ezselection') {
            if (is_array($value)) {
                if (isset($value['selection'])) {
                    $selection = $value['selection'];
                } else {
                    $selection = $value;
                }
                if ($selection) {
                    $classContent = $dataType->classAttributeContent($attribute->attribute('contentclass_attribute'));
                    $optionArray = $classContent['options'];
                    $optionNames = array();
                    $optionIds = array();
                    foreach ($optionArray as $option) {
                        $optionNames[] = $option['name'];
                        $optionIds[] = $option['id'];
                    }
                    foreach ($selection as $idx => $selectId) {
                        if (is_numeric($selectId)) {
                            $found = false;
                            foreach ($optionArray as $option) {
                                if ($option['id'] == $selectId) {
                                    $selection[$idx] = $option['name'];
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                throw new ValueError("Selection '$selectId' at index $idx does not match any of the option identifiers: " . implode(", ", $optionNames));
                            }
                        } else {
                            $found = false;
                            foreach ($optionArray as $option) {
                                if ($option['name'] == $selectId) {
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                throw new ValueError("Selection '$selectId' at index $idx does not match any of the option names: " . implode(", ", $optionNames));
                            }
                        }
                    }
                    $value = implode("|", $selection);
                } else {
                    $value = '';
                }
            }
            $asString = true;
        } else if ($type === 'ezurl') {
            $fields = null;
            if (isset($value['url'])) {
                $url = $value['url'];
                $fields = array($url);
                if (isset($value['text'])) {
                    $fields[] = $value['text'];
                }
            } else if ($value) {
                $fields = array($value);
            }
            if ($fields) {
                $value = implode("|", $fields);
            } else {
                $value = '';
            }
            $asString = true;
        } else if ($type === 'ezboolean' || $type === 'eztext' || $type === 'ezinteger' ||
                   $type === 'ezfloat' || $type === 'ezemail' || $type === 'ezidentifier') {
            if ($value === null) {
                $value = '';
            }
            $asString = true;
        } else if ($type === 'ezdate' || $type === 'ezdatetime') {
            if ($value === null) {
                $value = '';
                $asString = true;
            } else if (is_object($value) && $value instanceof \DateTime) {
                if ($type === 'ezdate') {
                    $value->setTime(0, 0, 0);
                }
                $value = $value->getTimestamp();
            } else if (is_object($value) && $value instanceof \eZDate) {
                $value = $value->timeStamp();
            } else if (is_object($value) && $value instanceof \eZDateTime) {
                if ($type === 'ezdate') {
                    $value = $value->toDate();
                }
                $value = $value->timeStamp();
            } else if (is_object($value) || is_array($value)) {
                throw new TypeError("Value for ${type} must be a literal or a date object, cannot import value: " . var_export($value, true));
            }
            $asString = true;
        } else if ($type === 'ezauthor') {
            if ($value === null) {
                $value = '';
                $asString = true;
            } if (is_object($value) && $value instanceof \eZAuthor) {
                $asContent = true;
            } else if (is_array($value)) {
                $authors = new \eZAuthor();
                foreach ($value as $author) {
                    if (isset($author['name']) && isset($author['email'])) {
                        $authors->addAuthor(Arr::get($author, 'id'), $author['name'], $author['email']);
                    }
                }
                $value = $authors;
                $asContent = true;
            } else if (is_string($value)) {
                $asString = true;
            } else {
                throw new TypeError("Value for ${type} must be eZAuthor object, array with author info or a formatted string, cannot import value: " . var_export($value, true));
            }
        } else if ($type === 'ezcountry') {
            if (!$value) {
                $value = '';
                $asString = true;
            } else if (is_array($value)) {
                $identifiers = array();
                foreach ($value as $country) {
                    if (isset($country['Alpha2'])) {
                        $identifiers[] = $country['Alpha2'];
                    } else if (isset($country['identifier'])) {
                        $identifiers[] = $countr['identifier'];
                    } else {
                        continue;
                    }
                }
                $value = implode(",", $identifiers);
                $asString = true;
            } else if (is_string($value)) {
                $asString = true;
            } else {
                throw new TypeError("Value for ${type} must be array of eZCountry objects, identifier or array with 'identifier' entry, cannot import value: " . var_export($value, true));
            }
        } else if ($type === 'ezkeyword') {
            if ($value === null) {
                $value = '';
                $asString = true;
            } else if (is_array($value)) {
                $value = implode(",", $value);
                $asString = true;
            } else if (is_object($value) && $value instanceof \eZKeyword) {
                $asContent = true;
            } else {
                throw new TypeError("Value for ${type} must be eZKeyword object, array of keyword identifier, cannot import value: " . var_export($value, true));
            }
        } else if ($type === 'ezprice') {
            if (!$value) {
                $value = '';
                $asString = true;
            } else if (is_array($value) && isset($value['amount'])) {
                $amount = $value['amount'];
                $isVatIncluded = true;
                $vatId = null;
                if (isset($value['is_vat_included'])) {
                    $isVatIncluded = (bool)$value['is_vat_included'];
                }
                if (isset($value['vat']['id'])) {
                    $vatId = $value['vat']['id'];
                }
                $value = implode("|", array($amount, (string)$vatId, (int)$isVatIncluded));
                $asString = true;
            } else if (is_string($value)) {
                $asString = true;
            } else {
                throw new TypeError("Value for ${type} must be array with 'amount', 'is_vat_included' and 'vat', cannot import value: " . var_export($value, true));
            }
        } else {
            // Detect input handler for content
            $contentInputHandler = $this->contentInputHandler;
            $hasHandler = false;
            if (isset($contentInputHandler[$type])) {
                $handlerData = explode(";", $contentInputHandler[$type]);
                $handlerType = $handlerData[0];
                $hasHandler = true;
                if ($handlerType === 'string') {
                    $asString = true;
                } else if ($handlerType === 'text') {
                    $attribute->setAttribute('data_text', $value);
                } else if ($handlerType === 'int') {
                    $attribute->setAttribute('data_int', $value);
                } else if ($handlerType === 'float') {
                    $attribute->setAttribute('data_float', $value);
                } else if ($handlerType === 'ignore') {
                    $this->isDirty = false;
                    return;
                } else if ($handlerType === 'class') {
                    if (!isset($handlerData[1])) {
                        throw new ImproperlyConfigured("Input handler for $type is 'class' but no class was defined");
                    }
                    $handlerClass = $handlerData[1];
                    $handler = new $handlerClass($object, $attribute, $type, $handlerData);
                    // Let the handler store the content, string import is no longer possible
                    $handler->storeContent($value);
                } else {
                    throw new TypeError("Unsupported input handler type '$handlerType'");
                }
            }

            // No handler, try automatic detection by value type
            if (!$hasHandler) {
                if (!is_array($value) && !is_object($value)) {
                    $asString = true;
                } else {
                    throw new TypeError("Data-type '$type' is not known and the value is not a literal (string, int), cannot import value");
                }
            }
        }

        if ($asContent) {
            $attribute->setContent($value);
        } else if ($asString) {
            if ($attribute->fromString($value) === false) {
                throw new ValueError("Failed to import data using fromString(), data-type '${type}' reports false, value: " . var_export($value, true));
            }
        }
        $attribute->store();
        $this->contentAttribute = $attribute;
        $this->isDirty = false;
        return $attribute;
    }

    public function exportBinaryFileType($attribute, $object)
    {
        if (!$this->value) {
            return null;
        }
        return array(
            'original_filename' => $this->value->attribute('original_filename'),
            'path' => $this->value->filePath(),
        );
    }

    public function exportImageType($attribute, $object)
    {
        $content = $this->value;
        if (!$content) {
            return null;
        }
        $original = $content->imageAlias('original');
        $path = $original['full_path'];
        return array(
            'alternative_text' => $content->attribute('alternative_text'),
            'original_filename' => $content->attribute('original_filename'),
            'path' => $path,
        );
    }

    static public function domRenameElement(DOMElement $node, $name, $skipAttributeCopy=false) {
        $renamed = $node->ownerDocument->createElement($name);

        if (!$skipAttributeCopy) {
            foreach ($node->attributes as $attribute) {
                $renamed->setAttribute($attribute->nodeName, $attribute->nodeValue);
            }
        }

        while ($node->firstChild) {
            $renamed->appendChild($node->firstChild);
        }

        $node->parentNode->replaceChild($renamed, $node);
        return $renamed;
    }

    public function exportXmlTextType($attribute, $object) {
        // TODO: Replace link tags with url from id
        // TODO: Handle embed tags, add object as relation
        // TODO: 
        $dom = new \DOMDocument('1.0', 'utf-8');
        if (!@$dom->loadXML($this->value)) {
            return null;
        }

        $xpath = new \DOMXPath($dom);

        // Links must include the full url as it is stored, to be transferred to new site
        $links = $xpath->query('//link');
        foreach ($links as $link) {
            $urlId = $link->getAttribute('url_id');
            if ($urlId) {
                $url = \eZURL::fetch($urlId);
                $link->setAttribute('href', $url->URL);
            } else {
                // TODO: Write warning/error
            }
        }

        // Embedded objects must include references to uuid and optionally added to exported items
        $embedObjects = array();
        $embeds = $xpath->query('//embed');
        foreach ($embeds as $embed) {
            $objectId = $embed->getAttribute('object_id');
            if (!$objectId) {
                continue;
            }
            $embedObject = \eZContentObject::fetch($objectId);
            if (!$embedObject) {
                continue;
            }
            $identifier = $embedObject->attribute('class_identifier');
            $uuid = $embedObject->remoteId();
            $embed->setAttribute('uuid', $uuid);
            $embed->setAttribute('class_identifier', $identifier);
            $embed->setAttribute('name', $embedObject->name());
            $embed->setAttribute('status', ContentObject::statusToIdentifier($embedObject->attribute('status')));
            $embedObjects[] = $embedObject;
        }

        $xml = $dom->saveXML();

        return array(
            // reference_objects are to be used by exporter, and will be removed before export
            'referenced_objects' => $embedObjects,
            'xml' => $xml,
        );
    }

    /**
     * Updates the attribute containing an ezimage datatype with the given
     * value. The value can be one of:
     *
     * - array - Must contain 'path', may contain 'alternative_text' and 'original_filename'
     * - ImageFile - Contains the path to the image file locally, with additional meta data
     * - BinaryFile - Contains the path to the image file locally
     * - HttpFile - Contains the identifier of the POST variable containing
     *              the uploaded file. Is transferred to the attribute.
     *
     * @note This does not store the attribute content to the database.
     * @throws ValueError If $value is not one of the supported types above.
     */
    public function updateImageType(ContentObject $object, \eZContentObjectAttribute $attribute, $value)
    {
        if ($value === null) {
            return;
        }
        if (is_array($value)) {
            if (isset($value['path'])) {
                $value = new ImageFile(
                    $value['path'],
                    isset($value['alternative_text']) ? $value['alternative_text'] : null,
                    isset($value['original_filename']) ? $value['original_filename'] : null
                );
            }
        }

        if ($value instanceof ImageFile) {
            $path = $value->path;
            if (!file_exists($path)) {
                throw new FileDoesNotExist("The image file $path does not exist, cannot import image file to '" . $attribute->attribute('identifier') . "'");
            }
            $contentObject = $object->contentObject;
            $contentVersionId = $contentObject->attribute("current_version");
            if (!$attribute->insertRegularFile($contentObject, $contentVersionId, $this->language, $path, $result)) {
                throw new ContentError("Failed to import file $path into ezimage attribute '" . $attribute->attribute('identifier') . "'");
            }
            $content = $attribute->content();
            if ($value->originalFilename !== null) {
                $content->setAttribute('original_filename', $value->originalFilename);
            }
            if ($value->alternativeText !== null) {
                $content->setAttribute('alternative_text', $value->alternativeText);
            }
            $attribute->setContent($content);
        } else if ($value instanceof BinaryFile) {
            $path = $value->path;
            if (!file_exists($path)) {
                throw new FileDoesNotExist("The image file $path does not exist, cannot import image file to '" . $attribute->attribute('identifier') . "'");
            }
            $contentObject = $object->contentObject;
            $contentVersionId = $contentObject->attribute("current_version");
            if (!$attribute->insertRegularFile($contentObject, $contentVersionId, $this->language, $path, $result)) {
                throw new ContentError("Failed to import file $path into ezimage attribute '" . $attribute->attribute('identifier') . "'");
            }
            $content = $attribute->content();
            if ($value->originalFilename !== null) {
                $content->setAttribute('original_filename', $value->originalFilename);
            }
            $attribute->setContent($content);
        } else if ($value instanceof \eZHTTPFile) {
            $contentObject = $object->contentObject;
            $contentVersionId = $contentObject->attribute("current_version");
            $mimeData = eZMimeType::findByFileContents($value->attribute("original_filename"));
            if (!$attribute->insertHTTPFile($contentObject, $contentVersionId, $this->language, $attribute, $value, $mimeData, $result)) {
                throw new ContentError("Failed to import HTTP file into ezbinaryfile attribute '" . $attribute->attribute('identifier') . "'");
            }
        } else if ($value instanceof HttpFile) {
            $content = $attribute->attribute('content');
            if ($value->hasFile && $value->isValid) {
                $httpFile = \eZHTTPFile::fetch($value->name);
                if ($httpFile && $content) {
                    $content->setHTTPFile($httpFile);
                }
            }
        } else {
            throw new ValueError("Cannot update attribute data for '{$this->identifier}', unsupported content value: " . var_export($value, true));
        }
    }

    /**
     * Updates the attribute containing an ezbinaryfile datatype with the given
     * value. The value can be one of:
     *
     * - array - Must contain 'path', may contain 'original_filename'
     * - ImageFile - Contains the path to the image file locally, with additional meta data
     * - BinaryFile - Contains the path to the file locally
     * - HttpFile - Contains the identifier of the POST variable containing
     *              the uploaded file. Is transferred to the attribute.
     *
     * @note This does not store the attribute content to the database.
     * @throws ValueError If $value is not one of the supported types above.
     */
    public function updateBinaryFileType(ContentObject $object, \eZContentObjectAttribute $attribute, $value)
    {
        if ($value === null) {
            return;
        }
        if (is_array($value)) {
            if (isset($value['path'])) {
                $value = new ImageFile(
                    $value['path'],
                    isset($value['original_filename']) ? $value['original_filename'] : null
                );
            }
        }

        if ($value instanceof ImageFile || $value instanceof BinaryFile) {
            $path = $value->path;
            if (!file_exists($path)) {
                throw new FileDoesNotExist("The binary file $path does not exist, cannot import image file to '" . $attribute->attribute('identifier') . "'");
            }
            $contentObject = $object->contentObject;
            $contentVersionId = $contentObject->attribute("current_version");
            if (!$attribute->insertRegularFile($contentObject, $contentVersionId, $this->language, $path, $result)) {
                throw new ContentError("Failed to import file $path into ezbinaryfile attribute '" . $attribute->attribute('identifier') . "'");
            }
            $content = $attribute->content();
            if ($value->originalFilename !== null) {
                $content->setAttribute('original_filename', $value->originalFilename);
            }
            $attribute->setContent($content);
        } else if ($value instanceof \eZHTTPFile) {
            $contentObject = $object->contentObject;
            $contentVersionId = $contentObject->attribute("current_version");
            $mimeData = eZMimeType::findByFileContents($value->attribute("original_filename"));
            if (!$attribute->insertHTTPFile($contentObject, $contentVersionId, $this->language, $attribute, $value, $mimeData, $result)) {
                throw new ContentError("Failed to import HTTP file into ezbinaryfile attribute '" . $attribute->attribute('identifier') . "'");
            }
        } else if ($value instanceof HttpFile) {
            $content = $attribute->attribute('content');
            if ($value->hasFile && $value->isValid) {
                $httpFile = \eZHTTPFile::fetch($value->name);
                if ($httpFile && $content) {
                    $content->setHTTPFile($httpFile);
                }
            }
        } else {
            throw new ValueError("Cannot update attribute data for '{$this->identifier}', unsupported content value: " . var_export($value, true));
        }
    }

    /**
     * Updates the attribute containing an ezxmltext datatype with the given
     * value. The value can be one of:
     *
     * - HtmlText - The HTML content is parsed and turned into RawXmlText.
     * - RawXmlText - The xml text is set directly in the attribute and
     *                and any relations/links updated.
     *
     * @note This does not store the attribute content to the database.
     * @throws ValueError If $value is not one of the supported types above.
     */
    public function updateXmlTextType($attribute, $value, $object)
    {
        if ($value === null) {
            return;
        }

        // Array support, must contain an 'xml' entry
        if (is_array($value) && isset($value['xml'])) {
            $value = new RawXmlText($value['xml']);
        }

        // If we have HTML content convert it to XML text first
        if ($value instanceof HtmlText) {
            $value = $this->parseHtmlToXml($value->text, $object);
        }

        if ($value instanceof RawXmlText) {
            // Update links/relations first
            if ($value->urlObjectLinks) {
                $this->updateUrlObjectLinks($attribute, $value->urlObjectLinks);
            }

            if ($value->relatedObjects || $value->linkedObjects) {
                $contentObject = $attribute->attribute('object');
                if ($value->relatedObjects) {
                    $contentObject->appendInputRelationList($value->relatedObjects, \eZContentObject::RELATION_EMBED);
                }
                if ($value->linkedObjects) {
                    $contentObject->appendInputRelationList($value->linkedObjects, \eZContentObject::RELATION_LINK);
                }
            }

            // Then store the xml text
            $xml = $value->rawText;
            $xml = $this->updateXmlContent($xml);
            $attribute->setAttribute('data_text', $xml);
        } else {
            throw new ValueError("Cannot update attribute data for '{$this->identifier}', unsupported content value: " . var_export($value, true));
        }
    }

    /**
     * Updates the XML content for ezxmltext by doing the following:
     * 
     * For link entries it replaces the href with a link_id by storing the referenced
     * url in eZURL.
     * 
     * For embed entries it find the referenced object by uuid and stores the
     * object_id.
     * 
     * Returns the transformed xml as text.
     * 
     * @return string
     */
    protected function updateXmlContent($xml)
    {
        $dom = new \DOMDocument('1.0', 'utf-8');
        if (!@$dom->loadXML($xml)) {
            return $xml;
        }
        $xpath = new \DOMXPath($dom);

        // Links must transform href into url objects with ID stored in xml
        $links = $xpath->query('//link');
        foreach ($links as $link) {
            $url = $link->getAttribute('href');
            if (!$url) {
                continue;
            }
            $urlId = \eZURL::registerURL($url);
            // url_id
            if ($urlId) {
                $link->setAttribute('url_id', $urlId);
                $link->removeAttribute('href');
            }
        }

        // Embedded objects with uuid must transformed to use object_id
        $embedObjects = array();
        $embeds = $xpath->query('//embed');
        foreach ($embeds as $embed) {
            $embedUuid = $embed->getAttribute('uuid');
            if (!$embedUuid) {
                // If there is no uuid leave it as-is
                continue;
            }
            $embedObject = \eZContentObject::fetchByRemoteID($embedUuid);
            if (!$embedObject) {
                echo "XML: Embedded object with UUID ${embedUuid} does not exist\n";
                $parentNode = $embed->parentNode;
                $parentNode->removeChild($embed);
                continue;
            }
            $embed->setAttribute('object_id', $embedObject->attribute('id'));
            $embed->removeAttribute('uuid');
        }

        $xml = $dom->saveXML();
        return $xml;
    }

    /**
     * Updates url object links related to a content attribute.
     *
     * @param $attribute The content attribute to create links for.
     * @param $urlObjectLinks Array of URL ids
     */
    public function updateUrlObjectLinks($attribute, $urlObjectLinks)
    {
        $objectAttributeID = $attribute->attribute('id');
        $objectAttributeVersion = $attribute->attribute('version');

        foreach ($urlObjectLinks as $urlID) {
            $linkObjectLink = \eZURLObjectLink::fetch($urlID, $objectAttributeID, $objectAttributeVersion);
            if ($linkObjectLink == null) {
                $linkObjectLink = \eZURLObjectLink::create($urlID, $objectAttributeID, $objectAttributeVersion);
                $linkObjectLink->store();
            }
        }
    }

    /**
     * Parses the HTML content $text and turns it into RawXmlText.
     * Any links or relations are also passed in the RawXmlText object.
     *
     * This is similar to the parsing done by the ezxmltext datatype
     * but stores the result in a separate xml container than the
     * attribute itself.
     *
     * @throw HtmlError If it fails to parse the HTML.
     */
    public function parseHtmlToXml($text, $object)
    {
        $contentObjectID = $object->contentObject->attribute('id');
        $text = preg_replace('/\r/', '', $text);
        $text = preg_replace('/\t/', ' ', $text);

        // first empty paragraph
        $text = preg_replace('/^\n/', '<p></p>', $text);

        $parser = new \eZSimplifiedXMLInputParser($contentObjectID, true, \eZXMLInputParser::ERROR_ALL, true);
        $document = $parser->process($text);

        if (!is_object($document)) {
            $errorMessage = implode(' ', $parser->getMessages());
            throw new HtmlError("Failed parsing HTML to XML: $errorMessage");
        }

        $xmlString = \eZXMLTextType::domString($document);
        $urlObjectLinks = $parser->getUrlIDArray();

        return new RawXmlText($xmlString, array(
            'urlObjectLinks' => $urlObjectLinks,
            'relatedObjects' => $parser->getRelatedObjectIDArray(),
            'linkedObjects' => $parser->getLinkedObjectIDArray(),
        ));
    }

    public function __exists($name)
    {
        return $name === 'contentIni' || $name === 'contentInputHandler';
    }

    public function __get($name)
    {
        if ($name === 'contentIni') {
            if ($this->_contentIni === null) {
                $this->_contentIni = \eZINI::instance('content.ini');
            }
            return $this->_contentIni;
        } else if ($name === 'contentInputHandler') {
            if ($this->_contentInputHandler === null) {
                $contentIni = $this->contentIni;
                if ($contentIni->hasVariable('DataTypeSettings', 'ContentInputHandler')) {
                    $this->_contentInputHandler = $contentIni->variable('DataTypeSettings', 'ContentInputHandler');
                } else {
                    $this->_contentInputHandler = array();
                }
            }
            return $this->_contentInputHandler;
        } else {
            throw new AttributeError("Unknown attribute $name on ContentObjectAttribute instance");
        }
    }
}
