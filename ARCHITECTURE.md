# ARCHITECTURE.md

Technical documentation for the WooCommerce Subscriptions Tax Retrofit plugin.

## Overview

Single-file WordPress plugin (~2300 lines of PHP) that migrates WooCommerce Subscriptions from tax-inclusive pricing (no separated VAT) to pre-tax + VAT format, while preserving the total price paid by customers.

**Use case**: A merchant crosses the VAT threshold and needs to retroactively add proper tax breakdown to existing subscriptions.

## File structure

```
wcs-tax-retrofit.php                — Main plugin file (all code)
uninstall.php                       — Database cleanup on plugin deletion
languages/
  wcs-tax-retrofit.pot              — Translation template (~290 strings)
  wcs-tax-retrofit-fr_FR.po         — French translation
  wcs-tax-retrofit-en_US.po         — English translation
```

## Constants

```php
WC_TAX_RETROFIT_VERSION     = '1.4.4'
WC_TAX_RETROFIT_BATCH_SIZE  = 100       // Subscriptions per batch
WC_TAX_RETROFIT_TOLERANCE   = 0.01      // Max rounding difference (1 cent)
```

## Database options (wp_options)

| Option | Type | Description |
|--------|------|-------------|
| `wc_tax_retrofit_selected_tax_rate_id` | int | Selected WooCommerce tax rate ID |
| `wc_tax_retrofit_date_limit` | string (Y-m-d) | Cutoff date for subscription filtering |
| `wc_tax_retrofit_statuses` | array | Subscription statuses to process |
| `wc_tax_retrofit_tolerance_setting` | float | Rounding tolerance in currency units |
| `wc_tax_retrofit_executed` | bool | Whether migration has completed |
| `wc_tax_retrofit_current_offset` | int | Resume position after interruption |
| `wc_tax_retrofit_last_activity` | int (timestamp) | Last batch processing time |
| `wc_tax_retrofit_count` | int | Total number of migrated subscriptions |
| `wc_tax_retrofit_partial_count` | int | Intermediate counter for multi-batch runs |
| `wc_tax_retrofit_date` | string | Migration execution date |

## Configuration priority

Consistent across all 3 main getters: the DB option always takes priority; the filter is only a fallback.

```
DB option  →  (auto-detection for date)  →  PHP filter (fallback)  →  constant/default
```

- `get_selected_rate()`: DB `selected_tax_rate_id` → filter `wc_tax_retrofit_rate` → 0.20
- `get_date_limit()`: DB `date_limit` → auto-detection → filter `wc_tax_retrofit_date_limit` → `current_time('Y-m-d')`
- `get_tolerance()`: DB `tolerance_setting` → filter `wc_tax_retrofit_tolerance` → constant `WC_TAX_RETROFIT_TOLERANCE`

## Core processing flow

### `wc_tax_retrofit_process($dry_run = false, $offset = 0) : array`

Central engine used by both the web interface and CLI.

```
1. Check dependencies (WooCommerce + Subscriptions)
2. Verify permissions (manage_woocommerce) + nonce (skipped for WP-CLI)
3. Auto-resume from saved offset (real migration only, not dry-run)
4. Acquire transient lock (5 min TTL, prevents concurrent execution)
5. Look up the WooCommerce tax_rate_id
6. Query wcs_get_subscriptions() with LIMIT/OFFSET
7. For each subscription:
   a. Validate (valid WC_Subscription, total > 0, items > 0)
   b. Skip if already migrated (existing tax > 0.01)
   c. For each line item:
      - Compute pre-tax = total / (1 + rate)
      - Compute tax = total - pre-tax
      - Check per-item tolerance → skip entire subscription if exceeded
   d. If changes exist and not dry_run:
      - START TRANSACTION
      - Remove old items
      - Create new items with set_subtotal(pre-tax), set_total(pre-tax), set_taxes({tax_rate_id: tax})
      - Copy meta_data
      - update_taxes() + calculate_totals() + save()
      - Check total tolerance → ROLLBACK if exceeded, otherwise COMMIT
      - Add order note to the subscription
8. Save offset for resume if has_more (real migration only)
9. Store CSV data in a transient (per user_id, 24h TTL)
10. do_action('wc_tax_retrofit_after_process', $stats, $dry_run)
```

### Returned `$stats` array

```php
[
    'total'               => int|null,  // Total count (computed only when offset=0)
    'batch_size'          => int,       // Number in this batch
    'offset'              => int,       // Current offset
    'updated'             => int,       // Migrated in this batch
    'skipped'             => int,       // Skipped (already migrated, tolerance, validation)
    'errors'              => int,       // Errors in this batch
    'has_more'            => bool,      // More batches to process
    'details'             => string[],  // Per-subscription detail messages
    'errors_list'         => string[],  // Error messages
    'tolerance_warnings'  => string[],  // Rounding difference alerts
    'csv_data'            => array[],   // Data for CSV export
]
```

## Function organization

### Validation

| Function | Purpose |
|----------|---------|
| `validate_tolerance($tolerance, $default)` | Float between 0.001 and 1.0 |
| `validate_statuses($statuses, $default)` | Array of valid WooCommerce Subscriptions statuses |
| `validate_date($date)` | Strict Y-m-d format + real calendar date |
| `validate_float($value, $min, $max)` | Float within a range |
| `validate_subscription($subscription)` | Valid WC_Subscription with total > 0 and items > 0 |

### Configuration (getters with static cache)

| Function | Returns | Cache |
|----------|---------|-------|
| `get_selected_rate()` | float (0.20) | `static $cached` |
| `get_selected_rate_percent()` | float (20.0) | via `get_selected_rate()` |
| `get_date_limit()` | string (Y-m-d) | `static $cached` |
| `get_tolerance()` | float (0.01) | `static $cached` |
| `get_subscription_statuses()` | array | `static $cached` |
| `get_all_tax_rates()` | array | none |
| `get_tax_rate_id()` | int\|null | `static $cached` + `$cached_resolved` |
| `count_subscriptions()` | int | `static $cached` |

### Automatic date detection

| Function | Purpose | Cache |
|----------|---------|-------|
| `detect_no_tax_date($order)` | Scans 50 subscriptions for one without tax | `static $cache` + 5 min transient |
| `detect_last_no_tax_date()` | Wrapper (DESC order) | via `detect_no_tax_date` |
| `detect_first_no_tax_date()` | Wrapper (ASC order) | via `detect_no_tax_date` |

### Tax rate lookup: `get_tax_rate_id()`

Searches `{prefix}_woocommerce_tax_rates` in 4 steps:

1. Match FR + standard class + `ABS(tax_rate - target) < 0.01`
2. All countries + standard class + same tolerance
3. WooCommerce API `WC_Tax::find_rates()` (may fail if called too early)
4. Debug: log all available rates

Result is cached with a `static` variable and a `$cached_resolved` flag to handle the `null` case.

### Web interface

| Function | ~Lines | Purpose |
|----------|--------|---------|
| `admin_page()` | ~700 | Full admin page: config, simulation, migration, reset |
| `admin_menu()` | 8 | Registers the WooCommerce submenu |
| `display_results($stats, $dry_run)` | ~130 | Renders results with progress bar and auto-continue |
| `export_csv()` | ~50 | Generates CSV download (hooked on admin_init) |
| `require_capability()` | 8 | Checks `manage_woocommerce` |
| `activation_notice()` | ~25 | Activation notice |

### Admin actions (inside `admin_page()`)

The web interface handles 6 distinct POST actions, each with its own nonce:

- `save_date` — Save tax rate + cutoff date
- `save_settings` — Save statuses + tolerance
- `full_reset` — Full migration reset
- `clear_csv` — Delete cached CSV data
- `dry_run` — Run simulation
- `confirm_update` — Run real migration

### Batch auto-continuation

When `has_more` is true, `display_results()` generates a hidden form with a JavaScript countdown (3 seconds) that automatically submits the next batch. This allows processing hundreds of subscriptions without manual intervention.

## WP-CLI

### Class `WC_Tax_Retrofit_CLI_Command`

Registered as `wp tax-retrofit` via `WP_CLI::add_command()`.

| Command | Purpose |
|---------|---------|
| `config` | Display or modify configuration (--tax-rate, --date-limit, --tolerance, --statuses) |
| `simulate` | Loop through all batches in dry-run mode. Supports --json, temporary --tax-rate/--date-limit/--tolerance |
| `migrate` | Loop through all batches in real mode. Requires --yes-i-have-a-backup. Interactive confirmation unless --skip-confirm |
| `stats` | Display offset, last activity, status (running/stopped) |
| `reset` | Delete all migration options (identical to the web reset) |

**Key differences between CLI and Web**:

- CLI loops internally (`do { ... } while ($stats['has_more'])`) instead of using the JavaScript auto-refresh
- CLI bypasses nonce verification (`defined('WP_CLI') && WP_CLI`)
- `simulate` saves/restores DB options for temporary --tax-rate, --date-limit, and --tolerance overrides

## Security

### Systematic checks

1. **Capability**: `current_user_can('manage_woocommerce')` on all actions
2. **Nonce**: one distinct nonce per action (6 different nonces)
3. **Sanitization**: `sanitize_text_field()`, `intval()`, `floatval()` on all inputs; `array_map('sanitize_text_field', ...)` on array inputs
4. **SQL**: `$wpdb->prepare()` on every query, no direct interpolation
5. **Output**: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()` as appropriate
6. **ABSPATH check**: `if (!defined('ABSPATH')) exit;` at line 17

### Concurrency lock

Transient `wc_tax_retrofit_running` with a 5-minute TTL. If processing is already running, `process()` returns a full `$stats` array with `'locked' => true` and all counters set to 0.

## Data integrity

### SQL transactions

Each modified subscription is wrapped in `START TRANSACTION` / `COMMIT` / `ROLLBACK`. Rollback triggers if:

- The total price difference after migration >= tolerance
- A `\Throwable` is thrown during item manipulation (covers `Exception`, `TypeError`, `Error`)

### Double tolerance check

1. **Per item**: `abs((pre_tax + tax) - original_total) >= tolerance` → skip the entire subscription (`continue 2`)
2. **Per subscription** (after save): `abs(new_total - original_total) >= tolerance` → ROLLBACK

### Resume after interruption

- After each real migration batch, the offset is saved to `wc_tax_retrofit_current_offset`
- On restart, if `$offset === 0` and a saved offset exists, processing resumes automatically
- `!$dry_run` guard: simulation always starts at offset 0

## Available hooks

### Filters

```php
// Override the tax rate (decimal, e.g. 0.055 for 5.5%)
add_filter('wc_tax_retrofit_rate', fn($rate) => 0.20);

// Override the cutoff date
add_filter('wc_tax_retrofit_date_limit', fn($date) => '2024-01-15');

// Override the rounding tolerance
add_filter('wc_tax_retrofit_tolerance', fn($t) => 0.02);

// Override which subscription statuses to process
add_filter('wc_tax_retrofit_subscription_statuses', fn($s) => ['active', 'on-hold']);
```

### Action

```php
// Intercept results after each batch
add_action('wc_tax_retrofit_after_process', function($stats, $dry_run) {
    // $stats contains updated, skipped, errors, tolerance_warnings, csv_data...
}, 10, 2);
```

## Plugin lifecycle

### Activation (`register_activation_hook`)

- Sets transient `wc_tax_retrofit_activation_notice` (60s TTL)
- Logs "Plugin activated"

### Deactivation (`register_deactivation_hook`)

- Deletes the concurrency lock (`wc_tax_retrofit_running`)
- Deletes date detection transients (`detect_date_desc`, `detect_date_asc`)
- Deletes intermediate data (`partial_count`, `current_offset`, `last_activity`)
- Deletes the activation notice
- Cleans all CSV transients for all users (direct SQL query)

### Uninstall (`uninstall.php`)

- Deletes ALL `wc_tax_retrofit_*` options (10 options)
- Deletes all `wc_tax_retrofit_*` transients (lock, activation notice, date detection)
- Deletes all per-user CSV transients (SQL LIKE query)

## Dependencies and loading

```
PHP file loads
  └─ ABSPATH check (exit if missing)
  └─ wc_tax_retrofit_check_dependencies_early()
       ├─ PHP >= 7.4 ?
       ├─ class_exists('WooCommerce') ?
       └─ function_exists('wcs_get_subscriptions') ?
  └─ If errors: admin_notices + return (STOP)
  └─ Otherwise: define(), functions, hooks, CLI
```

The `return` at line 102 prevents the rest of the file from loading when dependencies are missing. This is a standard WordPress pattern to avoid fatal errors.

## Internationalization

- Text domain: `wcs-tax-retrofit`
- Loaded via `plugins_loaded` hook → `load_plugin_textdomain()`
- ~290 translatable strings
- Any change to `__()` strings must be reflected in all 3 .po/.pot files
- Compiled `.mo` files are in `.gitignore`
