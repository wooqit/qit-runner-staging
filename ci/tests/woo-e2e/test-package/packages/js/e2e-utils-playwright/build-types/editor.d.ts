/**
 * External dependencies
 */
import type { Page } from '@playwright/test';
/**
 * Internal dependencies
 */
import type { PageContext, EditorCanvas } from './types';
export type { PageContext, EditorCanvas } from './types';
/**
 * Closes the "Choose a pattern" modal if present.
 *
 * @param context      - Object containing the Playwright page
 * @param context.page - The Playwright page object
 */
export declare const closeChoosePatternModal: ({ page, }: PageContext) => Promise<void>;
/**
 * Disables the Gutenberg welcome modal.
 *
 * @param context      - Object containing the Playwright page
 * @param context.page - The Playwright page object
 */
export declare const disableWelcomeModal: ({ page, }: PageContext) => Promise<void>;
/**
 * Opens the editor settings sidebar if closed.
 *
 * @param context      - Object containing the Playwright page
 * @param context.page - The Playwright page object
 */
export declare const openEditorSettings: ({ page, }: PageContext) => Promise<void>;
/**
 * Returns the editor canvas frame for Gutenberg interactions.
 *
 * The Gutenberg editor mounts either inside an `iframe[name="editor-canvas"]`
 * (modern site/post editor) or directly on the outer page (non-iframed
 * contexts, e.g. accessibility mode). Recent Gutenberg
 * builds mount the iframe slightly after initial paint, so callers that resolve
 * the canvas before the iframe attaches end up running locators against the
 * outer page and timing out inside the editor.
 *
 * Resolves as soon as either surface becomes visible, using a union selector
 * so neither path incurs a hardcoded probe timeout. If neither surface appears
 * within Playwright's default timeout, the underlying `waitFor` throws and the
 * test fails loudly rather than silently degrading to the wrong document.
 *
 * @param page - The Playwright page object
 * @return The editor canvas frame or the original page
 */
export declare const getCanvas: (page: Page) => Promise<EditorCanvas>;
/**
 * Navigates to the WordPress page editor.
 *
 * @param context      - Object containing the Playwright page
 * @param context.page - The Playwright page object
 */
export declare const goToPageEditor: ({ page, }: PageContext) => Promise<void>;
/**
 * Navigates to the WordPress post editor.
 *
 * @param context      - Object containing the Playwright page
 * @param context.page - The Playwright page object
 */
export declare const goToPostEditor: ({ page, }: PageContext) => Promise<void>;
/**
 * Inserts a block using the block inserter.
 *
 * @param page      - The Playwright page object
 * @param blockName - The name of the block to insert
 */
export declare const insertBlock: (page: Page, blockName: string) => Promise<void>;
/**
 * Inserts a block using the slash command shortcut.
 *
 * @param page      - The Playwright page object
 * @param blockName - The name of the block to insert
 */
export declare const insertBlockByShortcut: (page: Page, blockName: string) => Promise<void>;
/**
 * Transforms classic content into blocks.
 *
 * @param page - The Playwright page object
 */
export declare const transformIntoBlocks: (page: Page) => Promise<void>;
/**
 * Publishes a page or post.
 *
 * @param page      - The Playwright page object
 * @param pageTitle - The title of the page/post being published
 * @param isPost    - Whether this is a post (true) or page (false)
 */
export declare const publishPage: (page: Page, pageTitle: string, isPost?: boolean) => Promise<void>;
//# sourceMappingURL=editor.d.ts.map