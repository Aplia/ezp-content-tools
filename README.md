# eZ publish support classes for content objects

This packages contains classes which makes it easier to work with
content objects when it comes to creating and updating content.

Whenever something is missing or an action fails it will throw
a proper Exception, see the namespace `Aplia\Content\Exceptions'
for a list of possible errors.

## ContentType

Wrapper around eZContentClass which defines a new class with
chosen attributes.

Example, creating a new content class:

```
<?php
// Define the class, does not modify the database
$type = new Aplia\Content\ContentType('folder', 'Folder', array(
    'groups' => array('Content'),
    'isContainer' => true,
    'contentObjectName' => '<title>',
    'alwaysAvailable' => false,
    'description' => 'Container for other content objects',
))
->addAttribute('ezstring', 'title', 'Title');

// Create the content-class in the database
$type->create();

```

Each call to `addAttribute` creates a new `ContentTypeAttribute`
instance which will be created in eZ publish when
`ContentType::create` is called.
An attribute mainly consists of the data-type, identifier and name.
In addition a fourth parameter may be set which is an array with
additional named parameters.

The following are common for all attributes.

- `isRequired` - Whether the attribute is required or not, defaults to false.
- `isSearchable` - Whether the attribute will be registered to the search index, defaults to true.
- `canTranslate` - Whether the attribute is allowed to be translated, defaults to true but also depends on the data-type in use.
- `isInformationCollector` - Whether the attribute is an information collector, defaults to false.
- `value` - Addtional value to send to the data-type, this is an associative array and the content depends on the data-type.
- `language` - The language of the attribute, defaults to the same language as the `ContentType` it belongs to.
- `description` - Description text for the attribute.

## Data-Types

While it is is possible to create an attribute with any data-type
not all of them are supported when it comes to setting additional
class properties. All supported types are set by supplying the
`value` parameter to `addAttribute`, the value is always an array
with entries specific to the datatype.
e.g. setting max 50 characters for an ezstring attribute.

```
<?php
$type->addAttribute('ezstring', 'title', 'Title', array(
    'value' => array(
        'max' => 50,
    )
))
```

### ezstring

The maxium length can be set with the `max` parameter in `value`,
it defaults to 0 (no limit).

The default value can be set with the `default` parameter in `value`,
it defaults to '' (empty string).

```
<?php
$type->addAttribute('ezstring', 'title', 'Title', array(
    'value' => array(
        'max' => 50,
        'default' => 'Perfect Circle',
    )
))
```

### ezboolean

The default value can be set with the `default` parameter in `value`,
it defaults to 0 (unchecked).

```
<?php
$type->addAttribute('ezboolean', 'has_size', 'Has size?', array(
    'value' => array(
        'default' => true,
    )
))
```

### ezinteger

The minimum value can be set with the `min` parameter in `value`,
it defaults to null (no limit).

The maximum value can be set with the `max` parameter in `value`,
it defaults to null (no limit).

The default value can be set with the `default` parameter in `value`,
it defaults to null.

```
<?php
$type->addAttribute('ezinteger', 'size', 'Size', array(
    'value' => array(
        'min' => 5,
        'max' => 10,
        'default' => 7,
    )
))
```

### eztext

The column size can be set with the `columns` parameter in `value`,
it defaults to 10.

```
<?php
$type->addAttribute('eztext', 'body', 'Body', array(
    'value' => array(
        'columns' => 15,
    )
))
```

### ezxmltext

The column size can be set with the `columns` parameter in `value`,
it defaults to 10.

The tag preset can be set with the `tag_preset` parameter in `value`.

```
<?php
$type->addAttribute('ezxmltext', 'body', 'Body', array(
    'value' => array(
        'columns' => 15,
        'tag_preset' => 'whatever this is',
    )
))
```

### ezimage

The max file size (in MB) can be set with the `max_file_size` parameter in `value`,
it defaults to 0 (no limit).

```
<?php
$type->addAttribute('ezimage', 'image', 'Image', array(
    'value' => array(
        'max_file_size' => 10, // 10MB
    )
))
```

### ezobjectrelation

The selection type can be set with the `selection_type` parameter in `value`.
A value of `0` means to browse for relation, `1` means dropdown.

The default selection node can be set with the `default_selection_node` parameter
in `value`.

The fuzzy match can be turned on or off with the `fuzzy_match` parameter in `value`.

```
<?php
$type->addAttribute('ezimage', 'image', 'Image', array(
    'value' => array(
        'selection_type' => 1,
        'default_selection_node' => 2,
        'fuzzy_match' => false
    )
))
```

### ezobjectrelation

The selection type can be set with the `selection_type` parameter in `value`.

The default selection node can be set with the `default_selection_node` parameter
in `value`.

The fuzzy match can be turned on or off with the `fuzzy_match` parameter in `value`.

```
<?php
$type->addAttribute('ezimage', 'image', 'Image', array(
    'value' => array(
        'selection_type' => 1,
        'default_selection_node' => 2,
        'fuzzy_match' => false
    )
))
```

### ezurl

The default value can be set with the `default` parameter in `value`,
it defaults to '' (empty string).

```
<?php
$type->addAttribute('ezurl', 'link', 'Link', array(
    'value' => array(
        'default' => 'http://example.com',
    )
))
```

## ContentObject

Wrapper around eZContentObject which defines a content object
with chosen attributes. First create a `ContentType` instance
either with an identifier when using an existing content-class
or `create()` new content-class. Then use that type instance
to instantiate a new object instance.

Example, instantiating a content class:

```php
<?php
// Create an object wrapper which is filled with data
$type = new Aplia\Content\ContentType('folder');
$folder = $type->contentObject(array(
    'locations' => array(array('parent_id' => 2)),
))
->setAttribute('title', 'New folder');

// Create the object in the database and publish it
$folder->create(true);
```
