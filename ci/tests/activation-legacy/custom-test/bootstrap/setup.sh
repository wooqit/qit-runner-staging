#!/bin/bash

# Bootstrap Shell Script (Optional)

# Purpose: This script is executed before test runs to set up the testing environment.
#
# Usage:
# - Use WP CLI to configure prerequisites for your tests. 
# - Example: To install a specific theme required for tests:
#   wp theme install twentytwentynine
#   (You can then activate this theme during your tests)
#
# Note: Delete this file if it's not required for your setup.
#
# Documentation: Detailed instructions available at https://qit.woo.com/docs/custom-tests/generating-tests

wp plugin install https://downloads.wordpress.org/plugin/query-monitor.3.17.0.zip --activate
wp theme install twentynineteen --activate --force

# If WooCommerce is not the SUT, activate it.
if [ "$QIT_SLUG" != "woocommerce" ]; then
    wp plugin activate woocommerce
fi