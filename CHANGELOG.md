# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added
- Functionality for migrating heimrichhannot/subcolumns

### Fixed
- The CSS ID/class of database column sets (`tl_columnset.cssID`) was dropped. The SubcolumnsBootstrapBundle rendered
  it on the row whenever the start element had no CSS ID or class of its own; the migration now writes it onto those
  start elements, and leaves elements with their own value untouched.
