<?php

/*
 * Plugin name: QIT Validation Plugin
 */

/*
 * Register a WP_CLI command called "qit-validate" with "--type" and "--slug" arguments.
 * This will get plugin metadata or theme metadata using native WordPress Functions,
 * and will save the raw JSON array in /var/www/html/validation/validation.json
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

WP_CLI::add_command( 'qit-validate', 'QIT_Validation_Command' );

class QIT_Validation_Command {
	/**
	 * Validates a plugin or theme by saving its metadata to a file.
	 *
	 * ## OPTIONS
	 *
	 * --type=<type>
	 * : The type of metadata to fetch ('plugin' or 'theme').
	 *
	 * --slug=<slug>
	 * : The slug of the plugin or theme.
	 * 
	 * --sut_directory=<sut_directory>
	 * : The name for the top level folder in the SUT zip.
	 * 
	 * --sut_entry_point=<sut_entry_point>
	 * : The name of the main PHP file in the SUT.
	 *
	 * --output=<output>
	 * : The output file to save the results in.
	 *
	 * ## EXAMPLES
	 *
	 *     wp qit-validate --type=plugin --slug=woocommerce
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		// Not in use yet, but we'll want these eventually.
		// These use the same key as the value.
		$add_custom_headers = function( $headers ) {
			$headers[] = 'WC requires at least';
			$headers[] = 'WC tested up to';
			$headers[] = 'Woo';
			$headers[] = 'Tested up to'; // note: often in the readme instead, which we also parse with get_file_data().
			$headers[] = 'License'; // note: not actually returned by get_plugin_data() by default.

			return $headers;
		};

		add_filter( 'extra_theme_headers', $add_custom_headers );
		add_filter( 'extra_plugin_headers', $add_custom_headers );

		$type            = $assoc_args['type'];
		$slug            = $assoc_args['slug'];
		$sut_directory   = $assoc_args['sut_directory'];
		$sut_entry_point = $assoc_args['sut_entry_point'];
		$output          = $assoc_args['output'];

		// WP returns header info keyed by subtly different keys than the header itself.
		// We map them back to the header itself here so that all the downstream code doesn't need to worry.
		$required_headers = [
			'Requires PHP'         => 'RequiresPHP',
			'Requires at least'    => 'RequiresWP',
			'Tested up to'         => 'Tested up to',
			'WC requires at least' => 'WC requires at least',
			'WC tested up to'      => 'WC tested up to',
			'Woo'                  => 'Woo',
			'License'              => 'License',
		];

		$results = [
			'headers' => [],
			'features' => [],
			'has_outdated_templates' => false,
			'has_readme' => false,
		];

		$readme_file = $this->get_readme_file( $type, $slug, $sut_directory );

		if ( file_exists( $readme_file ) ) {
			$results['has_readme'] = true;
		}

		// get_file_data() wants HeaderKey => Header Name, so we array_flip our required headers to put it in that format.
		$file_data = $this->get_extension_metadata( $type, $slug, $sut_directory, $sut_entry_point );
		$readme_data = $this->get_readme_metadata( $type, $slug, $sut_directory, array_flip( $required_headers ) );

		// take any non-empty value; if both are set, use the PHP file data in preference.
		$data = [];
		foreach ( $required_headers as $header_name => $header_key ) {
			if ( ! empty( $file_data[ $header_key ] ) ) {
				$data[ $header_key ] = $file_data[ $header_key ];
			} else if ( ! empty( $readme_data[ $header_key ] ) ) {
				$data[ $header_key ] = $readme_data[ $header_key ];
			} else {
				$data[ $header_key ] = false;
			}
		}

		if ( ! empty( $data ) ) {
			foreach ( $required_headers as $header_name => $header_key ) {
				if ( empty( $data[ $header_key ] ) ) {
					$results['headers'][ $header_name ] = false;
				} else {
					$results['headers'][ $header_name ] = $data[ $header_key ];
				}
			}

			if ( $type === 'plugin' && class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				// Get WC feature compatibility declarations.
				// Only run for plugins (themes are hard to activate due to parents) and be defensive about the class we need being loaded.
				// Just return the WC API result here (array with compatible, incompatible, uncertain sub-arrays) and let the manager code decide what matters.
				$results['features'] = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_features_for_plugin( $slug . '/' . $slug . '.php' );
			}

			if ( $type === 'theme' ) {
				// rest_do_request skips general auth, but we still need to pass the WC check
				add_filter( 'woocommerce_rest_check_permissions', '__return_true' );

				// there's no internal library we can call for this short of the endpoint itself;
				// doing an internal REST request is easier and better than instantiating the controller
				$request = new WP_REST_Request( 'GET', '/wc/v3/system_status' );

				// limit to the theme info; this endpoint doesn't like the sqlite database so that part fails if we request all the info
				$request->set_param( '_fields', [ 'theme' ] );
				$response = rest_do_request( $request );

				// clean up our filter just in case
				remove_filter( 'woocommerce_rest_check_permissions', '__return_true' );

				if ( ! $response->is_error() ) {
					$data = $response->get_data();

					if ( isset( $data['theme']['has_outdated_templates'] ) ) {
						$results['has_outdated_templates'] = $data['theme']['has_outdated_templates'];
					}
				}
			}

			$directory = dirname( $output );
			if ( ! file_exists( $directory ) ) {
				mkdir( $directory, 0777, true );
			}

			file_put_contents( $output, json_encode( $results, JSON_PRETTY_PRINT ) );
			WP_CLI::success( "Metadata saved to {$output}." );
		} else {
			// Probably not a plugin or theme.
			WP_CLI::error( "No data found for the specified {$type} with slug '{$slug}'." );
		}
	}

	protected function get_extension_metadata( $type, $slug, $sut_directory, $sut_entry_point ): array {
		$data = [];

		if ( $type === 'plugin' ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugin_file = WP_PLUGIN_DIR . '/' . $sut_directory . '/' . $sut_entry_point;
			if ( file_exists( $plugin_file ) ) {
				$data = get_plugin_data( $plugin_file );
			} else {
				// inspired by find_theme_or_plugin_entry_point(), search the rest of the PHP files in the directory for one with headers
				$plugin_dir = WP_PLUGIN_DIR . '/' . $sut_directory . '/';
				foreach ( glob( $plugin_dir . '*.php' ) as $file ) {
					$data = get_plugin_data( $file );
					if ( ! empty( $data ) ) {
						break;
					}
				}
			}
		} elseif ( $type === 'theme' ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				// Use Reflection to get all private properties in $theme->header and add them to the $data array.
				$reflection = new ReflectionClass( $theme );
				if ( $reflection->hasProperty( 'headers' ) ) {
					$property = $reflection->getProperty( 'headers' );
					$property->setAccessible( true ); // Make the private property accessible
					$headers = $property->getValue( $theme ); // Get the value of the 'headers' property

					if ( is_array( $headers ) ) {
						foreach ( $headers as $key => $value ) {
							$data[ $key ] = $value; // Add each header to the $data array
						}
					}
				}
			}
		}

		return $data;
	}

	protected function get_readme_metadata( $type, $slug, $sut_directory, $headers ): array {
		$data = [];

		if ( ! function_exists( 'get_file_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/functions.php';
		}

		$readme_file = $this->get_readme_file( $type, $slug, $sut_directory );

		if ( file_exists( $readme_file ) ) {
			$data = get_file_data( $readme_file, $headers, $type );
		}

		return $data;
	}

	protected function get_readme_file( $type, $slug, $sut_directory ) {
		if ( $type === 'plugin' ) {
			return WP_PLUGIN_DIR . '/' . $sut_directory . '/readme.txt';
		} elseif ( $type === 'theme' ) {
			return WP_CONTENT_DIR . '/themes/' . $sut_directory . '/readme.txt';
		}
	}
}
