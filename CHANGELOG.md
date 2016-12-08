# Changelog

Changelog for the `contentquery` package.

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
