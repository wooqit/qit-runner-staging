# Woo Core E2E test package

`test-package/` is WooCommerce Core's Playwright suite
(`plugins/woocommerce/tests/e2e` upstream), repackaged so the same flows run
against a site with a third-party extension installed. Only the critical-flow
subset is carried: shop, cart, checkout, my-account, orders, products, coupons,
settings and shipping.

**Spec files are kept byte-identical to upstream.** When a spec does not run
under QIT, the fix goes in the environment layer, never in the spec. Every fork
is a spec that silently stops tracking upstream, and QIT-local edits are where
breakage hides — the six failures on WooCommerce 11.1.0-beta.1 came from a
QIT-only helper, not from upstream drift.

`.github/woo-core-test-watch.json` watches the upstream paths this package
derives from and opens an issue when they change.

## The environment layer

This is where adaptation belongs, and the only place divergence is legitimate.

| File | Why it differs |
|---|---|
| `playwright.config.ts` | QIT harness: CTRF reporter, `QIT_BASE_URL`, `workers: 1`, its own project list. |
| `test-data/data.ts` | Falls back to `QIT_WP_USERNAME` / `QIT_WP_PASSWORD`. |
| `utils/wordpress.ts` | `getInstalledWordPressVersion` reads `e2e-environment/info` instead of shelling to `wp-env run cli -- wp core version`. |
| `utils/cli.ts` | Upstream shells to `wp-env run cli`. QIT has no wp-env — WP-CLI only exists during `qit-test.json` globalSetup, and Playwright runs on the host. Routed through the `e2e-cli` REST bridge instead, keeping upstream's `wpCLI()` signature. |
| `bootstrap/*.php` | mu-plugins listed in `qit-test.json`, standing in for what upstream ships through `.wp-env.e2e.json` and `bin/test-env-setup.sh`: basic auth, `e2e-test-helper.php` (a port of upstream's always-on `woocommerce-e2e-test-helper`, with every function prefixed so it cannot collide with the extension under test), the `e2e-cli` bridge, and seeding the `image-01/02/03` attachments `utils/media.ts` resolves. |

Adding a command to `wpCLI` means adding it to `bootstrap/wp-cli-bridge.php`.
The bridge implements only what the specs issue and rejects anything else, so an
unported command fails loudly rather than silently doing nothing.

The vendored `packages/js/e2e-utils-playwright` is checked in prebuilt, so a
change to `src/` must be mirrored into `build/` and `build-module/` — those are
what actually run. Keep it unforked: `addAProductToCart` once waited on the
classic `.woocommerce-message` notice, which silently stopped matching the
moment the pinned block theme started rendering block notices, and took every
cart, checkout and coupon spec down with it.

## Environment parity with upstream

Several specs assert against things upstream's environment provisions rather
than the specs themselves. `qit-test.json` `globalSetup` mirrors what
`bin/test-env-setup.sh` does upstream, and the pieces are load-bearing:

- **`wp theme install twentytwentythree --activate`** — upstream pins this
  theme. Themes differ in how many catalog-ordering selects an archive renders
  and how the cart and checkout blocks lay out, so an unpinned theme fails specs
  that are correct.
- **`wp rewrite structure '/%postname%/' --hard`** — pretty permalinks.
- The `image-01/02/03` attachments, seeded by `bootstrap/seed-media.php` in place
  of upstream's `wp media import`.

Specs must not leave global state changed for later specs. `site.setup.ts`
establishes the baseline (taxes on with no rates, COD and BACS enabled, free
shipping on zone 0) and a spec that needs something else scopes it — see
`withScopedTaxClass` in `utils/taxes.ts`. A `beforeAll` that flips
`woocommerce_calc_taxes` off and never restores it breaks every spec that runs
after it; that is exactly what the pre-alignment coupon specs did.

## Not yet ported

Two upstream specs in the subset are still missing:

- `checkout/checkout-shortcode-custom-place-order-button.spec.ts` — needs
  upstream's `test-plugins/` shipped and mounted.
- `checkout/checkout-link.spec.ts` — imports `guestFile` from
  `fixtures/fixtures`, which does not export it (upstream's own import looks
  broken); resolve upstream before porting.
