"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.addAProductToCart = void 0;
exports.addOneOrMoreProductToCart = addOneOrMoreProductToCart;
/**
 * Adds a specified quantity of a product by ID to the WooCommerce cart.
 *
 * @param page      - Playwright page object
 * @param productId - The product ID to add
 * @param quantity  - Number of items to add (default: 1)
 */
const addAProductToCart = async (page, productId, quantity = 1) => {
    for (let i = 0; i < quantity; i++) {
        const responsePromise = page.waitForResponse('**/wp-json/wc/store/v1/cart**');
        await page.goto(`shop/?add-to-cart=${productId}`);
        await responsePromise;
        await page.getByRole('alert').waitFor({ state: 'visible' });
    }
};
exports.addAProductToCart = addAProductToCart;
/**
 * Util helper made for adding multiple same products to cart.
 *
 * @param page          - Playwright page object
 * @param productName   - Name of the product to add
 * @param quantityCount - Number of items to add (default: 1)
 */
async function addOneOrMoreProductToCart(page, productName, quantityCount = 1) {
    await page.goto(`product/${productName.replace(/ /gi, '-').toLowerCase()}`);
    await page
        .getByLabel('Product quantity')
        .fill(quantityCount.toString());
    await page.locator('button[name="add-to-cart"]').click();
}
