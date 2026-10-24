#!/usr/bin/env bash
# Idempotent setup for the "Memories for KodBox" plugin development environment.
#
# It prepares three things:
#   1. PHP CLI + the extensions the plugin and its PHPUnit-style tests need.
#   2. The sibling `recognize` plugin, checked out next to this repo so the
#      repo's own tests/SchemaTest.php (which reads ../recognize) passes.
#   3. A local KodBox install with this plugin (and recognize) symlinked in,
#      so the plugin can be exercised end to end through a real KodBox stack.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"                       # plugin repo root (memories)
RECOGNIZE_DIR="$(dirname "$REPO_DIR")/recognize"          # sibling expected by SchemaTest
KODBOX_DIR="$HOME/kodbox"
KODBOX_PORT="${KODBOX_PORT:-8080}"
ADMIN_USER="${KODBOX_ADMIN_USER:-admin}"
ADMIN_PASS="${KODBOX_ADMIN_PASS:-Memories#2026}"

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

# ---------------------------------------------------------------------------
# 1. PHP toolchain
# ---------------------------------------------------------------------------
need_php_ext() { php -m 2>/dev/null | grep -qix "$1"; }
PHP_OK=1
if ! command -v php >/dev/null 2>&1; then PHP_OK=0; fi
for ext in sqlite3 gd exif mbstring curl xml zip; do
  need_php_ext "$ext" || PHP_OK=0
done
if [ "$PHP_OK" -ne 1 ]; then
  log "Installing PHP CLI and extensions"
  sudo DEBIAN_FRONTEND=noninteractive apt-get update -y
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
    php-cli php-sqlite3 php-gd php-mbstring php-xml php-curl php-zip \
    php-bcmath php-intl php-mysql php-gmp unzip git curl
else
  log "PHP already present: $(php -v | head -1)"
fi

# ---------------------------------------------------------------------------
# 2. Sibling recognize plugin (required by tests/SchemaTest.php + tag/people features)
# ---------------------------------------------------------------------------
if [ ! -f "$RECOGNIZE_DIR/lib/data/sqlite.sql" ]; then
  log "Cloning recognize sibling into $RECOGNIZE_DIR"
  PARENT="$(dirname "$RECOGNIZE_DIR")"
  if [ ! -w "$PARENT" ]; then
    sudo mkdir -p "$RECOGNIZE_DIR"
    sudo chown "$(id -u):$(id -g)" "$RECOGNIZE_DIR"
  fi
  # The cloud git config rewrites https://github.com/ to include the access
  # token (see repositoryDependencies in environment.json), so no secret is
  # embedded here.
  git clone --depth 1 https://github.com/d1ngx/recognize.git "$RECOGNIZE_DIR"
else
  log "recognize sibling already present at $RECOGNIZE_DIR"
fi

# ---------------------------------------------------------------------------
# 3. KodBox host application
# ---------------------------------------------------------------------------
if [ ! -f "$KODBOX_DIR/index.php" ]; then
  log "Cloning KodBox into $KODBOX_DIR"
  git clone --depth 1 https://github.com/kalcaddle/kodbox.git "$KODBOX_DIR"
else
  log "KodBox already present at $KODBOX_DIR"
fi

log "Linking plugins into KodBox"
ln -sfn "$REPO_DIR" "$KODBOX_DIR/plugins/memories"
ln -sfn "$RECOGNIZE_DIR" "$KODBOX_DIR/plugins/recognize"

# Dev router for the PHP built-in server: serve real static files, route the
# rest through KodBox's front controller.
if [ ! -f "$KODBOX_DIR/router.php" ]; then
  cat > "$KODBOX_DIR/router.php" <<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';
PHP
fi

# ---------------------------------------------------------------------------
# 4. First-run KodBox install (SQLite) — idempotent via install.lock
# ---------------------------------------------------------------------------
if [ ! -f "$KODBOX_DIR/data/system/install.lock" ]; then
  log "Installing KodBox (SQLite, admin=$ADMIN_USER)"
  ( cd "$KODBOX_DIR" && php index.php install/index/auto \
      --database sqlite --user-name "$ADMIN_USER" --user-pass "$ADMIN_PASS" )
else
  log "KodBox already installed"
fi

# ---------------------------------------------------------------------------
# 5. Enable plugins + seed sample photos (best effort) — idempotent via marker
# ---------------------------------------------------------------------------
READY_MARKER="$KODBOX_DIR/data/.memories-env-ready"
if [ ! -f "$READY_MARKER" ]; then
  log "Enabling plugins and seeding demo photos"
  TMP_PORT=18080
  ( cd "$KODBOX_DIR" && php -S 127.0.0.1:$TMP_PORT router.php >/tmp/kodbox-setup-server.log 2>&1 ) &
  SRV_PID=$!
  trap 'kill "$SRV_PID" >/dev/null 2>&1 || true' EXIT

  BASE="http://127.0.0.1:$TMP_PORT"
  CJ="$(mktemp)"
  for _ in $(seq 1 30); do
    curl -sf -o /dev/null "$BASE/" && break || sleep 0.5
  done

  TOKEN="$(curl -s -c "$CJ" -b "$CJ" -X POST "$BASE/?user/index/loginSubmit" \
    --data-urlencode "name=$ADMIN_USER" --data-urlencode "password=$ADMIN_PASS" \
    | php -r '$j=json_decode(file_get_contents("php://stdin"),true); echo $j["info"] ?? "";')"

  if [ -n "$TOKEN" ]; then
    for app in fileThumb memories; do
      curl -s -b "$CJ" -c "$CJ" -X POST "$BASE/?admin/plugin/changeStatus" \
        --data-urlencode "app=$app" --data-urlencode "status=1" \
        --data-urlencode "accessToken=$TOKEN" >/dev/null || true
    done

    # Seed a few GPS/EXIF sample photos so the timeline + map are populated.
    SAMPLES_DIR="$(mktemp -d)"
    GOT_SAMPLES=0
    for f in DSCN0010.jpg DSCN0021.jpg DSCN0025.jpg; do
      if curl -sf -L -o "$SAMPLES_DIR/$f" \
          "https://raw.githubusercontent.com/ianare/exif-samples/master/jpg/gps/$f"; then
        curl -s -b "$CJ" -c "$CJ" -X POST "$BASE/?explorer/upload/fileUpload" \
          -F "path={source:8}/" -F "accessToken=$TOKEN" -F "file=@$SAMPLES_DIR/$f" >/dev/null \
          && GOT_SAMPLES=1 || true
      fi
    done
    rm -rf "$SAMPLES_DIR"

    if [ "$GOT_SAMPLES" -eq 1 ]; then
      curl -s -b "$CJ" -c "$CJ" -X POST "$BASE/?plugin/memories/scan" \
        --data-urlencode "accessToken=$TOKEN" >/dev/null || true
    fi
    touch "$READY_MARKER"
  else
    echo "WARN: could not authenticate to KodBox; skipping plugin enable/seed (do it via the admin UI)."
  fi

  rm -f "$CJ"
  kill "$SRV_PID" >/dev/null 2>&1 || true
  trap - EXIT
else
  log "Plugins already enabled and demo data seeded"
fi

log "Setup complete. KodBox: http://localhost:$KODBOX_PORT/  (login: $ADMIN_USER / $ADMIN_PASS)"
