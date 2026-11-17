# Déploiement en production — Mon Élevage

**État : préparation et procédure, pas un déploiement.** Aucun hébergement cible,
nom de domaine ni accès de déploiement n'a été fourni. Le site n'a donc pas été
installé en production et sa disponibilité publique n'est pas vérifiée.

## 1. État de préparation et décision de mise en service

Les contrôles locaux exécutés le 19 mars 2026 ont passé les 14 suites
d'intégration (402 assertions) sous PHP 8.3.28. Ils ne remplacent ni un test
d'acceptation sur l'hébergement choisi, ni un audit indépendant de sécurité.

| État | Périmètre |
|---|---|
| Implémenté et couvert par les tests d'intégration | Comptes et sessions, exploitations et permissions, élevages et suivi quotidien, performances et calendriers, catalogue/achats/stocks/inventaires, budgets/dépenses, ventes/paiements/reçus, rentabilité, suivi sanitaire et alertes internes, rapports PDF privés, guide de formation, administration et journaux d'audit. |
| Implémenté, avec service ou configuration externe | Confirmation d’inscription par code valable une minute et récupération du mot de passe par lien ; PHPMailer nécessite un relais SMTP TLS authentifié configuré et testé. Prévisions extérieures Open-Meteo (requêtes HTTPS sortantes, coordonnées et seuils configurés) ; mesures du poulailler saisies manuellement. |
| Non inclus ou à finaliser avant ouverture publique | Paiements en ligne, SMS/push, application hors ligne, pièce jointe téléversée par l'utilisateur, worker asynchrone et tâche de génération automatique des alertes ; ces fonctions ne doivent pas être promises. Les documents légaux sont encore provisoires et exigent une revue et des coordonnées de responsable réelles. |
| Bloquant jusqu'à validation en environnement cible | Domaine/DNS, certificat TLS, configuration PHP-FPM/Apache ou Nginx, base dédiée, relais de courriel, sauvegarde/restauration, compte administrateur initial, test des permissions et des téléchargements, surveillance et procédure d'incident. |

Le bootstrap autorise maintenant l'accès distant lorsque `app_env` vaut
`staging` ou `production`; il continue à limiter `development` à la boucle locale.
Une configuration de production sans secret est fournie dans
[`config/production.php.example`](config/production.php.example). Le site ne doit
pas être déclaré « déployé » tant que la liste de contrôle de la section 11 n'a
pas été exécutée sur l'hébergement cible.

## 2. Prérequis d'hébergement

Prévoir un hôte Linux maintenu (ou une plateforme équivalente permettant de régler
les mêmes permissions), avec :

- PHP **8.3** ou une version ultérieure encore maintenue et validée par la suite
  de tests. PHP 8.3.28 est la version de référence effectivement testée ici.
- Apache avec PHP-FPM ou Nginx avec PHP-FPM ; le document root doit être
  exactement le dossier `public/`.
- MySQL **8.4 LTS** recommandé, InnoDB, `utf8mb4` et collation
  `utf8mb4_unicode_ci`. Le schéma déclare aussi MySQL 8.0.16+ ou MariaDB 10.6+,
  mais la validation applicative disponible a été faite avec MySQL 8.4.7,
  pas avec MariaDB.
- Extensions PHP requises : `PDO`, `pdo_mysql`, `mbstring`, `dom`, `openssl`,
  `fileinfo` et `gd` (prise en charge WEBP requise pour les photos de profil).
  `json` et `filter` doivent également être disponibles ; `zlib` (compression
  PDF) est recommandé. Confirmer les exigences Composer avec
  `composer check-platform-reqs`.
- Pour l'import d'images de profil, configurer `upload_max_filesize` à au moins
  `5M` et `post_max_size` à au moins `6M` dans PHP-FPM/Apache. Les images
  enregistrées sont redimensionnées en WEBP dans la base de données.
- Composer 2.x pour construire les dépendances verrouillées. Node.js/npm ne sont
  pas requis en production : Chart.js et les autres actifs utilisés sont déjà
  livrés dans le projet.
- DNS, accès entrant TCP 443, certificat TLS valide, synchronisation horaire
  NTP, journalisation, supervision et sauvegarde chiffrée hors hôte.
- Accès HTTPS sortant vers le relais de courrier configuré. Pour la météo,
  autoriser également `api.open-meteo.com` en HTTPS si cette fonction est activée.
- Espace disque pour les journaux et les PDF privés, avec seuil d'alerte.

Ne pas exposer MySQL sur Internet. Préférer une base locale ou un réseau privé
entre l'application et la base. La connexion PDO n'a pas de configuration de
certificat TLS MySQL dédiée ; ne pas utiliser une base distante non privée.

## 3. Préparation du domaine et des secrets

1. Réserver un domaine de production et pointer son DNS vers l'hébergement.
   Préparer si possible un sous-domaine de recette distinct, une base distincte
   et des comptes distincts.
2. Créer une base vide dédiée, par exemple `mon_elevage_prod`, et un utilisateur
   SQL dédié. Depuis une session MySQL administrateur protégée, adapter et
   exécuter les instructions ci-dessous ; remplacer le mot de passe fictif par
   un secret aléatoire jamais utilisé ailleurs :

   ```sql
   CREATE DATABASE mon_elevage_prod
     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'mon_elevage_app'@'127.0.0.1'
     IDENTIFIED BY 'SECRET_ALEATOIRE_A_REMPLACER';
   GRANT SELECT, INSERT, UPDATE, DELETE
     ON mon_elevage_prod.* TO 'mon_elevage_app'@'127.0.0.1';
   ```

   Importer le schéma avec un compte DBA/migration distinct. Le compte runtime
   n'a pas besoin de droits globaux ni de DDL ; accorder temporairement les droits
   de migration seulement si une procédure de mise à jour les exige, puis les
   retirer.
3. Créer `config/local.php` à partir de
   [`config/production.php.example`](config/production.php.example). Remplacer
   **toutes** les valeurs d'exemple : hôte/base/utilisateur/mot de passe SQL,
   URL HTTPS réelle et adresse d'expédition contrôlée. Choisir la devise par
   défaut du service (par exemple `XOF`) avant l'inscription des exploitations.
4. Générer le mot de passe SQL avec un générateur cryptographique ou un
   gestionnaire de secrets ; ne pas réutiliser le mot de passe local, ni le
   mot de passe d'un compte utilisateur. Injecter les secrets par le coffre
   de l'hébergeur ou créer le fichier directement sur l'hôte. Ne pas les mettre
   dans Git, une commande shell journalisée, un ticket ou une capture d'écran.
5. Régler `base_url` sur l'URL canonique HTTPS, sans slash final, pour que les
   liens de vérification et de réinitialisation pointent vers le bon site.
6. Régler `app_env` sur `production`. Cette valeur autorise l'accès HTTP depuis
   le réseau ; ne pas la définir sur `development` en production.

`config/local.php` est ignoré par Git et se trouve hors de `public/`. Ne jamais
copier le fichier local WAMP ni les identifiants locaux. Dans le modèle PHP,
`REPLACE_WITH_A_RANDOM_PRODUCTION_SECRET` et `example.com` sont des marqueurs
fictifs, pas des valeurs utilisables.

## 4. Construire et publier une version

1. Vérifier la révision du code, les changements et le verrou de dépendances.
   Utiliser une version identifiée (tag ou commit) approuvée ; ne pas déployer
   un répertoire de travail contenant des changements non revus.
2. Construire hors du webroot, par exemple dans un répertoire de release :

   ```sh
   composer install --no-dev --prefer-dist --no-interaction --classmap-authoritative
   composer check-platform-reqs --no-dev
   ```

   Exécuter `composer install` depuis la racine du projet, qui contient
   `composer.lock`. Les scripts Composer ne sont pas déclarés. Le dossier
   `vendor/` produit est nécessaire à Dompdf.
3. Vérifier la présence de `public/assets/vendor/chart.umd.js`. Pour modifier
   l'actif, utiliser le `package-lock.json` et la version verrouillée de Chart.js
   (4.5.1), puis reconstruire/copier explicitement l'actif ; ne pas servir
   `node_modules/`.
4. Publier le code en dehors du webroot et configurer le serveur pour servir
   uniquement `.../Gestion_Plet/public`. Interdire l'accès web à `.git`,
   `app/`, `config/`, `database/`, `scripts/`, `storage/`, `tests/`, `vendor/`
   et aux fichiers cachés. Ne pas compter sur `.htaccess` avec Nginx.
5. Créer les dossiers nécessaires de `storage/` hors du webroot : `logs/`,
   `reports/`, `exports/`, `receipts/`, `pdf-font-cache/` et `pdf-temp/`.
   Les PDF de rapports doivent rester privés ; leurs téléchargements passent
   par la vérification d'accès de l'application.
6. Déployer d'abord sur la recette avec une copie/anonymisation appropriée des
   données si des tests métier sont nécessaires. Ne jamais lancer les tests
   d'intégration contre une base contenant les données réelles : ces tests
   créent et suppriment leurs propres comptes et enregistrements.

### HTTPS

- Utiliser un certificat valide renouvelé automatiquement (par exemple
  ACME/Let's Encrypt), TLS 1.2 minimum, TLS 1.3 recommandé. Rediriger HTTP vers
  HTTPS côté serveur et tester le renouvellement du certificat.
- Configurer `base_url` en HTTPS. Les cookies de session sont marqués `Secure`
  lorsque PHP voit `HTTPS` actif. En terminaison TLS par proxy, configurer le
  serveur FastCGI/PHP de manière à transmettre le statut HTTPS depuis **ce proxy
  de confiance**. Ne pas accepter aveuglément `X-Forwarded-Proto` fourni par
  l'internaute.
- Si un proxy inverse est utilisé, configurer `REMOTE_ADDR` avec le module de
  proxy/IP client de l'hébergeur et ne faire confiance qu'aux adresses du proxy.
  L'application utilise `REMOTE_ADDR` pour la limitation des tentatives ; ne
  pas faire confiance à un `X-Forwarded-For` arbitraire.
- N'activer HSTS qu'après validation complète de HTTPS. Commencer sans
  `includeSubDomains` ni `preload`, puis définir une politique adaptée au domaine.

Apache et PHP-FPM sont supportés par leurs mécanismes standards. Pour Nginx,
utiliser un `root` vers `public/`, un `try_files` pour les fichiers existants,
`fastcgi_pass` vers le socket PHP-FPM et une règle explicite qui refuse les
fichiers cachés et n'exécute que les scripts PHP présents dans `public/`.
Faire vérifier le bloc serveur par l'administrateur Nginx avant ouverture.

## 5. Permissions des fichiers et erreurs

Exemple de principe sous Linux (adapter les utilisateurs et groupes à
l'hébergement ; ne jamais appliquer `777`) :

- Code et actifs : propriétaire de déploiement, lecture seule pour le compte
  PHP-FPM ; répertoires `0750` et fichiers `0640` ou plus restrictifs.
- `config/local.php` : propriétaire `root`/compte de déploiement, lecture
  uniquement par le groupe nécessaire au PHP-FPM (`0640` au plus large).
- `storage/` et sous-dossiers : seul le compte PHP-FPM peut y écrire ; répertoires
  `0750`/`0770` selon les groupes réellement nécessaires.
- Base : accès local/privé, compte applicatif sans droits globaux.

Le bootstrap désactive `display_errors`, active `E_ALL` et écrit les détails
dans `storage/logs/app.log`; les pages web renvoient un message générique en
production. Vérifier que le compte PHP-FPM peut écrire dans le journal et
surveiller les erreurs 500. Conserver les journaux hors webroot, limiter leur
accès, mettre en place une rotation côté système et ne pas les rendre publics.
Les logs peuvent contenir des messages d'exception : appliquer une rétention
et un accès limités, sans y ajouter de secrets.

Avant le lancement, vérifier qu'une exception testée sur la recette affiche
un message générique au navigateur et inscrit le détail dans le journal privé.
Ne pas provoquer artificiellement cette erreur sur la production réelle.

## 6. Base, installation initiale et migrations

### Installation neuve

Le fichier [`database/schema.sql`](database/schema.sql) est le schéma initial
complet actuel (49 tables après ajout des permissions individuelles par exploitation).
Importer ce fichier dans
une base vide `utf8mb4`, sous le compte de migration, puis vérifier les tables
avec `public/health.php` depuis un accès autorisé. Le schéma crée les tables,
pas l'utilisateur SQL, le compte administrateur ni les données utilisateur.

Exemple d'import avec un fichier de paramètres SQL de migration protégé et
stocké hors du dépôt :

```sh
mysql --defaults-extra-file=/run/secrets/mon-elevage-migration.cnf \
  mon_elevage_prod < database/schema.sql
```

Le schéma crée également les tables de formation, mais les contenus initiaux
sont fournis par `database/migrations/007_create_training_guide.sql`. Pour un
import manuel neuf, appliquer ce fichier **une fois après** `schema.sql`; il
charge neuf modules et dix-huit leçons. Le script local
`scripts/initialize-local-database.php` applique le même seed automatiquement.
Ne pas appliquer les autres migrations `002`–`006` ou `008`–`018` après le
schéma complet : elles sont déjà représentées dans celui-ci ou ne sont pas
conçues pour une réexécution aveugle.

### Mise à niveau d'une base existante

Les fichiers incrémentaux se trouvent dans
[`database/migrations/`](database/migrations/), dans cet ordre :

1. `002_create_user_consents.sql`
2. `003_record_farm_authorization.sql`
3. `004_create_farm_cycle_settings.sql`
4. `005_extend_flocks_management.sql`
5. `006_create_flock_stages.sql`
6. `007_create_training_guide.sql`
7. `008_extend_daily_followups.sql`
8. `009_create_flock_performance.sql`
9. `010_create_stock_catalog.sql`
10. `011_create_finance_module.sql`
11. `012_create_health_weather_notifications.sql`
12. `013_create_commercial_module.sql`
13. `014_create_private_reports.sql`
14. `015_create_system_administration.sql`
15. `016_create_pending_registrations.sql`
16. `017_create_farm_member_permission_overrides.sql`
17. `018_create_oauth_identities.sql`

Appliquer la migration `016` sur une base existante avant d'activer les nouvelles
inscriptions. Les installations neuves créées depuis `schema.sql` possèdent déjà
la table correspondante.

Appliquer également `017` avant d'utiliser la configuration des permissions
individuelles par utilisateur et exploitation. Les installations neuves créées
depuis `schema.sql` possèdent déjà cette table.

Appliquer `018` avant d'activer l’authentification Google sur une base existante.
Configurer `MON_ELEVAGE_GOOGLE_CLIENT_ID`, `MON_ELEVAGE_GOOGLE_CLIENT_SECRET` et
`MON_ELEVAGE_GOOGLE_REDIRECT_URI` avec les identifiants OAuth du projet Google
et l’URI de rappel HTTPS exacte déclarée dans la console Google. Les comptes déjà
existants ne sont jamais liés automatiquement sur la seule adresse e-mail.

Il n'existe pas encore de table de suivi des migrations ni de commande de migration automatique.
Pour une base existante, **ne pas appliquer les fichiers à l'aveugle** :

1. identifier et sauvegarder le schéma et sa version connue ;
2. comparer chaque migration à la base cible et confirmer son état déjà appliqué ;
3. tester la séquence exacte sur une copie de la base avec la même version MySQL ;
4. prendre une sauvegarde restaurable, planifier une fenêtre de maintenance et
   appliquer chaque migration dans l'ordre avec un compte habilité ;
5. consigner révision, fichier, somme de contrôle, date, opérateur, sortie SQL et
   résultat dans le registre d'exploitation ;
6. vérifier les contraintes, les comptages métier, `/health.php`, les tests
   d'acceptation et les journaux avant de rouvrir les écritures.

Le DDL MySQL peut provoquer des validations implicites : ne pas supposer qu'un
`ROLLBACK` annule une migration. Préparer une procédure de restauration testée.
Créer un véritable gestionnaire de migrations et un registre versionné est un
travail technique recommandé avant de multiplier les mises à jour régulières.

## 7. Compte administrateur initial

L'interface ne permet pas de s'attribuer le rôle administrateur. Après
configuration du courrier, créer et vérifier le compte nominatif du responsable
du site via le flux normal d'inscription. Puis, depuis une session SQL
d'administration sécurisée, promouvoir uniquement son adresse confirmée :

```sql
UPDATE users
SET is_admin = 1
WHERE email = 'ADMIN_REEL_VERIFIE@example.org'
  AND status = 'active'
  AND email_verified_at IS NOT NULL;

SELECT ROW_COUNT() AS comptes_promus;
SELECT id, email, status, is_admin
FROM users
WHERE email = 'ADMIN_REEL_VERIFIE@example.org';
```

Remplacer l'adresse fictive avant exécution. Le nombre de lignes modifiées doit
être exactement `1`; sinon, arrêter et diagnostiquer. Utiliser une connexion
administrateur dédiée, conserver une trace de l'approbation et fermer la session
SQL. Ne jamais promouvoir un compte de test ni utiliser un identifiant partagé.
L'accès administrateur est global à l'installation et doit être attribué au
minimum de personnes possible.

## 8. Courrier, météo et tâches planifiées

### Services externes

- **Courriel :** PHPMailer envoie le code de création du compte et les liens de
  récupération via un relais SMTP authentifié en TLS. Fournir
  `MON_ELEVAGE_MAIL_FROM`, `MON_ELEVAGE_SMTP_HOST`, `MON_ELEVAGE_SMTP_PORT`,
  `MON_ELEVAGE_SMTP_USERNAME`, `MON_ELEVAGE_SMTP_PASSWORD` et
  `MON_ELEVAGE_SMTP_ENCRYPTION` dans le coffre de secrets de l'hôte ; choisir
  `mail.transport = smtp` et une adresse expéditrice autorisée. Configurer SPF,
  DKIM et DMARC. Ne jamais placer ces secrets dans Git ou dans le chat. Tester
  une réception réelle, les renvois, l'expiration à une minute et la récupération
  de mot de passe sur une boîte contrôlée ; une acceptation SMTP ne prouve pas à
  elle seule la réception en boîte.
- **Météo :** Open-Meteo n'exige pas de clé dans l'intégration actuelle. Saisir
  latitude/longitude valides, autoriser HTTPS sortant et vérifier cache, date de
  dernière mise à jour et état d'indisponibilité. Les prévisions extérieures ne
  sont pas des mesures du poulailler. La météo est chargée lors de la consultation
  du module ; aucun scheduler dédié n'est défini.

Aucun cron applicatif, worker en arrière-plan, paiement, SMS ou notification
push n'est livré. Les échéances et alertes sont calculées dans les vues de
l'application. Les tâches d'exploitation à planifier sont :

| Fréquence suggérée | Tâche | Remarque |
|---|---|---|
| Quotidienne | Sauvegarde cohérente de la base et des PDF privés ; copie chiffrée hors site ; vérification du code de sortie | Définir les RPO/RTO et la rétention avec le responsable métier. |
| Quotidienne | Vérifier espace disque, disponibilité HTTP, certificat TLS et erreurs PHP | Une sonde ne doit pas exposer de secrets ni d'informations personnelles. |
| Continue / rotation quotidienne | Rotation, limitation et purge contrôlée des journaux | Conserver selon la politique de rétention approuvée. |
| Périodique, après chaque version | Restauration réelle en recette et contrôle d'intégrité | Une sauvegarde non restaurée n'est pas validée. |
| Mensuelle ou selon politique | Revue des comptes administrateurs, rôles, accès SQL, mises à jour OS/PHP/Composer | Garder le système et les dépendances corrigés. |

La purge automatique des anciens jetons d'authentification et tentatives n'est
pas fournie. Définir une durée de conservation avant d'ajouter une tâche SQL de
nettoyage. Ne pas supprimer automatiquement les rapports privés ou les données
métier sans politique de conservation et besoin validés.

## 9. Sauvegardes et restauration

Sauvegarder au minimum :

- une exportation cohérente complète MySQL avec `mysqldump` (tables, triggers,
  routines et événements s'il y en a) ;
- `storage/reports/` et toute donnée privée créée hors base ;
- le code exact de la release déployée et ses fichiers de verrouillage ;
- la configuration de production dans un coffre sécurisé séparé. Ne jamais
  déposer le secret en clair à côté des sauvegardes ni dans un dépôt Git.

Exemple d'export logique, à exécuter avec un fichier de paramètres MySQL protégé
(`0600`) ou le coffre de l'hébergeur, et non un mot de passe dans la ligne de
commande :

```sh
mysqldump --defaults-extra-file=/run/secrets/mon-elevage-mysql.cnf \
  --single-transaction --routines --events --triggers --hex-blob \
  --default-character-set=utf8mb4 mon_elevage_prod > /backup/mon_elevage.sql
```

Chiffrer avant transfert et stockage hors de l'hôte. Définir et documenter une
durée de rétention (exemple à valider : sauvegardes quotidiennes et mensuelles)
et des objectifs RPO/RTO avec les responsables ; aucune politique de rétention
n'est actuellement configurée par le projet.

### Restauration

1. Déclarer l'incident, suspendre les écritures et choisir une sauvegarde
   antérieure à l'incident. Noter le point de restauration et les pertes
   potentielles.
2. Restaurer dans une base isolée ou un environnement de secours, jamais
   directement sur l'unique copie disponible. Réinstaller la release et
   récupérer `storage/reports/` correspondant au même point de sauvegarde.
3. Charger les secrets depuis le coffre, vérifier propriétaire et permissions,
   puis importer la base dans une base neuve dédiée, par exemple :

   ```sh
   mysql --defaults-extra-file=/run/secrets/mon-elevage-mysql.cnf \
     mon_elevage_restore < /backup/mon_elevage.sql
   ```
4. Exécuter le diagnostic de santé, vérifier le schéma, l'ouverture de session,
   les permissions multi-exploitation, un téléchargement autorisé et un refus
   d'accès à un rapport d'un autre utilisateur.
5. Réconcilier l'intégrité des rapports référencés avec les fichiers restaurés,
   tester les pages principales et obtenir l'accord du responsable métier.
6. Basculer le trafic, surveiller erreurs et métriques, puis consigner l'incident.

Répéter ce test régulièrement et après toute modification de sauvegarde. Ne pas
considérer `mysqldump` comme seul plan de reprise.

## 10. Versions et dépendances

| Composant | Version / politique |
|---|---|
| PHP | Minimum syntaxique du projet : PHP 8.2 ; cible de production recommandée : PHP 8.3 maintenu ou supérieur, après tests. Suite exécutée sur 8.3.28. |
| MySQL | Recommandation : MySQL 8.4 LTS. Schéma déclaré compatible MySQL 8.0.16+ ; base vérifiée : 8.4.7. MariaDB 10.6+ est mentionné par le schéma mais non validé par les tests disponibles. |
| Composer | Branche 2.x ; utiliser `composer.lock`, `composer install`, jamais `composer update` en déploiement. |
| `dompdf/dompdf` | 3.1.6 |
| `dompdf/php-font-lib` | 1.0.2 |
| `dompdf/php-svg-lib` | 1.0.2 |
| `masterminds/html5` | 2.12.0 |
| `sabberworm/php-css-parser` | 9.5.0 |
| Chart.js | 4.5.1 dans les actifs distribués (`public/assets/vendor/chart.umd.js`). |
| Node.js/npm | Non requis au runtime ; utiles uniquement pour mettre à jour l'actif à partir des fichiers verrouillés. |

Réviser les avis de sécurité des dépendances avant chaque release. Toute montée
de version doit passer par les tests de l'application et une recette.

## 11. Vérifications après déploiement et critères de go/no-go

Depuis un poste autorisé et l'environnement cible :

- [ ] DNS pointe vers l'hôte voulu ; HTTP redirige vers HTTPS ; certificat et
      renouvellement valides.
- [ ] Le document root sert `public/`; les URL `/config/`, `/database/`,
      `/storage/`, `/.git/`, `/vendor/`, `/tests/` et un fichier `.env` sont
      inaccessibles (404/403), sans contenu sensible.
- [ ] `health.php` répond correctement depuis localhost ou une sonde
      explicitement autorisée : PHP, extensions, PDO et toutes les tables.
      Ne pas rendre ce diagnostic détaillé accessible publiquement sans contrôle.
- [ ] Page de connexion et inscription répondent sans erreur 500 ; session
      cookie `Secure`, `HttpOnly`, `SameSite=Lax`; CSRF et limitation d'essais
      fonctionnels.
- [ ] Inscription, réception et confirmation du code valable une minute,
      renvoi, réinitialisation, connexion et déconnexion testées avec des boîtes
      de recette et des liens HTTPS.
- [ ] Création d'une exploitation et d'un élevage de recette ; séparation de
      deux comptes ; refus d'accès aux ressources de l'autre compte.
- [ ] Tests fonctionnels des achats, seuils et stocks non négatifs, dépenses,
      ventes insuffisamment approvisionnées, paiements et annulations selon
      les rôles.
- [ ] Rapport PDF généré dans `storage/reports/`, consultable par son propriétaire
      et refusé aux autres comptes ; aucune URL directe publique au fichier.
- [ ] Les erreurs 500 affichent un texte générique et restent dans les journaux
      privés ; aucune erreur SQL, secret ou trace PHP n'est divulguée.
- [ ] Sauvegarde créée, chiffrée, restaurée en recette et comparée aux fichiers
      privés ; procédures d'incident et contacts connus.
- [ ] Conditions d'utilisation et politique de confidentialité revues, complétées
      et approuvées pour le pays et l'organisation qui exploitent le service.
- [ ] Compte administrateur nominatif créé, vérifié, promu par procédure
      contrôlée, puis accès vérifié ; aucun compte temporaire résiduel.
- [ ] Pages et formulaires clés vérifiés sur mobile et navigateur pris en charge.

Toute case non cochée bloque la déclaration de succès. Refaire le diagnostic et
les vérifications applicables après chaque déploiement. Le contrôle automatique
ne prouve pas l'absence de vulnérabilité.

## 12. Références du dépôt

- Schéma initial : [`database/schema.sql`](database/schema.sql)
- Évolutions SQL : [`database/migrations/`](database/migrations/)
- Exemple de configuration : [`config/production.php.example`](config/production.php.example)
- Procédure WAMP de développement : [`README-INITIALISATION-WAMP.md`](README-INITIALISATION-WAMP.md)
- Guide utilisateur : [`GUIDE-UTILISATEUR.md`](GUIDE-UTILISATEUR.md)
- Guide technique de maintenance : [`GUIDE-MAINTENANCE.md`](GUIDE-MAINTENANCE.md)
- Tests d'intégration : [`tests/`](tests/)
