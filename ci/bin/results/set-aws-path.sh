#!/usr/bin/env bash

ID=$(uuidgen)-$(uuidgen)

AWS_PATH="$BUCKET_URL/$S3_ROOT/$ID"

echo "::set-output name=report_path::$AWS_PATH"
echo "::set-output name=id::$ID"
