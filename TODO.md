- fix ezxmltext link with eznode: entry, must export reference to uuid of object

- when finding relation, check internal object index and mapping first

- Check if there are more fields in classes than on site.
  If so ask if existing objects should be updated.
  If yes then mark class with fields that should be updated.
  This mark can also later be used by transform classes
  to add additional fields to always update.
  Can also be used per-object.
- Optimize object existance checking. Do one pass and figure
  out all uuids to check then run one SQL (or several chunked)
  with all uuids to get object id. Place this information
  in object/node index with status set to 'existing' (or similar)
  Then make all object checks first check with index first.

- support root_type and parent_root_type in ContentObject
  can be used to find correct parent if uuid is missing
- Generalize the code for --parent-node into a static function
  for usage in other places. Support more input types such as
  uuid: , node_uuid: etc.
- support iso format inputs for dates, and export dates as iso string
- write exported files with suffix based on original filename
- change importer to store a references from object uuid to a list
  of object with that reference. e.g. if an object has an owner, that
  owner uuid is stored as reference with the object as part of that reference
  when a new object is imported it checks if this new object (with old and new uuid)
  has references to it, if so goes over all objects that references and fixes
  them.
  Maybe one reference index per type, e.g. owner, relation etc.
- before removing location check if it has children, if it has deny the removal.
  Add option to removeLocation to force removal of children
