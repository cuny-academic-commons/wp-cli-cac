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
			'accordion-slider-lite', // #16654
			'buddypress-group-documents',
			'bp-groupblog',
			'bp-import-blog-activity',
			'cac-group-admin-auto-add',
			'elementor',
			'event-organiser-ical-sync',
			'event-tickets',
			'forum-attachments-for-buddypress',
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
	 * Only perform minor updates on these items.
	 */
	protected $do_not_update_major = [
		'plugin' => [
			'events-tickets',
			'the-events-calendar',
		],
		'theme' => [],
	];

	/**
	 * Items whose updates should trigger specific notifications.
	 */
	protected $notify_on_update = [
		'plugin' => [
			'ml-slider' => 'Apply manual patch to metaslider_plugin_is_installed()',
		],
		'theme'  => [],
	];

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
	 *
	 * [--skip-post]
	 * : Skip creating a draft blog post.
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

		$this->set_up_blacklist( $assoc_args, 'major' );

		foreach ( $types as $type ) {
			$data = $this->prepare_major_update_for_type( $type );
			WP_CLI::log( sprintf( "Identified %s items of type '%s' with major updates available.", count( $data ), $type ) );
			$update_data['data'][ $type ] = $data;
		}

		$json_path = ABSPATH . '.cac-major-update.json';

		$update_data['header'] = sprintf( 'CAC major upgrades for %s', $assoc_args['date'] );
		file_put_contents( $json_path, json_encode( $update_data, JSON_PRETTY_PRINT ) );
		WP_CLI::log( sprintf( 'Saved results to %s.', $json_path ) );

		if ( isset( $assoc_args['skip-post'] ) ) {
			$this->create_major_update_blog_post( $update_data, $assoc_args );
		}

		WP_CLI::log( 'Don\'t forget to manually check WooThemes for available updates.' );
		WP_CLI::log( 'Also, don\'t forget to manually check https://wpcom-themes.svn.automattic.com for updates to "imbalance2" and "manifest".' );
		WP_CLI::log( 'Also, don\'t forget to manually check http://themetrust.com for updates to "reveal".' );
		WP_CLI::log( 'Also, don\'t forget to manually check https://themify.me/themes/basic for updates to "basic".' );
		WP_CLI::log( 'Also, don\'t forget to manually check https://elegantthemes.com for updates to "DailyNotes", "ArtSee", "13Floor", "eNews", "Lucid", "Basic", "Cion", "AskIt".' );
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

		$this->set_up_blacklist( $assoc_args, 'minor' );

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
	 * Create a draft blog post for major updates via the WordPress REST API.
	 *
	 * @param array $update_data Update data from prepare_major_update.
	 * @param array $assoc_args Command arguments.
	 */
	protected function create_major_update_blog_post( $update_data, $assoc_args ) {
		// Generate blog post content
		$blog_post = $this->generate_major_update_blog_post( $update_data, $assoc_args );

		// Authentication setup
		$rest_url = defined('CAC_UPDATE_REST_URL') ? CAC_UPDATE_REST_URL : 'https://dev.commons.gc.cuny.edu/wp-json';
		$app_password = defined('CAC_UPDATE_APP_PASSWORD') ? CAC_UPDATE_APP_PASSWORD : '';
		$app_username = defined('CAC_UPDATE_APP_USERNAME') ? CAC_UPDATE_APP_USERNAME : '';

		if ( empty( $app_password ) || empty( $app_username ) ) {
			WP_CLI::warning( 'App password or username not configured. Define CAC_UPDATE_APP_USERNAME and CAC_UPDATE_APP_PASSWORD constants to enable automatic post creation.' );
			return false;
		}

		// Collect all plugin/theme slugs for tags
		$tag_slugs = array();
		foreach ( $update_data['data'] as $type => $items ) {
			foreach ( $items as $item ) {
				$tag_slugs[] = $item['name'];
			}
		}

		// Add additional required tags
		$version_parts = explode('.', $assoc_args['version']);
		$release_series = $version_parts[0] . '.' . $version_parts[1] . '.x';
		$release_name = $assoc_args['version'];

		$additional_tags = array(
			$release_series,
			$release_name,
			'major-update-releases'
		);

		$tag_slugs = array_merge($tag_slugs, $additional_tags);

		// Get existing tags
		$existing_tags = $this->get_existing_tags( $rest_url, $app_username, $app_password );

		// Process tags (use existing or create new ones)
		$post_tags = $this->process_tags( $tag_slugs, $existing_tags, $rest_url, $app_username, $app_password );

		// Convert content to Gutenberg blocks
		$block_content = $this->convert_to_gutenberg_blocks($blog_post['text']);

		// Create draft post
		$post_data = array(
			'title'      => $blog_post['title'],
			'content'    => $block_content,
			'status'     => 'draft',
			'tags'       => $post_tags,
			'categories' => [ 9 ], // 'Updates'
		);

		$response = $this->create_post( $post_data, $rest_url, $app_username, $app_password );

		if ( is_wp_error( $response ) ) {
			WP_CLI::error( sprintf( 'Failed to create draft post: %s', $response->get_error_message() ) );
			return false;
		}

		$post_id = $response['id'];
		$edit_url = str_replace(parse_url($rest_url, PHP_URL_HOST), 'dev.commons.gc.cuny.edu', $rest_url);
		$edit_url = preg_replace('|/wp-json.*|', '', $edit_url) . '/wp-admin/post.php?post=' . $post_id . '&action=edit';

		WP_CLI::success( sprintf( 'Draft post created! Edit it here: %s', $edit_url ) );

		return true;
	}

	/**
	 * Convert HTML content to Gutenberg blocks format while preserving order.
	 *
	 * @param string $content HTML content.
	 * @return string Content in Gutenberg blocks format.
	 */
	protected function convert_to_gutenberg_blocks($content) {
		// Initialize block content
		$block_content = '';

		// Use DOMDocument to properly parse the HTML
		$dom = new DOMDocument();

		$content = mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8');

		// Prevent HTML5 parsing errors by using LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		// and wrapping content in a temporary root element
		libxml_use_internal_errors(true); // Suppress warnings for invalid HTML
		$dom->loadHTML('<div id="temp-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();

		// Get the root element (our temp div)
		$root = $dom->getElementById('temp-root');

		// Process each child node in order
		if ($root && $root->hasChildNodes()) {
			foreach ($root->childNodes as $node) {
				// Skip text nodes that are just whitespace
				if ($node->nodeType === XML_TEXT_NODE && trim($node->textContent) === '') {
					continue;
				}

				if ($node->nodeType === XML_ELEMENT_NODE) {
					$tag_name = strtolower($node->nodeName);

					// Handle different element types
					switch ($tag_name) {
						case 'p':
							$inner_html = $dom->saveHTML($node);
							$block_content .= '<!-- wp:paragraph -->' . PHP_EOL;
							$block_content .= $inner_html . PHP_EOL;
							$block_content .= '<!-- /wp:paragraph -->' . PHP_EOL . PHP_EOL;
							break;

						case 'ul':
							$inner_html = $dom->saveHTML($node);
							$block_content .= '<!-- wp:list -->' . PHP_EOL;
							$block_content .= $inner_html . PHP_EOL;
							$block_content .= '<!-- /wp:list -->' . PHP_EOL . PHP_EOL;
							break;

						case 'ol':
							$inner_html = $dom->saveHTML($node);
							$block_content .= '<!-- wp:list {"ordered":true} -->' . PHP_EOL;
							$block_content .= $inner_html . PHP_EOL;
							$block_content .= '<!-- /wp:list -->' . PHP_EOL . PHP_EOL;
							break;

						case 'h1':
						case 'h2':
						case 'h3':
						case 'h4':
						case 'h5':
						case 'h6':
							$level = substr($tag_name, 1);
							$inner_html = $dom->saveHTML($node);
							$block_content .= '<!-- wp:heading {"level":' . $level . '} -->' . PHP_EOL;
							$block_content .= $inner_html . PHP_EOL;
							$block_content .= '<!-- /wp:heading -->' . PHP_EOL . PHP_EOL;
							break;

						default:
							// For any other HTML elements, wrap in an HTML block
							$inner_html = $dom->saveHTML($node);
							$block_content .= '<!-- wp:html -->' . PHP_EOL;
							$block_content .= $inner_html . PHP_EOL;
							$block_content .= '<!-- /wp:html -->' . PHP_EOL . PHP_EOL;
							break;
					}
				} elseif ($node->nodeType === XML_TEXT_NODE && trim($node->textContent) !== '') {
					// For plain text nodes with content, wrap in paragraph blocks
					$block_content .= '<!-- wp:paragraph -->' . PHP_EOL;
					$block_content .= '<p>' . htmlspecialchars($node->textContent) . '</p>' . PHP_EOL;
					$block_content .= '<!-- /wp:paragraph -->' . PHP_EOL . PHP_EOL;
				}
			}
		}

		// If we couldn't process any blocks (perhaps due to malformed HTML),
		// fall back to wrapping the entire content in a paragraph block
		if (empty($block_content)) {
			$block_content = '<!-- wp:paragraph -->' . PHP_EOL;
			$block_content .= '<p>' . $content . '</p>' . PHP_EOL;
			$block_content .= '<!-- /wp:paragraph -->' . PHP_EOL;
		}

		return $block_content;
	}

	/**
	 * Process tags - use existing ones or create new ones as needed.
	 *
	 * @param array $tag_slugs Tag slugs to process.
	 * @param array $existing_tags Existing tags from the site.
	 * @param string $rest_url Base REST API URL.
	 * @param string $username Username for authentication.
	 * @param string $password App password for authentication.
	 * @return array Array of tag IDs.
	 */
	protected function process_tags( $tag_slugs, $existing_tags, $rest_url, $username, $password ) {
		$tag_ids = array();

		foreach ( $tag_slugs as $slug ) {
			$tag_id = null;

			// Sanitize the slug
			$slug = sanitize_title($slug);

			// Look for existing tag with matching slug
			foreach ( $existing_tags as $tag ) {
				if ( $tag['slug'] === $slug ) {
					$tag_id = $tag['id'];
					break;
				}
			}

			// Create new tag if not found
			if ( null === $tag_id ) {
				$new_tag = $this->create_tag( $slug, $rest_url, $username, $password );
				if ( $new_tag && isset( $new_tag['id'] ) ) {
					$tag_id = $new_tag['id'];
				}
			}

			if ( $tag_id ) {
				$tag_ids[] = $tag_id;
			}
		}

		return $tag_ids;
	}

	/**
	 * Create a new tag.
	 *
	 * @param string $slug Tag slug.
	 * @param string $rest_url Base REST API URL.
	 * @param string $username Username for authentication.
	 * @param string $password App password for authentication.
	 * @return array|bool Tag data on success, false on failure.
	 */
	protected function create_tag( $slug, $rest_url, $username, $password ) {
		$tags_endpoint = $rest_url . '/wp/v2/tags';

		// Make plugin/theme slug more readable for the tag name
		$name = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );

		// Special case for some specific tags
		if ($slug === 'major-update-releases') {
			$name = 'Major Update Releases';
		} elseif (preg_match('/^[\d\.]+x$/', $slug)) {
			// For version series like "2.5.x"
			$name = $slug; // Keep the original format
		} elseif (preg_match('/^[\d\.]+$/', $slug)) {
			// For version numbers like "2.5.6"
			$name = $slug; // Keep the original format
		}

		$response = wp_remote_post(
			$tags_endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
					'Content-Type'  => 'application/json',
				),
				'body' => json_encode( array(
					'name' => $name,
					'slug' => $slug,
				) ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_CLI::warning( sprintf( 'Failed to create tag "%s": %s', $slug, $response->get_error_message() ) );
			return false;
		}

		$tag_data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $tag_data ) || !isset( $tag_data['id'] ) ) {
			WP_CLI::warning( sprintf( 'Failed to create tag "%s"', $slug ) );
			return false;
		}

		return $tag_data;
	}

	/**
	 * Get existing tags from WordPress.
	 *
	 * @param string $rest_url Base REST API URL.
	 * @param string $username Username for authentication.
	 * @param string $password App password for authentication.
	 * @return array Existing tags.
	 */
	protected function get_existing_tags( $rest_url, $username, $password ) {
		$tags = array();
		$page = 1;
		$per_page = 100;
		$more_tags = true;

		while ( $more_tags ) {
			$tags_endpoint = $rest_url . '/wp/v2/tags?per_page=' . $per_page . '&page=' . $page;

			$response = wp_remote_get(
				$tags_endpoint,
				array(
					'headers' => array(
						'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
					),
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) ) {
				WP_CLI::warning( sprintf( 'Failed to fetch tags: %s', $response->get_error_message() ) );
				return $tags;
			}

			$response_tags = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( empty( $response_tags ) || !is_array( $response_tags ) ) {
				$more_tags = false;
			} else {
				$tags = array_merge( $tags, $response_tags );
				$page++;

				// Check if we've reached the last page
				$total_pages = wp_remote_retrieve_header( $response, 'X-WP-TotalPages' );
				if ( $page > intval( $total_pages ) ) {
					$more_tags = false;
				}
			}
		}

		return $tags;
	}

	/**
	 * Create a post via the REST API.
	 *
	 * @param array $post_data Post data.
	 * @param string $rest_url Base REST API URL.
	 * @param string $username Username for authentication.
	 * @param string $password App password for authentication.
	 * @return array|WP_Error Post data on success, WP_Error on failure.
	 */
	protected function create_post( $post_data, $rest_url, $username, $password ) {
		$posts_endpoint = $rest_url . '/wp/v2/posts';

		$response = wp_remote_post(
			$posts_endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
					'Content-Type'  => 'application/json',
				),
				'body' => json_encode( array(
					'title'    => $post_data['title'],
					'content'  => $post_data['content'],
					'status'   => $post_data['status'],
					'tags'     => $post_data['tags'],
				) ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 || $response_code >= 300 ) {
			return new WP_Error(
				'rest_api_error',
				sprintf( 'REST API returned status code %d: %s', $response_code, wp_remote_retrieve_body( $response ) )
			);
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
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

		$updates = [];
		$notify  = [];
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

			// If this needs a notification.
			if ( isset( $this->notify_on_update[ $type ][ $item->name ] ) ) {
				$notify[] = $item->name;
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

		foreach ( $notify as $notify_item => $notify_action ) {
			WP_CLI::warning( sprintf( 'The following %s has been updated and needs attention: %s. Action: %s', $type, $notify_item, $notify_action ) );
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
		$base_assoc_args = array( 'skip-columns' => 'guid', 'precise' => 1, 'all-tables' => 1 );
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
	 * @param string $type Type of update. 'major' or 'minor'.
	 */
	protected function set_up_blacklist( $assoc_args, $type ) {
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

		if ( 'major' === $type ) {
			$this->update_blacklist['plugin'] = array_merge( $this->update_blacklist['plugin'], $this->do_not_update_major['plugin'] );
			$this->update_blacklist['theme'] = array_merge( $this->update_blacklist['theme'], $this->do_not_update_major['theme'] );
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
