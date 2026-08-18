=== 4WP Drive ===
Contributors: 4wpdev, anatolikkk
Tags: google drive, import, editorial, drafts, content pipeline
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Google Docs from Drive into WordPress drafts—or update existing posts and pages from the Inbox.

== Description ==

**4WP Drive** connects a Google Drive folder workflow to WordPress: writers drop documents in Drive, editors review them in an admin **Inbox**, and approved content becomes **draft posts** or updates **existing posts and pages**—without copy-paste.

A plugin by [4wp.dev](https://4wp.dev/).

Learn more, workflow details, and comparisons on the plugin page at [4wp.dev/plugin/4wp-drive/](https://4wp.dev/plugin/4wp-drive/).


= Perfect for =

* **Editorial teams** that draft in Google Docs but publish in WordPress
* **Agencies** with a shared Drive `incoming/` folder and a review step before publish
* **Content pipelines** that need structured front-matter (title, slug, categories, SEO fields) parsed from the doc header
* Sites that want **API credentials stored encrypted** and **OAuth handled server-side**

= How it works =

1. Install and activate **4WP Drive**.
2. Create Google OAuth credentials and connect Drive in **Storage sources**.
3. Set your Drive **root folder ID**; the plugin uses `incoming/` and `published/` subfolders.
4. Drop a Google Doc (with optional image) into `incoming/` or run **Sync**.
5. Open **Inbox** → Preview → **Import as Draft**, **Update existing post**, or Reject.
6. On import, files move to `published/` in Drive.

= Key features =

* **Google Drive OAuth** — connect an admin Google account; tokens stored encrypted
* **Folder sync** — scan `incoming/` for new Google Docs and images
* **Admin Inbox** — preview HTML, import as draft, update an existing post or page, or reject
* **Document template** — front-matter lines before a separator (`---` or `=====`) map to post fields; body becomes post content (headings, lists, bold preserved)
* **Configurable field map** — title, slug, categories, tags, author, dates, SEO meta (when supported)
* **Featured image** — import image from the same Drive subfolder
* **Update existing content** — search and pick a post or page, then replace its content from the Drive document
* **REST API** + **WP-CLI** `wp forwp-drive sync` for manual sync
* **Roadmap sources** — GitHub Markdown/MDX and additional storage providers registered for future releases

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

== Installation ==

1. Upload the plugin to `/wp-content/plugins/4wp-drive/` or install from the Plugins screen.
2. Activate **4WP Drive**.
3. Open **4WP Drive → Storage sources** (or **Documentation** for the setup guide).
4. Paste **Client ID** and **Client Secret** from Google Cloud Console → **Save credentials**.
5. Click **Connect Google Drive**, then enter your Drive **root folder ID** and save subfolders.
6. Use **Inbox** to preview and import documents.

Optional: define `FORWP_DRIVE_GOOGLE_CLIENT_ID`, `FORWP_DRIVE_GOOGLE_CLIENT_SECRET`, or `FORWP_DRIVE_OAUTH_REDIRECT_URI` in `wp-config.php`.

== Frequently Asked Questions ==

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

== Screenshots ==

1. Storage sources — source registry (Google Drive live; GitHub, OneDrive, Dropbox planned).
2. Google Drive — OAuth credentials, Connect, and folder mapping.
3. Document template — map front-matter labels to post fields and taxonomies.
4. Drive folders — WordPress settings alongside the matching `incoming` / `published` / `failed` folders in Drive.
5. Inbox — synced articles from `incoming/` with Preview, Import as Draft, and Reject.
6. Document template — example header format next to a Google Doc with front-matter.
7. Inbox preview — parsed metadata and featured image before import.
8. Imported draft — post editor with content, featured image, categories, and Yoast SEO fields.
9. Published post — front-end article after import from Drive.

== Changelog ==

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

= 1.1.0 =
Update existing posts and pages from the Inbox—pick a target post before import.

= 1.0.2 =
Inbox sync UX and Google Drive connection health notices. Reconnect in Settings if sync stops working.

= 1.0.1 =
Maintenance release addressing WordPress.org plugin review feedback on core file includes.

= 1.0.0 =
First public release. Connect Google Drive, sync incoming docs, and import drafts from the admin Inbox.
