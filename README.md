# Migrate H&#8239;<small>&amp;</small>&#8239;H Subcolumns to CB&#8239;Grids

This bundle aids in migrating from either one of
- [`heimrichhannot/subcolumns`](https://github.com/heimrichhannot/contao-subcolumns)
- [`heimrichhannot/contao-subcolumns-bootstrap-bundle`](https://github.com/heimrichhannot/contao-subcolumns-bootstrap-bundle)

to your choice of
- [`contao-bootstrap/grid:^2.0`](https://github.com/contao-bootstrap/grid)
- [`contao-bootstrap/grid:^3.0`](https://contao-bootstrap.de/bootstrap-5-verwenden.html)


## Prerequisites

- Contao 4.13 or higher
- PHP 7.4 or higher
- Either version 2 or 3 of `contao-bootstrap/grid` installed
- Contao database migrations must be up-to-date

> [!CAUTION]
> You may also run the migration on Contao 5 with none of the subcolumns packages installed.
> Just make sure to run the **Contao migration WITHOUT deletes** prior to this migration.

> [!NOTE]
> Neither the subcolumns module nor subcolumns bootstrap bundle have to be installed,
> although it does not hurt if they are.

> [!TIP]
> Run the [fix command](#fixing-corrupt-subcolumns) before migrating to ensure that all subcolumns are in a consistent state.


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

The migration must obtain some information on the environment and your intents.
An attempt is made to automatically obtain information that can be inferred.
Non-inferable details will be queried interactively by the wizard.

You may also provide all non-inferable options to skip the wizard.

#### Most common options

- `--from FROM`|`-f FROM`: The package to migrate from. Either `m` for the legacy SubColumns module or `b` for the SubcolumnsBootstrapBundle.
- `--theme THEME_ID`|`-t THEME_ID`: The theme ID to create the grid definitions on. Set to 0 to create a new theme.
- `--grid-version GRID_VERSION`|`-g GRID_VERSION`: The grid version to migrate to. Either `2` or `3`.
- `--dry-run`: Perform a dry run without committing changes to the database.

For example:
```bash
vendor/bin/contao-console sub2grid:migrate -f b -g 3 -t 0 --dry-run
```

To see all available options, run the command with `--help`:

```bash
vendor/bin/contao-console sub2grid:migrate --help
```


### Rolling back

If you want to roll back the migration, use the following command:

```bash
vendor/bin/contao-console sub2grid:rollback
```

This command will prompt you to choose whether to reset the types of the migrated content elements and/or form fields,
whether to reset their `customTpl` settings that have been overwritten during the migration, and whether to remove
previously migrated grid definitions.

To run a full rollback and skip all prompts, provide the `-n` option:

```bash
vendor/bin/contao-console sub2grid:rollback -n
```


### Fixing corrupt Subcolumns

If you have been using the SubcolumnsBootstrapBundle prior to version 1.12 or the Subcolumns module, you may encounter issues with corrupt subcolumns.
These issues may manifest as missing subcolumn content elements and form fields, or as such elements that are not properly linked to their subcolumn start element.

These issues arose due to a bug in the SubcolumnsBootstrapBundle prior to version 1.12 and the Subcolumns module, which caused the wrong `sc_parent` IDs to be inherited upon cloning subcolumn content elements and form fields.

Run the following command to attempt to fix these issues:

```bash
vendor/bin/contao-console sub2grid:fix
```

You may provide the `--dry-run` option to perform a dry run without committing changes to the database.

Provide the `--cleanse`|`-c` option to remove all subcolumn content elements and form fields that pose incomplete start&mdash;parts&mdash;end series AND that are turned invisible. In case of doubt, run the command without this option first.

> [!NOTE]
> The command will always throw an error if it detects any incomplete subcolumn content element series that are visible.


## Aftermath

After the migration, you should check the affected pages and modules for any issues.

- The migration will only be commited to the database if no errors occur during the migration.
- The migration will migrate global subcolumn set definitions from your `config.php` and `$GLOBALS['TL_SUBCL']`,
  respectively, to grid definitions of any theme you select or optionally of a newly created one.
- The migration will migrate database-defined subcolumn definitions to grid definitions of any theme you choose or
  a newly created one.
- The migration will transform subcolumn content elements to grid content elements.
- The migration will not remove the subcolumns module or subcolumns bootstrap bundle.

> [!TIP]
> You may run the migration multiple times without causing issues.

> [!IMPORTANT]
> Running the migration multiple times will not duplicate any grid definitions, as long as you leave the created tags
> (e.g. `[sub2grid:source.profile.name]`) within the grid definition descriptions untouched. 
