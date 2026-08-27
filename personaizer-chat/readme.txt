=== PERSONAIZER Chat & Search ===
Contributors: personaizer
Tags: ai chatbot, live chat, chat widget, woocommerce, customer support
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your site to a PERSONAIZER AI persona in one click — a chat widget and AI-powered search that answer from your own pages, posts and products.

== Description ==

This plugin puts your PERSONAIZER AI persona on your WordPress site as a floating chat widget and, optionally, an AI-powered search box — and, if you want it to, teaches that persona your site's own content, so both answer from your real pages, posts and WooCommerce products instead of guessing.

You build and train the persona at [personaizer.com](https://personaizer.com); this plugin is the bridge between it and your site. Conversations, knowledge and the widget's appearance all live in your PERSONAIZER account.

= One-click Connect =

There is no ID to copy and paste. Click **Connect**, approve your site on the PERSONAIZER consent screen, and choose what the AI may learn. The plugin receives its credentials automatically and the widget goes live.

**Disconnect** at any time — it clears every credential and setting this plugin stored on your site. Nothing is removed on the PERSONAIZER side, so reconnecting later picks up where you left off.

= Teach the AI your site (optional) =

Your content syncs in independent **lanes** — Pages, Posts, WooCommerce Products, and any custom post type your site registers (a Recipes type simply appears beside the rest). Each lane has its own switch:

* Switch a lane off and the AI stops using it — **nothing is deleted**. Switch it back on and it picks up where it left off.
* Published items are pushed with their text and images (featured and inline), and kept in sync as you publish, edit, unpublish, trash or delete them.
* Removals that happen while a lane is switched off are remembered, re-checked against your live site, and applied when that lane resumes — so a page you deleted never lingers in the AI's knowledge.

= WooCommerce =

When WooCommerce is active, a **Products** lane appears. Products are synced as structured commerce data, not just text, so the AI can filter and recommend rather than paraphrase:

* Price, sale price, stock availability, SKU, attributes, categories and images.
* **Per-SKU variants** — each variation is synced individually, so "the blue one in medium" resolves to a real SKU.
* **Stock stays current in real time** — a purchase that changes stock re-syncs that product automatically.

= Plan-aware syncing =

If your site holds more content than your PERSONAIZER plan's knowledge allowance, the plugin syncs what fits and tells you plainly how much landed, with a link to upgrade. Whatever did not fit is remembered, and syncs **automatically** as soon as the plan has room — you do not have to come back and re-sync by hand.

= AI Search (optional) =

Turn on **Let visitors search with AI** and your persona can power search on your site too — the same knowledge that answers chat also ranks search results, so a visitor typing "warm jacket under $50" gets matching products, not just keyword hits.

* Drop the `[personaizer_search]` shortcode anywhere for a ready-made search box, or point the plugin at your theme's own search field with a CSS selector — either way it's a dropdown of live results layered on top of what's already there. Nothing about your existing search is removed or replaced.
* Two quality modes: **Smart** (best relevance, 5 credits per search) or **Fast** (lighter matching, 1 credit per search) — pick whichever fits your plan.
* Off by default, like every other optional feature here.

= Recognise signed-in customers (optional) =

The plugin can identify a signed-in WordPress user to the AI, so it can greet them by name and continue their previous conversations. When this is on, that customer's **name, email address and phone number** are sent to PERSONAIZER, signed with a per-site Identity Secret.

This is switched **off** by default — you can turn it on at any time in the plugin's settings. If you turn it on, disclose it in your site's privacy policy.

= Where the widget's look is configured =

The widget's theme, position, accent colour, title, greeting, FAQ and contact form are **not** plugin settings — they live on the persona, in the **Widget** tab of your PERSONAIZER dashboard, so every place the persona is embedded stays consistent. Changes there apply to your site without touching WordPress.

The plugin does **not** process payments, and chat conversations are not stored in WordPress.

== External Services ==

This plugin connects to PERSONAIZER, a third-party AI chat service, to load and run the chat widget. This connection is required for the plugin to function.

**1. Chat widget script (PERSONAIZER CDN)**
The plugin loads the widget script `chat.js` from the PERSONAIZER content delivery network (`https://personaizerprodstore.blob.core.windows.net`). It is loaded on every front-end page where the widget is enabled, using the public Persona ID you configure.

**2. PERSONAIZER Chat API (`https://api.personaizer.com`)**
Once loaded, the widget communicates with the PERSONAIZER API to power the conversation. When a visitor interacts with the chat, the following is sent to PERSONAIZER:

* The messages the visitor types into the chat.
* The page URL / referring context of the conversation.
* A public Persona ID that identifies which persona should respond.

The chat widget sends data only when a visitor opens and uses it.

**3. Connecting your site (`https://personaizer.com` and `https://api.personaizer.com`)**
When you click Connect, you are sent to the PERSONAIZER consent screen to approve the connection and choose a persona. Your site's address is included so the persona can be matched to it. On approval the plugin exchanges a single-use code for the credentials it stores (Persona ID, secret API key, Identity Secret).

**4. Content and product sync (optional; `https://api.personaizer.com`)**
If you enable a sync lane, the plugin sends the title, URL, text and image URLs of the published items in that lane so your AI can answer from them, and updates or removes them when you edit or delete that content. For WooCommerce products this also includes price, sale price, stock availability, SKU, attributes and categories, including per-variant values. This runs only for the lanes you explicitly switch on. The plugin also reads your plan's knowledge allowance from PERSONAIZER so it can tell you when your content exceeds it.

**5. Customer recognition (optional; `https://api.personaizer.com`)**
If customer recognition is enabled, a signed-in WordPress user's name, email address and phone number are sent to PERSONAIZER, in a token signed with your site's Identity Secret, so the AI can recognise them. Nothing is sent for signed-out visitors. You can switch this off in the plugin's settings.

**6. AI Search (optional; `https://api.personaizer.com`)**
If AI Search is enabled, the text a visitor types into the search box (whether the plugin's own `[personaizer_search]` shortcode or your theme's search field, if you've pointed the plugin at it) is sent to PERSONAIZER as they type, and PERSONAIZER returns matching results from your synced content. This runs only when AI Search is switched on, using the same public Persona ID as the chat widget — no separate credential.

Your use of PERSONAIZER is governed by:

* Terms of Service: https://personaizer.com/terms
* Privacy Policy: https://personaizer.com/privacy

== Installation ==

1. Download the plugin zip (`personaizer-chat-<version>.zip`).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**, choose the zip, click **Install Now**, then **Activate**.
3. Open the **PERSONAIZER** menu in your admin sidebar and click **Connect**.
4. Approve your site on the PERSONAIZER consent screen, pick the persona to use, and tick what it may learn from.
5. You are returned to WordPress connected — visit your site and the chat widget is live.

You will need a PERSONAIZER account. A free persona is enough to get started.

= Updating =

Download the newer zip and upload it the same way (**Plugins → Add New → Upload Plugin**); WordPress will ask you to confirm replacing the current version. Your connection and sync settings are preserved across an update.

== Frequently Asked Questions ==

= Do I need a PERSONAIZER account? =
Yes. You create and train your AI persona at personaizer.com, then connect this plugin to it. A free persona is enough to get started.

= Where do I change the widget's colour, position or greeting? =
In your PERSONAIZER dashboard, on the persona's **Widget** tab — not in this plugin. Those settings belong to the persona so that every site and page it is embedded on stays consistent, and they take effect without any change in WordPress.

= Does this work on sites without WooCommerce? =
Yes. The widget works on any WordPress site. The Products lane only appears when WooCommerce is active.

= If I switch a sync lane off, does it delete what the AI already learned? =
No. Switching a lane off stops the AI from using it and stops further syncing; nothing is deleted. Switching it back on resumes from where it stopped, and any deletions you made in the meantime are applied then.

= What happens if I have more content than my plan allows? =
The plugin syncs as much as your plan's knowledge allowance permits, then tells you how much landed and links you to upgrade. Everything that did not fit is remembered and syncs automatically once the plan has room — no manual re-sync needed.

= Does syncing rely on WP-Cron? =
Yes. The initial catalog sync, the retry of items that did not fit your plan, and queued removals all run on WordPress's scheduled tasks. The plugin's **System info** panel shows whether WP-Cron is working on your site. If your host disables WP-Cron (`DISABLE_WP_CRON`), configure a real server cron to request `wp-cron.php` on a schedule, as you would for any other scheduled WordPress task.

= What does the plugin store, and what is sent to PERSONAIZER? =
In WordPress it stores only options: your Persona ID, your secret API key, your Identity Secret, which lanes you sync, and the sync's own bookkeeping (progress, queued removals, and items waiting for plan space). Chat conversations live in your PERSONAIZER account, not in WordPress. What is sent is listed in detail under **External Services** above.

= How do I remove everything? =
Use **Disconnect** to clear every credential and setting the plugin stored, while keeping the plugin installed. Deleting the plugin removes the same data. Neither touches your persona or its knowledge on PERSONAIZER.

== Changelog ==

= 1.1.1 =
* Fix: page-builder post types (Elementor, ElementsKit and similar) no longer show up as sync lanes — only genuine content types do. A collided lane label (two custom types both named "Templates") now shows its type slug so they're distinguishable.

= 1.1.0 =
* AI Search: an optional AI-powered search box, either the `[personaizer_search]` shortcode or bound to your theme's own search field via a CSS selector. Two quality modes (Smart / Fast) trading relevance against credit cost. Off by default.

= 1.0.0 =
* One-click Connect: approve your site on the PERSONAIZER consent screen and the plugin provisions its own credentials — no IDs to copy.
* Chat widget injection, with the widget's appearance, greeting, FAQ and contact form managed on the persona in the PERSONAIZER dashboard.
* Content sync in independent lanes — Pages, Posts and any custom post type — including featured and inline images, kept in sync through WordPress hooks. Each lane switches on and off without deleting anything.
* WooCommerce Products lane: products sync as structured commerce data (price, sale price, stock, SKU, attributes, categories, images) with per-SKU variants, and stock re-syncs in real time as it changes. Attributes in any language are supported, including non-Latin scripts.
* **Check what's out of date**: compare what your AI holds against what your site actually has. It reports how many items are missing, out of date, or no longer on your site, and changes nothing — updating is a separate click that sends only the differences. It finishes immediately, so it works even where WordPress's scheduled tasks are unreliable.
* Syncing is designed not to lose items: a rejected batch is retried item by item, anything that still doesn't land is queued and re-tried automatically, and an interrupted sync resumes on its own. When something hasn't synced, the plugin says why.
* Removals made while a lane is switched off are queued, re-verified against the live site, and applied when that lane resumes.
* Plan-aware syncing: content beyond your plan's knowledge allowance is reported clearly with an upgrade link, remembered, and synced automatically once the plan has room.
* Optional recognition of signed-in customers, sending their name, email and phone in a token signed with a per-site Identity Secret.
* Warns when your server is still running a cached copy of an older build, which would otherwise make an update appear to do nothing.
* Disconnect and uninstall remove every credential, setting and scheduled task the plugin created.
