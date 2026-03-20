# Docker Plugin for RaspAP

A Docker container management GUI plugin for RaspAP. Provides container, image, volume, and Compose project management through the RaspAP web interface.

## Commands

```bash
# No local build step — PHP is interpreted, JS is vanilla
# Lint PHP
composer lint          # requires composer install first
composer phpcs         # PSR-2 code style check

# Release a new version
./release.sh v1.0.1   # bumps manifest.json, commits, tags, pushes
```

## Architecture

```
Docker.php              → Plugin entry point (implements PluginInterface)
DockerService.php       → Docker CLI wrapper (containers, images, volumes, compose, daemon)
DockerJobManager.php    → Background job tracking (pull, compose up/down)
DockerHubClient.php     → Docker Hub v2 search API client
DockerUpdateService.php → Self-update via git tags

ajax/                   → AJAX endpoints (one per action type)
  docker_action.php       → Container start/stop/rm, image delete, daemon start, inspect
  docker_compose_action.php → Compose up/down/pull/restart
  docker_create_container.php → Container creation with full params
  docker_hub_search.php   → Docker Hub search
  docker_image_pull.php   → Background image pull via DockerJobManager
  docker_job_status.php   → Poll background job status
  docker_logs.php         → Container log retrieval with timeout
  docker_update_check.php → Plugin update check via GitHub Tags API
  docker_update_apply.php → Plugin self-update execution
  docker_volume_browse.php → Volume filesystem browser

app/js/Docker.js        → All frontend logic (jQuery, polling engine, tab handlers)

templates/
  main.php              → Tab container layout + script/CSS loading
  tabs/                 → One template per tab (status, containers, images, compose, volumes, about)

config/
  docker_plugin_update.sh → Self-update shell script (deployed to /etc/raspap/docker/)
```

## Deployment Model (CRITICAL — read before making changes)

RaspAP has a two-location deployment for plugins that you MUST understand:

1. **Plugin directory**: `/var/www/html/plugins/Docker/` — the git repo, source of truth
2. **Webroot JS copy**: `/var/www/html/app/js/plugins/Docker.js` — a STALE copy made by PluginInstaller at install time, NEVER updated automatically

### How updates reach the browser

| File type | Served from | Updated by git checkout? | Needs extra step? |
|-----------|-------------|--------------------------|-------------------|
| PHP templates | Plugin directory (directly) | Yes | Restart lighttpd (clear PHP opcache) |
| AJAX endpoints | Plugin directory (directly) | Yes | Restart lighttpd (clear PHP opcache) |
| JavaScript | **Depends on script tag** | Only if loaded from plugin dir | See below |
| Static assets (CSS, images) | Plugin directory (directly) | Yes | Browser cache-bust |

### JS loading — the copy trap

The PluginInstaller copies JS files to `app/js/plugins/` at install time via `copyJavaScriptFiles()`. If main.php loads from that copy (`app/js/plugins/Docker.js`), the JS is PERMANENTLY stale after any update.

**Solution**: main.php MUST load JS from the plugin directory:
```html
<!-- WRONG — loads stale copy that never updates -->
<script src="app/js/plugins/Docker.js"></script>

<!-- CORRECT — loads directly from plugin git repo -->
<script src="plugins/Docker/app/js/Docker.js"></script>
```

### PHP opcache — the invisible cache

PHP opcache caches compiled PHP files in memory. After `git checkout` changes files on disk, PHP still serves the old cached versions. Templates, AJAX endpoints, and service classes are ALL affected.

**Solution**: The update script MUST restart lighttpd after checkout:
```bash
systemctl restart lighttpd
```

Without this, template changes (like adding `type="button"` to fix form submission) will NOT take effect even though the files on disk are correct.

### Self-update flow

1. JS calls `docker_update_check.php` → PHP queries GitHub Tags API → returns latest version
2. If update available, user clicks "Update" → JS calls `docker_update_apply.php`
3. PHP runs `docker_plugin_update.sh` via sudo
4. Shell script: `git fetch --tags --force` → `git checkout vX.Y.Z` → restart lighttpd
5. User reloads page → new PHP templates and JS are active

## Key Patterns

- **Plugin interface**: Implements `RaspAP\Plugins\PluginInterface` from upstream webgui
- **Namespace**: `RaspAP\Plugins\Docker`
- **All Docker CLI calls** use `sudo /usr/bin/docker` with `escapeshellarg()` — sudoers whitelist in `manifest.json`
- **Background jobs**: Long operations (image pull, compose up) run async via `DockerJobManager`, polled from JS
- **AJAX auth**: Every handler includes `autoload.php`, `CSRF.php`, `session.php`, `config.php`, `authenticate.php` from upstream
- **AJAX paths**: Relative `../../../includes/` (plugin installs at `/var/www/html/plugins/Docker/`)
- **Data persistence**: Serialized to `/tmp/plugin__Docker.data` (cleared on reboot)
- **Config path**: `RASPI_DOCKER_CONFIG` = `/etc/raspap/docker`

## Gotchas

### Deployment
- **JS copy trap**: PluginInstaller copies JS once at install. Updates via git do NOT update the copy. Load JS from plugin dir, not the copy. See "Deployment Model" above.
- **PHP opcache**: `git checkout` does not clear opcache. MUST restart lighttpd after updating files.
- **`replace_all` on utility functions**: If you do a bulk find-replace (e.g. replacing `JSON.parse(data)` with a wrapper), check that you didn't also replace the call INSIDE the wrapper itself, creating infinite recursion.

### HTML / Bootstrap
- **All templates are inside a `<form>` in main.php** (line 27). Every `<button>` MUST have `type="button"` or it will default to `type="submit"` and submit the form instead of running its JS handler. This causes HTML5 validation errors on hidden `required` inputs (e.g. Create Container modal).
- **Bootstrap JS is v5.3.3** (loaded from `bootstrap.bundle.min.js`). The non-minified JS files in the dist folder are stale v4.3.1 leftovers — ignore them.
- **Bootstrap CSS is v5.3.3**. Use `data-bs-toggle`, `data-bs-dismiss` (BS5 syntax), not `data-toggle` (BS4).
- **Modals**: Use `bootstrap.Modal.getOrCreateInstance(el).show()` — NEVER `new bootstrap.Modal(el)` which throws on second open because an instance already exists.

### jQuery / AJAX
- **jQuery may auto-parse JSON responses** even without `Content-Type: application/json`. All AJAX callbacks must handle both string and object `data`. Use the `dockerParseJSON(data)` utility.
- **Never set Content-Type `application/json`** on AJAX endpoints — causes a jQuery auto-parse bug.
- **Button colors in dark mode**: Upstream `dark.css` applies `opacity: 75%` to all `.btn`. Outline button variants (`btn-outline-*`) become unreadable. Use solid variants (`btn-secondary`, `btn-danger`, etc.) for action buttons.

### Infrastructure
- Plugin runs as `www-data` — all Docker/git/systemctl commands need sudoers entries in `manifest.json`
- `manifest.json` is both plugin metadata AND version source for self-update
- Keep the Docker object in `plugins/manifest.json` (the registry) in sync with plugin `manifest.json`
- No composer.json — PHP deps come from upstream webgui's autoloader

## Rules for Making Changes

These rules exist because v1.0.2 through v1.0.5 all shipped broken. Every one of them could have been caught before pushing.

### 1. Run the code, not just read it

Reading source files is not verification. Grep counts and line numbers do not prove code works. Before claiming any fix:
- Run JavaScript through node to confirm it doesn't crash (e.g. test `dockerParseJSON` with both string and object input)
- Run `php -l` on changed PHP files to catch syntax errors
- Trace the full execution path: button click → event handler → AJAX call → PHP endpoint → response → callback. If any step is untested, the fix is unverified.
- Never connect to network devices without explicit confirmation of the IP/hostname from the user.

### 2. Check existing working code before writing new code

Before fixing a broken button, look at a working button. Before writing CSS overrides, check what the readable buttons use. Before building a utility function, check if the existing pattern is simpler. Match what already works.

### 3. After bulk operations, verify the result — especially inside the thing you just created

`replace_all` is dangerous. After any bulk find-replace:
- Read the changed file and verify every occurrence, especially any occurrence inside a function that IS the replacement
- Run the code (node, php -l, or browser) to confirm it doesn't crash

### 4. One fix, verify, then next fix

Do not stack multiple fixes into one release without verifying each one. If fix A is broken, fixes B and C shipped on top of it are also broken. Verify A works before adding B.

### 5. Understand the deployment path before claiming a fix is deployed

Before pushing any tag, be able to answer: "How does this change get from the git repo to the user's browser?" If the answer involves a copy step, a cache, or a restart, the fix isn't done until those steps happen.

## Code Style

- PSR-2 (enforced via phpcs in dev/psr2 branch)
- PHP 8.2+ with typed properties and return types
- Vanilla JavaScript (jQuery 3.5+, no build step)
- Bootstrap 5.3.3 for UI components
