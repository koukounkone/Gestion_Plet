# Architecture technique — Mon Élevage

**Version :** architecture initiale, complétée par les modules implémentés  
**Base fonctionnelle :** [Cahier des charges](./Cahier-des-charges-Mon-Elevage.md)  
**Schéma initial :** [`database/schema.sql`](./database/schema.sql)

Ce document décrit une architecture simple, adaptée à un développeur débutant, mais qui pose dès le départ les bases nécessaires à l’isolation des exploitations, à la traçabilité et à l’évolution du projet. Il ne constitue pas l’implémentation des fonctionnalités.

## 1. Hypothèses de cette proposition

- Application PHP rendue côté serveur avec HTML/CSS et JavaScript progressif, sans SPA.
- PHP 8.2 ou version maintenue ultérieure ; MySQL 8.0.16+ ou MariaDB 10.6+ ; InnoDB ; `utf8mb4`.
- PDO avec requêtes préparées ; Composer pour l’autoload PSR-4, sans framework obligatoire.
- Plusieurs exploitations possibles à terme. Chaque donnée métier est rattachée à une exploitation (`farm_id`), et les accès passent par une adhésion active (`farm_members`).
- Une devise par exploitation pour l’usage, mais la devise est également enregistrée sur chaque opération financière afin de préserver son historique.
- Comptabilité de gestion simplifiée. Les rapports sont calculés à partir des journaux ; ils ne sont pas stockés comme des vérités indépendantes.
- Utilisation en ligne au lancement. Aucun paiement en ligne, SMS, service météo ou API payante requis.

## 2. Architecture des dossiers

```text
mon-elevage/
├── app/
│   ├── Bootstrap.php
│   ├── Config/
│   │   └── config.example.php       # Exemple sans secret
│   ├── Core/
│   │   ├── Database.php             # Création PDO
│   │   ├── Router.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   ├── Session.php
│   │   ├── Csrf.php
│   │   ├── Auth.php
│   │   ├── Authorization.php
│   │   ├── Validator.php
│   │   └── View.php
│   ├── Middleware/
│   │   ├── RequireAuthentication.php
│   │   ├── RequireFarmMembership.php
│   │   └── VerifyCsrf.php
│   ├── Controllers/
│   │   ├── Public/                  # Accueil, conditions
│   │   ├── Auth/                    # Inscription, connexion, réinitialisation
│   │   └── App/                     # Élevages, mouvements, stocks, ventes...
│   ├── Services/
│   │   ├── AuthService.php
│   │   ├── FlockService.php
│   │   ├── AnimalMovementService.php
│   │   ├── InventoryService.php
│   │   ├── ExpenseService.php
│   │   ├── SalesService.php
│   │   └── ReportService.php
│   ├── Repositories/                # SQL et accès aux données, toujours tenant-scopés
│   ├── Policies/                    # Règles d'autorisation par ressource/action
│   ├── Validators/                  # Validation métier par formulaire/opération
│   └── Views/
│       ├── layouts/
│       ├── components/
│       ├── public/
│       ├── auth/
│       └── app/
├── routes/
│   ├── web.php
│   └── auth.php
├── public/                          # Seul répertoire exposé par le serveur web
│   ├── index.php                    # Front controller
│   └── assets/
│       ├── css/
│       ├── js/
│       └── images/
├── database/
│   ├── schema.sql                   # Schéma initial importable
│   ├── migrations/                  # Évolutions versionnées après le schéma initial
│   └── seeds/                       # Données de démonstration non sensibles
├── storage/
│   ├── logs/
│   └── exports/                     # Hors du répertoire public, accès contrôlé
├── tests/
│   ├── Unit/
│   ├── Feature/
│   └── Integration/
├── .env.example                     # Noms des variables, valeurs fictives
├── .gitignore
├── composer.json
└── README.md
```

Les clés, mots de passe DB et secrets CSRF sont fournis par l’environnement, jamais commités. En production, le serveur web doit pointer sur `public/`. `app/`, `storage/`, `.env` et les fichiers SQL ne doivent pas être publiquement accessibles. Les justificatifs téléversés sont hors périmètre initial ; s’ils sont ajoutés, ils seront stockés hors webroot.

## 3. Pages et routes

### Publiques

| Méthode / route proposée | Écran | Accès |
|---|---|---|
| `GET /` | Présentation ou redirection vers connexion | Public |
| `GET /conditions`, `GET /confidentialite` | Documents légaux | Public |
| `GET /legal.php?document=terms`, `GET /legal.php?document=privacy` | Conditions et politique de confidentialité provisoires | Public |
| `GET, POST /auth.php?view=register` | Inscription et création de l’exploitation propriétaire | Public, limitation de débit, vérification e-mail requise |
| `GET, POST /auth.php?view=login` | Connexion | Public, message générique, limitation de débit |
| `POST /logout.php` | Déconnexion | CSRF requis |
| `GET, POST /auth.php?view=forgot` | Demande de lien de réinitialisation | Réponse non révélatrice et limitation de débit |
| `GET, POST /auth.php?view=reset` | Définition d’un nouveau mot de passe | Jeton à usage unique valide |
| `GET, POST /auth.php?view=verify` | Vérification/renvoi de vérification d’adresse | Jeton à usage unique ; confirmation finale par POST |
| `GET, POST /profile.php` | Profil, adhésions actives et changement de mot de passe | Session active, CSRF requis pour les mutations |

L’inscription exige l’acceptation séparée des conditions d’utilisation et de la politique
de confidentialité, ainsi qu’une confirmation d’autorisation d’utiliser le service pour
l’exploitation. Les versions acceptées, la confirmation et la date UTC sont journalisées
dans `user_consents`. Les migrations `002_create_user_consents.sql` et
`003_record_farm_authorization.sql` mettent à niveau une base historique. Les documents
actuels sont provisoires ; leurs informations sur le responsable, la juridiction et les
durées de conservation doivent être complétées avant une ouverture publique.

### Privées

Toutes les routes privées exigent une session authentifiée **et** une adhésion active à l’exploitation ciblée. Le contexte d’exploitation est choisi parmi les adhésions du compte, puis revalidé côté serveur.

`GET /` affiche le tableau de bord de l’exploitation choisie avec `farm_id`, uniquement
si le compte dispose d’une adhésion active. Sans identifiant, la première exploitation
autorisée est sélectionnée ; un identifiant inconnu ou non autorisé renvoie 404. Les
rôles `owner` et `manager` peuvent modifier les durées de cycle (POST protégé par CSRF).
Les données financières ne sont chargées que pour les rôles disposant de `expense.view`.

Les effectifs sont calculés depuis l’effectif initial et les mouvements d’animaux ; les
mortalités corrigées sont exclues du total net. Les dépenses sont agrégées par devise,
en excluant les écritures annulées et sans addition entre devises. Le budget est saisi
par élevage, sa devise est figée à la création et le reste est calculé uniquement à partir
des dépenses comptabilisées pour cet élevage dans la même devise. Le coût initial et le
fournisseur sont conservés comme informations d’achat ; le coût initial n’est pas
automatiquement transformé en dépense. Le schéma ne comporte toujours pas de registre
de maladies structuré : cet indicateur est affiché comme indisponible, pas comme zéro.
Dates de vente et progression sont des estimations basées sur des durées configurables
dans `farm_cycle_settings` (1 à 2 000 jours par espèce et production). La progression
linéaire ne mesure pas la croissance et ne constitue pas un conseil zootechnique. Sur une
base historique, appliquer les migrations `002`, `003`, `004` et `005` dans l’ordre. La
migration `005` classe les anciens élevages `eggs` en pondeuses, `breeding` en
reproducteurs et les autres en poulets de chair, faute de catégorie plus précise dans
l’ancien schéma. La clôture archive la fiche sans supprimer son historique ; son résumé
financier utilise uniquement les opérations comptabilisées dans la devise de l’élevage.

| Route indicative | Écrans / actions |
|---|---|
| `GET /` | Tableau de bord de l’exploitation active, protégé par session, adhésion et permissions |
| `GET /flocks.php?farm_id=…` | Liste des élevages actifs, en pause et archivés du tenant autorisé |
| `GET, POST /flocks.php?farm_id=…&view=new` | Création validée côté serveur ; coût initial, budget, devise, fournisseur et localisation sont enregistrés atomiquement |
| `GET, POST /flocks.php?farm_id=…&view=show&id=…` | Détail, suspension/reprise, clôture et archivage tracés avec CSRF |
| `GET, POST /flocks.php?farm_id=…&view=edit&id=…` | Modification ; champs initiaux verrouillés dès l’existence d’opérations |
| `GET, POST /app/exploitation` | Configuration de l’exploitation |
| `GET /app/elevages`, `GET, POST /app/elevages/nouveau` | Liste et création |
| `GET, POST /app/elevages/{id}` | Fiche, édition et résumé |
| `POST /app/elevages/{id}/cloture`, `POST /app/elevages/{id}/reouverture` | Clôture/réouverture, gestionnaire seulement |
| `POST /app/elevages/{id}/mouvements-animaux` | Entrée, perte, vente liée, transfert ou ajustement |
| `GET, POST /app/elevages/{id}/suivi-quotidien` | Observations quotidiennes |
| `GET, POST /app/produits` | Catalogue |
| `POST /app/produits/{id}/mouvements-stock` | Réception, consommation, perte, ajustement |
| `GET, POST /app/depenses` | Consultation et saisie des dépenses |
| `GET, POST /app/clients` | Répertoire privé de clients |
| `GET, POST /app/ventes` | Liste et saisie de vente |
| `POST /app/ventes/{id}/paiements` | Encaissement/remboursement manuel, si activé |
| `POST /app/ventes/{id}/annulation` | Annulation tracée |
| `GET /app/rapports`, `GET /app/rapports/export.csv` | Rapports et export autorisé |
| `GET, POST /app/membres` | Membres/rôles, propriétaire ou gestionnaire |
| `GET, POST /app/profil` | Profil et sécurité du compte |

Les routes de modification utilisent `POST` (ou `PATCH` si la couche HTTP le gère), jamais un lien `GET`. Les identifiants dans l’URL ne sont pas des preuves d’autorisation. L’export CSV suit exactement les permissions et filtres de l’écran source.

## 4. Composants réutilisables

- **Layout public / layout privé :** navigation, pied de page, contenu et métadonnées.
- **Navigation d’exploitation :** affiche uniquement les exploitations accessibles et le rôle courant.
- **Composants de formulaire :** champ libellé, aide, valeur conservée après erreur, erreur accessible associée.
- **Composants d’état :** messages succès/erreur, confirmation de clôture/annulation, état vide, pagination et filtres.
- **Composants métier :** badge de statut d’élevage, résumé de stock, résumé financier, ligne de mouvement, sélecteur d’élevage.
- **Échappement central des vues :** sortie HTML encodée selon le contexte ; aucun contenu utilisateur rendu comme HTML brut.
- **JavaScript progressif :** confirmations, aides à la saisie et calcul indicatif de formulaire seulement. Le serveur recalcule et valide toujours.

Éviter de faire porter les règles métier à un composant visuel ; le même service métier doit être utilisé par une saisie HTML, un export ou une future API.

## 5. Organisation des traitements PHP

Flux d’une requête :

1. `public/index.php` charge l’autoload Composer et `Bootstrap.php`.
2. Le routeur associe méthode/URL à un contrôleur.
3. Les middlewares contrôlent session, jeton CSRF pour les mutations, adhésion active et permission.
4. Le contrôleur valide la forme de la requête, appelle un service métier et choisit une vue ou une redirection.
5. Le service démarre une transaction SQL pour les opérations composées, appelle les repositories et consigne l’audit.
6. Les repositories exécutent uniquement du PDO préparé, avec `farm_id` explicite sur toute requête métier.
7. La réponse HTML échappe les données ; les erreurs inattendues sont journalisées côté serveur et présentées sans détail technique.

Responsabilités :

- **Controller :** HTTP, conversion des entrées, choix de réponse ; pas de SQL métier complexe.
- **Service :** règles et transitions atomiques (p. ex. vente + ligne + sortie de stock/effectif + audit).
- **Repository :** requêtes SQL paramétrées, tenant-scopées.
- **Policy :** décision par rôle/action et propriétaire de la ressource.
- **Validator :** format et règles locales ; les contraintes SQL restent la dernière barrière.

Ne pas utiliser `farm_id` fourni par le formulaire comme autorité. Le middleware résout le tenant à partir de l’utilisateur authentifié et de `farm_members`; ce tenant autorisé est passé explicitement aux services et repositories.

## 6. Règles de validation

Valider côté serveur avant écriture, puis laisser les contraintes SQL protéger les invariants :

- **Compte :** e-mail normalisé et unique ; mot de passe conforme à une politique documentée ; taille maximale de chaque champ.
- **Dates :** format strict, date réelle, fuseau de l’exploitation appliqué ; dates futures interdites lorsque la règle métier l’exige ; opérations antérieures à la création contrôlées.
- **Élevage :** nom/code/type/date valides ; code unique dans l’exploitation ; effectif initial entier positif ; montant initial et budget décimaux en devise de l’exploitation ; date future interdite ; transition de statut permise ; les données initiales deviennent immuables après mouvement, suivi ou vente ; aucun mouvement ordinaire si clôturé.
- **Effectifs :** quantité entière non nulle ; delta cohérent avec le type ; solde suffisant après verrouillage transactionnel du solde concerné ; motif obligatoire pour pertes/ajustements.
- **Suivi quotidien :** une observation par date et élevage pour le MVP ; mesures numériques dans des plages raisonnables et unités explicites ; observation ne modifie pas l’effectif comptable. La consommation d’aliment est enregistrée dans le journal de stock, pas en double dans l’observation.
- **Produit/stock :** unité définie au produit et constante ; quantité décimale strictement positive ; mouvement d’entrée/sortie donne le bon signe ; solde ne devient pas négatif ; seuil de stock positif ou nul.
- **Dépense :** montant décimal positif, devise connue et identique à celle de l’exploitation au moment de l’opération ; catégorie/date obligatoires ; élevage optionnel mais du même tenant.
- **Vente :** au moins une ligne ; quantité positive ; prix unitaire positif ou nul si vente gratuite explicitement permise ; total calculé côté serveur ; ligne soit animale, soit produit ; disponibilité recontrôlée dans une transaction ; client facultatif du même tenant.
- **Paiement :** montant positif ; somme des paiements/restitutions contrôlée selon la règle retenue ; pas de dépassement silencieux du solde.
- **Texte :** taille maximale, normalisation prudente, encodage à la sortie ; pas de validation par liste noire HTML.
- **Actions :** jeton CSRF et confirmation pour clôture, réouverture et annulation ; actions répétées protégées contre le double envoi (clé d’idempotence ou contrôle de doublon côté service).

Une validation JS peut améliorer l’expérience, mais ne remplace jamais la validation PHP ni les contraintes DB.

## 7. Authentification et autorisation

### Authentification

- `password_hash()` avec l’algorithme PHP par défaut adapté, et `password_verify()` ; réhash au besoin.
- Inscription transactionnelle : compte `pending`, exploitation et adhésion propriétaire sont créés ensemble ; seule une adresse vérifiée peut ouvrir une session.
- Vérification d’adresse et réinitialisation : jeton aléatoire présenté une seule fois, empreinte SHA-256 stockée, durée limitée, expiration et consommation atomique.
- Mot de passe : minimum 12 octets, maximum 72 octets pour empêcher la troncature avec l’algorithme `PASSWORD_DEFAULT` actuellement configuré ; champs de confirmation comparés côté serveur.
- Session PHP avec cookie `HttpOnly`, `Secure` en HTTPS, `SameSite=Lax`, `session.use_strict_mode=1`, identifiant renouvelé à la connexion, expiration après 30 minutes d’inactivité et limite absolue de 8 heures.
- CSRF vérifié en temps constant pour tous les POST, dont l’inscription, les formulaires d’authentification, le profil, le changement de mot de passe et la déconnexion.
- Connexion à réponse générique pour un compte inexistant, en attente, désactivé ou un mot de passe erroné ; cinq échecs par combinaison pseudonymisée compte/IP bloquent cette combinaison pendant 15 minutes.
- Demandes de réinitialisation et de vérification à réponse non révélatrice et limitées ; les empreintes de limites ne conservent pas les adresses e-mail/IP en clair.
- Le renvoi des courriels utilise le transport natif PHP configuré ; un échec est journalisé sans inscrire le jeton dans les journaux et le jeton non délivré est supprimé.
- Déconnexion par `POST` protégé CSRF ; destruction de la session côté serveur.
- L’authentification multifacteur reste hors du premier périmètre et nécessitera une validation métier distincte.

### Rôles et autorisations

Rôles DB : `owner`, `manager`, `operator`, `accountant`. Le rôle est attaché à une adhésion exploitation/utilisateur, pas au compte global. Le compte d’un visiteur n’a aucune visibilité métier.

Matrice initiale appliquée par `FarmAccess` :

| Action | owner | manager | operator | accountant |
|---|---:|---:|---:|---:|
| `farm.view`, `flock.view` | Oui | Oui | Oui | Oui |
| `followup.write` | Oui | Oui | Oui | Non |
| `stock.view` | Oui | Oui | Oui | Oui |
| `flock.manage`, `stock.manage`, `expense.manage`, `sale.manage` | Oui | Oui | Non | Non |
| `report.view` | Oui | Oui | Non | Oui |
| `member.manage` | Oui | Oui | Non | Non |
| Gérer propriétaire/transférer exploitation | Oui | Non | Non | Non |

Le contexte de toute exploitation est vérifié contre une adhésion `active` et un compte `active`. La matrice est volontairement codée simplement ; le contrôle de chaque ressource métier devra appeler la politique côté serveur. Aucun rôle administrateur global ni écran métier n’est exposé dans le module d’authentification. Toute évolution fine des droits doit être validée avant ajout d’une table de permissions.

## 8. Modèle relationnel

Le script [`database/schema.sql`](./database/schema.sql) est la définition exécutable. Toutes les tables métier portent `farm_id`; les tables enfants possèdent une clé étrangère composite vers leur parent comprenant le même `farm_id`. Un lien vers un article, un client, un élevage, un membre ou une vente d’une autre exploitation est ainsi rejeté par la base, en plus du contrôle applicatif.

### Tables et rôle

| Table | Usage, champs principaux | Clés / index importants |
|---|---|---|
| `users` | Compte, e-mail normalisé, `password_hash`, statut et dates | PK `id`; UNIQUE `email` |
| `farms` | Exploitation, propriétaire, devise, pays, fuseau horaire | PK `id`; FK propriétaire; index propriétaire |
| `farm_members` | Adhésion, rôle et statut utilisateur/exploitation | PK `(farm_id,user_id)`; index `(user_id,status)` |
| `auth_tokens` | Empreinte de jeton de réinitialisation/confirmation, expiration | PK; UNIQUE empreinte; index `(user_id,purpose,expires_at)` |
| `login_attempts` | Compteur de limitation de connexion sans IP brute | PK empreinte; index expiration |
| `flocks` | Élevage, code, type avicole, devise, fournisseur, coût initial, budget, localisation, statut, effectif de départ et dates | UNIQUE `(farm_id,id)`, `(farm_id,code)`; index statut/date; CHECK effectif/coûts/budget |
| `flock_lifecycle_events` | Création, changement d’état, clôture/réouverture et snapshots de clôture | FK composite élevage; index élevage/date |
| `flock_transfers` | Transfert animal source/destination, quantité et date | FK composites des deux élevages; index par source/date et destination/date |
| `animal_movements` | Journal signé des entrées, pertes, ventes, transferts et ajustements | FK composite élevage; index élevage/date; transfert et vente uniques par source |
| `daily_followups` | Mesures et notes quotidiennes sans modifier le ledger | UNIQUE `(farm_id,flock_id,followup_date)` |
| `products` | Catalogue article, unité et seuil d’alerte | UNIQUE `(farm_id,id)`; index catégorie/actif |
| `expenses` | Dépenses, catégorie, élevage optionnel, devise, annulation traçable | FK composites élevage/auteur; index date/élevage/statut |
| `stock_movements` | Journal signé des réceptions, consommations, ventes, pertes et ajustements | FK composites article/élevage/dépense/ligne de vente; index article/date |
| `clients` | Répertoire privé de clients | UNIQUE `(farm_id,id)`; index nom/archivage |
| `sales` | Vente, client, date, devise et annulation traçable | FK composite client/auteur; index date/client/statut |
| `sale_lines` | Lignes produit ou animaux, quantité, prix et total calculé | FK composites vente/produit/élevage; index vente |
| `sale_payments` | Encaissements/remboursements saisis manuellement | FK composite vente/auteur; index vente/date |
| `audit_logs` | Trace d’actions sensibles, acteur, ressource et métadonnées JSON limitées | index `(farm_id,created_at)` et acteur/date |

Les rapports ne sont pas une table : le module de rapports exécute des requêtes tenant-scopées sur les journaux et peut exporter le résultat autorisé.

### Relations cardinales

- `users` ↔ `farms` : plusieurs-à-plusieurs par `farm_members`; `farms.owner_user_id` désigne le propriétaire principal.
- `farms` → `flocks`, produits, dépenses, clients, ventes, mouvements et audit : un-à-plusieurs.
- `flocks` → mouvements d’animaux, suivis quotidiens, mouvements de stock et ventes : un-à-plusieurs facultatifs selon le type de mouvement.
- `flock_transfers` → deux élevages et deux lignes de mouvement (sortie + entrée) : le service crée les trois éléments atomiquement.
- `sales` → `sale_lines` → soit produit, soit élevage animal ; `sale_payments` → vente.
- Vente d’animaux → exactement une sortie d’effectif liée à la ligne ; vente de produit → exactement une sortie de stock liée à la ligne. Le service réalise l’ensemble dans une transaction.
- `expenses` → éventuels mouvements de réception en stock par `stock_movements.expense_id`, évitant de saisir deux fois la charge.

## 9. Isolation vérifiable des données

MySQL/MariaDB ne fournit pas une politique générale de sécurité par ligne équivalente au RLS de certains autres moteurs. L’isolation repose donc sur le serveur PHP, renforcée par les clés étrangères composites du schéma :

1. Après authentification, résoudre les `farm_id` accessibles en consultant `farm_members` où `user_id` correspond à l’utilisateur de session et `status='active'`.
2. Ne jamais accepter l’identité du compte ni le rôle depuis le navigateur. Le rôle provient de l’adhésion stockée.
3. Choisir le contexte d’exploitation seulement parmi ces résultats ; vérifier à nouveau l’adhésion et le rôle pour chaque requête sensible.
4. Chaque méthode de repository reçoit le `farm_id` autorisé, filtre toutes les lectures et écritures par celui-ci et ne charge jamais une ressource sur son seul `id`.
5. Les insertions enfants renseignent `farm_id` depuis le contexte serveur ; les FKs composites refusent les références croisées.
6. Les transactions verrouillent la ligne du produit ou de l’élevage (`SELECT ... FOR UPDATE`), puis recalculent/contrôlent le solde du ledger avant insertion afin que deux requêtes simultanées ne vendent pas le même stock ou les mêmes animaux.
7. Les exports, erreurs, listes de recherche et compteurs suivent les mêmes filtres tenant.
8. L’utilisateur DB de l’application est non administrateur et n’est pas accessible au navigateur.

Exemple de forme obligatoire pour charger un élevage : filtre simultané `farm_id = contexte_autorisé` et `id = identifiant_route`, puis contrôle du rôle. Les jointures entre tables métier incluent leur `farm_id`, même si l’intégrité composite existe déjà.

Tests d’intégration avant mise en service :

- compte A connecté, accès à l’ID de ferme/élevage/vente/stock de B : réponse 404 ou 403 sans donnée métier divulguée ;
- compte A modifie `farm_id` dans POST : refus, aucune écriture ;
- compte A lie une ligne de vente à un produit ou client de B : contrainte DB ou service refuse ;
- opérateur essaie de clôturer/annuler : refusé côté serveur ;
- export A ne contient aucune ligne B ;
- deux ventes concurrentes de la dernière quantité disponible : une seule réussit.

Le schéma empêche les références inter-exploitations accidentelles, mais ne remplace pas ces contrôles PHP et tests : un compte DB ayant accès global pourrait toujours lire les tables.

## 10. Suppression, archivage et conservation de l’historique

- Les tables métier utilisent des FKs restrictives : toute suppression d’exploitation doit supprimer ses données dans une transaction et respecter leurs dépendances, jamais s’appuyer sur une cascade implicite.
- Les produits, clients et comptes sont désactivés/archivés par statut ou `archived_at`, et exclus des nouveaux formulaires ; les anciennes opérations restent visibles.
- Les élevages clôturés passent en lecture seule. La réouverture est réservée au propriétaire/gestionnaire, justifiée et enregistrée dans `flock_lifecycle_events` et `audit_logs`.
- Les mouvements d’effectifs et de stock sont des journaux append-only dans l’interface : une erreur donne lieu à une ligne inverse liée à la ligne d’origine, jamais à une suppression ni à une réécriture silencieuse.
- Dépenses, ventes et paiements annulés conservent leur enregistrement, auteur, date et motif. Les rapports excluent les opérations annulées et tiennent compte des lignes de contrepassation.
- Un transfert crée une sortie et une entrée atomiques, liées à un transfert.
- Depuis l’administration, un compte standard peut être supprimé définitivement avec ses exploitations détenues. L’administrateur peut télécharger au préalable une archive PDF de ses données structurées ; la confirmation avertit que la suppression des exploitations et de leurs données est irréversible. Un compte administrateur et un compte membre d’une exploitation appartenant à un autre compte ne peuvent pas être supprimés par cette action.
- La suppression/anonymisation d’un compte ou d’une exploitation doit suivre la législation applicable ; vérifier les obligations de conservation et télécharger l’archive avant confirmation.
- Définir avant production une durée de conservation et un processus de purge pour les jetons expirés, tentatives de connexion, journaux techniques et données personnelles. L’audit métier n’est pas purgé automatiquement avec les sessions.

## 11. Calculs métier principaux

### Effectif d’un élevage

`effectif courant = flocks.initial_count + SUM(animal_movements.quantity_delta)`

`quantity_delta` est positif pour les entrées, transferts entrants et ajustements à la hausse ; négatif pour mortalités, pertes, ventes, transferts sortants et ajustements à la baisse. Les lignes annulées doivent être compensées par une ligne inverse. Un solde inférieur à zéro est toujours refusé.

`taux de mortalité = pertes/mortalités retenues dans la définition ÷ effectif de référence × 100`. La définition exacte (mortalité seule ou mortalité + pertes, entrées cumulées ou effectif initial) doit être affichée et validée ; elle n’est pas encodée comme vérité unique dans le schéma.

### Stock d’un produit

`stock courant = SUM(stock_movements.quantity_delta)` pour produit et exploitation. Entrées/réceptions/retours/ajustements positifs ; consommation/vente/perte/ajustement négatifs. Quantité négative interdite. Les opérations doivent verrouiller la ligne produit et être insérées dans une transaction. Le suivi quotidien peut présenter la consommation agrégée depuis ce ledger, mais n’enregistre pas une seconde quantité source.

### Dépenses, ventes et résultat simplifié

- Vente de ligne : `ROUND(quantity × unit_price, 2)`, calculé par la colonne générée SQL et contrôlé aussi côté service.
- Chiffre d’affaires : somme des lignes de ventes confirmées, moins les annulations/avoirs ou écritures inverses conformément à la méthode validée ; la devise est conservée et les devises ne sont jamais additionnées entre elles.
- Encaissements : somme des paiements reçus moins remboursements enregistrés, séparée du chiffre d’affaires.
- Dépenses : somme des dépenses publiées non annulées dans la devise et période sélectionnées.
- Résultat simplifié : chiffre d’affaires − charges reconnues par la méthode retenue. Les achats de stock et consommations ne sont pas comptés deux fois ; les frais communs non répartis sont présentés à part.
- Le bilan de clôture conserve une photographie du nombre final et des principaux totaux dans l’événement de clôture. Les rapports historiques recalculés et le snapshot de clôture sont distingués.

Ces indicateurs ne sont pas une comptabilité certifiée ni un résultat fiscal.

## 12. Points à valider avant le schéma définitif

1. Confirmer le pays de déploiement, la devise et les règles de conservation légales.
2. Confirmer les types de produits vendus : animaux uniquement, œufs et/ou autres produits.
3. Confirmer si les paiements partiels, crédits clients et remboursements entrent dans le premier lot.
4. Valider la définition du taux de mortalité et les mesures du suivi quotidien.
5. Valider les rôles initiaux et si les opérateurs ont des permissions configurables.
6. Valider l’inscription libre ou uniquement sur invitation.
7. Valider si le stock d’animaux doit être représenté uniquement par le ledger d’effectifs (recommandé), sans créer un deuxième stock concurrent.
8. Valider le calcul de résultat simplifié et le traitement des achats consommables.

## 13. Import du schéma et validation ultérieure

Après validation de l’architecture, importer `database/schema.sql` dans une base vide MySQL 8.0.16+ ou MariaDB 10.6+ sélectionnée. Le script ne crée pas de base ni d’utilisateur et ne contient aucun jeu de données. Vérifier ensuite les tables, contraintes, index et vues avec `SHOW TABLES` / `SHOW CREATE TABLE`.

Avant une mise en production, l’implémentation devra fournir les tests d’intégration d’isolation décrits plus haut, ainsi que des tests des soldes effectifs, des annulations, des ventes et des courses concurrentes. L’import du SQL seul ne démontre pas encore l’isolation HTTP, qui dépend des contrôles PHP.
