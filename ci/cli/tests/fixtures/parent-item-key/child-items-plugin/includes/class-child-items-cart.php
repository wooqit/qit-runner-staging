<?php

namespace ChildItems;

const MY_PARENT_ITEM_KEY = 'my_parent';

class Cart {
	const BUNDLED_BY = 'bundled_by';

	public function add_child( $bundle_cart_key, $search_container_item_key, $parent_cart_item_key, $parent_item_key, $cart_item_key ) {
		$cart_item_data = array(
			'bundled_by' => $bundle_cart_key,
			'stamp'      => array(),
		);

		$cart_item['bundled_by'] = $search_container_item_key;

		$cart_item_data['bundle_sells']['bundle_sell_of'] = $parent_cart_item_key;

		$data = [ MY_PARENT_ITEM_KEY => $parent_item_key ];

		$data = [ self::BUNDLED_BY => $Parent_Cart_KEY ];

		$data = [ \ChildItems\MY_PARENT_ITEM_KEY => $parent_item_key ];

		$data = [
			'composite_parent' =>
				$parent_cart_item_key,
		];

		// Not matches from here on.
		$core = [ 'key' => $cart_item_key ];
		$same = [ 'cart_item_key' => $cart_item_key ];
		$expr = [ 'bundled_by' => $bundle_cart_key . '-suffix' ];
		$name = [ 'bundled_by' => $parent ];
		$list = [ 'bundled_by' => $cart_item_keys ];
		$var  = [ $index => $bundle_cart_key ];
		$prop = [ 'bundled_by' => $this->bundle_cart_key ];
		// $cart_item['bundled_by'] = $search_container_item_key;
		/* 'bundled_by' => $bundle_cart_key, */
		$text = "'bundled_by' => $bundle_cart_key";
		$quoted = '$cart_item[\'bundled_by\'] = $search_container_item_key;';
		$doc = <<<EOT
'bundled_by' => $bundle_cart_key, and \$cart_item['bundled_by'] = {$search_container_item_key};
EOT;
		$now = <<<'EOT'
'bundled_by' => $bundle_cart_key
EOT;
		$filter = "woocommerce_store_api_cart_item_parent_item_key is mentioned with $cart_item_key";

		return array( $cart_item_data, $cart_item, $data, $core, $same, $expr, $name, $list, $var, $prop, $text, $quoted, $doc, $now, $filter );
	}
}
