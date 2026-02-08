# Changelog

All notable changes to this project are documented in this file.

## [1.4.2] - 2026-02-08

### Consistency (P2)
- **Systematic escaping in `display_results()`**: `$mode_color`, `$percent`, `$processed`, `$stats['total']`, `$stats['batch_size']`, `$stats['offset']`, `$stats['updated']`, `$stats['skipped']`, `$stats['errors']`, `$remaining`, `$eta_minutes`, `$eta_sec_remain` and `$next` were interpolated directly in HTML output without `intval()` or `esc_attr()`. All internal values with no user input path, but inconsistent with the rest of the plugin which escapes systematically. Fixed with `intval()` for integers and `esc_attr()` for attributes
- **Unescaped `$style` in tax rate debug table**: hardcoded CSS string output without `esc_attr()` in `admin_page()`. Fixed
- **Unescaped `$diff` in tax rate debug table**: float difference output without `esc_html()` while all other values in the same table row were escaped. Fixed with `esc_html(number_format(...))`
- **Loose comparison `==` for rate selection**: `$current_rate_id == $rate_id` used loose comparison where `null == 0` evaluates to `true`. Replaced with `(int) === (int)` strict comparison
- **Redundant tolerance validation**: `validate_tolerance()` and a manual bounds check were both applied on the same input. Simplified to a single `validate_float()` call
- **Translation file version headers**: `.pot`, `fr_FR.po` and `en_US.po` still referenced version 1.4.0 instead of current version

### Documentation (P3)
- **Translatable string count**: README.md and ARCHITECTURE.md claimed "~130 translatable strings" while the POT file contains 287 entries. Updated to "~290"

## [1.4.1] - 2026-02-08

### Robustness (P1)
- **`catch (Exception)` → `catch (\Throwable)`**: both try/catch blocks in `process()` (per-subscription transaction + outer loop) only caught `Exception`. In PHP 7+, `TypeError` and `Error` are not subclasses of `Exception` — a WooCommerce `TypeError` crash would leave the transaction open without ROLLBACK. Fixed with `\Throwable`
- **Float comparison `== 0` in `detect_no_tax_date()`**: `array_sum($taxes['total']) == 0` could produce a false positive for values very close to 0 (e.g. 0.0001). Replaced with `abs(array_sum($taxes['total'])) < 0.001`

### Performance (P2)
- **Transient cache for `detect_no_tax_date()`**: automatic date detection (N+1 queries across 50 subscriptions) ran on every admin page load when no date was configured. Result now cached in a 5-minute transient (`wc_tax_retrofit_detect_date_desc` / `_asc`)
- **Static cache for `get_tax_rate_id()`**: tax rate lookup (up to 4 DB queries) was not cached, unlike other getters. Added `static` cache with dual variables (`$cached` + `$cached_resolved`) to support the `null` case
- **Static cache for `count_subscriptions()`**: total count (`wcs_get_subscriptions` with `limit => -1`) was not cached. Added `static` cache and deduplicated the inline code in `process()` that replicated the same logic

### Consistency (P3)
- **`$wpdb->prepare()` on debug query step 4**: the only SQL query in the plugin not using `prepare()` (available rates listing in `get_tax_rate_id()`). Fixed with `LIMIT %d`
- **Sanitize `$_POST['statuses']`**: status checkboxes input was not passed through `sanitize_text_field()` before `validate_statuses()`. Near-zero risk (whitelist downstream) but inconsistent with other inputs
- **Cleanup `detect_date_*` transients**: both new transients are now deleted in `deactivate()` and `uninstall.php`

### Documentation (P3)
- **README.md**: 3 occurrences of the old name `woocommerce-subscription-tax-retrofit` corrected to `wcs-tax-retrofit` (renamed since v1.3.5)

## [1.4.0] - 2026-02-08

### Full internationalization (P1)
- **~70 untranslatable UI strings**: most admin interface texts (`admin_page()`, `display_results()`, `ajax_count()`, CSV headers) were raw French without `__()` calls. All strings now wrapped in `__()`, `esc_html__()` or `esc_js()` as appropriate. Translation files grew from ~62 to ~130 entries
- **Regenerated .po/.pot files**: all 3 translation files rewritten with new entries and updated versions

### Fixes (P2)
- **Missing `batch_size` and `csv_data`**: the `$stats` array returned on dependency errors in `process()` lacked `batch_size`, `offset` and `csv_data` keys, causing `undefined index` in calling code
- **`get_all_tax_rates()` without `$wpdb->prepare()`**: SQL query used direct interpolation instead of `$wpdb->prepare()`, inconsistent with the rest of the plugin. Fixed with parameterized `LIMIT %d`

## [1.3.9] - 2026-02-08

### Fixes (P1)
- **Undefined `$target` variable**: in `admin_page()`, when no FR rate existed in DB (`$debug_rates` empty), `$target` was used without being initialized in the configuration instructions, causing a PHP Warning. Moved before the conditional block
- **CLI `simulate --json` incomplete multi-batch**: only `details`, `csv_data` and `errors_list` from the last batch were included in JSON output. All three arrays now accumulated across batches, like `tolerance_warnings` (fixed in v1.3.4)

### Inconsistencies fixed (P2)
- **Filter priority harmonized**: `get_date_limit()` applied `apply_filters()` even when a DB value existed, unlike `get_selected_rate()` and `get_tolerance()` which only use the filter as fallback. All three getters now follow the same logic: DB → filter fallback
- **CLI `simulate --date-limit` and `--tolerance`**: these temporary options used `add_filter()` which was ignored when a DB value existed (DB > filter priority). Migrated to the save/restore DB pattern already used by `--tax-rate` since v1.3.1
- **Hardcoded date fallback**: the `'2024-11-29'` fallback in `get_date_limit()` (specific to one use case) replaced with `current_time('Y-m-d')` (today's date)
- **`locked` return from `process()`**: the array returned when processing was already running only contained `locked` and `message`, without expected keys (`updated`, `errors`, `has_more`, etc.). Added all standard `$stats` keys to prevent undefined index
- **CSV transient TTL**: temporary CSV data TTL increased from 1 hour to 24 hours, preventing data loss when interrupted migrations are resumed after >1h

### Performance (P3)
- **Static cache for `get_date_limit()`**: function was called ~8 times on admin page without cache. Each call reads `get_option()`, validates the date, and may trigger automatic detection. A `static` cache eliminates redundant calls
- **Static cache for `get_tolerance()`**: ~6 uncached calls on admin page
- **Static cache for `get_subscription_statuses()`**: ~5 uncached calls on admin page

### Cleanup (P3)
- **SQL with `$wpdb->prepare()`**: DELETE queries in deactivation hook and `uninstall.php` used raw SQL. Migrated to `esc_like()` + `prepare()` for consistency

## [1.3.8] - 2026-02-07

### Rename
- **Text domain renamed**: `wc-tax-retrofit` → `wcs-tax-retrofit` in plugin header, `load_plugin_textdomain()`, 60+ `__()` calls, log source, and 3 admin HTML references. Language files renamed accordingly (`wcs-tax-retrofit.pot`, `wcs-tax-retrofit-fr_FR.po`, `wcs-tax-retrofit-en_US.po`)

### Fix (P1)
- **Simulation after interrupted migration**: `wc_tax_retrofit_process()` automatically resumed at the saved offset even in simulation mode (`$dry_run = true`). A simulation launched after an interrupted migration would start at the wrong offset. Added `!$dry_run` guard to the resume condition

### Inconsistencies fixed (P2)
- **3x "before" → "up to"**: the `date_created` filter uses `<=` (inclusive) since v1.3.7, but 3 UI texts still said "before" instead of "up to" (affected subscriptions section, recalculation, and actions)
- **CSV filename**: exported file was named `wc-subscription-tax-retrofit-*.csv` (old naming without 's'). Renamed to `wcs-tax-retrofit-*.csv` for consistency with the text domain
- **README "~20 entries"**: translations section stated ~20 translatable strings while .po files contained 60
- **Missing PO string**: "Starting migration..." (CLI migrate) was not in .po files — old files incorrectly pointed to "Starting simulation..." for both references. Added with English translation

### Cleanup (P3)
- **PHPDoc added**: `get_subscription_statuses()`, `get_tolerance()` and `get_date_limit()` lacked `@return` docblocks, unlike other public functions

## [1.3.7] - 2026-02-07

### Fixes (P1)
- **Inclusive `date_created` filter**: code used `<` (strictly before) while UI indicated "before or on". Replaced with `<=` in all 3 locations (`count_subscriptions`, `process` batch, `process` total count) to include subscriptions created on the cutoff date itself
- **Duplicate success/error message**: when user unchecked all statuses in advanced settings, both error AND success messages displayed simultaneously. A `$settings_has_error` flag now conditions success display
- **`--skip-confirm` documented**: CLI `migrate --skip-confirm` option (for scripts/cron) was handled in code but absent from WP-CLI docblock. `wp tax-retrofit migrate --help` now displays it

### Improvements (P2)
- **`uninstall.php` added**: plugin deletion via admin now cleans all options and transients from the database. Previously, 7 config and state options remained orphaned
- **Dead code removed**: 3 `wp_die()` calls after `wp_send_json_success/error()` in `ajax_count()` were unreachable (those functions already call `wp_die()` internally)
- **Static cache for `get_selected_rate()`**: function made a SQL query (`$wpdb->get_var`) on each call. On admin page (~5 calls), this generated 5 identical queries. A `static` cache eliminates redundant calls

### Cleanup (P3)
- **`$meta->is_unique` removed**: `WC_Meta_Data` has no `is_unique` property; `$meta->is_unique ?? false` always evaluated to `false`. Replaced with `false` directly
- **`$stats` docblock reduced**: 65-line inline comment describing `$stats` structure in `process()` replaced with a 5-line summary
- **README hooks updated**: "3 PHP hooks" replaced with "5 PHP hooks (4 filters + 1 action)" with documentation of 2 missing hooks (`wc_tax_retrofit_subscription_statuses` and `wc_tax_retrofit_after_process`)

### Distribution
- **`uninstall.php` created**: full cleanup on plugin deletion
- **`LICENSE` file added**: complete GPL v2 license for GitHub distribution
- **`.gitignore` added**: excludes system files, IDE files and compiled `.mo` files
- **README file structure updated**: added `uninstall.php`, `CHANGELOG.md` and `LICENSE`

## [1.3.6] - 2026-02-07

### Internationalization
- **Language files regenerated**: all 3 files (POT, fr_FR.po, en_US.po) were frozen at v1.2.9 with old filename, obsolete line numbers, 8 missing strings and 3 removed strings. Fully regenerated with 60 strings (vs 50 before)
- **8 strings added**: `Tax rate not found...`, `Last activity: %s ago`, `No valid status provided...`, `Rate %s%% not found... using configured rate`, `Batch offset %d: %d to migrate...`, `To migrate`, `Batch offset %d: %d updated...`, `Migration fully reset`
- **3 obsolete strings removed**: `There are subscriptions left to process.`, `Run again with: wp tax-retrofit migrate...`, `Migration state reset`
- **References updated**: filename `wc-subscriptions-tax-retrofit.php`, version 1.3.6, date 2026-02-07
- **English translation fixed**: `Type "OUI"` instead of `Type "YES"` (code checks `$confirmation !== 'OUI'` regardless of locale)

## [1.3.5] - 2026-02-07

### Fix (P1)
- **`wc_tax_retrofit_tolerance` filter restored**: `get_tolerance()` no longer called `apply_filters()` since v1.3.2 (removed from `define()` but never reintroduced in the function). The filter documented in README, mentioned in admin UI, and used by CLI `simulate --tolerance` was silently ignored. Added as priority 2 (DB > filter > constant), consistent with `get_date_limit()` and `get_selected_rate()`

### Cleanup (P3)
- **Reused `$target` cache (technical info)**: `get_selected_rate_percent()` was still called in the "Tax Rate ID not found" technical info block while `$target` was already in scope (missed in v1.3.4 fix #4)
- **Duplicate `$saved_date` removed**: `get_option('wc_tax_retrofit_date_limit')` was called twice within 9 lines in the same admin page scope

### Rename
- **Main file renamed**: `woocommerce-subscription-tax-retrofit.php` → `wc-subscriptions-tax-retrofit.php`

## [1.3.4] - 2026-02-07

### Description
- **Plugin header rewritten**: long description with HTML link (not rendered in plugin list or `wp plugin list`) replaced with a concise, readable description

### Fix (P1)
- **CLI `simulate` tolerance warnings multi-batch**: only warnings from the last batch were checked after the loop. `tolerance_warnings` now accumulated across all batches (like `updated`, `skipped`, `errors`). Also fixed in JSON output

### Inconsistencies fixed (P2)
- **`wc_tax_retrofit_batch_size` filter reference removed**: admin technical info displayed `(filter wc_tax_retrofit_batch_size)` while this filter was removed in v1.3.3
- **`--batch-size` option removed from CLI `migrate` docblock**: option was documented but never implemented in code
- **Reused `$target` cache**: `get_selected_rate_percent()` was called 2 times in the "rate not found" error block while `$target` was already computed in the same scope

### Cleanup (P3)
- **`reset_migration` handler removed**: dead code — no UI form submitted this action (replaced by `full_reset` since v1.3.0). The handler was also incomplete compared to `full_reset` (did not delete `current_offset` or `last_activity`)

## [1.3.3] - 2026-02-07

### Inconsistencies fixed
- **`apply_filters()` in `define(WC_TAX_RETROFIT_BATCH_SIZE)`**: same timing issue as `TOLERANCE` and `RATE` fixed in v1.3.1/1.3.2 — filters are not yet registered at `define()` time. The constant now uses its default value directly
- **Stale comment**: `// Priority 2: Constant (defined via filter at load)` in `get_tolerance()` no longer reflected reality since filter removal in v1.3.2
- **README version**: header showed 1.3.1 instead of current version

### Performance
- **`get_selected_rate_percent()` cached in debug loop**: function was called on each iteration of the FR rates array instead of being cached before the loop
- **"How it works" section cached**: `get_selected_rate()` was called 3 times and `get_selected_rate_percent()` once in the same HTML block. Results now cached in local variables

### PHP 7.4+ modernization (continued)
- **Null coalescing operator**: replaced 7 occurrences of `isset($x) ? $x : default` with `$x ?? default` (available since PHP 7.0)

### Cleanup
- **`WHERE 1=1` removed**: unnecessary clause in `get_all_tax_rates()` SQL query (no dynamic conditions concatenated)

## [1.3.2] - 2026-02-07

### PHP 7.4+ modernization
- **Return type hints**: added explicit return types on 24 functions (`: array`, `: bool`, `: float`, `: int`, `: string`, `: void`). 6 functions returning union types (`string|false`, `string|null`, etc.) excluded as union types require PHP 8.0+
- **Arrow functions**: replaced closures `function() use ($var) { return $var; }` with `fn() => $var` in CLI `simulate` commands
- **Spread operator**: replaced `array_merge($a, $b)` with `[...$a, ...$b]` for CSV data merging (more efficient on numerically-keyed arrays)
- **Redundant PHP check removed**: `version_compare(PHP_VERSION, '7.4', '<')` in `wc_tax_retrofit_check_dependencies()` removed since WordPress `Requires PHP: 7.4` header and early check already prevent execution on PHP < 7.4. Function simplified to a single expression
- **`apply_filters()` removed from `define(WC_TAX_RETROFIT_TOLERANCE)`**: filters are not yet registered at `define()` time, constant now uses its default value directly

## [1.3.1] - 2026-02-07

### Fixes (P1)
- **CLI `simulate` multi-batch**: `wp tax-retrofit simulate` only processed the first batch of 100 subscriptions. Now loops through all batches like `migrate`
- **CLI `simulate --tax-rate` functional**: `--tax-rate` parameter was silently ignored when a rate was already saved in DB (DB had priority over filter). Temporary rate now applied via a DB option restored after simulation
- **CLI `reset` complete**: CLI command did not delete `wc_tax_retrofit_executed`, `wc_tax_retrofit_date` or `wc_tax_retrofit_count`, unlike the web reset. Both are now identical
- **CLI `config --tax-rate` approximate match**: rate lookup in DB used exact match (`WHERE tax_rate = %s`) while the rest of the plugin uses `ABS(tax_rate - %f) < 0.01`. Harmonized
- **Deprecated `current_time('timestamp')`**: replaced with `time()` in CLI `stats` command (deprecated since WordPress 5.3)

### Improvements (P2)
- **Debug query moved**: SQL debug query listing FR rates no longer runs systematically on admin page, only when rate is not found
- **`wc_tax_retrofit_get_tax_rate_id()` cached**: first call result on admin page reused in technical info (saves a heavy call with up to 4 SQL queries)
- **Static cache for date detection**: `wc_tax_retrofit_detect_no_tax_date()` now uses a static cache to avoid redundant calls within the same request

### Cleanup (P3)
- **`WC_TAX_RETROFIT_RATE` removed**: unused constant that used `apply_filters()` in `define()` (filters not yet registered at load time)
- **`WC_TAX_RETROFIT_TEXT_DOMAIN` removed**: constant defined but never used (text domain is hardcoded in all `__()` calls)
- **`WC_TAX_RETROFIT_TOLERANCE` filter removed**: constant keeps its default value without `apply_filters()` (same timing issue as `WC_TAX_RETROFIT_RATE`)
- **`$show_detection_block` removed**: variable assigned but never read (dead code)
- **Date detection factored**: `detect_first_no_tax_date()` and `detect_last_no_tax_date()` delegate to a single `detect_no_tax_date($order)` function instead of duplicating 30 lines
- **`validate_tolerance()` factored**: delegates to `validate_float()` instead of duplicating validation logic
- **README updated**: version 1.2.9 → 1.3.1

## [1.3.0] - 2026-02-07

### Critical fixes (P0)
- **PHP Fatal Error fix**: removed duplicate declaration of `wc_tax_retrofit_validate_date()` (lines 145 and 308) which caused a crash on activation
- **`$stats` used before declaration**: offset save block in `wc_tax_retrofit_process()` referenced `$stats['batch_size']` before `$stats` was initialized, causing incorrect resume offset

### Important fixes (P1)
- **WP-CLI functional**: permission and nonce checks in `wc_tax_retrofit_process()` now skipped in CLI context (`defined('WP_CLI')`)
- **CLI tolerance option fixed**: `wp tax-retrofit config --tolerance=` wrote to the wrong key (`wc_tax_retrofit_tolerance` instead of `wc_tax_retrofit_tolerance_setting`), making CLI configuration ineffective
- **CLI migrate saves state**: `wp tax-retrofit migrate` now saves `wc_tax_retrofit_executed`, `wc_tax_retrofit_date` and `wc_tax_retrofit_count`, like the web version. CLI also processes all batches automatically instead of stopping at the first
- **CLI default statuses fixed**: fallback in `config` displayed `pending` instead of `pending-cancel`
- **CLI status validation**: statuses passed via `--statuses=` now validated with `wc_tax_retrofit_validate_statuses()` instead of being saved as-is

### Improvements (P2)
- **DB transactions**: item modifications (delete/recreate) in `wc_tax_retrofit_process()` now wrapped in SQL transactions (`START TRANSACTION` / `COMMIT` / `ROLLBACK`) to prevent corrupted subscriptions on error
- **Improved date detection**: `wc_tax_retrofit_detect_last_no_tax_date()` and `wc_tax_retrofit_detect_first_no_tax_date()` now scan up to 50 subscriptions (instead of 1), significantly increasing chances of finding a tax-free subscription
- **Loop value caching**: `wc_tax_retrofit_get_selected_rate()` and `wc_tax_retrofit_get_tolerance()` cached at the start of `wc_tax_retrofit_process()` instead of being recalculated per item (saves ~400 SQL queries per 100-subscription batch)
- **Offset saved for resume**: current offset correctly saved at end of each batch to allow resume after interruption

### Cleanup (P3)
- **HTML echo removed from process()**: `wc_tax_retrofit_process()` no longer echoes HTML when tax rate is not found; returns errors cleanly via `errors_list`
- **i18n fix**: replaced `" ago"` (English) with a translatable string via `__()` in the interrupted migration alert
- **CSV timezone fix**: replaced `date()` with `wp_date()` in exported CSV filename
- **Closing `?>` tag removed**: removed PHP closing tag at end of file to prevent "headers already sent" errors
- **CSV export performance**: reordered checks in `wc_tax_retrofit_export_csv()` so action check (`$_GET['action']`) runs first (before dependency and permission checks), avoiding expensive operations on every `admin_init` load

## [1.2.9] - 2024-12-10

### Added
- **Full WP-CLI support**: 5 commands available (`config`, `simulate`, `migrate`, `stats`, `reset`)
- Ideal for very large databases (no PHP timeout)
- Automation and CI/CD integration
- JSON output for parsing
- Complete documentation

## [1.2.8] - 2024-12-09

### Improvements
- Improved dependency notices
- Full internationalization
- More explicit error messages

## [1.2.7] - 2024-12-09

### Added
- Configurable tax rate via interface
- Universal country support

## [1.2.6] - 2024-12-09

### Improvements
- Strengthened security
- Strict data validation
- Centralized dependency checking
