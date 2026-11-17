# Initialisation locale — Mon Élevage (Windows / WampServer)

Guides : [déploiement en production](DEPLOIEMENT-PRODUCTION.md) ·
[utilisation](GUIDE-UTILISATEUR.md) ·
[maintenance technique](GUIDE-MAINTENANCE.md).

**État courant :** base locale sur MySQL 8.4.7 ; les migrations `010` à `017` couvrent le catalogue, les finances, la santé, le commercial, les rapports privés, l’administration système, la confirmation d’inscription et les permissions individuelles par exploitation. Apache, MySQL et MariaDB sont démarrés. L’alias Apache `http://localhost/mon-elevage/` n’est pas encore installé.

## 1. Résultat du contrôle de l’environnement

Contrôles actualisés le 1er novembre 2026 :

| Composant | Détecté | État |
|---|---|---|
| WampServer | 3.4.0, 64 bits | Installé dans `C:\wamp64` |
| PHP WAMP sélectionné | 8.3.28 selon `wampmanager.conf` | Version Apache configurée |
| PHP CLI choisi pour les commandes | 8.3.28 | `C:\wamp64\bin\php\php8.3.28\php.exe` |
| Extensions CLI | `PDO`, `pdo_mysql`, `mysqli`, `openssl`, `mbstring`, `dom` | Disponibles sous PHP 8.3.28 |
| MySQL | 8.4.7 | Configuré sur le port 3306 |
| MariaDB | 11.4.9 | Installé, port 3307 |
| Apache | 2.4.65 | Configuré sur le port 80 |
| Composer | Non trouvé dans le PATH ni dans les emplacements WAMP contrôlés | Dompdf est requis pour générer les rapports PDF ; voir la section 5 |
| Services WAMP | Apache et MySQL démarrés après le contrôle initial ; MariaDB également démarrée | MySQL écoute sur 3306 et a été utilisé. MariaDB est sur 3307 et n’a pas été utilisée. |

**Choix de base pour cette initialisation : MySQL 8.4.7, port 3306**, afin d’utiliser l’instance principale configurée dans WampServer. Ne pas démarrer MySQL et MariaDB ensemble sur le même port.

Avant un déploiement futur, faire correspondre la version PHP Apache et CLI pour éviter des différences de configuration. Pour les tests ci-dessous, le chemin CLI explicite garantit que les commandes utilisent bien PHP 8.2.29 et son pilote `pdo_mysql`.

## 2. Chemin du projet proposé

Le dossier conservé comme racine projet est :

```text
C:\Users\KONE\Desktop\Gestion_Plet
```

Ce choix garde ensemble le cahier des charges, l’architecture, le schéma, les maquettes et le code d’initialisation déjà présents ; aucun fichier existant n’a été déplacé ou remplacé.

**Document root public :**

```text
C:\Users\KONE\Desktop\Gestion_Plet\public
```

Seul ce sous-dossier doit être exposé par Apache. Ne pas utiliser la racine du dépôt comme document root : elle contient le schéma SQL, les documents et la configuration locale.

## 3. Arborescence préparée

```text
Gestion_Plet\
├── app\
│   ├── Database\
│   │   └── Connection.php
│   ├── Auth\                     # Comptes, permissions, limitation de débit
│   ├── Support\
│   │   ├── AuthSession.php
│   │   └── HealthCheck.php
│   ├── Flocks\
│   │   └── FlockService.php
│   ├── Followups\
│   │   └── DailyFollowupService.php
│   ├── Stock\
│   │   └── StockService.php
│   ├── Sales\
│   │   └── SalesService.php
│   ├── Reports\
│   │   ├── ReportService.php     # Données, versions et accès privé aux rapports
│   │   └── ReportPdfRenderer.php # Mise en page PDF UTF-8 avec Dompdf
│   ├── Training\
│   │   └── TrainingService.php
│   ├── Views\
│   │   ├── Components\            # Éléments d’interface réutilisables
│   │   ├── Layouts\               # Gabarit commun
│   │   └── Pages\                 # Démonstrations et pages privées dynamiques
│   └── bootstrap.php
├── config\
│   ├── local.php                 # Secrets et configuration locale, ignoré par Git
│   └── local.php.example         # Exemple sans secret
├── database\
│   ├── schema.sql                # Schéma initial, incluant maintenant user_consents
│   └── migrations\               # Migrations incrémentales
├── maquettes\
├── public\
│   ├── .htaccess
│   ├── auth.php                  # Inscription, connexion, vérification et récupération
│   ├── flocks.php                # Gestion privée des élevages
│   ├── followups.php             # Saisie et historique des suivis quotidiens
│   ├── stock.php                 # Catalogue, fournisseurs, achats et stocks
│   ├── finance.php               # Dépenses FCFA, budgets et justificatifs privés
│   ├── reports.php               # Génération, historique et téléchargement privé des rapports PDF
│   ├── health-monitoring.php     # Suivi sanitaire, prévisions et notifications
│   ├── sales.php                 # Clients, ventes, paiements et livraisons
│   ├── sales-receipt.php         # Reçu de vente privé imprimable
│   ├── training.php              # Guide et progression par compte
│   ├── admin-training.php        # Gestion administrateur des contenus
│   ├── admin.php                 # Console d’administration et contrôle des accès
│   ├── health.php                # Diagnostic local protégé
│   ├── index.php                 # Tableau de bord dynamique privé
│   ├── legal.php                 # Conditions et confidentialité (versions provisoires)
│   ├── logout.php
│   ├── profile.php               # Profil privé et adhésions du compte
│   └── assets\                   # CSS et JavaScript publics
├── scripts\
│   └── initialize-local-database.php # Initialisation protégée, à lancer une seule fois
├── tests\
│   ├── authentication.php        # Tests d’intégration PDO, session et permissions
│   ├── dashboard.php             # Tests d’intégration du tableau de bord
│   ├── daily_followups.php       # Tests du suivi, des mouvements et des droits
│   ├── farm_management.php       # Tests du cycle de vie des élevages
│   ├── flock_calendar.php        # Tests d’intégration du calendrier
│   ├── training_guide.php        # Tests de contenu, progression et permissions
│   ├── performance.php           # Tests des pesées, œufs et objectifs documentés
│   ├── stock_catalog.php         # Tests du catalogue, des achats et des stocks
│   ├── finance.php               # Tests financiers, budgets, permissions et écritures de stock
│   ├── profitability.php         # Tests d’intégration du bilan et des coûts sans double comptage
│   ├── reports.php               # Tests de contenu, PDF réel, historique et cloisonnement des comptes
│   ├── admin.php                 # Tests d’accès, utilisateurs, rôles, référentiels et audit
│   ├── health_monitoring.php     # Tests sanitaires, météo et notifications
│   └── sales.php                 # Tests d’intégration des ventes, stocks, paiements et rôles
├── storage\
│   ├── reports\                  # PDF privés, hors du répertoire public
│   ├── exports\
│   └── logs\
├── wamp\
│   └── mon-elevage.conf          # Modèle d’alias Apache localhost
├── .gitignore
├── composer.json                # Dépendance PDF Dompdf
├── composer.lock                # Versions verrouillées des dépendances PHP
└── README-INITIALISATION-WAMP.md
```

### Squelette visuel de démonstration

La page d’accueil (`public\index.php`) présente le gabarit partagé, le menu latéral,
l’en-tête, les composants de contenu et la navigation responsive. Les vues sont rangées
dans `app\Views\Layouts`, `app\Views\Components` et `app\Views\Pages`. La feuille
globale et le comportement du menu sont dans `public\assets\css\app.css` et
`public\assets\js\app.js`.

Les aperçus visuels initiaux restent conservés dans les vues et maquettes de démonstration.
La page `public\index.php` est maintenant le tableau de bord dynamique : elle requiert
une session active, vérifie l’adhésion à l’exploitation sélectionnée et lit les données
réelles du schéma. `public\flocks.php` permet de créer, consulter, modifier, suspendre,
reprendre, clôturer et archiver les élevages par exploitation. Les cinq types avicoles
sont disponibles. Coût initial et budget sont conservés dans la devise de l’exploitation ;
le coût initial est informatif et ne crée pas de dépense. Aucun indicateur de production
fictif n’est affiché. Les projections de cycle sont explicitement signalées.
`flocks.php?view=calendar&id=...` donne accès au calendrier vide et personnalisable
d’un élevage : les titres, descriptions, dates et priorités sont définis par l’éleveur.
La progression réelle est le nombre d’étapes terminées divisé par le nombre total
d’étapes applicables, multiplié par 100 et arrondi à l’entier inférieur ; sans étape,
elle est indisponible. Les durées de cycle paramétrées ne produisent que des estimations
de vente et ne créent ni jalons ni recommandations.
`training.php` présente les neuf modules de formation et les leçons publiées. La progression
est propre au compte connecté. `admin-training.php` est réservé aux comptes dont le champ
`users.is_admin` est activé par un administrateur de base de données; cette permission
n’est jamais attribuée lors d’une inscription. Les brouillons et archives ne sont pas
visibles dans le guide. Les contenus de départ citent des ressources FAO, WOAH ou Codex
et rappellent les limites locales; aucune posologie, aucun diagnostic ni protocole
vétérinaire individualisé n’est fourni.
`followups.php` permet aux membres autorisés d’enregistrer une observation par élevage et
par date, puis de filtrer l’historique. L’effectif final est calculé comme l’effectif
d’ouverture + les entrées - les mortalités - les ventes - les autres sorties, en incluant
une seule fois les autres mouvements déjà inscrits au registre. Les sujets malades ne sont
pas soustraits. Une correction met à jour les mouvements associés à la fiche et recalcule
les effectifs des jours suivants. Si une catégorie de mouvement est déjà inscrite
séparément le même jour, sa quantité doit correspondre au total saisi dans le suivi :
aucun deuxième mouvement n’est alors créé. Les mesures d’aliment, d’eau, de température, d’humidité
et de poids sont facultatives ; les observations ne produisent pas de diagnostic médical.

`performance.php?farm_id=...&flock_id=...` permet de saisir une pesée par élevage et par
date, de filtrer les historiques et, pour les pondeuses, de consigner collecte, œufs
cassés, ventes et stock. Le poids moyen en g/tête est le poids total de l’échantillon en
grammes divisé par le nombre d’animaux pesés. Le gain moyen en g/jour est la différence
entre les deux dernières moyennes observées divisée par le nombre de jours écoulés ; il
reste indisponible sans deux mesures datées distinctes. Les poids moyens déjà saisis dans
les anciennes fiches quotidiennes sont conservés comme observations historiques et ne
sont pas copiés ni présentés comme un échantillon détaillé.

Le stock d’œufs est calculé comme stock d’ouverture + œufs collectés - œufs cassés -
œufs vendus. Les ventes peuvent donc provenir d’une collecte antérieure. La première
journée permet de documenter un stock initial existant avant l’utilisation du site ;
les jours suivants reprennent le solde calculé. Une correction historique recalcule les
soldes suivants dans une transaction et est annulée si elle entraîne un stock négatif.
Le ratio affiché pour les pondeuses est « œufs collectés ÷ effectif vivant en fin de
journée × 100 » ; il est descriptif et ne constitue pas un taux par femelle, car le
nombre de sujets femelles productifs n’est pas enregistré.
La quantité d’œufs vendus dans ce registre sert au calcul du stock et ne crée pas de
vente monétaire ni de paiement ; consignez séparément le montant dans le module Ventes
si vous devez suivre le chiffre d’affaires.

Les objectifs de poids, de gain et de collecte sont facultatifs et n’ont aucune valeur
par défaut. Un responsable peut documenter leur source, leur contexte et leur période ;
deux objectifs d’un même indicateur ne peuvent pas avoir de périodes qui se chevauchent.
Les comparaisons ne sont affichées que pour des mesures couvertes par une référence
documentée. Chart.js 4.5.1 est fourni localement sous `public\assets\vendor` (licence MIT) :
les graphiques n’utilisent pas de CDN et ne nécessitent aucun service payant ni Node.js
à l’exécution.

`stock.php?farm_id=...` regroupe le catalogue, les fournisseurs, les achats, les
mouvements et les inventaires. Les produits et fournisseurs sont archivés, jamais
supprimés. Un achat multi-lignes inscrit atomiquement l’achat, une dépense unique, les
lots reçus et les mouvements positifs correspondants. Le stock courant est la somme
des mouvements ; chaque écriture verrouille le produit dans une transaction et refuse
un solde négatif. Les consommations de consommables allouent les lots en FEFO
(péremption la plus proche d’abord), excluent les lots déjà périmés et conservent le lien
avec l’élevage. Les équipements réutilisables sont sortis puis restitués par lot ; une
restitution ne dépasse pas la quantité encore affectée. Les inventaires conservent le
stock attendu, le comptage réel, l’écart et sa justification dans le registre.
Les alertes de stock apparaissent lorsque le niveau est inférieur ou égal au seuil ;
les lots expirés ou expirant dans les 30 jours sont signalés. Cette fenêtre d’alerte est
informatique et ne remplace pas les instructions de conservation du fabricant.
Les permissions `stock.view` et `stock.manage` respectent l’adhésion active à
l’exploitation ; les opérateurs peuvent consulter mais pas modifier.

`finance.php?farm_id=...` permet aux rôles autorisés de consulter les dépenses par
période et élevage, de saisir des dépenses en FCFA (XOF) avec un justificatif privé PDF,
JPEG ou PNG, et de créer, modifier ou archiver des budgets prévisionnels. Les montants
sont des FCFA entiers ; les fractions de franc sont refusées et les lignes d’achat sont
arrondies au franc avant leur enregistrement. Chaque budget compare ses postes de même
catégorie et nature avec le réalisé ; le restant devient négatif en cas de dépassement.
Les prévisionnels actifs et leurs écarts par nature sont également visibles au tableau
de bord pour les comptes ayant la permission financière.
Une dépense est annulée avec motif plutôt que supprimée afin de conserver sa trace.

Le bilan de rentabilité est visible à tous les rôles ayant la permission `expense.view`.
Il sépare les recettes des encaissements, les créances, les dépenses enregistrées et
les coûts économiques. Les achats de consommables ne deviennent des coûts qu’à leur
consommation ou à leur vente ; le stock restant est valorisé séparément. Le matériel
réutilisable est affiché comme investissement, sans être déduit du bénéfice. Le coût par
animal vendu est une approximation des coûts attribués à la période et n’inclut pas les
coûts d’acquisition historiques. Les dépenses saisies sont traitées comme sorties de
trésorerie, le schéma ne distinguant pas celles qui restent à payer. Les tableaux restent
disponibles si les graphiques locaux ne peuvent pas se charger.

Les indicateurs gardent séparés les flux de trésorerie, les charges consommées et les
investissements réutilisables : un achat de stock alimente la trésorerie une seule fois ;
les consommables contribuent aux charges lorsqu’ils sont consommés, au coût des lots
achetés ; les équipements ne contribuent aux investissements qu’à l’achat. Une dépense
liée à un achat est gérée depuis le module de stock et ne peut pas être modifiée ou
annulée une seconde fois dans l’écran financier. Les écritures et les justificatifs
respectent les permissions de l’exploitation et les modifications liées sont
transactionnelles.

`health-monitoring.php?farm_id=...` rassemble le registre des signes observés,
interventions vétérinaires, vaccinations et traitements consignés à partir des consignes
d’un professionnel. Les observations réelles du bâtiment proviennent du suivi quotidien ;
elles restent clairement séparées des prévisions extérieures Open-Meteo, disponibles
après saisie des coordonnées de l’exploitation et documentées sur
`https://open-meteo.com/en/docs`. Aucune maladie n’est diagnostiquée et aucun médicament
n’est prescrit. Les situations préoccupantes recommandent de contacter un vétérinaire.
Les prévisions sont mises en cache trois heures ; en cas d’erreur réseau, la page signale
l’indisponibilité et affiche, si possible, la date et les dernières données reçues. Les
alertes sanitaires, climatiques, de stock, de péremption et de calendrier indiquent leur
priorité, leur origine, leur date et une action suggérée. Les notifications sont privées
par compte et dédupliquées. Les rôles propriétaire et gestionnaire peuvent saisir des
événements et configurer la météo ; les autres rôles n’accèdent pas au registre.

`sales.php?farm_id=...` gère les clients, les ventes, les paiements et le statut des
livraisons. Une commande est confirmée comme vente lors de sa création : les poulets
sont débités de l’effectif de leur élevage et les œufs ou autres produits avicoles sont
débités des lots disponibles (FEFO), dans une transaction. Une quantité insuffisante
annule toute l’opération. Les prix et totaux sont en FCFA entiers ; le reste dû est
calculé à partir des paiements reçus. Une annulation reste traçable, restaure les stocks
et effectifs par des mouvements inverses et inscrit le remboursement des paiements nets.
Le reçu est une page privée à imprimer ou à enregistrer en PDF depuis le navigateur,
sans génération de fichier sur le serveur. Les propriétaires et gestionnaires peuvent
gérer clients, ventes et livraisons ; le rôle comptable consulte les ventes et peut
enregistrer des paiements, sans créer ni annuler de ventes.

Pour attribuer ce rôle, créez d’abord le compte par le parcours normal, puis exécutez
comme administrateur de base de données une requête ciblant une adresse vérifiée et
un compte actif :

```sql
UPDATE users
SET is_admin = 1
WHERE email = 'adresse-administrateur-verifiee@example.com'
  AND status = 'active';
```

Contrôlez que la requête a modifié exactement le compte prévu. N’utilisez pas cette
permission pour les comptes éleveurs ordinaires.

Le menu mobile s’ouvre depuis le bouton en-tête, se ferme sur Escape, clic extérieur
ou navigation et rend le focus au bouton en cas de fermeture au clavier. Les tableaux
gardent leur largeur lisible dans une zone de défilement horizontale sur petit écran.
Le tableau de bord est protégé par l’authentification et l’adhésion à l’exploitation ;
il présente les données réelles disponibles et identifie explicitement les estimations
et les indicateurs non calculables avec le schéma actuel.

### Module d’authentification

L’authentification s’appuie sur le schéma initial et les migrations
`database/migrations/002_create_user_consents.sql`,
`database/migrations/003_record_farm_authorization.sql` et
`database/migrations/004_create_farm_cycle_settings.sql`,
`database/migrations/005_extend_flocks_management.sql` et
`database/migrations/006_create_flock_stages.sql`,
`database/migrations/007_create_training_guide.sql` et
`database/migrations/008_extend_daily_followups.sql`, notamment `users`,
`user_consents`, `farm_members`, `auth_tokens`, `pending_registrations` et `login_attempts`. Sur une base
historique, exécutez les migrations dans l’ordre. L’inscription exige trois cases
indépendantes : l’acceptation des conditions, celle de la politique de confidentialité
et la confirmation d’autorisation pour l’exploitation. Les versions acceptées, cette
confirmation et leur horodatage UTC sont conservés avec le compte après sa création.
Avant le code correct, seules les données minimales d’une inscription temporaire sont
stockées ; l’utilisateur, son exploitation, ses consentements et son adhésion `owner`
sont créés ensemble dans une transaction après validation d’un code à six chiffres
valable une minute. Les codes sont hachés, limités à cinq essais et renvoyables.
Les adhésions d’un compte désactivé sont refusées.
Les documents liés à l’inscription sont des versions provisoires. Complétez l’identité
et les coordonnées du responsable, le pays applicable et les durées de conservation
avant l’ouverture publique. Après toute modification, incrémentez la constante de
version correspondante dans `app/Auth/ConsentPolicy.php`.

Routes locales :

- `auth.php?view=register`, `login`, `verify`, `forgot` et `reset` ;
- `profile.php` pour le profil, les adhésions du compte courant et le changement de mot de passe ;
- `logout.php` pour la déconnexion POST protégée par CSRF ;
- `index.php` redirige les visiteurs vers la connexion et affiche le tableau de bord
  aux comptes connectés.

La session utilise un cookie `HttpOnly`, `SameSite=Lax`, `Secure` sous HTTPS, un ID
renouvelé à la connexion, une expiration après 30 minutes d’inactivité et une limite
absolue de 8 heures. Les formulaires POST vérifient un jeton CSRF. Les mots de passe
sont stockés via `password_hash(PASSWORD_DEFAULT)` et vérifiés avec `password_verify`.
La politique appliquée est de 12 à 72 octets (la limite haute empêche la troncature
avec l’algorithme par défaut PHP actuellement utilisé). Les réinitialisations utilisent
des jetons aléatoires à usage unique dont seule l’empreinte SHA-256 est stockée et qui
expirent après 30 minutes ; les codes de création sont à usage unique et valables une minute.

`FarmAccess` ne résout un rôle qu’à partir d’une adhésion active et d’un compte actif.
Les rôles `owner`, `manager`, `operator` et `accountant` sont attachés à l’exploitation.
Le profil ne lit que l’ID utilisateur de la session et liste uniquement ses adhésions.
Aucune route de tableau de bord ou module métier ne peut encore utiliser ces données
avant la validation de cette étape.

#### Livraison et confirmation des courriels

L’inscription envoie un code à six chiffres, valable une minute. Aucun compte,
consentement ni élevage n’est créé avant la saisie correcte du code. Les codes sont
stockés sous forme d’empreinte, expirent après cinq erreurs et peuvent être renvoyés.
La récupération du mot de passe conserve son lien à durée limitée.

Le transport SMTP authentifié est fourni par PHPMailer. Définir les variables
d’environnement Windows pour l’environnement qui exécute Apache, sans inscrire les
secrets dans Git ni dans une commande enregistrée :

| Variable | Exemple |
|---|---|
| `MON_ELEVAGE_MAIL_FROM` | Adresse expéditrice autorisée |
| `MON_ELEVAGE_SMTP_HOST` | `smtp.gmail.com` |
| `MON_ELEVAGE_SMTP_PORT` | `587` (STARTTLS) ou `465` (TLS implicite) |
| `MON_ELEVAGE_SMTP_USERNAME` | Adresse du compte d’expédition |
| `MON_ELEVAGE_SMTP_PASSWORD` | Secret SMTP ou mot de passe d’application |
| `MON_ELEVAGE_SMTP_ENCRYPTION` | `tls` avec 587, ou `smtps` avec 465 |

Configurer `mail.transport` à `smtp` et `mail.from` à une adresse autorisée par le
fournisseur dans `config\local.php` (reprendre la structure de
`config\local.php.example`), puis redémarrer Apache. Pour Gmail, un mot de passe
d’application et la validation en deux étapes sont généralement nécessaires ; ne
pas communiquer ce secret dans le chat. Le destinataire réel doit être vérifié par
un envoi vers une boîte contrôlée et par la réception effective, pas seulement par
le retour positif du serveur SMTP.

Sans transport SMTP configuré, la création du compte échoue explicitement et
l’inscription en attente est supprimée ; aucun code n’est affiché dans le navigateur
ni inscrit dans les journaux. Le formulaire de confirmation permet de renvoyer le
code après son expiration.

Sur une base historique déjà au palier `015`, sauvegardez-la puis appliquez la
migration `016` pour ajouter le stockage des inscriptions en attente. La table est
déjà présente dans `schema.sql` pour les installations neuves.

Sur une base historique au palier `016`, sauvegardez-la puis appliquez
`database/migrations/017_create_farm_member_permission_overrides.sql`. Cette
migration permet à l’administration de configurer les droits d’un utilisateur
séparément pour chaque exploitation. Le schéma initial des nouvelles installations
inclut déjà cette table.

La migration `018_create_oauth_identities.sql` ajoute les identités Google.
Pour l’activer, configurez l’identifiant client, le secret et l’URI de rappel
OAuth dans l’environnement Apache, puis déclarez cette URI dans Google Cloud.
Voir `GUIDE-CONNEXION-GOOGLE.md`; la base issue du schéma complet contient déjà
cette table.

#### Tests

Depuis la racine `C:\Users\KONE\Desktop\Gestion_Plet`, exécuter les tests d’intégration
qui créent puis suppriment leurs comptes de test sur la base locale :

```powershell
& 'C:\wamp64\bin\php\php8.2.29\php.exe' `
    'C:\Users\KONE\Desktop\Gestion_Plet\tests\authentication.php'
```

Le test utilise un transport mail factice uniquement dans le processus de test ; aucun
courriel n’est envoyé. Il vérifie les entrées invalides, l’inscription/vérification,
les identifiants incorrects et inconnus, la limitation de tentatives, le hashage,
CSRF, le cycle de session, les jetons de réinitialisation, les rôles et l’isolation
entre deux exploitations. Il ne supprime aucune donnée préexistante.

Le test du catalogue et des stocks s’exécute séparément après application de `010` :

```powershell
& 'C:\wamp64\bin\php\php8.2.29\php.exe' `
    'C:\Users\KONE\Desktop\Gestion_Plet\tests\stock_catalog.php'
```

Il couvre les achats atomiques, les dépenses, FEFO et les lots périmés, les sorties et
retours d’équipement, les inventaires, les seuils, les rôles et l’isolation. Ses données
temporaires sont propres à un suffixe aléatoire puis supprimées en fin de test.

## 4. Configuration WAMP locale

### Démarrer les services

Au premier contrôle, les trois services WAMP étaient arrêtés et Windows a refusé leur démarrage sans droits suffisants. Ils ont depuis été démarrés via Wampserver.

1. Ouvrir le menu Démarrer et lancer **Wampserver 64** avec les droits administrateur.
2. Attendre que l’icône Wampserver passe au vert.
3. Depuis son menu, démarrer **MySQL 8.4.7** et **Apache 2.4.65**. Wampserver a également démarré MariaDB 11.4.9 sur le port distinct 3307 ; le projet utilise uniquement MySQL 3306.
4. Si Wampserver est déjà lancé, utiliser son menu « Démarrer/Reprendre les services » depuis l’application élevée.

Vérification depuis PowerShell :

```powershell
Get-Service wampapache64, wampmysqld64, wampmariadb64 |
    Select-Object Status, Name
Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue |
    Where-Object { $_.LocalPort -in 80, 3306, 3307 } |
    Select-Object LocalAddress, LocalPort
```

**État vérifié :** Apache, MySQL et MariaDB sont `Running`; les ports configurés sont respectivement 80, 3306 et 3307. Les opérations du projet utilisent exclusivement MySQL 3306. Si un port est déjà occupé lors d’une reprise, ne pas modifier une autre installation sans identifier son processus.

### Créer un alias Apache sans modifier le projet

Pour servir le projet sous `http://localhost/mon-elevage/`, WAMP doit associer ce chemin à `public`. Le dossier `C:\wamp64\alias` appartient à WAMP et n’est pas inclus au dépôt.

Avant de créer l’alias, vérifier qu’il n’existe pas déjà :

```powershell
Get-ChildItem -Force 'C:\wamp64\alias' |
    Select-Object Name, FullName
Test-Path 'C:\wamp64\alias\mon-elevage.conf'
```

Le contenu prêt à copier est dans [`wamp\mon-elevage.conf`](wamp/mon-elevage.conf). Après avoir confirmé que le dernier résultat est `False`, ouvrir PowerShell en administrateur et copier le modèle sans écrasement :

```powershell
Copy-Item `
    -LiteralPath 'C:\Users\KONE\Desktop\Gestion_Plet\wamp\mon-elevage.conf' `
    -Destination 'C:\wamp64\alias\mon-elevage.conf' `
    -NoClobber
```

Redémarrer Apache depuis Wampserver, puis vérifier `http://localhost/mon-elevage/health.php`. Le réglage limite le site à l’ordinateur local et n’expose pas `config`, `database`, `storage` ou les documents. Si le fichier existe déjà, **ne pas l’écraser** : examiner sa configuration et faire valider une modification.

Alternative de diagnostic temporaire sans modifier la configuration Apache (serveur de développement PHP, lui aussi limité à la boucle locale) :

```powershell
& 'C:\wamp64\bin\php\php8.2.29\php.exe' -S 127.0.0.1:8080 `
    -t 'C:\Users\KONE\Desktop\Gestion_Plet\public'
```

Dans un second terminal, ouvrir `http://127.0.0.1:8080/health.php`. Arrêter le serveur temporaire avec `Ctrl+C` après le test. Il ne remplace pas Apache WAMP pour le déploiement.

## 5. Dépendances et Composer

Les rapports PDF utilisent Dompdf, installé avec Composer et verrouillé dans
`composer.lock` (version 3.1.6). Composer n’étant pas détecté dans le PATH sur ce poste,
les commandes suivantes doivent être lancées après installation de Composer 2.x :

```powershell
composer install --no-interaction
composer check-platform-reqs
```

Le JavaScript Chart.js 4.5.1 est livré sous `public\assets\vendor`; Node.js n’est pas
nécessaire pour servir le site. Les dépendances PHP et versions de production sont
détaillées dans [`DEPLOIEMENT-PRODUCTION.md`](DEPLOIEMENT-PRODUCTION.md).

Vérification locale :

```powershell
& 'C:\wamp64\bin\php\php8.3.28\php.exe' -m |
    Select-String 'PDO|pdo_mysql|openssl|mbstring|dom'
```

**Attendu :** `PDO`, `pdo_mysql`, `openssl`, `mbstring` et `dom`. Ne pas installer
`vlucas/phpdotenv`, framework, SDK ou autre dépendance sans besoin approuvé.

## 6. Compte applicatif et secret local

L’initialisation a créé la base `mon_elevage_dev`, l’utilisateur `mon_elevage_app` limité à cette base et un secret aléatoire de 256 bits. Le secret a été écrit directement dans `config\local.php`, fichier ignoré par Git et situé hors de `public`. Il n’a pas été affiché dans la sortie de commande et ne doit pas être copié dans un chat ou un commit.

L’accès administrateur MySQL utilisé était le compte local `root` sans mot de passe, conformément à l’installation WAMP vérifiée. L’application utilise le compte dédié, pas `root`. Avant toute exposition réseau ou mise en production, protéger également l’accès administrateur MySQL.

Sur ce poste, le script d’initialisation a déjà été exécuté. Pour un autre poste avec une base et un utilisateur absents, la commande est :

```powershell
& 'C:\wamp64\bin\php\php8.2.29\php.exe' `
    'C:\Users\KONE\Desktop\Gestion_Plet\scripts\initialize-local-database.php'
```

Le script refuse de continuer si la base ou l’utilisateur existe déjà, ou si `config\local.php` est déjà personnalisé. Il ne doit être exécuté qu’une seule fois. Si le compte MySQL root a un mot de passe, fournir localement les variables `MON_ELEVAGE_MYSQL_ADMIN_USER` et `MON_ELEVAGE_MYSQL_ADMIN_PASSWORD` dans la session PowerShell avant exécution ; ne pas mettre ces valeurs dans le dépôt.

Le fichier d’exemple `config\local.php.example` ne contient aucun secret. `.gitignore` exclut `config\local.php` du dépôt.

## 7. Import et vérification du schéma initial

Le schéma est `C:\Users\KONE\Desktop\Gestion_Plet\database\schema.sql`. Dans sa version actuelle, il crée 49 tables et des référentiels initiaux (seuils, types, catégories et calendrier) ; aucun compte de démonstration n’est créé.

Pour une vérification indépendante dans phpMyAdmin ou le client MySQL :

```sql
SHOW TABLES;
```

**Résultat historique avant la migration d’authentification :** 18 tables : `animal_movements`, `audit_logs`, `auth_tokens`, `clients`,
`daily_followups`, `expenses`, `farm_members`, `farms`, `flock_lifecycle_events`,
`flock_transfers`, `flocks`, `login_attempts`, `products`, `sale_lines`, `sale_payments`,
`sales`, `stock_movements`, `users`.

Pour une base déjà initialisée avec l’ancien schéma, appliquer une fois les migrations requises, dans l’ordre :

```powershell
$php = 'C:\wamp64\bin\php\php8.2.29\php.exe'
$code = "require 'app/bootstrap.php'; `$pdo = MonElevage\Database\Connection::get(`$config); foreach (['002_create_user_consents.sql', '003_record_farm_authorization.sql', '004_create_farm_cycle_settings.sql', '005_extend_flocks_management.sql', '006_create_flock_stages.sql', '007_create_training_guide.sql'] as `$migration) { `$pdo->exec(file_get_contents('database/migrations/' . `$migration)); }"
& $php -r $code
```

Ces migrations ajoutent `user_consents`, qui conserve les versions acceptées des deux
documents, la confirmation d’autorisation pour l’exploitation, les durées configurables
utilisées pour les estimations de vente, les champs de gestion des élevages et le
calendrier personnalisable de chaque élevage. La migration `007` crée également le guide,
ses contenus et la progression par utilisateur. Pour une base neuve créée à partir du
`schema.sql` actuel, n’exécutez pas ces migrations : les tables complètes y sont déjà
définies. Si le schéma a été importé manuellement, appliquez une fois la migration
`007` pour charger les neuf modules et dix-huit leçons de départ. Le script
`initialize-local-database.php` applique déjà ce contenu de formation. Le champ
`users.is_admin` est inclus dans le schéma ; aucun compte administrateur n’est créé ou
promu automatiquement. Le contrôle technique requiert 49 tables.

Sur une base existante déjà à jour jusqu’à la migration `007`, appliquez ensuite une
seule fois `008` :

```powershell
$code = "require 'app/bootstrap.php'; `$pdo = MonElevage\Database\Connection::get(`$config); foreach (explode(';', file_get_contents('database/migrations/008_extend_daily_followups.sql')) as `$statement) { if (trim(`$statement) !== '') { `$pdo->exec(`$statement); } }"
& $php -r $code
```

La migration `008` n’ajoute pas de table ; elle étend le suivi et relie ses opérations au
registre de mouvements. Sur une base neuve, ces colonnes et contraintes sont déjà dans
`schema.sql` : ne réappliquez pas `008`.

Sur une base historique déjà migrée jusqu’à `008`, appliquez ensuite une seule fois
`009` pour créer les pesées, les relevés d’œufs et les objectifs documentés :

```powershell
$code = "require 'app/bootstrap.php'; `$pdo = MonElevage\Database\Connection::get(`$config); foreach (explode(';', file_get_contents('database/migrations/009_create_flock_performance.sql')) as `$statement) { if (trim(`$statement) !== '') { `$pdo->exec(`$statement); } }"
& $php -r $code
```

Sur une base neuve importée depuis `schema.sql`, les trois tables sont déjà présentes :
ne réappliquez pas `009`. Les bibliothèques JavaScript locales sont verrouillées dans
`package-lock.json`; pour les reconstruire ou les mettre à jour, utilisez
`npm.cmd ci` puis copiez le fichier UMD de Chart.js et sa licence dans
`public\assets\vendor`. L’application servie par PHP n’a pas besoin de Node.js.

Sur une base historique déjà migrée jusqu’à `009`, sauvegardez d’abord la base puis
appliquez une seule fois `010` pour créer fournisseurs, lots, achats et inventaires,
ajouter les types d’équipement et relier les mouvements à leurs sources :

```powershell
$code = "require 'app/bootstrap.php'; `$pdo = MonElevage\Database\Connection::get(`$config); foreach (explode(';', file_get_contents('database/migrations/010_create_stock_catalog.sql')) as `$statement) { if (trim(`$statement) !== '') { `$pdo->exec(`$statement); } }"
& $php -r $code
```

La migration `010` est incrémentale et s’applique uniquement à une base au palier `009`.
Sur une base neuve importée depuis le schéma actuel ou sur une base où `010` a déjà été
appliquée, ne la réexécutez pas. Après application, le contrôle technique doit détecter
34 tables et les colonnes de lots, achats et inventaires.

Sur une base historique déjà migrée jusqu’à `010`, appliquez ensuite une seule fois
`011` pour ajouter le type des dépenses, les métadonnées des justificatifs et les tables
de budgets. Le module d’achats écrit une dépense `stock_purchase` avec l’achat et les
mouvements de stock dans une transaction ; cette migration est donc requise pour
enregistrer des achats :

```powershell
$code = "require 'app/bootstrap.php'; `$pdo = MonElevage\Database\Connection::get(`$config); foreach (explode(';', file_get_contents('database/migrations/011_create_finance_module.sql')) as `$statement) { if (trim(`$statement) !== '') { `$pdo->exec(`$statement); } }"
& $php -r $code
```

Sauvegardez la base avant toute migration historique. Sur une base neuve créée depuis le
`schema.sql` actuel ou une base où `011` a déjà été appliquée, ne réexécutez pas cette
migration. Le schéma complet contient 49 tables. Pour valider le catalogue, les achats,
les consommations, les ajustements et les alertes :

```powershell
& $php tests/stock_catalog.php
```

Le test d’intégration attend une base migrée au palier `011`.

Sur une base historique au palier `011`, sauvegardez d’abord la base puis appliquez une
seule fois `012` pour créer les registres sanitaires, les paramètres et instantanés météo
et les notifications privées :

```powershell
$code = "require 'app/bootstrap.php'; `$pdo = MonElevage\Database\Connection::get(`$config); foreach (explode(';', file_get_contents('database/migrations/012_create_health_weather_notifications.sql')) as `$statement) { if (trim(`$statement) !== '') { `$pdo->exec(`$statement); } }"
& $php -r $code
```

Le schéma complet contient déjà ces quatre tables : ne réexécutez pas `012` sur une
installation neuve ou une base à jour. Pour valider le suivi sanitaire, les données
incomplètes, les réponses météo, les seuils et la déduplication des notifications :

```powershell
& $php tests/health_monitoring.php
```

Sur une base historique déjà au palier `012`, sauvegardez d’abord la base puis appliquez
une seule fois `013` pour ajouter l’instantané client, le statut de livraison et les
index permettant d’allouer une ligne de vente sur plusieurs lots :

```powershell
$code = "require 'app/bootstrap.php'; `$pdo = MonElevage\Database\Connection::get(`$config); foreach (explode(';', file_get_contents('database/migrations/013_create_commercial_module.sql')) as `$statement) { if (trim(`$statement) !== '') { `$pdo->exec(`$statement); } }"
& $php -r $code
```

Le schéma complet contient déjà ces champs et index : ne réexécutez pas `013` sur une
base neuve importée depuis `schema.sql` ou une base déjà migrée. Pour valider clients,
ventes payées, partiellement payées et impayées, annulations, remboursements, stocks
insuffisants, arrondis, filtres et permissions :

```powershell
& $php tests/sales.php
```

Le bilan de rentabilité (bénéfices, pertes, coûts de stock, paiements partiels,
investissements réutilisables et stocks non valorisés) se teste avec :

```powershell
& $php tests/profitability.php
```

Le générateur `reports.php?farm_id=...` produit un PDF UTF-8 avec Dompdf, les données
d’élevage, effectifs, mortalités, croissance, alimentation, santé autorisée, achats,
dépenses, ventes et bilan financier. Chaque génération crée une version immuable.
Les fichiers sont enregistrés dans `storage\reports`, hors du document root ; l’historique
et le téléchargement sont réservés au compte qui a créé le rapport et aux membres
autorisés par `report.view`. Les dossiers sanitaires sont omis pour les rôles sans
`health.view`. Installez la dépendance PHP avec `composer install`, puis validez le PDF,
les accents, les calculs et l’isolation des utilisateurs avec :

```powershell
& $php tests/reports.php
```

Sur une base historique déjà au palier `013`, sauvegardez-la puis appliquez une seule
fois la migration `014` afin de créer l’index de rapports privés :

```powershell
$code = "require 'app/bootstrap.php'; `$pdo = MonElevage\Database\Connection::get(`$config); foreach (explode(';', file_get_contents('database/migrations/014_create_private_reports.sql')) as `$statement) { if (trim(`$statement) !== '') { `$pdo->exec(`$statement); } }"
& $php -r $code
```

Le schéma complet contient déjà la table `farm_reports` : ne réexécutez pas `014` sur
une base neuve créée depuis `schema.sql` ou une base déjà migrée.

La console `admin.php` est réservée aux comptes actifs dont `users.is_admin = 1`.
Les rôles d’exploitation et leurs permissions sont configurables dans l’administration;
ils ne confèrent jamais le statut d’administrateur du site. L’interface ne propose aucun
champ de création ou de modification de `is_admin`. Les utilisateurs administrateurs
sont provisionnés explicitement hors de cette console. Chaque modification sensible et
son entrée `audit_logs` sont validées dans une seule transaction. Les paramètres météo
restent limités à l’endpoint HTTPS documenté d’Open-Meteo.

Sur une base historique déjà au palier `014`, sauvegardez-la puis appliquez la migration
`015` une seule fois :

```powershell
$code = "require 'app/bootstrap.php'; `$pdo = MonElevage\Database\Connection::get(`$config); foreach (explode(';', file_get_contents('database/migrations/015_create_system_administration.sql')) as `$statement) { if (trim(`$statement) !== '') { `$pdo->exec(`$statement); } }"
& $php -r $code
```

Le schéma complet contient déjà les tables d’administration et les références initiales.
Ne réexécutez pas `015` sur une base déjà migrée.

Testez les autorisations, les modifications et les journaux d’audit avec :

```powershell
& $php tests/admin.php
```

L’initialiseur exige une base neuve et un fichier local encore au statut modèle. Si la base existe déjà ou contient des données, ne pas lancer d’import ni supprimer quoi que ce soit : inspecter d’abord.

## 8. PDO, erreurs et diagnostic technique

- `app\Database\Connection` ouvre une connexion PDO MySQL avec `utf8mb4`, exceptions, résultats associatifs et requêtes préparées non émulées.
- Le secret est lu uniquement depuis `config\local.php`, situé au-dessus du document root.
- Le bootstrap désactive `display_errors`, active la journalisation locale et installe une réponse d’erreur générique ; les détails vont à `storage\logs\app.log`.
- `public\health.php` n’accepte que les requêtes de boucle locale et renvoie les versions/états utiles, jamais le mot de passe ni le message PDO brut.
- L’alias Apache et le serveur PHP temporaire sont tous deux limités à localhost.

Le diagnostic HTTP a été testé après l’import avec le serveur de développement PHP, limité à la boucle locale :

```text
http://127.0.0.1:8080/health.php
```

**État vérifié :** PHP 8.2.29, `pdo_mysql` et connexion PDO à MySQL 8.4.7 sont actifs ; `health.php` confirme les tables requises après migration. L’alias WAMP n’étant pas encore installé, l’URL `http://localhost/mon-elevage/health.php` ne sera disponible qu’après l’installation de `wamp\mon-elevage.conf` et le redémarrage d’Apache.

```powershell
& 'C:\wamp64\bin\php\php8.2.29\php.exe' -S 127.0.0.1:8080 `
    -t 'C:\Users\KONE\Desktop\Gestion_Plet\public'
```

Lancer cette commande depuis un terminal, ouvrir l’URL locale dans un navigateur, puis arrêter le serveur avec `Ctrl+C`. Il sert au test local uniquement.

## 9. Validation de syntaxe PHP

Depuis `C:\Users\KONE\Desktop\Gestion_Plet` :

```powershell
$php = 'C:\wamp64\bin\php\php8.2.29\php.exe'
Get-ChildItem -Recurse -Filter '*.php' |
    ForEach-Object { & $php -l $_.FullName }
```

**Attendu :** `No syntax errors detected` pour chaque fichier PHP.

## 10. Critère de passage — aucun développement d’authentification avant succès

L’étape initiale est terminée uniquement lorsque :

1. MySQL WAMP fonctionne sur le port 3306 ;
2. le compte applicatif dédié se connecte à `mon_elevage_dev` via PDO ;
3. l’import du schéma et des migrations requises a créé les 49 tables attendues ;
4. la page locale `health.php` affiche PHP, PDO MySQL, connexion et schéma en OK ;
5. le lint PHP passe.

L’authentification, les comptes réels, sessions et formulaires métier ne sont pas démarrés avant ces vérifications et une validation explicite du passage à l’étape suivante.
