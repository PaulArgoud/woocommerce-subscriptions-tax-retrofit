# WooCommerce Subscriptions Tax Retrofit

**Version:** 1.4.2
**Author:** Paul ARGOUD
**License:** GPL v2 or later
**Requires:** WordPress 5.0+, PHP 7.4+, WooCommerce, WooCommerce Subscriptions

## Description

WordPress plugin that automatically migrates WooCommerce Subscriptions stored as tax-inclusive (no separated VAT) to a pre-tax + VAT format, while preserving the total price paid by customers.

**Typical use case:** You just crossed the VAT threshold and need to display VAT on invoices for your existing subscriptions.

## Installation

### Method 1: Via the WordPress admin

1. Download `wcs-tax-retrofit.zip`
2. Go to **WordPress Admin → Plugins → Add New**
3. Click **Upload Plugin**
4. Select the ZIP file
5. Click **Install Now**
6. Activate the plugin

### Method 2: Manual installation

Download and extract into `wp-content/plugins/wcs-tax-retrofit/`

### Method 3: Via WP-CLI

```bash
wp plugin install wcs-tax-retrofit.zip --activate
```

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- WooCommerce installed and activated
- WooCommerce Subscriptions installed and activated
- (Optional) WP-CLI for command-line usage

## Usage

### Web interface (recommended)

1. **Backup**: Make a full database backup (NON-NEGOTIABLE)
2. Go to **WooCommerce → Tax Retrofit**
3. Configure:
   - Tax rate (selected from your WooCommerce tax rates)
   - Cutoff date (automatic detection available)
   - Subscription statuses to process
4. Run a **simulation** to preview the changes
5. Review the simulation results
6. If everything looks good, run the **real migration**
7. Export the CSV for your records

### WP-CLI (advanced)

```bash
# Configuration
wp tax-retrofit config --tax-rate=20 --date-limit=2024-01-01 --tolerance=0.01

# Simulation
wp tax-retrofit simulate

# Simulation with JSON output
wp tax-retrofit simulate --json > simulation.json

# Real migration (AFTER BACKUP!)
wp tax-retrofit migrate --yes-i-have-a-backup

# Progress statistics
wp tax-retrofit stats

# Reset
wp tax-retrofit reset
```

**Available WP-CLI commands:**
- `config` — Display or modify configuration
- `simulate` — Run a dry-run simulation
- `migrate` — Execute the real migration
- `stats` — Display migration statistics
- `reset` — Reset migration state

## Features

### Web interface
- Automatic migration of hundreds of subscriptions
- Mandatory simulation before execution
- Support for any tax rate (France, Belgium, Switzerland, etc.)
- Batch processing (100 subscriptions per batch)
- Automatic resume after interruption
- Configurable rounding tolerance
- Detailed WooCommerce logs
- CSV export of all modifications

### WP-CLI (since v1.2.9)
- Full command-line support
- Ideal for large databases (no PHP timeout)
- Scriptable and automatable (CI/CD, cron)
- JSON output for parsing
- 5 commands available

### Internationalization
- Multilingual interface (French, English)
- POT file included for new translations
- All strings are translatable (~290 entries)

### For developers
- 5 PHP hooks available (4 filters + 1 action)
- Commented code following WordPress standards (WPCS)
- Single PHP file (~2300 lines)

## Available hooks

The plugin provides 4 filters and 1 action for customization:

```php
// Override the tax rate
add_filter('wc_tax_retrofit_rate', function($rate) {
    return 0.20; // 20%
});

// Set a cutoff date
add_filter('wc_tax_retrofit_date_limit', function($date) {
    return '2024-01-15';
});

// Adjust the rounding tolerance
add_filter('wc_tax_retrofit_tolerance', function($tolerance) {
    return 0.02; // 2 cents
});

// Change which subscription statuses to process
add_filter('wc_tax_retrofit_subscription_statuses', function($statuses) {
    return array('active', 'on-hold'); // Default: active, pending-cancel, on-hold
});

// Intercept results after each batch
add_action('wc_tax_retrofit_after_process', function($stats, $dry_run) {
    if (!empty($stats['tolerance_warnings'])) {
        error_log('Tax Retrofit: ' . count($stats['tolerance_warnings']) . ' warnings');
    }
}, 10, 2);
```

## Translations

The plugin ships with:
- French (fr_FR) — primary language
- English (en_US) — full translation

**To create a new translation:**
1. Copy the `languages/wcs-tax-retrofit.pot` file
2. Open it with Poedit (free)
3. Create a new catalog for your language
4. Translate the strings (~290 entries)
5. Save: Poedit automatically generates the .po and .mo files
6. Place them in `languages/`

The plugin automatically loads the correct translation based on the WordPress locale.

## File structure

```
wcs-tax-retrofit/
├── wcs-tax-retrofit.php                        (Main plugin file ~2300 lines)
├── uninstall.php                               (Cleanup on deletion)
├── README.md                                   (This file)
├── CHANGELOG.md                                (Version history)
├── LICENSE                                     (GPL v2 license)
└── languages/
    ├── wcs-tax-retrofit.pot                   (Translation template)
    ├── wcs-tax-retrofit-fr_FR.po              (French translation)
    └── wcs-tax-retrofit-en_US.po              (English translation)
```

## Support

**This plugin is provided "as is", with no warranty and no technical support.**

- No support
- No warranty
- Use at your own risk
- Open source (GPL v2 license)
- You are free to modify it
- Contributions welcome

## Warning

**MAKE A FULL BACKUP BEFORE USE**

This plugin directly modifies your WooCommerce database. Although it has been extensively tested, a backup is ESSENTIAL before any migration.

## Tested with

- WordPress 5.0 through 6.4+
- PHP 7.4, 8.0, 8.1, 8.2
- WooCommerce 7.0 through 8.3+
- Over 500 subscriptions tested
- All tax rates (20%, 10%, 5.5%, etc.)
- Resume after timeout tested
- Full WP-CLI test coverage

## WP-CLI recipes

### Automated migration

```bash
#!/bin/bash
# Automated migration script

wp tax-retrofit config --tax-rate=20 --date-limit=2024-01-01
RESULT=$(wp tax-retrofit simulate --json)
ERRORS=$(echo $RESULT | jq -r '.errors')

if [ "$ERRORS" -eq 0 ]; then
    wp tax-retrofit migrate --yes-i-have-a-backup --skip-confirm
    echo "Migration completed successfully"
else
    echo "Errors detected, migration cancelled"
    exit 1
fi
```

### Scheduled migration (cron)

```bash
# Add to crontab
0 2 * * * cd /var/www/html && wp tax-retrofit migrate --yes-i-have-a-backup --skip-confirm >> /var/log/tax-retrofit.log 2>&1
```

### Batch migration (large database)

```bash
#!/bin/bash
# Batch migration with automatic resume

OFFSET=0
while true; do
    echo "Processing offset $OFFSET..."
    wp tax-retrofit migrate --yes-i-have-a-backup --skip-confirm --offset=$OFFSET

    if wp tax-retrofit stats | grep -q "has_more.*false"; then
        echo "Migration complete!"
        break
    fi

    OFFSET=$((OFFSET + 100))
    sleep 2
done
```

## License

GPL v2 or later — https://www.gnu.org/licenses/gpl-2.0.html

## Author

Paul ARGOUD
https://paul.argoud.net

## Changelog

See CHANGELOG.md for the detailed version history.
