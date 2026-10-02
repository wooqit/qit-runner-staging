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

## Request footprint (report-only)

Default/full and `basic` runs also record what the plugin does on requests
where it should do almost nothing: `admin-ajax.php?action=rest-nonce`,
`/wp-admin/profile.php` and the front page. It catches work registered on
request-wide hooks such as `admin_init` without a gate on request context,
like the Inbox Notes lookups that ran on every heartbeat.

Each request is sent three times before and three times after activating the
plugin, on marked probe requests only. The `bootstrap/request-footprint.php`
mu-plugin counts the statements the plugin runs, the writes among them, and
its outbound HTTP calls. A call counts as the plugin's when any frame of the
call stack is in the plugin's directory, so a write that `update_option()`
makes on its behalf counts. Outbound HTTP is answered with a `WP_Error` after
it is counted, so probes send no traffic to vendors. SQL samples keep the
statement's shape but not its values. The report gives, per request:

- the plugin's statements (`queries`, writes included), `writes` and `http`
  calls, as the lowest count over the repeats, with the range when they differ;
- up to ten call sites seen on every repeat, as hook → file:line with a
  sample;
- the totals per hook, with no cap on hooks, so work past the ten call sites
  still says which hook runs it, each split by file for up to twenty files
  (counted as omitted past that). Like call sites, only work on every repeat
  counts, so a hook's files can add up to less than the hook when its work
  moves between files; with a repeat missing, the totals are unavailable
  (`null`); and
- the change in total statements against the pre-activation baseline.

`Record request footprint` adds a `qit-request-footprint` annotation and
attaches `request-footprint.json`; the log only says where to find them. The
Manager shows the annotation as the "Request footprint" section of the test
report, without the SQL samples: reports are shared by link, and the samples
would expose the extension's schema. `RequestFootprintMetricsBuilder` reads the
annotation too; the builder is not registered in the metrics endpoint yet. The measurement only runs when the
Playwright command line selects `Record request footprint`, so release-smoke
and host-plan runs don't pay for it. Setting `QIT_REQUEST_FOOTPRINT_SELECTED=false`
in Playwright's environment turns it off.

It never fails the run, and it has no threshold yet. Know its limits:

- The store is clean, so code that only runs once the extension is configured
  (a connected gateway, a licence) is not exercised.
- There is no persistent object cache, so option and transient reads that
  production would serve from cache count as statements.
- Only work in the probe request itself counts: not cron, Action Scheduler or
  other loopback requests, not queries that bypass `$wpdb`, and not work in the
  plugin's own shutdown functions.

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

Two word tags sit alongside the versions, and they are not interchangeable:

- `nightly` is published from `trunk`, automatically, by
  `.github/workflows/publish-activation-test-package.yml` on every push that
  touches this directory. `trunk` is the line in development, so this is the
  package for a WooCommerce that has no release yet.
- `latest` follows the newest published stable line and is published by hand
  from that line's source, the same way the two-segment versions are. Nothing
  publishes it automatically any more. Leaving it behind means every WooCommerce
  older than the newest published line runs a suite written for a newer one.

Three consequences worth knowing:

- A fix that is not version-specific has to reach every live branch. Land it on
  `trunk` and cherry-pick.
- A WooCommerce version with no package of its own splits two ways. Ahead of
  every published line and not released yet — a nightly, a prerelease, a `-dev`
  build — it runs `nightly`. Anything else runs `latest`. A released line with
  no package of its own stays on `latest` on purpose: `stable` resolves to it,
  so that is the path a plain `qit run:activation` takes.
- Neither tag is written for the version it ends up running against, so a
  selector gate cannot help there; publishing a version for the line is the fix.
  This is where a new line lands every cycle, so publish for it as soon as QIT
  offers it.

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
