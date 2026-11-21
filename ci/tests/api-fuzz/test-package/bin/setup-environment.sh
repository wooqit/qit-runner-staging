#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${QIT_SUT_SLUG:-}" ]]; then
  echo "QIT_SUT_SLUG is required." >&2
  exit 1
fi

if [[ ! "$QIT_SUT_SLUG" =~ ^[a-z0-9][a-z0-9._-]*$ ]]; then
  echo "QIT_SUT_SLUG contains unsupported characters." >&2
  exit 1
fi

mkdir -p ./artifacts
rm -f ./artifacts/baseline-routes.json ./artifacts/sut-routes.json

# The QIT environment is deliberately started with automatic activation disabled. This lets the
# route registry be observed before and after the SUT is activated, which is the primary ownership
# boundary for inherited Woo controllers and dynamically registered routes.
if [[ "$QIT_SUT_SLUG" != "woocommerce" ]]; then
  wp plugin activate woocommerce --quiet
fi

IFS=',' read -ra STACK_SLUGS <<< "${QIT_API_FUZZ_STACK:-}"
for plugin_slug in "${STACK_SLUGS[@]}"; do
  if [[ -z "$plugin_slug" || "$plugin_slug" == "$QIT_SUT_SLUG" || "$plugin_slug" == "woocommerce" ]]; then
    continue
  fi
  if [[ ! "$plugin_slug" =~ ^[a-z0-9][a-z0-9._-]*$ ]]; then
    echo "Ignoring invalid activation-stack slug: $plugin_slug" >&2
    continue
  fi
  wp plugin activate "$plugin_slug" --quiet
done

# Seed a small set of valid Woo entities. This improves reachability for route parameters without
# making endpoint-specific success a prerequisite for useful fuzzing. Seed before the baseline so
# the route delta measures only the effect of activating the SUT.
wp eval-file ./bootstrap/seed.php

wp qit-api-fuzz routes --output="$(pwd)/artifacts/baseline-routes.json"
wp plugin activate "$QIT_SUT_SLUG" --quiet
wp qit-api-fuzz routes --output="$(pwd)/artifacts/sut-routes.json"

test -s ./artifacts/baseline-routes.json
test -s ./artifacts/sut-routes.json
