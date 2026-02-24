# AI Shopping for WooCommerce

**Contributors:**         flavflavor, twoelevenjay
**Donate link:**          https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=leon%40211j%2ecom&lc=MQ&item_name=Two%20Eleven%20Jay&no_note=0&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donateCC_LG%2egif%3aNonHostedGuest
**Tags:**                 woocommerce, ai, api, mcp, agentic-commerce
**Requires at least:**    6.4
**Tested up to:**         6.9
**Requires PHP:**         7.4
**Stable tag:**           1.0.0
**License:**              GPLv2 or later
**License URI:**          http://www.gnu.org/licenses/gpl-2.0.html

Instantly expose your WooCommerce storefront to AI agents via ACP, UCP, and MCP protocols.

## Description

AI Shopping for WooCommerce lets any AI agent — ChatGPT, Gemini, Claude, Copilot, or a custom agent — discover products, build carts, negotiate checkout, and track orders on your WooCommerce store without ever rendering a browser page.

**Zero configuration required.** Activate the plugin, generate an API key, and your store is AI-ready.

AI Shopping for WooCommerce requires the most current version of WooCommerce. You can find that [here](https://wordpress.org/plugins/woocommerce/).

### Three Protocols, One Plugin

* **Agentic Commerce Protocol (ACP)** — The OpenAI / Stripe standard for AI-powered checkout.
* **Universal Commerce Protocol (UCP)** — The Shopify / Google standard with capability negotiation and `/.well-known/ucp` discovery.
* **Model Context Protocol (MCP)** — The Anthropic standard for tool-based AI interaction.

### Features:

* Full product catalog API with search, filtering by category, price range, attributes, stock status, sale status, and more.
* Headless cart management with token-based sessions — no browser cookies required.
* Complete checkout flow: set addresses, choose shipping, select payment method, place order.
* Order tracking and customer account endpoints.
* Webhook notifications for order status changes with HMAC-signed payloads.
* Rate limiting with standard HTTP headers and Bearer token authentication.
* Auto-detection and integration for 16+ popular WooCommerce extensions.
* Admin settings page with API key management, protocol toggles, and a full endpoint reference.

### Extension Compatibility

When detected, AI Shopping automatically extends the API for:

* WooCommerce Subscriptions
* WooCommerce Product Bundles
* WooCommerce Composite Products
* WooCommerce Product Add-Ons
* WooCommerce Memberships
* WooCommerce Bookings
* WooCommerce Mix and Match Products
* WooCommerce Points and Rewards
* WooCommerce Gift Cards
* WooCommerce Stripe Gateway
* WooCommerce PayPal Payments
* WPML WooCommerce Multilingual
* Advanced Custom Fields (ACF)
* YITH WooCommerce Wishlist
* WooCommerce Dynamic Pricing
* All Products for WooCommerce Subscriptions

### 3rd Party Resources

* [WooCommerce](https://wordpress.org/plugins/woocommerce/) from [Automattic](https://automattic.com/).
* [ACP Spec](https://agenticcommerce.dev) from OpenAI and Stripe.
* [UCP Spec](https://ucp.dev) from Shopify and Google.
* [MCP Spec](https://spec.modelcontextprotocol.io) from Anthropic.

### Contribution

All contributions welcome. Please open an issue or pull request on the [GitHub repository](https://github.com/twoelevenjay/ai-shopping).

## Installation

1. Extract the .zip file for this plugin and upload its contents to the `/wp-content/plugins/` directory. Alternatively, you can install directly from the Plugin directory within your WordPress Install.
1. Activate the plugin through the "Plugins" menu in WordPress.
1. Go to **AI Shopping** in the admin menu and generate an API key from the **API Keys** tab.
1. Use the Consumer Secret as a Bearer token in your AI agent's requests: `Authorization: Bearer {consumer_secret}`.

## Frequently Asked Questions

**Q: Do I need to configure anything?**

**A: Just generate an API key. All endpoints are available immediately after activation.**

**Q: Which AI agents work with this plugin?**

**A: Any AI agent that can make HTTP requests. The plugin supports ACP (OpenAI/Stripe), UCP (Shopify/Google), and MCP (Anthropic).**

**Q: Is HTTPS required?**

**A: In production, yes. For local development, you can enable HTTP access in settings.**

**Q: Does this plugin process payments?**

**A: The plugin uses WooCommerce's payment gateway infrastructure. It does not process payments directly but passes payment tokens to your configured payment gateways.**

FAQ's usually end up in the [github wiki](https://github.com/twoelevenjay/ai-shopping/wiki).
