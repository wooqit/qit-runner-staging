"use strict";
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);
var cart_exports = {};
__export(cart_exports, {
  addAProductToCart: () => addAProductToCart,
  addOneOrMoreProductToCart: () => addOneOrMoreProductToCart
});
module.exports = __toCommonJS(cart_exports);
const addAProductToCart = async (page, productId, quantity = 1) => {
  for (let i = 0; i < quantity; i++) {
    const responsePromise = page.waitForResponse(
      "**/wp-json/wc/store/v1/cart**"
    );
    await page.goto(`shop/?add-to-cart=${productId}`);
    await responsePromise;
    await page.getByRole("alert").waitFor({ state: "visible" });
  }
};
async function addOneOrMoreProductToCart(page, productName, quantityCount = 1) {
  await page.goto(
    `product/${productName.replace(/ /gi, "-").toLowerCase()}`
  );
  await page.getByLabel("Product quantity").fill(quantityCount.toString());
  await page.locator('button[name="add-to-cart"]').click();
}
