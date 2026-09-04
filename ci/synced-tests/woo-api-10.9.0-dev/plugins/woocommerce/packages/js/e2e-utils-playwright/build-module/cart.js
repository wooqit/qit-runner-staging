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
export {
  addAProductToCart,
  addOneOrMoreProductToCart
};
