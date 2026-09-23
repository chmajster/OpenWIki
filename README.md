# OpenWiki

OpenWiki is a self-hosted knowledge base and enterprise wiki written for PHP 8.2+ and MySQL 8/MariaDB.

## Requirements

- PHP 8.2+
- MySQL 8 or compatible MariaDB
- PHP extensions: PDO, pdo_mysql, mbstring, OpenSSL, DOM, Phar, cURL, LDAP, ZIP
- a web server whose document root points to `public/`
- writable `storage/` directories

Composer is used for development/test dependencies. Production runtime does not require Node.js.

## Install from Git

```bash
git clone https://github.com/chmajster/OpenWIki.git
cd OpenWIki
```

Point Apache or Nginx at the repository's `public/` directory. On the first HTTP request OpenWiki redirects to `/install`.

The installer validates the runtime, connects to MySQL/MariaDB, applies migrations, creates the initial Super Admin, seeds RBAC and system templates, writes `.env`, performs its final persistence steps and creates `storage/installed.lock`.

The installation lock prevents the graphical installer from running again.

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

The public document root must be `public/`, never the repository root. Attachments and runtime data live outside the public directory.

Apache users can use the included `public/.htaccess` when `mod_rewrite` is enabled.

For Nginx, route requests for non-existent files to `/index.php` and pass PHP requests to PHP-FPM.

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
```

The public response exposes only coarse application, database and storage status. It does not return secrets or infrastructure credentials.

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

- Existing pages upload images immediately as protected attachments.
- New pages keep pasted images only in the local editor state until the first page save, then materialize them as attachments in the same database transaction.
- Inline base64 image data is not kept in page content after a successful save.
- Images support alt text, optional captions and a controlled display width without inline CSS.
- Thumbnails are generated with GD, cached under `storage/cache/images`, never upscale the source image and are protected by the same Page ACL as the original attachment.
- Server-side image inspection enforces raster formats, file limits and a configurable pixel limit before thumbnail decoding.
- REST page writes containing inline images additionally require `attachments:write` and backend `attachment.upload` permission.
- HTML/ZIP imports apply the same inline-image materialization rule.

SVG is intentionally not accepted as an uploaded image format because it may contain active content.


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
