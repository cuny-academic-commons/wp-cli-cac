## `wp cac`

wp-cli tools for managing the CUNY Academic Commons.

## Commands

### `$ wp cac prepare_major_update`

Generate a JSON manifest to describe an upcoming CAC update, and generate a draft blog post. The `--version` and `--date` flags are optional. See `--help` for details.

### `$ wp cac do_major_update`

Perform updates as specified in the `.cac-major-update.json` manifest file created by `wp cac prepare_major_update`.

__Note__: this command requires the PECL `svn` package, as well as [wp-cli-git-helper](https://github.com/boonebgorges/wp-cli-git-helper/).
__Note__: this command currently does not have a dry-run version, so use at your own risk.

### `$ wp cac do_minor_update`

Perform minor theme and plugin updates.

## License

Available under the terms of the GNU General Public License v2 or greater.
