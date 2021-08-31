# Improved API for working with content objects in eZ publish legacy

This packages contains classes which makes it easier to work with
content objects when it comes to creating and updating content.
It also has functionality for migrating content using [Phinx](https://phinx.org/).

Whenever something is missing or an action fails it will throw
a proper Exception, see the namespace `Aplia\Content\Exceptions`
for a list of possible errors.

[![Latest Stable Version](https://img.shields.io/packagist/v/aplia/content-tools.svg?style=flat-square)](https://packagist.org/packages/aplia/content-tools)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%205.3-8892BF.svg?style=flat-square)](https://php.net/)

## ContentType

Wrapper around eZContentClass which defines a new class with
chosen attributes.

Example, creating a new content class:

```php
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

### ezselection2

Example

```php
<?php
$type->addAttribute('ezselection2', 'selection2', 'Selection 2', array(
    'value' => array(
        'delimiter' => '',
        'is_checkbox' => true,
        'is_multiselect' => true,
        'options' => array(
            ['identifier' => 'first', 'name' => 'First', 'is_selected' => false],
            ['identifier' => 'second', 'name' => 'Second', 'is_selected' => true],
            ['identifier' => 'third', 'name' => 'Third', 'is_selected' => false],
        ),
        'use_identifier_name_pattern' => true,
    )
));
```

## Groups

Class-groups can be managed using `ContentType`. To create or delete groups use `ContentType::createGroup`
and `ContentType::deleteGroup`.

For instance:

```php
ContentType::createGroup('Sections');
```

Assigning a class to a group is done using `addToGroup()`, `removeFromGroup()` and `setGroups()`. These
schedules changes which are written on the next sync. If the group assignment is present (added/removed)
nothing changes.

For instance:

```php
$type
    ->addToGroup('Sections')
    ->removeFromGroup('Content')
    ->save();
```

Group assignment may also be specified in the `set()` call by using `groups` key entry.

For instance:

```php
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

```php
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

```php
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

```php
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

```php
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

```php
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

```php
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

```php
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

```php
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

```php
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

```php
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

```php
<?php
$object->setAttribute('body', new Aplia\Content\HtmlText('<p>My text</p>'));
```

### ezselection

This expects the selection value to be the integer value for the key which
is selected, the first selection is `0`, the next `1` and so on.

### ezselection2

Indentifiers can be specified the same way the datatype support `fromString`.

```php
<?php
$object->setAttribute('selection2', 'first|second')
```

Or as array

```php
<?php
$object->setAttribute('selection2', ['first', 'second'])
```

### ezimage

Currently only supports uploaded HTTP files. Create an instance of
`Aplia\Content\HttpImage` with the name of the file entry in `$_FILES`.

For instance if the HTML form contained an `<input type="file" name="portrait_image">`
then the image file will be available in the file entry `portrait_image`.

```php
<?php
$object->setAttribute('image', new Aplia\Content\HttpFile('portrait_image'));
```

## Content Export/Import

Content-tools has support for exporting/importing subtrees across sites, while also preserving ownership, relations, and embedded content. Remember to not have mismatching package versions of this when exporting from one project, and importing to another. It might require the use of a config file in the import, which maps content in the export to content in the destination. See section "Configuration for import" for more info regarding this.

### Content Export

Exporting content is done with `bin/dump_content`. At the time of writing, these are the current available options:

(`vendor/bin/dump_content --help`)

```console
Options:
  --format=VALUE           Type of format, choose between json, ndjson, php and line
  --preamble               Whether to include preamble when exporting php (php format only)
  --use-namespace          Whether to include ContentType namespace use statement (php format only)
  --class=VALUE            Limit search of objects to only these type of content-classes. Comma separated list
  --parent-node=VALUE      Choose starting point for export, defaults to root-node 1. Specifiy either node ID, node:<id>, object:<id> or path:<alias-path>
  --depth=VALUE            Choose how deep in the tree structure to search, defaults to 1 level. Use * for unlimited
  --exclude-parent         Exclude the parent node from the export, the result is then only the child/sub-nodes
  --only-visible           Only export visible nodes
  --file-storage=VALUE     Store file content in specified folder, instead of embedding as base64 in export.
  --include-relations      Include all objects which are related in export
  --include-embeds         Include all objects which are embedded in export
  --include-owners         Include objects for all owners
  --include-parents        Include all parents of objects
  --no-exclude-top-nodes   Turn off exclusion of top-level nodes
  --exclude-node=VALUE...  Excluded specific node from export, can be used multiple times
  --summary                Include commented summary at the end (php format only)
```

Script echoes, so pipe the result to file.

#### Examples

Here is a configuration which will export all objects of the given content_class, under the given path (container_path, corresponding to `--parent-node`-option). This export assumes the parent directory already exists on the import destination (i.e. remove `--exclude-parent` if the entire directory is to be imported).

(Values in chevrons ('<>') should be replaced with your own options.)

```console
vendor/bin/dump_content <container_path> --class=<content_class> --exclude-parent --file-storage=export_files --format=ndjson > <export_name>.ndjson;
```

When setting file storage path, this folder should be located in the same folder as the export files. For example:

```console
export_content
|-  <export_name.ndjson>
|-  export_files
    |-  file 1
    |-  file 2
```

### Content Import

Importing content is done with `bin/import_content`. At the time of writing, these are the current available options:

(`vendor/bin/import_content --help`)

```console
--parent-node=VALUE  Choose starting point for import of content objects, defaults to root-node 1. Specifiy either node ID, node:<id>, object:<id>, path:<alias-path>, or tree:<id> e.g tree:content, tree:media, tree:users, tree:top
--config=VALUE       Config file for mapping content in the export (e.g. node ids, uuids) to content in the import destination. See more about this in the section about configuration.
--temp-path=VALUE    Path to place to use for temporary files
--yes                Whether to answer yes on all questions. Use with care.
```

The import logs through stdout, so pipe to file to save log output. For example use `| tee import_log.txt`. (Piping to tee might not show interactive questions, so be sure that the script is not waiting for question response, if no update is showing.)

The script might ask whether to remove object relation, or reset ownership, if the object in question neither exists in the target database, or in the import. (Big imports might prompt for this A LOT, which is why the `--yes`-parameter was added.)

NB! The content import starts a database transaction, and does not commit before the entire import is finished. This way, in case of errors, an unsuccessful import will rollback the database transaction, so that no wrong data is committed to the database. This means that while the import is running, publishing new content on the destination will not work, and will give a database transaction error. See section "Batch Export/Import" for examples on how to make content publishing possible while importing content.

#### Examples

```console
vendor/bin/import_content --temp-path=temp --parent-node=path:kompetansetorget --config=extension/site/import/<config_file>.ini <export_file>
```

### NB! Batch Export/Import

By dividing the exported content into multiple files, we reduce the number of operations before content is committed to the database, and thereby make it possible to publish content.

(This is the only current support for this because of all the actions required for import of a given object. I.e.: 1. Creating node/object skeleton, 2. Creating object, 3. Updating the object with data. It is done this way to be able to preserve locations, relations and ownerships when importing objects which depend on other objects)

#### Examples

- Export:
Given an existing export `<export_name>`.ndjson, the following bash script will divide the export into a .head file, which will be prepended to the numbered files. It takes the first occurence of `ez_contentobject`, and splits the following on 5 lines, which corresponds to 5 content objects. PS: This can generate a lot of files.

```console
cat <export_name>.ndjson | sed -e '/ez_contentobject/,$d' > <export_name>.head; cat <export_name>.ndjson | sed -n -e '/ez_contentobject/,$p' | split -l5 - <export_name>- --additional-suffix=.tmp --numeric-suffixes=1; for f in <export_name>-*.tmp; do cat <export_name>.head "$f" > "${f%.*}".ndjson; rm "$f"; done
```

- Import:

```console
for f in <export_name>-*.ndjson; do vendor/bin/import_content --temp-path=temp --parent-node=path:<path> --config=extension/site/import/<config_file>.ini "$f" | tee import_log.txt; done
```

## Configuration for import

The content importer supports using a configuration file to define
mapping of data and defining transformation code to be run.

### Transformation classes

The import system supports custom transformation classes, these are
classes that are instantiated with the importer as the first parameter
and the ini file as second.

They should then contain methods for transform import data into new
data. The specifics are explained in subsequent sections.

### NodeMap

NodeMap allows for remapping the uuid of imported nodes to
a new uuid. It maps from the original UUID to a new UUID

e.g.

```ini
[Object]
NodeMap[16a72100ab6e3831dd0dffb10ef22902]=15ab238880ef4e989ab15823c906c005
```

This is used for nodes of objects, for parent uuids and owner uuid.
A typical use case is to relocate a parent node to a new location.

### ObjectMap

ObjectMap allows for remapping the uuid of imported objects to
a new uuid. It maps from the original UUID to a new UUID.

e.g.

```ini
[Object]
ObjectMap[c1426628bb809712ac78e8527ef93739]=15ab238880ef4e989ab15823c906c005
```

### Object transformation

Object import data may be transformed before the import system takes
care of it. This can either be done on a specific object uuid, class identifier
or for all objects.

To transform a single object specify `TransformByUuid`.

```ini
TransformByUuid[c1426628bb809712ac78e8527ef93739]=FrontpageTransformation
```

To transform objects of given class specify `TransformByClass`.

```ini
TransformByClass[user]=UserTransform
```

Transformation for all objects are specified with `Transform`

```ini
Transform[]=GenericTransform
```

All object transformation classes must implement the ContentObjectTransformation
interface.

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

```ini
[DataTypeSettings]
ContentInputHandler[ezselection2]=class;Selection2Handler
```

The handler must then implement the `storeContent` method and
store the value on the attribute or in the database. A simple
example follows:

```php
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

```ini
[DataTypeSettings]
ContentInputHandler[ezselection2]=string
```

## Migration

This package has support for the Phinx system for migrations of database
content and other data. First install Phinx using composer.

```console
composer require "robmorgan/phinx:^0.10.6"
```

If you get issues with `symfony/console` try adding `symfony/console:^3.0` as a requirement.

Next sphinx requires a configuration file, the easiest way is to create
the `sphinx.php` file and make use of the `Aplia\Migration\Configuration` class
to get automatic setup based on the eZ publish site.

Create the file and add:

```php
<?php
require 'autoload.php';
$configuration = new \Aplia\Migration\Configuration();
return $configuration->setupPhinx();
```

and add it to git.

The configuration assumes all migrations are located in `extension/site/migrations`.
If you have an older site with a different extension name for the site the
path must be configured in `project.ini`, add the following:

```ini
[Migration]
Path=extension/mysite/migrations
```

Or if you have migrated to using namespaces, add the following (multiple namespaced paths can be added if needed):

```ini
[Migration]
NamespacedPaths[Namespace\Path]=extension/mysite/migrations
```

Then use `vendor/bin/phinx` to handle migrations, remember to add `-c phinx.php` to
after all commands. For instance to see the current status.

```console
vendor/bin/phinx status -c phinx.php
```

To create a new migration file use `create`, for instance:

```console
vendor/bin/phinx create -c phinx.php Initial
```

To run migration use `migrate` command, for instance:

```console
vendor/bin/phinx migrate -c phinx.php
```

To migrate eZ publish content the classes `ContentType` and `ContentObject`
may be used inside the migrations.

## Content class export

This package has support for dumping content classes by given identifiers to a runnable script. The script can be run as a migrate-style PHP snippet.

To run this script manually:

```console
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

```console
php vendor/aplia/content-tools/bin/dump_contentclass preamble delete-existing update-class-group=Seksjoner update-creator-id=14 news_section campaign_section logo_section > import_content_classes.php
```

## Utilities

Utility classes using content-tools can be found in `Aplia/Utilities` and the scripts can be found in `bin`.

### Search and Replace

This utility can be used to either search for all occurrences of the given search string in all objects, or also replace the occurrence with the given replace string. The script will print the path of the matched occurrences. It uses ContentObject class to update every object.

#### Search

Assuming project root, run `php vendor/bin/ezp_search_and_replace <search_string>` to search for objects containing the given `search_string` in their attributes `data_text`.

#### Replace

Assuming project root, run `php vendor/bin/ezp_search_and_replace <search_string> <replace_string> --replace` to replace the occurences of `search_string` with `replace_string`. It might be wise to first run search, and then append the replace string after verifying all objects that will be changed. Pass the `--ignore`-parameter, with object ids separated by comma, to ignore specific objects (e.g. `--ignore=<objectId1>,<objectId2>,<objectId3>`).

NB! Some attributes might fail to update. For example eZImage. If they do, the objects are simply not updated, and they are printed after running the script, for manual update.

#### Other parameters

- `--print-urls`: Whether to print urls of the objects. If not set, `path_identification_string` will be printed for every occurrence.
- `--case-insensitive`: Case insensitive search. (The sql uses `REGEXP` on `BINARY`. Setting this omits `BINARY` from the sql.)
- `--new-version`: Whether to publish a new version of updated object as admin. This might return template errors which can be ignored. This parameter presumes Admin user is object id 14.

Run `php vendor/bin/ezp_search_and_replace --help` for a complete overview.

### Reindexing objects of migrated content class

The following is a SQL script for adding content objects of a given class to the index queue table. For example when using eZFind/Solr. (The script for running this is `php runcronjobs.php -s <siteaccess> indexcontent`) This can be added as a migration. (Note: A future version of this could replace this with a flag on the content class update, which queues the class for indexing.)

```SQL
INSERT INTO ezpending_actions (action, created, param)
SELECT "index_object", NULL, ezcontentobject.id
FROM ezcontentobject
INNER JOIN ezcontentclass
ON ezcontentobject.contentclass_id=ezcontentclass.id
WHERE ezcontentclass.identifier="<content class identifier ie. article>";
```
