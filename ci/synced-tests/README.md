### Synced tests

This directory contains tests that are synced with WooCommerce Core.

We fetch tests from WooCommerce Core and save them here to be used by QIT.

### Updating Synced Tests

To update the tests, do the following steps:

#### API Tests

- Create a new branch
- Open a terminal in the `ci` folder
- Run `php update-tests.php`
- Don't run the self-tests when updating (select `N` when the script asks)
- Deploy the manager to staging and switch your CLI to staging (`env:switch staging` or `switch staging`, depending on your CLi version)
- In the QIT CLI folder, go to the `_tests/managed_tests` and update the snapshots for the Woo API tests, first `php QITSelfTests.php update woo-api`. Note: if you run into any issues, make sure to run `composer install` in the `_tests/managed_tests` directory first.
- Commit the resulting changes and open a PR
- Review the changes for any odd changes (see the Tips below)

Note: if you run into any errors when pushing, you may need to update your git config postBuffer:

```
git config --global http.postBuffer 157286400
```

On the PR, we expect to see the following changes:

- Older tests will be removed
- New tests will be added
- The file `plugins/cd/manager/synced-with-woo.txt` will have changed to reflect that
- The delete products tests (both for the API and e2e tests) are expected to fail when updating snapshots, so these changes can be ignored.
- The no-op tests should succeed, if there's any failures we need to review those.

#### E2E Tests

- If everything is well with API, run the E2E as well with `php QITSelfTests.php update woo-e2e` and commit their snapshot as well
- This will take up to 45 minutes, and it's expected that the first run will fail, you can investigate the failures and skip them:
- Pick a "no-op" test snapshot and use it as a reference for skipping tests.

To skip a test, edit the file `ci/ci.sh` and scroll to the section that has the skips, it looks like this:

```bash
if [ "${QIT_TEST_TYPE}" == "woo-e2e" ]; then
  # Skips that applies to all versions:
  php ./bin/search-replace.php "${TEST_DIR}/playwright.config.js" "video: 'on-first-retry'" "video: 'retain-on-failure'"

  # Skips that applies for only specific versions:

  # 8.7
  if version_compare "$QIT_WOOCOMMERCE_TAG" "8.6.999" ">" && version_compare "$QIT_WOOCOMMERCE_TAG" "8.7.999" "<"; then
    php ./bin/search-replace.php "${TEST_DIR}/tests/merchant/order-emails.spec.js" "test( 'can receive completed email'" "test.skip( 'can receive completed email'"

    # Ported from 8.6
    php ./bin/search-replace.php "${TEST_DIR}/tests/merchant/order-refund.spec.js" "test( 'can issue a refund by quantity'" "test.skip( 'can issue a refund by quantity'"
  fi
fi
```

What to skip:
- On our tests, we are not interested in covering Woo Core behavior per-se, we leave that to Woo E2E. What we are interested in is running as much Woo E2E tests possible with other extensions active. Some tests don't work on our environment, so we skip them
- On the no-op snapshot, tests that were previously "pending" and now are "failed" are tests that we skipped in the previous version, and that continues to fail, so we should skip them, eg:

![image](https://github.com/Automattic/compatibility-dashboard/assets/9341686/8acd13fa-0fa8-4d02-b945-41202dba6083)

To skip that test, we would create a new version compare block and put the skips inside of it.

If you have GitHub CoPilot, you can copy and paste the test file and assertion in a comment, like this:

```
# merchant\\/create-shipping-zones.spec.js allows customer to benefit from a free Free shipping if in BC
```

And start filling the next line so that it auto-completes it for you based on the surrouding skips:

```
# merchant\\/create-shipping-zones.spec.js allows customer to benefit from a free Free shipping if in BC
php <tab>

// Will become:
php ./bin/search-replace.php "${TEST_DIR}/tests/merchant/create-shipping-zones.spec.js" "test( 'allows customer to benefit from a free Free shipping if in BC'" "test.skip( 'allows customer to benefit from a free Free shipping if in BC'"
```

Then you just delete the comment that you used to generate it and commit the skip.

Do this until there are no more tests failing on the `no-op`, at which point you can run just one more to make sure. If there is a flaky test that fails intermitently, feel free to skip it as well.

### Testing non-synced tests

- Spin up the QIT CLI repository locally
- Edit the self test environments to use the new version, eg: `_tests/api/main/env.php` file, change the `woocommerce_version` there.
- Point your local QIT CLI repo to staging with `qit switch staging`
- Deploy the Manager with the new tests to Staging
- Run the self-tests with `php QITSelfTests.php update`. Note: This will take up to one hour because of the synced E2E tests, otherwise it would be something like 5 minutes.
