=== 4WP Drive ===
Contributors: 4wpdev, anatolikkk
Tags: google drive, import, editorial, drafts, content pipeline
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Google Docs and Markdown from Drive or GitHub into WordPress drafts—or update existing posts and pages from Incoming.

== Description ==

**4WP Drive** connects Drive and GitHub folder workflows to WordPress: writers drop documents in `incoming/`, editors review them on the admin **Incoming** screen, and approved content becomes **draft posts** or updates **existing posts and pages**—without copy-paste. **Analytics** stores import history (folder alias, site slug, incoming → published) and can restore a package back to incoming.

A plugin by [4wp.dev](https://4wp.dev/).

Learn more, workflow details, and comparisons on the plugin page at [4wp.dev/plugin/4wp-drive/](https://4wp.dev/plugin/4wp-drive/).


= Perfect for =

* **Editorial teams** that draft in Google Docs but publish in WordPress
* **Agencies** with a shared Drive or GitHub `incoming/` folder and a review step before publish
* **Content pipelines** that need structured front-matter (title, slug, categories, SEO fields) parsed from the doc header
* Sites that want **API credentials stored encrypted** and **OAuth handled server-side**

= How it works =

1. Install and activate **4WP Drive**.
2. Connect a storage source in **Settings → Storage sources**: Google Drive (OAuth) and/or GitHub (PAT + owner/repo).
3. Drive: set the **root folder ID**; the plugin uses `incoming/` and `published/` subfolders. GitHub: set the repo and incoming path (default `incoming/`).
4. Drop a package into `incoming/` (Google Doc, Markdown, or Word on Drive; Markdown on GitHub) or run **Sync**.
5. Open **Incoming** — pick a source tab (Google Drive or GitHub live; OneDrive and Dropbox on the roadmap), sync, select a document, then preview and **Create new draft**, **Update existing post**, or Reject.
6. On import, files move to `published/`. Open **Analytics** to see history; **Restore to incoming** if you need the package back in the queue.

= Key features =

* **Google Drive OAuth** — connect an admin Google account; tokens stored encrypted
* **Folder sync** — scan `incoming/` for Google Docs, Markdown, Word, and images (Drive) or Markdown packages (GitHub)
* **GitHub** — live Markdown source: PAT, owner/repo, move to published/failed after import
* **Editorial Incoming** — status bar, source tabs, lazy folder tree, document queue + side workspace for preview and import
* **Package images** — preview jpg/png in Incoming without importing; open the file in Drive or GitHub
* **Analytics** — import history (source, post, folder alias, site slug, incoming → published) and Restore to incoming
* **Document template** — front-matter lines before a separator (`---` or `=====`) map to post fields; body becomes post content (headings, lists, bold preserved)
* **Body → block templates** — map FAQ-style sections to **4WP FAQ** or core Accordion; **Core Image** (default on) replaces `[image:filename.jpeg]` with `core/image` from the package folder
* **Configurable field map** — title, slug, categories, tags, author, dates, SEO meta (when supported)
* **Featured image** — import image from the same Drive subfolder
* **Update existing content** — search and pick a post or page, then replace its content from the Drive document
* **Polylang multilingual import** — pick content language in the Inbox when the site has multiple languages; assign language on create; filter update targets by language (WPML planned)
* **REST API** + **WP-CLI** `wp forwp-drive sync` for manual sync
* **Roadmap sources** — OneDrive and Dropbox

= Privacy =

OAuth tokens and Google API credentials are stored in your WordPress database (encrypted). Document content is fetched from Google Drive only when an administrator runs sync or import. No visitor-facing tracking.

**4WP** is our project brand; the letters "WP" appear only as part of that brand name, not as a reference to WordPress. This plugin is not affiliated with, endorsed, or sponsored by WordPress.

Source code: [github.com/4wpdev/4wp-drive](https://github.com/4wpdev/4wp-drive)

Plugin overview and FAQ: [4wp.dev/plugin/4wp-drive/](https://4wp.dev/plugin/4wp-drive/)

= Development =

Human-readable PHP source is in the public GitHub repository above. The plugin ZIP includes `src/` (PSR-4 autoload via `src/Autoload.php` when `vendor/` is absent). No npm build step — admin scripts ship as plain JS in `assets/`.

Run tests: `composer install && composer test && composer run lint`

== External services ==

This plugin connects to **Google** services when an administrator configures OAuth and syncs or imports documents.

= Google OAuth 2.0 =

Used to authorize access to the connected Google account's Drive files.

* Authorization URL: `https://accounts.google.com/o/oauth2/v2/auth`
* Token URL: `https://oauth2.googleapis.com/token`
* Scope: `https://www.googleapis.com/auth/drive`

When an administrator clicks **Connect Google Drive**, the browser is redirected to Google to sign in and grant access. WordPress stores refresh and access tokens encrypted in the site database. Client ID and Client Secret are stored encrypted (or may be defined in `wp-config.php`).

Google terms: https://policies.google.com/terms  
Google privacy: https://policies.google.com/privacy

= Google Drive API =

Used to list folders, download Google Docs (export as HTML/DOCX), and move files after import.

* API hostname: `https://www.googleapis.com/drive/v3/` (and related export endpoints)

Requests are made **server-side** only when an administrator runs sync, preview, or import. Document metadata and file content are processed on your server to create WordPress posts.

Google Drive API terms follow Google Cloud / Google API Services terms linked from the Google Cloud Console.

= GitHub REST API =

Used when an administrator saves a personal access token and syncs or imports from a GitHub repository.

* API hostname: `https://api.github.com/`
* Typical calls: repository metadata, Contents API (list, read, create, delete) to move files after import or restore

Requests are made **server-side** only. 4WP Drive uses the configured owner/repository. A classic token with `repo` scope can access other repositories the GitHub account can reach; a fine-grained token can be limited to one repository (Contents: Read and write).

GitHub terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service  
GitHub privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

== Installation ==

1. Upload the plugin to `/wp-content/plugins/4wp-drive/` or install from the Plugins screen.
2. Activate **4WP Drive**.
3. Open **4WP Drive → Storage sources** (or **Documentation** for the setup guide).
4. Paste **Client ID** and **Client Secret** from Google Cloud Console → **Save credentials**.
5. Click **Connect Google Drive**, then enter your Drive **root folder ID** and save subfolders.
6. Use **Inbox** to preview and import documents.

Optional: define `FORWP_DRIVE_GOOGLE_CLIENT_ID`, `FORWP_DRIVE_GOOGLE_CLIENT_SECRET`, or `FORWP_DRIVE_OAUTH_REDIRECT_URI` in `wp-config.php`.

== Frequently Asked Questions ==

= What does “Export errors” on Incoming mean? =

During **Sync**, Drive downloads each article as HTML (Google Docs export, Word conversion, or Markdown download). **Export errors** is how many of those downloads failed on the last sync — not a WordPress import failure. Click the chip to see file names and Google’s message (quota, permissions, unsupported type, or a broken Doc). The package can still sit in the queue; yellow labels mark it. Fix the file in Drive and Sync again.

= Do I need a Google Cloud project? =

Yes. Create OAuth 2.0 credentials (Web application), enable the Google Drive API, and register the redirect URI shown in the plugin Documentation tab.

= What document format is supported? =

Google Docs in `incoming/` (single doc or subfolder with doc + image). Front-matter uses `Label: value` lines before a separator paragraph (`---` or a row of `=` characters). The rest is post body.

= Are API keys exposed to visitors? =

No. OAuth and Drive requests run on the server. Only administrators with `manage_options` can connect Drive and import.

= Can I lock credentials in wp-config.php? =

Yes. Use `FORWP_DRIVE_GOOGLE_CLIENT_ID` and `FORWP_DRIVE_GOOGLE_CLIENT_SECRET` constants.

= Does it work on local dev (.local / 127.0.0.1)? =

Yes. Use the **OAuth redirect (local)** field when Google rejects your site hostname; register the same loopback URI in Google Cloud Console.

= Can I update an existing post or page instead of creating a draft? =

Yes. In **Inbox → Preview**, choose **Update existing post**, search or pick a target, and confirm. The document content is imported into that post. Use **Import as Draft** when you want a new post instead.

= What changes when I update an existing post? =

The plugin updates **title**, **content**, mapped **categories/tags**, **SEO meta** (when configured), and **featured image** (when the Drive folder includes an image). Optional front-matter **date** updates `post_date` when present. The post **slug** and **status** (published, draft, etc.) stay as they were unless your site or other plugins change them.

= Which posts can I select to update? =

Only posts of the **configured import post type** (for example **Posts** or **Pages** under **Document template**). Targets must be posts you can edit (`edit_post`). Published, draft, pending, private, and scheduled posts are supported. The picker can suggest a match by document **slug** or **title** before you search.

= Is update mode safe? Will it overwrite without asking? =

You must select a target and confirm before import. If the slug or title in the document matches an existing post, the plugin may **suggest** that post first—you still choose explicitly. Always preview the document before updating production content.

= Does update mode work with pages and custom post types? =

It works with whichever post type you set as the import type in **Document template**. Switch the import type to **Page** (or another public type with editor support) to update pages instead of posts. The target must match that same type.

= Does 4WP Drive work with multilingual sites? =

**Polylang (1.2.0):** When more than one language is configured, open **Inbox → Preview & import** and select **Content language** before import (no default). New drafts receive that language; **Update existing post** lists and validates targets in the selected language only. Language is not read from Drive folder names.

**WPML:** Shown as **Planned** under **Settings → Multilingual integration**; not used for import in this release.

Single-language sites (no Polylang) behave as before — no language picker in the Inbox.

= Does GitHub import use a folder named incoming/? =

Yes in this release. Set **Incoming path** in GitHub settings (default `incoming`). Each article is a subfolder with a `.md` file plus images. After import the same tree is moved to `published/`.

= Does the GitHub token access every repository? =

4WP Drive only reads and writes the owner/repo you configured. A **classic PAT** with `repo` can still access other repositories on that GitHub account. Use a **fine-grained token** limited to this repository (Contents: Read and write) if you want a narrow token.

= Where is import history? =

**4WP Drive → Analytics.** Each import stores source, date, post, folder alias, site slug, and incoming/published paths. **Restore to incoming** moves the published package back; then Sync Incoming. History starts after upgrading to 1.5.0 (older imports are not backfilled). You do not need to reactivate the plugin.

= Where do I see which multilingual plugin is active? =

**4WP Drive → Settings → Storage sources** — scroll to **Multilingual integration**. Cards show Polylang (live), WPML (planned), and single-language fallback, with status badges (Active, Inactive, Not installed, Planned).

== Screenshots ==

1. Storage sources — source registry (Google Drive and GitHub live; OneDrive, Dropbox planned).
2. Google Drive — OAuth credentials, Connect, and folder mapping.
3. Document template — map front-matter labels to post fields and taxonomies.
4. Drive folders — WordPress settings alongside the matching `incoming` / `published` / `failed` folders in Drive.
5. Incoming — synced articles from `incoming/` with Preview, Import as Draft, and Reject.
6. Document template — example header format next to a Google Doc with front-matter.
7. Inbox preview — parsed metadata and featured image before import.
8. Imported draft — post editor with content, featured image, categories, and Yoast SEO fields.
9. Published post — front-end article after import from Drive.

== Changelog ==

= 1.6.0 =
* **Incoming tree** — browse Drive/GitHub one folder at a time instead of loading the whole tree. Nested folders stay closed until you expand them; role folders (`incoming`, `published`, `failed`) open when they contain child folders. Shortcuts and shared-drive items are listed. Empty folders fetch children on expand.
* **Google Docs tables** — classic Docs tables survive the `======` split and import as Gutenberg `core/table` (cells are no longer dumped as paragraphs like “Risk Zone”).
* **Fonts** — Google Arial/11pt spans are stripped so the site theme fonts apply. Optional **Keep document fonts** on import.
* **Package images** — jpg/png/gif/webp/avif in the tree use an image icon. Click to preview in the workspace **without Import/Reject**. Open-outside icon opens the file in Drive or GitHub.
* **Sync wait** — “Sync already ran recently” is a yellow wait notice (HTTP 429), not a red error.
* **Export errors chip** — count of documents Google could not export as HTML on the last sync. Click the chip for file names and the Drive message. Failed packages are highlighted in the tree.

= 1.5.0 =
* **Incoming** — queue, preview, and import (same `forwp-drive-inbox` slug).
* **Markdown** — Drive `incoming/` packages accept `.md` / `.markdown` as the article (preferred over Google Doc / Word when several files sit in the folder). Pick which document to import.
* **Gutenberg** — import serializes headings, paragraphs, lists, quotes, code, and inline strong/em/u to core blocks. Image markers support left / right / center alignment.
* **Image pin** — package images can be placed in the preview before import (click image, click paragraph). Markdown `![](file.png)` in the same folder becomes `[image:file.png]`.
* **GitHub** — live source: PAT + owner/repo, scan `incoming/`, import Markdown packages, move to published/failed, sideload package images.
* **Analytics** — import history table (source, post ID/type, date, folder alias, site slug, incoming/published paths). **Restore to incoming** moves the published package back to the queue. No plugin reactivation required.

= 1.4.0 =
* **Inbox** — editorial dashboard: connection/sync status, storage **source tabs** (Google Drive live; GitHub, OneDrive, Dropbox marked Soon), document **queue** + side **workspace** for preview and import.
* **UX** — select a document to preview beside the queue; Open folder + Sync in one chrome block; brand icons per source; compact **Import** / Edit in Google Docs / Reject actions.
* **Package folder** — for articles in an `incoming/` subfolder, show doc/image counts, file names, and Open folder (after Sync).
* **Core Image template (default)** — `[image:filename.jpeg]` markers sideload from the package folder into `core/image` blocks on import.
* **Compatibility** — Tested up to WordPress 7.1.

= 1.3.0 =
* **Body → block templates** — build an import collection in Settings: map document section headings to Gutenberg block templates.
* **4WP FAQ template** — converts FAQ sections (Heading 2 + Heading 3 Q/A) into `forwp/faq` + core accordion (requires 4WP FAQ plugin).
* **Core Accordion template** — same document pattern, outputs `core/accordion` only (no FAQ wrapper).
* **Import** — block markup preserved in post content; Inbox preview notes when body is block markup.

= 1.2.0 =
* **Polylang multilingual import** — pluggable language providers; manual **Content language** in Inbox when the site has multiple languages (no default; no Drive subfolders).
* **Import** — assign post language on create via Polylang; **Update existing post** lists and validates targets in the selected language only.
* **Settings** — **Multilingual integration** registry (Polylang live, WPML planned, single-language fallback) with Active / Inactive / Not installed / Planned badges.
* **REST** — `language` on document import; `lang` on import-targets; `multilingual` payload on inbox, preview, settings, and targets.
* **Developers** — `Language_Provider_Interface`, `Language_Provider_Registry`, `Import_Language_Resolver`; filter `forwp_drive_language_providers`. WPML provider class kept; not enabled for import until a future release.

= 1.1.0 =
* Inbox: **Update existing post** — import a Drive document into a selected post or page instead of creating a new draft.
* REST: import targets search for choosing an existing post or page to update.
* Import: `Post_Creator::update_existing()` and `Import_Target_Resolver` for safe target validation.

= 1.0.2 =
* Inbox: single **Sync from Drive** button (replaces separate refresh control).
* OAuth: detect expired or revoked tokens on Inbox/Settings load; show reconnect notice before sync.
* WordPress.org listing assets: icons, banners, and screenshots.

= 1.0.1 =
* Plugin review: remove unnecessary core file include; load wp-admin image helpers only when generating attachment metadata.

= 1.0.0 =
* First WordPress.org release: Google Drive OAuth, folder sync, inbox, draft import, field mapping, WP-CLI sync.
* Plugin Check fixes: i18n translators, redirect URI copy, OAuth error messages, readme External services.

= 0.1.0 =
* Internal MVP.

== Upgrade Notice ==

= 1.6.0 =
Incoming tree is lazy, Google Docs tables import as tables, package images preview in the workspace, and Export errors explain failed Drive HTML exports.

= 1.5.0 =
GitHub Markdown is live. Incoming accepts Markdown packages. Analytics records imports and can restore a package to incoming.

= 1.4.0 =
Editorial Inbox: source tabs, status bar, queue + workspace preview. Tested up to WordPress 7.1.

= 1.3.0 =
Map Google Doc FAQ / image sections from **4WP Drive → Patterns** (4WP FAQ, Core Accordion, Core Image).

= 1.2.0 =
Polylang sites: pick content language in the Inbox before import; update mode respects the selected language. WPML is planned. See Settings → Multilingual integration.

= 1.1.0 =
Update existing posts and pages from the Inbox—pick a target post before import.

= 1.0.2 =
Inbox sync UX and Google Drive connection health notices. Reconnect in Settings if sync stops working.

= 1.0.1 =
Maintenance release addressing WordPress.org plugin review feedback on core file includes.

= 1.0.0 =
First public release. Connect Google Drive, sync incoming docs, and import drafts from the admin Inbox.
