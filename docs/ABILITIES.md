# Abilities (WordPress Abilities API)

Since **1.9.0**. Requires WordPress **6.9+** (Abilities API); on older WordPress nothing is registered. Code: `src/Abilities/Drive_Abilities.php`.

For AI clients (Claude Desktop, other MCP clients) enable them in **4WP MCP Connector** (Settings → 4WP MCP). MCP tool names replace `/` with `-`, e.g. `forwp-drive-list-incoming`.

- Category: `forwp-drive`.
- Same code paths as the Incoming screen (`Import_Runner`, `Import_Target_Resolver`, `Incoming_Scanner`).
- Permissions (configured import post type): read — `edit_posts`; import — `edit_posts` + `upload_files`; update — also `edit_post` on the target; sync — `manage_options`.
- Responses never contain OAuth tokens, PATs or keys.
- No Reject: it moves the source file to `failed/`; do it in Incoming.

## Typical flow

`list-sources` → (`sync`) → `list-incoming` → `get-document` → `import` **or** `find-targets` + `import-update` → edit the draft in WordPress.

## `forwp-drive/list-sources`

Read-only. No input. Output: `items[]` — `slug`, `label`, `implemented`, `ready`, `status`, `incoming_count`; `last_sync`.

## `forwp-drive/sync`

Scans incoming folders of connected sources (same as the Sync button). At most once a minute (`forwp_drive_sync_locked`). Does not import. Output: `summary` (`scanned`, `new_ready`, `removed`, `ready_total`, …), `last_sync`.

## `forwp-drive/list-incoming`

Read-only.

| Input | Type | Notes |
|---|---|---|
| `source` | string | `google_drive`, `github`; empty = all connected |
| `status` | `inbox` \| `ready` \| `detected` \| `failed` \| `imported` | Default `inbox` (everything waiting) |
| `search` | string | Part of title, slug or file name |
| `page`, `per_page` | integer | Default 1 / 20, `per_page` ≤ 100 |

Output: `items[]` — `id`, `title`, `slug`, `date` (front-matter, as written), `source`, `status`, `file_name`, `package`, `detected_at`, `wp_post_id`, `error`; `total`, `page`, `per_page`, `last_sync`.

## `forwp-drive/get-document`

Read-only. Input: `id` (required), `max_chars` (100–200 000, default 20 000).

Output: list fields + `front_matter` (`title`, `slug`, `date`, `author`, `category`, `tags`, `map`), `content` (body as text), `chars`, `truncated`, `outline[]` (`level`, `text`), `package_files[]` (`id`, `name`, `kind`: document / image / video), `suggested_target` (post with the same slug / title, or null), `can_import`.

## `forwp-drive/find-targets`

Read-only. Input: `slug`, `title`, `search`, `post_type`, `lang`. Output: `items[]` — `id`, `title`, `slug`, `post_type`, `status`, `url`, `edit_url`; `suggested_id`; `post_type`.

## `forwp-drive/import`

Write. New **draft** (never published). Annotations: not read-only, not destructive.

| Input | Type | Notes |
|---|---|---|
| `id` | integer | Required. Incoming document |
| `post_type` | string | Default: Drive settings |
| `language` | string | Polylang / WPML slug; default language when empty |
| `featured_image_file_id` | string | From `package_files`; default: cover / hero / first image |
| `keep_document_fonts` | boolean | Google Docs font styles; default false |

Output: `post_id`, `post_type`, `post_status`, `title`, `mode`, `edit_url`, `preview_url`, `warning`, `requeued`. The source file moves to `published/`; the import is listed in Analytics.

```json
{"id": 158}
```

## `forwp-drive/import-update`

Write. Replaces the content of an existing post. Annotations: **destructive** (enable separately in the connector).

Input: as `import` + `target_post_id` (required). Before the update the current version is saved as a revision. Output: as `import` + `before` (`title`, `modified`, `revision_id`, `revisions_url`) to restore it.

```json
{"id": 158, "target_post_id": 2875}
```

## `forwp-drive/calendar`

Read-only.

| Input | Type | Notes |
|---|---|---|
| `from`, `to` | Y-m-d | Default: current month |
| `include` | `all` \| `incoming` \| `imported` | Default `all` |
| `source` | string | Optional |

Output: `days[]` — `date`, `items[]` (`kind`: `incoming` / `draft` / `scheduled` / `published`, `id`, `title`, `source`, `status` or `post_id`, `post_status`, `edit_url`); `incoming_undated` — incoming documents without a parseable Date (e.g. `TBD`).

Dates: incoming — the **Date** front-matter field (Settings → template fields); imported — the post date.

## Errors

| Code | When |
|---|---|
| `not_found` | Document or target post not found |
| `forbidden` | No `edit_post` on the target |
| `invalid_range` | `to` before `from` |
| `forwp_drive_sync_locked` | Sync ran less than a minute ago |
| `forwp_drive_invalid_status` | Document cannot be imported in its current state |
| `forwp_drive_package_variant` | Only the original file of a `meta.json` package can be imported |
| `forwp_drive_not_ready` | No storage source is ready |
