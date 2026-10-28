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
var checkout_exports = {};
__export(checkout_exports, {
  fillBillingCheckoutBlocks: () => fillBillingCheckoutBlocks,
  fillShippingCheckoutBlocks: () => fillShippingCheckoutBlocks
});
module.exports = __toCommonJS(checkout_exports);
const addressLabels = {
  shipping: "Shipping address",
  billing: "Billing address"
};
async function setDynamicFieldType(field, value) {
  const tagName = await field.evaluate((el) => el.tagName.toLowerCase());
  if (tagName === "select") {
    await field.selectOption(value);
  } else {
    await field.fill(value);
  }
}
async function fillCheckoutBlocks(page, details = {}, type = "shipping") {
  const {
    country = "",
    firstName = "",
    lastName = "",
    address = "",
    zip = "",
    city = "",
    state = "",
    suburb = "",
    province = "",
    district = "",
    department = "",
    region = "",
    parish = "",
    county = "",
    prefecture = "",
    municipality = "",
    phone = "",
    isPostalCode = false
  } = details;
  const label = addressLabels[type];
  await page.getByRole("group", { name: label }).getByLabel("First name").fill(firstName);
  await page.getByRole("group", { name: label }).getByLabel("Last name").fill(lastName);
  await page.getByRole("group", { name: label }).getByLabel("Address", { exact: true }).fill(address);
  if (country) {
    await page.getByRole("group", { name: label }).getByLabel("Country").selectOption(country);
  }
  if (city) {
    await page.getByRole("group", { name: label }).getByLabel("City").fill(city);
  }
  if (suburb) {
    await page.getByRole("group", { name: label }).getByLabel("Suburb").fill(suburb);
  }
  if (province) {
    await page.getByRole("group", { name: label }).getByLabel("Province").selectOption(province);
  }
  if (district) {
    await page.getByRole("group", { name: label }).getByLabel("District").selectOption(district);
  }
  if (department) {
    await setDynamicFieldType(
      page.getByRole("group", { name: label }).getByLabel("Department"),
      department
    );
  }
  if (region) {
    await page.getByRole("group", { name: label }).getByLabel("Region", { exact: true }).selectOption(region);
  }
  if (parish) {
    await setDynamicFieldType(
      page.getByRole("group", { name: label }).getByLabel("Parish", { exact: false }),
      parish
    );
  }
  if (county) {
    await setDynamicFieldType(
      page.getByRole("group", { name: label }).getByLabel("County"),
      county
    );
  }
  if (prefecture) {
    await page.getByRole("group", { name: label }).getByLabel("Prefecture").selectOption(prefecture);
  }
  if (municipality) {
    await page.getByRole("group", { name: label }).getByLabel("Municipality").fill(municipality);
  }
  if (state) {
    const stateField = page.getByRole("group", { name: label }).getByLabel("State/County", { exact: false }).or(
      page.getByRole("group", { name: label }).getByLabel("State")
    );
    await setDynamicFieldType(stateField, state);
  }
  if (zip) {
    await page.getByRole("group", { name: label }).getByLabel(isPostalCode ? "Postal code" : "ZIP Code").fill(zip);
  }
  if (phone) {
    await page.getByRole("group", { name: label }).getByRole("textbox", { name: "Phone" }).fill(phone);
  }
}
async function fillShippingCheckoutBlocks(page, shippingDetails = {}) {
  await fillCheckoutBlocks(page, shippingDetails, "shipping");
}
async function fillBillingCheckoutBlocks(page, billingDetails = {}) {
  await fillCheckoutBlocks(page, billingDetails, "billing");
}
