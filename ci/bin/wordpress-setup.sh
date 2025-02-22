#!/bin/bash

# Exits immediately if it encounters an error.
set -e

# Variables
EXTRA_COMMANDS="${1}"
DBNAME="wordpress"
DBUSER="admin"
DBPASS="password"
DBHOST="ci_runner_db"
SITEURL="http://qit-runner.test"
SITETITLE="Test Site"
ADMIN_USER="admin"
ADMIN_EMAIL="wordpress@example.com"
ADMIN_PASSWORD="password"
SKIP_ACTIVATING_WOOCOMMERCE="${SKIP_ACTIVATING_WOOCOMMERCE:-no}"
QIT_DOCKER_REDIS="${QIT_DOCKER_REDIS:-no}"

# Require the environment variable
: ${PHP_EXTENSIONS:?Please set the PHP_EXTENSIONS environment variable}
: ${WORDPRESS_VERSION:?Please set the WORDPRESS_VERSION environment variable}
: ${WOOCOMMERCE_VERSION:?Please set the WOOCOMMERCE_VERSION environment variable}
: ${SUT_SLUG:?Please set the SUT_SLUG environment variable}
: ${TEST_TYPE:?Please set the SUT_SLUG environment variable}
: ${PLUGINS_JSON:?Please set the PLUGINS_JSON environment variable}

# Feature Flags
QIT_ENABLE_HPOS="${QIT_ENABLE_HPOS:-0}"
QIT_ENABLE_NEW_PRODUCT_EDITOR="${QIT_ENABLE_NEW_PRODUCT_EDITOR:-0}"
ENABLE_TRACKING="${ENABLE_TRACKING:-0}"

# Function to abstract docker exec as www-data
docker_wp() {
  docker exec --user=www-data ci_runner_php_fpm bash -c "set -e; ${*}"
}

# Function to abstract docker exec without exit on error, returns status
docker_wp_return() {
  local status
  # Temporarily disable exit on error within this command grouping
  set +e
  docker exec --user=www-data ci_runner_php_fpm bash -c "${*}"
  status=$?
  set -e  # Re-enable set -e for the rest of the script
  return $status
}

version_compare() {
  php -r "if(version_compare('$1', '$2', '$3')) exit(0); else exit(1);"
}

download_and_verify_wordpress() {
    local attempts=0
    local max_attempts=3
    local delay=5

    while (( attempts < max_attempts )); do
        echo "Attempt $(( attempts + 1 )) of $max_attempts: Downloading and verifying WordPress..."

        # Download WordPress without exiting on error
        if docker_wp_return "php -d memory_limit=256M \$(which wp) core download --version=\"$WORDPRESS_VERSION\" --force --path=/var/www/html"; then
            echo "WordPress downloaded successfully."
        else
            echo "Failed to download WordPress. Retrying in $delay seconds..."
            ((attempts++))
            sleep $delay
            continue
        fi

        # Verify WordPress without exiting on error
        if docker_wp_return "wp core verify-checksums --path=/var/www/html"; then
            echo "WordPress downloaded and verified successfully."
            return
        else
            echo "Failed to verify WordPress. Retrying in $delay seconds..."
            attempts=$((attempts + 1))
            echo "Increased attempts..."
            sleep $delay
            echo "Slept for 5 seconds..."
        fi
    done

    echo "Failed to download and verify WordPress after $max_attempts attempts."
    exit 1
}

download_and_verify_wordpress

# Configure WordPress
docker_wp "
wp core config --dbname=\"$DBNAME\" --dbuser=\"$DBUSER\" --dbpass=\"$DBPASS\" --dbhost=\"$DBHOST\" --path=/var/www/html &&
wp core install --url=\"$SITEURL\" --title=\"$SITETITLE\" --admin_user=\"$ADMIN_USER\" --admin_email=\"$ADMIN_EMAIL\" --admin_password=\"$ADMIN_PASSWORD\" &&
wp config set WP_DEBUG true --raw &&
wp config set WP_DEBUG_LOG true --raw &&
wp config set WP_DEBUG_DISPLAY false --raw &&
wp config set WP_DISABLE_FATAL_ERROR_HANDLER true --raw &&
wp config set DISABLE_WP_CRON true --raw &&
wp config set WP_AUTO_UPDATE_CORE false --raw &&
wp config set WP_MEMORY_LIMIT 256M &&
wp plugin deactivate akismet hello --uninstall &&
wp rewrite structure '/%postname%/' &&
wp option update blogname 'WooCommerce Core E2E Test Suite'
"

# Wait a few seconds as some processes might still be writing to the filesystem.
sleep 3;

# Make sure akismet and hello plugins are removed from the filesystem. There's something weird on WordPress 6.5 beta with filesystem operations.
# @see https://wordpress.slack.com/archives/C02RQBWTW/p1709380501163589?thread_ts=1709330758.080609&cid=C02RQBWTW
docker_wp rm -rf /var/www/html/wp-content/plugins/hello.php /var/www/html/wp-content/plugins/akismet /var/www/html/wp-content/themes/twentynineteen

docker_wp wp theme install twentytwenty --force

# Disable all automatic updates, and update checks.
docker_wp wp plugin install disable-wordpress-updates --force

# Install and activate the Twenty Nineteen theme.
docker_wp wp theme install twentytwentythree --force --activate

# Needed for some tests.
if [ "$TEST_TYPE" == "woo-e2e" ]; then
  docker_wp wp theme install storefront
fi

if [ "$TEST_TYPE" == "woo-e2e" ]; then
  docker_wp "wp media import '/woo-e2e/test-data/images/image-01.png' '/woo-e2e/test-data/images/image-02.png' '/woo-e2e/test-data/images/image-03.png'"
fi

# Check if SUT_SLUG is "woocommerce" or "wporg-woocommerce"
if [ "$SUT_SLUG" != "woocommerce" ] && [ "$SUT_SLUG" != "wporg-woocommerce" ]; then
  # Install and activate WooCommerce only if SUT_SLUG is not "woocommerce" or "wporg-woocommerce"
  echo "WOOCOMMERCE_VERSION = $WOOCOMMERCE_VERSION"
  docker_wp "wp plugin install https://github.com/woocommerce/woocommerce/releases/download/$WOOCOMMERCE_VERSION/woocommerce.zip"
else
    echo "Skipping WooCommerce installation as SUT_SLUG is $SUT_SLUG"
fi

# If test type is woo-e2e and version is higher than 8.8.9999
if [ "$TEST_TYPE" == "woo-e2e" ] && version_compare "$WOOCOMMERCE_VERSION" "8.8.9999" ">="; then
  echo "Special setup for Woo-E2E >= 8.9"
  docker_wp "wp option update woocommerce_coming_soon 'no'"
fi

echo "QIT_DOCKER_REDIS = $QIT_DOCKER_REDIS"

if [ "$QIT_DOCKER_REDIS" == "yes" ]; then
  echo "Installing and configuring Redis"
  docker_wp "wp config set WP_REDIS_HOST \"ci_runner_cache\" --add --type=constant"
  docker_wp "wp plugin install redis-cache --activate"
  docker_wp "wp redis enable"
  docker_wp "wp redis status | grep \"Status: Connected\" || exit 1"
fi

# Activate WooCommerce as needed.
if [ "$TEST_TYPE" != "activation" ] ; then
  docker_wp "wp plugin activate woocommerce"

  # We only create the user if activating Woo because of the "customer" role.
  # We don't activate Woo on other tests like Activation, but then we don't need the user.
  if version_compare "$WOOCOMMERCE_VERSION" "8.7.999" ">="
  then
    docker_wp "
    wp user create customer customer@woocommercecoree2etestsuite.com \
        --user_pass=password \
        --user_registered='2022-01-01 12:23:45' \
        --role=customer \
        --first_name=Jane \
        --last_name=Smith
    "
  else
    docker_wp "
    wp user create customer customer@woocommercecoree2etestsuite.com \
        --user_pass=password \
        --user_registered='2022-01-01 12:23:45' \
        --role=subscriber \
        --first_name=Jane \
        --last_name=Smith
    "
  fi
else
  # Skip activating "woocommerce" in activation test if SUT is woocommerce.
  if [ "$SUT_SLUG" == "woocommerce" ] || [ "$SUT_SLUG" == "wporg-woocommerce" ]; then
    echo "Skipping activation of WooCommerce as SUT_SLUG is $SUT_SLUG"
  else
    # Activate WooCommerce in "activation" test.
    docker_wp "wp plugin activate woocommerce"
  fi
fi

# Activate plugins, in order.
for plugin in $(echo "${PLUGINS_JSON}" | jq -r '.[] | select(.type=="plugin") | .slug'); do
  if [ "$TEST_TYPE" == "activation" ] && [ "$SUT_SLUG" == "$plugin" ]; then
    echo "Skipping activation of $plugin"
    continue
  fi
  echo "Activating plugin: ${plugin}"
  docker_wp "wp plugin activate ${plugin}"
done

if [ "$TEST_TYPE" != "activation" ]; then
  # Activate all leftover plugins, except Woo.
  docker_wp "wp plugin activate --all --exclude=woocommerce,wporg-woocommerce"
fi

# Execute additional docker_wp commands
# Example usage: EXTRA_COMMANDS="wp plugin install some-plugin --activate && wp theme install some-theme --activate"
if [[ -n "${EXTRA_COMMANDS}" ]]; then
  docker_wp "${EXTRA_COMMANDS}"
fi

# Download all extra plugins so that they are available in cache.
docker_wp wp plugin install https://gist.github.com/Luc45/06f1ab20d8fb4c0ac8f3878f0138c5ad/archive/8850368d73dc2f3f8d8de8ec4558fc30578d051d.zip # HPOS
docker_wp wp plugin install https://github.com/woocommerce/woocommerce-experimental-enable-new-product-editor/releases/download/0.1.0/woocommerce-experimental-enable-new-product-editor.zip # New Product Editor.

# High-performance order storage feature.
echo "QIT_ENABLE_HPOS = $QIT_ENABLE_HPOS"
if [[ $QIT_ENABLE_HPOS == 1 ]]; then
  echo 'Enable the HPOS feature'
  docker_wp wp plugin install https://gist.github.com/Luc45/06f1ab20d8fb4c0ac8f3878f0138c5ad/archive/8850368d73dc2f3f8d8de8ec4558fc30578d051d.zip --force --activate # HPOS
fi

# Todo: Wire up the new product editor feature in the Manager as an optional feature.
echo "QIT_ENABLE_NEW_PRODUCT_EDITOR = $QIT_ENABLE_NEW_PRODUCT_EDITOR"
if [ $QIT_ENABLE_NEW_PRODUCT_EDITOR == 1 ]; then  
	echo 'Enable the new product editor feature'
  docker_wp wp plugin install https://github.com/woocommerce/woocommerce-experimental-enable-new-product-editor/releases/download/0.1.0/woocommerce-experimental-enable-new-product-editor.zip --force --activate # New Product Editor.
fi

# Todo: Wire up enable tracking in the Manager as an optional feature (?)
echo "ENABLE_TRACKING = $ENABLE_TRACKING"
if [ $ENABLE_TRACKING == 1 ]; then
	echo 'Enable tracking'
	docker_wp option update woocommerce_allow_tracking 'yes'
fi

# Install additional PHP Extensions, if any.
echo "PHP_EXTENSIONS = $PHP_EXTENSIONS"
for extension in $(echo "${PHP_EXTENSIONS}" | jq -r '.[]'); do
  echo "Installing PHP extension: ${extension}"
    # Check if the extension is "soap"
    if [[ "${extension}" == "soap" ]]; then
      docker exec --user=root ci_runner_php_fpm apk update
      docker exec --user=root ci_runner_php_fpm apk add --no-cache libxml2-dev
    fi
  docker exec --user=root ci_runner_php_fpm docker-php-ext-install "${extension}"
done

# Restart PHP-FPM if at least one extension was installed.
if [[ -n "${PHP_EXTENSIONS}" ]]; then
  docker exec --user=root ci_runner_php_fpm kill -USR2 1
fi