=== 4WP Drive ===
Contributors: 4wpdev
Tags: google drive, import, editorial, drafts
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import Google Docs from Drive (and roadmap: GitHub MD/MDX) into WordPress drafts.

== Description ==

4WP Drive connects a Google Drive folder structure to WordPress:

* Drop Google Docs into Drive `incoming/` (GitHub `.md` / `.mdx` planned)
* Sync discovers new documents
* Admin Inbox: Preview, Import as Draft, Reject
* On import, files move to `published/`

== Installation ==

1. Upload the plugin and activate.
2. Open **4WP Drive → Settings** and follow the setup guide to create Google OAuth credentials.
3. Paste **Client ID** and **Client Secret** in the settings form (stored encrypted in the database).
4. Click **Connect Google Drive**, then set your root folder ID.
5. Use the document template: lines before a separator paragraph (only `---`, or a row of `=` three or more times, e.g. `=====`) are plain `Label: value` rows (matched to your field map); everything after that line is the post body (headings, lists, and bold are kept).

Optional: define `FORWP_DRIVE_GOOGLE_CLIENT_ID` and `FORWP_DRIVE_GOOGLE_CLIENT_SECRET` in `wp-config.php` to lock credentials.

== Changelog ==

= 0.1.0 =
* Initial MVP: Google Drive OAuth, folder sync, inbox, draft import.
