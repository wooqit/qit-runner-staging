<?php
/**
 * Plugin Name: QIT Internal Namespace Clean Fixture
 * Description: Deterministic negative fixture for the Internal namespace audit.
 */

use Automattic\WooCommerce\Utilities\OrderUtil;

function qit_internal_usage_clean_fixture(): string {
	return OrderUtil::class;
}

/** Automattic\WooCommerce\Internal\IgnoredDocblock */
// Automattic\WooCommerce\Internal\IgnoredComment
