# eZ publish support classes for content objects

This packages contains classes which makes it easier to work with
content objects when it comes to creating and updating content.

Whenever something is missing or an action fails it will throw
a proper Exception, see the namespace `Aplia\Content\Exceptions`
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
    'sortBy' => 'name',
))
->addAttribute('ezstring', 'title', 'Title');

// Create the content-class in the database
$type->create();
```

`sortBy` is a special property which should contain a string
with the identifier of the field to sort-by as specified by
`eZContentObjectTreeNode`, to reverse sorting prepend a dash.
e.g. `name` and `-name`.

Each call to `addAttribute` creates a new `ContentTypeAttribute`
instance which will be created in eZ publish when
`create()`, `update()` or `save()` is called.
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
- `placeAfter` - Place attribute after the existing attribute, specified using the identifier
- `placeBefore` - Place attribute before the existing attribute, specified using the identifier

Similarly attributes may be removed with `removeAttribute` and supplying the identifier.
This schedules the attribute to be removed on the next call to `save()`.

There are also methods for checking for attribute existance and fetching attribute object. (`hasAttribute()`
and `classAttribute()`)

To remove a class use `remove()`, just check if it `exists()` first.

For easy access to content-class and attributs use the properties `contentClass` and `attributes`, these
properties will lazy-load the values. Note they require that content-class exists.

## Groups

Class-groups can be managed using `ContentType`. To create or delete groups use `ContentType::createGroup`
and `ContentType::deleteGroup`.

For instance:
```
ContentType::createGroup('Sections');
```

Assigning a class to a group is done using `addToGroup()`, `removeFromGroup()` and `setGroups()`. These
schedules changes which are written on the next sync. If the group assignment is present (added/removed)
nothing changes.

For instance:
```
$type
    ->addToGroup('Sections')
    ->removeFromGroup('Content')
    ->save();
```

Group assignment may also be specified in the `set()` call by using `groups` key entry.

For instance:
```
$type
    ->set(array(
        'groups' => array('Sections'),
    ));
    ->save();
```

Groups are either specified by ID, name or a `eZContentClassGroup` object. As there is no persistent ID
for groups it is often the best to use names.

## Translations

Some fields of content-classes and their attributs may be translated, this is done by using the
`addTranslation()` and `removeTranslation()`.

The following content-class fields may be translated:
- name
- description

The following content-class attribute fields may be translated:
- name
- description
- data_text

For instance:
```
$type
    ->addTranslation(
        'nor-NO',
        array(
            'name' => 'Seksjon',
            'description' => 'Seksjonssystem',
            'attributes' => array(
                'title' => array(
                    'name' => 'Tittel',
                )
            ),
        )
    )
    ->save();
```

For direct manipulation there is also `createTranslation()` and `deleteTranslation()`.

There's currently no support for translating custom content of data-types, this could later
be solved by using a plugin system.

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

## Data-Types and object attributes

Most attribute content is set by supplying the `$content` parameter to `setAttribute`,
this value will then be sent to the data-type as-is using the `fromString` method.
However some data-types require special content values, the following are suppored.

### ezxmltext

Content must either be imported from HTML or using the internal XML format.

To import HTML content use the `Aplia\Content\HtmlText` class, instantiate it
with the HTML text and pass it as content. The system will then take care of
converting it to the internal format. Note: ezxmltext expects the HTML to
contain `<p>` tags for the main text content, so setting simple text will
require it to be wrapped in the `<p>` tags.

To import XML content use the `Aplia\Content\RawXmlText` class, instantiate
it with the text and optionally url object links, related object IDs or
linked object IDs.

Example with HTML text:

```
<?php
$object->setAttribute('body', new Aplia\Content\HtmlText('<p>My text</p>'));
```


### ezselection

This expects the selection value to be the integer value for the key which
is selected, the first selection is `0`, the next `1` and so on.


### ezimage

Currently only supports uploaded HTTP files. Create an instance of
`Aplia\Content\HttpImage` with the name of the file entry in `$_FILES`.

For instance if the HTML form contained an `<input type="file" name="portrait_image">`
then the image file will be available in the file entry `portrait_image`.

```
<?php
$object->setAttribute('image', new Aplia\Content\HttpFile('portrait_image'));
```

## Configuration for import

The cojntent importer supports using a configuration file to define
mapping of data and defining transformation code to be run.

### Object/ParentMap

ParentMap allows for remapping the parents of imported locations to
a new parent. It maps from the original UUID to a new UUID

e.g.

```
[Object]
ParentMap[16a72100ab6e3831dd0dffb10ef22902]=15ab238880ef4e989ab15823c906c005
```

## Content input handler

Content-tools supports extending support for 3rd-party data-types
by defining input handlers. The input handlers has the responsibility
to take the input value and store it on the attribute in whatever
manner makes sense. It may also transform the input value to
the format that the data-type expects.

The handler is a class that implements the ContentInputHandler interface
and must be defined in `content.ini` under `DataTypeSettings`
and the variable `ContentInputHandler`.
For instance lets say we want to support `ezselection` then add

```
[DataTypeSettings]
ContentInputHandler[ezselection2]=class;Selection2Handler
```

The handler must then implement the `storeContent` method and
store the value on the attribute or in the database. A simple
example follows:

```
class Selection2Handler extends ContentBaseHandler implements ContentInputHandler
{
    public function storeContent($value)
    {
        $this->attribute->setAttribute('data_int', $value);
    }
}
```

If the input value is simply text, integer, float or a string-like
input then swap out `class` for the corresponding types `text`, `int`, `float` and
`string`. To ignore the input value use type `ignore`.
`string` means to pass the value to the `fromString` method on
the data-type.

```
[DataTypeSettings]
ContentInputHandler[ezselection2]=string
```


## Migration

This package has support for the Phinx system for migrations of database
content and other data. First install Phinx using composer.

```
composer require "robmorgan/phinx:^0.10.6"
```

If you get issues with `symfony/console` try adding `symfony/console:^3.0` as a requirement.

Next sphinx requires a configuration file, the easiest way is to create
the `sphinx.php` file and make use of the `Aplia\Migration\Configuration` class
to get automatic setup based on the eZ publish site.

Create the file and add:
```
<?php
require 'autoload.php';
$configuration = new \Aplia\Migration\Configuration();
return $configuration->setupPhinx();
```
and add it to git.

The configuration assumes all migrations are located in `extension/site/migrations`.
If you have an older site with a different extension name for the site the
path must be configured in `project.ini`, add the following:

```
[Migration]
Path=extension/mysite/migrations
```

Then use `vendor/bin/phinx` to handle migrations, remember to add `-c phinx.php` to
after all commands. For instance to see the current status.

```
vendor/bin/phinx status -c phinx.php
```

To create a new migration file use `create`, for instance:

```
vendor/bin/phinx create -c phinx.php Initial
```

To run migration use `migrate` command, for instance:

```
vendor/bin/phinx migrate -c phinx.php
```

To migrate eZ publish content the classes `ContentType` and `ContentObject`
may be used inside the migrations.

## Content class export
This package has support for dumping content classes by given identifiers to a runnable script. The script can be run as a migrate-style PHP snippet.

To run this script manually:
```
php bin/dump_contentclass [identifier ...] [php-style] [preamble] [delete-existing] [update-class-group] [update-creator-id]
```

Output goes to `stdout`, so pipe to a file to save it.

### Options

#### identifier
Specify which content class identifiers to export. It supports multiple values. E.g.: `folder article`.

#### php-style
Set to migrate, to use migrate style. E.g.: `php-style=migrate`

#### preamble
Set to include boilerplate script options before the class definitions. E.g.: `preamble`.

#### delete-existing
Include this to delete the existing content classes instead of updating existing.

#### update-class-group
Use this to reset the class group on all exported classes to something of your choice. E.g.: `update-class-group=Seksjoner`.

NB! Create this class group beforehand.

#### update-creator-id
Use this to set a user by object id as creator for the content classes. E.g.: `update-creator-id=14` to set creator as admin.
(This uses `eZUser::setCurrentlyLoggedInUser()`.)

### Example
Assuming location is project root:
```
php vendor/aplia/content-tools/bin/dump_contentclass preamble delete-existing update-class-group=Seksjoner update-creator-id=14 news_section campaign_section logo_section > import_content_classes.php
```