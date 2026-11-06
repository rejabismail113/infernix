#!/usr/bin/env bash
# Restore test (NFR-06, M12-06): restores the newest encrypted backup into a scratch database, runs integrity
# checks (balanced journals, integrity triggers, migrations, users) and drops it. Exit code 0 = pass.
# Usage: make restore-test   (inside Docker)   or   scripts/restore-test.sh   (host with php + pg tools)
set -euo pipefail
cd "$(dirname "$0")/.."
if command -v docker >/dev/null 2>&1 && docker compose ps app >/dev/null 2>&1; then
  docker compose exec -T app php artisan backup:run restore-test
else
  php artisan backup:run restore-test
fi
