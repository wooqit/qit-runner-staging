# QIT-1013 reset benchmark

This report is adjacent to the API-fuzz runner so the exact CLI provenance, raw reset timings, and
campaign acceptance evidence evolve with the implementation. All comparisons retain the existing
20-minute and 2,500-request campaign limits.

## Provenance

- Reset benchmark QIT CLI PR: `woocommerce/qit-cli#474`
- Reset microbenchmark QIT CLI commit: `992424a445efa30daaae033574ebd4ebc68d083b`
- Reset microbenchmark PHAR SHA-256: `078347277a76dc339cecce16233cde4f809a6a8a09fdbbb2d381a7476ffdd1a4`
- Current embedded QIT CLI PR: `woocommerce/qit-cli#475`
- Current embedded QIT CLI commit: `2fe7a8168eb6fe388cb94f4bdd77a75763c5a5ad`
- Current embedded PHAR SHA-256: `d03c011b841ba6ab122c0b73fc6501e9fdc1725206ac1974b3622fc6e0718048`
- Reset implementation: `wp db import` followed by `wp cache flush`; a direct database client was
  not introduced because the one-exec WordPress CLI path already cleared the 10% reset-duration
  gate without changing restore semantics.

## Matched reset microbenchmark

The known-answer environment was started once on WordPress `stable`, WooCommerce `stable`, and PHP
8.3. Three staged resets and three legacy resets then restored the same post-setup snapshot in the
same container. Temporarily removing only the new metadata selected the legacy fallback; the SQL
snapshot and cache semantics were unchanged.

| Strategy | Reset seconds | Median | p95 | Database import median | Cache flush median | Copy + cleanup median |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| `copy_per_reset` | 1.267, 1.178, 1.148 | 1.178s | 1.267s | 0.765s | 0.319s | 0.093s |
| `container_staged` | 0.930, 0.975, 0.981 | 0.975s | 0.981s | 0.654s | 0.257s | 0.000s |

The staged median is 17.2% lower than the matched legacy median. Database import remains the
limiting phase. This passes the reset-duration portion of the acceptance gate and confirms that the
removed copy/cleanup work is visible in the phase accounting.

## Existing campaign baseline

These pre-QIT-1013 artifacts use QIT CLI commit `4f83f2d54810f9053d76fbcd67086653f27ba5d2`
and PHAR checksum `45ab5bea1926f92f2cb07cce6371d2d90fef0ea20f87db884d20633139e2694e`.
They predate phase and depth instrumentation, so unavailable values are explicitly marked.

| Test run | SUT | WP / WC / PHP | Requests | Reset count | Reset total | Reset % | Breadth per profile | Depth | Findings |
| ---: | --- | --- | ---: | ---: | ---: | ---: | --- | --- | ---: |
| 1424299 | Bookings 3.7.0 | 7.0.2 / 10.9.4 / 8.3 | 2,094 | 258 | 413.861s | 44.7% | 59 / 59 (100%) | not recorded | 6 |
| 1424300 | Subscriptions 9.0.1 | 7.0.2 / 10.9.4 / 8.3 | 2,028 | 273 | 458.379s | 43.1% | 70 / 70 (100%) | not recorded | 0 |

## Campaign comparison status

The first optimized smoke cohort ran on 2026-07-20 after replacing the local Python 3.9 interpreter
with Python 3.12 and installing the hash-pinned Schemathesis 4.22.4 requirements. Schemathesis 4.5.0
raised its Python floor to `>=3.10`, which is also declared by the pinned 4.22.4 release. Python 3.9
therefore filters out the pinned release and sees 4.4.4 as the newest compatible version; this is an
interpreter mismatch, not package unavailability. As of 2026-07-20,
[PyPI publishes the pinned 4.22.4 release](https://pypi.org/pypi/schemathesis/4.22.4/json) and
[reports 4.24.0 as current](https://pypi.org/pypi/schemathesis/json). CI also uses Python 3.12.

The known-answer run was local against the staging Manager. Bookings and Subscriptions were
dispatched through the staging Manager and the private staging runner in workflow run
`29776793857`. All three used QIT CLI commit `32c2d9819702e804e7bdb60f877b9bd47a4e3ed6`
and PHAR SHA-256 `b69933ea9c8b02fab42752772d34e3022b56cd68288d8de422c4f6310597fb46`.

| SUT | Optimized run ID | Reset median / p95 | Median database / cache / caller | Reset % | Requests | Anonymous breadth and depth (min / median / p95 / max) | Administrator breadth and depth (min / median / p95 / max) | Findings |
| --- | --- | ---: | ---: | ---: | ---: | --- | --- | --- |
| Known answer | local smoke | 1.258s / 1.743s | 0.786s / 0.273s / 0.090s | 55.0% | 212 | 4/4; 26 / 26 / 26 / 26 | 4/4; 26 / 26 / 26 / 26 | exactly 2 SUT-attributed; two clean-state reproductions each; clean control remained clean |
| Bookings 3.7.0 | 1424331 | 1.598s / 2.137s | 0.484s / 0.813s / 0.161s | 40.1% | 2,046 | 59/59; 2 / 19 / 19 / 19 | 59/59; 1 / 19 / 19 / 19 | 5 WordPress-attributed warnings; 0 SUT-attributed |
| Subscriptions 9.0.1 | 1424332 | 1.576s / 1.984s | 0.460s / 0.866s / 0.139s | 40.9% | 2,041 | 70/70; 2 / 16 / 16 / 16 | 70/70; 1 / 16 / 16 / 16 | 0 |

Every reset in the smoke cohort used `container_staged`: 20 known-answer resets, 244 Bookings
resets, and 274 Subscriptions resets. Snapshot copy and temporary cleanup were skipped on every
attempt, and no reset phase failed. The real-extension runs both completed with 100% operation
breadth for both authentication profiles and no infrastructure errors.

Against the existing single-run baseline, Bookings reduced reset share from 44.7% to 40.1% (a
10.3% relative reduction) while preserving breadth, but requests decreased from 2,094 to 2,046.
Subscriptions reduced reset share from 43.1% to 40.9% (a 5.0% relative reduction), preserved
breadth, and increased requests from 2,028 to 2,041. Object-cache flush, rather than database import,
was the limiting phase in both staging campaigns.

Overall campaign acceptance remains open. This cohort is one optimized sample per real extension;
the acceptance contract calls for at least three matched runs per variant where runner capacity
permits. Additional matched runs must establish that the reset-duration or reset-share improvement
is at least 10% and that requests or median depth increase while both profiles preserve breadth.
