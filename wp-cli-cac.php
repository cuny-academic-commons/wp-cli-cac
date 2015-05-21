<?php

// Bail if WP-CLI is not present.
if ( !defined( 'WP_CLI' ) ) return;

class CAC_Command extends WP_CLI_Command {
	protected $do_not_update = array(
		'plugin' => array(
			'buddypress-group-documents',
			'bp-import-blog-activity',
			'cac-group-admin-auto-add',
			'event-organiser', // temp
			'forum-attachments-for-buddypress',
			'wp-mailinglist',
		),
		'theme' => array(
			'atahualpa',
		)
	);

	/**
	 * Prepare a major update manifest and blog post.
	 *
	 * ## OPTIONS
	 *
	 * [--version=<version>]
	 * : The version number of the major release. If not provided, will be
	 * inferred from CAC_VERSION.
	 *
	 * [--date=<date>]
	 * : The date for the major release. If not provided, will be assumed
	 * to be the 21st of the current month.
	 */
	public function prepare_major_update( $args, $assoc_args ) {
		$types = array( 'plugin', 'theme' );

		$update_data = array(
			'header' => '',
			'data' => array(),
		);

		if ( ! isset( $assoc_args['version'] ) ) {
			$version = 'x.y.z';
			if ( defined( 'CAC_VERSION' ) ) {
				if ( preg_match( '/^[0-9]+\.[0-9]+\.([0-9]+)/', CAC_VERSION, $matches ) ) {
					$z = $matches[1];
					$new_z = (string) $z + 2;

					$cac_v_a = explode( '.', CAC_VERSION );
					$cac_v_a[2] = $new_z;
					$assoc_args['version'] = implode( '.', $cac_v_a );
				}
			}
		}

		// Infer that release date is 21st of this month.
		if ( ! isset( $assoc_args['date'] ) ) {
			$assoc_args['date'] = date( 'Y-m-21' );
		}

		WP_CLI::log( sprintf(
			'Generating data for CAC %s, scheduled for release on %s. If this is incorrect, please use the --version and --date options to specify a version and date.',
			$assoc_args['version'],
			$assoc_args['date']
		) );

		foreach ( $types as $type ) {
			$data = $this->prepare_major_update_for_type( $type );
			WP_CLI::log( sprintf( "Identified %s items of type '%s' with major updates available.", count( $data ), $type ) );
			$update_data['data'][ $type ] = $data;
		}

		$json_path = ABSPATH . '.cac-major-update.json';

		$update_data['header'] = sprintf( 'CAC major upgrades for %s', $assoc_args['date'] );
		file_put_contents( $json_path, json_encode( $update_data, JSON_PRETTY_PRINT ) );
		WP_CLI::log( sprintf( "Saved results to %s.", $json_path ) );

		$blog_post = $this->generate_major_update_blog_post( $update_data, $assoc_args );

		WP_CLI::log( '' );
		WP_CLI::log( "Don't forget a blog post. Title it \"{$blog_post['title']}\". Here's a draft:" );
		WP_CLI::log( '' );
		WP_CLI::log( '===' );
		WP_CLI::log( '' );
		WP_CLI::log( $blog_post['text'] );
		WP_CLI::log( '' );
		WP_CLI::log( '===' );
		WP_CLI::log( '' );

		WP_CLI::error( 'Don\'t forget to manually check WooThemes for available updates.' );

		WP_CLI::success( 'All done! Be sure to review the release manifest (.cac-major-update.json) before checking into the repo.' );
	}

	/**
	 * Perform major updates as previously prepared by prepare_major_release.
	 */
	public function do_major_update( $args, $assoc_args ) {
		$json_path = ABSPATH . '.cac-major-update.json';

		if ( ! function_exists( 'svn_ls' ) ) {
			WP_CLI::error( "You must have the svn PECL module to use this command (pecl install svn).\n\n(Ugh, don't ask.)" );
			return;
		}

		if ( ! file_exists( $json_path ) ) {
			WP_CLI::error( sprintf( 'Could not find a manifest at %s.', $json_path ) );
			return;
		}

		$update_data = json_decode( file_get_contents( $json_path ) );

		foreach ( $update_data->data as $type => $items ) {
			$this->do_major_update_for_type( $type, $items );
		}

		unlink( $json_path );
		WP_CLI::log( sprintf( 'Deleted %s.', $json_path ) );
		WP_CLI::success( 'Major updates completed.' );
	}

	/**
	 * Perform minor updates.
	 */
	public function do_minor_update( $args, $assoc_args ) {
		$types = array( 'plugin', 'theme' );

		$update_data = array(
			'header' => '',
			'data' => array(),
		);

		foreach ( $types as $type ) {
			$items = $this->get_available_updates_for_type( $type );

			foreach ( $items as $item_data ) {
				// Ignore items from blacklist.
				if ( in_array( $item_data['name'], $this->do_not_update[ $type ] ) ) {
					continue;
				}

				$new_version = $item_data['update_version'];
				$old_version = $item_data['version'];

				$version_compare = $this->version_compare( $new_version, $old_version );

				// Skip major updates.
				if ( $version_compare['is_major_update'] ) {
					continue;
				}

				$args = array( 'gh', $type, 'update', $item_data['name'] );

				WP_CLI::run_command( $args, array() );
			}
		}
	}

	/**
	 * Fetch a formatted list of items with available updates.
	 *
	 * @param string $type Item type. 'plugin' or 'theme'.
	 * @return array
	 */
	protected function get_available_updates_for_type( $type ) {
		$command = "$type list";

		$assoc_args = array(
			'update' => 'available',
			'format' => 'csv',
			'fields' => 'name,title,update_version,version',
		);

		$results = WP_CLI::launch_self( $command, array(), $assoc_args, true, true );

		if ( ! empty( $results->stderr ) ) {
			return false;
		}

		$raw_items = explode( "\n", trim( $results->stdout ) );

		$items = array();
		foreach ( $raw_items as $i => $raw_item ) {
			// Discard title row.
			if ( 0 === $i ) {
				continue;
			}

			$item_data = explode( ',', $raw_item );

			$items[ $item_data[0] ] = array(
				'name' => $item_data[0],

				// Titles have been csv-encoded, so strip the quotes.
				'title' => preg_replace( '/^"?([^"]+)"?$/', '\1', $item_data[1] ),
				'update_version' => $item_data[2],
				'version' => $item_data[3],
			);
		}

		return $items;
	}

	/**
	 * Compare version numbers and determine whether it's a major update + the whitelisted update series.
	 *
	 * @param string $new_version
	 * @param string $old_version
	 * @return array
	 */
	protected function version_compare( $new_version, $old_version ) {
		// "Major" means that either x or y is different. Blargh.
		$new_version_a = explode( '.', $new_version );
		$old_version_a = explode( '.', $old_version );

		$is_major_update = false;
		$update_series = array();
		for ( $i = 0; $i <= 1; $i++ ) {
			$new_version_place = isset( $new_version_a[ $i ] ) ? intval( $new_version_a[ $i ] ) : 0;
			$old_version_place = isset( $old_version_a[ $i ] ) ? intval( $old_version_a[ $i ] ) : 0;

			$update_series[] = $new_version_place;
			if ( $new_version_place != $old_version_place ) {
				$is_major_update = true;
			}
		}

		return array(
			'is_major_update' => $is_major_update,
			'update_series' => implode( '.', $update_series ),
		);
	}

	/**
	 * Prepare major update data for an item type.
	 *
	 * @param string $type Item type. 'plugin' or 'theme'.
	 * @return array
	 */
	protected function prepare_major_update_for_type( $type ) {
		if ( 'theme' !== $type ) {
			$type = 'plugin';
		}

		$items = $this->get_available_updates_for_type( $type );

		if ( false === $items ) {
			WP_CLI::error( $results->stderr );
			return;
		}

		$updates = array();
		foreach ( $items as $item_data ) {
			// Ignore items from blacklist.
			if ( in_array( $item_data['name'], $this->do_not_update[ $type ] ) ) {
				continue;
			}

			$new_version = $item_data['update_version'];
			$old_version = $item_data['version'];

			$version_compare = $this->version_compare( $new_version, $old_version );

			// Not a major update.
			if ( ! $version_compare['is_major_update'] ) {
				continue;
			}

			$item_update = array(
				'name' => $item_data['name'],
				'title' => $item_data['title'],
				'update_series' => $version_compare['update_series'],
			);

			$updates[ $item_data['name'] ] = $item_update;
		}

		return $updates;
	}

	protected function generate_major_update_blog_post( $update_data, $assoc_args ) {
		$update_strings = array();
		foreach ( $update_data['data'] as $type => $type_data ) {
			$update_strings[ $type ] = array();

			foreach ( $type_data as $item ) {
				$update_strings[ $type ][] = sprintf(
					'<li>%s (%s)</li>',
					$item['title'],
					$item['update_series']
				);

				sort( $update_strings[ $type ] );
			}
		}

		$text = '';
		$pretty_date = date( 'F j, Y', strtotime( $assoc_args['date'] ) );

		if ( ! empty( $update_strings['plugin'] ) ) {
			$text .= sprintf(
				"<p>The following plugins will receive major updates as part of the %s release of the CUNY Academic Commons, scheduled for %s:</p>\n<ul>%s</ul>",
				$assoc_args['version'],
				$pretty_date,
				implode( "\n", $update_strings['plugin'] )
			);
		}

		if ( ! empty( $update_strings['theme'] ) ) {
			$text .= sprintf(
				"\n\n<p>The following theme will receive major updates as part of the %s release of the CUNY Academic Commons, scheduled for %s:</p>\n<ul>%s</ul>",
				$assoc_args['version'],
				$pretty_date,
				implode( "\n", $update_strings['theme'] )
			);
		}

		$text .= "\n\n" . '<p>For more details on major update releases, please visit <a href="http://dev.commons.gc.cuny.edu/release-schedule-and-procedures/">our release schedule and procedures page</a>.</p>.';

		$title = sprintf( 'Major plugin and theme updates for %s', $pretty_date );
		return array(
			'title' => $title,
			'text' => $text,
		);
	}

	protected function do_major_update_for_type( $type, $items ) {
		// Get a list of available updates. If whitelisted series matches, no need to check svn.
		$available_updates = $this->get_available_updates_for_type( $type );

		$updates = array();
		foreach ( $items as $item ) {
			if ( ! isset( $available_updates[ $item->name ] ) ) {
				continue;
			}

			$available_version = $available_updates[ $item->name ]['update_version'];

			$version_compare = $this->version_compare( $available_version, $item->update_series );

			if ( ! $version_compare['is_major_update'] ) {
				$updates[ $item->name ] = 'latest';
				continue;
			}

			// There's a mismatch, so we have to scrape wordpress.org for versions. Whee!
			// @todo Get someone to implement this in the API.
			$url = "http://{$type}s.svn.wordpress.org/{$item->name}/tags/";
			$versions = svn_ls( $url );
			$versions = array_keys( $versions );
			rsort( $versions );

			foreach ( $versions as $v ) {
				$v_version_compare = $this->version_compare( $v, $item->update_series );

				if ( ! $v_version_compare['is_major_update'] ) {
					$updates[ $item->name ] = $v;
					break;
				}
			}
		}

		foreach ( $updates as $plugin_name => $update_version ) {
			$args = array( 'gh', $type, 'update', $plugin_name );

			$assoc_args = array();
			if ( 'latest' !== $update_version ) {
				$assoc_args['version'] = $update_version;
			}

			WP_CLI::run_command( $args, $assoc_args );
		}
	}
}

WP_CLI::add_command( 'cac', 'CAC_Command' );
