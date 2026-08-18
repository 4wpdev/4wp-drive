# Release notes (4WP Drive)

Draft copy for channels **outside** WordPress.org — Google Business Profile, social, newsletter, [4wp.dev](https://4wp.dev/plugin/4wp-drive/), etc.

WordPress.org users see [readme.txt](../../readme.txt) (`== Changelog ==`). Keep that file the canonical technical changelog; use this folder for shorter, audience-friendly announcements.

## Workflow

1. Ship the version (Git tag + SVN `tags/{version}`).
2. Add `{version}.md` here (copy the template below).
3. Paste or adapt sections into each channel (trim length as needed).
4. Link to the plugin page or wp.org listing.

## File naming

| File | When |
|------|------|
| `1.1.0.md` | Release **1.1.0** — update existing post from Inbox |
| `1.2.0.md` | Release **1.2.0** — Polylang multilingual import; WPML planned |
| `README.md` | This guide |

## Template (`X.Y.Z.md`)

```markdown
# 4WP Drive X.Y.Z

**Published:** YYYY-MM-DD  
**WordPress.org:** https://wordpress.org/plugins/4wp-drive/

## Summary

One sentence for listings and posts.

## Highlights

- Bullet for editors
- Bullet for developers (optional)

## Short post (social / Google)

≤ 300 characters. Link to plugin page.

## Longer post (blog / newsletter)

2–3 paragraphs.

## Links

- Plugin: https://4wp.dev/plugin/4wp-drive/
- WordPress.org: https://wordpress.org/plugins/4wp-drive/
- GitHub: https://github.com/4wpdev/4wp-drive
```

## Not shipped in wp.org ZIP

This `docs/` tree is excluded via [.distignore](../../.distignore).
