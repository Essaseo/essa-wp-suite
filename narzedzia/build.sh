#!/usr/bin/env bash
# Lint + testy + paczka ZIP. Uruchom z katalogu projektu: bash narzedzia/build.sh
set -e
cd "$(dirname "$0")/.."

PHP="${PHP:-$USERPROFILE/php83/php.exe}"
[ -x "$PHP" ] || PHP=php

VERSION=$(grep -m1 "^ \* Version:" essa-wp-suite/essa-wp-suite.php | sed 's/.*Version:[[:space:]]*//' | tr -d '\r')
echo "== ESSA WP Suite $VERSION"

echo "== lint"
find essa-wp-suite -name '*.php' -print0 | while IFS= read -r -d '' f; do
    "$PHP" -l "$f" | grep -v "No syntax errors" && exit 1 || true
done

echo "== testy"
"$PHP" tests/test-encoder.php | tail -1
"$PHP" tests/smoke.php | tail -1

echo "== pot"
"$PHP" narzedzia/make-pot.php

echo "== zip"
mkdir -p dist
rm -f "dist/essa-wp-suite-$VERSION.zip"
if command -v zip >/dev/null 2>&1; then
    zip -qr "dist/essa-wp-suite-$VERSION.zip" essa-wp-suite -x '*.DS_Store' -x '*/.git*'
else
    python - "$VERSION" <<'PY'
import os, sys, zipfile
v = sys.argv[1]
with zipfile.ZipFile(f"dist/essa-wp-suite-{v}.zip", "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk("essa-wp-suite"):
        for f in sorted(files):
            if f == ".DS_Store": continue
            path = os.path.join(root, f)
            z.write(path, path.replace(os.sep, "/"))
PY
fi
ls -la "dist/essa-wp-suite-$VERSION.zip"
