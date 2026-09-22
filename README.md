# OpenWiki

OpenWiki is a self-hosted knowledge base and enterprise wiki written for PHP 8.2+ and MySQL 8/MariaDB.

## Requirements

- PHP 8.2+
- MySQL 8 or compatible MariaDB
- PHP extensions: PDO, pdo_mysql, mbstring, OpenSSL, DOM
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
