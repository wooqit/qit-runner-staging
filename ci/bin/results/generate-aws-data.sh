#!/usr/bin/env bash

# Ensures that all code is executed relative to this script.
cd "$(dirname "${BASH_SOURCE[0]}")"

EXPIRATION_TIMESTAMP=
FILE=./presign.json

# Set timestamp based on OS
if [[ "$OSTYPE" == "linux-gnu"* ]]; then
  EXPIRATION_TIMESTAMP=$(date -d "+7 days" +%s)
elif [[ "$OSTYPE" == "darwin"* ]]; then
  EXPIRATION_TIMESTAMP=$(date -v+7d +%s)
fi

URL=$BUCKET.$S3_DOMAIN/$S3_ROOT/$OBJECT_ID/index.html

cat << END >> $FILE
{
  "url": "$URL",
  "expiration": "$EXPIRATION_TIMESTAMP"
}
END

cat $FILE

ls
