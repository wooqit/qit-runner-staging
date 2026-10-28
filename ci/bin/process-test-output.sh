#!/bin/bash
#
# Processes test output for TEST_RESULT_JSON and DEBUG_LOG.
#
# Usage: process-test-output.sh <expected_json_file> <output_log_file>
#
# Sets:
#   TEST_RESULT_JSON - The JSON content if file exists, empty otherwise
#   DEBUG_LOG        - Path to the output log for error analysis
#
# This script is designed to be used by CI workflows to capture test output
# and set environment variables for the results CLI to send to the manager.
#

set -e

EXPECTED_JSON="${1:?Expected JSON file path required}"
OUTPUT_LOG="${2:?Output log file path required}"

echo "=== Process Test Output Debug ==="
echo "Expected JSON path: $EXPECTED_JSON"
echo "Output log path: $OUTPUT_LOG"
echo "GITHUB_ENV: $GITHUB_ENV"

if [[ -f "$EXPECTED_JSON" ]]; then
    # Normal case: JSON was generated
    json_content=$(jq -c . "$EXPECTED_JSON")
    echo "TEST_RESULT_JSON=$json_content" >> "$GITHUB_ENV"
    echo "Test completed with JSON output."
else
    echo "No JSON output generated at $EXPECTED_JSON."
fi

# Always set DEBUG_LOG if the output file exists (for fatal detection)
if [[ -f "$OUTPUT_LOG" ]]; then
    echo "DEBUG_LOG=$OUTPUT_LOG" >> "$GITHUB_ENV"
    echo "Debug log captured at $OUTPUT_LOG"
    echo "=== Output log content (first 500 chars) ==="
    head -c 500 "$OUTPUT_LOG"
    echo ""
    echo "=== End of preview ==="
else
    echo "No output log found at $OUTPUT_LOG."
    echo "Listing env/output directory:"
    ls -la "$(dirname "$OUTPUT_LOG")" 2>&1 || echo "Directory does not exist"
fi
