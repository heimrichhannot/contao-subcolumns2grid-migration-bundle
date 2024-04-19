# Migrate H&#8239;&&#8239;H Subcolumns to CB Grids

This bundle aids in migrating from either one of
- [`heimrichhannot/subcolumns`](https://github.com/heimrichhannot/contao-subcolumns)
- [`heimrichhannot/contao-subcolumns-bootstrap-bundle`](https://github.com/heimrichhannot/contao-subcolumns-bootstrap-bundle) (migration currently in development)

to your choice of
- [`contao-bootstrap/grid:^2.0`](https://github.com/contao-bootstrap/grid)
- [`contao-bootstrap/grid:^3.0`](https://contao-bootstrap.de/bootstrap-5-verwenden.html)


## Prerequisites

- Contao 4.13 or higher
- PHP 7.4 or higher
- Either version 2 or 3 of `contao-bootstrap/grid` installed
- Contao database migrations must be up-to-date
- If you were using the [subcolumns bootstrap bundle](https://github.com/heimrichhannot/contao-subcolumns-bootstrap-bundle) prior to the migration,
**you must update and migrate to version [1.13@beta](https://github.com/heimrichhannot/contao-subcolumns-bootstrap-bundle/tree/feature/set_selection)** before running this migration.

> **Note:** Neither the subcolumns module nor subcolumns bootstrap bundle have to be installed,
> although it does not hurt if they are, provided you are running Contao 4.13.
> 
> You can also run the migration on Contao 5 with none of the subcolumns packages installed.
> Just make sure to **run the contao migration WITHOUT deletes** prior to running this migration.


## Installation

Install via composer:

```bash
composer require heimrichhannot/contao-subcolumns2grid-migration-bundle:dev-trunk
```


## Usage

### Migrating

Run the migration via the CLI wizard:

```bash
vendor/bin/contao-console sub2grid:migrate
```

Or use the provided options to skip the wizard.


#### Most common options

- `--from FROM`|`-f FROM`: The package to migrate from. Either `m` for the legacy SubColumns module or `b` for the SubcolumnsBootstrapBundle.
- `--theme THEME_ID`|`-t THEME_ID`: The theme ID to create the grid definitions on. Set to 0 to create a new theme.
- `--grid-version GRID_VERSION`|`-g GRID_VERSION`: The grid version to migrate to. Either `2` or `3`.
- `--dry-run`: Perform a dry run without committing changes to the database.

For example:
```bash
vendor/bin/contao-console sub2grid:migrate -f b -g 3 -t 0 --dry-run
```

To see all the available options, run the command with the `--help` option:

```bash
vendor/bin/contao-console sub2grid:migrate --help
```


### Rolling back

If you want to roll back the migration, you can use the following command:

```bash
vendor/bin/contao-console sub2grid:rollback
```

This command will prompt you to choose whether to reset the types of the migrated content elements and/or form fields,
whether to reset their customTpl settings that have been overwritten during the migration, and whether to remove
previously migrated grid definitions.

To run a full rollback and skip any prompts, you can use the `-n` option:

```bash
vendor/bin/contao-console sub2grid:rollback -n
```


## Aftermath

After the migration, you should check the affected pages and modules for any issues.

- The migration will only be commited to the database if no errors occur during the migration.
- The migration will migrate global subcolumn set definitions from your `config.php` and `$GLOBALS['TL_SUBCL']`,
  respectively, to grid definitions of any template you choose or of a new theme.
- The migration will migrate database-defined subcolumn definitions to grid definitions of any template you choose or
  of a new template.
- The migration will transform subcolumn content elements to grid content elements.
- The migration will not remove the subcolumns module or subcolumns bootstrap bundle.

> **Note:** You may run the migration multiple times without causing any issues. It will not duplicate any grid definitions,
> as long as you leave the created tags (e.g. `[sub2grid:source.profile.name]`) within the grid definition descriptions untouched. 
