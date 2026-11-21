"use strict";

Object.defineProperty(exports, "__esModule", {
  value: true
});
exports.getOrderIdFromUrl = getOrderIdFromUrl;
function getOrderIdFromUrl(page) {
  var regex = /order-received\/(\d+)/;
  try {
    return page.url().match(regex)[1];
  } catch (error) {
    return undefined;
  }
}