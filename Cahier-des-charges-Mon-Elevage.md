# Cahier des charges fonctionnel et technique

## Mon Élevage — version initiale

**Statut :** document de cadrage avant développement  
**Périmètre analysé :** le dossier projet ne contient pas de spécification existante ; le fichier `ploulet.html` est vide. Les propositions ci-dessous sont donc fondées sur le besoin exprimé et doivent être confirmées par le porteur du projet.

---

## 1. Présentation et objectifs

### 1.1 Contexte

« Mon Élevage » est un site web dynamique de gestion d’élevages avicoles. Il doit aider un éleveur à suivre ses bandes (groupes d’animaux), leur évolution, les achats et consommations d’intrants, les dépenses, les ventes et les résultats économiques, depuis la préparation d’un élevage jusqu’à sa clôture.

Le produit est envisagé comme une application web accessible depuis un navigateur, d’abord adaptée à un utilisateur ou à une petite exploitation. Les notions de monnaie, de pays, de réglementation et de fonctionnement hors connexion ne sont pas encore précisées.

### 1.2 Objectifs

1. Centraliser les informations d’une exploitation et de ses élevages.
2. Conserver un historique fiable des entrées, mortalités, transferts et sorties d’animaux.
3. Suivre les achats, les stocks d’intrants et les consommations.
4. Enregistrer les charges et les ventes avec des montants et des dates vérifiables.
5. Fournir des indicateurs compréhensibles : effectif restant, mortalité, dépenses, chiffre d’affaires et résultat.
6. Permettre de retrouver les opérations et d’imprimer ou exporter des relevés utiles.
7. Limiter les erreurs de saisie et protéger les données de l’exploitation.

### 1.3 Indicateurs de réussite

- Un utilisateur autorisé peut créer un élevage, saisir les mouvements et comprendre l’effectif restant.
- Les quantités en stock ne deviennent pas négatives à la suite d’une opération validée.
- Chaque total financier peut être expliqué à partir de ses opérations sources.
- La clôture d’un élevage conserve son historique et empêche les modifications accidentelles.
- Les écrans essentiels sont utilisables sur ordinateur et téléphone, sans service payant obligatoire.

---

## 2. Périmètre fonctionnel

### 2.1 Version initiale recommandée (MVP)

Le MVP vise une application mono-exploitation ou une exploitation par compte, utilisée principalement en ligne :

- création de compte, connexion, profil et récupération de mot de passe ;
- informations de l’exploitation ;
- tableau de bord ;
- gestion des élevages et de leur cycle de vie ;
- journal des effectifs et mortalités ;
- catalogue simple des produits et suivi des stocks ;
- enregistrement des dépenses ;
- enregistrement des ventes ;
- rapports élémentaires et export CSV ;
- clôture et consultation des élevages archivés.

### 2.2 Hors périmètre initial

À reporter à une phase ultérieure, sauf validation contraire :

- comptabilité générale certifiée, déclarations fiscales ou facturation conforme à une législation nationale ;
- paiements en ligne, portefeuille électronique et rapprochement bancaire ;
- marketplace publique, comptes acheteurs et commandes en libre-service ;
- application mobile native, synchronisation hors ligne et usage multi-exploitations avancé ;
- capteurs, pesée automatique, IoT et intégrations avec matériels d’élevage ;
- prévisions automatiques de production ou recommandations vétérinaires ;
- envoi de SMS payants et campagnes marketing ;
- gestion de paie, transport, abattoir, traçabilité réglementaire ou certification.

---

## 3. Profils utilisateurs et permissions

Les permissions doivent être vérifiées côté serveur à chaque requête. Masquer un bouton dans l’interface ne constitue pas un contrôle d’accès.

| Profil | Permissions recommandées |
|---|---|
| **Administrateur technique** | Gérer les paramètres globaux et les comptes si cette fonction est activée. Ne consulte pas automatiquement les données privées métier sans besoin justifié. Accès réservé au responsable du site. |
| **Propriétaire / gestionnaire** | Accès complet à son exploitation : paramètres, utilisateurs invités, élevages, mouvements, dépenses, stocks, ventes, rapports, clôture et réouverture exceptionnelle. |
| **Employé / opérateur** | Consulter les élevages actifs et saisir les opérations autorisées (effectifs, consommation, dépenses ou ventes selon le mandat). Ne peut ni gérer les accès, ni supprimer ou clôturer un élevage, ni modifier les paramètres sensibles. |
| **Comptable / lecteur** | Consultation des dépenses, ventes, rapports et exports. Aucune création ou modification d’opération. Les données d’élevage peuvent être limitées selon le besoin. |
| **Visiteur / acheteur** | Aucun accès au back-office dans le MVP. Une éventuelle vitrine commerciale publique sera distincte des données privées. |

**Recommandation débutant :** commencer par un compte propriétaire et, si nécessaire, un compte employé avec peu de permissions. Ajouter les rôles comptable et acheteur quand un besoin réel est confirmé.

**Décisions à valider :** inscription libre ou comptes créés par le propriétaire ; une exploitation par compte ou plusieurs ; invitation de collaborateurs ; accès d’un administrateur technique aux données métier.

---

## 4. Modules fonctionnels et critères d’acceptation

### 4.1 Inscription, connexion et profil

Fonctions :

- inscription par nom, adresse e-mail et mot de passe ;
- connexion et déconnexion ;
- demande de réinitialisation du mot de passe ;
- modification du profil et du mot de passe ;
- affichage et acceptation de la politique de confidentialité et des conditions applicables.

**Terminé lorsque :** un nouvel utilisateur peut créer un compte avec une adresse valide ; les erreurs sont explicites ; la connexion ouvre uniquement ses données ; un lien de réinitialisation à durée limitée fonctionne ; les mots de passe ne sont jamais enregistrés en clair.

### 4.2 Exploitation et paramètres

Fonctions :

- nom de l’exploitation, coordonnées et informations facultatives ;
- fuseau horaire, langue et devise de référence ;
- coordonnées destinées aux rapports et documents imprimés.

**Terminé lorsque :** le gestionnaire peut créer et modifier ces informations ; elles sont reprises dans les écrans et exports concernés ; devise et fuseau horaire s’appliquent de manière cohérente.

### 4.3 Tableau de bord

Présenter au minimum :

- élevages actifs et leur statut ;
- effectifs actuels et alertes de seuil configurées ;
- dépenses, ventes et résultat par élevage et période ;
- niveau de stock bas ;
- opérations récentes.

**Terminé lorsque :** les indicateurs correspondent aux journaux de données sources, respectent les droits de l’utilisateur et affichent un état vide compréhensible en l’absence de données.

### 4.4 Élevages et bandes

Fonctions :

- créer un élevage avec identifiant, espèce/type, objectif (chair, ponte ou autre), souche facultative, date de démarrage, effectif initial et notes ;
- suivre les statuts **Brouillon**, **Actif**, **En pause** et **Clôturé** ;
- consulter la fiche, modifier les informations autorisées et accéder aux journaux associés ;
- clôturer l’élevage avec date, effectif final, motif et commentaire ;
- réouverture uniquement par un gestionnaire, avec justification et historique.

**Terminé lorsque :** la création exige les champs essentiels ; chaque opération est rattachée à un élevage ; la clôture conserve les données et bloque les nouvelles opérations ordinaires ; l’historique des changements reste consultable.

### 4.5 Effectifs et suivi zootechnique

Fonctions du MVP :

- enregistrer les entrées d’animaux ;
- enregistrer mortalités et autres pertes avec quantité, date et motif ;
- enregistrer les sorties, transferts et corrections d’inventaire ;
- afficher l’effectif calculé et le journal chronologique ;
- ajouter des observations et mesures manuelles, par exemple poids et consommation, si validé.

**Terminé lorsque :** le solde est recalculable depuis les événements ; toute quantité est strictement positive ; une sortie supérieure au disponible est refusée ; toute correction exige un motif et laisse une trace.

### 4.6 Catalogue, achats et stocks

Catégories recommandées : aliments, produits vétérinaires, animaux, litière, matériel et autres intrants.

Fonctions :

- créer des articles avec nom, unité de mesure et catégorie ;
- saisir réception/achat, consommation, perte, retour ou ajustement ;
- associer une consommation à un élevage ;
- afficher stock courant et historique des mouvements ;
- signaler les seuils bas définis par l’utilisateur.

**Terminé lorsque :** le stock affiché correspond à la somme de ses mouvements ; les unités sont cohérentes ; une opération ne peut rendre le stock négatif, sauf autorisation explicite et journalisée ; chaque entrée ou sortie indique son origine.

### 4.7 Dépenses

Fonctions :

- saisir date, catégorie, libellé, montant, devise, élevage concerné et référence justificative facultative ;
- distinguer une dépense directe d’élevage d’une dépense commune à l’exploitation ;
- relier un achat d’intrant à la réception en stock sans compter deux fois sa valeur dans le résultat ;
- rechercher et filtrer par période, catégorie et élevage.

**Terminé lorsque :** les montants négatifs ou nuls sont refusés ; une dépense liée à un élevage clôturé est bloquée par défaut ; le total est cohérent avec le détail filtré ; une correction ou annulation est traçable.

### 4.8 Ventes et commercialisation

Fonctions :

- saisir date, produit vendu, quantité, unité, prix unitaire, acheteur facultatif, mode de paiement et élevage d’origine ;
- prendre en charge les ventes d’animaux et, si confirmé, d’œufs ou autres produits ;
- déduire du stock vendu ou des effectifs l’objet effectivement vendu ;
- enregistrer un paiement partiel et un solde à recevoir seulement si le suivi des créances est inclus.

**Terminé lorsque :** la quantité disponible ne peut être dépassée ; le total de ligne est recalculé par le serveur ; les ventes, paiements et unités sont consultables dans un journal ; les corrections sont historisées.

### 4.9 Rapports et exports

Rapports de base :

- situation d’un élevage et évolution de l’effectif ;
- mortalité et pertes ;
- achats, consommation et état des stocks ;
- dépenses et ventes par période ;
- résultat simplifié par élevage ;
- historique des opérations.

**Terminé lorsque :** un utilisateur peut filtrer par période et élevage ; les totaux reflètent les mêmes règles que le tableau de bord ; les exports CSV sont lisibles et respectent les permissions ; l’impression ne perd pas les données essentielles.

### 4.10 Administration, audit et archivage

Fonctions :

- gestion des utilisateurs et rôles si le multi-utilisateur est retenu ;
- historique des actions sensibles (création, modification, annulation, clôture, accès aux rôles) ;
- archivage logique plutôt que suppression définitive des données métier.

**Terminé lorsque :** les opérations importantes sont attribuées à leur auteur et horodatées ; un utilisateur ne peut agir au-delà de son rôle ; les éléments archivés restent retrouvables par les personnes autorisées.

---

## 5. Écrans nécessaires

### Pages publiques

1. Accueil / présentation du service (facultatif pour un usage privé).
2. Inscription.
3. Connexion.
4. Mot de passe oublié et confirmation de réinitialisation.
5. Conditions d’utilisation et politique de confidentialité.

### Pages de l’application

1. Tableau de bord.
2. Assistant de première configuration de l’exploitation.
3. Liste des élevages (actifs, brouillons, clôturés).
4. Création et modification d’un élevage.
5. Fiche d’élevage avec onglets : résumé, effectifs, observations, dépenses, stocks/consommation, ventes, documents/historique.
6. Formulaire d’entrée, mortalité/perte, sortie/transfert et correction d’effectif.
7. Catalogue des articles.
8. Liste et fiche de stock avec journal des mouvements.
9. Formulaire de réception, consommation, perte et ajustement de stock.
10. Liste, consultation et saisie des dépenses.
11. Liste, consultation et saisie des ventes.
12. Rapports et exports.
13. Profil et sécurité du compte.
14. Paramètres de l’exploitation (devise, unités, seuils, coordonnées).
15. Gestion des utilisateurs et permissions (si multi-utilisateur).
16. Confirmation de clôture et écran de bilan de clôture.
17. États vides, erreurs, accès refusé et page introuvable.

Chaque formulaire doit prévoir validation côté serveur, messages compréhensibles, confirmation des actions irréversibles et adaptation aux écrans mobiles.

---

## 6. Parcours utilisateur

### 6.1 Inscription à la première exploitation

1. L’utilisateur ouvre la page d’inscription.
2. Il saisit ses informations, son mot de passe et accepte les documents applicables.
3. Le système valide les champs, crée le compte et confirme l’adresse e-mail si cette fonction est activée.
4. Il se connecte et renseigne les informations de l’exploitation, la devise et le fuseau horaire.
5. Il accède au tableau de bord avec un guide de démarrage et sans données fictives.

### 6.2 Démarrage d’un élevage

1. Le gestionnaire crée un élevage et enregistre l’effectif initial.
2. Il ajoute les articles utiles et reçoit les premiers stocks.
3. Il associe les achats et charges au bon élevage.
4. Il saisit au fil du temps les mortalités, pertes, consommations, observations et autres mouvements.
5. Le tableau de bord et la fiche montrent les soldes recalculés.

### 6.3 Vente

1. L’utilisateur choisit l’élevage et le produit disponible.
2. Il saisit quantité, prix et date ; le système contrôle disponibilité et calcul du total.
3. À confirmation, le système enregistre la vente et met à jour le stock ou l’effectif correspondant.
4. La vente apparaît dans le journal et dans les rapports.

### 6.4 Clôture d’un élevage

1. Le gestionnaire consulte le bilan : effectif restant, pertes, ventes, dépenses, stocks attribués et résultat.
2. Il résout les incohérences ou confirme un solde final avec motif.
3. Il saisit la date et le motif de clôture puis confirme.
4. Le système fige l’élevage en lecture seule et conserve son historique.
5. Une réouverture, si autorisée, exige un rôle suffisant, une confirmation et une trace d’audit.

---

## 7. Règles de gestion

### 7.1 Principes communs

- Les quantités, dates, unités et montants sont validés côté serveur.
- Les opérations confirmées ne sont pas supprimées silencieusement : elles sont annulées ou corrigées par une opération inverse, avec motif et auteur.
- Les mouvements sont horodatés et reliés à leur source et, lorsque pertinent, à un élevage.
- Les calculs sont effectués côté serveur avec des types décimaux adaptés aux quantités et à la monnaie ; éviter les flottants binaires pour l’argent.
- Chaque opération financière a une devise explicite. Le MVP devrait interdire le mélange de devises dans un total, faute de taux de conversion validé.

### 7.2 Effectifs

Pour un élevage donné :

**Effectif courant = effectif initial + entrées + transferts entrants − mortalités − autres pertes − sorties − transferts sortants.**

- Chaque événement est enregistré une seule fois.
- Les mouvements sortants ne peuvent dépasser l’effectif courant.
- Une correction d’inventaire ne réécrit pas l’effectif initial ; elle crée un ajustement motivé.
- Un transfert entre élevages produit deux événements liés (sortie et entrée), afin de conserver l’équilibre.
- Une vente d’animaux doit diminuer l’effectif par une sortie liée à la vente, sans double déduction.
- Taux de mortalité recommandé : pertes cumulées / animaux entrés cumulés selon une définition à valider ; afficher la formule et la période utilisées.
- À la clôture, l’effectif final doit être explicite ; toute différence avec le calcul doit être justifiée.

### 7.3 Dépenses et achats

- Dépense = montant effectivement engagé/enregistré pour une charge, avec catégorie, date et devise.
- Distinguer la dépense d’achat de la réception physique en stock : ces deux opérations sont liées mais ne doivent pas créer deux charges.
- Une facture d’achat stockée est une charge ou un coût d’achat selon la méthode retenue ; la consommation du stock ne doit pas être comptabilisée une seconde fois si l’achat l’a déjà été.
- Les coûts communs peuvent être conservés comme « non affectés » dans le MVP. Leur répartition entre élevages requiert une règle explicitement choisie (au prorata, saisie manuelle ou aucune allocation).
- Pour un débutant, commencer par un résultat de gestion simplifié et ne pas présenter le système comme une comptabilité officielle.

### 7.4 Stocks

**Stock courant = stock initial + réceptions + retours − consommations − ventes en stock − pertes ± ajustements.**

- Une ligne de mouvement comporte article, quantité, unité, date, motif, auteur et référence liée.
- Les unités doivent être homogènes pour un article ; aucune conversion implicite entre kg, sacs, litres ou unités.
- Interdire par défaut le stock négatif.
- Si une vente porte sur un article stocké, elle génère un mouvement de sortie unique.
- Le stock d’animaux et les articles ordinaires doivent rester distingués, même si une vente d’animaux entraîne aussi un mouvement d’effectif.
- Un inventaire physique crée un ajustement traçable, jamais une réécriture invisible du journal.

### 7.5 Ventes

- **Total ligne = quantité × prix unitaire**, calculé côté serveur et arrondi selon la devise configurée.
- Chiffre d’affaires simplifié = somme des ventes confirmées, après prise en compte des annulations/avoirs.
- Les ventes ne sont pas assimilées automatiquement aux encaissements si paiement différé ou partiel.
- En l’absence de module de paiements, indiquer clairement si le montant saisi représente une vente conclue ou une somme encaissée.
- Un retour/avoir doit être enregistré comme opération inverse, lié à la vente initiale.

### 7.6 Résultat et bénéfice

Pour le MVP, afficher explicitement un **résultat brut de gestion simplifié**, et non un bénéfice comptable ou fiscal :

**Résultat simplifié = chiffre d’affaires des ventes confirmées − charges affectées selon la méthode retenue.**

- Préciser les dépenses incluses et exclues ainsi que la période du calcul.
- Ne pas soustraire deux fois un achat d’aliment et sa consommation.
- Les frais communs non répartis doivent apparaître séparément et ne pas être dissimulés.
- Les créances non encaissées et stocks restant en fin d’élevage nécessitent un choix comptable ; tant qu’il n’est pas validé, les rapports doivent les présenter séparément au lieu d’inventer un résultat net.

### 7.7 Clôture

- La clôture est une transition d’état contrôlée, pas une suppression.
- Les opérations après clôture sont refusées par défaut ; une réouverture est réservée au gestionnaire et auditée.
- Le bilan archivé inclut les soldes, la date, l’auteur et les anomalies ou ajustements documentés.

---

## 8. Relations entre modules

```text
Compte utilisateur
  └── Exploitation
       ├── Utilisateurs et rôles (si multi-utilisateur)
       ├── Élevages
       │    ├── Mouvements d'effectifs
       │    ├── Observations et mesures
       │    ├── Dépenses affectées
       │    ├── Consommations de stock
       │    └── Ventes d'animaux / produits
       ├── Articles
       │    └── Mouvements de stock
       ├── Dépenses communes ou affectées
       ├── Ventes et paiements (si retenus)
       └── Rapports calculés à partir des journaux
```

Relations métier importantes :

- une exploitation possède un ou plusieurs élevages, articles, opérations et utilisateurs autorisés ;
- un élevage possède des mouvements d’effectifs et peut être référencé par une dépense, une consommation ou une vente ;
- un mouvement de stock porte sur un article et peut référencer un achat, une consommation, une perte ou une vente ;
- une vente d’animaux relie une vente à une sortie d’effectif ; une vente d’article relie une vente à une sortie de stock ;
- un rapport est calculé à partir des opérations sources et ne constitue pas une source de vérité indépendante ;
- toutes les données appartenant à une exploitation doivent être isolées des autres exploitations.

---

## 9. Exigences de sécurité et de confidentialité

1. **Mots de passe :** utiliser les fonctions de hachage PHP adaptées (`password_hash` / `password_verify`), jamais un stockage réversible.
2. **SQL :** PDO et requêtes préparées ; aucun assemblage de SQL depuis une entrée utilisateur.
3. **Sessions :** cookies `HttpOnly`, `Secure` en HTTPS, `SameSite` approprié ; renouvellement de l’identifiant après authentification ; expiration et déconnexion effectives.
4. **CSRF :** jeton anti-CSRF pour les actions modifiant des données.
5. **XSS :** échapper les sorties selon leur contexte ; ne pas faire confiance aux notes ni aux champs importés.
6. **Contrôle d’accès :** vérifier le rôle et l’appartenance à l’exploitation côté serveur pour chaque lecture et écriture ; prévenir l’accès direct par modification d’identifiant.
7. **Validation :** valider types, bornes, tailles, formats, montants, dates et quantités côté serveur ; la validation JavaScript ne suffit pas.
8. **HTTPS :** obligatoire en production, avec configuration correcte et secrets non stockés dans le dépôt.
9. **Protection des comptes :** limitation des tentatives, messages de connexion qui ne révèlent pas si un compte existe, réinitialisation par jeton aléatoire à expiration.
10. **Données personnelles :** collecter le minimum, préciser finalités, conservation, droits des utilisateurs et procédure de suppression/anonymisation conformément au droit applicable.
11. **Journaux :** ne jamais inscrire mots de passe, jetons ou secrets dans les logs ; tracer les changements sensibles sans exposer inutilement les données.
12. **Sauvegardes :** sauvegardes régulières de la base, stockage protégé et tests documentés de restauration.
13. **Fichiers :** si justificatifs ajoutés plus tard, limiter types et tailles, renommer côté serveur et empêcher l’exécution de fichiers téléversés.
14. **Erreurs :** détails techniques dans les logs protégés ; messages génériques et utiles à l’utilisateur en production.
15. **Dépendances :** maintenir PHP et bibliothèques à jour ; limiter les dépendances et réviser leurs licences et vulnérabilités.

---

## 10. APIs externes, services payants et alternatives

### APIs qui ne sont pas nécessaires au MVP

Toutes les fonctions fondamentales peuvent être réalisées sans API externe payante : authentification locale, calculs, saisies, gestion des stocks, rapports, exports CSV et impression.

### APIs/services optionnels

| Besoin | API/service externe | Obligatoire ? | Option sans service payant |
|---|---|---:|---|
| Confirmation et récupération par e-mail | Serveur SMTP ou fournisseur d’e-mail transactionnel | Non pour une maquette locale ; recommandé en production | SMTP de l’hébergeur si disponible ; l’envoi réel peut être désactivé en développement, avec avertissement visible. |
| Cartes ou géolocalisation | Fournisseur cartographique et géocodage | Non | Saisir l’adresse en texte ou ouvrir un lien vers un service de cartes sans intégrer de carte. |
| SMS et alertes mobiles | Passerelle SMS | Non | Alertes dans le tableau de bord ; e-mail si configuré. |
| Paiement en ligne | Prestataire de paiement | Non | Enregistrer une vente et son statut de paiement manuellement. Ne jamais stocker de données de carte. |
| Taux de change | API de taux de change | Non | Une seule devise par exploitation ; si conversion nécessaire, taux manuel daté et documenté. |
| Prévisions météo | API météo | Non | Saisie et planification manuelles ; traiter la météo comme une amélioration future. |
| Authentification fédérée | Google, Microsoft ou autre fournisseur OAuth | Non | Compte e-mail/mot de passe local, correctement sécurisé. |
| Statistiques d’usage | Service d’analytics | Non | Mesures internes minimales ou aucune collecte, conformément à la politique de confidentialité. |

Les offres gratuites ont souvent des limites, conditions d’utilisation et exigences de confidentialité : elles doivent être vérifiées avant adoption. « Sans service payant » ne signifie pas sans coût d’hébergement, de nom de domaine, de maintenance ou de temps de développement.

---

## 11. Contraintes techniques, qualité et risques

### 11.1 Socle technique proposé

- **Serveur :** PHP maintenu, avec version supportée et environnement homogène en développement et production.
- **Base de données :** MySQL avec moteur transactionnel InnoDB, clés étrangères, contraintes et index.
- **Interface :** HTML5 sémantique, CSS responsive et JavaScript progressif ; les opérations essentielles restent validées et exécutées par PHP.
- **Accès aux données :** PDO et requêtes préparées.
- **Architecture débutant recommandée :** application PHP organisée en couches simples (routes/contrôleurs, services métier, accès aux données, vues), sans framework au départ si le projet pédagogique impose PHP natif. Éviter les fichiers PHP mélangeant toute la logique et le HTML.
- **Environnements :** distinguer développement et production ; documenter la configuration, l’installation, les migrations de base et les sauvegardes.
- **Tests :** tests des règles de calcul et contrôles manuels des parcours principaux ; ajouter les tests d’intégration à mesure que le projet grandit.

### 11.2 Exigences de qualité non fonctionnelles

- Interface responsive et utilisable au clavier ; libellés et erreurs compréhensibles.
- Temps de réponse raisonnable sur une exploitation de taille habituelle ; indexer les recherches courantes (exploitation, élevage, date, catégorie).
- Dates stockées de manière cohérente et affichées selon le fuseau configuré.
- Montants présentés avec leur devise et sans ambiguïté d’arrondi.
- Exports limités aux données autorisées.
- Code et base documentés assez pour installer, sauvegarder, restaurer et dépanner l’application.

### 11.3 Risques et mesures de réduction

| Risque | Conséquence | Réduction proposée |
|---|---|---|
| Règles comptables non définies | Bénéfice trompeur ou double comptabilisation | Appeler le chiffre un résultat simplifié, formaliser les catégories et cas de stock avant les rapports. |
| Mauvaise saisie ou corrections invisibles | Effectifs et stocks incohérents | Journal immuable autant que possible, opérations inverses, motif obligatoire et bilan d’incohérences. |
| Confusion entre vente, encaissement et créance | Chiffres de trésorerie faux | Distinguer statut de vente et paiement ; reporter le suivi des paiements si le besoin n’est pas confirmé. |
| Plusieurs unités ou devises | Totaux et quantités non comparables | Une devise par exploitation et unités fixes par article dans le MVP. |
| Accès croisé entre exploitations | Divulgation ou altération de données | Contrôle d’appartenance systématique, tests d’autorisation et clés étrangères. |
| Perte ou corruption de données | Interruption de l’activité | Sauvegardes automatisées et test de restauration. |
| Connexion Internet instable | Saisie impossible | Prévoir une procédure manuelle temporaire ; ne pas promettre le hors-ligne avant une conception dédiée. |
| Extension prématurée du périmètre | Retard et complexité disproportionnée | Livrer le MVP, valider les usages, puis prioriser les intégrations. |
| Réglementations locales inconnues | Documents ou traitement de données non conformes | Identifier pays d’exploitation, règles fiscales, protection des données et conservation avant mise en production. |

---

## 12. Décisions à valider avant le développement

| Décision | Choix recommandé pour démarrer | Pourquoi / impact |
|---|---|---|
| Public ou application privée | Application privée, inscription sur invitation ou compte unique initial | Évite de construire une marketplace et simplifie le contrôle d’accès. |
| Nombre d’exploitations par compte | Une exploitation par compte dans le MVP | Réduit la complexité ; la base peut conserver une relation propriétaire/exploitation évolutive. |
| Collaborateurs et rôles | Propriétaire + rôle opérateur minimal, ou propriétaire seul | À confirmer selon le travail réel sur l’exploitation. |
| Types d’animaux et de production | Poulets de chair en premier, autres types ultérieurement | Réduit les règles propres aux cycles de ponte et à d’autres espèces. |
| Devise, pays et fuseau horaire | Une devise configurable par exploitation ; préciser le pays avant les obligations légales | Évite les mélanges de monnaie et rend les formats cohérents. |
| Fonctionnement sans Internet | En ligne uniquement au lancement | Le hors-ligne demande synchronisation, résolution de conflits et stockage local sécurisé. |
| Calcul du résultat | Résultat simplifié de gestion, méthode explicitée | Une comptabilité complète dépend du pays et d’un professionnel compétent. |
| Achats et valorisation du stock | Suivre quantités et dépenses séparément ; ne pas calculer de coût des ventes automatisé au départ | Limite le risque de double comptabilisation et de mauvaise valorisation. |
| Paiements et dettes clients | Saisir les ventes ; paiements partiels/créances en phase ultérieure | Évite de confondre revenu et encaissement dans le MVP. |
| Produits commercialisés | Animaux seulement ou animaux + œufs/autres produits | Influence les écrans, stocks, unités et règles d’effectif. |
| Documents justificatifs | Pas de pièces jointes au lancement ; référence textuelle facultative | Réduit risques de sécurité, stockage et gestion de fichiers. |
| Courriel de confirmation | Obligatoire en production ou reporté avec comptes administrés | Nécessite la configuration d’un service SMTP et une politique d’envoi. |
| Suppression des opérations | Annulation traçable plutôt que suppression physique | Préserve la fiabilité des bilans et l’audit. |

Ces décisions devraient être consignées et validées avant le modèle de données et les premières interfaces.

---

## 13. Définition de « terminé » par module

Un module est considéré terminé lorsque tous les éléments suivants s’appliquent :

1. Les parcours et champs du module sont utilisables, y compris les états vides, erreurs et confirmations.
2. Les validations métier sont appliquées côté serveur ; les données invalides ne sont pas enregistrées.
3. Les permissions sont testées sur les actions et les données, pas seulement sur l’affichage.
4. Les calculs affichés peuvent être rapprochés des opérations sources et suivent les règles de ce document.
5. Les modifications, annulations et actions sensibles ne détruisent pas l’historique utile.
6. Les écrans sont utilisables sur téléphone et ordinateur, avec navigation clavier de base.
7. Les erreurs ne révèlent pas de secrets ou de détails internes à l’utilisateur.
8. Les tests du parcours nominal et des principaux cas limites passent, et la documentation associée est à jour.

### Critères transverses de recette

- Un utilisateur ne peut lire ni modifier une autre exploitation en changeant une URL ou un identifiant.
- La somme des mouvements d’effectifs égale l’effectif courant et la fiche explique le calcul.
- La somme des mouvements d’un article égale son stock, sans stock négatif autorisé.
- Une vente déduit une seule fois la quantité correspondante.
- Une opération annulée n’apparaît plus comme vente/charge active dans les totaux, mais demeure visible dans l’historique.
- La clôture empêche les opérations courantes et conserve les rapports d’un élevage clôturé.
- Une restauration de sauvegarde permet de retrouver un jeu de données de test cohérent.
- Aucun service payant ni API externe n’est requis pour créer un élevage, suivre ses opérations ou consulter son bilan simplifié.

---

## 14. Ordre de réalisation conseillé

1. Valider pays, devise, espèce/production, modèle d’accès et règles de calcul.
2. Mettre en place la structure PHP, la base MySQL et la configuration sécurisée.
3. Créer comptes, exploitation et permissions minimales.
4. Créer élevages et journal des effectifs ; valider les invariants par des tests.
5. Ajouter articles, mouvements de stock et achats, en séparant mouvement physique et charge.
6. Ajouter dépenses, ventes et annulations traçables.
7. Construire tableau de bord, rapports, export CSV et clôture.
8. Tester les droits, sauvegardes/restaurations et parcours complet sur données fictives.
9. Déployer en HTTPS et n’activer les courriels qu’après configuration et tests.
10. Évaluer les besoins réels avant d’ajouter paiements, SMS, marketplace, hors-ligne ou prévisions.

**Conclusion :** le projet peut commencer sans API externe payante. Le principal prérequis n’est pas un choix de bibliothèque, mais la validation des règles métier — surtout devise, type d’élevage, calcul du résultat, traitement des stocks et nombre d’utilisateurs — afin que les chiffres affichés soient fiables et compréhensibles.
