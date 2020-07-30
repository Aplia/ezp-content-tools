<?php
namespace Aplia\Content\AttributeHelpers;

use Aplia\Content\ContentType;
use Aplia\Content\Exceptions\TypeError;

/**
 * Helper class for working with `ezselection` data types.
 *
 * Makes it easy to add or remove selections entries.
 */
class SelectionType
{
    /**
     * Add or update ezselection entry using ID and name.
     * If the ID already exists it changes the name, otherwise it is added as a new entry.
     * If the ID is null it creates a new unique ID.
     *
     * @param ContentType $contentType The content-type object
     * @param string $identifier Identifier for content class attribute
     * @param int|null $id The unique ID of the selection
     * @param string $name Name of selection
     * @return void 
     */
    public static function set(ContentType $contentType, string $identifier, $id, string $name)
    {
        $attribute = $contentType->getAttribute($identifier);
        if ($attribute->type !== 'ezselection') {
            throw new TypeError("The content class attribute {$attribute->identifier} is not an ezselection, got: {$attribute->type}");
        }
        $value = $attribute->value;
        if (!$value) {
            $value = array('options');
        }
        $found = false;
        if ($id !== null) {
            foreach ($value['options'] as $idx => $selection) {
                if ($selection['id'] == $id) {
                    $value['options'][$idx]['name'] = $name;
                    $found = true;
                    break;
                }
            }
        } else {
            $ids = array();
            foreach ($value['options'] as $idx => $selection) {
                $ids = $selection['id'];
            }
            $id = ($ids ? max($ids) : 0) + 1;
        }
        if (!$found) {
            $value['options'][] = array('id' => (string)$id, 'name' => $name);
            $value['options'] = array_values($value['options']);
        }
        $attribute->value = $value;
        // Write back the attribute, this will add it to the change-list
        $contentType->setAttribute($identifier, $attribute);
    }

    /**
     * Remove ezselection entry using ID or name.
     * If the ID is null it uses the name to locate the entry.
     * If there is no match the attribute is left as-is.
     *
     * @param ContentType $contentType The content-type object
     * @param string $identifier Identifier for content class attribute
     * @param int|null $id The unique ID of the selection to remove or null to use name matching
     * @param string $name Name of selection to remove
     * @return void 
     */
    public static function unset(ContentType $contentType, string $identifier, $id=null, string $name=null)
    {
        $attribute = $contentType->getAttribute($identifier);
        if ($attribute->type !== 'ezselection') {
            throw new TypeError("The content class attribute {$attribute->identifier} is not an ezselection, got: {$attribute->type}");
        }
        if ($id === null && $name === null) {
            throw new TypeError("Cannot unset ezselection entry without id or name");
        }
        $value = $attribute->value;
        if (!$value) {
            $value = array('options');
        }
        $found = false;
        if ($id !== null) {
            foreach ($value['options'] as $idx => $selection) {
                if ($selection['id'] == $id) {
                    unset($value['options'][$idx]);
                    $found = true;
                }
            }
        } else {
            foreach ($value['options'] as $idx => $selection) {
                if ($selection['name'] == $name) {
                    unset($value['options'][$idx]);
                    $found = true;
                }
            }
        }
        if (!$found) {
            return;
        }
        $value['options'] = array_values($value['options']);
        $attribute->value = $value;
        // Write back the attribute, this will add it to the change-list
        $contentType->setAttribute($identifier, $attribute);
    }
}
