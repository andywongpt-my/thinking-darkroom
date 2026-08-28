#!/usr/bin/env bash
# Vercel static-phase build for Thinking Darkroom.
# Runtime-only deployment scaffolding — not part of certified application code.
set -euo pipefail
cd "$(dirname "$0")"

rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php
npm ci --no-audit --no-fund
npm run build
mkdir -p vercel-out/build
cp -r public/build/. vercel-out/build/
cp public/favicon.ico public/robots.txt vercel-out/
echo "vercel-out ready"
