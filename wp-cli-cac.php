<?php
// Load autoloader if available.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require __DIR__  . '/vendor/autoload.php';
}

// Register our command if WP-CLI is available.
if ( defined( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'cac', 'CAC_Command' );
}

class CAC_Command extends WP_CLI_Command {
	protected $update_blacklist = array(
		'plugin' => array(),
		'theme' => array(),
	);

	/**
	 * Default blacklist values.
	 *
	 * Can be overridden with exclude-plugin and exclude-theme flags.
	 */
	protected $do_not_update = array(
		'plugin' => array(
			'buddypress-group-documents',
			'bp-import-blog-activity',
			'cac-group-admin-auto-add',
			'forum-attachments-for-buddypress',
			'plugins-list', // not compatible with our PHP.
			'post-gallery-widget',
			'wordpress-mu-domain-mapping',
			'wp-front-end-editor', // we run a fork.
			'wp-mailinglist',
			'wysija-newsletters',
		),
		'theme' => array(
			'atahualpa',
		),
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

		$month = date( 'M' );
		$first_release = new DateTime( "second tuesday of $month" );
		$second_release = new DateTime( "fourth tuesday of $month" );

		// Infer that release date is fourth Tuesday of this month.
		if ( ! isset( $assoc_args['date'] ) ) {
			$assoc_args['date'] = $second_release->format( 'Y-m-d' );
		}

		if ( ! isset( $assoc_args['version'] ) ) {
			$version = 'x.y.z';

			if ( defined( 'CAC_VERSION' ) ) {
				if ( preg_match( '/^[0-9]+\.[0-9]+\.([0-9]+)/', CAC_VERSION, $matches ) ) {
					$z = $matches[1];
					$new_z = $z;

					$release_time = strtotime( $assoc_args['date'] );
					$dom = date( 'j' );
					$year = date( 'Y' );
					$month = date( 'm' );
					for ( $i = date( 'j' ); $i <= intval( $second_release->format( 'j' ) ); $i++ ) {
						$maybe_date = "$year-$month-$i";
						if ( $maybe_date === $first_release->format( 'Y-m-d' ) || $maybe_date === $second_release->format( 'Y-m-d' ) ) {
							$new_z++;
						}
					}
					$new_z = (string) $new_z;

					$cac_v_a = explode( '.', CAC_VERSION );
					$cac_v_a[2] = $new_z;
					$assoc_args['version'] = implode( '.', $cac_v_a );
				}
			}
		}

		WP_CLI::log( sprintf(
			'Generating data for CAC %s, scheduled for release on %s. If this is incorrect, please use the --version and --date options to specify a version and date.',
			$assoc_args['version'],
			$assoc_args['date']
		) );

		$this->set_up_blacklist( $assoc_args );

		foreach ( $types as $type ) {
			$data = $this->prepare_major_update_for_type( $type );
			WP_CLI::log( sprintf( "Identified %s items of type '%s' with major updates available.", count( $data ), $type ) );
			$update_data['data'][ $type ] = $data;
		}

		$json_path = ABSPATH . '.cac-major-update.json';

		$update_data['header'] = sprintf( 'CAC major upgrades for %s', $assoc_args['date'] );
		file_put_contents( $json_path, json_encode( $update_data, JSON_PRETTY_PRINT ) );
		WP_CLI::log( sprintf( 'Saved results to %s.', $json_path ) );

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

		WP_CLI::log( 'Don\'t forget to manually check WooThemes for available updates.' );
		WP_CLI::log( 'Also, don\'t forget to manually check https://wpcom-themes.svn.automattic.com for updates to "imbalance2" and "manifest".' );
		WP_CLI::log( 'Also, don\'t forget to manually check http://themetrust.com for updates to "reveal".' );
		WP_CLI::log( 'Also, don\'t forget to manually check https://themify.me/themes/basic for updates to "basic".' );
		WP_CLI::log( 'Also, don\'t forget to manually check https://elegantthemes.com for updates to "DailyNotes", "ArtSee", "13Floor", "eNews", "Lucid", "Basic", "Cion", "AskIt", "BusinessCard".' );
		WP_CLI::log( 'Look for Gravity Forms updates' );

		WP_CLI::success( 'All done! Be sure to review the release manifest (.cac-major-update.json) before checking into the repo.' );
	}

	/**
	 * Perform major updates as previously prepared by prepare_major_release.
	 *
	 * ## OPTIONS
	 *
	 * [--exclude-plugins=<plugins>]
	 * : Comma-separated list of plugin slugs to be excluded.
	 *
	 * [--exclude-themes=<themes>]
	 * : Comma-separated list of theme slugs to be excluded.
	 */
	public function do_major_update( $args, $assoc_args ) {
		$json_path = ABSPATH . '.cac-major-update.json';

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
	 *
	 * ## OPTIONS
	 *
	 * [--exclude-plugins=<plugins>]
	 * : Comma-separated list of plugin slugs to be excluded.
	 *
	 * [--exclude-themes=<themes>]
	 * : Comma-separated list of theme slugs to be excluded.
	 */
	public function do_minor_update( $args, $assoc_args ) {
		$this->maybe_register_gh_command();

		$types = array( 'plugin', 'theme' );

		$update_data = array(
			'header' => '',
			'data' => array(),
		);

		$this->set_up_blacklist( $assoc_args );

		foreach ( $types as $type ) {
			$items = $this->get_available_updates_for_type( $type );
			if ( empty( $items ) ) {
				continue;
			}

			foreach ( $items as $item_data ) {
				// Ignore items from blacklist.
				if ( in_array( $item_data['name'], $this->update_blacklist[ $type ] ) ) {
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

				// Override locale so we can skip translation updates.
				add_filter( 'locale', array( $this, 'set_locale' ) );

				WP_CLI::run_command( $args, array() );

				remove_filter( 'locale', array( $this, 'set_locale' ) );
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

		$assoc_args = '--update=available --format=csv --fields=name,title,update_version,version';

		$results = WP_CLI::runcommand( $command . ' ' . $assoc_args, array(
			'return' => true
		) );

		/*
		 * No results, so bail!
		 *
		 * Here, we're checking if there is a line return. If there isn't, then we
		 * only have the title row, which means no results.
		 */
		if ( false === strpos( $results, "\n" ) ) {
			return false;
		}

		$raw_items = explode( "\n", trim( $results ) );

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
			if ( in_array( $item_data['name'], $this->update_blacklist[ $type ] ) ) {
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
		$this->maybe_register_gh_command();

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
			// Used to use `svn_ls()` for this, but PECL broke for me. Let the fun begin.
			$url = "http://{$type}s.svn.wordpress.org/{$item->name}/tags/";
			$f = wp_remote_get( $url );
			$body = wp_remote_retrieve_body( $f );

			$dom = new DomDocument();
			$dom->loadHTML( $body );
			$tags = $dom->getElementsByTagName( 'li' );
			$versions = array();
			foreach ( $tags as $tag ) {
				$versions[] = rtrim( $tag->nodeValue, '/' );
			}

			// If a plugin has been closed or whatever.
			if ( ! $versions ) {
				continue;
			}

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

			// Override locale so we can skip translation updates.
			add_filter( 'locale', array( $this, 'set_locale' ) );

			WP_CLI::run_command( $args, $assoc_args );

			remove_filter( 'locale', array( $this, 'set_locale' ) );
		}
	}

	public function set_locale( $locale ) {
		return 'en_US';
	}

	/**
	 * Change a site's domain.
	 *
	 * ## OPTIONS
	 *
	 * --from=<from>
	 * : The current domain of the site being changed.
	 *
	 * --to=<date>
	 * : The domain that the site is being changed to.
	 *
	 * [--dry-run]
	 * : Whether this should be a dry run.
	 */
	public function change_domain( $args, $assoc_args ) {
		global $wpdb;

		if ( empty( $assoc_args['from'] ) || empty( $assoc_args['to'] ) ) {
			WP_CLI::error( "The 'from' and 'to' parameters are required." );
			return;
		}

		$from_domain = $assoc_args['from'];
		$to_domain   = $assoc_args['to'];

		$from_site = get_site_by_path( $from_domain, '/' );
		if ( ! $from_site ) {
			WP_CLI::error( sprintf( 'No site with the domain %s was found. Aborting.', $from_domain ) );
			return;
		}

		$to_site = get_site_by_path( $to_domain, '/' );
		if ( $to_site ) {
			WP_CLI::error( sprintf( 'An existing site was found with the domain %s. Aborting.', $to_domain ) );
		}

		// Blog-specific tables first.
		$base_args = array( 'search-replace', $from_domain, $to_domain );
		$base_assoc_args = array( 'skip-columns' => 'guid', 'precise' => 1 );
		if ( isset( $assoc_args['dry-run'] ) ) {
			$base_assoc_args['dry-run'] = 1;
		}

		$blog_tables = $wpdb->get_col( "SHOW TABLES LIKE '" . like_escape( $wpdb->get_blog_prefix( $from_site->blog_id ) ) . "%'" );
		$_args = array_merge( $base_args, $blog_tables );
		$_assoc_args = $base_assoc_args;

		WP_CLI::run_command( $_args, $_assoc_args );

		// Global tables next.
		$global_tables = array_merge( $wpdb->global_tables, $wpdb->ms_global_tables );
		foreach ( $global_tables as &$global_table ) {
			$global_table = $wpdb->base_prefix . $global_table;
		}

		if ( function_exists( 'buddypress' ) ) {
			$bp_prefix = bp_core_get_table_prefix() . 'bp_';
			$bp_prefix = esc_sql( $bp_prefix ); // just in case....
			$bp_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s%', $bp_prefix ) );

			if ( $bp_tables ) {
				$global_tables = array_merge( $global_tables, $bp_tables );
			}
		}

		$_args = array_merge( $base_args, $global_tables );
		$_assoc_args = $base_assoc_args;

		WP_CLI::run_command( $_args, $_assoc_args );

		WP_CLI::success( 'Domains switched!' );
		WP_CLI::error( 'wp-cli cannot flush site caches, so make sure to do it yourself!' );
	}

	/**
	 * Set up the update blacklist, based on arguments passed to the command.
	 *
	 * @param array $assoc_args Associative argument array.
	 */
	protected function set_up_blacklist( $assoc_args ) {
		if ( isset( $assoc_args['exclude-plugins'] ) ) {
			$this->update_blacklist['plugin'] = array_filter( explode( ',', $assoc_args['exclude-plugins'] ) );
		} else {
			$this->update_blacklist['plugin'] = $this->do_not_update['plugin'];
		}

		if ( isset( $assoc_args['exclude-themes'] ) ) {
			$this->update_blacklist['theme'] = array_filter( explode( ',', $assoc_args['exclude-themes'] ) );
		} else {
			$this->update_blacklist['theme'] = $this->do_not_update['theme'];
		}
	}

	protected function maybe_register_gh_command() {
		$root = WP_CLI::get_root_command();
		$commands = $root->get_subcommands();
		if ( ! isset( $commands['gh'] ) ) {
			WP_CLI::add_command( 'gh', '\boonebgorges\WPCLIGitHelper\Command' );
		}
	}
}
