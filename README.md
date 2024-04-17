# Migrate Subcolumns to Grids

This bundle aids in migrating from either one of
- [`heimrichhannot/subcolumns`](https://github.com/heimrichhannot/contao-subcolumns)
- [`heimrichhannot/contao-subcolumns-bootstrap-bundle`](https://github.com/heimrichhannot/contao-subcolumns-bootstrap-bundle) (currently in development)

to your choice of
- [`contao-bootstrap/grid` version 2](https://github.com/contao-bootstrap/grid)
- [`contao-bootstrap/grid` version 3](https://contao-bootstrap.de/bootstrap-5-verwenden.html)


## Prerequisites

- Contao 4.13 or higher
- PHP 7.4 or higher
- Either version 2 or 3 of `contao-bootstrap/grid` installed
- Contao database migrations up to date
- If you were using the [subcolumns bootstrap bundle](https://github.com/heimrichhannot/contao-subcolumns-bootstrap-bundle) prior to the migration,
**you must update and migrate to version [1.13@beta](https://github.com/heimrichhannot/contao-subcolumns-bootstrap-bundle/tree/feature/set_selection)** before running this migration.

> **Note:** Neither the subcolumns module nor subcolumns bootstrap bundle have to be installed,
> although it does not hurt if they are.


## Installation

Install via composer:

```bash
composer require heimrichhannot/contao-subcolumns2grid-migration-bundle:dev-trunk
```

## Usage

Run the migration via the CLI wizard:

```bash
vendor/bin/contao-console sub2grid:migrate
```

Or use the provided options to skip the wizard. List all available options via:

```bash
vendor/bin/contao-console sub2grid:migrate --help
```

## Aftermath

After the migration, you should check the affected pages and modules for any issues.

- The migration will migrate global subcolumn set definitions from your `config.php`
  to grid definitions of any template you choose or of a new theme.
- The migration will migrate database-defined subcolumn definitions to grid definitions of any template you choose or
  of a new template.
- The migration will transform subcolumn content elements to grid content elements.
- The migration will not remove the subcolumns module or subcolumns bootstrap bundle.

> **Note:** You may run the migration multiple times without any issues. It will not duplicate any grid definitions,
> as long as you leave the created tags (e.g. `[sub2grid:myset.id]`) within the grid definition descriptions untouched. 
