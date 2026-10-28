/**
 * External dependencies
 */
import type { Page } from '@playwright/test';
/**
 * Internal dependencies
 */
import type { CheckoutDetails } from './types';
export type { CheckoutDetails } from './types';
/**
 * Convenience function to fill Shipping Address fields.
 *
 * Opens a collapsed shipping address form automatically before filling fields.
 *
 * @param page            - Playwright page object
 * @param shippingDetails - See CheckoutDetails type for available fields
 */
export declare function fillShippingCheckoutBlocks(page: Page, shippingDetails?: CheckoutDetails): Promise<void>;
/**
 * Convenience function to fill Billing Address fields.
 *
 * Opens a collapsed billing address form automatically before filling fields.
 *
 * @param page           - Playwright page object
 * @param billingDetails - See CheckoutDetails type for available fields
 */
export declare function fillBillingCheckoutBlocks(page: Page, billingDetails?: CheckoutDetails): Promise<void>;
//# sourceMappingURL=checkout.d.ts.map