<?php
/*
 * Individual image caches are not being used for now due to 429 in actions/cache@v4
 */
/*
$docker_php_cache = <<<'YML'
      - name: Restore PHP Image Cache - Step 1
        if: env.QIT_DOCKER_PHP == 'yes'
        id: cache-docker-php
        uses: actions/cache@v4
        with:
          fail-on-cache-miss: false
          path: ci/cache/docker/php-${{ github.event.client_payload.shared_matrix_data.php_version }}
          key: ${{ env.daily-cache-burst }}-cache-docker-php-${{ github.event.client_payload.shared_matrix_data.php_version }}

      - name: Restore PHP Image Cache - Step 2
        if: env.QIT_DOCKER_PHP == 'yes'
        run: docker image load --input ./ci/cache/docker/php-${{ github.event.client_payload.shared_matrix_data.php_version }}/image.tar
YML;

$docker_mysql_cache = <<<'YML'
      - name: Restore MySQL Image Cache - Step 1
        if: env.QIT_DOCKER_MYSQL == 'yes'
        id: cache-docker-mysql
        uses: actions/cache@v4
        with:
          fail-on-cache-miss: false
          path: ci/cache/docker/mysql
          key: ${{ env.daily-cache-burst }}-cache-docker-mysql

      - name: Restore MySQL Image Cache - Step 2
        if: env.QIT_DOCKER_MYSQL == 'yes'
        run: docker image load --input ./ci/cache/docker/mysql/image.tar
YML;

$docker_redis_cache = <<<'YML'
      - name: Restore Redis Image Cache - Step 1
        if: env.QIT_DOCKER_REDIS == 'yes'
        id: cache-docker-redis
        uses: actions/cache@v4
        with:
          fail-on-cache-miss: false
          path: ci/cache/docker/redis
          key: ${{ env.daily-cache-burst }}-cache-docker-redis

      - name: Restore Redis Image Cache - Step 2
        if: env.QIT_DOCKER_REDIS == 'yes'
        run: docker image load --input ./ci/cache/docker/redis/image.tar
YML;

$docker_nginx_cache = <<<'YML'
      - name: Restore Nginx Image Cache - Step 1
        if: env.QIT_DOCKER_NGINX == 'yes'
        id: cache-docker-nginx
        uses: actions/cache@v4
        with:
          fail-on-cache-miss: false
          path: ci/cache/docker/nginx
          key: ${{ env.daily-cache-burst }}-cache-docker-nginx

      - name: Restore Nginx Image Cache - Step 2
        if: env.QIT_DOCKER_NGINX == 'yes'
        run: docker image load --input ./ci/cache/docker/nginx/image.tar
YML;
*/

$docker_bundle_cache = <<<'YML'
      - name: Restore Bundle Docker Image Cache
        id: cache-docker-bundle
        uses: actions/cache@v4
        with:
          fail-on-cache-miss: false
          path: ci/cache/docker/bundle/php-${{ github.event.client_payload.shared_matrix_data.php_version }}
          key: ${{ env.daily-cache-burst }}-cache-docker-bundle-php-${{ github.event.client_payload.shared_matrix_data.php_version }}
          restore-keys: | # Allow to restore from last day cache if current day doesn't exist.
            ${{ env.yesterday-cache-burst }}-cache-docker-bundle-php-${{ github.event.client_payload.shared_matrix_data.php_version }}

      - name: Restore Redis Image from Bundle
        if: env.QIT_DOCKER_REDIS == 'yes' && steps.cache-docker-bundle.outputs.cache-hit == 'true'
        run: docker image load --input ./ci/cache/docker/bundle/php-${{ github.event.client_payload.shared_matrix_data.php_version }}/redis/image.tar

      - name: Restore MySQL Image from Bundle
        if: env.QIT_DOCKER_MYSQL == 'yes' && steps.cache-docker-bundle.outputs.cache-hit == 'true'
        run: docker image load --input ./ci/cache/docker/bundle/php-${{ github.event.client_payload.shared_matrix_data.php_version }}/mysql/image.tar

      - name: Restore PHP Image from Bundle
        if: env.QIT_DOCKER_PHP == 'yes' && steps.cache-docker-bundle.outputs.cache-hit == 'true'
        run: docker image load --input ./ci/cache/docker/bundle/php-${{ github.event.client_payload.shared_matrix_data.php_version }}/php-${{ github.event.client_payload.shared_matrix_data.php_version }}/image.tar

      - name: Restore Nginx Image from Bundle
        if: env.QIT_DOCKER_NGINX == 'yes' && steps.cache-docker-bundle.outputs.cache-hit == 'true'
        run: docker image load --input ./ci/cache/docker/bundle/php-${{ github.event.client_payload.shared_matrix_data.php_version }}/nginx/image.tar

      - name: Restore Playwright-Related Images from Bundle
        if: env.QIT_DOCKER_PLAYWRIGHT == 'yes' && steps.cache-docker-bundle.outputs.cache-hit == 'true'
        run: |
            docker image load --input ./ci/cache/docker/bundle/php-${{ github.event.client_payload.shared_matrix_data.php_version }}/playwright/image.tar
            docker image load --input ./ci/cache/docker/bundle/php-${{ github.event.client_payload.shared_matrix_data.php_version }}/zip/image.tar
            docker image load --input ./ci/cache/docker/bundle/php-${{ github.event.client_payload.shared_matrix_data.php_version }}/busybox/image.tar
YML;

$header = <<<'YML'
      - name: Set dynamic environment variables.
        run: |
          echo "TEST_TYPE_CACHE_DIR=$GITHUB_WORKSPACE/ci/cache/test-type/${{ matrix.test_run.test_type }}" >> $GITHUB_ENV
          echo "WP_CLI_CACHE_DIR=$GITHUB_WORKSPACE/ci/cache/test-type/${{ matrix.test_run.test_type }}/wp-cli" >> $GITHUB_ENV
          echo "PLAYWRIGHT_BROWSERS_PATH=$GITHUB_WORKSPACE/ci/cache/test-type/${{ matrix.test_run.test_type }}/playwright-browsers" >> $GITHUB_ENV
        
      - name: Plugin Under Test - ${{ matrix.test_run.sut_name }}
        run: echo "Starting test for plugin \"${{ matrix.test_run.sut_name }}\""
        
      - name: Generate a unique id
        id: gen-id
        if: always()
        run: |
          echo "rand=$(openssl rand -hex 3)" >> "$GITHUB_OUTPUT"
          
      - name: Remove special characters from JOB_NAME
        if: always()
        run: |
          echo "CLEAN_JOB_NAME=$(echo $JOB_NAME | tr -cd '[:alnum:]')" >> $GITHUB_ENV
        
      - name: Checkout code.
        uses: actions/checkout@v4
YML;

$test_type_cache = <<<'YML'
      - name: Restore Test Type Cache (WP CLI Cache (WP zips, plugins zips, etc), Playwright cache if needed, etc)
        id: cache-qit-daily-test-type
        uses: actions/cache@v4
        with:
          fail-on-cache-miss: false
          path: ci/cache/test-type
          key: ${{ env.daily-cache-burst }}-test-type-${{ matrix.test_run.test_type }}-${{ matrix.test_run.environment.woocommerce_version }}-${{ matrix.test_run.environment.wordpress_version }}
          restore-keys: | # Allow to restore from last day cache if current day doesn't exist.
            ${{ env.yesterday-cache-burst }}-test-type-${{ matrix.test_run.test_type }}-${{ matrix.test_run.environment.woocommerce_version }}-${{ matrix.test_run.environment.wordpress_version }}
YML;

$job_env = <<<'YML'
      PHP_EXTENSIONS: ${{ toJSON( matrix.test_run.environment.php_extensions ) }}
      QIT_ENABLE_HPOS: ${{ toJSON( matrix.test_run.environment.optional_features.hpos ) }}
      QIT_ENABLE_NEW_PRODUCT_EDITOR: ${{ toJSON( matrix.test_run.environment.optional_features.new_product_editor ) }}
      PHP_VERSION: ${{ github.event.client_payload.shared_matrix_data.php_version }}
      WOOCOMMERCE_VERSION: ${{ matrix.test_run.environment.woocommerce_version }}
      WORDPRESS_VERSION: ${{ matrix.test_run.environment.wordpress_version }}
      SUT_SLUG: ${{ matrix.test_run.sut_slug }}
      SUT_WOO_ID: ${{ matrix.test_run.sut_woo_id }}
      TEST_TYPE: ${{ matrix.test_run.test_type }}
      SUT_VERSION: Undefined
      JOB_NAME: __JOB_NAME__
YML;

$daily_cache_burst = <<<'YML'
      - name: Daily Cache Burst.
        run: echo "daily-cache-burst=$(date +'%Y-%m-%d')" >> $GITHUB_ENV
        
      - name: Yesterday Cache Burst.
        run: echo "yesterday-cache-burst=$(date -u -d 'yesterday' +'%Y-%m-%d')" >> $GITHUB_ENV
YML;

$sut_version = <<<'YML'
      - name: Get SUT version (Debug)
        if: always()
        run: ls -la $GITHUB_WORKSPACE/ci/${{ matrix.test_run.sut_type }}s && ls -la $GITHUB_WORKSPACE/ci/${{ matrix.test_run.sut_type }}s/${{ steps.find_entry.outputs.plugin_directory }}
        
      - name: Get SUT version (Debug)
        if: always()
        id: sut-version
        working-directory: ci/cli
        env:
          PLUGIN_DIRECTORY: ${{ github.workspace }}/ci/${{ matrix.test_run.sut_type }}s/${{ steps.find_entry.outputs.plugin_directory }}
          PLUGIN_TYPE: ${{ matrix.test_run.sut_type }}
          PLUGIN_SLUG: ${{ steps.find_entry.outputs.entry_point }}
        run: php src/cli.php header -o Version
YML;

$update_test_status = <<<'YML'
      - name: Notify Manager That Test Has Started
        id: test-in-progress
        working-directory: ci/cli
        env:
          TEST_RUN_ID: ${{ matrix.test_run.test_run_id }}
          CI_SECRET: ${{ secrets.CI_SECRET }}
          CI_STAGING_SECRET: ${{ secrets.CI_STAGING_SECRET }}
          MANAGER_HOST: ${{ github.event.client_payload.shared_matrix_data.manager_host }}
          RESULTS_ENDPOINT: ${{ github.event.client_payload.shared_matrix_data.in_progress_endpoint }}
          TEST_RUN_HASH: ${{ matrix.test_run.custom_payload.hash }}
          WORKFLOW_ID: ${{ github.run_id }}
          CANCELLED: ${{ steps.workflow-cancelled.outputs.cancelled }}
        run: php src/cli.php notify -s
YML;

$download_plugins = <<<'YML'
      - name: Create Plugin Directories
        working-directory: ci
        run: mkdir -p plugins & mkdir -p themes
      
      - name: Download Plugin Activation Stack
        working-directory: ci/cli
        env:
          TOKEN: ${{ secrets.TOKEN }}
          ACCEPT_HEADER: ${{ secrets.ACCEPT_HEADER }}
          WOO_DOWNLOAD_URL: ${{ secrets.WOO_DOWNLOAD_URL }}
          SHA_URL: ${{ secrets.SHA_URL }}
          CI_ENCRYPTION_KEY: ${{ secrets.CI_ENCRYPTION_KEY }}
          SHA_POSTFIELDS: ${{ secrets.SHA_POSTFIELDS }}
          PLUGINS_ZIPS: ${{ toJSON( github.event.client_payload.shared_matrix_data.plugin_zips ) }}
          PLUGINS_JSON: ${{ toJSON( matrix.test_run.plugins ) }}
          THEME_DIRECTORY: ${{ github.workspace }}/ci/themes
          PLUGIN_DIRECTORY: ${{ github.workspace }}/ci/plugins
          SKIP_CACHE: true
        run: php src/cli.php download-plugins
YML;

$cli_setup = <<<'YML'
      - name: CI CLI Cache
        id: cache-ci-cli
        uses: actions/cache@v4
        with:
          path: ci/cli/vendor
          key: ${{ hashFiles( 'ci/cli/composer.lock' ) }}

      - name: CI CLI Setup
        if: steps.cache-ci-cli.outputs.cache-hit != 'true'
        working-directory: ci/cli
        run: composer install --no-interaction --no-progress --no-suggest --prefer-dist --optimize-autoloader --no-dev
YML;

$debug_plugin_lists = <<< 'YML'
      - name: Debug Plugin List
        if: ${{ matrix.test_run.sut_type != 'theme' }}
        working-directory: ci/bin
        run: docker exec --user=www-data ci_runner_php_fpm bash -c "wp plugin list"

      - name: Debug Theme List
        if: ${{ matrix.test_run.sut_type == 'theme' }}
        working-directory: ci/bin
        run: docker exec --user=www-data ci_runner_php_fpm bash -c "wp theme list"
YML;

$theme_activation = <<< 'YML'
      - name: Maybe Install Parent Theme
        if: ${{ matrix.test_run.sut_type == 'theme' }}
        working-directory: ci/cli
        env:
          PLUGIN_DIRECTORY: ${{ github.workspace }}/ci/themes/${{ steps.find_entry.outputs.plugin_directory }}
          PLUGIN_ENTRYPOINT: ${{ github.workspace }}/ci/themes/${{ steps.find_entry.outputs.plugin_directory }}/style.css
        run: php src/cli.php theme -p

      - name: Maybe Activate Theme
        if: ${{ matrix.test_run.sut_type == 'theme' && matrix.test_run.test_type != 'activation' }}
        working-directory: ci/bin
        run: docker exec --user=www-data ci_runner_php_fpm bash -c "wp theme activate ${{ matrix.test_run.sut_slug }}"
YML;

$setup_wordpress = <<< 'YML'
      - name: Setup WordPress
        working-directory: ci/bin
        env:
          PLUGINS_JSON: ${{ toJSON( matrix.test_run.plugins ) }}
        run: ./wordpress-setup.sh
YML;

$find_plugin_entrypoint = <<< 'YML'
      - name: Identify Plugin or Theme Entry Point
        working-directory: ci/bin
        id: find_entry
        env:
          PARENT_DIRECTORY: ${{ github.workspace }}/ci/${{ matrix.test_run.sut_type }}s
          PLUGIN_TYPE: ${{ matrix.test_run.sut_type }}
          PLUGIN_SLUG: ${{ matrix.test_run.sut_slug }}
          GITHUB_OUTPUT: $GITHUB_OUTPUT
        run: php find-plugin-entrypoint.php ${{ env.PARENT_DIRECTORY }} ${{ env.PLUGIN_SLUG }} ${{ env.PLUGIN_TYPE }}

      - name: Display Extracted Information
        run: |
          echo "Plugin/Theme Directory: ${{ steps.find_entry.outputs.plugin_directory }}"
          echo "Entry Point File: ${{ steps.find_entry.outputs.entry_point }}"
YML;

$job_name = static function ( string $test_type ): string {
	switch ( $test_type ) {
		case 'php-compatibility':
			return '${{ matrix.test_run.sut_name }} - PHP ${{ matrix.test_run.environment.min_php_version }} - ${{ matrix.test_run.environment.max_php_version }}';
		case 'security':
		case 'phpstan':
		case 'malware':
			return '${{ matrix.test_run.sut_name }}';
		default:
			return '${{ matrix.test_run.sut_name }} - WP ${{ matrix.test_run.environment.wordpress_version }} - WC ${{ matrix.test_run.environment.woocommerce_version }} - PHP ${{ github.event.client_payload.shared_matrix_data.php_version }}';
	}
};

$job_extra = <<< 'YML'
    name: __JOB_NAME__
YML;

$it = new DirectoryIterator( __DIR__ . '/..' );

$modified          = [];
$nothing_to_change = [];

while ( $it->valid() ) {
	if ( ! $it->isFile() || $it->getExtension() !== 'yml' ) {
		$it->next();
		continue;
	}

	$test_type = preg_match( '/ci-runner-(.*).yml/', $it->getBasename(), $matches ) ? $matches[1] : '';

	$workflow = file_get_contents( $it->getPathname() );

	// Docker.
	$workflow = preg_replace(
		'/DOCKER_CACHE_START.*?DOCKER_CACHE_END/s',
		"DOCKER_CACHE_START\n" . $docker_bundle_cache . "\n      # DOCKER_CACHE_END",
		$workflow
	);

	// Header.
	$workflow = preg_replace(
		'/HEADER_START.*?HEADER_END/s',
		"HEADER_START\n" . $daily_cache_burst . "\n\n" . $header . "\n      # HEADER_END",
		$workflow
	);

	// Env.
	$workflow = preg_replace(
		'/JOB_ENV_START.*?JOB_ENV_END/s',
		"JOB_ENV_START\n" . $job_env . "\n      # JOB_ENV_END",
		$workflow
	);

	// Test Type Cache.
	$workflow = preg_replace(
		'/TEST_TYPE_CACHE_START.*?TEST_TYPE_CACHE_END/s',
		"TEST_TYPE_CACHE_START\n" . $test_type_cache . "\n      # TEST_TYPE_CACHE_END",
		$workflow
	);

	// Daily Cache Burst.
	$workflow = preg_replace(
		'/DAILY_CACHE_BURST_START.*?DAILY_CACHE_BURST_END/s',
		"DAILY_CACHE_BURST_START\n" . $daily_cache_burst . "\n      # DAILY_CACHE_BURST_END",
		$workflow
	);

	// Sut Version.
	$workflow = preg_replace(
		'/SUT_VERSION_START.*?SUT_VERSION_END/s',
		"SUT_VERSION_START\n" . $sut_version . "\n      # SUT_VERSION_END",
		$workflow
	);

	// Update Manager about test status.
	$workflow = preg_replace(
		'/UPDATE_TEST_STATUS_START.*?UPDATE_TEST_STATUS_END/s',
		"UPDATE_TEST_STATUS_START\n". $update_test_status . "\n      # UPDATE_TEST_STATUS_END",
		$workflow
	);

	// Download plugins.
	$workflow = preg_replace(
		'/DOWNLOAD_PLUGINS_START.*?DOWNLOAD_PLUGINS_END/s',
		"DOWNLOAD_PLUGINS_START\n". $download_plugins . "\n      # DOWNLOAD_PLUGINS_END",
		$workflow
	);

	// CLI Setup.
	$workflow = preg_replace(
		'/CLI_SETUP.*?CLI_SETUP_END/s',
		"CLI_SETUP\n". $cli_setup . "\n      # CLI_SETUP_END",
		$workflow
	);

	// Debug Plugin Lists.
	$workflow = preg_replace(
		'/DEBUG_PLUGIN_LISTS.*?DEBUG_PLUGIN_LISTS_END/s',
		"DEBUG_PLUGIN_LISTS\n". $debug_plugin_lists . "\n      # DEBUG_PLUGIN_LISTS_END",
		$workflow
	);

	// Setup WordPress.
	$workflow = preg_replace(
		'/SETUP_WORDPRESS.*?SETUP_WORDPRESS_END/s',
		"SETUP_WORDPRESS\n". $setup_wordpress . "\n      # SETUP_WORDPRESS_END",
		$workflow
	);

	// Job Extra.
	$workflow = preg_replace(
		'/JOB_EXTRA_START.*?JOB_EXTRA_END/s',
		"JOB_EXTRA_START\n". $job_extra . "\n      # JOB_EXTRA_END",
		$workflow
	);

	// FIND_ENTRY_POINT_START.
	$workflow = preg_replace(
		'/FIND_ENTRY_POINT_START.*?FIND_ENTRY_POINT_END/s',
		"FIND_ENTRY_POINT_START\n". $find_plugin_entrypoint . "\n      # FIND_ENTRY_POINT_END",
		$workflow
	);

	if ( ! strpos( $it->getPathname(), 'e2e' ) ) {
		// Theme Activation.
		$workflow = preg_replace(
			'/THEME_ACTIVATION.*?THEME_ACTIVATION_END/s',
			"THEME_ACTIVATION\n". $theme_activation . "\n      # THEME_ACTIVATION_END",
			$workflow
		);
	}

	// Job name.
	$workflow = str_replace( '__JOB_NAME__', $job_name( $test_type ), $workflow );

	if ( file_get_contents( $it->getPathname() ) !== $workflow ) {
		if ( ! file_put_contents( $it->getPathname(), $workflow ) ) {
			echo "Failed to write {$it->getBasename()}\n";
			die( 1 );
		}

		$modified[] = $it->getBasename();
	} else {
		$nothing_to_change[] = $it->getBasename();
	}

	$it->next();
}

if ( ! empty( $modified ) ) {
	echo "=== Modified ===\n\n";
	echo implode( "\n", $modified ) . "\n";
	echo "\n";
}

if ( ! empty( $nothing_to_change ) ) {
	echo "=== Nothing to change ===\n\n";
	echo implode( "\n", $nothing_to_change ) . "\n";
	echo "\n";
}