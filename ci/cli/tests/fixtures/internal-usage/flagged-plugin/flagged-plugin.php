<?php
/**
 * Plugin Name: QIT Internal Namespace Flagged Fixture
 * Description: Deterministic positive fixture for the Internal namespace audit.
 */

/** Automattic\WooCommerce\Internal\IgnoredDocblock */
use Automattic\WooCommerce\Internal\Features\FeaturesController;

function qit_internal_usage_flagged_fixture(): array {
	return [
		'class_constant'  => FeaturesController::class,
		'string_reference' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore',
	];
}

// Automattic\WooCommerce\Internal\IgnoredComment
