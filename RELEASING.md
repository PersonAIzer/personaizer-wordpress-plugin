# Releasing

Two separate things ship from this repo: **self-hosted release zips** (this repo's own GitHub Releases,
via `publish.sh`) and **the WordPress.org directory listing** (via SVN, once approved). This doc covers
both — first submission, then every release after.

## First-time WordPress.org submission

### 1. Prerequisites

- **Legal pages live**: the plugin's `readme.txt` links Terms + Privacy — the reviewer will click them. A
  404 is a review flag. Confirm `https://personaizer.com/terms` and `https://personaizer.com/privacy` both
  load, and that Privacy mentions the "recognize signed-in customers" data flow (name/email/phone sent to
  the AI when the site owner opts in).
- **A WordPress.org account** (free), registered with a company-owned email — this account becomes the
  plugin owner. Add `plugins@wordpress.org` to contacts so review mail isn't filtered.
- **`readme.txt`'s `Contributors:` field** must be a real WordPress.org username, or the readme parser
  errors on submission.
- **Listing assets** (not shipped in the zip — pushed into SVN's `assets/` after approval):
  `icon-256x256.png`, `banner-1544x500.png` (+ optionally `banner-772x250.png`), and any
  `screenshot-N.png` you want on the listing page (list each in `personaizer/readme.txt`'s
  `== Screenshots ==` section, in order).

### 2. Build and submit

```bash
./build-zip.sh --org
```

This produces `dist/personaizer-<version>-org.zip` — the submission package, identical to a prod
build minus the self-hosted updater (wordpress.org serves updates itself; shipping your own update channel
alongside it is a hard rejection).

- Go to **https://wordpress.org/plugins/developers/add/**, upload that zip, and submit the description
  below.
- **The slug is assigned from this submission and can never change after approval** (the display name
  can). If you want a different slug than what the folder/readme currently declares, you must say so
  explicitly in your reply during review — changing it silently isn't possible once approved.

> PERSONAIZER Chat & Search connects a WordPress / WooCommerce site to a PERSONAIZER AI persona in one
> click. It embeds a floating chat widget that answers visitors from the site's own pages, posts and
> WooCommerce products, and can keep that content synced to the persona so answers stay current.
>
> PERSONAIZER is an external SaaS (https://personaizer.com). This plugin is a client for it: chat runs
> against the PERSONAIZER API and the widget script is served from PERSONAIZER's CDN. A free plan exists,
> and connecting creates a persona automatically — no paid account is needed to test.
>
> How to test (for the reviewer):
> 1. Install and activate the plugin.
> 2. Open the "PERSONAIZER" menu in wp-admin and click "Connect to PERSONAIZER".
> 3. Approve on the consent screen — a free persona is created automatically.
> 4. The chat widget then appears on the site's front end and answers questions.
> Optional content sync and "recognize signed-in customers" are OFF until the owner enables them in the
> plugin's settings.
>
> External services, plus Terms and Privacy links, are documented in the readme's "External Services"
> section. Terms: https://personaizer.com/terms  Privacy: https://personaizer.com/privacy

### 3. Review

- Official SLA is 14 business days from being queued; realistically 1–3 weeks for a first submission.
- Reply from the account that submitted, on the same email thread — don't start a new one.
- For each code change requested: fix it, re-run `./build-zip.sh --org`, and only attach the new zip if
  the reviewer asked for one (they re-check whatever's most recently uploaded at
  `/developers/add/` — you don't need to re-upload for every reply).
- When approved, you get an email with SVN repository access.

## After approval: publish via SVN

WordPress.org distributes the plugin from SVN, not from the review zip — you push files into `trunk/`,
then tag the release.

```bash
# 1. Check out the (initially empty) SVN repo — slug confirmed in the approval email
svn co https://plugins.svn.wordpress.org/personaizer personaizer-svn
cd personaizer-svn

# 2. Fill trunk/ with the plugin files (the CONTENTS of the --org build, unzipped — never the zip
#    itself, never the updater)
../build-zip.sh --org
unzip -o ../dist/personaizer-<version>-org.zip -d /tmp/pz-org
cp -r /tmp/pz-org/personaizer/* trunk/

# 3. Put the listing images into assets/ — these are NOT shipped to users
cp icon-256x256.png banner-1544x500.png screenshot-1.png assets/

# 4. Register + commit
svn add --force trunk/* assets/*
svn ci -m "Initial release <version>"

# 5. Tag the release — this is what users actually install (readme's Stable tag already matches)
svn cp trunk tags/<version>
svn ci -m "Tag <version>"
```

Confirm the plugin appears at `https://wordpress.org/plugins/personaizer/` and installs from a test
site's Plugins → Add New search.

> SVN is a release system, not git. Only commit finished, tested files — never `.git*`, zips,
> `node_modules`, or vendor folders. Every commit rebuilds the served zip.

## Every future release

1. **Bump the version in three places** (guarded by `build-zip.sh` — it refuses to build if these
   disagree): the `personaizer.php` header `Version:`, the `PERSONAIZER_VERSION` `define()`, and
   `personaizer/readme.txt`'s `Stable tag:`.
2. Add a `== Changelog ==` entry in `readme.txt`.
3. **WordPress.org**: copy the new files into `trunk/`, then:
   ```bash
   svn ci -m "<version>: <summary>"
   svn cp trunk tags/<version>
   svn ci -m "Tag <version>"
   ```
   WordPress.org auto-delivers the new tag to every install — the self-hosted updater is never involved
   for directory installs.
4. **Self-hosted channel** (this repo's own Releases, for anyone who installed from a downloaded zip
   instead of the directory):
   ```bash
   ./publish.sh dev    # test first
   ./publish.sh prod   # then production — every site running the plugin gets offered this version
   ```
   `publish.sh` uploads the zip *then* the update manifest, in that order, deliberately — publishing the
   manifest first would offer every self-hosted install a download that doesn't exist yet.

## Why this plugin passes review

- **GPLv2** license in header + readme + `LICENSE`.
- **External service disclosed** with Terms + Privacy links (`readme.txt`'s `== External Services ==`).
- **No self-serving updates** in the `--org` build — the self-hosted updater is stripped.
- **Opt-in for personal data**: name/email/phone recognition is OFF until the owner enables it.
- **Security**: prepared SQL, escaped output, sanitized input, nonces + capability checks, `ABSPATH`
  guards on every file, no bundled/minified code, no hardcoded secrets (Connect fetches them at runtime).
  CSS/JS are registered via `wp_enqueue_style`/`wp_enqueue_script` (`admin_enqueue_scripts` for the admin
  page, `wp_footer` for the widget) — never raw `<style>`/`<script>` echoes.
- **No admin nags / no trialware** — the free plan is fully functional.
