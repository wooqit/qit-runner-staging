/**
 * External dependencies
 */
import type { Page } from '@playwright/test';
/**
 * Adds a specified quantity of a product by ID to the WooCommerce cart.
 *
 * QIT divergence from upstream (@woocommerce/e2e-utils-playwright 0.5.0):
 * upstream waits for a `wc/store/v1/cart` response and then any `role="alert"`
 * element. QIT runs this suite against arbitrary sites — older WooCommerce
 * versions and themes that never issue that Store API request would hang until
 * the action timeout, and a bare `role="alert"` lookup can match notices added
 * by the extension under test, which fails Playwright's strict mode. Waiting on
 * the rendered WooCommerce notice keeps this portable across those sites.
 *
 * @param page      - Playwright page object
 * @param productId - The product ID to add
 * @param quantity  - Number of items to add (default: 1)
 */
export declare const addAProductToCart: (page: Page, productId: string, quantity?: number) => Promise<void>;
/**
 * Util helper made for adding multiple same products to cart.
 *
 * @param page          - Playwright page object
 * @param productName   - Name of the product to add
 * @param quantityCount - Number of items to add (default: 1)
 */
export declare function addOneOrMoreProductToCart(page: Page, productName: string, quantityCount?: number): Promise<void>;
//# sourceMappingURL=cart.d.ts.map