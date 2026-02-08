<?php
/**
 * WooCommerce Subscription Tax Retrofit - Uninstall
 *
 * Supprime toutes les options et transients du plugin lors de la suppression.
 * Ce fichier est appelé automatiquement par WordPress quand l'utilisateur
 * supprime le plugin via l'interface admin.
 *
 * @package WC_Subscriptions_Tax_Retrofit
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Options de configuration
delete_option('wc_tax_retrofit_selected_tax_rate_id');
delete_option('wc_tax_retrofit_date_limit');
delete_option('wc_tax_retrofit_statuses');
delete_option('wc_tax_retrofit_tolerance_setting');

// Options d'état de migration
delete_option('wc_tax_retrofit_executed');
delete_option('wc_tax_retrofit_count');
delete_option('wc_tax_retrofit_date');
delete_option('wc_tax_retrofit_partial_count');
delete_option('wc_tax_retrofit_current_offset');
delete_option('wc_tax_retrofit_last_activity');

// Transients
delete_transient('wc_tax_retrofit_running');
delete_transient('wc_tax_retrofit_activation_notice');

// Transients CSV de tous les utilisateurs
global $wpdb;
$like_csv = $wpdb->esc_like('_transient_wc_tax_retrofit_csv_') . '%';
$like_timeout = $wpdb->esc_like('_transient_timeout_wc_tax_retrofit_csv_') . '%';
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_csv));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout));
