# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

## [1.4.1] - 2026-02-08

### Robustesse (P1)
- **`catch (Exception)` → `catch (\Throwable)`** : les deux blocs try/catch dans `process()` (transaction par abonnement + boucle externe) ne capturaient que `Exception`. En PHP 7+, les `TypeError` et `Error` ne sont pas des sous-classes d'`Exception` — un crash WooCommerce de type `TypeError` laissait la transaction ouverte sans ROLLBACK. Corrigé avec `\Throwable`
- **Comparaison float `== 0` dans `detect_no_tax_date()`** : `array_sum($taxes['total']) == 0` pouvait donner un faux positif pour des valeurs très proches de 0 (ex: 0.0001). Remplacé par `abs(array_sum($taxes['total'])) < 0.001`

### Performance (P2)
- **Cache transient `detect_no_tax_date()`** : la détection automatique de date (N+1 queries sur 50 abonnements) s'exécutait à chaque chargement de la page admin si aucune date n'était configurée. Résultat désormais caché dans un transient de 5 minutes (`wc_tax_retrofit_detect_date_desc` / `_asc`)
- **Cache static `get_tax_rate_id()`** : la recherche du taux de TVA (jusqu'à 4 requêtes DB) n'était pas cachée, contrairement aux autres getters. Ajout d'un cache `static` avec double variable (`$cached` + `$cached_resolved`) pour supporter le cas `null`
- **Cache static `count_subscriptions()`** : le comptage total (`wcs_get_subscriptions` avec `limit => -1`) n'était pas caché. Ajout d'un cache `static` et déduplication du code inline dans `process()` qui répliquait la même logique

### Cohérence (P3)
- **`$wpdb->prepare()` sur requête debug étape 4** : la seule requête SQL du plugin qui n'utilisait pas `prepare()` (listing des taux disponibles dans `get_tax_rate_id()`). Corrigé avec `LIMIT %d`
- **Sanitize `$_POST['statuses']`** : l'input checkboxes des statuts n'était pas passé par `sanitize_text_field()` avant `validate_statuses()`. Risque quasi-nul (whitelist en aval) mais incohérent avec les autres inputs
- **Nettoyage transients `detect_date_*`** : les deux nouveaux transients sont supprimés dans `deactivate()` et `uninstall.php`

### Documentation (P3)
- **README.md** : 3 occurrences de l'ancien nom `woocommerce-subscription-tax-retrofit` corrigées en `wcs-tax-retrofit` (renommage datant de la v1.3.5)

## [1.4.0] - 2026-02-08

### Internationalisation complète (P1)
- **~70 chaînes UI non traduisibles** : la majorité des textes de l'interface admin (`admin_page()`, `display_results()`, `ajax_count()`, en-têtes CSV) étaient en français brut sans appel `__()`. Toutes les chaînes sont désormais encapsulées dans `__()`, `esc_html__()` ou `esc_js()` selon le contexte. Les fichiers de traduction passent de ~62 à ~130 entrées
- **Fichiers .po/.pot régénérés** : les 3 fichiers de traduction ont été réécrits avec toutes les nouvelles entrées et les versions mises à jour

### Corrections (P2)
- **`batch_size` et `csv_data` manquants** : le tableau `$stats` retourné en cas d'erreur de dépendances dans `process()` ne contenait pas les clés `batch_size`, `offset` et `csv_data`, provoquant des `undefined index` dans le code appelant
- **`get_all_tax_rates()` sans `$wpdb->prepare()`** : la requête SQL utilisait une interpolation directe au lieu de `$wpdb->prepare()`, incohérent avec le reste du plugin. Corrigé avec un `LIMIT %d` paramétré

## [1.3.9] - 2026-02-08

### Corrections (P1)
- **Variable `$target` non définie** : dans `admin_page()`, si aucun taux FR n'existait en base (`$debug_rates` vide), `$target` était utilisée sans avoir été initialisée dans les instructions de configuration, provoquant un PHP Warning. Déplacée avant le bloc conditionnel
- **CLI `simulate --json` multi-batch incomplet** : seuls les `details`, `csv_data` et `errors_list` du dernier lot étaient inclus dans la sortie JSON. Les trois tableaux sont désormais accumulés sur tous les lots, comme `tolerance_warnings` (corrigé en v1.3.4)

### Incohérences corrigées (P2)
- **Priorité filtre harmonisée** : `get_date_limit()` appliquait `apply_filters()` même quand une valeur DB existait, contrairement à `get_selected_rate()` et `get_tolerance()` qui n'utilisent le filtre qu'en fallback. Les trois getters suivent désormais la même logique : DB → fallback filtre
- **CLI `simulate --date-limit` et `--tolerance`** : ces options temporaires utilisaient `add_filter()` qui était ignoré quand une valeur DB existait (priorité DB > filtre). Migration vers le pattern save/restore DB déjà utilisé par `--tax-rate` depuis v1.3.1
- **Date fallback hardcodée** : le fallback `'2024-11-29'` dans `get_date_limit()` (spécifique à un cas d'usage) est remplacé par `current_time('Y-m-d')` (date du jour)
- **Retour `locked` de `process()`** : le tableau retourné quand un traitement est déjà en cours ne contenait que `locked` et `message`, sans les clés attendues (`updated`, `errors`, `has_more`, etc.). Ajout de toutes les clés du tableau `$stats` standard pour éviter des undefined index
- **CSV transient TTL** : le TTL des données CSV temporaires est passé de 1 heure à 24 heures, évitant la perte de données lors de migrations interrompues puis reprises après >1h

### Performance (P3)
- **Cache statique `get_date_limit()`** : la fonction était appelée ~8 fois sur la page admin sans cache. Chaque appel lit `get_option()`, valide la date, et peut déclencher la détection automatique. Un cache `static` élimine les appels redondants
- **Cache statique `get_tolerance()`** : ~6 appels sans cache sur la page admin
- **Cache statique `get_subscription_statuses()`** : ~5 appels sans cache sur la page admin

### Nettoyage (P3)
- **SQL avec `$wpdb->prepare()`** : les requêtes DELETE dans le hook de désactivation et dans `uninstall.php` utilisaient des requêtes SQL brutes. Migré vers `esc_like()` + `prepare()` pour cohérence avec le reste du plugin

## [1.3.8] - 2026-02-07

### Renommage
- **Text domain renommé** : `wc-tax-retrofit` → `wcs-tax-retrofit` dans le header plugin, `load_plugin_textdomain()`, les 60+ appels `__()`, la source de log, et les 3 mentions HTML admin. Les fichiers de langue ont été renommés en conséquence (`wcs-tax-retrofit.pot`, `wcs-tax-retrofit-fr_FR.po`, `wcs-tax-retrofit-en_US.po`)

### Correction (P1)
- **Simulation après migration interrompue** : `wc_tax_retrofit_process()` reprenait automatiquement à l'offset sauvegardé même en mode simulation (`$dry_run = true`). Une simulation lancée après une migration interrompue démarrait donc au mauvais offset. Ajout d'un guard `!$dry_run` à la condition de reprise

### Incohérences corrigées (P2)
- **3x « avant le » → « jusqu'au »** : le filtre `date_created` utilise `<=` (inclusif) depuis v1.3.7, mais 3 textes UI disaient encore « avant le » au lieu de « jusqu'au » (section abonnements concernés, recalcul, et actions)
- **Nom du fichier CSV** : le fichier exporté s'appelait `wc-subscription-tax-retrofit-*.csv` (ancien naming sans 's'). Renommé en `wcs-tax-retrofit-*.csv` pour cohérence avec le text domain
- **README « ~20 entrées »** : la section traductions indiquait ~20 chaînes à traduire alors que les fichiers .po en contiennent 60
- **Chaîne PO manquante** : « Lancement de la migration... » (CLI migrate) n'était pas dans les fichiers .po — les anciens fichiers pointaient à tort vers « Lancement de la simulation... » pour les deux références. Ajoutée avec traduction anglaise

### Nettoyage (P3)
- **PHPDoc ajouté** : `get_subscription_statuses()`, `get_tolerance()` et `get_date_limit()` n'avaient pas de docblock `@return`, contrairement aux autres fonctions publiques du plugin

## [1.3.7] - 2026-02-07

### Corrections (P1)
- **Filtre `date_created` inclusif** : le code utilisait `<` (strictement avant) alors que l'UI indiquait « avant ou le ». Remplacé par `<=` aux 3 endroits (`count_subscriptions`, `process` batch, `process` total count) pour inclure les abonnements créés le jour même de la date limite
- **Double message succès/erreur corrigé** : quand l'utilisateur décochait tous les statuts dans les paramètres avancés, le message d'erreur ET le message de succès s'affichaient simultanément. Un flag `$settings_has_error` conditionne désormais l'affichage du succès
- **`--skip-confirm` documenté** : l'option CLI `migrate --skip-confirm` (pour scripts/cron) était gérée dans le code mais absente du docblock WP-CLI. `wp tax-retrofit migrate --help` l'affiche désormais

### Améliorations (P2)
- **`uninstall.php` ajouté** : la suppression du plugin via l'interface admin nettoie désormais toutes les options et transients de la base de données. Auparavant, 7 options de configuration et d'état restaient orphelines
- **Code mort supprimé** : les 3 appels `wp_die()` après `wp_send_json_success/error()` dans `ajax_count()` étaient inatteignables (ces fonctions appellent déjà `wp_die()` en interne)
- **Cache statique `get_selected_rate()`** : la fonction faisait une requête SQL (`$wpdb->get_var`) à chaque appel. Sur la page admin (~5 appels), cela générait 5 requêtes identiques. Un cache `static` élimine les appels redondants

### Nettoyage (P3)
- **`$meta->is_unique` supprimé** : `WC_Meta_Data` n'a pas de propriété `is_unique` ; l'expression `$meta->is_unique ?? false` évaluait toujours `false`. Remplacé par `false` directement
- **Docblock `$stats` réduit** : le commentaire inline de 65 lignes décrivant la structure du tableau `$stats` dans `process()` est remplacé par un résumé de 5 lignes
- **README hooks mis à jour** : « 3 hooks PHP » remplacé par « 5 hooks PHP (4 filtres + 1 action) » avec documentation des 2 hooks manquants (`wc_tax_retrofit_subscription_statuses` et `wc_tax_retrofit_after_process`)

### Distribution
- **Fichier `uninstall.php` créé** : nettoyage complet à la suppression du plugin
- **Fichier `LICENSE` ajouté** : licence GPL v2 complète pour distribution GitHub
- **`.gitignore` ajouté** : exclut les fichiers système, IDE et fichiers compilés `.mo`
- **Structure fichiers README mise à jour** : ajout de `uninstall.php`, `CHANGELOG.md` et `LICENSE`

## [1.3.6] - 2026-02-07

### Internationalisation
- **Fichiers de langues régénérés** : les 3 fichiers (POT, fr_FR.po, en_US.po) étaient figés à v1.2.9 avec l'ancien nom de fichier, des numéros de lignes obsolètes, 8 chaînes manquantes et 3 chaînes supprimées. Régénérés intégralement avec 60 chaînes (vs 50 avant)
- **8 chaînes ajoutées** : `Taux de TVA introuvable...`, `Dernière activité : il y a %s`, `Aucun statut valide fourni...`, `Taux %s%% non trouvé... utilisation du taux configuré`, `Batch offset %d : %d à migrer...`, `À migrer`, `Batch offset %d : %d mis à jour...`, `Migration complètement réinitialisée`
- **3 chaînes obsolètes supprimées** : `Il reste des abonnements à traiter.`, `Relancez avec : wp tax-retrofit migrate...`, `État de la migration réinitialisé`
- **Références mises à jour** : nom de fichier `wc-subscriptions-tax-retrofit.php`, version 1.3.6, date 2026-02-07
- **Traduction anglaise corrigée** : `Type "OUI"` au lieu de `Type "YES"` (le code vérifie `$confirmation !== 'OUI'` quelle que soit la locale)

## [1.3.5] - 2026-02-07

### Correction (P1)
- **Filtre `wc_tax_retrofit_tolerance` restauré** : `get_tolerance()` n'appelait plus `apply_filters()` depuis la v1.3.2 (supprimé du `define()` mais jamais réintroduit dans la fonction). Le filtre documenté dans le README, mentionné dans l'UI admin, et utilisé par CLI `simulate --tolerance` était silencieusement ignoré. Ajouté en priorité 2 (DB > filtre > constante), en cohérence avec `get_date_limit()` et `get_selected_rate()`

### Nettoyage (P3)
- **Cache `$target` réutilisé (infos techniques)** : `get_selected_rate_percent()` était encore appelée dans le bloc « Tax Rate ID non trouvé » des infos techniques alors que `$target` était déjà en scope (oubli du fix v1.3.4 #4)
- **`$saved_date` doublon supprimé** : `get_option('wc_tax_retrofit_date_limit')` était appelée deux fois à 9 lignes d'intervalle dans le même scope de la page admin

### Renommage
- **Fichier principal renommé** : `woocommerce-subscription-tax-retrofit.php` → `wc-subscriptions-tax-retrofit.php`

## [1.3.4] - 2026-02-07

### Description
- **Header plugin réécrit** : la description longue avec lien HTML (non rendu dans la liste des extensions ni dans `wp plugin list`) est remplacée par une description concise et lisible

### Correction (P1)
- **CLI `simulate` alertes de tolérance multi-batch** : seules les alertes du dernier batch étaient vérifiées après la boucle. Les `tolerance_warnings` sont désormais accumulés sur tous les batchs (comme `updated`, `skipped`, `errors`). Corrigé aussi dans la sortie JSON

### Incohérences corrigées (P2)
- **Mention filtre `wc_tax_retrofit_batch_size` supprimée** : les infos techniques admin affichaient `(filtre wc_tax_retrofit_batch_size)` alors que ce filtre a été supprimé en v1.3.3
- **Option `--batch-size` supprimée du docblock CLI `migrate`** : l'option était documentée mais jamais implémentée dans le code
- **Cache `$target` réutilisé** : `get_selected_rate_percent()` était rappelée 2 fois dans le bloc d'erreur « taux introuvable » alors que `$target` était déjà calculée dans le même scope

### Nettoyage (P3)
- **Handler `reset_migration` supprimé** : code mort — aucun formulaire dans l'UI ne soumettait cette action (remplacé par `full_reset` depuis v1.3.0). Le handler était aussi incomplet par rapport à `full_reset` (ne supprimait pas `current_offset` ni `last_activity`)

## [1.3.3] - 2026-02-07

### Incohérences corrigées
- **`apply_filters()` dans `define(WC_TAX_RETROFIT_BATCH_SIZE)`** : même problème de timing que `TOLERANCE` et `RATE` corrigés en v1.3.1/1.3.2 — les filtres ne sont pas encore enregistrés au moment du `define()`. La constante utilise maintenant sa valeur par défaut directement
- **Commentaire obsolète** : `// Priorité 2 : Constante (définie via filtre au chargement)` dans `get_tolerance()` ne reflétait plus la réalité depuis la suppression du filtre en v1.3.2
- **README version** : le header affichait 1.3.1 au lieu de la version courante

### Performance
- **Cache `get_selected_rate_percent()` dans la boucle debug** : la fonction était appelée à chaque itération du tableau de taux FR au lieu d'être mise en cache avant la boucle
- **Cache section « Fonctionnement »** : `get_selected_rate()` était appelée 3 fois et `get_selected_rate_percent()` 1 fois dans le même bloc HTML. Les résultats sont désormais mis en cache en variables locales

### Modernisation PHP 7.4+ (suite)
- **Null coalescing operator** : remplacement de 7 occurrences de `isset($x) ? $x : default` par `$x ?? default` (disponible depuis PHP 7.0)

### Nettoyage
- **`WHERE 1=1` supprimé** : clause inutile dans la requête SQL de `get_all_tax_rates()` (aucune condition dynamique concaténée)

## [1.3.2] - 2026-02-07

### Modernisation PHP 7.4+
- **Return type hints** : ajout de types de retour explicites sur 24 fonctions (`: array`, `: bool`, `: float`, `: int`, `: string`, `: void`). Les 6 fonctions retournant des union types (`string|false`, `string|null`, etc.) sont exclues car les union types nécessitent PHP 8.0+
- **Arrow functions** : remplacement des closures `function() use ($var) { return $var; }` par `fn() => $var` dans les commandes CLI `simulate`
- **Spread operator** : remplacement de `array_merge($a, $b)` par `[...$a, ...$b]` pour la fusion des données CSV (plus performant sur les tableaux à clés numériques)
- **Suppression du check PHP redondant** : le `version_compare(PHP_VERSION, '7.4', '<')` dans `wc_tax_retrofit_check_dependencies()` est supprimé car le header WordPress `Requires PHP: 7.4` et le check early empêchent déjà l'exécution sur PHP < 7.4. La fonction est simplifiée en une seule expression
- **Suppression de `apply_filters()` dans `define(WC_TAX_RETROFIT_TOLERANCE)`** : les filtres ne sont pas encore enregistrés au moment du `define()`, la constante utilise maintenant sa valeur par défaut directement

## [1.3.1] - 2026-02-07

### Corrections (P1)
- **CLI `simulate` multi-batch** : la commande `wp tax-retrofit simulate` ne traitait que le premier lot de 100 abonnements. Elle boucle désormais sur tous les lots comme `migrate`
- **CLI `simulate --tax-rate` fonctionnel** : le paramètre `--tax-rate` était silencieusement ignoré quand un taux était déjà sauvé en base (la DB avait priorité sur le filtre). Le taux temporaire est désormais appliqué via une option DB restaurée après la simulation
- **CLI `reset` complet** : la commande CLI ne supprimait pas `wc_tax_retrofit_executed`, `wc_tax_retrofit_date` ni `wc_tax_retrofit_count`, contrairement au reset web. Les deux sont désormais identiques
- **CLI `config --tax-rate` match approximatif** : la recherche du taux en base utilisait un match exact (`WHERE tax_rate = %s`) alors que le reste du plugin utilise `ABS(tax_rate - %f) < 0.01`. Harmonisé
- **`current_time('timestamp')` déprécié** : remplacé par `time()` dans la commande CLI `stats` (déprécié depuis WordPress 5.3)

### Améliorations (P2)
- **Requête debug déplacée** : la requête SQL de debug listant les taux FR ne s'exécute plus systématiquement sur la page admin, uniquement quand le taux est introuvable
- **Cache `wc_tax_retrofit_get_tax_rate_id()`** : le résultat du premier appel sur la page admin est réutilisé dans les infos techniques (économie d'un appel lourd avec jusqu'à 4 requêtes SQL)
- **Cache statique détection de dates** : `wc_tax_retrofit_detect_no_tax_date()` utilise désormais un cache statique pour éviter les appels redondants dans la même requête

### Nettoyage (P3)
- **Suppression `WC_TAX_RETROFIT_RATE`** : constante inutilisée qui utilisait `apply_filters()` dans `define()` (les filtres ne sont pas encore enregistrés au moment du chargement)
- **Suppression `WC_TAX_RETROFIT_TEXT_DOMAIN`** : constante définie mais jamais utilisée (le text domain est en dur dans tous les appels `__()`)
- **Suppression `WC_TAX_RETROFIT_TOLERANCE` filtre** : la constante conserve sa valeur par défaut sans `apply_filters()` (même problème de timing que `WC_TAX_RETROFIT_RATE`)
- **Suppression `$show_detection_block`** : variable assignée mais jamais lue (code mort)
- **Factorisation détection de dates** : `detect_first_no_tax_date()` et `detect_last_no_tax_date()` délèguent à une fonction unique `detect_no_tax_date($order)` au lieu de dupliquer 30 lignes
- **Factorisation `validate_tolerance()`** : délègue à `validate_float()` au lieu de dupliquer la logique de validation
- **README mis à jour** : version 1.2.9 → 1.3.1

## [1.3.0] - 2026-02-07

### Corrections critiques (P0)
- **Fix Fatal Error PHP** : suppression de la double déclaration de `wc_tax_retrofit_validate_date()` (lignes 145 et 308) qui provoquait un crash à l'activation
- **Fix `$stats` utilisé avant déclaration** : le bloc de sauvegarde d'offset dans `wc_tax_retrofit_process()` référençait `$stats['batch_size']` avant que `$stats` ne soit initialisé, causant un offset de reprise incorrect

### Corrections importantes (P1)
- **WP-CLI fonctionnel** : les vérifications de permissions et nonce dans `wc_tax_retrofit_process()` sont désormais ignorées en contexte CLI (`defined('WP_CLI')`)
- **Option tolérance CLI corrigée** : la commande `wp tax-retrofit config --tolerance=` écrivait sous la mauvaise clé (`wc_tax_retrofit_tolerance` au lieu de `wc_tax_retrofit_tolerance_setting`), rendant la configuration CLI sans effet
- **CLI migrate sauvegarde l'état** : la commande `wp tax-retrofit migrate` sauvegarde désormais `wc_tax_retrofit_executed`, `wc_tax_retrofit_date` et `wc_tax_retrofit_count`, comme la version web. Le CLI traite aussi tous les lots automatiquement au lieu de s'arrêter au premier batch
- **Statuts par défaut CLI corrigés** : le fallback dans `config` affichait `pending` au lieu de `pending-cancel`
- **Validation des statuts CLI** : les statuts passés via `--statuses=` sont désormais validés avec `wc_tax_retrofit_validate_statuses()` au lieu d'être sauvegardés tels quels

### Améliorations (P2)
- **Transaction DB** : les modifications d'items (suppression/recréation) dans `wc_tax_retrofit_process()` sont désormais encapsulées dans une transaction SQL (`START TRANSACTION` / `COMMIT` / `ROLLBACK`) pour éviter les abonnements corrompus en cas d'erreur
- **Détection de date améliorée** : `wc_tax_retrofit_detect_last_no_tax_date()` et `wc_tax_retrofit_detect_first_no_tax_date()` analysent désormais jusqu'à 50 abonnements (au lieu d'un seul), ce qui augmente considérablement les chances de trouver un abonnement sans TVA
- **Cache des valeurs dans la boucle** : `wc_tax_retrofit_get_selected_rate()` et `wc_tax_retrofit_get_tolerance()` sont mis en cache au début de `wc_tax_retrofit_process()` au lieu d'être recalculés à chaque item (économie de ~400 requêtes SQL par batch de 100)
- **Sauvegarde offset pour reprise** : l'offset courant est correctement sauvegardé à la fin de chaque batch pour permettre la reprise après interruption

### Nettoyage (P3)
- **Suppression du HTML echo dans process()** : `wc_tax_retrofit_process()` ne fait plus de `echo` HTML quand le taux de TVA est introuvable ; elle retourne proprement un tableau d'erreurs via `errors_list`
- **Fix i18n** : remplacement de `" ago"` (anglais) par une chaîne traduisible via `__()` dans l'alerte de migration interrompue
- **Fix fuseau horaire CSV** : remplacement de `date()` par `wp_date()` dans le nom du fichier CSV exporté
- **Suppression de la balise `?>`** : suppression de la balise de fermeture PHP en fin de fichier pour éviter les erreurs "headers already sent"
- **Performance export CSV** : réorganisation des vérifications dans `wc_tax_retrofit_export_csv()` pour que la vérification d'action (`$_GET['action']`) soit effectuée en premier (avant les vérifications de dépendances et permissions), évitant des opérations coûteuses sur chaque chargement `admin_init`

## [1.2.9] - 2024-12-10

### Ajouts
- **Support WP-CLI complet** : 5 commandes disponibles (`config`, `simulate`, `migrate`, `stats`, `reset`)
- Idéal pour très grosses bases de données (pas de timeout PHP)
- Automatisation et intégration CI/CD
- Sortie JSON pour parsing
- Documentation complète

## [1.2.8] - 2024-12-09

### Améliorations
- Amélioration des notices de dépendances
- Internationalisation complète
- Messages d'erreur plus explicites

## [1.2.7] - 2024-12-09

### Ajouts
- Taux de TVA configurable via interface
- Support universel de tous les pays

## [1.2.6] - 2024-12-09

### Améliorations
- Sécurité renforcée
- Validation stricte des données
- Vérification centralisée des dépendances