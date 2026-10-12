# OpenWiki

OpenWiki is a self-hosted knowledge base and enterprise wiki written for PHP 8.2+ and MySQL 8/MariaDB.

## Requirements

- PHP 8.2+
- MySQL 8 or compatible MariaDB
- PHP extensions: PDO, pdo_mysql, mbstring, OpenSSL, DOM, Phar, cURL, LDAP, ZIP, GD
- Apache with `mod_rewrite` (repository-root deployment) or a web server whose document root points to `public/`
- writable `storage/` directories

Composer installs PHP runtime dependencies and development/test tools. Node.js 22+ is needed only when building assets from source. Production HTTP requests are handled entirely by PHP. On Linux x64/arm64, `bash scripts/build-editor.sh` can use a temporary, checksum-verified Node.js 22 toolchain if the system version is older; it removes that toolchain after building.

## Install from Git

```bash
git clone https://github.com/chmajster/OpenWIki.git
cd OpenWIki
composer install --no-dev --prefer-dist
npm ci --include=dev
npm run build
```

For Apache, the repository can be extracted directly into the virtual host web root. The included root `index.php` and `.htaccess` route requests into the application while blocking direct access to internal directories. The Apache virtual host must allow `.htaccess` overrides and `mod_rewrite` must be enabled. Nginx and hardened production deployments should still point the document root directly to `public/`.

On the first HTTP request OpenWiki automatically redirects to `/install`. The four-step browser wizard checks the runtime, collects instance settings, configures MySQL/MariaDB, applies migrations, seeds RBAC and system templates, writes `.env`, creates the installation lock and creates the initial Super Admin account.

The default first-login credentials are `admin` / `admin`. The account is marked for mandatory password change immediately after the first successful sign-in. The installation lock prevents the graphical installer from running again.

## Production installer

For a fresh Debian/Ubuntu or RHEL/Rocky/Alma/Fedora host, run:

```bash
sudo ./install.sh
```

The script installs and starts Nginx, MariaDB and PHP-FPM, installs Composer dependencies, builds static editor assets with npm, provisions the OpenWiki database account, runs the shared CLI installer, configures cron, Nginx and SELinux where applicable, and performs application/database/HTTP health checks. No Node.js service runs after installation.

## Uruchamianie lokalne

Z katalogu pobranego repozytorium uruchom:

```bash
bash install.sh --local
```

Aplikacja działa pod `http://127.0.0.1:8080`, bez roota i bez konfigurowania usług systemowych. Skrypt korzysta bezpośrednio z katalogu, w którym znajduje się `install.sh`. Nie kopiuje ani nie pobiera kodu, nie zmienia gałęzi, lokalnych zmian, właściciela repozytorium ani konfiguracji Nginx/Apache/PHP-FPM/crona/systemd.

Wymagane są PHP CLI 8.2+, Composer oraz rozszerzenia aplikacji: PDO/pdo_mysql, mbstring, OpenSSL, DOM, Phar, cURL, LDAP, ZIP, GD, JSON, session i fileinfo. `storage/` musi być zapisywalne. Na Debianie/Ubuntu zależności można przygotować poleceniem:

```bash
sudo apt-get install php-cli php-mysql php-mbstring php-xml php-curl php-ldap php-zip php-gd composer curl unzip
```

Dystrybucja musi dostarczać PHP 8.2 lub nowsze. Na Fedora/RHEL odpowiednikiem jest `sudo dnf install php-cli php-mysqlnd php-mbstring php-xml php-curl php-ldap php-zip php-gd composer curl unzip`. Lokalny skrypt zgłasza brakujące zależności; sam ich nie instaluje systemowo. W Windows użyj Bash w WSL z linuksowym PHP/Composer/Node; katalog projektu może znajdować się na `/mnt/c/...`, również w ścieżce ze spacjami.

Można wybrać adres i port, także uruchamiając skrypt pełną ścieżką z innego katalogu:

```bash
bash install.sh --local --port 8090
bash install.sh --local --host 127.0.0.1 --port 8090
bash install.sh --local --host 0.0.0.0 --port 8090
bash "/ścieżka ze spacjami/OpenWIki/install.sh" --local
```

Hostem może być adres IPv4/IPv6 lub `localhost`; port musi mieścić się w zakresie 1024–65535. Dla `0.0.0.0` podsumowanie podaje adres `http://127.0.0.1:PORT`. Zajęty port powoduje błąd i nie zatrzymuje procesu, który go używa. `--host`/`--port` wymagają `--local`, a `--local` nie łączy się z `--install`, `--status`, `--uninstall` ani `--install-dir`. Zmienna `OPENWIKI_INSTALL_DIR` nie zmienia katalogu trybu lokalnego.

Pierwsze uruchomienie udostępnia istniejący kreator pod `http://127.0.0.1:8080/install`. Potrzebny jest dostępny serwer **MySQL/MariaDB** oraz konto z uprawnieniami do bazy; kreator może utworzyć bazę, jeżeli konto ma takie prawo. Przed instalacją `/health` zwraca `503` i `setup_required` — skrypt sprawdza również kreator i rozpoznaje ten stan. Nie instaluje automatycznie bazy ani nie wprowadza SQLite.

Przy ponownym uruchomieniu pozostają dotychczasowe `.env`, `APP_KEY`, poświadczenia, znacznik instalacji, konta, strony i pliki. Lokalny URL oraz nieszyfrowane ciasteczko dla lokalnego HTTP są nadpisane tylko w procesie PHP; `.env` nie jest przepisywane. Lokalna nazwa ciasteczka zależy od adresu i portu. Istniejący `installed.lock` bez czytelnego `.env` lub prawidłowego `APP_KEY` wymaga przywrócenia konfiguracji tej instancji; skrypt nie resetuje jej danych. Problem bazy lub `storage/` po instalacji jest osobnym błędem healthcheck.

Skrypt używa `composer install` z `composer.lock`. Aktualne zależności PHP i kompletne, zgodne bundle są ponownie używane. Gdy potrzebne jest zbudowanie edytora, wymagane są **Node.js 22+ i npm**; skrypt używa `npm ci --include=dev` oraz `npm run build`. Manifest builda sprawdza lockfile, źródła i wyniki, a zmiana źródła wymusza przebudowanie. Na Linuxie można wcześniej wykonać `bash scripts/build-editor.sh`, aby użyć istniejącego, tymczasowego narzędzia Node.js z weryfikacją sumy kontrolnej.

Zmiany plików PHP są widoczne po odświeżeniu strony. Zmiany w `resources/js/` wymagają `npm run build` albo ponownego uruchomienia `--local`, które wykryje nieaktualne zasoby. Serwer wyświetla logi w terminalu i działa na pierwszym planie. **Ctrl+C** kończy jego proces i zwalnia port. Tryb lokalny używa [serwera developerskiego PHP CLI](https://www.php.net/manual/en/features.commandline.webserver.php); wdrożenie systemowe pozostaje osobnym trybem. Szczegóły i testy opisuje [dokumentacja lokalnego runtime](docs/local-runtime.md).

## Daily documentation editor

The Visual editor uses [Tiptap/ProseMirror](https://tiptap.dev/docs/editor/core-concepts/extensions). Its maintained document schema, table/list commands and transaction history replace `execCommand` and manual DOM mutations. Pages and revisions store one versioned source envelope (`version`, `format`, `source`); existing HTML and Markdown fields remain compatible projections. The migration preserves original historical content bytes, and reading a page never rewrites it. HTML-based extensions keep compatibility with PHP rendering, protected attachments, wiki links, macros, import/export and the shared page write service.

Visual tools include six heading levels, controlled colors/highlights/alignment, superscript/subscript, nested lists and checklists, callouts, disclosures, responsive columns, block operations and undo/redo. Tables support size selection, row/column movement, headers, merged cells, column widths, cell colors/alignment and spreadsheet paste. Code blocks retain whitespace, language, wrapping and line-number preferences; syntax highlighting is a display decoration. Mermaid source has an isolated, restricted preview with visible syntax errors. Slash commands, ACL-filtered page links and user suggestions, parameterized macros, find/replace, heading navigation, word counts and focus mode support daily editing.

Autosave is on by default: it saves the whole private working draft after two seconds idle, or at ten seconds during continuous typing. Ctrl/Cmd+S saves the draft immediately. Applying changes to the article is a separate explicit action. IndexedDB recovery, retryable private file uploads, operation receipts and retained recovery points protect incomplete new pages as well as edits to existing pages. See the [Polish user guide](docs/editor-user-guide.md) and [technical model](docs/editor-architecture.md) for recovery, concurrency, limits and keyboard controls.

Serialization changes such as `b`/`strong`, `i`/`em`, table section wrappers, a single paragraph wrapper in a cell/list item and insignificant block whitespace are equivalent. Changes to unsupported structure or metadata display the original and editor documents for review. Keeping the original allows metadata-only saves with the original HTML; accepting the transformation keeps the sanitized original in draft/revision recovery. For example, a formatted image caption is shown before conversion to the editor's plain caption field. No transformation silently rewrites old pages or revisions.

Markdown preview, save, API and import all use [League CommonMark/GFM](https://commonmark.thephpleague.com/2.x/) through `ContentService`, with raw HTML escaped, unsafe links disabled and bounded parsing. Tables, nested lists, fenced code languages and task states are supported. Checkbox state is stored as passive `data-type`/`data-checked` attributes; editor controls are never stored in document HTML. Mixed bullet/task lists are split into contiguous runs so bullets are not silently converted into tasks. The Visual-to-Markdown conversion retains its explicit loss review and recovery rules from the safe-save model; richer HTML may require review.

Polish is the default interface language; existing translated application messages also offer English. The expanded workspace tools use Polish labels. Account and secondary page actions use keyboard-accessible menus, and the page tree collapses on phones. Keyboard shortcuts: Ctrl/Cmd+S saves the draft, Ctrl/Cmd+Z undoes, Ctrl/Cmd+Shift+Z redoes, Ctrl/Cmd+K opens the link picker, Ctrl/Cmd+F opens Visual find/replace, Alt+Shift+Up/Down moves blocks, Tab/Shift+Tab indents/outdents list items, Ctrl/Cmd+Enter exits a code block. Arrow keys change editor tabs and command suggestions; Escape closes dialogs and navigation menus.

Editor sources live in `resources/js/`. `npm run build` produces `public/assets/wiki-editor.js`, `document-reader.js` and `diagram.js`; generated bundles are not committed. The tracked `diagram.html` isolates Mermaid. CI builds before browser tests and records screenshots and actual performance measurements. The installer builds source deployments and accepts complete precompiled releases. Tags matching `v*` produce a release archive containing Composer runtime dependencies and all static assets, ready for PHP hosting without Node.js.

Fresh installation, configuration repair and version upgrades are separate operations. Repair preserves code, schema, configuration and credentials:

```bash
sudo ./install.sh --repair
sudo ./install.sh --diagnose
sudo ./install.sh --upgrade <tag-or-commit>
sudo ./install.sh --upgrade-status
sudo ./install.sh --resume
```

`--install` refuses an existing instance. An upgrade pins the requested Git reference to a SHA, pauses writes, verifies a private recovery backup, builds a separate release, runs `php bin/console migrate`, switches `current`, and verifies HTTP readiness and the worker. Pending migrations prevent success. See [upgrade and recovery procedures](docs/upgrades.md), including the first upgrade from older installers and schema-aware rollback/restore.

Uninstall removes the OpenWiki files, cron entry and Nginx site. The database is intentionally retained:

```bash
sudo ./install.sh --uninstall
```

Useful environment overrides:

```bash
OPENWIKI_INSTALL_DIR=/var/www/openwiki
OPENWIKI_DB_NAME=openwiki
OPENWIKI_DB_USER=openwiki
OPENWIKI_GIT_REF=main
```

The configured distribution repositories must provide PHP 8.2 or newer. On modular DNF systems the installer enables the PHP 8.2 stream when it is available.

## CLI installation

```bash
php bin/console install
```

The CLI performs the same installation through the shared `InstallService`.

## CLI operations

```bash
php bin/console help
php bin/console migrate
php bin/console db:status
php bin/console cache:clear
php bin/console system:check
php bin/console user:create
php bin/console user:disable <id|username|email>
php bin/console admin:reset-password <id|username|email>
php bin/console backup:create
php bin/console cron:run
```

Run housekeeping periodically, for example once per minute:

```cron
* * * * * cd /var/www/openwiki && php bin/console cron:run >/dev/null 2>&1
```

## Web server

The recommended production document root is `public/`; attachments and runtime data remain outside that directory.

Apache additionally supports repository-root deployment for the download-and-extract installation flow. The root `.htaccess` blocks internal application directories, maps `/assets/*` to `public/assets/` and sends application requests through the root front controller. This mode requires `AllowOverride` for the site and `mod_rewrite`. If those controls are unavailable, use `public/` as the document root instead.

For Nginx, point the document root to `public/`, route requests for non-existent files to `/index.php` and pass PHP requests to PHP-FPM.

## Current implemented foundation

The initial platform branch includes:

- graphical and CLI installation
- migration runner and production schema
- local authentication and persistent login rate limiting
- role/permission model and Space ACL foundations
- Spaces
- hierarchical Wiki pages
- Visual and Markdown editing
- HTML sanitization
- draft/published/archived page status
- immutable page revisions
- revision restore
- optimistic locking
- redirects after slug changes
- dashboard/recent pages/my drafts
- permission-aware search
- security headers and CSRF protection
- structured audit logging
- health endpoint
- responsive enterprise UI
- GitHub Actions validation against PHP 8.2 and MySQL 8

The schema intentionally includes tables needed by subsequent production modules so later functionality can be added without replacing the core data model.

## Health

```text
GET /health
GET /health/live
```

`/health/live` reports PHP process liveness without loading Composer, configuration or the database. `/health` returns 200 only when installation, database, storage, schema compatibility and maintenance state permit readiness; otherwise it returns 503. It includes pending, unknown and incomplete migration identifiers, but no secrets or infrastructure credentials. `php bin/console readiness` applies the same checks and exit status.

## Security

OpenWiki uses native PDO prepared statements, output escaping, rich-text allowlist sanitization, CSRF tokens, session ID rotation, secure cookie options, restrictive browser headers, permission checks in the backend, persistent rate limiting and audit events.

Secrets and local runtime state are excluded from Git. Do not commit `.env`, database passwords, tokens or files from runtime storage.

## Development

```bash
composer install
composer validate --strict
vendor/bin/phpunit
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

Pull requests are validated by `.github/workflows/ci.yml`.


## Backups

Administrators can create and download backups from `/admin/backups`, or create one from CLI:

```bash
php bin/console backup:create
```

Backups are stored outside the public document root in `storage/backups/` as TAR archives. Each archive contains a MySQL schema/data dump, protected attachments, a manifest and non-secret application configuration. `APP_KEY`, `DB_USERNAME` and `DB_PASSWORD` are intentionally excluded.


## Webhooks

Administrators with `webhook.manage` can configure outbound webhooks under `/admin/webhooks`.

Supported events:

- `page.created`
- `page.updated`
- `page.deleted`
- `page.published`
- `space.created`
- `space.updated`
- `space.deleted`
- `comment.created`
- `user.created`

Webhook requests are JSON POST requests signed with `X-OpenWiki-Signature: sha256=<HMAC>`. Secrets are encrypted at rest with AES-256-GCM using the application key. Delivery retries are processed by `php bin/console cron:run`. Redirects are disabled and private, reserved and loopback destinations are rejected to reduce SSRF risk.


## Multi-factor authentication

OpenWiki supports TOTP MFA compatible with Google Authenticator, Microsoft Authenticator, Authy and 1Password.

Users can enroll from `/account/mfa/setup`. TOTP secrets are encrypted at rest with AES-256-GCM. Enrollment generates ten one-time recovery codes; only SHA-256 hashes of recovery codes are stored.

Administrators can configure MFA policy under `/admin/mfa`:

- require MFA globally,
- require MFA for selected roles,
- reset a user's MFA from user administration.

Password authentication alone does not complete a browser login when MFA is enabled or required. The session remains restricted until TOTP or an unused recovery code is verified.


## LDAP / Active Directory

Administrators can configure directory authentication under `/admin/ldap`.

Configuration includes host, port, LDAP/LDAPS, Base DN, Bind DN and password, User DN, Group DN, username/email/name/group attributes and group-to-role mappings. The form includes a connection test that verifies connection, service bind and Base DN search.

The Bind Password is encrypted at rest using the application key and is never returned to the UI. User directory passwords are used only for the LDAP bind and are never stored.

After the first successful LDAP sign-in OpenWiki creates a local profile with `auth_source=ldap`. Existing local accounts cannot be claimed by an LDAP identity with the same username or email. LDAP-managed role assignments are tracked separately so synchronization removes only roles previously granted through LDAP and leaves manually assigned roles intact.

Group mapping syntax:

```text
CN=Wiki Editors,OU=Groups,DC=example,DC=com => editor
Wiki Viewers => viewer
```


## Page ACL

OpenWiki supports per-page ACL rules for users, groups and roles. Page ACL is evaluated on top of global RBAC and Space access and does not replace backend permission checks.

Page permissions can be managed from the page action **Permissions**. Rules support:

- `allow` and `deny`,
- users, groups and roles as principals,
- granular `page.*` permissions,
- optional propagation to child pages,
- page-level switch for inheriting ACL from parent pages.

A matching `deny` takes precedence over `allow`. If an ACL scope contains one or more `allow` rules for a permission, it acts as an allowlist for that scope. Direct page rules are evaluated before inherited rules. Setting `inherit_acl` off stops inheritance from ancestors.

Page ACL is enforced consistently for page rendering, editor/autosave/locks, history/restore, delete/trash, comments, favorites/watches, attachment access, search, tags/wiki links, dashboard page lists and REST API resources.


## Active sessions

Authenticated browser sessions are tracked server-side in `user_sessions`. Every non-API browser request validates that the current PHP session still exists in the server-side registry and has not expired or been revoked.

Users can manage sessions under `/account/sessions`:

- review active sessions, IP addresses, user agents, activity and expiry,
- revoke an individual session,
- revoke the current session,
- sign out all sessions immediately.

Raw PHP session IDs are never displayed in the UI. Session actions use SHA-256 fingerprints. The session ID is rotated after successful MFA verification and the registry entry is atomically replaced.


## Search

Global search at `/search` covers pages, Spaces, users, comments, tags and attachment names.

UI and API share complete ACL-filtered pagination, accurate totals, published/working
revision visibility, matching-context snippets and keyboard suggestions. See
[search semantics and index recovery](docs/search.md) and the
[10,000-page benchmark](docs/search-benchmark.md).

Filters include:

- result type,
- Space,
- author,
- tag,
- created date range,
- updated date range.

Page-related results are filtered through Page ACL before they are rendered. Matching fragments are highlighted only after HTML escaping. Anonymous users do not receive user-directory search results.

Rebuild searchable plain-text page content after imports or data repair with:

```bash
php bin/console search:index
php bin/console search:status
php bin/console cron:run
```


## System diagnostics

Administrators can inspect `/admin/system` for application/PHP/database versions, database size, attachment and backup storage usage, filesystem capacity, required PHP extensions, writable directories, migration status and scheduler state.

A successful `php bin/console cron:run` stores its last-run timestamp. The administration UI marks the scheduler stale when no successful run has been recorded for more than 10 minutes.


## Macros and table of contents

Page content can contain render-time macros. Generated macro output is not written back into page revisions.

Supported macros include:

- `{{toc}}`
- `{{child-pages}}`
- `{{page-properties}}`
- `{{attachments}}`
- `{{recent-updates}}`
- `{{user-profile:username}}`
- `{{status:In progress}}`
- `{{info:Information}}`
- `{{warning:Warning}}`
- `{{note:Note}}`
- `{{code:echo "hello";}}`

Headings receive stable anchors while rendering. Pages with at least two headings show a sticky Table of Contents on wide screens and a normal TOC block on smaller screens.

## Page templates

Administrators with `template.manage` can manage custom page templates under `/admin/templates`. System templates are read-only and cannot be deleted. Template HTML and Markdown pass through the same content normalization and HTML sanitization used by Wiki pages.

## Import and export

A page can be exported as HTML, Markdown or PDF. A Space can be exported as a ZIP archive containing:

- a manifest with page hierarchy,
- standalone HTML files,
- Markdown files,
- accessible page attachments,
- an index page.

Spaces can import Markdown, HTML or ZIP documentation. Imported pages are created as drafts.

ZIP import is processed entry-by-entry without filesystem extraction. OpenWiki rejects absolute paths, path traversal and Unix symlink entries, limits archive entry count and uncompressed sizes, and ignores unsupported file types instead of executing or extracting them.

Normal Space ZIP downloads remove their temporary archive after sending. `cron:run` also removes stale files from `storage/temp` after 24 hours.


## Rich images

The Visual editor supports PNG, JPEG, GIF and WebP images through file selection and clipboard paste.

- New and existing pages upload editor files as private draft files. The owning account can preview/download them before applying changes; other accounts cannot.
- Applying changes materializes draft files as protected page attachments in the same database transaction. Readers then access them through the current Page ACL. Pending uploads retain their file blobs in IndexedDB, progress and retry identity, with explicit cancellation.
- Materialized image destinations use protected attachment URLs after a successful save; literal examples inside code remain unchanged.
- Images support alt text, captions, alignment, controlled dimensions, aspect-ratio preservation and protected previews without arbitrary inline CSS.
- Thumbnails are generated with GD, cached under `storage/cache/images`, never upscale the source image and are protected by the same Page ACL as the original attachment.
- Server-side image inspection enforces raster formats, file limits and a configurable pixel limit before thumbnail decoding.
- REST page writes containing inline images additionally require `attachments:write` and backend `attachment.upload` permission.
- HTML/ZIP imports apply the same inline-image materialization rule.

SVG is intentionally not accepted as an uploaded image format because it may contain active content.


## Editor saves and recovery

The authoritative `document_json` envelope has schema version 1, format `visual` or `markdown`, and one source string. Visual source is sanitized HTML; Markdown source is original Markdown, with HTML and search text derived by the shared renderer. API clients can send `document: {version: 1, format, source}` or compatible legacy fields. Inactive editor buffers never replace that source. A format change through the API must include the converted source explicitly.

Mode switches convert the current document. Markdown to Visual renders the supported Markdown syntax. Visual to Markdown compares the HTML before conversion with the rendered result; unsupported formatting such as underline, image presentation, tables or callout attributes requires reviewing a preview and source differences. The editor keeps the previous source for undo, includes it in recovery drafts, and stores it in the saved revision. History can restore the content before conversion as a new revision.

Every update and revision restore requires the original `base_version`. A conflict shows the base, local and current server documents. Repeating the same save cannot advance that base or overwrite a concurrent edit. Only explicitly preparing the reviewed document against the displayed server version changes the base; another concurrent edit then causes another conflict.

Recovery copies include content, title, slug, parent, status, tags, change summary, base version, draft version and change sequence. They are isolated by application instance, account, Space, document and editing context. New-page URLs receive a stable document identifier; tabs have independent editing contexts. Acknowledgements cover one snapshot, so later changes and other tabs survive. Local copies and server drafts/checkpoints expire after 30 days; each server context retains its latest 20 checkpoints. `cron:run` also prunes operation receipts and unused private files with the same 30-day retention, preserving adopted attachments and historical attachment versions. Current limits are documented in the [technical model](docs/editor-architecture.md).

UI, API, import and restore share transactional validation, version checks, revisions, tags, backlinks, audit, watcher notifications and queued webhook events. Existing internal HTML links and `[[references]]` both contribute backlinks.

Browser regression tests use the real login and editor forms, PHP routes and an isolated MySQL/MariaDB database. Install dependencies with `npm ci` and `npx playwright install chromium`, configure the same `OPENWIKI_TEST_DB_*` variables as the PHPUnit integration tests, and run `npm run test:browser`. The database name must end in `_test`; fixtures are created and removed automatically. Run against a disposable checkout/database with no live PHP server on port 8765. The suite temporarily manages the installation marker and restores it afterward. `npm run check:js` checks the shipped JavaScript syntax.

## REST API v1

API clients authenticate with a Bearer token created under `/account/api-tokens`. Tokens are stored only as SHA-256 hashes and are shown in plaintext once.

Available scopes:

```text
spaces:read       spaces:write
pages:read        pages:write
comments:read     comments:write
search:read
users:read        users:write
groups:read       groups:write
roles:read        roles:write
tags:read
attachments:read attachments:write
templates:read   templates:write
webhooks:read    webhooks:write
```

A scope never bypasses backend RBAC or Space/Page ACL. Both the token scope and the token owner's current permissions must allow the operation.

Main resources:

```text
GET,POST                 /api/v1/spaces
GET,PUT,PATCH,DELETE     /api/v1/spaces/{id}

GET,POST                 /api/v1/pages
GET,PUT,PATCH,DELETE     /api/v1/pages/{id}

GET,POST                 /api/v1/comments
PUT,PATCH,DELETE          /api/v1/comments/{id}

GET                       /api/v1/search
GET,POST                  /api/v1/users
GET,PUT,PATCH,DELETE      /api/v1/users/{id}

GET,POST                  /api/v1/groups
GET,PUT,PATCH,DELETE      /api/v1/groups/{id}

GET,POST                  /api/v1/roles
GET,PUT,PATCH,DELETE      /api/v1/roles/{id}
GET                       /api/v1/permissions

GET                       /api/v1/tags
GET                       /api/v1/tags/{id}

GET,POST                  /api/v1/templates
GET,PUT,PATCH,DELETE      /api/v1/templates/{id}

GET,POST                  /api/v1/webhooks
GET,PUT,PATCH,DELETE      /api/v1/webhooks/{id}
GET                       /api/v1/webhooks/{id}/deliveries

GET,POST                  /api/v1/attachments
GET,PATCH,DELETE          /api/v1/attachments/{id}
POST                      /api/v1/attachments/{id}/version
GET                       /api/v1/attachments/{id}/download
GET                       /api/v1/attachments/{id}/versions/{version}/download
```

Webhook signing secrets are returned only once, in the `201` response from `POST /api/v1/webhooks`. Listing or reading webhook resources never returns encrypted or plaintext secret material.

`GET /api/v1/search` remains backward-compatible with page search by default. Use `type=all|space|user|comment|attachment|tag` to enable multi-type search. The endpoint also accepts Space, author, tag and created/updated date filters.

API responses are rate-limited by token and source IP. Attachment binary responses are authorized before the protected file is streamed.
