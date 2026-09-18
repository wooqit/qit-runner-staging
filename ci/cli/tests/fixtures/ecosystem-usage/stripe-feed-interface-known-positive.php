<?php

use Automattic\WooCommerce\Internal\ProductFeed\Feed\FeedInterface;

class WC_Stripe_Agentic_Commerce_Csv_Feed implements FeedInterface {
	public function get_title(): string {
		return 'Stripe Agentic Commerce';
	}
}
