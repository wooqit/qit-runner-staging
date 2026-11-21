<?php
/**
 * Regression fixture for the originally reported false positive.
 *
 * `rank` appears in a translatable label ("by Rank Math"), and the same string
 * contains the word "from" — which used to satisfy the old line-based SQL-context
 * heuristic. There is no SQL here; this must produce zero findings.
 */

$field_list                                     = [];
$field_list['SeoByRankMath:getPrimaryCategory'] = __(
	'Primary Category from SEO by Rank Math extension',
	'woocommerce_gpf'
);
