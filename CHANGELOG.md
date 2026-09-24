# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added
- Functionality for migrating heimrichhannot/subcolumns

### Fixed
- `sub2grid:fix` paired visible and invisible elements in one pass, so an invisible end could close a visible start.
  The visible end that closed that row on the page was then left over and deleted by `--cleanse --force`, and the
  migrated grid never closed, pulling every following element into its last column. Sets are now built from the
  visible elements first, the way SubColumns rendered them.
- The CSS ID/class of database column sets (`tl_columnset.cssID`) was dropped. The SubcolumnsBootstrapBundle rendered
  it on the row whenever the start element had no CSS ID or class of its own; the migration now writes it onto those
  start elements, and leaves elements with their own value untouched.
