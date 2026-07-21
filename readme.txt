=== Dianxiaomi to PayPal Package Tracking Bridge ===
Contributors: jaynchou
Tags: woocommerce, paypal, tracking, dianxiaomi, hpos
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Sends Dianxiaomi shipment data through WooCommerce PayPal Payments' native Package Tracking service.

== Description ==

This bridge watches WooCommerce orders for tracking data supplied by Dianxiaomi and calls the native order-tracking function provided by WooCommerce PayPal Payments.

It does not store separate PayPal credentials and does not call PayPal with a second API client. It supports HPOS, Action Scheduler, order-note parsing, standard WooCommerce Shipment Tracking metadata, carrier matching, duplicate protection, manual diagnostics, historical batch scans, order-list status/actions, and retries.

The bridge does not modify customer email hooks. PayPal-side behavior remains controlled by WooCommerce PayPal Payments and PayPal.

== Requirements ==

* WooCommerce
* WooCommerce PayPal Payments 3.1.0 or newer (verified against 4.1.1)
* A captured order with `_ppcp_paypal_order_id` and a transaction/Capture ID

== Installation ==

1. Deactivate older PayPal tracking sync plugins to avoid duplicate submissions.
2. Upload and activate this plugin.
3. Open WooCommerce > PayPal Tracking Bridge.
4. Diagnose one real order and perform one manual test before enabling site-wide automatic use.

See INSTALL-ZH.md for detailed Chinese instructions.

== Data sources ==

Default Dianxiaomi metadata:

* `_dianxiaomi_tracking_number`
* `_dianxiaomi_tracking_provider`
* `_dianxiaomi_tracking_provider_name`

The bridge also reads `_wc_shipment_tracking_items` and recognized Dianxiaomi shipment notes.

== Changelog ==

= 1.2.0 =
* Exclude Processing orders from automatic, manual, selected-order, and historical synchronization.
* Replace the latest-20 scan with 7, 15, and 30-day shipping windows based on WooCommerce date completed; retain an all-history Completed-only option.
* Only captured WooCommerce PayPal Payments orders with detected tracking data are queued.
* Keep the current HPOS/legacy order-list page, search, status, sorting, and filters after a per-order sync action.

= 1.1.0 =
* Added background scans for the latest 20 or all historical Processing/Completed orders.
* Added HPOS and legacy order-list status columns, per-order sync actions, and list-table bulk actions.
* Added a persistent order-detail metabox with shipment status, errors, and an immediate manual-sync button.
* Historical scans skip ineligible, missing-tracking, already queued, and already synchronized orders.

= 1.0.2 =
* Added a persistent Chinese success/failure banner and localized last-success time on the diagnostics page.
* Keep YunExpress as OTHER for non-China merchant carrier lists, matching PayPal Payments' native dropdown.

= 1.0.1 =
* Detect shipment numbers in customer and internal order notes created before the bridge was activated.
* Added an explicit YunExpress carrier mapping.

= 1.0.0 =
* Initial native bridge for WooCommerce PayPal Payments Package Tracking.
* Verified with WooCommerce PayPal Payments 4.1.1.
* Added HPOS support, carrier resolution, idempotency, retries, diagnostics, and a guard for first-sync scalar tracking metadata in PayPal Payments 4.1.1.
