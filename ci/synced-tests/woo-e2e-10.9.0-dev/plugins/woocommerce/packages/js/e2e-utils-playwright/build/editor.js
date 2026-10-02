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
var editor_exports = {};
__export(editor_exports, {
  closeChoosePatternModal: () => closeChoosePatternModal,
  disableWelcomeModal: () => disableWelcomeModal,
  getCanvas: () => getCanvas,
  goToPageEditor: () => goToPageEditor,
  goToPostEditor: () => goToPostEditor,
  insertBlock: () => insertBlock,
  insertBlockByShortcut: () => insertBlockByShortcut,
  openEditorSettings: () => openEditorSettings,
  publishPage: () => publishPage,
  transformIntoBlocks: () => transformIntoBlocks
});
module.exports = __toCommonJS(editor_exports);
const closeChoosePatternModal = async ({
  page
}) => {
  const closeModal = page.locator("div").filter({ hasText: "Choose a pattern" }).getByLabel("Close");
  await page.addLocatorHandler(closeModal, async () => {
    await closeModal.click();
  });
};
const disableWelcomeModal = async ({
  page
}) => {
  await page.waitForLoadState("domcontentloaded");
  const isWelcomeGuideActive = await page.evaluate(
    () => window.wp?.data?.select("core/edit-post")?.isFeatureActive("welcomeGuide")
  );
  if (isWelcomeGuideActive) {
    await page.evaluate(
      () => window.wp?.data?.dispatch("core/edit-post")?.toggleFeature("welcomeGuide")
    );
  }
};
const openEditorSettings = async ({
  page
}) => {
  if (await page.getByLabel("Editor Settings").isVisible()) {
    console.log("Editor Settings is open, skipping action.");
  } else {
    await page.getByLabel("Settings", { exact: true }).click();
  }
};
const getCanvas = async (page) => {
  const iframeLocator = page.locator('iframe[name="editor-canvas"]');
  if (await iframeLocator.count() > 0) {
    return iframeLocator.contentFrame();
  }
  return page;
};
const goToPageEditor = async ({
  page
}) => {
  await page.goto("wp-admin/post-new.php?post_type=page");
  await disableWelcomeModal({ page });
  await closeChoosePatternModal({ page });
};
const goToPostEditor = async ({
  page
}) => {
  await page.goto("wp-admin/post-new.php");
  await disableWelcomeModal({ page });
};
const insertBlock = async (page, blockName) => {
  const canvas = await getCanvas(page);
  const emptyBlock = canvas.getByLabel("Empty block");
  if (await emptyBlock.isVisible()) {
    await emptyBlock.click();
  }
  await page.getByRole("button", {
    name: /Toggle block inserter|Block Inserter/,
    expanded: false
  }).click();
  await page.getByPlaceholder("Search", { exact: true }).fill(blockName);
  await page.getByRole("option", { name: blockName, exact: true }).click();
  await page.getByRole("button", {
    name: "Close block inserter"
  }).click();
};
const insertBlockByShortcut = async (page, blockName) => {
  const canvas = await getCanvas(page);
  const emptyBlockField = canvas.getByText("Type / to choose a block").or(
    canvas.getByRole("document", {
      name: "Empty block; start writing or type forward slash to choose a block"
    })
  );
  await emptyBlockField.click();
  await emptyBlockField.pressSequentially(`/${blockName}`);
  await page.getByRole("option", { name: blockName, exact: true }).click();
};
const transformIntoBlocks = async (page) => {
  const canvas = await getCanvas(page);
  await canvas.getByRole("button").filter({ hasText: "Transform into blocks" }).click();
};
const publishPage = async (page, pageTitle, isPost = false) => {
  await page.getByRole("button", { name: "Publish", exact: true }).dispatchEvent("click");
  const createPageResponse = page.waitForResponse((response) => {
    return response.url().includes(isPost ? "/posts/" : "/pages/") && response.ok() && response.request().method() === "POST" && response.json().then(
      (json) => json.title.rendered === pageTitle && json.status === "publish"
    );
  });
  await page.getByRole("region", { name: "Editor publish" }).getByRole("button", { name: "Publish", exact: true }).click();
  await createPageResponse;
};
