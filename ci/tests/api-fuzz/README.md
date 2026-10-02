# QIT schema-driven API fuzzing PoC

This is an internal, plugin-only QIT test type for evaluating whether WordPress REST route
metadata can drive useful API fuzzing. It is deliberately separate from WooCommerce.com release
automation and from QIT's existing security tests. The PoC is dispatched only for a curated
evaluation cohort.

## Campaign workflow

1. The dedicated `cd-test-api-fuzz` workflow downloads the SUT and its activation stack.
2. The existing QIT CLI `env:up` command provisions a normal WordPress/WooCommerce environment,
   with automatic plugin activation disabled.
3. The test package seeds a small set of Woo entities, exports the REST registry, activates the SUT,
   and exports the registry again. Seeding happens before the baseline so the route delta measures
   only SUT activation.
4. The Python runner classifies activation changes as SUT-owned or shared-modified. Newly added
   operations and modified operations dispatched by SUT callback files are primary targets.
   Pre-existing WooCommerce, WordPress, or dependency routes whose metadata changed are recorded as
   shared integration diagnostics and excluded from the primary campaign. For duplicate route
   handlers, the runner models WordPress's first-eligible-handler dispatch behavior.
5. Route regexes and argument metadata are converted to OpenAPI 3.0.3. Each operation is reported as
   `complete`, `partial`, `untyped`, or `unsupported`, including any constraints lost in conversion.
   Distinct raw routes that collapse to the same OpenAPI path and method are explicitly unsupported
   rather than silently overwriting one operation.
6. Schemathesis first generates one fuzz example for every usable SUT-owned added operation,
   anonymously and as an administrator, then does the same for SUT-owned modified operations. Only
   after that breadth pass can a profile spend its remaining independent quota on additional
   examples per operation. The generic coverage phase is excluded because it can exhaust the
   campaign on the first operation.
7. Generation restores the clean database snapshot when it moves to a new operation; examples for
   that operation share a batch. Marked requests cannot send external HTTP requests or email.
8. Candidate 5xx responses and PHP fatals are reduced to the smallest observed request for that
   operation/fault and must reproduce in two additional clean-state replays before becoming a
   finding. Reset, transport, and instrumentation failures are retried twice; if two clean replays
   still cannot complete, the campaign is `partial` with `confirmation_infrastructure_error`.
9. The compact result is sent to QIT Manager. Detailed ledgers, tool reports, and content-addressed
   exact bodies for requests larger than 64KB remain in a private GitHub artifact for 14 days.

The hard campaign limits are 20 minutes and 2,500 requests. Request and wall-time reserves are held
back for confirmation, and the remaining generation budget is divided equally between anonymous and
administrator profiles. A campaign is complete only when every usable operation was exercised under
both profiles and confirmation finished; otherwise it reports `partial` rather than a clean pass.
`campaign.scheduling` records each breadth/depth stage, its quota outcome, and per-profile coverage.

Administrator generation, confirmation replay, and protected instrumentation polling all use
`QIT_API_FUZZ_ADMIN_USER` and `QIT_API_FUZZ_ADMIN_PASSWORD`, defaulting to `admin` and `password`.
The Schemathesis hooks and campaign runner intentionally share this credential source.

For request bodies larger than 64KB, a finding's redacted curl uses
`--data-binary @request-bodies/<sha256>.bin`, relative to the downloaded private artifact root. The
body file is hash- and size-verified before confirmation replay.

Finding cURL is emitted as a multiline reproduction template rather than a command tied to the
destroyed runner environment. Set `QIT_SITE_URL` to an equivalent disposable test site; administrator
findings also use `QIT_ADMIN_USER` and `QIT_ADMIN_PASSWORD` placeholders. Transport and harness
correlation headers are omitted, while behavior-affecting request headers and the QIT fuzz marker are
retained. If a confirmed JSON payload is larger than 256 bytes, the runner performs a bounded
structural reduction and retains it only when the same finding reproduces in two more clean-state
replays. The Manager report presents that minimized body as copyable JSON alongside a short cURL that
references a local file. The full captured request remains available as secondary diagnostic evidence.

The isolation scope is explicit in every result: `env:reset` restores the database and flushes the
object cache, while the harness blocks marked-request HTTP and email side effects. It does not
restore filesystem mutations. The environment is disposable, but filesystem-dependent findings
need extra scrutiny during the PoC evaluation.

The database snapshot is restored before each generated operation batch. Confirmation replays always
reset before every replay, so a state-dependent fault that only surfaces inside a shared generation
batch fails to reproduce and is not reported. The result records
`campaign.isolation.generation_reset_strategy=operation_batch`; `campaign.overhead` reports
generation/confirmation time plus reset count and duration so the evaluation can verify the expected
throughput improvement without overstating the isolation boundary.

Because each profile runs both anonymously and as an administrator, an anonymous request that reaches
a write callback (`POST`/`PUT`/`PATCH`/`DELETE`) and receives a 2xx is recorded as an
`anonymous_write_accepted` anomaly — a differential observation of a possible missing permission
check. It is deduplicated per operation, surfaced for evaluation review only, and never scored as a
finding.

Expected WordPress/WooCommerce domain rejections that use a 5xx status are classified by their exact
JSON error `code`, not by route or status. The small code allowlist records an
`expected_5xx_response_ignored` anomaly without confirmation replay. A PHP fatal, an unknown error
code, or a different 5xx on the same route remains a finding candidate, so this exception cannot
mask a future crash merely because it shares an endpoint or response status.

## Running a campaign

Real-extension campaigns are dispatched on the Manager. An operator with shell access to the Manager
server triggers one run for an extension with a single command:

```bash
wp cd api-fuzz enqueue --slug=woocommerce-bookings
```

or the curated evaluation cohort in one command:

```bash
wp cd api-fuzz eval-set --slugs=woocommerce-bookings,woocommerce-subscriptions
```

Both print the created test-run ID(s), enqueue internal `api-fuzz` run(s), and dispatch the
`cd-test-api-fuzz` workflow, which provisions a disposable environment and returns a compact result
to the Manager. Optional `--woocommerce-version`, `--wordpress-version`, `--php-version`, and
`--event` flags override the defaults (`stable` WordPress/WooCommerce, PHP 8.3, notifications off);
unknown slugs in an `eval-set` are skipped with a notice rather than aborting the cohort.

`wp cd api-fuzz` is a thin wrapper over `ApiFuzzScheduler` and is the operator interface. The Python
`runner.py` entry point and its environment variables are the CI plumbing that the workflow wraps,
not something a person runs by hand. A public `qit run:api-fuzz` CLI command (mirroring the other
`qit run:<type>` commands) is a possible future front-end for the same dispatch; see the
[QIT CLI boundary](#qit-cli-boundary) below.

## Result and reporting model

`qit_api_fuzz.result` defines and validates the `1.0.0` cross-repository result contract. Every run
contains campaign, discovery, schema-usability, reachability, finding, suppression, anomaly, error,
and artifact sections. Campaign state is one of:

- `completed`: both profiles finished within their budgets;
- `partial`: useful observations exist, but the campaign did not fully finish;
- `not_applicable`: activating the SUT exposed no SUT-owned REST operations; shared route metadata
  changes may still be recorded as excluded diagnostics;
- `unavailable`: the environment, tool, or result contract failed.

QIT Manager persists normalized findings. Route ownership and fault origin are separate fields: a
SUT-owned route can still fail inside WooCommerce or WordPress, and the report shows both facts.
An `api_fuzz` metric builder and its contract tests are
included for evaluation, but the builder is intentionally not registered in `MetricsAggregator`
while this remains an internal PoC with no WooCommerce.com consumer. Confirmed faults whose fatal
file is inside the SUT are emitted as SUT-attributed error evidence; other confirmed 5xx or fatal
observations are warnings. This attribution is evidence, not marketplace policy.

## False-positive suppressions

Suppressions live in `suppressions.json` and match only the SHA-256 finding fingerprint. A record
may include `owner`, `reason`, and ISO `expires_at` fields. Expired, malformed, or non-exact entries
are ignored. Applied suppressions remain counted and auditable in the result but do not create a
normalized actionable finding.

Example:

```json
[
  {
    "fingerprint": "<64-character SHA-256>",
    "owner": "team-name",
    "reason": "Known framework response; upstream issue linked here",
    "expires_at": "2026-12-31"
  }
]
```

## QIT CLI boundary

The PoC does not add a public `run:api-fuzz` command or synchronize a new public CLI test type. The
runner composes existing `env:up`, `env:reset`, and `env:down` primitives around a test package, and
`ApiFuzzScheduler` is invoked only by the operator command shown in
[Running a campaign](#running-a-campaign) — it is deliberately not wired into cron, release
orchestration, `MassTestRunsQueue`, or marketplace-wide sweeps while this remains an evaluation
tool. A dedicated public command (`qit run:api-fuzz`, mirroring the other `qit run:<type>` commands,
or a `wp cd` subcommand for the server-side operator flow) should be considered only after the cohort
establishes that the workflow and output contract are worth supporting.

The CI runner uses the host-side `ci/tests/api-fuzz/qit` PHAR, not a CLI embedded in the WordPress
test package. `qit-source.json` records the source repository, PR/commit, build version, and PHAR
SHA-256 checksum; the runner verifies the artifact checksum independently when recording every
result. To refresh this PoC build, check out the
recorded qit-cli commit, run `make build VERSION=qit_dev_build` there, and copy the generated `qit`
binary into `ci/tests/api-fuzz/qit`. The development-build sentinel intentionally bypasses the CLI's
release update enforcement; `qit-source.json` and the reported checksum identify the exact build.
Keeping this binary API-fuzz-specific prevents an experimental reset contract from silently changing
other public runner workflows.

API-fuzz requires the additive `env:reset --json` protocol from that build. Generation and
confirmation both use the same parser, and every attempt is written to the private interaction
ledger. `campaign.overhead` retains `reset_count` and `reset_seconds_total` while also reporting
reset median/p95, strategy, caller overhead, per-phase count and duration distributions, and the
limiting phase. A failed or malformed reset is an infrastructure error and never a SUT finding.
Older environments without staged snapshot metadata use the timed copy-per-reset strategy. If a
staged snapshot is missing or unreadable, QIT verifies the retained host backup against the checksum
recorded during `env:up` before using the same copy-per-reset path; the reported strategy makes that
fallback visible in telemetry. A staged checksum mismatch, or a host backup that fails verification,
remains fail-closed.

## Validation and evaluation

Run the Python contract and conversion tests with:

```bash
PYTHONPATH=ci/tests/api-fuzz python -m unittest discover -s ci/tests/api-fuzz/tests -v
```

The initial cohort should include WooCommerce, AutomateWoo, WooCommerce Subscriptions, WooCommerce
Bookings, synthetic known-vulnerable/known-safe fixtures, and additional extensions spanning route
styles. Evaluate discovery precision, schema usability, route/auth reachability, reproducibility,
actionable unique findings, false-positive rate, runtime, and request count. Subscriptions exercises
shared namespaces, inherited controllers, and side-effecting GET routes; Bookings exercises sparse
argument metadata and regex path captures.

Matched reset and campaign measurements for this change are tracked in
[`RESET-BENCHMARK.md`](RESET-BENCHMARK.md). The report also records incomplete comparison cells so
acceptance cannot be inferred from missing data.

The evaluation report emits one `per_extension` row per counted run, including both `woo_id` and
`test_run_id`. Retries and overlapping evaluation sets therefore remain visible and reconcile with
the run-level aggregate counters instead of replacing an earlier row for the same product.

### Known-answer fixture

`test-package/fixtures/qit-api-fuzz-synthetic-sut/` is a synthetic SUT plugin whose faults are fixed
in advance, so a run against it has a verifiable expected outcome that is independent of any real
extension's robustness:

- `POST /qit-fuzz-fixture/v1/deterministic-fatal` — uncaught `Error`, HTTP 500, attributed to the fixture;
- `POST /qit-fuzz-fixture/v1/swallowed-fatal` — HTTP 200 with a fatal in shutdown, attributed to the fixture;
- `GET /qit-fuzz-fixture/v1/clean` — never faults (control).

A correct campaign therefore reports exactly two SUT-attributed findings and no finding for the clean
route. `tests/test_known_answer.py` asserts that end to end through the real diff, conversion,
confirmation, attribution, fingerprinting, exact suppression, and contract-validation code, stubbing
only the live Schemathesis generation and HTTP replay transport.

To exercise the real generator, disposable QIT environment, instrumentation, and replay transport,
install the pinned Python requirements and run:

```bash
PYTHON=/path/to/python ci/tests/api-fuzz/bin/run-known-answer.sh
```

The runner resolves the synthetic slug to the repository fixture instead of a downloaded extension.
The script fails unless the campaign completes, both planted faults reproduce and are attributed to
the fixture, and the clean route is exercised without becoming a finding. WordPress, WooCommerce,
PHP, and `QIT_HOME` can be overridden with `QIT_API_FUZZ_WORDPRESS_VERSION`,
`QIT_API_FUZZ_WOOCOMMERCE_VERSION`, `QIT_API_FUZZ_PHP_VERSION`, and `QIT_HOME`; their defaults are
`stable`, `stable`, `8.3`, and the repository-local `qit-home` directory. That QIT home must already
be connected to the desired Manager backend.

The PoC should not be promoted solely because it can generate traffic. Its value is demonstrated
only if it repeatedly reaches SUT-owned behavior and produces reproducible, reviewable defects at a
manageable false-positive and operating cost.
