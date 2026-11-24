/**
 * External dependencies
 */
import type { Page } from '@playwright/test';
/**
 * Adds a specified quantity of a product by ID to the WooCommerce cart.
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