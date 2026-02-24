=== AI Shopping for WooCommerce ===
Contributors: flavflavor
Tags: woocommerce, ai, api, mcp, agentic-commerce
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Instantly expose your WooCommerce storefront to AI agents via ACP, UCP, and MCP protocols.

== Description ==

AI Shopping for WooCommerce lets any AI agent — ChatGPT, Gemini, Claude, Copilot, or custom agents — discover products, build carts, negotiate checkout, and track orders on your WooCommerce store without ever rendering a browser page.

**Zero configuration required.** Activate the plugin, generate an API key, and your store is AI-ready.

= Three Protocols, One Plugin =

* **Agentic Commerce Protocol (ACP)** — The OpenAI/Stripe standard for AI-powered checkout.
* **Universal Commerce Protocol (UCP)** — The Shopify/Google standard with capability negotiation and /.well-known/ucp discovery.
* **Model Context Protocol (MCP)** — The Anthropic standard for tool-based AI interaction.

= Core Features =

* Full product catalog search with filtering by category, price, attributes, and more
* Headless cart management with token-based sessions (no browser cookies required)
* Complete checkout flow: addresses, shipping methods, payment, order placement
* Order tracking and customer account endpoints
* Webhook notifications for order status changes
* Rate limiting and Bearer token authentication
* Auto-detection of 16+ popular WooCommerce extensions

= Extension Compatibility =

When detected, AI Shopping automatically extends the API for:

* WooCommerce Subscriptions
* WooCommerce Product Bundles
* WooCommerce Composite Products
* WooCommerce Product Add-Ons
* WooCommerce Memberships
* WooCommerce Bookings
* WooCommerce Stripe Gateway
* WooCommerce PayPal Payments
* WPML WooCommerce Multilingual
* Advanced Custom Fields (ACF)
* And more...

== Installation ==

1. Upload the `ai-shopping` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **AI Shopping** in the admin menu.
4. Generate an API key from the **API Keys** tab.
5. Use the Consumer Secret as a Bearer token in your AI agent's requests.

== Frequently Asked Questions ==

= Do I need to configure anything? =

Just generate an API key. All endpoints are available immediately after activation.

= Which AI agents work with this plugin? =

Any AI agent that can make HTTP requests. The plugin supports three protocol standards: ACP (OpenAI/Stripe), UCP (Shopify/Google), and MCP (Anthropic).

= Is HTTPS required? =

In production, yes. For local development, you can enable HTTP access in settings.

= Does this plugin process payments? =

The plugin uses WooCommerce's payment gateway infrastructure. It does not process payments directly but passes payment tokens to your configured payment gateways.

== Changelog ==

= 1.0.0 =
* Initial release
* Core storefront API: products, cart, checkout, orders, store info
* ACP protocol adapter (4-endpoint checkout model)
* UCP protocol adapter (merchant profile, capability negotiation, shopping service)
* MCP protocol adapter (tool manifest and execution)
* Headless cart session system
* API key management with permissions
* Rate limiting
* Webhook dispatcher for order events
* Extension detector for 16+ WooCommerce extensions
* Admin settings page with protocol toggles and endpoint reference
