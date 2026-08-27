# Testing the plugin on a real public WordPress site

LocalWP is fine for developing the plugin, but it **cannot** validate the behaviours that only
happen on a publicly-reachable HTTPS site — and those are exactly the ones most likely to break in
front of a real owner:

- **Image sync** — the plugin sends the backend your image *URLs*; the backend then *fetches* them.
  A LocalWP site serves `http://something.local/...`, which the deployed backend can't reach, so image
  ingestion is effectively never exercised locally.
- **Connect (PKCE)** — the callback + server-side token exchange need a real public host.
- **WP-Cron** — the backfill, overflow catch-up, and pending-removals all ride it; real hosts behave
  differently from LocalWP (loopback, `DISABLE_WP_CRON`, low-traffic sites).
- **CORS** — the widget calling the API cross-origin from a real customer domain.

So: test on a real public site. It doesn't need to cost anything.

## Files here (all tracked — this kit is meant to be turnkey for the whole team)

| File | Use |
|------|-----|
| `woocommerce-sample-products.csv` | The **official WooCommerce sample catalog** (~20 products — hoodies, tees, beanies, cap, sunglasses, belt; two variable products with colour/size variations; plus grouped/external/downloadable types). Realistic coverage, all images have proper `.jpg` URLs. (Same file WooCommerce ships at `wp-content/plugins/woocommerce/sample-data/`.) |
| `dev-override.php` | **Ready-to-use** mu-plugin, pre-filled for **dev**. Copy it into the test site's `wp-content/mu-plugins/` and the plugin talks to dev. |
| `.gitignore` | Only ignores built zips (artifacts) — see below. |

The plugin **zip is not committed on purpose**: build it fresh with `build-zip.sh` each time. A
committed zip drifts from source and you end up testing the wrong version — the exact failure the
version guards exist to prevent.

## Free public site: TasteWP

1. Go to **tastewp.com** → **Advanced Setup**. Pick **PHP 8.2 or 8.3** (not the newest — WooCommerce
   lags it), a recent WordPress, uncheck the default demo plugins (especially any pop-up plugin, it
   overlays the widget). Create a **Temp Site** (free). You get `https://<name>.tastewp.com` + wp-admin.
   - Free temp sites are short-lived (a few days). A free TasteWP account makes them last longer.
   - InstaWP works too but funnels to paid tiers; **WordPress.com free does NOT allow custom plugins** — avoid it.

2. **Install WooCommerce**: Plugins → Add New → WooCommerce → Activate → skip the setup wizard.

3. **Import the catalog**: Products → Import → upload `woocommerce-sample-products.csv` → run it. You
   should get ~20 products, with the Hoodie and V-Neck Tee carrying colour/size variations and images.

4. **Build + upload the plugin.** Two ways to make it talk to dev — pick one:

   **A. Dev-baked zip (simplest — no override file, skip step 5):**
   ```bash
   ./build-zip.sh --dev     # → dist/personaizer-<ver>-dev.zip
   ```
   Plugins → Add New → **Upload Plugin** → the `-dev.zip` → Activate. It already points at dev. Done.

   **B. Prod zip + override (closer to what a real owner installs):**
   ```bash
   ./build-zip.sh           # → dist/personaizer-<ver>.zip (+ manifest)
   ```
   Upload that zip, then do step 5.

   Both write to the gitignored `dist/` — a consistent spot, never committed (a stale
   committed zip is how you end up testing the wrong version).

5. **(Only for option B) Point it at dev**: put `dev-override.php` into the site's `wp-content/mu-plugins/`
   (create the folder — the file must sit *directly* in it). On TasteWP, install the free **WP File
   Manager** plugin and upload the file through it. It's already filled in for dev — no editing needed.
   - Verify: **PERSONAIZER → System info → API base** should read `https://dev-api.personaizer.com`.
   - Skip options B/5 entirely when testing against **prod** (once prod is deployed): the plugin defaults
     to prod, so no override or dev build is needed.

6. **Connect**: PERSONAIZER menu → **Connect** → approve on the consent screen → let it sync.

## Dev endpoints the override points at

- `PERSONAIZER_API_URL` → `https://dev-api.personaizer.com` — the one `/v1` ingress that fronts both
  Core and Sessions (`deploy/sessions/dev/ingress-v1.yaml`).
- `PERSONAIZER_APP_URL` → `https://dev.personaizer.com` — the dashboard (Connect consent at `/connect`).
- `PERSONAIZER_WIDGET_URL` → dev blob `chat.js`.

## What to verify

- **Connect** returns credentials and the widget goes live (no 500 — a 500 here usually means the
  backend's `Secrets:SecretProtection:Key` isn't set in that environment).
- **Sync**: pages/posts/products land; the dashboard's knowledge count matches the site.
- **Images**: featured + inline images actually embed (the whole reason for a public site).
- **Variants**: the T-shirt's per-SKU variants resolve; the out-of-stock one reads as unavailable.
- **Quota**: force a low cap → 402 → "X of Y, upgrade" → raise the cap → overflow auto-heals.
- **WP-Cron**: the backfill/overflow actually fire (System info panel shows cron status).
- **Chat**: the widget answers on the real domain (CORS), with greeting/FAQ/contact.

## Notes

- The plugin ships pointing at **production**; `build-zip.sh` refuses to package non-prod defaults. The
  dev override lives on the *test site*, never in the package.
- Knowledge keys off the site's domain, so a fresh test site starts clean; a throwaway site's data
  doesn't carry anywhere.
