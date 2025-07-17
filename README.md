# Migrate H&#8239;<small>&amp;</small>&#8239;H Subcolumns to CB&#8239;Grids

This bundle facilitates the migration from either one of:
- [`heimrichhannot/subcolumns`](https://github.com/heimrichhannot/contao-subcolumns)
- [`heimrichhannot/contao-subcolumns-bootstrap-bundle`](https://github.com/heimrichhannot/contao-subcolumns-bootstrap-bundle)

to your choice of:
- [`contao-bootstrap/grid:^2.0`](https://github.com/contao-bootstrap/grid)
- [`contao-bootstrap/grid:^3.0`](https://contao-bootstrap.de/bootstrap-5-verwenden.html)


## Prerequisites

- Contao 4.13 or higher
- PHP 7.4 or higher
- `contao-bootstrap/grid` v2 or v3 installed
- Updated Contao database migrations

> [!CAUTION]
> You may also run the migration with none of the subcolumns packages installed, i.e. on Contao 5.
> Just make sure to run the <ins>**Contao migration WITHOUT deletes**</ins> prior to this migration. 

> [!TIP]
> Run the [fix command](#fixing-corrupt-subcolumns) before migrating to ensure that all subcolumns are in a consistent state.

> [!TIP]
> Error messages will generally try to guide you to the source of the issue. If you encounter any issues that are not covered in this document, or that you are unable to resolve yourself,
> please report them on the [GitHub issue tracker](https://github.com/heimrichhannot/contao-subcolumns2grid-migration-bundle/issues).

## Installation

Install via composer:

```bash
composer require heimrichhannot/contao-subcolumns2grid-migration-bundle:dev-trunk
```


## Usage

###### Commands Index

- [Migrating](#migrating)
- [Rolling back](#rolling-back)
- [Fixing corrupt Subcolumns](#fixing-corrupt-subcolumns)

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
- `--theme THEME_ID`|`-t THEME_ID`: The theme ID to create the grid definitions on. Use `0` to create a new theme.
- `--grid-version GRID_VERSION`|`-g GRID_VERSION`: The grid version to migrate to (`2` or `3`).
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

To roll back the migration:

```bash
vendor/bin/contao-console sub2grid:rollback
```

This command prompts for reset options. To skip prompts, use:

```bash
vendor/bin/contao-console sub2grid:rollback -n
```


### Fixing corrupt Subcolumns

This command will go through all subcolumn content elements and form fields and fix the `sc_parent` IDs of all elements respectively.

Run the following command to ensure that all subcolumns are in a consistent state:

```bash
vendor/bin/contao-console sub2grid:fix
```

You may provide the `--dry-run` option to perform a dry run without committing changes to the database.

Provide the `--cleanse`|`-c` option to remove all subcolumn content elements and form fields that pose incomplete start&mdash;parts&mdash;end series AND that are turned invisible.
Meaning that if the elements of a column set are invisible, and their start or stop is missing, they will be deleted.
In case of doubt, run the command without this option first.

Provide the `--force`|`-f` option in combination with `--cleanse` to force the removal of all subcolumn content elements and form fields that pose incomplete start&mdash;parts&mdash;end series, regardless of their visibility.

> [!NOTE]
> The command errors if it finds incomplete, *visible* subcolumn content element series. You will have to investigate and fix these manually.

> **Trivia:** If you have been using the SubcolumnsBootstrapBundle prior to version 1.11.3 or the Subcolumns module, you may encounter issues with corrupt subcolumns.
These issues may manifest as missing subcolumn content elements and form fields, or as such elements that are not properly linked to their subcolumn start element.
> 
> These issues arose due to a bug in the SubcolumnsBootstrapBundle prior to version 1.11.3 and the Subcolumns module, which caused the wrong `sc_parent` IDs to be inherited upon cloning subcolumn content elements and form fields.


## Aftermath

Post-migration, verify affected pages and modules.

- The migration tries to use database transactions and only commits if no errors occur.
- The migration will migrate global subcolumn set definitions from your `config.php` and `$GLOBALS['TL_SUBCL']`,
  respectively, to grid definitions of any theme you select or optionally of a newly created one.
- The migration will migrate database-defined subcolumn definitions to grid definitions of any theme you choose or
  a newly created one.
- The migration will transform subcolumn content elements to grid content elements.
- The migration will not remove the subcolumns module or subcolumns bootstrap bundle.

> [!TIP]
> You may run the migration multiple times without causing issues.

> [!IMPORTANT]
> Running the migration multiple times doesn't duplicate grid definitions if the created tags
> (e.g. `[sub2grid:source.profile.name]`) within the grid definition descriptions remain unchanged. 


## Wording

- **Module**: Refers to the legacy [Subcolumns module](https://github.com/heimrichhannot/contao-subcolumns).
- **Bundle**: Refers to the [SubcolumnsBootstrapBundle](https://github.com/heimrichhannot/contao-subcolumns-bootstrap-bundle) (EOL).
- **Set**: A configuration of content divisors of defined width and offset that make up a column set.
- **Series**: Refers to a series of subcolumn content elements or form fields that are defined by a column set. A start element, any number of part elements, and a stop element make up a series.
- **Subcolumn Content Elements**: Refers to the content elements that are part of a subcolumn set in `tl_content`.
- **Subcolumn Form Fields**: Refers to the form fields that are part of a subcolumn set in `tl_form_field`.
- **Alchemist**: Refers to the migration tools that transform subcolumns to grids.
- **Set Definitions**: Refers to the definitions of subcolumn sets in either the database or the global variable.
- **Globals**: Refers to the global subcolumn set definitions in `config.php` and `$GLOBALS['TL_SUBCL']`, respectively.
- **Database Definitions**: Refers to subcolumn set definitions stored in the database.


## Hotfixes for known issues

### Using a container class on a column

If you are using Bootstrap and the migration has added a `.container` class to a `.col`, you may experience issues with the column not being displayed correctly.

To fix this, add the following SCSS snippet to your theme. Just make sure that it cascades after the Bootstrap styles. 

```scss
.ce_bs_gridStart > .col.container {
    @include make-container-max-widths();
}
```
