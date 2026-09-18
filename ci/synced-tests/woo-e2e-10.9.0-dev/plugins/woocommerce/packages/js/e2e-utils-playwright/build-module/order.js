function getOrderIdFromUrl(page) {
  const regex = /order-received\/(\d+)/;
  const match = page.url().match(regex);
  return match?.[1];
}
export {
  getOrderIdFromUrl
};
