# 4WP Drive

Import **Google Docs**, **Markdown**, and **Word** from **Google Drive**, or **Markdown packages** from **GitHub**, into WordPress **drafts** — or **update existing posts and pages** from the admin **Incoming** screen.

**Plugin page:** [4wp.dev/plugin/4wp-drive/](https://4wp.dev/plugin/4wp-drive/) · [WordPress.org](https://wordpress.org/plugins/4wp-drive/) · [4wp.dev](https://4wp.dev/) · GPL-2.0-or-later

**Current stable:** 1.6.0

## What it does

1. Writers drop articles into a source `incoming/` folder (Drive or a GitHub repo).
2. **4WP Drive** syncs them into the admin **Incoming** queue (source tabs + workspace).
3. Editors **Preview**, pick **Content language** (Polylang, when needed), then **Create new draft**, **Update existing post**, or **Reject**.
4. On import, the package moves to `published/` (same tree). Reject goes to `failed/`.
5. **Analytics** records each import. **Restore to incoming** moves the package back if the WordPress post was deleted or the import needs a redo.

Front-matter lines before a separator (`---` or `=====`) map to post fields (title, slug, categories, SEO, etc.). The rest becomes Gutenberg post content.

## What shipped in 1.6.0

Incoming editor UX and Google Docs fidelity.

### Incoming tree

- The queue is a **lazy folder tree**: one Drive/GitHub level at a time, not a recursive dump of the whole `incoming/` tree.
- Nested folders stay **closed** until expanded. `incoming` / `published` / `failed` open when they have child folders.
- Expand fetches children (including shortcuts / shared drives). Empty folders no longer stay empty if Drive has files.

### Google Docs

- **Classic tables** import as `core/table`. Docs wraps cells in `<p>`; those paragraphs no longer leak into the post body.
- Default: strip Google Arial/11pt so the **theme fonts** win. Import checkbox **Keep document fonts** when you need the Doc’s typeface.

### Images

- Image files in the tree get an **image icon**.
- Click the name: **workspace preview** (no Import / Reject).
- Open-outside icon: Drive file view or GitHub blob.

### Status

- Sync cooldown (“already ran recently”) is **yellow**, not red.
- **Export errors: N** is from the last Sync: Google could not export that many articles as HTML (Docs export API, Word conversion, or unsupported mime). Click the chip for names and messages. It is **not** a failed WordPress import.

## What shipped in 1.5.0

Progress since **1.4.0** (Inbox dashboard). This is the release cut.

### Incoming (editors)

- Screen name **Incoming** (same slug as the old Inbox).
- Source tabs: **Google Drive** and **GitHub** are live. OneDrive / Dropbox stay on the roadmap.
- Status bar: connection, last sync, ready count, **Open folder**, **Sync**.
- Package folders: one article = a subfolder with a document + png/jpg images.
- **File to import** when a folder has more than one document (Markdown preferred).
- **Image pin** in preview: pick a package image, set alignment, click a paragraph. Markdown `![](hero.png)` in the same folder becomes `[image:hero.png]`.
- Import still writes **core blocks** (headings, paragraphs, lists, quotes, code, images).

### Google Drive

- Same `incoming/` → `published/` / `failed/` folder contract.
- Articles: **Google Doc**, **Markdown** (`.md` / `.markdown`), or **Word** (`.docx`). Not Drive shortcuts.
- Markdown is preferred when several article files sit in the same package folder.

### GitHub (live)

- Connect under **Settings → Storage sources → GitHub**: owner, repository, branch, incoming path (default `incoming`), **personal access token**.
- Scans `incoming/` for Markdown packages (subfolder with `.md` + images, or a flat `.md`).
- After import: files move to `published/` (Git commit via Contents API: new path + delete old path). Reject → `failed/`.
- **4WP Drive** only talks to the configured `owner/repo`. A **classic PAT** with `repo` can still access *all* repos the GitHub user can reach. Prefer a **fine-grained token** limited to this repository, **Contents: Read and write**.
- Token is stored encrypted. GitHub shows a classic token only once (`ghp_…`).

Example package:

```text
incoming/
  4wp-drive-plugin-overview/
    article.md
    cover.png
published/          ← after import, same folder tree
  4wp-drive-plugin-overview/
    article.md
    cover.png
```

### Analytics (import history)

- Menu: **4WP Drive → Analytics**.
- Separate table `wp_forwp_drive_import_history` (created on the next admin load — **no plugin reactivation required**).
- Each successful import stores: source (Drive / GitHub), date, **post ID + post type**, **folder alias**, **site alias** (slug), incoming path, published path, create vs update.
- **Restore to incoming** moves the package from `published/` back to `incoming/` (Drive parent change, or GitHub commit). Then **Incoming → Sync** to see it in the queue again.
- Restore does **not** rebuild Markdown from WordPress. The source package is the source of truth.
- History starts from the next import after 1.5.0. Older imports are not backfilled.

### Still true from earlier releases

- **Polylang** language picker at import (1.2.0). WPML planned.
- **Update existing post** (1.1.0).
- **Patterns** (FAQ / accordion / core image markers) (1.3.0).
- Encrypted OAuth / PAT in the database.

## Requirements

- WordPress 6.4+ (tested up to 7.1)
- PHP 7.4+
- **Drive:** Google Cloud project, Drive API, OAuth 2.0 Web client
- **GitHub (optional):** PAT with access to one repo (fine-grained recommended)
- **Polylang (optional)** — multilingual import

## Quick start

### Google Drive

1. Activate the plugin.
2. **4WP Drive → Settings → Storage sources** — paste Client ID & Secret → **Save credentials**.
3. **Connect Google Drive** (register the redirect URI from **Documentation** in Google Cloud Console).
4. Set **root folder ID** → **Save & create subfolders** (`incoming`, `published`, `failed`).
5. **Incoming** → Google Drive tab → **Sync** → preview → import.

### GitHub

1. Create a repo with an `incoming/` folder and one package subfolder (Markdown + images).
2. **Settings → GitHub** — owner, repository, branch, incoming path, PAT → **Save GitHub settings**.
3. **Incoming** → GitHub tab → **Sync** → preview → import.
4. Check **Analytics** after import; use **Restore to incoming** if you need the package back in the queue.

Optional `wp-config.php` constants (Drive):

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
- History schema: `src/Database/Schema.php` (`forwp_drive_documents`, `forwp_drive_import_history`)

WordPress.org assets: see [.wordpress-org/assets/README.txt](.wordpress-org/assets/README.txt).

Release announcements (Google, social, newsletter): [docs/releases/](docs/releases/) — not shipped in the wp.org ZIP. Latest: [1.6.0.md](docs/releases/1.6.0.md).

## External services

- **Google OAuth 2.0** — administrator authorization for Drive
- **Google Drive API** — list, export, and move files during sync/import
- **GitHub REST API** (`api.github.com`) — list/read Contents and commit moves when a PAT is saved (administrators only)

See [readme.txt](readme.txt) → **External services** for WordPress.org detail.

Overview and FAQ: [4wp.dev/plugin/4wp-drive/](https://4wp.dev/plugin/4wp-drive/)

## License

GPL v2 or later. See [LICENSE](LICENSE) if present or plugin header.
