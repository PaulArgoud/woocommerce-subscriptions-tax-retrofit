<?php
/**
 * Plugin Name: WooCommerce Subscriptions Tax Retrofit
 * Plugin URI: https://paul.argoud.net
 * Description: Retrofits existing WooCommerce Subscriptions with proper tax breakdown (pre-tax + VAT) while preserving the total price paid by customers. Essential after crossing the VAT registration threshold.
 * Version: 1.4.4
 * Author: Paul ARGOUD
 * Author URI: https://paul.argoud.net
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wcs-tax-retrofit
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

/**
 * VÉRIFICATION CENTRALISÉE DES DÉPENDANCES
 * Bloque tout le plugin si les prérequis ne sont pas remplis
 */
function wc_tax_retrofit_check_dependencies_early(): array {
    $errors = array();
    
    // Check PHP version
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        $errors[] = array(
            'message' => sprintf(
                /* translators: %s: current PHP version */
                __('PHP 7.4 ou supérieur est requis (version actuelle : %s)', 'wcs-tax-retrofit'),
                PHP_VERSION
            ),
            'action' => __('Contactez votre hébergeur pour mettre à jour PHP.', 'wcs-tax-retrofit')
        );
    }
    
    // Check WooCommerce
    if (!class_exists('WooCommerce')) {
        $errors[] = array(
            'message' => __('WooCommerce doit être installé et activé.', 'wcs-tax-retrofit'),
            'action' => sprintf(
                /* translators: %s: URL to install WooCommerce */
                __('Installez WooCommerce depuis <a href="%s">cette page</a>.', 'wcs-tax-retrofit'),
                admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')
            )
        );
    }
    
    // Check WooCommerce Subscriptions
    if (!function_exists('wcs_get_subscriptions')) {
        $errors[] = array(
            'message' => __('WooCommerce Subscriptions doit être installé et activé.', 'wcs-tax-retrofit'),
            'action' => __('Installez et activez l\'extension WooCommerce Subscriptions.', 'wcs-tax-retrofit')
        );
    }
    
    return $errors;
}

// Effectuer la vérification immédiatement
$wc_tax_retrofit_dependency_errors = wc_tax_retrofit_check_dependencies_early();

// Si des erreurs existent, afficher une notice et arrêter le chargement
if (!empty($wc_tax_retrofit_dependency_errors)) {
    add_action('admin_notices', function() use ($wc_tax_retrofit_dependency_errors) {
        echo '<div class="notice notice-error" style="padding: 15px 20px;">';
        echo '<p style="margin: 0 0 10px 0;"><strong>';
        echo esc_html__('WooCommerce Subscription Tax Retrofit', 'wcs-tax-retrofit');
        echo '</strong></p>';
        echo '<p style="margin: 0 0 10px 0;">';
        echo esc_html__('Le plugin ne peut pas se charger car certaines dépendances sont manquantes :', 'wcs-tax-retrofit');
        echo '</p>';
        echo '<ul style="margin: 10px 0 10px 20px; list-style: disc;">';
        
        foreach ($wc_tax_retrofit_dependency_errors as $error) {
            echo '<li style="margin: 5px 0;">';
            echo '<strong>' . esc_html($error['message']) . '</strong>';
            if (!empty($error['action'])) {
                echo '<br><span style="color: #666;">';
                // action peut contenir du HTML (lien), on utilise wp_kses
                echo wp_kses(
                    $error['action'],
                    array(
                        'a' => array(
                            'href' => array(),
                            'target' => array(),
                            'rel' => array()
                        )
                    )
                );
                echo '</span>';
            }
            echo '</li>';
        }
        
        echo '</ul>';
        echo '</div>';
    });
    
    // ARRÊTER le chargement du plugin ici
    return;
}

// Si on arrive ici, toutes les dépendances sont OK, on peut continuer

/**
 * Vérifie les dépendances à l'exécution
 * Utilisée avant toute opération critique
 * 
 * @return bool True si toutes les dépendances sont satisfaites
 */
function wc_tax_retrofit_check_dependencies(): bool {
    // PHP 7.4+ garanti par le header WordPress "Requires PHP: 7.4" et le check early
    return class_exists('WooCommerce') && function_exists('wcs_get_subscriptions');
}

define('WC_TAX_RETROFIT_VERSION', '1.4.4');
define('WC_TAX_RETROFIT_BATCH_SIZE', 100);
define('WC_TAX_RETROFIT_TOLERANCE', 0.01);

/**
 * Valide et caste une tolérance (float entre 0.001 et 1.0)
 * 
 * @param mixed $tolerance Valeur à valider
 * @param float $default Valeur par défaut
 * @return float Tolérance validée
 */
function wc_tax_retrofit_validate_tolerance($tolerance, $default = 0.01): float {
    $validated = wc_tax_retrofit_validate_float($tolerance, 0.001, 1.0);
    if ($validated === false) {
        wc_tax_retrofit_log("Tolérance hors limites : " . $tolerance, 'warning');
        return $default;
    }
    return $validated;
}

/**
 * Valide un tableau de statuts d'abonnements
 * 
 * @param mixed $statuses Statuts à valider
 * @param array $default Statuts par défaut
 * @return array Statuts validés
 */
function wc_tax_retrofit_validate_statuses($statuses, $default = array('active', 'pending-cancel', 'on-hold')): array {
    if (!is_array($statuses) || empty($statuses)) {
        return $default;
    }
    
    $valid_statuses = array('active', 'on-hold', 'pending-cancel', 'pending', 'cancelled', 'expired', 'switched');
    $validated = array();
    
    foreach ($statuses as $status) {
        if (in_array($status, $valid_statuses, true)) {
            $validated[] = $status;
        }
    }
    
    return empty($validated) ? $default : $validated;
}

/**
 * Load plugin textdomain for translations
 */
function wc_tax_retrofit_load_textdomain(): void {
    load_plugin_textdomain(
        'wcs-tax-retrofit',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}
add_action('plugins_loaded', 'wc_tax_retrofit_load_textdomain');

/**
 * Récupère tous les taux de TVA configurés dans WooCommerce
 * 
 * @return array Tableau associatif [tax_rate_id => ['rate' => 20.0, 'name' => 'TVA FR', 'country' => 'FR', ...]]
 */
function wc_tax_retrofit_get_all_tax_rates(): array {
    global $wpdb;
    $table = $wpdb->prefix . 'woocommerce_tax_rates';
    
    $rates = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT tax_rate_id, tax_rate_country, tax_rate, tax_rate_name, tax_rate_class, tax_rate_priority
             FROM {$table}
             ORDER BY tax_rate_country ASC, tax_rate DESC
             LIMIT %d",
            100
        ),
        ARRAY_A
    );
    
    if (empty($rates)) {
        return array();
    }
    
    $formatted_rates = array();
    foreach ($rates as $rate) {
        $rate_id = $rate['tax_rate_id'];
        $formatted_rates[$rate_id] = array(
            'rate' => floatval($rate['tax_rate']),
            'name' => $rate['tax_rate_name'],
            'country' => $rate['tax_rate_country'],
            'class' => $rate['tax_rate_class'],
            'priority' => intval($rate['tax_rate_priority']),
            // Label pour affichage
            'label' => sprintf(
                '%s - %s%% (%s)',
                $rate['tax_rate_country'] ?: 'Global',
                number_format(floatval($rate['tax_rate']), 2, ',', ''),
                $rate['tax_rate_name'] ?: __('Sans nom', 'wcs-tax-retrofit')
            )
        );
    }
    
    return $formatted_rates;
}

/**
 * Récupère le taux de TVA sélectionné par l'utilisateur
 * 
 * @return float Taux de TVA en décimal (ex: 0.20 pour 20%)
 */
function wc_tax_retrofit_get_selected_rate(): float {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    // Priorité 1 : Taux sélectionné en base
    $selected_rate_id = get_option('wc_tax_retrofit_selected_tax_rate_id', null);

    if ($selected_rate_id !== null) {
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_tax_rates';
        $rate = $wpdb->get_var($wpdb->prepare(
            "SELECT tax_rate FROM {$table} WHERE tax_rate_id = %d",
            $selected_rate_id
        ));

        if ($rate !== null) {
            $cached = floatval($rate) / 100; // 20 → 0.20
            return $cached;
        }
    }

    // Priorité 2 : Filtre (rétrocompatibilité)
    $cached = apply_filters('wc_tax_retrofit_rate', 0.20);
    return $cached;
}

/**
 * Récupère le taux de TVA en pourcentage pour affichage
 * 
 * @return float Taux en pourcentage (ex: 20.0 pour 20%)
 */
function wc_tax_retrofit_get_selected_rate_percent(): float {
    return wc_tax_retrofit_get_selected_rate() * 100;
}

/**
 * Valide et nettoie une date au format Y-m-d
 * 
 * @param string $date Date à valider
 * @return string|false Date valide ou false
 */
function wc_tax_retrofit_validate_date($date) {
    if (empty($date) || !is_string($date)) {
        return false;
    }
    
    // Validation regex stricte : YYYY-MM-DD
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    
    // Vérification que la date est réellement valide (pas de 2024-13-45)
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        return false;
    }
    
    return $date;
}

/**
 * Valide et nettoie un float (tolérance)
 * 
 * @param mixed $value Valeur à valider
 * @param float $min Minimum accepté
 * @param float $max Maximum accepté
 * @return float|false Float valide ou false
 */
function wc_tax_retrofit_validate_float($value, $min = 0.001, $max = 1.0) {
    if ($value === null || $value === '' || $value === false) {
        return false;
    }
    
    if (!is_numeric($value)) {
        return false;
    }
    
    $float_value = floatval($value);
    
    if ($float_value < $min || $float_value > $max) {
        return false;
    }
    
    return $float_value;
}

/**
 * Récupère les statuts d'abonnements à traiter
 * Priorité : option DB > filtre > défaut (active, pending-cancel, on-hold)
 *
 * @return array Liste de statuts validés
 */
function wc_tax_retrofit_get_subscription_statuses(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    // Priorité 1 : Options sauvegardées en base
    $saved_statuses = get_option('wc_tax_retrofit_statuses', null);

    // Priorité 2 : Filtre (pour compatibilité avancée)
    $default_statuses = array('active', 'pending-cancel', 'on-hold');
    $filtered_statuses = apply_filters('wc_tax_retrofit_subscription_statuses', $default_statuses);

    // Si des statuts sont sauvegardés, les valider strictement
    if ($saved_statuses !== null) {
        $cached = wc_tax_retrofit_validate_statuses($saved_statuses, $filtered_statuses);
        return $cached;
    }

    // Valider aussi les statuts du filtre au cas où un développeur aurait mis n'importe quoi
    $cached = wc_tax_retrofit_validate_statuses($filtered_statuses, $default_statuses);
    return $cached;
}

/**
 * Récupère la tolérance d'arrondi en euros
 * Priorité : option DB > filtre > constante WC_TAX_RETROFIT_TOLERANCE (0.01)
 *
 * @return float Tolérance en euros (ex: 0.01 pour 1 centime)
 */
function wc_tax_retrofit_get_tolerance(): float {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    // Priorité 1 : Option sauvegardée en base
    $saved_tolerance = get_option('wc_tax_retrofit_tolerance_setting', null);

    if ($saved_tolerance !== null) {
        $cached = wc_tax_retrofit_validate_tolerance($saved_tolerance, WC_TAX_RETROFIT_TOLERANCE);
        return $cached;
    }

    // Priorité 2 : Filtre (rétrocompatibilité)
    $cached = wc_tax_retrofit_validate_tolerance(
        apply_filters('wc_tax_retrofit_tolerance', WC_TAX_RETROFIT_TOLERANCE),
        0.01
    );
    return $cached;
}

/**
 * Récupère la date limite pour le filtre d'abonnements (format Y-m-d)
 * Priorité : option DB > détection auto > filtre > date du jour
 *
 * @return string Date au format Y-m-d
 */
function wc_tax_retrofit_get_date_limit(): string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    // Priorité 1 : Option sauvegardée en base
    $saved_date = get_option('wc_tax_retrofit_date_limit', '');

    if (!empty($saved_date)) {
        $validated_date = wc_tax_retrofit_validate_date($saved_date);
        if ($validated_date !== false) {
            $cached = $validated_date;
            return $cached;
        }
        // Si la date sauvegardée est invalide, logger et continuer
        wc_tax_retrofit_log('Date sauvegardée invalide : ' . $saved_date, 'error');
    }

    // Priorité 2 : Détection automatique du dernier abonnement sans TVA
    $auto_date = wc_tax_retrofit_detect_last_no_tax_date();
    if ($auto_date) {
        $validated_auto = wc_tax_retrofit_validate_date($auto_date);
        if ($validated_auto !== false) {
            $cached = $validated_auto;
            return $cached;
        }
    }

    // Priorité 3 : Filtre (rétrocompatibilité)
    $cached = apply_filters('wc_tax_retrofit_date_limit', current_time('Y-m-d'));
    return $cached;
}

function wc_tax_retrofit_count_subscriptions(): int {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    if (!function_exists('wcs_get_subscriptions')) return 0;
    $count_args = array(
        'status' => wc_tax_retrofit_get_subscription_statuses(),
        'date_created' => '<=' . wc_tax_retrofit_get_date_limit(),
        'return' => 'ids',
        'limit' => -1,
    );
    $cached = count(wcs_get_subscriptions($count_args));
    return $cached;
}

function wc_tax_retrofit_ajax_count(): void {
    check_ajax_referer('wc_tax_retrofit_count_nonce', 'nonce');

    if (!wc_tax_retrofit_check_dependencies()) {
        wp_send_json_error(__('Dépendances manquantes (WooCommerce ou WooCommerce Subscriptions)', 'wcs-tax-retrofit'));
    }

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(__('Permission refusée', 'wcs-tax-retrofit'));
    }

    wp_send_json_success(array('count' => wc_tax_retrofit_count_subscriptions()));
}
add_action('wp_ajax_wc_tax_retrofit_count', 'wc_tax_retrofit_ajax_count');

/**
 * Détecte automatiquement la date d'un abonnement sans TVA
 *
 * @param string $order 'DESC' pour le dernier, 'ASC' pour le premier
 * @return string|null Date au format Y-m-d ou null si aucun trouvé
 */
function wc_tax_retrofit_detect_no_tax_date($order = 'DESC') {
    static $cache = array();
    if (array_key_exists($order, $cache)) {
        return $cache[$order];
    }

    // Cache transient pour éviter les N+1 queries à chaque chargement admin
    $transient_key = 'wc_tax_retrofit_detect_date_' . strtolower($order);
    $transient_value = get_transient($transient_key);
    if ($transient_value !== false) {
        $cache[$order] = $transient_value === '' ? null : $transient_value;
        return $cache[$order];
    }

    if (!function_exists('wcs_get_subscriptions')) {
        return null;
    }

    $args = array(
        'subscriptions_per_page' => 50,
        'orderby' => 'date',
        'order' => in_array($order, array('ASC', 'DESC'), true) ? $order : 'DESC',
        'subscription_status' => wc_tax_retrofit_get_subscription_statuses(),
    );

    $subscriptions = wcs_get_subscriptions($args);

    foreach ($subscriptions as $subscription) {
        $items = $subscription->get_items();
        foreach ($items as $item) {
            $taxes = $item->get_taxes();
            if (empty($taxes['total']) || abs(array_sum($taxes['total'])) < 0.001) {
                $date_created = $subscription->get_date_created();
                if ($date_created) {
                    $cache[$order] = $date_created->format('Y-m-d');
                    set_transient($transient_key, $cache[$order], 5 * MINUTE_IN_SECONDS);
                    return $cache[$order];
                }
            }
        }
    }

    $cache[$order] = null;
    set_transient($transient_key, '', 5 * MINUTE_IN_SECONDS);
    return null;
}

function wc_tax_retrofit_detect_last_no_tax_date() {
    return wc_tax_retrofit_detect_no_tax_date('DESC');
}

function wc_tax_retrofit_detect_first_no_tax_date() {
    return wc_tax_retrofit_detect_no_tax_date('ASC');
}

function wc_tax_retrofit_log($message, $level = 'info'): void {
    if (function_exists('wc_get_logger')) {
        wc_get_logger()->log($level, $message, array('source' => 'wcs-tax-retrofit'));
    }
}

function wc_tax_retrofit_validate_subscription($subscription): bool {
    return is_a($subscription, 'WC_Subscription') && $subscription->get_total() > 0 && count($subscription->get_items()) > 0;
}

function wc_tax_retrofit_get_tax_rate_id() {
    static $cached = null;
    static $cached_resolved = false;
    if ($cached_resolved) {
        return $cached;
    }

    global $wpdb;

    $target_rate = wc_tax_retrofit_get_selected_rate() * 100; // Ex: 0.20 → 20
    $table = $wpdb->prefix . 'woocommerce_tax_rates';

    wc_tax_retrofit_log(sprintf('Recherche taux de TVA : %.2f%%', $target_rate));
    
    // Étape 1 : Recherche directe en base de données (plus fiable)
    // D'abord chercher pour la France
    $result = $wpdb->get_row($wpdb->prepare(
        "SELECT tax_rate_id, tax_rate_country, tax_rate, tax_rate_name 
         FROM {$table} 
         WHERE tax_rate_country = %s 
         AND tax_rate_class = %s 
         AND ABS(tax_rate - %f) < 0.01
         ORDER BY tax_rate_priority ASC 
         LIMIT 1",
        'FR', '', $target_rate
    ));
    
    if ($result) {
        wc_tax_retrofit_log(sprintf('✓ Tax rate trouvé (DB direct FR): ID=%s, Taux=%s%%',
            $result->tax_rate_id, $result->tax_rate));
        $cached = $result->tax_rate_id;
        $cached_resolved = true;
        return $cached;
    }

    wc_tax_retrofit_log('Aucun taux FR trouvé, recherche dans tous les pays...');

    // Étape 2 : Chercher dans tous les pays
    $result = $wpdb->get_row($wpdb->prepare(
        "SELECT tax_rate_id, tax_rate_country, tax_rate, tax_rate_name
         FROM {$table}
         WHERE tax_rate_class = %s
         AND ABS(tax_rate - %f) < 0.01
         ORDER BY
            CASE WHEN tax_rate_country = 'FR' THEN 0 ELSE 1 END,
            tax_rate_priority ASC
         LIMIT 1",
        '', $target_rate
    ));

    if ($result) {
        wc_tax_retrofit_log(sprintf('✓ Tax rate trouvé (DB tous pays): ID=%s, Pays=%s, Taux=%s%%',
            $result->tax_rate_id, $result->tax_rate_country, $result->tax_rate));
        $cached = $result->tax_rate_id;
        $cached_resolved = true;
        return $cached;
    }
    
    // Étape 3 : Essayer via l'API WooCommerce (peut échouer si appelé trop tôt)
    if (class_exists('WC_Tax')) {
        try {
            $tax_rates = WC_Tax::find_rates(array(
                'country' => 'FR', 
                'state' => '', 
                'postcode' => '', 
                'city' => '', 
                'tax_class' => ''
            ));
            
            wc_tax_retrofit_log(sprintf('API WC_Tax::find_rates a trouvé %d taux', count($tax_rates)));
            
            foreach ($tax_rates as $rate) {
                wc_tax_retrofit_log(sprintf('  - Rate ID=%s, Taux=%s%%, Pays=%s',
                    $rate['rate_id'] ?? 'N/A',
                    $rate['rate'] ?? 'N/A',
                    $rate['country'] ?? 'N/A'));
                
                if (isset($rate['rate']) && abs($rate['rate'] - $target_rate) < 0.01) {
                    wc_tax_retrofit_log(sprintf('✓ Tax rate trouvé (API WC_Tax): ID=%s, Rate=%s%%',
                        $rate['rate_id'], $rate['rate']));
                    $cached = $rate['rate_id'];
                    $cached_resolved = true;
                    return $cached;
                }
            }
        } catch (\Throwable $e) {
            wc_tax_retrofit_log('Erreur lors de l\'appel WC_Tax::find_rates : ' . $e->getMessage());
        }
    }
    
    // Étape 4 : Debug - Lister TOUS les taux disponibles
    $all_available = $wpdb->get_results($wpdb->prepare(
        "SELECT tax_rate_id, tax_rate_country, tax_rate, tax_rate_name, tax_rate_class
         FROM {$table}
         ORDER BY tax_rate_country, tax_rate DESC
         LIMIT %d",
        20
    ));
    
    if (!empty($all_available)) {
        wc_tax_retrofit_log('⚠️ Aucun taux correspondant. Taux disponibles dans la base :');
        foreach ($all_available as $rate) {
            wc_tax_retrofit_log(sprintf('  - ID=%s, Pays=%s, Taux=%s%%, Nom=%s, Classe=%s', 
                $rate->tax_rate_id, 
                $rate->tax_rate_country, 
                $rate->tax_rate, 
                $rate->tax_rate_name, 
                $rate->tax_rate_class ?: '(standard)'));
        }
        
        wc_tax_retrofit_log(sprintf('Note: Recherche effectuée pour %.2f%% exact (tolérance 0.01%%)', $target_rate));
    } else {
        wc_tax_retrofit_log('⚠️ AUCUN taux de TVA configuré dans WooCommerce !');
        wc_tax_retrofit_log('Table vérifiée : ' . $table);
    }
    
    wc_tax_retrofit_log(sprintf('❌ ERREUR : Aucun taux de TVA trouvé pour %.2f%%', $target_rate), 'error');
    $cached_resolved = true;
    return null;
}

function wc_tax_retrofit_process($dry_run = false, $offset = 0): array {
    // VÉRIFICATION CRITIQUE : Dépendances
    if (!wc_tax_retrofit_check_dependencies()) {
        wc_tax_retrofit_log('ERREUR CRITIQUE : Dépendances manquantes', 'error');
        return array(
            'total' => 0,
            'batch_size' => 0,
            'offset' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 1,
            'details' => array(),
            'errors_list' => array(__('Dépendances manquantes (WooCommerce ou WooCommerce Subscriptions)', 'wcs-tax-retrofit')),
            'tolerance_warnings' => array(),
            'has_more' => false,
            'csv_data' => array()
        );
    }
    
    // Vérification des permissions et nonce (sauf en contexte WP-CLI)
    $is_cli = defined('WP_CLI') && WP_CLI;

    if (!$is_cli) {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(
                esc_html__('Accès non autorisé', 'wcs-tax-retrofit'),
                esc_html__('Erreur de sécurité', 'wcs-tax-retrofit'),
                array('response' => 403)
            );
        }

        // Vérification du nonce (sauf en dry-run)
        if (!$dry_run && !check_admin_referer('wc_tax_retrofit_nonce')) {
            wp_die(esc_html__('Erreur de sécurité : nonce invalide', 'wcs-tax-retrofit'));
        }
    }
    
    // Reprise automatique si offset sauvegardé (et pas d'offset déjà fourni)
    if (!$dry_run && $offset === 0 && get_option('wc_tax_retrofit_current_offset')) {
        $offset = (int) get_option('wc_tax_retrofit_current_offset');
        wc_tax_retrofit_log("Reprise automatique à l'offset $offset");
    }
    
    $lock_key = 'wc_tax_retrofit_running';
    if (get_transient($lock_key)) return array(
        'locked' => true, 'message' => __('Une mise à jour est déjà en cours.', 'wcs-tax-retrofit'),
        'total' => 0, 'batch_size' => 0, 'offset' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'details' => array(), 'errors_list' => array(), 'tolerance_warnings' => array(),
        'has_more' => false, 'csv_data' => array()
    );
    
    set_transient($lock_key, time(), 5 * MINUTE_IN_SECONDS);
    $tolerance = wc_tax_retrofit_get_tolerance();
    $tax_rate = wc_tax_retrofit_get_selected_rate();
    $current_user = $is_cli ? 'WP-CLI' : wp_get_current_user()->user_login;
    wc_tax_retrofit_log(sprintf('Début (Mode: %s, User: %s, Offset: %d, Tolérance: %s€)',
        $dry_run ? 'DRY-RUN' : 'PROD', $current_user, $offset, $tolerance));
    
    $tax_rate_id = wc_tax_retrofit_get_tax_rate_id();
    
    if ($tax_rate_id === null) {
        delete_transient($lock_key);
        wc_tax_retrofit_log('ERREUR : Taux de TVA introuvable dans WooCommerce', 'error');
        return array(
            'total' => 0, 'batch_size' => 0, 'offset' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1,
            'details' => array(),
            'errors_list' => array(__('Taux de TVA introuvable dans WooCommerce. Configurez un taux dans WooCommerce → Réglages → Taxes.', 'wcs-tax-retrofit')),
            'tolerance_warnings' => array(), 'has_more' => false, 'csv_data' => array()
        );
    }
    
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');
    
    $args = array(
        'status' => wc_tax_retrofit_get_subscription_statuses(),
        'date_created' => '<=' . wc_tax_retrofit_get_date_limit(),
        'limit' => WC_TAX_RETROFIT_BATCH_SIZE,
        'offset' => $offset,
        'orderby' => 'ID',
        'order' => 'ASC',
        'return' => 'objects',
    );
    $subscriptions = wcs_get_subscriptions($args);
    
    $total_count = null;
    if ($offset === 0) {
        $total_count = wc_tax_retrofit_count_subscriptions();
        wc_tax_retrofit_log('Total abonnements : ' . $total_count);
    }
    
    // Structure $stats : total, batch_size, offset, updated, skipped, errors, has_more,
    // details (string[]), errors_list (string[]), tolerance_warnings (string[]),
    // csv_data (array[] avec subscription_id, status, customer_id, customer_email,
    //           original_total, new_total_ht, new_tax, new_total_ttc, date_updated)
    // Hook : do_action('wc_tax_retrofit_after_process', $stats, $dry_run)
    $stats = array(
        'total' => $total_count, 
        'batch_size' => count($subscriptions), 
        'offset' => $offset, 
        'updated' => 0, 
        'skipped' => 0, 
        'errors' => 0, 
        'details' => array(), 
        'errors_list' => array(), 
        'tolerance_warnings' => array(),
        'has_more' => count($subscriptions) === WC_TAX_RETROFIT_BATCH_SIZE, 
        'csv_data' => array()
    );
    
    foreach ($subscriptions as $subscription) {
        try {
            $subscription_id = $subscription->get_id();
            if (!wc_tax_retrofit_validate_subscription($subscription)) {
                $stats['skipped']++;
                $stats['details'][] = "⚠️ #$subscription_id ignoré (validation)";
                continue;
            }
            
            $status = $subscription->get_status();
            $original_total = $subscription->get_total();
            $original_tax = $subscription->get_total_tax();
            
            if ($original_tax > 0.01) {
                $stats['skipped']++;
                $stats['details'][] = sprintf("#%d [%s] ignoré (TVA : %.2f€)", $subscription_id, $status, $original_tax);
                wc_tax_retrofit_log("#$subscription_id déjà migré", 'info');
                continue;
            }
            
            $has_changes = false;
            $total_tax_calc = 0;
            $items_to_recreate = array();
            
            foreach ($subscription->get_items() as $item_id => $item) {
                $item_ttc = $item->get_total();
                if ($item_ttc <= 0) continue;
                
                $item_ht = round($item_ttc / (1 + $tax_rate), 2);
                $item_tax = round($item_ttc - $item_ht, 2);

                $rounding_diff = abs(($item_ht + $item_tax) - $item_ttc);
                
                if ($rounding_diff >= $tolerance) {
                    $warning_msg = sprintf(
                        "Écart d'arrondi item #%d (abonnement #%d): %.4f€ >= %.4f€. TTC: %s, HT: %s, TVA: %s",
                        $item_id, $subscription_id, $rounding_diff, $tolerance, $item_ttc, $item_ht, $item_tax
                    );
                    $stats['tolerance_warnings'][] = $warning_msg;
                    wc_tax_retrofit_log($warning_msg, 'warning');
                    
                    // Écart d'arrondi à la limite : ignorer cet abonnement entier
                    $stats['skipped']++;
                    $stats['details'][] = sprintf("⚠️ #%d ignoré (écart item #%d: %.4f€)", $subscription_id, $item_id, $rounding_diff);
                    continue 2; // Continue la boucle externe (subscriptions)
                }
                
                $total_tax_calc += $item_tax;
                $items_to_recreate[] = array(
                    'old_item_id' => $item_id, 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(),
                    'quantity' => $item->get_quantity(), 'name' => $item->get_name(), 'total_ht' => $item_ht,
                    'tax_amount' => $item_tax, 'original_total_ttc' => $item_ttc, 'meta_data' => $item->get_meta_data()
                );
                $has_changes = true;
            }
            
            if ($has_changes && !$dry_run) {
                global $wpdb;
                $wpdb->query('START TRANSACTION');

                try {
                    foreach ($items_to_recreate as $item_data) $subscription->remove_item($item_data['old_item_id']);

                    foreach ($items_to_recreate as $item_data) {
                        $new_item = new WC_Order_Item_Product();
                        $new_item->set_product_id($item_data['product_id']);
                        $new_item->set_variation_id($item_data['variation_id']);
                        $new_item->set_quantity($item_data['quantity']);
                        $new_item->set_name($item_data['name']);
                        $new_item->set_subtotal($item_data['total_ht']);
                        $new_item->set_total($item_data['total_ht']);

                        $tax_data = array(
                            'total' => array($tax_rate_id => $item_data['tax_amount']),
                            'subtotal' => array($tax_rate_id => $item_data['tax_amount'])
                        );
                        $new_item->set_taxes($tax_data);

                        foreach ($item_data['meta_data'] as $meta) {
                            $new_item->add_meta_data($meta->key, $meta->value, false);
                        }
                        $subscription->add_item($new_item);
                    }

                    $subscription->update_taxes();
                    $subscription->calculate_totals();
                    $subscription->save();

                    $new_total = $subscription->get_total();
                    $new_tax = $subscription->get_total_tax();

                    $total_diff = abs($new_total - $original_total);

                    if ($total_diff >= $tolerance) {
                        $wpdb->query('ROLLBACK');
                        $warning_msg = sprintf(
                            "Écart total abonnement #%d: %.4f€ >= %.4f€. Avant: %s, Après: %s",
                            $subscription_id, $total_diff, $tolerance, $original_total, $new_total
                        );
                        $stats['tolerance_warnings'][] = $warning_msg;
                        wc_tax_retrofit_log($warning_msg, 'warning');

                        $stats['skipped']++;
                        $stats['details'][] = sprintf("⚠️ #%d ignoré (écart: %.4f€)", $subscription_id, $total_diff);
                        continue;
                    }

                    $wpdb->query('COMMIT');
                } catch (\Throwable $tx_error) {
                    $wpdb->query('ROLLBACK');
                    $stats['errors']++;
                    $error = sprintf("Erreur transaction #%d : %s", $subscription_id, $tx_error->getMessage());
                    $stats['errors_list'][] = $error;
                    wc_tax_retrofit_log($error, 'error');
                    continue;
                }

                $subscription->add_order_note(sprintf('[Tax Retrofit v%s] TTC→HT+TVA. Total: %s (TVA: %s). Par: %s',
                    WC_TAX_RETROFIT_VERSION, wc_price($new_total), wc_price($new_tax), $current_user));

                wc_tax_retrofit_log(sprintf('#%d MAJ - Total: %s, TVA: %s', $subscription_id, $new_total, $new_tax));

                $stats['details'][] = sprintf("✓ #%d [%s] - %.2f€ (TVA: %.2f€)", $subscription_id, $status, $new_total, $new_tax);
                $stats['csv_data'][] = array(
                    'subscription_id' => $subscription_id, 'status' => $status, 'customer_id' => $subscription->get_customer_id(),
                    'customer_email' => $subscription->get_billing_email(), 'original_total' => $original_total,
                    'new_total_ht' => $new_total - $new_tax, 'new_tax' => $new_tax, 'new_total_ttc' => $new_total, 'date_updated' => current_time('mysql')
                );
            }
            
            if ($has_changes) {
                if ($dry_run) {
                    $ht_calculated = round($original_total / (1 + $tax_rate), 2);
                    $stats['details'][] = sprintf("🔍 [DRY-RUN] #%d [%s] - %.2f€ → HT: %.2f€ + TVA: %.2f€",
                        $subscription_id, $status, $original_total, $ht_calculated, $total_tax_calc);
                    $stats['csv_data'][] = array(
                        'subscription_id' => $subscription_id, 'status' => $status, 'customer_id' => $subscription->get_customer_id(),
                        'customer_email' => $subscription->get_billing_email(), 'original_total' => $original_total,
                        'new_total_ht' => $ht_calculated, 'new_tax' => $total_tax_calc,
                        'new_total_ttc' => $original_total, 'date_updated' => current_time('mysql')
                    );
                }
                $stats['updated']++;
            }
        } catch (\Throwable $e) {
            $stats['errors']++;
            $error = sprintf("Erreur #%d : %s", $subscription_id ?? '?', $e->getMessage());
            $stats['errors_list'][] = $error;
            wc_tax_retrofit_log($error, 'error');
        }
    }
    
    // Sauvegarder l'offset pour reprise en cas d'interruption
    if (!$dry_run && $stats['has_more']) {
        update_option('wc_tax_retrofit_current_offset', $offset + $stats['batch_size']);
        update_option('wc_tax_retrofit_last_activity', time());
    }

    delete_transient($lock_key);

    if (!empty($stats['csv_data'])) {
        $user_id = get_current_user_id();
        $csv_key = 'wc_tax_retrofit_csv_' . $user_id;
        $existing = get_transient($csv_key) ?: array();
        set_transient($csv_key, [...$existing, ...$stats['csv_data']], DAY_IN_SECONDS);
    }
    
    wc_tax_retrofit_log(sprintf('Fin batch %d - Traités: %d, MAJ: %d, Ignorés: %d, Erreurs: %d, Alertes tolérance: %d', 
        $offset, $stats['batch_size'], $stats['updated'], $stats['skipped'], $stats['errors'], count($stats['tolerance_warnings'])));
    
    /**
     * Hook déclenché après le traitement d'un batch
     * 
     * Permet aux développeurs d'intercepter les statistiques pour:
     * - Logger les alertes de tolérance dans un système externe
     * - Envoyer des notifications si trop d'alertes
     * - Parser et analyser les écarts détectés
     * - Déclencher des workflows personnalisés
     * 
     * @param array $stats    Tableau de statistiques (voir doc structure ci-dessus)
     * @param bool  $dry_run  True si simulation, False si exécution réelle
     * 
     * @since 1.1.0
     */
    do_action('wc_tax_retrofit_after_process', $stats, $dry_run);
    
    return $stats;
}

function wc_tax_retrofit_export_csv(): void {
    // Vérification 1 : Action correcte (en premier pour performance, tourne sur chaque admin_init)
    if (!isset($_GET['action']) || $_GET['action'] !== 'wc_tax_retrofit_export_csv') {
        return;
    }

    // Vérification 2 : Dépendances
    if (!wc_tax_retrofit_check_dependencies()) {
        wp_die(
            esc_html__('Impossible d\'exporter : dépendances manquantes (WooCommerce ou WooCommerce Subscriptions)', 'wcs-tax-retrofit'),
            esc_html__('Erreur', 'wcs-tax-retrofit'),
            array('response' => 500)
        );
    }

    // Vérification 3 : Capacité utilisateur
    if (!current_user_can('manage_woocommerce')) {
        wp_die(
            esc_html__('Accès non autorisé', 'wcs-tax-retrofit'),
            esc_html__('Erreur', 'wcs-tax-retrofit'),
            array('response' => 403)
        );
    }

    // Vérification 4 : Nonce (protection CSRF)
    check_admin_referer('wc_tax_retrofit_export_csv_nonce');
    
    $user_id = get_current_user_id();
    $csv_key = 'wc_tax_retrofit_csv_' . $user_id;
    $csv_data = get_transient($csv_key);
    
    if (empty($csv_data)) {
        wp_die(esc_html__('Aucune donnée à exporter.', 'wcs-tax-retrofit'));
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="wcs-tax-retrofit-' . wp_date('Y-m-d-His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, array(
        __('ID Abonnement', 'wcs-tax-retrofit'),
        __('Statut', 'wcs-tax-retrofit'),
        __('ID Client', 'wcs-tax-retrofit'),
        __('Email Client', 'wcs-tax-retrofit'),
        __('Total Original (TTC)', 'wcs-tax-retrofit'),
        __('Nouveau Total HT', 'wcs-tax-retrofit'),
        __('Nouvelle TVA', 'wcs-tax-retrofit'),
        __('Nouveau Total TTC', 'wcs-tax-retrofit'),
        __('Date Modification', 'wcs-tax-retrofit')
    ), ';');
    
    foreach ($csv_data as $row) {
        fputcsv($output, array($row['subscription_id'], $row['status'], $row['customer_id'], $row['customer_email'],
            number_format($row['original_total'], 2, ',', ''), number_format($row['new_total_ht'], 2, ',', ''),
            number_format($row['new_tax'], 2, ',', ''), number_format($row['new_total_ttc'], 2, ',', ''), $row['date_updated']), ';');
    }
    fclose($output);
    wc_tax_retrofit_log('Export CSV : ' . count($csv_data) . ' lignes');
    exit;
}
add_action('admin_init', 'wc_tax_retrofit_export_csv');

/**
 * Vérifie que l'utilisateur a les capacités requises pour les actions admin
 * Centralise la vérification pour éviter la répétition
 * 
 * @param string $capability Capacité requise (défaut: manage_woocommerce)
 * @return void Appelle wp_die() si l'utilisateur n'a pas la capacité
 */
function wc_tax_retrofit_require_capability($capability = 'manage_woocommerce'): void {
    if (!current_user_can($capability)) {
        wp_die(
            esc_html__('Vous n\'avez pas les permissions nécessaires pour effectuer cette action.', 'wcs-tax-retrofit'),
            esc_html__('Accès non autorisé', 'wcs-tax-retrofit'),
            array('response' => 403)
        );
    }
}

function wc_tax_retrofit_display_results($stats, $dry_run = false): void {
    if (isset($stats['locked']) && $stats['locked']) {
        echo "<div style='background:#fff3cd;padding:20px;margin:20px;border-left:4px solid #ffc107'><h2>⏳ " . esc_html__('En cours', 'wcs-tax-retrofit') . "</h2><p>" . esc_html($stats['message']) . "</p></div>";
        return;
    }

    $mode_label = $dry_run ? '🔍 ' . __('SIMULATION', 'wcs-tax-retrofit') : '✅ ' . __('MISE À JOUR', 'wcs-tax-retrofit');
    $mode_color = $dry_run ? '#2196f3' : '#46b450';

    echo "<div style='background:#fff;padding:20px;margin:20px;border-left:4px solid " . esc_attr($mode_color) . "'><h2>" . esc_html($mode_label) . "</h2>";

    if (isset($stats['total']) && $stats['total'] !== null && $stats['total'] > 0) {
        $processed = intval($stats['offset']) + intval($stats['batch_size']);
        $percent = min(100, round(($processed / intval($stats['total'])) * 100));

        echo "<div style='margin:20px 0'>";
        echo "<div style='background:#f0f0f0;border-radius:10px;height:30px;position:relative;overflow:hidden'>";
        echo "<div style='background:" . esc_attr($mode_color) . ";height:100%;width:" . intval($percent) . "%;transition:width 0.3s'></div>";
        echo "<div style='position:absolute;top:0;left:0;right:0;text-align:center;line-height:30px;font-weight:bold;color:#333'>";
        echo intval($processed) . " / " . intval($stats['total']) . " " . esc_html__('abonnements traités', 'wcs-tax-retrofit') . " (" . intval($percent) . "%)";
        echo "</div></div></div>";
    }

    if (isset($stats['total']) && $stats['total'] !== null) {
        echo "<p><strong>" . esc_html__('Total :', 'wcs-tax-retrofit') . "</strong> " . intval($stats['total']) . "</p><p><strong>" . esc_html__('Batch :', 'wcs-tax-retrofit') . "</strong> " . intval($stats['batch_size']) . " (offset: " . intval($stats['offset']) . ")</p>";
    } else {
        echo "<p><strong>" . esc_html__('Batch :', 'wcs-tax-retrofit') . "</strong> " . intval($stats['batch_size']) . "</p>";
    }

    echo "<p><strong>✓ " . esc_html__('Mis à jour :', 'wcs-tax-retrofit') . "</strong> <span style='color:green;font-weight:bold'>" . intval($stats['updated']) . "</span></p>";
    echo "<p><strong>⊗ " . esc_html__('Ignorés :', 'wcs-tax-retrofit') . "</strong> " . intval($stats['skipped']) . "</p>";
    if ($stats['errors'] > 0) echo "<p style='color:#d63638'><strong>✗ " . esc_html__('Erreurs :', 'wcs-tax-retrofit') . "</strong> " . intval($stats['errors']) . "</p>";

    if (!empty($stats['tolerance_warnings'])) {
        echo "<div style='background:#fff3cd;padding:15px;margin:15px 0;border-left:4px solid #ffc107'>";
        echo "<h3>⚠️ " . esc_html(sprintf(__('Alertes de tolérance (%d)', 'wcs-tax-retrofit'), count($stats['tolerance_warnings']))) . "</h3>";
        echo "<p><strong>" . esc_html__('Ces abonnements ont été IGNORÉS', 'wcs-tax-retrofit') . "</strong> " . esc_html(sprintf(__('car ils présentent des écarts d\'arrondi >= %s€ :', 'wcs-tax-retrofit'), wc_tax_retrofit_get_tolerance())) . "</p>";
        echo "<div style='max-height:300px;overflow-y:auto;background:white;padding:10px;font-family:monospace;font-size:11px'>";
        foreach ($stats['tolerance_warnings'] as $warning) {
            echo esc_html($warning) . "<br>";
        }
        echo "</div>";
        echo "<p><strong>💡 " . esc_html__('Que faire ?', 'wcs-tax-retrofit') . "</strong></p>";
        echo "<ul style='margin:10px 0'>";
        echo "<li>" . esc_html__('Ces écarts sont dus aux arrondis de TVA', 'wcs-tax-retrofit') . "</li>";
        echo "<li>" . sprintf(__('Ils sont comptés dans %1$s, pas dans %2$s', 'wcs-tax-retrofit'), '<strong>⊗ ' . esc_html__('Ignorés', 'wcs-tax-retrofit') . '</strong>', '<strong>✗ ' . esc_html__('Erreurs', 'wcs-tax-retrofit') . '</strong>') . "</li>";
        echo "<li>" . esc_html__('Si vous acceptez ces écarts minimes (1 centime), augmentez la tolérance à 0.02€ dans les paramètres', 'wcs-tax-retrofit') . "</li>";
        echo "</ul>";
        echo "</div>";
    }

    if (isset($stats['has_more']) && $stats['has_more']) {
        $next = $stats['offset'] + WC_TAX_RETROFIT_BATCH_SIZE;
        echo "<div style='background:#e7f5fe;padding:20px;margin-top:20px;border-left:4px solid #2196f3'><h3>📦 " . esc_html__('Lot suivant', 'wcs-tax-retrofit') . "</h3>";
        echo "<p>⏱️ " . esc_html__('Traitement en cours, ne fermez pas cette page...', 'wcs-tax-retrofit') . "</p>";

        // Compteur temps réel mis à jour
        if (isset($stats['total']) && $stats['total'] > 0) {
            $remaining = $stats['total'] - ($stats['offset'] + $stats['batch_size']);
            $eta_seconds = ceil(($remaining / WC_TAX_RETROFIT_BATCH_SIZE) * 5);
            $eta_minutes = floor($eta_seconds / 60);
            $eta_sec_remain = $eta_seconds % 60;

            echo "<p><strong>" . esc_html__('Restant :', 'wcs-tax-retrofit') . "</strong> ~" . intval($remaining) . " " . esc_html__('abonnements', 'wcs-tax-retrofit');
            if ($eta_minutes > 0) {
                echo " | <strong>" . esc_html__('Temps estimé :', 'wcs-tax-retrofit') . "</strong> ~" . intval($eta_minutes) . "min " . intval($eta_sec_remain) . "s";
            } else {
                echo " | <strong>" . esc_html__('Temps estimé :', 'wcs-tax-retrofit') . "</strong> ~" . intval($eta_sec_remain) . "s";
            }
            echo "</p>";
        }

        $continue_label = esc_html__('Continuer', 'wcs-tax-retrofit');
        $countdown_text = esc_js(__('Continuation automatique dans', 'wcs-tax-retrofit'));

        echo "<form method='post' id='continue-batch-form'>";
        wp_nonce_field('wc_tax_retrofit_nonce');
        echo "<input type='hidden' name='batch_offset' value='" . esc_attr($next) . "'>";
        echo $dry_run ? "<input type='hidden' name='dry_run' value='yes'>" : "<input type='hidden' name='confirm_update' value='yes'>";
        echo "<button type='submit' class='button button-primary button-large'>➡️ {$continue_label}</button>";
        echo " <span id='countdown-timer'>{$countdown_text} <strong>3</strong>s...</span>";
        echo "</form>";
        echo "<script>
        var countdown = 3;
        var countdownText = '{$countdown_text}';
        var timer = setInterval(function() {
            countdown--;
            if (countdown > 0) {
                document.getElementById('countdown-timer').innerHTML = countdownText + ' <strong>' + countdown + '</strong>s...';
            } else {
                clearInterval(timer);
                document.getElementById('continue-batch-form').submit();
            }
        }, 1000);
        </script></div>";
    }

    if (!empty($stats['details'])) {
        echo "<h3>" . esc_html__('Détails :', 'wcs-tax-retrofit') . "</h3><div style='max-height:400px;overflow-y:auto;background:#f5f5f5;padding:15px;font-family:monospace;font-size:12px'>";
        foreach ($stats['details'] as $d) echo esc_html($d) . "<br>";
        echo "</div>";
    }

    if (!empty($stats['errors_list'])) {
        echo "<div style='background:#ffebe8;padding:15px;margin-top:20px;border-left:4px solid #d63638'><h3>⚠️ " . esc_html__('Erreurs', 'wcs-tax-retrofit') . "</h3><ul>";
        foreach ($stats['errors_list'] as $e) echo "<li style='color:#d63638'>" . esc_html($e) . "</li>";
        echo "</ul></div>";
    }

    if (!$dry_run && $stats['updated'] > 0 && !$stats['has_more']) {
        $user_id = get_current_user_id();
        $csv_key = 'wc_tax_retrofit_csv_' . $user_id;
        $csv = get_transient($csv_key);
        if ($csv) {
            echo "<div style='margin-top:20px;padding:15px;background:#e7f5fe;border-left:4px solid #2196f3'><h3>📊 " . esc_html__('Export', 'wcs-tax-retrofit') . "</h3>";
            $url = wp_nonce_url(admin_url('admin.php?action=wc_tax_retrofit_export_csv'), 'wc_tax_retrofit_export_csv_nonce');
            echo "<a href='" . esc_url($url) . "' class='button button-secondary'>📥 CSV (" . count($csv) . ")</a></div>";
        }

        echo "<div style='margin-top:20px;padding:15px;background:#fff3cd;border-left:4px solid #ffc107'><p><strong>⚠️ " . esc_html__('Prochaines étapes :', 'wcs-tax-retrofit') . "</strong></p>";
        echo "<ol><li>" . esc_html__('Téléchargez le CSV', 'wcs-tax-retrofit') . "</li><li>" . esc_html__('Vérifiez 2-3 abonnements', 'wcs-tax-retrofit') . "</li>";
        echo "<li>📋 <a href='" . esc_url(admin_url('admin.php?page=wc-status&tab=logs')) . "' target='_blank'>" . esc_html__('Consultez les logs WooCommerce', 'wcs-tax-retrofit') . "</a> (source: wcs-tax-retrofit)</li>";
        echo "<li>" . esc_html__('Vérifiez Stripe', 'wcs-tax-retrofit') . "</li><li>" . esc_html__('Désactivez ce plugin', 'wcs-tax-retrofit') . "</li></ol></div>";
    } elseif ($dry_run && $stats['updated'] > 0 && !$stats['has_more']) {
        $user_id = get_current_user_id();
        $csv_key = 'wc_tax_retrofit_csv_' . $user_id;
        $csv = get_transient($csv_key);
        if ($csv) {
            echo "<div style='margin-top:20px;padding:15px;background:#e7f5fe;border-left:4px solid #2196f3'><h3>📊 " . esc_html__('CSV Simulation', 'wcs-tax-retrofit') . "</h3>";
            $url = wp_nonce_url(admin_url('admin.php?action=wc_tax_retrofit_export_csv'), 'wc_tax_retrofit_export_csv_nonce');
            echo "<a href='" . esc_url($url) . "' class='button button-secondary'>📥 " . esc_html(sprintf(__('CSV simulation (%d)', 'wcs-tax-retrofit'), count($csv))) . "</a></div>";
        }
    }

    echo "</div>";
}

function wc_tax_retrofit_admin_menu(): void {
    // Vérifier les dépendances AVANT de créer le menu
    if (!wc_tax_retrofit_check_dependencies()) {
        // Ne pas créer le menu si les dépendances manquent
        return;
    }
    
    add_submenu_page('woocommerce', 'Subscription Tax Retrofit', 'Tax Retrofit', 'manage_woocommerce', 'wcs-tax-retrofit', 'wc_tax_retrofit_admin_page');
}
add_action('admin_menu', 'wc_tax_retrofit_admin_menu');

function wc_tax_retrofit_admin_page(): void {
    // Double vérification des dépendances (sécurité)
    if (!wc_tax_retrofit_check_dependencies()) {
        echo "<div class='wrap'><h1>" . esc_html__('Tax Retrofit - Dépendances manquantes', 'wcs-tax-retrofit') . "</h1>";
        echo "<div class='notice notice-error'><p>" . esc_html__('Impossible d\'accéder à cette page. Veuillez installer et activer WooCommerce et WooCommerce Subscriptions.', 'wcs-tax-retrofit') . "</p></div>";
        echo "</div>";
        return;
    }
    
    $already_executed = get_option('wc_tax_retrofit_executed', false);
    
    // Gestion de la réinitialisation complète
    if (isset($_POST['full_reset']) && check_admin_referer('wc_tax_retrofit_full_reset_nonce')) {
        wc_tax_retrofit_require_capability();
        
        // Nettoyer TOUTES les données de migration
        delete_option('wc_tax_retrofit_executed');
        delete_option('wc_tax_retrofit_count');
        delete_option('wc_tax_retrofit_date');
        delete_option('wc_tax_retrofit_partial_count');
        delete_option('wc_tax_retrofit_current_offset');
        delete_option('wc_tax_retrofit_last_activity');
        
        // Nettoyer le CSV de l'utilisateur
        $user_id = get_current_user_id();
        delete_transient('wc_tax_retrofit_csv_' . $user_id);
        delete_transient('wc_tax_retrofit_running');
        
        wc_tax_retrofit_log('Migration complètement réinitialisée par ' . wp_get_current_user()->user_login);
        
        echo '<div class="notice notice-warning is-dismissible" style="padding:15px;margin:20px 0">';
        echo '<p><strong>✓ ' . esc_html__('Migration réinitialisée', 'wcs-tax-retrofit') . '</strong></p>';
        echo '<p>' . esc_html__('Toutes les données de migration ont été effacées. Vous pouvez relancer depuis le début.', 'wcs-tax-retrofit') . '</p>';
        echo '</div>';
        
        $already_executed = false;
    }
    
    // Vérifier s'il y a une migration interrompue à reprendre
    $interrupted_offset = get_option('wc_tax_retrofit_current_offset', 0);
    $last_activity = get_option('wc_tax_retrofit_last_activity', 0);
    $can_resume = false;
    
    if ($interrupted_offset > 0 && !$already_executed) {
        // Vérifier que l'interruption n'est pas trop ancienne (< 1 heure)
        $time_since_activity = time() - $last_activity;
        if ($time_since_activity < 3600) {
            $can_resume = true;
        }
    }
    
    if (isset($_POST['save_date']) && check_admin_referer('wc_tax_retrofit_save_date_nonce')) {
        wc_tax_retrofit_require_capability();
        
        $success_messages = array();
        $error_messages = array();
        
        // Sauvegarder le taux de TVA
        if (isset($_POST['tax_rate_id'])) {
            $tax_rate_id = intval($_POST['tax_rate_id']);
            
            // Vérifier que ce taux existe
            global $wpdb;
            $table = $wpdb->prefix . 'woocommerce_tax_rates';
            $rate_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT tax_rate FROM {$table} WHERE tax_rate_id = %d",
                $tax_rate_id
            ));
            
            if ($rate_exists !== null) {
                update_option('wc_tax_retrofit_selected_tax_rate_id', $tax_rate_id);
                wc_tax_retrofit_log('Taux de TVA modifié : ID ' . $tax_rate_id . ' (' . $rate_exists . '%)');
                $success_messages[] = __('Taux de TVA :', 'wcs-tax-retrofit') . ' ' . number_format(floatval($rate_exists), 2, ',', '') . '%';
            } else {
                $error_messages[] = sprintf(__('Taux de TVA invalide (ID: %d)', 'wcs-tax-retrofit'), $tax_rate_id);
            }
        }
        
        // Sauvegarder la date
        $new_date = sanitize_text_field($_POST['date_limit']);
        $validated_date = wc_tax_retrofit_validate_date($new_date);
        
        if ($validated_date !== false) {
            update_option('wc_tax_retrofit_date_limit', $validated_date);
            wc_tax_retrofit_log('Date modifiée : ' . $validated_date);
            $success_messages[] = __('Date :', 'wcs-tax-retrofit') . ' ' . esc_html($validated_date);
        } else {
            $error_messages[] = __('Format de date invalide (AAAA-MM-JJ)', 'wcs-tax-retrofit');
        }
        
        // Afficher les messages
        if (!empty($success_messages)) {
            echo '<div class="notice notice-success is-dismissible" style="padding:15px;margin:20px 0"><p><strong>✓ ' . esc_html__('Enregistré :', 'wcs-tax-retrofit') . '</strong><br>' . implode('<br>', array_map('esc_html', $success_messages)) . '</p></div>';
        }
        if (!empty($error_messages)) {
            echo '<div class="notice notice-error is-dismissible" style="padding:15px;margin:20px 0"><p><strong>✗ ' . esc_html__('Erreur :', 'wcs-tax-retrofit') . '</strong><br>' . implode('<br>', array_map('esc_html', $error_messages)) . '</p></div>';
        }
    }
    
    if (isset($_POST['save_settings']) && check_admin_referer('wc_tax_retrofit_save_settings_nonce')) {
        wc_tax_retrofit_require_capability();
        $settings_has_error = false;

        // Sauvegarder les statuts
        $selected_statuses = array();

        if (isset($_POST['statuses']) && is_array($_POST['statuses'])) {
            $selected_statuses = wc_tax_retrofit_validate_statuses(array_map('sanitize_text_field', $_POST['statuses']), array());
        }

        if (!empty($selected_statuses)) {
            update_option('wc_tax_retrofit_statuses', $selected_statuses);
            wc_tax_retrofit_log('Statuts modifiés : ' . implode(', ', $selected_statuses));
        } else {
            echo '<div class="notice notice-error is-dismissible" style="padding:15px;margin:20px 0"><p><strong>✗ ' . esc_html__('Erreur :', 'wcs-tax-retrofit') . '</strong> ' . esc_html__('Vous devez sélectionner au moins un statut valide', 'wcs-tax-retrofit') . '</p></div>';
            $settings_has_error = true;
        }

        // Sauvegarder la tolérance
        if (isset($_POST['tolerance'])) {
            $tolerance_input = sanitize_text_field($_POST['tolerance']);

            $validated_tolerance = wc_tax_retrofit_validate_float($tolerance_input, 0.001, 1.0);
            if ($validated_tolerance !== false) {
                update_option('wc_tax_retrofit_tolerance_setting', $validated_tolerance);
                wc_tax_retrofit_log('Tolérance modifiée : ' . $validated_tolerance . '€');
            } else {
                echo '<div class="notice notice-error is-dismissible" style="padding:15px;margin:20px 0"><p><strong>✗ ' . esc_html__('Erreur :', 'wcs-tax-retrofit') . '</strong> ' . esc_html__('La tolérance doit être un nombre entre 0.001 et 1€', 'wcs-tax-retrofit') . '</p></div>';
                $settings_has_error = true;
            }
        }

        if (!$settings_has_error) {
            echo '<div class="notice notice-success is-dismissible" style="padding:15px;margin:20px 0"><p><strong>✓ ' . esc_html__('Paramètres enregistrés', 'wcs-tax-retrofit') . '</strong></p></div>';
        }
    }
    
    if (isset($_POST['clear_csv']) && check_admin_referer('wc_tax_retrofit_clear_csv_nonce')) {
        wc_tax_retrofit_require_capability();
        
        $user_id = get_current_user_id();
        delete_transient('wc_tax_retrofit_csv_' . $user_id);
        wc_tax_retrofit_log('CSV nettoyé manuellement');
        echo '<div class="notice notice-success is-dismissible" style="padding:15px;margin:20px 0"><p><strong>✓ ' . esc_html__('CSV supprimé', 'wcs-tax-retrofit') . '</strong></p></div>';
    }
    
    echo "<div class='wrap'><h1>🔧 WooCommerce Subscription Tax Retrofit</h1>";
    echo "<p style='color:#d63638;font-weight:bold'>⚠️ " . esc_html__('Faire une sauvegarde complète avant utilisation', 'wcs-tax-retrofit') . "</p>";
    
    // Vérifier le Tax Rate ID et afficher un avertissement clair si introuvable
    $tax_rate_id_check = wc_tax_retrofit_get_tax_rate_id();

    if (!$tax_rate_id_check) {
        // Requête debug uniquement quand le taux est introuvable
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_tax_rates';
        $target = wc_tax_retrofit_get_selected_rate_percent();
        $debug_rates = $wpdb->get_results(
            "SELECT tax_rate_id, tax_rate_country, tax_rate, tax_rate_name, tax_rate_class
             FROM {$table}
             WHERE tax_rate_country = 'FR'
             ORDER BY tax_rate DESC
             LIMIT 10"
        );
        echo "<div class='notice notice-error' style='padding:20px;margin:20px 0;border-left:4px solid #d63638'>";
        echo "<h2 style='margin-top:0'>❌ " . esc_html__('Configuration requise : Taux de TVA', 'wcs-tax-retrofit') . "</h2>";
        echo "<p><strong>" . esc_html__('Le plugin ne peut pas trouver le taux de TVA dans WooCommerce.', 'wcs-tax-retrofit') . "</strong></p>";

        // Afficher ce qui existe en base
        if (!empty($debug_rates)) {
            echo "<div style='background:#fff3cd;padding:15px;margin:15px 0;border:1px solid #ffc107'>";
            echo "<h3>🔍 " . esc_html__('Taux FR trouvés dans votre base de données :', 'wcs-tax-retrofit') . "</h3>";
            echo "<table style='width:100%;border-collapse:collapse'>";
            echo "<tr style='background:#f0f0f0'>";
            echo "<th style='border:1px solid #ddd;padding:8px;text-align:left'>" . esc_html__('ID', 'wcs-tax-retrofit') . "</th>";
            echo "<th style='border:1px solid #ddd;padding:8px;text-align:left'>" . esc_html__('Pays', 'wcs-tax-retrofit') . "</th>";
            echo "<th style='border:1px solid #ddd;padding:8px;text-align:left'>" . esc_html__('Taux', 'wcs-tax-retrofit') . "</th>";
            echo "<th style='border:1px solid #ddd;padding:8px;text-align:left'>" . esc_html__('Nom', 'wcs-tax-retrofit') . "</th>";
            echo "<th style='border:1px solid #ddd;padding:8px;text-align:left'>" . esc_html__('Classe', 'wcs-tax-retrofit') . "</th>";
            echo "</tr>";
            foreach ($debug_rates as $rate) {
                $diff = abs($rate->tax_rate - $target);
                $match = $diff < 0.01;
                $style = $match ? 'background:#d4edda;font-weight:bold' : '';
                echo "<tr style='" . esc_attr($style) . "'>";
                echo "<td style='border:1px solid #ddd;padding:8px'>" . esc_html($rate->tax_rate_id) . "</td>";
                echo "<td style='border:1px solid #ddd;padding:8px'>" . esc_html($rate->tax_rate_country) . "</td>";
                echo "<td style='border:1px solid #ddd;padding:8px'>" . esc_html($rate->tax_rate) . "% " . ($match ? '✅ ' . esc_html__('CORRESPOND', 'wcs-tax-retrofit') : '(' . esc_html__('différence:', 'wcs-tax-retrofit') . ' ' . esc_html(number_format($diff, 2)) . '%)') . "</td>";
                echo "<td style='border:1px solid #ddd;padding:8px'>" . esc_html($rate->tax_rate_name) . "</td>";
                echo "<td style='border:1px solid #ddd;padding:8px'>" . esc_html($rate->tax_rate_class ?: '(standard)') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "<p style='margin-top:15px'><strong>" . esc_html__('Taux recherché :', 'wcs-tax-retrofit') . "</strong> " . esc_html($target) . "%</p>";
            echo "<p><strong>" . esc_html__('Méthode de comparaison :', 'wcs-tax-retrofit') . "</strong> ABS(taux_db - taux_recherché) < 0.01</p>";
            echo "</div>";
        } else {
            echo "<p style='color:#d63638'><strong>⚠️ " . esc_html(sprintf(__('Aucun taux FR trouvé dans la table %s', 'wcs-tax-retrofit'), $table)) . "</strong></p>";
        }
        
        echo "<h3>📋 " . esc_html__('Comment configurer un taux de TVA :', 'wcs-tax-retrofit') . "</h3>";
        echo "<ol style='margin-left:20px'>";
        echo "<li>" . sprintf(__('Allez dans %s', 'wcs-tax-retrofit'), '<strong><a href="' . admin_url('admin.php?page=wc-settings&tab=tax') . '">WooCommerce → ' . esc_html__('Réglages', 'wcs-tax-retrofit') . ' → ' . esc_html__('Taxes', 'wcs-tax-retrofit') . '</a></strong>') . "</li>";
        echo "<li>" . esc_html__('Activez les taxes si ce n\'est pas déjà fait', 'wcs-tax-retrofit') . "</li>";
        echo "<li>" . esc_html__('Cliquez sur \'Taux standard\' (ou créez une classe de taxe)', 'wcs-tax-retrofit') . "</li>";
        echo "<li>" . esc_html__('Ajoutez un taux avec :', 'wcs-tax-retrofit') . "</li>";
        echo "<ul style='margin-left:20px'>";
        echo "<li><strong>" . esc_html__('Pays :', 'wcs-tax-retrofit') . "</strong> FR (" . esc_html__('ou votre pays', 'wcs-tax-retrofit') . ")</li>";
        echo "<li><strong>" . esc_html__('Taux % :', 'wcs-tax-retrofit') . "</strong> " . esc_html($target) . "</li>";
        echo "<li><strong>" . esc_html__('Nom :', 'wcs-tax-retrofit') . "</strong> TVA (" . esc_html__('ou autre', 'wcs-tax-retrofit') . ")</li>";
        echo "</ul>";
        echo "<li>" . esc_html__('Enregistrez', 'wcs-tax-retrofit') . "</li>";
        echo "<li>" . esc_html__('Rafraîchissez cette page', 'wcs-tax-retrofit') . "</li>";
        echo "</ol>";
        echo "<p><strong>💡 " . esc_html(sprintf(__('Taux recherché actuellement : %s%% pour la France', 'wcs-tax-retrofit'), $target)) . "</strong></p>";
        echo "<p class='description'>" . esc_html__('Si votre taux est différent, vous pouvez le modifier avec le filtre', 'wcs-tax-retrofit') . " <code>wc_tax_retrofit_rate</code> (" . esc_html__('ex: 0.055 pour 5.5%', 'wcs-tax-retrofit') . ")</p>";
        echo "<hr>";
        echo "<p><strong>🔍 " . sprintf(__('Consultez les %s', 'wcs-tax-retrofit'), '<a href="' . admin_url('admin.php?page=wc-status&tab=logs') . '" target="_blank">' . esc_html__('logs WooCommerce', 'wcs-tax-retrofit') . '</a>') . "</strong> (source: wcs-tax-retrofit) " . esc_html__('pour voir :', 'wcs-tax-retrofit') . "</p>";
        echo "<ul style='margin-left:20px'>";
        echo "<li>" . esc_html__('Les taux de TVA disponibles dans votre base', 'wcs-tax-retrofit') . "</li>";
        echo "<li>" . esc_html__('Les détails de la recherche effectuée', 'wcs-tax-retrofit') . "</li>";
        echo "</ul>";
        echo "</div>";
    }
    
    if ($already_executed) {
        echo "<div class='notice notice-success' style='padding:20px;margin:20px 0'><h2>✅ " . esc_html__('Déjà exécuté', 'wcs-tax-retrofit') . "</h2>";
        echo "<p>" . esc_html__('Date :', 'wcs-tax-retrofit') . " <strong>" . esc_html(get_option('wc_tax_retrofit_date')) . "</strong></p>";
        echo "<p>" . esc_html__('Abonnements :', 'wcs-tax-retrofit') . " <strong>" . esc_html(get_option('wc_tax_retrofit_count', 0)) . "</strong></p>";
        
        $user_id = get_current_user_id();
        $csv_key = 'wc_tax_retrofit_csv_' . $user_id;
        $csv = get_transient($csv_key);
        if ($csv) {
            echo "<hr><p>📊 <strong>" . esc_html(sprintf(__('Export CSV disponible (%d lignes)', 'wcs-tax-retrofit'), count($csv))) . "</strong></p>";
            $url = wp_nonce_url(admin_url('admin.php?action=wc_tax_retrofit_export_csv'), 'wc_tax_retrofit_export_csv_nonce');
            echo "<a href='" . esc_url($url) . "' class='button button-secondary'>📥 " . esc_html__('Télécharger CSV', 'wcs-tax-retrofit') . "</a> ";

            // Bouton pour nettoyer le CSV
            echo "<form method='post' style='display:inline-block;margin-left:10px'>";
            wp_nonce_field('wc_tax_retrofit_clear_csv_nonce');
            echo "<button type='submit' name='clear_csv' class='button' onclick='return confirm(\"" . esc_js(__('Supprimer le CSV actuel ? Cela ne supprimera pas les modifications déjà effectuées sur vos abonnements.', 'wcs-tax-retrofit')) . "\")'>🗑️ " . esc_html__('Nettoyer CSV', 'wcs-tax-retrofit') . "</button>";
            echo "</form>";
        }
        
        echo "<hr><details><summary style='cursor:pointer;color:#d63638'><strong>⚠️ " . esc_html__('Réinitialiser complètement', 'wcs-tax-retrofit') . "</strong></summary>";
        echo "<div style='margin-top:15px;padding:15px;background:#fff3cd;border-left:4px solid #d63638'>";
        echo "<p><strong>⚠️ " . esc_html__('ATTENTION : Action irréversible', 'wcs-tax-retrofit') . "</strong></p>";
        echo "<p>" . esc_html__('Cette action va :', 'wcs-tax-retrofit') . "</p><ul>";
        echo "<li>❌ " . esc_html__('Effacer l\'historique d\'exécution', 'wcs-tax-retrofit') . "</li>";
        echo "<li>❌ " . esc_html__('Supprimer les données CSV', 'wcs-tax-retrofit') . "</li>";
        echo "<li>❌ " . esc_html__('Réinitialiser tous les compteurs', 'wcs-tax-retrofit') . "</li>";
        echo "<li>✅ " . esc_html__('Permettre de relancer la migration depuis le début', 'wcs-tax-retrofit') . "</li>";
        echo "</ul>";
        echo "<p><strong>" . esc_html__('À utiliser uniquement si :', 'wcs-tax-retrofit') . "</strong></p><ul>";
        echo "<li>" . esc_html__('La première migration a échoué', 'wcs-tax-retrofit') . "</li>";
        echo "<li>" . esc_html__('Vous devez retraiter les abonnements', 'wcs-tax-retrofit') . "</li>";
        echo "<li>" . esc_html__('Vous testez sur un environnement de staging', 'wcs-tax-retrofit') . "</li>";
        echo "</ul>";
        echo "<form method='post' onsubmit=\"return confirm('" . esc_js(__('CONFIRMATION FINALE : Cette action va EFFACER toutes les données de migration. Êtes-vous ABSOLUMENT certain de vouloir réinitialiser ?', 'wcs-tax-retrofit')) . "')\">";
        wp_nonce_field('wc_tax_retrofit_full_reset_nonce');
        echo "<input type='hidden' name='full_reset' value='1'>";
        echo "<button type='submit' class='button' style='color:#fff;background:#d63638;border-color:#d63638'>🔄 " . esc_html__('RÉINITIALISER COMPLÈTEMENT', 'wcs-tax-retrofit') . "</button>";
        echo "</form></div></details>";
        echo "</div></div>";
        return;
    }
    
    // Afficher l'alerte de reprise si une migration interrompue existe
    if ($can_resume) {
        $total = wc_tax_retrofit_count_subscriptions();
        $percent = $total > 0 ? round(($interrupted_offset / $total) * 100) : 0;
        
        echo "<div class='notice notice-warning' style='padding:20px;margin:20px 0;border-left:4px solid #f0b429'>";
        echo "<h3 style='margin-top:0'>🔄 " . esc_html__('Migration interrompue détectée', 'wcs-tax-retrofit') . "</h3>";
        echo "<p><strong>" . esc_html(sprintf(__('Une migration précédente a été interrompue à %1$d%% (%2$d / %3$d abonnements traités).', 'wcs-tax-retrofit'), $percent, $interrupted_offset, $total)) . "</strong></p>";
        echo "<p>" . esc_html__('Voulez-vous reprendre où vous vous étiez arrêté ?', 'wcs-tax-retrofit') . "</p>";
        
        echo "<div style='margin-top:15px'>";
        // Bouton reprendre
        echo "<form method='post' style='display:inline-block;margin-right:10px'>";
        wp_nonce_field('wc_tax_retrofit_nonce');
        echo "<input type='hidden' name='confirm_update' value='yes'>";
        echo "<input type='hidden' name='batch_offset' value='" . intval($interrupted_offset) . "'>";
        echo "<button type='submit' class='button button-primary button-large'>▶️ " . esc_html(sprintf(__('Reprendre la migration (%d%%)', 'wcs-tax-retrofit'), $percent)) . "</button>";
        echo "</form>";
        
        // Bouton recommencer
        echo "<form method='post' style='display:inline-block' onsubmit=\"return confirm('" . esc_js(sprintf(__('Êtes-vous sûr de vouloir recommencer depuis le début ? La progression actuelle (%d%%) sera perdue.', 'wcs-tax-retrofit'), $percent)) . "')\">";
        wp_nonce_field('wc_tax_retrofit_full_reset_nonce');
        echo "<input type='hidden' name='full_reset' value='1'>";
        echo "<button type='submit' class='button button-secondary'>🔄 " . esc_html__('Recommencer depuis le début', 'wcs-tax-retrofit') . "</button>";
        echo "</form>";
        echo "</div>";
        
        echo "<p style='margin-top:15px;font-size:12px;color:#666'>";
        echo esc_html(sprintf(__('Dernière activité : il y a %s', 'wcs-tax-retrofit'), human_time_diff($last_activity, time())));
        echo "</p>";
        echo "</div>";
    }
    
    if (isset($_POST['dry_run']) && check_admin_referer('wc_tax_retrofit_nonce')) {
        wc_tax_retrofit_require_capability();
        
        $offset = intval($_POST['batch_offset'] ?? 0);
        
        // Nettoyer le CSV au début d'une nouvelle simulation
        if ($offset === 0) {
            $user_id = get_current_user_id();
            $csv_key = 'wc_tax_retrofit_csv_' . $user_id;
            delete_transient($csv_key);
            wc_tax_retrofit_log('Nettoyage CSV : Nouvelle simulation détectée');
        }
        
        wc_tax_retrofit_display_results(wc_tax_retrofit_process(true, $offset), true);
        echo "<hr>";
    }
    
    if (isset($_POST['confirm_update']) && check_admin_referer('wc_tax_retrofit_nonce')) {
        wc_tax_retrofit_require_capability();
        
        $offset = intval($_POST['batch_offset'] ?? 0);
        
        // Nettoyer le CSV au début d'une nouvelle exécution complète
        if ($offset === 0) {
            $user_id = get_current_user_id();
            $csv_key = 'wc_tax_retrofit_csv_' . $user_id;
            delete_transient($csv_key);
            wc_tax_retrofit_log('Nettoyage CSV : Nouvelle exécution détectée');
        }
        
        $stats = wc_tax_retrofit_process(false, $offset);
        wc_tax_retrofit_display_results($stats, false);
        
        if (!isset($stats['has_more']) || !$stats['has_more']) {
            update_option('wc_tax_retrofit_executed', true);
            update_option('wc_tax_retrofit_date', current_time('mysql'));
            $partial = get_option('wc_tax_retrofit_partial_count', 0);
            update_option('wc_tax_retrofit_count', $partial + $stats['updated']);
            delete_option('wc_tax_retrofit_partial_count');
            
            // Nettoyer les données de reprise (migration terminée)
            delete_option('wc_tax_retrofit_current_offset');
            delete_option('wc_tax_retrofit_last_activity');
        } else {
            $partial = get_option('wc_tax_retrofit_partial_count', 0);
            update_option('wc_tax_retrofit_partial_count', $partial + $stats['updated']);
        }
        echo "</div>";
        return;
    }
    ?>
    <div style="background:#fff;padding:30px;margin:20px 0;border:1px solid #ccc">
        <div style="background:#f0f6fc;padding:20px;margin-bottom:30px;border-left:4px solid #0073aa">
            <h2 style="margin-top:0">⚙️ <?php echo esc_html__('Configuration', 'wcs-tax-retrofit'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('wc_tax_retrofit_save_date_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="tax_rate_id">💰 <?php echo esc_html__('Taux de TVA', 'wcs-tax-retrofit'); ?></label></th>
                        <td>
                            <?php
                            $all_rates = wc_tax_retrofit_get_all_tax_rates();
                            $current_rate_id = get_option('wc_tax_retrofit_selected_tax_rate_id', null);
                            
                            if (empty($all_rates)) {
                                echo '<div style="background:#ffebe8;padding:15px;border-left:4px solid #d63638">';
                                echo '<p><strong>❌ ' . esc_html__('Aucun taux de TVA configuré', 'wcs-tax-retrofit') . '</strong></p>';
                                echo '<p>' . esc_html__('Vous devez d\'abord configurer des taux de TVA dans WooCommerce :', 'wcs-tax-retrofit') . '</p>';
                                echo '<p><a href="' . admin_url('admin.php?page=wc-settings&tab=tax') . '" class="button button-secondary">⚙️ ' . esc_html__('Configurer les taux de TVA', 'wcs-tax-retrofit') . '</a></p>';
                                echo '</div>';
                            } else {
                                echo '<select name="tax_rate_id" id="tax_rate_id" class="regular-text">';
                                
                                foreach ($all_rates as $rate_id => $rate_data) {
                                    $selected = ((int) $current_rate_id === (int) $rate_id) ? 'selected' : '';
                                    echo sprintf(
                                        '<option value="%d" %s>%s</option>',
                                        esc_attr($rate_id),
                                        $selected,
                                        esc_html($rate_data['label'])
                                    );
                                }
                                
                                echo '</select>';
                                
                                echo '<p class="description">';
                                echo esc_html__('Sélectionnez le taux de TVA à appliquer lors de la migration.', 'wcs-tax-retrofit') . '<br>';
                                echo '<strong>' . esc_html__('Taux actuel :', 'wcs-tax-retrofit') . '</strong> ' . number_format(wc_tax_retrofit_get_selected_rate_percent(), 2, ',', '') . '%';
                                
                                $using_filter = (get_option('wc_tax_retrofit_selected_tax_rate_id', null) === null);
                                if ($using_filter) {
                                    echo ' <span style="color:#2196f3">(via filtre <code>wc_tax_retrofit_rate</code>)</span>';
                                }
                                
                                echo '</p>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="date_limit">📅 <?php echo esc_html__('Date limite passage TVA', 'wcs-tax-retrofit'); ?></label></th>
                        <td>
                            <input type="date" id="date_limit" name="date_limit" value="<?php echo esc_attr(wc_tax_retrofit_get_date_limit()); ?>" class="regular-text" required>
                            <p class="description">
                                <?php echo esc_html__('Les abonnements dépourvus de TVA et créés avant ou le cette date seront migrés.', 'wcs-tax-retrofit'); ?>
                            </p>
                            <p class="description">
                                <strong><?php echo esc_html__('Date actuelle :', 'wcs-tax-retrofit'); ?></strong> <?php echo esc_html(wc_tax_retrofit_get_date_limit()); ?>
                                <?php
                                $saved_date = get_option('wc_tax_retrofit_date_limit', '');
                                if (empty($saved_date)) {
                                    echo ' <span style="color:#2196f3">(' . esc_html__('détectée automatiquement', 'wcs-tax-retrofit') . ')</span>';
                                }
                                ?>
                            </p>
                            <?php
                            $first_no_tax = wc_tax_retrofit_detect_first_no_tax_date();
                            $last_no_tax = wc_tax_retrofit_detect_last_no_tax_date();
                            $current_date = wc_tax_retrofit_get_date_limit();
                            
                            // Cas 1 : Plusieurs abonnements sans TVA (premier != dernier)
                            if ($first_no_tax && $last_no_tax && $first_no_tax !== $last_no_tax) {
                                echo '<div style="background:#f0f6fc;padding:15px;margin:10px 0;border-left:4px solid #2196f3">';
                                echo '<strong>📊 ' . esc_html__('Abonnements sans TVA détectés :', 'wcs-tax-retrofit') . '</strong>';
                                echo '<ul style="margin:10px 0;padding-left:20px">';
                                echo '<li>' . esc_html__('Premier :', 'wcs-tax-retrofit') . ' <strong>' . esc_html($first_no_tax) . '</strong></li>';
                                echo '<li style="color:#2196f3"><strong>' . esc_html__('Dernier :', 'wcs-tax-retrofit') . ' ' . esc_html($last_no_tax) . '</strong></li>';
                                echo '</ul>';
                                if ($last_no_tax !== $current_date) {
                                    echo '<p style="margin:10px 0 0 0"><strong>💡 ' . esc_html__('Recommandation :', 'wcs-tax-retrofit') . '</strong> ' . esc_html(sprintf(__('Utilisez la date du dernier abonnement sans TVA (%s)', 'wcs-tax-retrofit'), $last_no_tax)) . '</p>';
                                } else {
                                    echo '<p style="margin:10px 0 0 0;color:#28a745"><strong>✅ ' . esc_html__('La date actuelle correspond au dernier abonnement sans TVA', 'wcs-tax-retrofit') . '</strong></p>';
                                }
                                echo '</div>';
                            }
                            // Cas 2 : Un seul abonnement sans TVA ET date différente de la date actuelle
                            elseif ($last_no_tax && $last_no_tax !== $current_date) {
                                echo '<div style="background:#fff3cd;padding:15px;margin:10px 0;border-left:4px solid #ffc107">';
                                echo '<p style="margin:0"><strong>⚠️ ' . esc_html__('Attention :', 'wcs-tax-retrofit') . '</strong> ' . esc_html(sprintf(__('Un abonnement sans TVA a été détecté à la date %s', 'wcs-tax-retrofit'), $last_no_tax)) . '</p>';
                                echo '<p style="margin:5px 0 0 0">' . esc_html(sprintf(__('La date actuelle (%s) est différente.', 'wcs-tax-retrofit'), $current_date)) . '</p>';
                                echo '</div>';
                            }
                            ?>
                            <?php if ($last_no_tax && $last_no_tax !== $current_date): ?>
                            <button type="button" class="button button-secondary" onclick="document.getElementById('date_limit').value='<?php echo esc_js($last_no_tax); ?>'">
                                🎯 <?php echo esc_html(sprintf(__('Utiliser la date recommandée (%s)', 'wcs-tax-retrofit'), $last_no_tax)); ?>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <button type="submit" name="save_date" class="button button-primary">💾 <?php echo esc_html__('Enregistrer', 'wcs-tax-retrofit'); ?></button>
            </form>
        </div>
        
        <div style="background:#f0f6fc;padding:20px;margin-bottom:30px;border-left:4px solid #0073aa">
            <h2 style="margin-top:0">⚙️ <?php echo esc_html__('Paramètres avancés', 'wcs-tax-retrofit'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('wc_tax_retrofit_save_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label>📋 <?php echo esc_html__('Statuts d\'abonnement à traiter', 'wcs-tax-retrofit'); ?></label></th>
                        <td>
                            <?php
                            $current_statuses = wc_tax_retrofit_get_subscription_statuses();
                            $all_statuses = array(
                                'active' => __('Actif (active)', 'wcs-tax-retrofit'),
                                'on-hold' => __('En attente (on-hold)', 'wcs-tax-retrofit'),
                                'pending-cancel' => __('Annulation en attente (pending-cancel)', 'wcs-tax-retrofit'),
                                'pending' => __('En attente de paiement (pending)', 'wcs-tax-retrofit'),
                                'cancelled' => __('Annulé (cancelled)', 'wcs-tax-retrofit'),
                                'expired' => __('Expiré (expired)', 'wcs-tax-retrofit'),
                                'switched' => __('Basculé (switched)', 'wcs-tax-retrofit')
                            );
                            
                            echo '<fieldset>';
                            foreach ($all_statuses as $status_key => $status_label) {
                                $checked = in_array($status_key, $current_statuses) ? 'checked' : '';
                                echo '<label style="display:block;margin:5px 0">';
                                echo '<input type="checkbox" name="statuses[]" value="' . esc_attr($status_key) . '" ' . esc_attr($checked) . '> ';
                                echo esc_html($status_label);
                                echo '</label>';
                            }
                            echo '</fieldset>';
                            ?>
                            <p class="description">
                                <?php echo esc_html__('Sélectionnez les statuts d\'abonnement à inclure dans la migration.', 'wcs-tax-retrofit'); ?><br>
                                <strong><?php echo esc_html__('Recommandé :', 'wcs-tax-retrofit'); ?></strong> active, on-hold, pending-cancel<br>
                                <small><?php echo esc_html__('Conseil : N\'incluez pas "cancelled" ou "expired" sauf si nécessaire.', 'wcs-tax-retrofit'); ?></small>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tolerance">📏 <?php echo esc_html__('Tolérance d\'arrondi (€)', 'wcs-tax-retrofit'); ?></label></th>
                        <td>
                            <?php $current_tolerance = wc_tax_retrofit_get_tolerance(); ?>
                            <input type="number" id="tolerance" name="tolerance" value="<?php echo esc_attr($current_tolerance); ?>" 
                                   min="0.001" max="1" step="0.001" class="small-text" required>
                            <p class="description">
                                <?php echo esc_html__('Écart maximum accepté entre le total TTC original et le recalculé (HT + TVA).', 'wcs-tax-retrofit'); ?><br>
                                <strong><?php echo esc_html__('Valeur actuelle :', 'wcs-tax-retrofit'); ?></strong> <?php echo number_format($current_tolerance, 3); ?>€<br>
                                <strong><?php echo esc_html__('Valeur par défaut :', 'wcs-tax-retrofit'); ?></strong> 0.01€ (<?php echo esc_html__('1 centime', 'wcs-tax-retrofit'); ?>)<br>
                                <strong><?php echo esc_html__('Plage valide :', 'wcs-tax-retrofit'); ?></strong> 0.001€ <?php echo esc_html__('à', 'wcs-tax-retrofit'); ?> 1€<br>
                                <small>💡 <?php echo esc_html__('1 centime (0.01€) convient à la plupart des cas.', 'wcs-tax-retrofit'); ?></small>
                            </p>
                        </td>
                    </tr>
                </table>
                <button type="submit" name="save_settings" class="button button-primary">💾 <?php echo esc_html__('Enregistrer les paramètres', 'wcs-tax-retrofit'); ?></button>
                <p class="description" style="margin-top:10px">
                    <strong><?php echo esc_html__('Note :', 'wcs-tax-retrofit'); ?></strong> <?php echo esc_html__('Les filtres PHP sont toujours disponibles pour les configurations avancées. Les valeurs ici configurées ont la priorité.', 'wcs-tax-retrofit'); ?>
                </p>
            </form>
        </div>
        
        <div style="background:#fffbea;padding:20px;margin-bottom:30px;border-left:4px solid #f0b429">
            <h3 style="margin-top:0">📊 <?php echo esc_html__('Abonnements concernés', 'wcs-tax-retrofit'); ?></h3>
            <p style="font-size:18px;margin:10px 0">
                <strong id="subscription-count" style="font-size:24px;color:#2271b1">
                    <span class="spinner is-active" style="float:none;margin:0 10px 0 0"></span>
                    <?php echo esc_html__('Calcul en cours...', 'wcs-tax-retrofit'); ?>
                </strong>
            </p>
            <p class="description"><?php echo esc_html__('Statuts :', 'wcs-tax-retrofit'); ?> <code><?php echo implode('</code>, <code>', wc_tax_retrofit_get_subscription_statuses()); ?></code> <?php echo esc_html(sprintf(__('créés jusqu\'au %s', 'wcs-tax-retrofit'), wc_tax_retrofit_get_date_limit())); ?></p>
            <p class="description"><small>(<?php echo esc_html__('modifiable via filtre', 'wcs-tax-retrofit'); ?> <code>wc_tax_retrofit_subscription_statuses</code>)</small></p>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wc_tax_retrofit_count',
                    nonce: '<?php echo wp_create_nonce('wc_tax_retrofit_count_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        var count = response.data.count;
                        var html = '<span style="color:' + (count > 0 ? '#2271b1' : '#d63638') + '">' + count + '</span> <?php echo esc_js(__('abonnement(s)', 'wcs-tax-retrofit')); ?>';
                        $('#subscription-count').html(html);
                    } else {
                        $('#subscription-count').html('<span style="color:#d63638"><?php echo esc_js(__('Erreur', 'wcs-tax-retrofit')); ?></span>');
                    }
                },
                error: function() {
                    $('#subscription-count').html('<span style="color:#d63638"><?php echo esc_js(__('Erreur', 'wcs-tax-retrofit')); ?></span>');
                }
            });
        });
        </script>
        
        <h2>📋 <?php echo esc_html__('Fonctionnement', 'wcs-tax-retrofit'); ?></h2>
        <?php
        $display_rate = wc_tax_retrofit_get_selected_rate();
        $display_rate_percent = wc_tax_retrofit_get_selected_rate_percent();
        $display_divisor = 1 + $display_rate;
        ?>
        <div style="background:#e7f5fe;padding:20px;margin:15px 0;border-left:4px solid #2196f3">
            <h3>🔢 <?php echo esc_html__('Recalcul', 'wcs-tax-retrofit'); ?></h3>
            <p><strong><?php echo esc_html(sprintf(__('Pour chaque abonnement créé jusqu\'au %s :', 'wcs-tax-retrofit'), wc_tax_retrofit_get_date_limit())); ?></strong></p>
            <ol><li><?php echo esc_html__('Montant HT = Total TTC ÷', 'wcs-tax-retrofit'); ?> <?php echo $display_divisor; ?></li><li><?php echo esc_html__('TVA = Total TTC - Montant HT', 'wcs-tax-retrofit'); ?></li><li><?php echo esc_html__('Total TTC reste identique', 'wcs-tax-retrofit'); ?></li></ol>
            <p><strong><?php echo esc_html(sprintf(__('Exemple (TVA %s%%) :', 'wcs-tax-retrofit'), $display_rate_percent)); ?></strong> 30€ → <?php echo number_format(30 / $display_divisor, 2); ?>€ HT + <?php echo number_format(30 - (30 / $display_divisor), 2); ?>€ TVA = <strong>30€ TTC ✅</strong></p>
        </div>
        
        <div style="background:#fff3cd;padding:20px;margin:15px 0;border-left:4px solid #ffc107">
            <h3>⚙️ <?php echo esc_html__('Tolérance d\'arrondi', 'wcs-tax-retrofit'); ?></h3>
            <p><strong><?php echo esc_html__('Valeur actuelle :', 'wcs-tax-retrofit'); ?></strong> <?php echo wc_tax_retrofit_get_tolerance(); ?>€</p>
            <p><?php echo esc_html__('La tolérance définit l\'écart maximum accepté entre le total TTC original et le recalculé (HT + TVA).', 'wcs-tax-retrofit'); ?></p>
            <p>💡 <strong><?php echo esc_html__('Pour modifier :', 'wcs-tax-retrofit'); ?></strong> <?php echo esc_html__('Utilisez la section "Paramètres avancés" ci-dessus.', 'wcs-tax-retrofit'); ?></p>
            
            <div style="background:white;padding:15px;margin:10px 0">
                <h4 style="margin-top:0">📌 <?php echo esc_html__('Cas où ajuster la tolérance :', 'wcs-tax-retrofit'); ?></h4>
                <ul style="margin:5px 0 5px 20px">
                    <li><?php echo esc_html__('Certaines passerelles de paiement ou imports peuvent générer des écarts > 0.01€', 'wcs-tax-retrofit'); ?></li>
                    <li><?php echo esc_html__('Données migrées d\'autres systèmes avec arrondis différents', 'wcs-tax-retrofit'); ?></li>
                    <li><?php echo esc_html__('Abonnements créés en devises étrangères puis convertis', 'wcs-tax-retrofit'); ?></li>
                    <li><?php echo esc_html__('Plus le montant est grand, plus les arrondis cumulés peuvent être importants', 'wcs-tax-retrofit'); ?></li>
                </ul>
            </div>
            
            <div style="background:#ffebe8;padding:15px;margin:10px 0">
                <h4 style="margin-top:0">⚠️ <?php echo esc_html__('Attention :', 'wcs-tax-retrofit'); ?></h4>
                <p><?php echo esc_html__('Une tolérance trop élevée peut masquer de véritables erreurs de calcul. Ne l\'augmentez que si vous comprenez l\'origine des écarts.', 'wcs-tax-retrofit'); ?></p>
                <p><small><strong><?php echo esc_html__('Note pour développeurs :', 'wcs-tax-retrofit'); ?></strong> <?php echo esc_html__('Le filtre reste disponible mais la valeur configurée via l\'interface a la priorité.', 'wcs-tax-retrofit'); ?></small></p>
            </div>
        </div>
        
        <h3>✓ <?php echo esc_html__('Actions', 'wcs-tax-retrofit'); ?></h3>
        <ul>
            <li>✓ <?php echo esc_html(sprintf(__('Identifie abonnements %1$s créés jusqu\'au %2$s', 'wcs-tax-retrofit'), implode(', ', wc_tax_retrofit_get_subscription_statuses()), wc_tax_retrofit_get_date_limit())); ?></li>
            <li>✓ <?php echo esc_html__('Supprime/recrée chaque ligne avec HT et TVA', 'wcs-tax-retrofit'); ?></li>
            <li>✓ <?php echo esc_html__('Préserve métadonnées', 'wcs-tax-retrofit'); ?></li>
            <li>✓ <?php echo esc_html__('Utilise tax_rate_id (Stripe compatible)', 'wcs-tax-retrofit'); ?></li>
            <li>✓ <?php echo esc_html(sprintf(__('Vérifie total TTC identique (tolérance : %s€)', 'wcs-tax-retrofit'), wc_tax_retrofit_get_tolerance())); ?></li>
            <li>✓ <?php echo esc_html__('Logs + notes sur abonnements', 'wcs-tax-retrofit'); ?></li>
            <li>✓ <?php echo esc_html__('Alertes détaillées en cas d\'écarts de tolérance', 'wcs-tax-retrofit'); ?></li>
        </ul>
        
        <div style="background:#e7f5fe;padding:20px;margin:20px 0;border-left:4px solid #2196f3">
            <h3>🔍 <?php echo esc_html__('SIMULATION', 'wcs-tax-retrofit'); ?></h3>
            <p><?php echo esc_html(sprintf(__('Testez sans modifier la base. Lots de %d abonnements.', 'wcs-tax-retrofit'), WC_TAX_RETROFIT_BATCH_SIZE)); ?></p>
            <form method="post">
                <?php wp_nonce_field('wc_tax_retrofit_nonce'); ?>
                <input type="hidden" name="dry_run" value="yes">
                <button type="submit" class="button button-secondary button-large">🔍 <?php echo esc_html__('SIMULATION', 'wcs-tax-retrofit'); ?></button>
            </form>
        </div>
        
        <div style="background:#ffebe8;padding:20px;margin:20px 0;border-left:4px solid #d63638">
            <h3>🚀 <?php echo esc_html__('EXÉCUTION RÉELLE', 'wcs-tax-retrofit'); ?></h3>
            <p><strong>⚠️ <?php echo esc_html__('Modifiera définitivement la base de données !', 'wcs-tax-retrofit'); ?></strong></p>
            
            <div style="background:#fff3cd;padding:15px;margin:15px 0;border-left:4px solid #ffc107">
                <h4 style="margin-top:0">✅ <?php echo esc_html__('AVANT DE LANCER, VÉRIFIEZ :', 'wcs-tax-retrofit'); ?></h4>
                <ol style="font-weight:bold;margin:10px 0 10px 20px">
                    <li>✅ <?php echo esc_html__('Sauvegarde COMPLÈTE de la base de données', 'wcs-tax-retrofit'); ?></li>
                    <li>✅ <?php echo esc_html__('Simulation lancée ci-dessus (sans erreur)', 'wcs-tax-retrofit'); ?></li>
                    <li>✅ <?php echo esc_html(sprintf(__('Date vérifiée (%s)', 'wcs-tax-retrofit'), wc_tax_retrofit_get_date_limit())); ?></li>
                    <li>✅ <?php echo esc_html__('Aucune alerte de tolérance n\'apparaît', 'wcs-tax-retrofit'); ?></li>
                    <li>✅ <?php echo esc_html__('Test sur environnement de staging (si possible)', 'wcs-tax-retrofit'); ?></li>
                </ol>
            </div>
            
            <form method="post" onsubmit="return confirm('<?php echo esc_js(__('CONFIRMATION FINALE : Sauvegarde complète faite ? Simulation lancée sans erreur ? Aucune alerte de tolérance ? Date vérifiée ? Cette action va MODIFIER votre base de données. Cliquez OK pour confirmer.', 'wcs-tax-retrofit')); ?>')">
                <?php wp_nonce_field('wc_tax_retrofit_nonce'); ?>
                <input type="hidden" name="confirm_update" value="yes">
                <button type="submit" class="button button-primary button-hero" style="background:#d63638;border-color:#d63638">⚠️ <?php echo esc_html__('LANCER LA MIGRATION', 'wcs-tax-retrofit'); ?></button>
            </form>
        </div>
        
        <div style="background:#f0f0f0;padding:15px;margin:20px 0;font-size:11px;color:#666">
            <p><strong><?php echo esc_html__('Infos techniques :', 'wcs-tax-retrofit'); ?></strong></p>
            <ul style="margin:0">
                <li><?php echo esc_html__('Version :', 'wcs-tax-retrofit'); ?> <?php echo esc_html(WC_TAX_RETROFIT_VERSION); ?></li>
                <li><?php echo esc_html__('Auteur :', 'wcs-tax-retrofit'); ?> <a href="https://paul.argoud.net" target="_blank">Paul ARGOUD</a></li>
                <li><?php echo esc_html__('Date limite :', 'wcs-tax-retrofit'); ?> <?php echo esc_html(wc_tax_retrofit_get_date_limit()); ?> <small>(<?php echo esc_html__('modifiable', 'wcs-tax-retrofit'); ?>)</small></li>
                <li><?php echo esc_html__('TVA :', 'wcs-tax-retrofit'); ?> <?php echo number_format(wc_tax_retrofit_get_selected_rate_percent(), 2, ',', ''); ?>% <small>(<?php
                    $saved_rate_id = get_option('wc_tax_retrofit_selected_tax_rate_id', null);
                    if ($saved_rate_id !== null) {
                        echo esc_html(sprintf(__('ID taux: %d, configuré via interface', 'wcs-tax-retrofit'), $saved_rate_id));
                    } else {
                        echo esc_html__('filtre', 'wcs-tax-retrofit') . ' <code>wc_tax_retrofit_rate</code>';
                    }
                ?>)</small></li>
                <li><?php echo esc_html__('Lots :', 'wcs-tax-retrofit'); ?> <?php echo WC_TAX_RETROFIT_BATCH_SIZE; ?></li>
                <li><?php echo esc_html__('Tolérance :', 'wcs-tax-retrofit'); ?> <?php echo wc_tax_retrofit_get_tolerance(); ?>€ <small>(<?php
                    $saved = get_option('wc_tax_retrofit_tolerance_setting', null);
                    echo esc_html($saved !== null ? __('configurée via interface', 'wcs-tax-retrofit') : __('valeur par défaut ou filtre', 'wcs-tax-retrofit'));
                ?>)</small></li>
                <li><?php echo esc_html__('Statuts :', 'wcs-tax-retrofit'); ?> <?php
                    $statuses = wc_tax_retrofit_get_subscription_statuses();
                    echo implode(', ', $statuses);
                ?> <small>(<?php
                    $saved_statuses = get_option('wc_tax_retrofit_statuses', null);
                    echo esc_html($saved_statuses !== null ? __('configurés via interface', 'wcs-tax-retrofit') : __('valeurs par défaut ou filtre', 'wcs-tax-retrofit'));
                ?>)</small></li>
                <li>Tax Rate ID : <?php
                    $tid = $tax_rate_id_check;
                    if ($tid) {
                        echo esc_html($tid) . ' ✅';
                    } else {
                        echo '<span style="color:#d63638;font-weight:bold">' . esc_html__('Non trouvé', 'wcs-tax-retrofit') . ' ⚠️</span>';
                        echo '<br><small style="color:#d63638">→ ' . esc_html__('Configurez un taux de TVA dans', 'wcs-tax-retrofit') . ' <a href="' . admin_url('admin.php?page=wc-settings&tab=tax') . '" target="_blank" style="color:#d63638;text-decoration:underline">WooCommerce → ' . esc_html__('Réglages', 'wcs-tax-retrofit') . ' → ' . esc_html__('Taxes', 'wcs-tax-retrofit') . '</a></small>';
                        echo '<br><small style="color:#666">→ ' . esc_html(sprintf(__('Taux recherché : %s%%', 'wcs-tax-retrofit'), $target)) . ' (' . esc_html__('modifiable via filtre', 'wcs-tax-retrofit') . ' <code>wc_tax_retrofit_rate</code>)</small>';
                        echo '<br><small style="color:#666">→ ' . sprintf(__('Consultez les %s pour voir les taux disponibles', 'wcs-tax-retrofit'), '<a href="' . admin_url('admin.php?page=wc-status&tab=logs') . '" target="_blank">logs</a>') . '</small>';
                    }
                ?></li>
                <li>📋 <a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=logs')); ?>" target="_blank"><?php echo esc_html__('Logs WooCommerce', 'wcs-tax-retrofit'); ?></a> (source: wcs-tax-retrofit)</li>
                <li>PHP : <?php echo PHP_VERSION; ?></li>
                <li>WooCommerce : <?php echo defined('WC_VERSION') ? WC_VERSION : 'N/A'; ?></li>
            </ul>
        </div>
    </div>
    <?php
    echo "</div>";
}

function wc_tax_retrofit_activation_notice(): void {
    // Ne pas afficher la notice si les dépendances manquent
    // (la notice d'erreur de dépendances sera affichée à la place)
    if (!wc_tax_retrofit_check_dependencies()) {
        delete_transient('wc_tax_retrofit_activation_notice');
        return;
    }
    
    if (get_transient('wc_tax_retrofit_activation_notice')) {
        echo '<div class="notice notice-info is-dismissible"><p><strong>';
        echo esc_html__('WooCommerce Subscription Tax Retrofit activé !', 'wcs-tax-retrofit');
        echo '</strong></p>';
        echo '<p>';
        printf(
            /* translators: %s: menu path */
            esc_html__('Allez dans %s', 'wcs-tax-retrofit'),
            '<strong>' . esc_html__('WooCommerce → Tax Retrofit', 'wcs-tax-retrofit') . '</strong>'
        );
        echo '</p>';
        echo '<p style="color:#d63638">⚠️ ';
        echo esc_html__('Faites une sauvegarde complète avant toute migration.', 'wcs-tax-retrofit');
        echo '</p></div>';
        delete_transient('wc_tax_retrofit_activation_notice');
    }
}
add_action('admin_notices', 'wc_tax_retrofit_activation_notice');

function wc_tax_retrofit_activate(): void {
    set_transient('wc_tax_retrofit_activation_notice', true, 60);
    wc_tax_retrofit_log('Plugin activé v' . WC_TAX_RETROFIT_VERSION);
}
register_activation_hook(__FILE__, 'wc_tax_retrofit_activate');

function wc_tax_retrofit_deactivate(): void {
    wc_tax_retrofit_log('Plugin désactivé - Nettoyage');
    delete_transient('wc_tax_retrofit_running');
    delete_transient('wc_tax_retrofit_detect_date_desc');
    delete_transient('wc_tax_retrofit_detect_date_asc');
    delete_option('wc_tax_retrofit_partial_count');
    delete_option('wc_tax_retrofit_current_offset');
    delete_option('wc_tax_retrofit_last_activity');
    delete_transient('wc_tax_retrofit_activation_notice');
    
    global $wpdb;
    $like_csv = $wpdb->esc_like('_transient_wc_tax_retrofit_csv_') . '%';
    $like_timeout = $wpdb->esc_like('_transient_timeout_wc_tax_retrofit_csv_') . '%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_csv));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout));

    wc_tax_retrofit_log('Nettoyage terminé');
}
register_deactivation_hook(__FILE__, 'wc_tax_retrofit_deactivate');

/**
 * ============================================================================
 * WP-CLI COMMANDS
 * ============================================================================
 * Permet d'utiliser le plugin via ligne de commande
 * 
 * Exemples d'utilisation :
 * 
 * # Lister les paramètres actuels
 * wp tax-retrofit config
 * 
 * # Configurer le taux de TVA
 * wp tax-retrofit config --tax-rate=20
 * 
 * # Configurer la date limite
 * wp tax-retrofit config --date-limit=2024-01-01
 * 
 * # Lancer une simulation
 * wp tax-retrofit simulate
 * 
 * # Lancer une simulation avec paramètres personnalisés
 * wp tax-retrofit simulate --tax-rate=5.5 --date-limit=2024-06-01 --tolerance=0.02
 * 
 * # Lancer la migration (après simulation)
 * wp tax-retrofit migrate --yes-i-have-a-backup
 * 
 * # Afficher les statistiques
 * wp tax-retrofit stats
 * 
 * # Réinitialiser la migration
 * wp tax-retrofit reset
 */

if (defined('WP_CLI') && WP_CLI) {
    
    class WC_Tax_Retrofit_CLI_Command {
        
        /**
         * Affiche ou modifie la configuration du plugin
         * 
         * ## OPTIONS
         * 
         * [--tax-rate=<percent>]
         * : Taux de TVA en pourcentage (ex: 20 pour 20%)
         * 
         * [--date-limit=<date>]
         * : Date limite au format YYYY-MM-DD
         * 
         * [--tolerance=<amount>]
         * : Tolérance d'arrondi en euros (ex: 0.01)
         * 
         * [--statuses=<statuses>]
         * : Statuts d'abonnements à traiter, séparés par des virgules
         * 
         * ## EXAMPLES
         * 
         *     wp tax-retrofit config
         *     wp tax-retrofit config --tax-rate=20
         *     wp tax-retrofit config --date-limit=2024-01-01
         *     wp tax-retrofit config --tolerance=0.02
         */
        public function config($args, $assoc_args) {
            // Vérifier les dépendances
            if (!wc_tax_retrofit_check_dependencies()) {
                WP_CLI::error(__('Dépendances manquantes : WooCommerce et/ou WooCommerce Subscriptions', 'wcs-tax-retrofit'));
            }
            
            // Si des arguments sont fournis, mettre à jour la configuration
            $updated = false;
            
            if (isset($assoc_args['tax-rate'])) {
                $rate = floatval($assoc_args['tax-rate']) / 100;
                if ($rate > 0 && $rate <= 1) {
                    // Trouver le tax_rate_id correspondant (match approximatif comme wc_tax_retrofit_get_tax_rate_id)
                    global $wpdb;
                    $tax_rate_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates
                         WHERE ABS(tax_rate - %f) < 0.01 LIMIT 1",
                        $rate * 100
                    ));
                    
                    if ($tax_rate_id) {
                        update_option('wc_tax_retrofit_selected_tax_rate_id', $tax_rate_id);
                        WP_CLI::success(sprintf(__('Taux de TVA configuré : %s%%', 'wcs-tax-retrofit'), $assoc_args['tax-rate']));
                        $updated = true;
                    } else {
                        WP_CLI::warning(sprintf(__('Taux %s%% non trouvé dans WooCommerce', 'wcs-tax-retrofit'), $assoc_args['tax-rate']));
                    }
                } else {
                    WP_CLI::error(__('Taux invalide (doit être entre 0.1 et 100)', 'wcs-tax-retrofit'));
                }
            }
            
            if (isset($assoc_args['date-limit'])) {
                $date = wc_tax_retrofit_validate_date($assoc_args['date-limit']);
                if ($date) {
                    update_option('wc_tax_retrofit_date_limit', $date);
                    WP_CLI::success(sprintf(__('Date limite configurée : %s', 'wcs-tax-retrofit'), $date));
                    $updated = true;
                } else {
                    WP_CLI::error(__('Date invalide (format requis : YYYY-MM-DD)', 'wcs-tax-retrofit'));
                }
            }
            
            if (isset($assoc_args['tolerance'])) {
                $tolerance = wc_tax_retrofit_validate_float($assoc_args['tolerance'], 0.001, 1.0);
                if ($tolerance !== false) {
                    update_option('wc_tax_retrofit_tolerance_setting', $tolerance);
                    WP_CLI::success(sprintf(__('Tolérance configurée : %s€', 'wcs-tax-retrofit'), $tolerance));
                    $updated = true;
                } else {
                    WP_CLI::error(__('Tolérance invalide (doit être entre 0.001 et 1.0)', 'wcs-tax-retrofit'));
                }
            }
            
            if (isset($assoc_args['statuses'])) {
                $statuses = array_map('trim', explode(',', $assoc_args['statuses']));
                $validated_statuses = wc_tax_retrofit_validate_statuses($statuses, array());
                if (!empty($validated_statuses)) {
                    update_option('wc_tax_retrofit_statuses', $validated_statuses);
                    WP_CLI::success(sprintf(__('Statuts configurés : %s', 'wcs-tax-retrofit'), implode(', ', $validated_statuses)));
                    $updated = true;
                } else {
                    WP_CLI::error(__('Aucun statut valide fourni. Valides : active, on-hold, pending-cancel, pending, cancelled, expired, switched', 'wcs-tax-retrofit'));
                }
            }
            
            // Afficher la configuration actuelle
            if (!$updated) {
                WP_CLI::line('');
                WP_CLI::line(__('Configuration actuelle :', 'wcs-tax-retrofit'));
                WP_CLI::line('');
            }
            
            $rate_percent = wc_tax_retrofit_get_selected_rate_percent();
            $date_limit = wc_tax_retrofit_get_date_limit();
            $tolerance = wc_tax_retrofit_get_tolerance();
            $statuses = wc_tax_retrofit_get_subscription_statuses();
            
            WP_CLI::line(sprintf('  ' . __('Taux de TVA', 'wcs-tax-retrofit') . ' : %s%%', $rate_percent));
            WP_CLI::line(sprintf('  ' . __('Date limite', 'wcs-tax-retrofit') . ' : %s', $date_limit));
            WP_CLI::line(sprintf('  ' . __('Tolérance', 'wcs-tax-retrofit') . '   : %s€', $tolerance));
            WP_CLI::line(sprintf('  ' . __('Statuts', 'wcs-tax-retrofit') . '     : %s', implode(', ', $statuses)));
            WP_CLI::line('');
        }
        
        /**
         * Lance une simulation de migration
         * 
         * ## OPTIONS
         * 
         * [--tax-rate=<percent>]
         * : Taux de TVA temporaire pour cette simulation
         * 
         * [--date-limit=<date>]
         * : Date limite temporaire pour cette simulation
         * 
         * [--tolerance=<amount>]
         * : Tolérance temporaire pour cette simulation
         * 
         * [--json]
         * : Sortie au format JSON
         * 
         * ## EXAMPLES
         * 
         *     wp tax-retrofit simulate
         *     wp tax-retrofit simulate --tax-rate=5.5
         *     wp tax-retrofit simulate --json
         */
        public function simulate($args, $assoc_args) {
            // Vérifier les dépendances
            if (!wc_tax_retrofit_check_dependencies()) {
                WP_CLI::error(__('Dépendances manquantes', 'wcs-tax-retrofit'));
            }

            // Sauvegarder et restaurer les options temporaires
            $restore = array();

            // --tax-rate : override via option DB (le filtre est ignoré si une option DB existe)
            if (isset($assoc_args['tax-rate'])) {
                $rate = floatval($assoc_args['tax-rate']) / 100;
                if ($rate > 0 && $rate <= 1) {
                    global $wpdb;
                    $tax_rate_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates
                         WHERE ABS(tax_rate - %f) < 0.01 LIMIT 1",
                        $rate * 100
                    ));
                    if ($tax_rate_id) {
                        $restore['wc_tax_retrofit_selected_tax_rate_id'] = get_option('wc_tax_retrofit_selected_tax_rate_id', null);
                        update_option('wc_tax_retrofit_selected_tax_rate_id', $tax_rate_id);
                    } else {
                        WP_CLI::warning(sprintf(__('Taux %s%% non trouvé dans WooCommerce, utilisation du taux configuré', 'wcs-tax-retrofit'), $assoc_args['tax-rate']));
                    }
                }
            }

            if (isset($assoc_args['date-limit'])) {
                $date = wc_tax_retrofit_validate_date($assoc_args['date-limit']);
                if ($date) {
                    $restore['wc_tax_retrofit_date_limit'] = get_option('wc_tax_retrofit_date_limit', null);
                    update_option('wc_tax_retrofit_date_limit', $date);
                }
            }

            if (isset($assoc_args['tolerance'])) {
                $tolerance = wc_tax_retrofit_validate_float($assoc_args['tolerance'], 0.001, 1.0);
                if ($tolerance !== false) {
                    $restore['wc_tax_retrofit_tolerance_setting'] = get_option('wc_tax_retrofit_tolerance_setting', null);
                    update_option('wc_tax_retrofit_tolerance_setting', $tolerance);
                }
            }

            WP_CLI::line(__('Lancement de la simulation...', 'wcs-tax-retrofit'));
            WP_CLI::line('');

            // Lancer la simulation par lots (comme migrate)
            $total_updated = 0;
            $total_skipped = 0;
            $total_errors = 0;
            $total_tolerance_warnings = array();
            $total_details = array();
            $total_csv_data = array();
            $total_errors_list = array();
            $all_stats = null;
            $offset = 0;

            do {
                $stats = wc_tax_retrofit_process(true, $offset);

                if (isset($stats['locked']) && $stats['locked']) {
                    WP_CLI::error($stats['message']);
                }

                $total_updated += $stats['updated'];
                $total_skipped += $stats['skipped'];
                $total_errors += $stats['errors'];
                if (!empty($stats['tolerance_warnings'])) {
                    $total_tolerance_warnings = [...$total_tolerance_warnings, ...$stats['tolerance_warnings']];
                }
                if (!empty($stats['details'])) {
                    $total_details = [...$total_details, ...$stats['details']];
                }
                if (!empty($stats['csv_data'])) {
                    $total_csv_data = [...$total_csv_data, ...$stats['csv_data']];
                }
                if (!empty($stats['errors_list'])) {
                    $total_errors_list = [...$total_errors_list, ...$stats['errors_list']];
                }
                $all_stats = $stats;

                if ($stats['batch_size'] > 0) {
                    WP_CLI::line(sprintf(
                        __('Batch offset %d : %d à migrer, %d ignorés, %d erreurs', 'wcs-tax-retrofit'),
                        $offset, $stats['updated'], $stats['skipped'], $stats['errors']
                    ));
                }

                $offset += WC_TAX_RETROFIT_BATCH_SIZE;
            } while (!empty($stats['has_more']));

            // Restaurer les options temporaires
            foreach ($restore as $key => $value) {
                if ($value === null) {
                    delete_option($key);
                } else {
                    update_option($key, $value);
                }
            }

            // Afficher les résultats
            if (isset($assoc_args['json'])) {
                $all_stats['updated'] = $total_updated;
                $all_stats['skipped'] = $total_skipped;
                $all_stats['errors'] = $total_errors;
                $all_stats['tolerance_warnings'] = $total_tolerance_warnings;
                $all_stats['details'] = $total_details;
                $all_stats['csv_data'] = $total_csv_data;
                $all_stats['errors_list'] = $total_errors_list;
                WP_CLI::line(json_encode($all_stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                WP_CLI::line('');
                WP_CLI::success(__('Simulation terminée', 'wcs-tax-retrofit'));
                WP_CLI::line('');
                WP_CLI::line(sprintf('  ✓ ' . __('À migrer', 'wcs-tax-retrofit') . '    : %d', $total_updated));
                WP_CLI::line(sprintf('  ⊗ ' . __('Ignorés', 'wcs-tax-retrofit') . '     : %d', $total_skipped));
                WP_CLI::line(sprintf('  ✗ ' . __('Erreurs', 'wcs-tax-retrofit') . '     : %d', $total_errors));

                if (!empty($total_tolerance_warnings)) {
                    WP_CLI::line('');
                    WP_CLI::warning(sprintf(__('%d alertes de tolérance', 'wcs-tax-retrofit'), count($total_tolerance_warnings)));
                }

                WP_CLI::line('');
            }
        }
        
        /**
         * Lance la migration réelle (DANGER!)
         * 
         * ## OPTIONS
         * 
         * --yes-i-have-a-backup
         * : Confirmation que vous avez fait une sauvegarde (OBLIGATOIRE)
         *
         * [--offset=<offset>]
         * : Offset de départ (pour reprise)
         *
         * [--skip-confirm]
         * : Sauter la confirmation interactive (pour scripts/cron)
         *
         * ## EXAMPLES
         *
         *     wp tax-retrofit migrate --yes-i-have-a-backup
         *     wp tax-retrofit migrate --yes-i-have-a-backup --skip-confirm
         */
        public function migrate($args, $assoc_args) {
            // Vérifier les dépendances
            if (!wc_tax_retrofit_check_dependencies()) {
                WP_CLI::error(__('Dépendances manquantes', 'wcs-tax-retrofit'));
            }
            
            // Vérifier la confirmation de sauvegarde
            if (!isset($assoc_args['yes-i-have-a-backup'])) {
                WP_CLI::error(__('ARRÊT : Vous devez confirmer avoir fait une sauvegarde avec --yes-i-have-a-backup', 'wcs-tax-retrofit'));
            }
            
            $offset = intval($assoc_args['offset'] ?? 0);
            
            WP_CLI::warning('⚠️  ' . __('MIGRATION RÉELLE - LES DONNÉES VONT ÊTRE MODIFIÉES', 'wcs-tax-retrofit'));
            WP_CLI::line('');
            
            // Demander confirmation supplémentaire en mode interactif
            if (!isset($assoc_args['skip-confirm'])) {
                fwrite(STDOUT, __('Êtes-vous ABSOLUMENT SÛR ? Tapez "OUI" pour continuer : ', 'wcs-tax-retrofit'));
                $confirmation = trim(fgets(STDIN));
                
                if ($confirmation !== 'OUI') {
                    WP_CLI::error(__('Migration annulée', 'wcs-tax-retrofit'));
                }
            }
            
            WP_CLI::line(__('Lancement de la migration...', 'wcs-tax-retrofit'));
            WP_CLI::line('');
            
            // Lancer la migration par lots avec continuation automatique
            $total_updated = 0;
            $total_skipped = 0;
            $total_errors = 0;

            do {
                $stats = wc_tax_retrofit_process(false, $offset);

                if (isset($stats['locked']) && $stats['locked']) {
                    WP_CLI::error($stats['message']);
                }

                $total_updated += $stats['updated'];
                $total_skipped += $stats['skipped'];
                $total_errors += $stats['errors'];

                WP_CLI::line(sprintf(
                    __('Batch offset %d : %d mis à jour, %d ignorés, %d erreurs', 'wcs-tax-retrofit'),
                    $offset, $stats['updated'], $stats['skipped'], $stats['errors']
                ));

                if (!empty($stats['tolerance_warnings'])) {
                    WP_CLI::warning(sprintf(__('%d alertes de tolérance', 'wcs-tax-retrofit'), count($stats['tolerance_warnings'])));
                }

                $offset += WC_TAX_RETROFIT_BATCH_SIZE;
            } while (!empty($stats['has_more']));

            // Sauvegarder l'état d'exécution (comme la version web)
            update_option('wc_tax_retrofit_executed', true);
            update_option('wc_tax_retrofit_date', current_time('mysql'));
            update_option('wc_tax_retrofit_count', $total_updated);
            delete_option('wc_tax_retrofit_partial_count');
            delete_option('wc_tax_retrofit_current_offset');
            delete_option('wc_tax_retrofit_last_activity');

            // Afficher les résultats finaux
            WP_CLI::line('');
            WP_CLI::success(__('Migration terminée', 'wcs-tax-retrofit'));
            WP_CLI::line('');
            WP_CLI::line(sprintf('  ✓ ' . __('Mis à jour', 'wcs-tax-retrofit') . '  : %d', $total_updated));
            WP_CLI::line(sprintf('  ⊗ ' . __('Ignorés', 'wcs-tax-retrofit') . '     : %d', $total_skipped));
            WP_CLI::line(sprintf('  ✗ ' . __('Erreurs', 'wcs-tax-retrofit') . '     : %d', $total_errors));
            WP_CLI::line('');
        }
        
        /**
         * Affiche les statistiques de migration
         * 
         * ## EXAMPLES
         * 
         *     wp tax-retrofit stats
         */
        public function stats($args, $assoc_args) {
            // Vérifier les dépendances
            if (!wc_tax_retrofit_check_dependencies()) {
                WP_CLI::error(__('Dépendances manquantes', 'wcs-tax-retrofit'));
            }
            
            WP_CLI::line('');
            WP_CLI::line(__('Statistiques de migration :', 'wcs-tax-retrofit'));
            WP_CLI::line('');
            
            $offset = get_option('wc_tax_retrofit_current_offset', 0);
            $last_activity = get_option('wc_tax_retrofit_last_activity', 0);
            $running = get_transient('wc_tax_retrofit_running');
            
            WP_CLI::line(sprintf('  ' . __('Offset actuel', 'wcs-tax-retrofit') . '    : %d', $offset));
            
            if ($last_activity) {
                $time_ago = human_time_diff($last_activity, time());
                WP_CLI::line(sprintf('  ' . __('Dernière activité', 'wcs-tax-retrofit') . ' : %s', $time_ago));
            } else {
                WP_CLI::line('  ' . __('Dernière activité', 'wcs-tax-retrofit') . ' : ' . __('Aucune', 'wcs-tax-retrofit'));
            }
            
            WP_CLI::line(sprintf('  ' . __('Statut', 'wcs-tax-retrofit') . '           : %s', $running ? __('En cours', 'wcs-tax-retrofit') : __('Arrêté', 'wcs-tax-retrofit')));
            WP_CLI::line('');
        }
        
        /**
         * Réinitialise l'état de la migration
         * 
         * ## EXAMPLES
         * 
         *     wp tax-retrofit reset
         */
        public function reset($args, $assoc_args) {
            // Même nettoyage que le reset web (wc_tax_retrofit_admin_page full_reset)
            delete_option('wc_tax_retrofit_executed');
            delete_option('wc_tax_retrofit_count');
            delete_option('wc_tax_retrofit_date');
            delete_option('wc_tax_retrofit_partial_count');
            delete_option('wc_tax_retrofit_current_offset');
            delete_option('wc_tax_retrofit_last_activity');
            delete_transient('wc_tax_retrofit_running');

            wc_tax_retrofit_log('Migration complètement réinitialisée via WP-CLI');
            WP_CLI::success(__('Migration complètement réinitialisée', 'wcs-tax-retrofit'));
        }
    }
    
    WP_CLI::add_command('tax-retrofit', 'WC_Tax_Retrofit_CLI_Command');
}