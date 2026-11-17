# Guide technique — maintenance de Mon Élevage

Ce document est destiné à l'équipe d'exploitation. Les commandes supposent un
hôte Linux de production ; adapter les chemins, comptes et services au fournisseur.
Ne pas exécuter des commandes de migration ou de restauration sans sauvegarde et
validation préalables.

## 1. Architecture d'exploitation

- PHP serveur avec Apache/PHP-FPM ou Nginx/PHP-FPM ; seul `public/` est le
  document root.
- Code métier et vues dans `app/`, points d'entrée et actifs dans `public/`.
- MySQL en InnoDB ; toutes les données d'exploitation sont isolées par
  `farm_id`.
- Configuration dans `config/local.php`, hors webroot et non suivie par Git.
- Journaux et PDF privés dans `storage/`, hors webroot.
- Les photos de profil sont normalisées en WEBP et enregistrées dans la base
  dans `users` ; elles sont envoyées uniquement au propriétaire authentifié.
- Aucun worker, scheduler métier, conteneur ou orchestrateur n'est livré.
- Open-Meteo est un service externe optionnel ; les courriels utilisent PHPMailer
  via un relais SMTP TLS dont les secrets sont fournis par l'hôte.

## 2. Déploiement et changement de version

1. Approuver une révision/tâche de publication et consulter le diff.
2. Exécuter les tests automatisés sous la version PHP ciblée ; la référence
   actuellement vérifiée est PHP 8.3.28, 14 suites et 402 assertions.
3. Créer une sauvegarde cohérente de la base, des PDF et des éléments de
   configuration protégés.
4. Construire une release propre avec `composer install --no-dev
   --prefer-dist --no-interaction --classmap-authoritative`; vérifier
   `composer check-platform-reqs --no-dev`.
5. Valider les évolutions SQL contre une copie de la base de production.
6. Mettre le site en maintenance si les modifications de schéma l'exigent,
   appliquer les migrations validées puis publier les fichiers.
7. Vérifier les permissions, le diagnostic de santé depuis un accès contrôlé,
   les journaux et les parcours d'acceptation avant de rouvrir le service.
8. Surveiller les erreurs et mesures après publication ; consigner le résultat,
   la révision, le schéma, les sauvegardes et l'opérateur.

Les déploiements ne doivent jamais embarquer `config/local.php`, une base
locale, les tests, les journaux ou les fichiers de travail. Ne pas écraser les
fichiers runtime (`storage/`) lors d'une publication.

## 3. Base et migrations

### Nouvelle installation

Créer une base vide, importer `database/schema.sql`, puis contrôler le résultat
avec `health.php`. Ce schéma représente l'état initial complet actuel. Les
migrations `002` à `019` ne doivent pas être rejouées sur cette base.

### Évolution d'une installation

Les migrations SQL sont versionnées dans `database/migrations/`. Le projet ne
fournit pas de registre `schema_migrations`, de CLI de migration ou de détection
automatique de version. Avant chaque évolution : comparer la base réelle à la
migration, sauvegarder et restaurer cette sauvegarde en recette, puis appliquer
uniquement les migrations manquantes dans l'ordre. DDL MySQL et opérations de
migration ne sont pas nécessairement annulables par transaction.

La migration `016_create_pending_registrations.sql` ajoute la table de stockage
des inscriptions temporaires avec code d'e-mail ; elle est déjà présente dans le
schéma initial des nouvelles installations.

La migration `017_create_farm_member_permission_overrides.sql` ajoute les
permissions individuelles, séparées par utilisateur et exploitation ; elle est
également incluse dans le schéma initial des nouvelles installations.

La migration `018_create_oauth_identities.sql` stocke les identités de connexion
Google sans enregistrer de jetons d’accès. Pour activer cette connexion, définir
`MON_ELEVAGE_GOOGLE_CLIENT_ID`, `MON_ELEVAGE_GOOGLE_CLIENT_SECRET` et
`MON_ELEVAGE_GOOGLE_REDIRECT_URI` dans l’environnement, puis déclarer cette URI
exacte dans les identifiants OAuth Google. L’association à un compte classique
demande une session ouverte et une adresse Google vérifiée identique.

La migration `019_add_user_profile_photos.sql` ajoute les colonnes pour les
photos de profil. Le traitement nécessite l'extension PHP GD compilée avec
WEBP ; les fichiers importés sont contrôlés, décodés et ramenés à 512 px avant
leur stockage en base. La sauvegarde MySQL inclut donc les photos ; `mysqldump`
doit conserver l'option `--hex-blob`.

La migration `020_add_poultry_flock_types.sql` ajoute les types cailles, dindes,
canards, pintades, oies et pigeons au référentiel et à la colonne des élevages.
Appliquez-la sur les bases existantes avant de publier le code correspondant.
Les nouvelles espèces n'ont pas de durée de cycle standard préremplie ; chaque
exploitation peut configurer ses propres durées sans appliquer de valeur non
vérifiée.

La migration `021_move_breeder_flock_type_last.sql` place le type reproducteur
en dernier dans l'ordre du menu.

Consigner la version, les sommes de contrôle, l'opérateur, les dates, les
résultats, le schéma avant/après et les éventuels ajustements. Ne jamais prendre
`IF NOT EXISTS` pour une preuve qu'une migration a été appliquée correctement.
Construire un runner et un registre de migrations constitue une amélioration
technique recommandée avant des mises à jour fréquentes.

## 4. Sauvegarde, restauration et reprise

- Automatiser une sauvegarde quotidienne de la base et des PDF de rapports, la
  chiffrer puis la transférer hors hôte. Définir avec le propriétaire le RPO,
  le RTO, la rétention et les contraintes de résidence des données.
- Garder séparément les secrets dans le coffre de l'hébergeur. Ne pas sauvegarder
  le fichier de configuration en clair dans le même dépôt ou bucket.
- Alerter en cas d'échec, volume vide, espace insuffisant ou sauvegarde trop
  ancienne.
- Tester périodiquement une restauration complète dans un environnement isolé,
  incluant l'intégrité des références PDF et le cloisonnement des comptes.
- Lors d'une restauration d'incident, suspendre les écritures, restaurer vers
  une base isolée, valider, faire approuver puis basculer le trafic et consigner
  les pertes potentielles.

Exemple d'export logique avec un fichier de paramètres SQL protégé (`0600`) :

```sh
mysqldump --defaults-extra-file=/run/secrets/mon-elevage-mysql.cnf \
  --single-transaction --routines --events --triggers --hex-blob \
  --default-character-set=utf8mb4 mon_elevage_prod > /backup/mon_elevage.sql
```

Le mot de passe ne doit pas apparaître dans les arguments de processus, les logs
de cron ou l'historique shell.

## 5. Surveillance et incidents

Surveiller : codes HTTP, latence, disponibilité MySQL, erreurs PHP, stockage
libre, âge des sauvegardes, certificat TLS, succès du courrier et erreurs
Open-Meteo. Le point `public/health.php` expose un diagnostic des versions et
de la base ; le limiter aux accès locaux ou à une sonde explicitement autorisée,
car il n'est pas un endpoint public de supervision avec authentification.

Le bootstrap masque les détails des exceptions aux clients et écrit `E_ALL`
dans `storage/logs/app.log`. Les logs ont besoin d'une rotation système et d'une
rétention approuvée ; la purge automatique n'est pas configurée par le projet.
Restreindre l'accès aux journaux, les protéger des téléchargements publics et
ne pas y recopier de secrets.

En incident : protéger les preuves, limiter les accès, désactiver les comptes ou
services touchés, préserver la base avant toute réparation, informer les
responsables et documenter les actions. Toute compromission d'un secret exige sa
rotation et l'invalidation des sessions/tokens correspondants selon le cas.

## 6. Sécurité opérationnelle

- Appliquer les mises à jour OS, PHP, serveur web, MySQL et dépendances après
  recette ; vérifier les avis CVE et les licences.
- Déployer sous un utilisateur non-root. Code en lecture seule pour PHP-FPM ;
  écriture PHP limitée à `storage/`. Jamais de `chmod -R 777`.
- N'exposer que `public/`; bloquer `.git`, les dotfiles, SQL, config, logs et
  fichiers de sauvegarde.
- Administrer les comptes nominatifs, rôles minimums et droits SQL minimums ;
  réexaminer périodiquement le compte administrateur global.
- Ne pas faire confiance aux en-têtes proxy de source inconnue. Configurer le
  proxy pour fournir l'IP client seulement s'il est explicitement approuvé.
- Vérifier que HTTPS est imposé et que PHP détecte la terminaison TLS pour
  générer les cookies `Secure`.
- Ne pas copier des données de production en recette sans anonymisation,
  contrôle d'accès et autorisation.
- Aucun service de paiement, SMS, pièce jointe ou notification push n'est
  actuellement intégré ; ne pas collecter ou annoncer ces capacités.

## 7. Diagnostics et tests

Après chaque release, consulter la checklist du
[`guide de déploiement`](DEPLOIEMENT-PRODUCTION.md).
À minima, contrôler PHP/MySQL/tables, connexion, courrier, permissions,
transactions de stock et vente, accès aux rapports et restauration de sauvegarde.

Lancer les 14 scripts sous PHP 8.3 sur une base de test dédiée :

```sh
for test in tests/*.php; do php "$test" || exit 1; done
```

Les scripts d'intégration créent et suppriment leurs fixtures. Ne jamais les
lancer en production. En cas d'échec, conserver la sortie et les logs privés,
reproduire sur la recette, corriger puis relancer tous les tests affectés.

## 8. Limitations opérationnelles connues

- Le déploiement sur un véritable hébergeur n'a pas été effectué ni testé.
- Aucun système de migration automatisé ou d'historique de version de schéma
  n'est fourni.
- Le transport SMTP est intégré via PHPMailer, mais la configuration et le test
  de réception réelle relèvent de l'hôte. Aucun worker, cron métier, rotation de
  logs ni sauvegarde automatique n'est fourni par le code.
- Les pages légales sont provisoires. Ne pas ouvrir les inscriptions publiques
  avant leur validation par les responsables compétents.
- Les tests locaux ne prouvent pas la capacité, disponibilité, sécurité ou
  conformité d'une configuration de production particulière.
