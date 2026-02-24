

## Changelog

### 1.0.0 02.24.2026
* Initial release.
* Core storefront API: products, cart, checkout, orders, store info.
* ACP protocol adapter (4-endpoint checkout model).
* UCP protocol adapter (merchant profile, capability negotiation, shopping service).
* MCP protocol adapter (tool manifest and execution).
* Headless cart session system with custom DB table.
* API key management with permissions (read / read-write / full).
* Rate limiting with configurable per-key and global defaults.
* Webhook dispatcher for order status events with HMAC signing.
* Extension detector for 16+ WooCommerce extensions.
* Admin settings page with protocol toggles and endpoint reference.
