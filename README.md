# Migrate Subcolumns to Grids

This bundle aids in migrating from
- [`heimrichhannot/subcolumns`](https://github.com/heimrichhannot/contao-subcolumns)
- [`heimrichhannot/contao-subcolumns-bootstrap-bundle`](https://github.com/heimrichhannot/contao-subcolumns-bootstrap-bundle)

to
- [`contao-bootstrap/grid` version 2](https://github.com/contao-bootstrap/grid)
- [`contao-bootstrap/grid` version 3](https://contao-bootstrap.de/bootstrap-5-verwenden.html)

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

Or use the provided options to skip the wizard:

```bash
vendor/bin/contao-console sub2grid:migrate --help
```
