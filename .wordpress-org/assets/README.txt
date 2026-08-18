WordPress.org directory listing assets (SVN assets/, not inside the plugin ZIP).

## Folder workflow

| Folder | Purpose |
|--------|---------|
| info/ | Local staging — originals (icon.png, banner.png, screenshot-N.png). Not in plugin ZIP. |
| .wordpress-org/assets/ | Ready-to-upload PNGs with wp.org file names. Commit to Git; sync to SVN. |

Regenerate icon/banner from info/:

```bash
INFO="wp-content/plugins/4wp-drive/info"
OUT="wp-content/plugins/4wp-drive/.wordpress-org/assets"
sips -z 128 128 "$INFO/icon.png" --out "$OUT/icon-128x128.png"
sips -z 256 256 "$INFO/icon.png" --out "$OUT/icon-256x256.png"
sips -z 250 772 "$INFO/banner.png" --out "$OUT/banner-772x250.png"
sips -z 500 1544 "$INFO/banner.png" --out "$OUT/banner-1544x500.png"
```

Or: `FOURWP_PUBLIC="$(pwd)" bash 4wp-cursor-rules/docs/4wp-drive/release/export-wporg-assets.sh` (from site `public/` root).

## Required for SVN assets/

- icon-128x128.png
- icon-256x256.png
- banner-772x250.png
- banner-1544x500.png
- screenshot-1.png … (match readme.txt == Screenshots ==)

Sync to: `~/wordpress.org/4wp-drive/assets/` (canonical SVN checkout — not inside Local `public/`).
