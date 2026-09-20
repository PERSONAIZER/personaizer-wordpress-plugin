=== PERSONAIZER ===
Contributors: personaizer
Tags: ai chatbot, live chat, chat widget, woocommerce, customer support
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your site to a PERSONAIZER AI persona in one click — a chat widget that answers from your own pages, posts and products.

== Description ==

This plugin puts your PERSONAIZER AI persona on your WordPress site as a floating chat widget — and, if you want it to, teaches that persona your site's own content, so it answers from your real pages, posts and WooCommerce products instead of guessing.

You build and train the persona at [personaizer.com](https://personaizer.com); this plugin is the bridge between it and your site. Conversations, knowledge and the widget's appearance all live in your PERSONAIZER account.

= One-click Connect =

There is no ID to copy and paste. Click **Connect**: the plugin tells PERSONAIZER what your site is — its name, logo, colours, languages, currency and what it sells, read straight from WordPress, no crawling — and you approve it on the consent screen: the brand it belongs to (the form is already filled in), the persona that answers on it (built for you on the spot if you have none), and what the AI may learn. The plugin receives its credentials automatically; the widget goes live and your content starts syncing.

**Disconnect** at any time — it freezes the connection on personaizer.com and clears every credential and setting this plugin stored on your site. Nothing is deleted on the PERSONAIZER side, so reconnecting later picks up where you left off.

= Teach the AI your site (optional) =

Your content syncs in independent **streams** — Pages, Posts, WooCommerce Products, and any custom post type your site registers (a Recipes type simply appears beside the rest). Each stream is switched on or off on personaizer.com, next to everything else the AI knows:

* Switch a stream off and the AI stops using it — **nothing is deleted**. Switch it back on and it picks up where it left off.
* Published items are pushed with their text and images (featured and inline), and kept in sync as you publish, edit, unpublish, trash or delete them.
* What this plugin syncs, only this plugin can change: those items are read-only on personaizer.com, so your site is always the source of truth. Edit them here.
* Once a day (and whenever you click **Sync now**) the plugin compares each stream with what PERSONAIZER holds, pushes anything missing or changed, and removes what no longer exists on your site — so a missed update or a deletion made while the plugin was inactive never lingers.

= WooCommerce =

When WooCommerce is active, a **Products** stream appears. Products are synced as structured commerce data, not just text, so the AI can filter and recommend rather than paraphrase:

* Price, sale price, stock availability, SKU, attributes, categories and images.
* **Per-SKU variants** — each variation is synced individually, so "the blue one in medium" resolves to a real SKU.
* **Stock stays current in real time** — a purchase that changes stock re-syncs that product automatically.

= Plan-aware syncing =

If your site holds more content than your PERSONAIZER plan's knowledge allowance, the plugin syncs what fits and tells you plainly how much landed, with a link to upgrade. Whatever did not fit is remembered, and syncs **automatically** as soon as the plan has room — you do not have to come back and re-sync by hand.

= Recognise signed-in customers (optional) =

The plugin can identify a signed-in WordPress user to the AI, so it can greet them by name and continue their previous conversations. When this is on, that customer's **name, email address and phone number** are sent to PERSONAIZER, signed with a per-site Identity Secret.

This is switched **off** by default — you can turn it on at any time in the plugin's settings. If you turn it on, disclose it in your site's privacy policy.

= Where the widget's look is configured =

The widget's theme, position, accent colour, title, greeting, FAQ and contact form are **not** plugin settings — they live on the persona, in the **Widget** tab of your PERSONAIZER dashboard, so every place the persona is embedded stays consistent. Changes there apply to your site without touching WordPress.

The plugin does **not** process payments, and chat conversations are not stored in WordPress.

== External Services ==

This plugin connects to PERSONAIZER, a third-party AI chat service, to load and run the chat widget. This connection is required for the plugin to function.

**1. Chat widget script (PERSONAIZER CDN)**
The plugin loads the widget script `chat.js` from the PERSONAIZER content delivery network (`https://personaizerprodstore.blob.core.windows.net`). It is loaded on every front-end page once a persona was chosen at Connect, using that persona's public ID.

**2. PERSONAIZER Chat API (`https://api.personaizer.com`)**
Once loaded, the widget communicates with the PERSONAIZER API to power the conversation. When a visitor interacts with the chat, the following is sent to PERSONAIZER:

* The messages the visitor types into the chat.
* The page URL / referring context of the conversation.
* A public Persona ID that identifies which persona should respond.

The chat widget sends data only when a visitor opens and uses it.

**3. Connecting your site (`https://personaizer.com` and `https://api.personaizer.com`)**
When you click Connect, you are sent to the PERSONAIZER consent screen to approve the connection, choose the brand and persona, and tick what may sync. Your site's address, name and a count of its pages, posts and products are included so the screen can show what will be learned. On approval the plugin exchanges a single-use code for the credentials it stores (an integration key for syncing; the Persona ID and your account's Identity Secret when you chose a widget persona).

**4. Content and product sync (optional; `https://api.personaizer.com`)**
If you switch a stream on, the plugin sends the title, URL, text and image URLs of the published items in that stream so your AI can answer from them, and updates or removes them when you edit or delete that content. For WooCommerce products this also includes price, sale price, stock availability, SKU, attributes and categories, including per-variant values. Once a day it also sends the list of published items in each stream (ids and a checksum of each) so PERSONAIZER can tell what is missing, changed or gone. It reports which content types your site has, with counts, so you can switch streams on from personaizer.com, and reads your plan's knowledge allowance so it can tell you when your content exceeds it. This runs only for the streams you explicitly switch on.

**5. Customer recognition (optional; `https://api.personaizer.com`)**
If customer recognition is enabled, a signed-in WordPress user's name, email address and phone number are sent to PERSONAIZER, in a token signed with your site's Identity Secret, so the AI can recognise them. Nothing is sent for signed-out visitors. You can switch this off in the plugin's settings.

Your use of PERSONAIZER is governed by:

* Terms of Service: https://personaizer.com/terms
* Privacy Policy: https://personaizer.com/privacy

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New**, search for **PERSONAIZER**, click **Install Now**, then **Activate**. (Installing from a zip works the same way through **Upload Plugin**.)
2. Open the **PERSONAIZER** menu in your admin sidebar and click **Connect**.
3. Approve your site on the PERSONAIZER consent screen: pick the brand it belongs to, the persona to use, and tick what it may learn from.
4. You land on your brand's knowledge map on personaizer.com, where the sync is already under way — visit your site and the chat widget is live.

You will need a PERSONAIZER account. A free persona is enough to get started.

= Updating =

Updates arrive through WordPress like any other plugin's; your connection is preserved across an update.

**Updating from 1.x or 2.x to 3.0:** connect your site once more (the PERSONAIZER page in your admin asks you to). Version 3.0 talks to a new sync API, so the site has to be approved again. Your persona, its knowledge and the widget are untouched.

== Frequently Asked Questions ==

= Do I need a PERSONAIZER account? =
Yes. You create and train your AI persona at personaizer.com, then connect this plugin to it. A free persona is enough to get started.

= Where do I change the widget's colour, position or greeting? =
In your PERSONAIZER dashboard, on the persona's **Widget** tab — not in this plugin. Those settings belong to the persona so that every site and page it is embedded on stays consistent, and they take effect without any change in WordPress.

= Does this work on sites without WooCommerce? =
Yes. The widget works on any WordPress site. The Products stream only appears when WooCommerce is active.

= Where do I switch a stream on or off? =
On personaizer.com, on your brand's page — this plugin shows each stream's state and links there. Keeping the switch beside everything else the AI knows means you see your site's pages next to the files you uploaded by hand, and switch either on or off in one place.

= If I switch a sync stream off, does it delete what the AI already learned? =
No. Switching a stream off stops the AI from using it and stops further syncing; nothing is deleted. Switching it back on resumes from where it stopped, and any deletions you made in the meantime are applied then.

= Can I edit a synced page or product on personaizer.com? =
No — what this plugin syncs is read-only there, so your site stays the source of truth. Edit it in WordPress and the change syncs. Files you upload on personaizer.com yourself are separate and stay editable there.

= What happens if I have more content than my plan allows? =
The plugin syncs as much as your plan's knowledge allowance permits, then tells you how much landed and links you to upgrade. Everything that did not fit is remembered and syncs automatically once the plan has room — no manual re-sync needed.

= Does syncing rely on WP-Cron? =
Yes. The initial catalog sync, the daily check, the retry of items that did not fit your plan, and queued removals all run on WordPress's scheduled tasks. The plugin's **System info** panel shows whether WP-Cron is working on your site. If your host disables WP-Cron (`DISABLE_WP_CRON`), configure a real server cron to request `wp-cron.php` on a schedule, as you would for any other scheduled WordPress task.

= What does the plugin store, and what is sent to PERSONAIZER? =
In WordPress it stores your integration key, brand and persona ids, your account's Identity Secret, and the sync's own bookkeeping: an outbox table (`wp_personaizer_outbox`) of records still to be sent, and the state of the daily check. Which streams sync is not stored here — it is read from personaizer.com. Chat conversations live in your PERSONAIZER account, not in WordPress. What is sent is listed in detail under **External Services** above.

= How do I remove everything? =
Use **Disconnect** to clear every credential and setting the plugin stored, while keeping the plugin installed. Deleting the plugin removes the same data. Neither touches your persona or its knowledge on PERSONAIZER.

== Changelog ==

= 3.0.3 =
* No code change: a code-standards annotation in the outbox was scoped so the wordpress.org Plugin Check report reads clean.

= 3.0.2 =
* The batch insert into the outbox builds its placeholders inline, and the backfill and daily check no longer set `suppress_filters` explicitly — `get_posts()` already does, which is what keeps a language plugin from hiding the other languages' records. Both are what wordpress.org's Plugin Check asked for on 3.0.1.

= 3.0.1 =
* Requires WordPress 6.2 or newer: every query on the plugin's outbox table is now fully prepared, the table name through WordPress's `%i` identifier placeholder.
* The admin page's working variables no longer live in the request's global scope.
* The plugin's URI is its repository, not the company site.

= 3.0.0 =
* Rebuilt from the ground up around one outbox: every change on the site becomes a row, and a worker sends rows to PERSONAIZER in batches of up to 100 — a 500-page backfill is a handful of requests instead of 500, and nothing is lost when a request dies mid-way.
* Connect tells PERSONAIZER what your site is before you get there — name, logo, colours, every language your site publishes in (WPML, Polylang, TranslatePress) with the primary first, currency, and what you sell — so the brand form on the consent screen arrives filled in and the persona can be built right there, with no crawling.
* Pages and posts are sent as real markdown (headings, lists, links, tables) instead of stripped text.
* Hidden and private WooCommerce products are no longer sent.
* The admin page is a status page: what you are connected as, whether the persona is ready, and per stream how much is synced, waiting, or refused — plus Sync now and Disconnect. Everything else is a link to personaizer.com.
* Talks to the four-call sync API introduced with this release. The plugin's readers are tested against the API's own recorded responses, so the two cannot drift apart unnoticed again.
* A site whose connection was removed on personaizer.com says so and offers to connect again, instead of showing its last known state as if nothing had happened.
* **You must connect your site again after this update.** Nothing on personaizer.com is lost.
= 2.1.1 =
* Wording follows the account model: the Identity Secret that signs customer-recognition tokens is your account's, shared by every persona you embed, so rotating it on personaizer.com rotates it for this site too.

= 2.1.0 =
* Follows the names personaizer.com now uses: your site is an **integration**, what it syncs are **streams**, and its credential is an integration key (`ik_`). Existing installs reconnect once from the plugin's settings.
* All sync calls moved to `/v1/integration/…`; the daily check and manifest are unchanged in behaviour.
* WooCommerce attribute taxonomies (`pa_*`) are left untouched by the rename.

= 2.0.0 =
* Your site is now a **connector** on personaizer.com: it belongs to a brand, syncs with its own key, and the lanes it syncs are switched on and off there — next to everything else the AI knows. What the plugin syncs is read-only on personaizer.com, so your site is the source of truth.
* A daily check compares each lane with what PERSONAIZER holds, pushes what is missing or changed, and removes what no longer exists on your site. "Check what's out of date" runs it on demand.
* The consent screen asks for the brand, the persona and the lanes in one step; a site can sync knowledge without embedding a widget.
* **You must connect your site again after this update** — see Updating. Nothing on personaizer.com is lost.

= 1.3.1 =
* Conversations started from your site now show as "WordPress" in the PERSONAIZER inbox and analytics, instead of a generic website embed. Nothing about what is sent changes — the widget just tells PERSONAIZER which plugin it is running in.

= 1.3.0 =
* AI Search has been removed. PERSONAIZER no longer offers the search product it was built on, so the "Let visitors search with AI" setting, the `[personaizer_search]` shortcode and the search box are gone. If a page still contains the shortcode, remove it from that page. Chat and content syncing are unchanged.

= 1.2.3 =
* Plugin updates now come from our GitHub releases page — the same place the plugin is downloaded from. Update notices in WordPress are unchanged; they are simply served from one place instead of two, so a released version can no longer be missing from the update check.
* No changes to how your site or its products are synced.

= 1.2.2 =
* Product photos are now described by PERSONAIZER's image analysis, so customers can find a product by what it visibly is — its colour, its shape, or text printed on the packaging — even when none of that appears in the product's own text. The plugin used to send the product's name as the photo's description, and a photo that already had a description was left alone, so this analysis never ran for any product and the name was simply repeated back.
* Your next sync re-sends every product so their photos get described.

= 1.2.1 =
* Deleting many products at once no longer leaves your AI recommending products you removed. Each deletion used to contact PERSONAIZER on the spot, so emptying a whole category meant hundreds of calls inside one page request — WordPress cut it short part-way, and every deletion it hadn't reached yet was lost with nothing to retry it. Deletions are now recorded first and sent together, so a bulk delete arrives complete, and anything a slow connection interrupts is retried instead of dropped.
* Deleting a single product is unchanged — it still reaches your AI right away.

= 1.2.0 =
* Products now sync with every category they belong to, not just one. WooCommerce lets a product sit in several categories and doesn't say which is "the" one, so the plugin used to guess — and a product in two branches (say an LED driver that is both lighting and a power supply) lost one of them, along with that branch's filters. All of its categories are now sent as full paths, and PERSONAIZER decides how to group them.
* Sub-categories are preserved as paths ("Sheet materials > Dibond") instead of being flattened to a single name, so two different branches that happen to share a sub-category name no longer collide.
* Stores with many categories no longer fail to sync. Previously a catalog with more categories than the account's limit was rejected outright, syncing nothing; now everything syncs and the deepest categories simply group under their parent.
* Your next sync re-sends every product, so the new categories take effect without any action from you.

= 1.1.2 =
* Display name simplified to "PERSONAIZER" (was "PERSONAIZER Chat & Search") — no functional change.

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
