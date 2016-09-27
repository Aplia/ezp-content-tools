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
$type = new Aplia\Content\ContentType('folder', 'Folder')
->addAttribute('ezstring', 'title', 'Title');

// Create the class in the database
$type->create();

```

## ContentObject

Wrapper around eZContentObject which defines a content object
with chosen attributes.

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