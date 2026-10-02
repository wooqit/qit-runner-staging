"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.getOrderIdFromUrl = getOrderIdFromUrl;
/**
 * Extracts the order ID from the current page URL.
 *
 * @param page - Playwright page object
 * @return The order ID or undefined if not found
 */
function getOrderIdFromUrl(page) {
    const regex = /order-received\/(\d+)/;
    const match = page.url().match(regex);
    return match?.[1];
}
