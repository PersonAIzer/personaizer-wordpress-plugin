# PERSONAIZER — WordPress Plugin

Connect a WordPress / WooCommerce site to a [PERSONAIZER](https://personaizer.com) AI persona in one
click: a floating chat widget and AI-powered search that answer visitors from the site's own pages,
posts and WooCommerce products, and can keep that content synced to the persona so answers stay
current.

PERSONAIZER is an external SaaS. This plugin is a client for it — chat runs against the PERSONAIZER API
and the widget script is served from PERSONAIZER's CDN. A free plan exists, and connecting creates a
persona automatically — no paid account is needed to try it.

## Install

- **From the WordPress.org plugin directory** (once approved): Plugins → Add New → search "PERSONAIZER"
  → Install → Activate.
- **From a release zip**: grab the latest from [Releases](../../releases), then in WordPress admin go to
  Plugins → Add New → Upload Plugin, and install it from the zip.

## Use it

1. Open the **PERSONAIZER** menu in wp-admin and click **Connect**.
2. Approve on the PERSONAIZER consent screen — a free persona is created automatically.
3. The chat widget appears on the site's front end and answers questions.
4. Optionally turn on content sync (Pages/Posts/WooCommerce Products) so the persona learns your actual
   site content, and "recognize signed-in customers" if you want the AI to greet logged-in visitors by
   name.

Everything the owner configures beyond that — the widget's look, greeting, FAQ, and the persona itself —
lives on [personaizer.com](https://personaizer.com); this plugin is the bridge to it, not a second copy of
those settings.

## Docs

- **[ARCHITECTURE.md](ARCHITECTURE.md)** — how the plugin is put together: the file map, the two backend
  auth modes, and how content sync/backfill/reconciliation fit together.
- **[RELEASING.md](RELEASING.md)** — the WordPress.org submission process and how to ship a new version.
- The plugin's own `readme.txt` (inside `personaizer/`, bundled in every release zip) is the
  WordPress.org-facing feature list, FAQ, and changelog.
- **[testing/](testing/)** — a turnkey kit for validating the plugin against a real public WordPress site
  (LocalWP can't exercise image sync, Connect's PKCE callback, WP-Cron, or CORS — see
  `testing/README.md`).

## Repo layout

```
personaizer/     the plugin itself — this folder's contents are exactly what ships in the zip
build-zip.sh           packages personaizer/ into an installable zip (prod / --dev / --org builds)
release.sh              cuts the GitHub release — the zip and the update manifest, as one release
testing/                 kit for testing against a real public site + the WooCommerce sample catalog
```

## License

GPLv2 or later — see [LICENSE](LICENSE).
