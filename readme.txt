=== AI Craft Post ===
Contributors: craftpost
Tags: AI content, content automation, SEO, FAQ, Polylang
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to CraftPost and securely receive AI-assisted articles, images, SEO metadata, translations, and FAQ content.

== Description ==

AI Craft Post connects your WordPress website to the CraftPost content automation service through authenticated REST API webhooks.

The plugin can:

* Create and update posts and pages.
* Assign authors, categories, tags, publication status, and templates.
* Download featured and section images to the WordPress Media Library.
* Write supported metadata for Yoast SEO, Rank Math, and All in One SEO.
* Store and display FAQ content with optional FAQPage structured data.
* Create and update multilingual content when Polylang is active.
* Refresh existing articles through approved CraftPost operations.

Every incoming CraftPost request is protected by a site-specific key, timestamp validation, and an HMAC signature.

An account on CraftPost and a site connection key are required to use the service. The plugin does not create content by itself and does not connect to CraftPost until an administrator configures a site key.

= External service =

This plugin connects to the external CraftPost service at [craftpost.net](https://craftpost.net/). The service is required to generate, manage, translate, and deliver content to WordPress.

After an administrator creates a connection and configures the site key, authenticated CraftPost requests may retrieve:

* The site URL, name, language, timezone, and WordPress version.
* WordPress author IDs, usernames, display names, and roles.
* Public post types, categories, templates, and image sizes.
* Existing post titles, content, excerpts, status, author, categories, language, translations, and featured-image status when requested from the CraftPost dashboard.
* Information about supported SEO and multilingual plugins.

CraftPost may send generated or updated article content, metadata, translations, FAQ items, and remote image URLs to the website. Remote images are downloaded from the supplied image host into the WordPress Media Library. That image host receives a request from the website server during the download.

The service is used only after the website administrator configures a CraftPost site key. For information about data handling and retention, review the [CraftPost Privacy Policy](https://craftpost.net/privacy-policy/) and [CraftPost Terms of Service](https://craftpost.net/terms-of-service/).

== Installation ==

1. Upload the `ai-craft-post` folder to `/wp-content/plugins/`, or install the plugin ZIP from **Plugins > Add New > Upload Plugin**.
2. Activate **AI Craft Post**.
3. Sign in to your CraftPost account and add the WordPress website.
4. Copy the generated `aic_live` site key.
5. In WordPress, open **Tools > AI Craft Post**.
6. Paste the site key and save the settings.
7. Choose the optional SEO and FAQ settings required for the website.

Use HTTPS on both the WordPress website and CraftPost account.

== Frequently Asked Questions ==

= Is a CraftPost account required? =

Yes. AI Craft Post is a connector for the external CraftPost service. A CraftPost account and a site key are required.

= Does the plugin generate content inside WordPress? =

No. Content is prepared by the CraftPost service and delivered to WordPress through authenticated REST API requests.

= How are incoming requests protected? =

Requests require a site-specific bearer key, a timestamp no more than five minutes old, and a valid HMAC SHA-256 signature.

= Which SEO plugins are supported? =

The plugin can write supported metadata for Yoast SEO, Rank Math, and All in One SEO when one of those plugins is active.

= Does it support multilingual content? =

Yes. The plugin can connect translated posts when Polylang is installed and configured. Polylang is optional.

= Can I disable the connection? =

Yes. Deactivate the plugin to disable its REST routes. You can also replace the site key from **Tools > AI Craft Post**.

== Screenshots ==

1. AI Craft Post connection and site-key settings.
2. SEO integration settings.
3. FAQ display and structured-data settings.
4. FAQ editor metabox.

== Changelog ==

= 1.3.7 =

* Improved image processing compatibility and optimization handling.
* Improved article refresh validation and translation updates.
* Added publication-date refresh support.
* Improved translated FAQ handling.

== Upgrade Notice ==

= 1.3.7 =

Improves image handling, article refresh validation, translated FAQ content, and publication-date updates.
