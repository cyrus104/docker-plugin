#!/bin/bash
# docker_plugin_update.sh — Update Docker plugin to a specific git tag.
# Called by www-data via sudo. Validates all input before proceeding.
# Deployed to /etc/raspap/docker/docker_plugin_update.sh by manifest.
set -euo pipefail

PLUGIN_DIR="/var/www/html/plugins/Docker"
TAG="${1:-}"

# ── Validate tag format ────────────────────────────────────────────────
if [[ -z "$TAG" ]]; then
    echo "ERROR: No tag specified. Usage: $0 vX.Y.Z" >&2
    exit 1
fi

if [[ ! "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?$ ]]; then
    echo "ERROR: Invalid tag format '${TAG}'. Expected vX.Y.Z (e.g., v1.0.1)" >&2
    exit 1
fi

# ── Verify plugin directory is a git repo ──────────────────────────────
if [[ ! -d "$PLUGIN_DIR/.git" ]]; then
    echo "ERROR: ${PLUGIN_DIR} is not a git repository." >&2
    echo "The plugin must be installed via 'git clone' for auto-updates." >&2
    exit 2
fi

cd "$PLUGIN_DIR"

# ── Fetch tags from remote (30-second timeout) ────────────────────────
echo "Fetching tags from remote..."
if ! timeout 30 git fetch --tags --force 2>&1; then
    echo "ERROR: Failed to fetch tags (network timeout or remote error)." >&2
    exit 3
fi

# ── Verify the requested tag exists ───────────────────────────────────
if ! git rev-parse "refs/tags/${TAG}" >/dev/null 2>&1; then
    echo "ERROR: Tag ${TAG} not found in repository." >&2
    exit 4
fi

# ── Reset local modifications and checkout the tag ────────────────────
echo "Checking out ${TAG}..."
git reset --hard HEAD >/dev/null 2>&1
git checkout "${TAG}" 2>&1

# ── Self-update: deploy new copy of this script if present ────────────
SCRIPT_SRC="${PLUGIN_DIR}/config/docker_plugin_update.sh"
SCRIPT_DST="/etc/raspap/docker/docker_plugin_update.sh"
if [[ -f "$SCRIPT_SRC" ]]; then
    cp "$SCRIPT_SRC" "$SCRIPT_DST"
    chmod 755 "$SCRIPT_DST"
fi

# ── Ensure lighttpd whitelists plugins/ path ──────────────────────────
# lighttpd rewrites all URLs through PHP except a whitelist. AJAX calls
# to plugins/Docker/ajax/*.php and JS files need to be served directly.
LIGHTTPD_CONF="/etc/lighttpd/conf-enabled/50-raspap-router.conf"
if [[ -f "$LIGHTTPD_CONF" ]] && ! grep -q 'plugins' "$LIGHTTPD_CONF"; then
    sed -i 's#dist|app|ajax|config#dist|app|ajax|config|plugins#' "$LIGHTTPD_CONF"
    echo "Added plugins to lighttpd whitelist"
fi

# ── Copy JS to webroot (backup path for serving) ─────────────────────
JS_SRC="${PLUGIN_DIR}/app/js/Docker.js"
JS_DST="/var/www/html/app/js/plugins/Docker.js"
if [[ -f "$JS_SRC" ]]; then
    cp "$JS_SRC" "$JS_DST"
fi

# ── Restart lighttpd to clear PHP opcache ─────────────────────────────
# Without this, PHP serves stale cached versions of template files
# even though the on-disk files have been updated by git checkout.
if systemctl is-active --quiet lighttpd; then
    systemctl restart lighttpd
    echo "lighttpd restarted (PHP opcache cleared)"
fi

echo "SUCCESS: Updated to ${TAG}"
