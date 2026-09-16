#!/usr/bin/env bash
#
# Builds the two zips that go on the server. No shell is available there, so
# these are made here and uploaded through the file manager.
#
#   bash bin/build-release.sh
#
#   dist/plan.swytch.graphics.zip   -> the document root
#   dist/coverapp.zip               -> /home/om44wfu4/coverapp
#
# config.php is never in either: it holds the API key and is written by hand on
# the server, outside the web root.

set -euo pipefail
cd "$(dirname "$0")/.."

rm -rf dist && mkdir -p dist/staging

# --- the document root ------------------------------------------------------
cp -R public dist/staging/docroot
( cd dist/staging/docroot && zip -qr ../../plan.swytch.graphics.zip . -x '.DS_Store' )

# --- the application, above the document root -------------------------------
mkdir -p dist/staging/coverapp
cp -R src bin db content dist/staging/coverapp/
rm -f dist/staging/coverapp/bin/build-release.sh
( cd dist/staging/coverapp && zip -qr ../../coverapp.zip . -x '.DS_Store' )

# Uploaded only if the password hash has to be made through a browser, and
# deleted straight afterwards — so it is kept out of the docroot zip on purpose.
cp tools/password-hash.php dist/password-hash.php

rm -rf dist/staging

echo "Built:"
for z in dist/*.zip; do
  printf "  %-34s %6s KB, %3s files\n" "$z" "$(( $(stat -c%s "$z") / 1024 ))" "$(unzip -Z1 "$z" | wc -l)"
done
printf "  %-34s %6s KB   (upload only if needed, then delete)\n" "dist/password-hash.php" "$(( $(stat -c%s dist/password-hash.php) / 1024 ))"
echo
echo "Neither zip contains config.php:"
for z in dist/*.zip; do
  if unzip -Z1 "$z" | grep -q '^config\.php$'; then
    echo "  !! $z DOES — stop and fix bin/build-release.sh"; exit 1
  fi
done
echo "  confirmed."
