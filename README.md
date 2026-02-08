# WooCommerce Subscriptions Tax Retrofit

**Version:** 1.4.1
**Auteur:** Paul ARGOUD  
**Licence:** GPL v2 or later  
**Requires:** WordPress 5.0+, PHP 7.4+, WooCommerce, WooCommerce Subscriptions

## Description

Plugin WordPress pour migrer automatiquement des abonnements WooCommerce Subscriptions enregistrés en TTC (sans TVA séparée) vers un format HT + TVA, tout en conservant le prix TTC payé par les clients.

**Cas d'usage typique :** Vous venez de franchir le seuil de TVA et devez maintenant afficher la TVA sur vos factures d'abonnements existants.

## Installation

### Méthode 1 : Via l'interface WordPress

1. Téléchargez le fichier `wcs-tax-retrofit.zip`
2. Allez dans **WordPress Admin → Extensions → Ajouter**
3. Cliquez sur **Téléverser une extension**
4. Sélectionnez le fichier ZIP
5. Cliquez sur **Installer maintenant**
6. Activez l'extension

### Méthode 2 : Installation manuelle

Téléchargez et décompressez dans `wp-content/plugins/wcs-tax-retrofit/`

### Méthode 3 : Via WP-CLI

```bash
wp plugin install wcs-tax-retrofit.zip --activate
```

## Configuration requise

- WordPress 5.0 ou supérieur
- PHP 7.4 ou supérieur
- WooCommerce installé et activé
- WooCommerce Subscriptions installé et activé
- (Optionnel) WP-CLI pour usage en ligne de commande

## Utilisation

### Via l'interface Web (recommandé)

1. **Sauvegarde** : Faites une sauvegarde complète de votre base de données (NON NÉGOCIABLE)
2. Allez dans **WooCommerce → Tax Retrofit**
3. Configurez :
   - Le taux de TVA (sélection depuis vos taux WooCommerce)
   - La date limite (détection automatique disponible)
   - Les statuts d'abonnements à traiter
4. Lancez une **simulation** pour prévisualiser les changements
5. Vérifiez les résultats de la simulation
6. Si tout est OK, lancez la **migration réelle**
7. Exportez le CSV pour vos archives

### Via WP-CLI (usage avancé)

```bash
# Configuration
wp tax-retrofit config --tax-rate=20 --date-limit=2024-01-01 --tolerance=0.01

# Simulation
wp tax-retrofit simulate

# Simulation avec sortie JSON
wp tax-retrofit simulate --json > simulation.json

# Migration réelle (APRÈS SAUVEGARDE !)
wp tax-retrofit migrate --yes-i-have-a-backup

# Statistiques d'avancement
wp tax-retrofit stats

# Réinitialisation
wp tax-retrofit reset
```

**Commandes WP-CLI disponibles :**
- `config` : Afficher ou modifier la configuration
- `simulate` : Lancer une simulation
- `migrate` : Exécuter la migration réelle
- `stats` : Afficher les statistiques
- `reset` : Réinitialiser l'état de migration

## Fonctionnalités

### Interface Web
- ✅ Migration automatique de centaines d'abonnements
- ✅ Mode simulation obligatoire avant exécution
- ✅ Support de tous les taux de TVA (France, Belgique, Suisse, etc.)
- ✅ Traitement par batch (100 abonnements / lot)
- ✅ Reprise automatique en cas d'interruption
- ✅ Tolérance d'arrondi configurable
- ✅ Logs détaillés dans WooCommerce
- ✅ Export CSV des modifications

### WP-CLI (nouveauté v1.2.9)
- ✅ Support complet de la ligne de commande
- ✅ Idéal pour très grosses bases (pas de timeout PHP)
- ✅ Scriptable et automatisable (CI/CD, cron)
- ✅ Sortie JSON pour parsing
- ✅ 5 commandes disponibles

### Internationalisation
- ✅ Interface multilingue (français, anglais)
- ✅ Fichier POT fourni pour nouvelles traductions
- ✅ Toutes les chaînes traduisibles

### Pour développeurs
- ✅ 5 hooks PHP disponibles (4 filtres + 1 action)
- ✅ Code commenté et standards WordPress (WPCS)
- ✅ Un seul fichier PHP (~2300 lignes)

## Hooks disponibles

Le plugin propose 4 filtres et 1 action PHP pour personnalisation :

```php
// Forcer un taux de TVA spécifique
add_filter('wc_tax_retrofit_rate', function($rate) {
    return 0.20; // 20%
});

// Définir une date limite
add_filter('wc_tax_retrofit_date_limit', function($date) {
    return '2024-01-15';
});

// Modifier la tolérance d'arrondi
add_filter('wc_tax_retrofit_tolerance', function($tolerance) {
    return 0.02; // 2 centimes
});

// Modifier les statuts d'abonnements à traiter
add_filter('wc_tax_retrofit_subscription_statuses', function($statuses) {
    return array('active', 'on-hold'); // Par défaut : active, pending-cancel, on-hold
});

// Intercepter les résultats après chaque batch
add_action('wc_tax_retrofit_after_process', function($stats, $dry_run) {
    if (!empty($stats['tolerance_warnings'])) {
        error_log('Tax Retrofit: ' . count($stats['tolerance_warnings']) . ' alertes');
    }
}, 10, 2);
```

## Traductions

Le plugin est traduit en :
- Français (fr_FR) - langue principale
- Anglais (en_US) - traduction complète

**Pour créer une nouvelle traduction :**
1. Copiez le fichier `languages/wcs-tax-retrofit.pot`
2. Ouvrez-le avec Poedit (gratuit)
3. Créez un nouveau catalogue pour votre langue
4. Traduisez les chaînes (~130 entrées)
5. Sauvegardez : Poedit génère automatiquement les fichiers .po et .mo
6. Placez-les dans `languages/`

Le plugin chargera automatiquement la bonne traduction selon la langue WordPress.

## Structure des fichiers

```
wcs-tax-retrofit/
├── wcs-tax-retrofit.php                        (Plugin principal ~2300 lignes)
├── uninstall.php                               (Nettoyage à la suppression)
├── README.md                                   (Ce fichier)
├── CHANGELOG.md                                (Historique des versions)
├── LICENSE                                     (Licence GPL v2)
└── languages/
    ├── wcs-tax-retrofit.pot                   (Template de traduction)
    ├── wcs-tax-retrofit-fr_FR.po              (Traduction française)
    └── wcs-tax-retrofit-en_US.po              (Traduction anglaise)
```

## Support

**Ce plugin est fourni "tel quel", sans aucune garantie et sans support technique.**

- ❌ Pas de support
- ❌ Pas de garantie
- ❌ Utilisez à vos risques et périls
- ✅ Code open source (licence GPL v2)
- ✅ Vous pouvez le modifier librement
- ✅ Contributions bienvenues

## Avertissement

⚠️ **FAITES UNE SAUVEGARDE COMPLÈTE AVANT UTILISATION**

Ce plugin modifie directement votre base de données WooCommerce. Bien qu'il ait été largement testé, une sauvegarde est INDISPENSABLE avant toute migration.

## Tests effectués

- ✅ WordPress 5.0 à 6.4+
- ✅ PHP 7.4, 8.0, 8.1, 8.2
- ✅ WooCommerce 7.0 à 8.3+
- ✅ Plus de 500 abonnements testés
- ✅ Tous les taux de TVA (20%, 10%, 5.5%, etc.)
- ✅ Tests de reprise après timeout
- ✅ Tests WP-CLI complets

## Cas d'usage WP-CLI

### Migration automatisée

```bash
#!/bin/bash
# Script de migration automatique

wp tax-retrofit config --tax-rate=20 --date-limit=2024-01-01
RESULT=$(wp tax-retrofit simulate --json)
ERRORS=$(echo $RESULT | jq -r '.errors')

if [ "$ERRORS" -eq 0 ]; then
    wp tax-retrofit migrate --yes-i-have-a-backup --skip-confirm
    echo "Migration terminée avec succès"
else
    echo "Erreurs détectées, migration annulée"
    exit 1
fi
```

### Migration nocturne (cron)

```bash
# Ajouter dans crontab
0 2 * * * cd /var/www/html && wp tax-retrofit migrate --yes-i-have-a-backup --skip-confirm >> /var/log/tax-retrofit.log 2>&1
```

### Migration par lots (grosse base)

```bash
#!/bin/bash
# Migration par lots de 100 avec reprise automatique

OFFSET=0
while true; do
    echo "Traitement offset $OFFSET..."
    wp tax-retrofit migrate --yes-i-have-a-backup --skip-confirm --offset=$OFFSET
    
    if wp tax-retrofit stats | grep -q "has_more.*false"; then
        echo "Migration complète !"
        break
    fi
    
    OFFSET=$((OFFSET + 100))
    sleep 2
done
```

## Licence

GPL v2 or later - https://www.gnu.org/licenses/gpl-2.0.html

## Auteur

Paul ARGOUD  
https://paul.argoud.net

## Changelog

Voir le fichier CHANGELOG.md pour l'historique détaillé des versions.