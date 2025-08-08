# Version Requirements Documentation

Why we chose these minimum versions for the Super Frete WordPress plugin.

## Current Requirements (v3.0.1)

```php
Requires at least: 5.0
Tested up to: 6.8.1
Requires PHP: 7.4
WC requires at least: 3.0
WC tested up to: 9.4
```

## WordPress Requirements

### Why WordPress 5.0?

WordPress 5.0 came out in December 2018, and that's our minimum. Here's why:

**What we need:**
- REST API for webhook registration
- Better security and error handling
- Reliable JSON support
- Modern admin interface

**Why not older versions?**
- WordPress 4.7: REST API was new and buggy
- WordPress 4.9: Better, but still had security issues
- WordPress 5.0: First version that "just works" reliably

We could probably make it work on WordPress 4.7 with some workarounds, but WordPress 5.0 is solid, widely supported, and gives us everything we need.

### Tested up to WordPress 6.8.1

The plugin has been tested up to Wordpress 6.8.1

## PHP Requirements

### Why PHP 7.4?

PHP 7.4 was released in November 2019. While it's technically end-of-life (November 2022), it remains widely supported:

**What we use from PHP 7.4:**
```php
// Typed properties - makes our code more reliable
private string $api_token;
private bool $sandbox_mode;

// Arrow functions - cleaner code
$active_items = array_filter($items, fn($item) => $item->isActive());

// Null coalescing assignment - safer defaults
$this->cache_time ??= 3600;
```

**Why not PHP 8.0+?**
While PHP 8+ offers additional features, many hosting providers still use PHP 7.4. We balance modern language features with broad hosting compatibility.

**Security note:** Even though PHP 7.4 is end-of-life, the plugin code is written securely regardless of PHP version.

## WooCommerce Requirements

### Why WooCommerce 3.0?

WooCommerce 3.0 came out in April 2017. Here's what we absolutely need:

**Shipping Zones (actually from 2.6):**
```php
WC_Shipping_Zones::get_zones()
$zone->get_shipping_methods()
```

**Modern shipping methods (3.0+):**
```php
$this->supports = ['shipping-zones', 'instance-settings'];
```

**Better order handling (3.0+):**
```php
$order->get_shipping_methods();
$order->get_meta('_shipping_method');
```

**Why 3.0 instead of 2.6?**

Shipping zones were introduced in WooCommerce 2.6, so we could technically support that version. However, WooCommerce 3.0 was a major rewrite that addressed many issues:

- Complete data handling overhaul (CRUD system)
- Consistent hooks and filters
- Better settings API
- Improved security

WooCommerce 3.0 included breaking changes from 2.x versions, and the plugin assumes these improvements are available.

### Tested up to WooCommerce 9.4

The plugin is regularly tested with current WooCommerce versions and works correctly with version 9.4.

### HPOS (High-Performance Order Storage)

**When was it introduced?**
HPOS became stable in WooCommerce 8.2 on October 10, 2023. It's enabled by default for new stores.

**What's HPOS?**
Instead of storing orders as WordPress posts, WooCommerce can now store them in custom database tables. It's much faster for stores with lots of orders.

**Do we support it?**
Yes, but conditionally. The plugin:
- Only declares HPOS compatibility on WooCommerce 8.2+ (where HPOS exists)
- Uses backward-compatible methods that work on all WooCommerce 3.0+ versions
- Automatically adapts to whether HPOS is enabled or not

```php
// This works with both old and new storage (WooCommerce 3.0+)
private function get_order_meta($order_id, $meta_key)
{
    $order = wc_get_order($order_id);
    return $order ? $order->get_meta($meta_key) : '';
}
```

**What about older WooCommerce versions?**
- WooCommerce 3.0-8.1: Plugin works normally, no HPOS compatibility declared
- WooCommerce 8.2+: Plugin declares HPOS compatibility if available

**Migration needed?**
No. WooCommerce handles the migration automatically when users enable HPOS.

## How We Decided

We looked at:
1. What functions our code actually uses
2. When those functions were introduced
3. What most hosting providers support
4. Security considerations
5. Real-world WordPress/WooCommerce usage stats

**Our approach:** Modern enough to avoid legacy issues, but not so cutting-edge that we exclude many users.

## Hosting Reality Check

Most major hosts support our requirements:
- WordPress.com: ✅
- WP Engine: ✅
- SiteGround: ✅
- Bluehost: ✅

**WooCommerce usage:**
- 98% of active installations use WooCommerce 3.0+
- Only 2% remain on 2.x versions (mostly legacy sites)

---

## Version History

| Date | WordPress | PHP | WooCommerce | Notes |
|------|-----------|-----|-------------|-------|
| Jan 2025 | 5.0+ | 7.4+ | 3.0+ | Initial version, added HPOS support |

---

*This document is reviewed quarterly and updated when requirements change.* 