# Changelog

Changelog for the `content-tools` package.

## 1.7.5

- Fixed bug in updating object relation attributes using eZ objects.

## 1.7.4

- Added option `--yes` to turn off interactive mode and assume yes on all
  questions that would have been asked.

## 1.7.2

- Fixes to binary file import, uses temporary file with original filename
  to make sure eZ publish stores the information correctly.

## 1.7.1

- Caches related to roles are now cleared upon modification. Can be turned
  off and called manually if needed.
- Added ContentObject::lookupNodeId, this is similar to lookupNode() but
  will only return the node ID.
- Added ContentObject::lookupObject and ContentObject::lookupObjectId to
  return content object and content object ID based on input value.

## 1.7.0

- New API Aplia\Content\Role for working with eZRole, policies and assignments.
- New script dump_role which dumps the eZRole as PHP code.

## 1.6.0

- ContentImporter::warning() now outputs to stderr not stdout.
- Added ContentImporter::info() to output to stdout console, only outputs
  if verbosity is on.
- Added method ContentObjet::lookupContentObject for finding an object
  by UUID/Remote ID, uses a LRU cache to avoid repeated access to DB.
- Support for late mapping of content objects on import. Any new objects
  will now scan for relations to this object and fix the UUID reference
  if it was changed by mapping/transformation.

## 1.5.8

- Extended BatchProcessor with match control, visit callback and read-only
  support

  The 'match' callback is called for each node and if it returns true
  then the node is processed, if false it ignores the node. This can
  be used for more advanced filtering.

  The 'visit' callback is called before node/object is processed, can
  be used to output progress before anything happens.

  By setting processor param 'readOnly' to true it will force the
  processor to no call the 'process' callback, 'visit' and 'visited'
  are still called.
- ContentObject loadLocations now stores node properties
- Added more options to process_content

  --read-only forces processing to be read-only, no process is called
  only visit callbacks.

  --nodes makes processing to occur on nodes and not objects.

  --php-file specifies a PHP file which should return an array with
  callback entries, it can return the following

  - 'process' - The function to process the object, must be set.
  - 'visit' - The function to call on each visit.
  - 'visited' - The function to call after each visit.
  - 'completed' - The function to call when an object was modified.
  - 'skipped' - The function to call when an object was not modified.
  - 'match' - The function to call for additional filtering.
- Support for attribute filtering in process_content

## 1.5.7

- Added a batch processor class for easily modifying multiple objects.
  It takes a QuerySet object as parameter (or parameters for QuerySet)
  and takes care of iterating over all objects in that set and calling
  a callback. This class can either be used directly by passing
  functions into the callbacks or by subclassing.
  The processor defaults to iterating over objects (ie. main nodes) and
  will pass a ContentObject instance to the callback, however it may be
  changed to visiting nodes instead.
  There are also callbacks for when an object was modified, skipped or
  one for all visited nodes/objects.
- Added script bin/process_content for running batch processing on objects.
- getAttribute is now getContentAttributeValue and it only returns the
  value for the ContentObjectAttribute instance. Added
  getRawAttributeValue to get the raw content value directlry from
  data-type. getContentAttribute returns the ContentObjectAttribute
  instance.
- Added ContentObject::lookupNode to return nodes from string formats.
  The formats are:
    * node:<number> - Use as node ID to find node 
    * node_uuid:<uuid> - Use as remote ID to find node
    * object:<number> - Use as object ID to find object and main node
    * object_uuid:<uuid> - Use as object remote ID to find object and main node
    * path:<path> - Use as url-alias path to find node
- Fixed import of DateTime
- Fixed checking for existing attribute before trying to remove.
- Fixed migration to include all content languages.
- Fixed value extraction for ezselection, no selection now results in null.


## 1.5.6

- All locations of visited nodes are now part of export.
- The same object may now be imported multiple times, e.g in different files,
  in the same import run. The object data is only taken once but locations
  from all will be merged.
- Prompt for starting import now only happens once, not per file.
- Fixed class transformation, it will now change the class used and has support
  for filtering out certain attributes.
- Fixed issue with ezxmltext being emptied on import.
- Support for enabling debug output, can be used to debug certain aspects of the content system.
- Support for date strings for ContentObject->publishedDate and ContentType->created.

## 1.5.5

- Support for moving locations with ContentObject::moveLocation().

## 1.5.4

- Support for object 'status' value, it is exported as a string.
- References to objects which does not exist locally and have status draft or
  archived are now removed.
- Better display of name on referenced nodes, now displays root and top-level nodes.
- All object relations are now exported.
- Improved export of PHP code to set more fields on object and locations.
- Fixed issue in code for mapping tree identifier.
- Fixed issue in importing files to file/image data-types.

## 1.5.3

- Support for exporting all parents of visited nodes.
- Support for excluding nodes on export.
- Detection of root node (id=1) on import, it is then mapped
  to the same uuid of the local root node.
- Fixed issue in updating ezuser data-type, if the attribute already has
  an email set it will fail unique-check, applying workarounds in that case.
- Imported objects are now part of remapping index if a transformer has
  changed the uuid. For instance if a transformer code found an existing
  object and changed it to use that uuid.

## 1.5.2

- Section identifier on imported objects are now remapped.

## 1.5.1

- Relations on content object are now imported.
- Support for setting owner on content classes, specifiy ownerId,
  ownerUuid or ownerIdentifier field.
- Tree node 1 is now considered tree identifier 'top'
- Fixed tree identifier in dump_content.
- Fixed issues with creating/removing class groups.
- Added ContentMigration class to aid in migrating eZ content.
  This requires that Phinx is installed.

## 1.5

- New importer for importing multiple record types. This includes
  content-language, content-state, section, content-object and nodes.
  Content may also be transformed on import by mapping tables or
  using custom Transformation classes.
- Support for 'php' format when dumping content.
- Improved support for updating attributes using most builtin data-types.

## 1.4

- New script for dumping content-object to JSON. It creates an export which
  contains content-objects and related elements such as section, content-state,
  sparse class definition, relations, files etc.
- Improved ContentObjectAttribute to support loading data from the attribute
  data-type and supports many more data-types. The data is loaded into the
  $value property depending on the type.

## 1.3.1
- Added support for setting logged in user by object id when calling `dump_contentclass`.
- Added support for setting a content class group on all exported content classes when calling `dump_contentclass`.
- Fixed bug where script generated by `dump_contentclass` would give fatal error.
- Added `aplia/support` as dependency

## 1.3

- Better support for managing group assignments (adding, removing) and for creating
  new groups.
- Support for translating content of content-classes and its attributes.
- Support for using sort identifiers in new field sortBy.
- New script for dumping a content-class to PHP code, can be used to aid
  in writing migration code or import scripts.

## 1.2

- Support for migrations using Phinx, a new class Migration\Configuration sets
  up Phinx based on eZ publish site data.
- Improved API for ContentType by making more consistent (removeAttribute vs deleteAttribute)
  and adding more methods for common operations.

## 1.1.2
- The `locations` parameter to ContentObject can now contain content nodes or
  content objects. The node ID is extracted from those objects if used.

## 1.1.1
- Fix for `locations` parameter if it is null.

## 1.1.0
- Support for updating content objects. Create the `ContentObject` instance with the
  actual content object instance (or id/uuid) specified.
  Then change attributes with `setAttribute()` and call `update()` to write back changes.
- New method `getAttribute()` to get the attribute value from an existing object.
  If attribute is modified in memory it returns the new value.
- `ContentObjectAttribute` now has a `isDirty` flag which is `false` by default, if a
  new value is written it will be set to `true`.

## 1.0.6

- PHP 5.3 compatibility fixes.

## 1.0.5

- New methods on `ContentType` for checking for class and attribute existance.
- New exception `TypeError`, used when attribute does not match the expected type.

## 1.0.4

- Fixed issue when parsing HTML text.

## 1.0.3

- Fixed issue with updating `ezimage` attributes

## 1.0.2

- Support for `ezboolean`, `eztext`, `ezxmltext`, `ezimage`, `ezinteger`, `ezurl`.

## 1.0.1

- First official version for use with composer.
