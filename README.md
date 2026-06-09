# 4WP Drive

Import **Google Docs** from Drive into WordPress **drafts** — editorial inbox, field mapping, and server-side OAuth.

[4wp.dev](https://4wp.dev/) · WordPress plugin · GPL-2.0-or-later

## What it does

1. Writers drop Google Docs (and optional images) into a Drive `incoming/` folder.
2. **4WP Drive** syncs new files and lists them in the admin **Inbox**.
3. Editors **Preview**, **Import as Draft**, or **Reject**.
4. Imported files move to `published/` in Drive.

Front-matter lines before a separator (`---` or `=====`) map to post fields (title, slug, categories, SEO, etc.). The rest becomes post content with headings, lists, and bold preserved.

## Requirements

- WordPress 6.4+
- PHP 7.4+
- Google Cloud project with **Google Drive API** enabled and **OAuth 2.0 Web client** credentials

## Quick start

1. Activate the plugin.
2. **4WP Drive → Storage sources** — paste Client ID & Secret → **Save credentials**.
3. **Connect Google Drive** (register the redirect URI from **Documentation** in Google Cloud Console).
4. Set **root folder ID** → **Save & create subfolders**.
5. **Inbox** → sync → import.

Optional `wp-config.php` constants:

```php
define( 'FORWP_DRIVE_GOOGLE_CLIENT_ID', '…' );
define( 'FORWP_DRIVE_GOOGLE_CLIENT_SECRET', '…' );
define( 'FORWP_DRIVE_OAUTH_REDIRECT_URI', '…' ); // local loopback if needed
```

## WP-CLI

```bash
wp forwp-drive sync
```

## Development

```bash
composer install
composer test
composer run lint
```

- PHP source: `src/` (PSR-4 `ForWP\Drive\`)
- Admin UI: plain JS in `assets/` (no npm build)
- Tests: `tests/unit/`

WordPress.org assets: `bash docs/4wp-drive/export-wporg-assets.sh` (from repo `public/` root; sources in plugin `assets/images/`).

## External services

- **Google OAuth 2.0** — administrator authorization for Drive access
- **Google Drive API** — list, export, and move files during sync/import

See [readme.txt](readme.txt) → **External services** for details required by WordPress.org.

## License

GPL v2 or later. See [LICENSE](LICENSE) if present or plugin header.
