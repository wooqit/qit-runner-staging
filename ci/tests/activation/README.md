# Activation test

The Activation test verifies that a WooCommerce extension can be activated and
survive common merchant workflows. Plugin activation always captures the
hook-resilience baseline and post-activation evidence. The verifier is blocking
in default/full and `basic` runs, so an ordinary `qit run:activation` includes
this check without any additional arguments.

The `release-smoke` variation is a focused selector for plugin release
candidates. It runs only plugin activation and the resilience verifier, while
the default/full suite continues into the broader Activation flows.

The hook-resilience check covers ordinary frontend, admin, and REST requests,
the WooCommerce settings screen, and the WordPress mail pipeline. The mail
recipient uses the reserved `example.invalid` domain. A legal `pre_wp_mail`
short-circuit is accepted whether it reports the mail as sent or as blocked;
otherwise `phpmailer_init` callbacks install a local
null transport at the beginning and end of that hook, preventing real delivery
while still running the argument, sender, content-type, and mailer initialization
hooks. The probe normalizes its final sender address so localhost environments
reach `phpmailer_init`.

It also exercises controlled WordPress hook contracts on marked QIT requests
only:

- `rest_pre_serve_request` returning `null`;
- `pre_http_request` returning `WP_Error`; and
- `rest_authentication_errors` returning `WP_Error`.

The dedicated mail and outbound-HTTP endpoints return structured results that
the runner validates directly. The settings request intentionally has no
required response status or completion marker, allowing setup-wizard redirects
and a WooCommerce-inactive baseline while still blocking transport failures,
HTTP 5xx responses, and captured PHP fatals.

Captured throwables are recorded as structured probe evidence and then returned
to PHP's normal exception path so the standard debug log continues to report
the original fatal error.

## Tests that depend on the WooCommerce version

These tests drive WooCommerce's admin UI, so their selectors are tied to the
markup of a particular version. QIT offers several at once — the four most
recent stable releases plus one prerelease, regularly spanning two minor lines
— so a selector a WooCommerce release changes has to work on two versions that
are both live.

**The difference belongs in the version branch, not in an `if`.** This package
is published once per WooCommerce `major.minor`, and a run executes the version
covering the WooCommerce it installs, so each published version only ever meets
the WooCommerce it was written for. Fix the selector for the version the branch
represents, and leave the other branch alone.

The branches are the ones the Woo Core E2E package already uses — a single
branch per WooCommerce version publishes both packages, since
`qit package:publish` takes a directory:

```bash
qit package:publish <branch>/ci/tests/woo-e2e/test-package    11.0
qit package:publish <branch>/ci/tests/activation/test-package 11.0
```

`ci/synced-tests/README.md` covers how a version is published, rolled back, and
retired, including why `latest` must never be retired.

Two consequences worth knowing:

- A fix that is not version-specific has to reach every live branch. Land it on
  `trunk` and cherry-pick.
- A WooCommerce version with no package of its own falls back to `latest`, which
  is the line in development. Nothing is written for it, so a selector gate
  cannot help there either; publishing a version for it is the fix. This is
  where a new line's prerelease lands every cycle, so publish for it as soon as
  QIT offers it.

## Run in a plugin pipeline

Build the release ZIP first, then run:

```bash
qit run:activation google-listings-and-ads \
  --zip=build/google-listings-and-ads.zip \
  --wp=stable --woo=stable --php=8.2 \
  --passthrough_target=woocommerce/activation \
  -- --grep="@release-smoke"
```

The explicit passthrough target routes `--grep` to the remote
`woocommerce/activation` test package. Omit the final two lines to run the full
Activation suite; the resilience verifier remains enabled by default. Replace
the extension slug and ZIP path as needed. QIT exits non-zero when:

- the pre-activation baseline is unhealthy, reported as
  `QIT_ACTIVATION_SMOKE_BASELINE_INVALID`; or
- activating the candidate introduces a fatal error, transport failure, or
  unexpected probe response, reported as `QIT_ACTIVATION_SMOKE_REGRESSION`.

The result includes a `global-surface-resilience.json` attachment containing
the baseline, post-activation observations, captured PHP events, and SUT
attribution evidence.

Selecting `release-smoke` runs only plugin activation and the resilience
verifier. It does not run the product, order, cart, checkout, or deactivation
flows from the full Activation suite.
