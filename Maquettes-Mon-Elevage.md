# Maquettes visuelles — Mon Élevage

**Statut :** proposition de design pour validation, avant développement fonctionnel  
**Prévisualisation web :** ouvrir [`maquettes/index.html`](./maquettes/index.html) dans un navigateur.  
**Périmètre :** prototype HTML/CSS/JavaScript statique de navigation visuelle. Il n’y a ni compte réel, ni écriture en base, ni envoi de formulaire, ni export PDF, ni connexion météo.

Toutes les valeurs visibles dans le prototype sont fictives. Les boutons indiquent les actions attendues, mais ne les exécutent pas. Seule la navigation entre vues de la maquette et le sélecteur d’espace sont interactifs.

## 1. Direction visuelle générale

- **Intention :** une interface de gestion professionnelle, claire, apaisante et lisible, à dominante vert forêt / vert sauge, sur fond très clair.
- **Typographie :** polices système, sans téléchargement de police externe ; titres légèrement renforcés, tableaux compacts et lisibles.
- **Navigation ordinateur :** barre latérale fixe avec identité, espace courant, groupes de rubriques et accès au profil ; barre supérieure avec fil d’Ariane, date et notifications.
- **Navigation téléphone :** bouton de menu qui ouvre la barre latérale coulissante ; barre supérieure simplifiée ; cartes empilées, tableaux défilants horizontalement, formulaires en colonne.
- **Composants partagés :** boutons principal/secondaire/danger, cartes, indicateurs, badges d’état, champs avec libellés, filtres, tableaux, onglets, alertes, états vides, mini-graphiques et fil d’activité.
- **Accessibilité visée :** contraste lisible, labels explicites, focus/navigation clavier à réaliser lors du développement, contenu qui ne dépend pas seulement de la couleur.
- **Aucune identité de compte ni donnée métier réelle n’est nécessaire pour parcourir la prévisualisation.**

### Convention de lecture du catalogue

Les actions décrites ci-dessous sont **prévues**. Elles ne sont pas encore fonctionnelles. « Adaptation mobile » décrit le comportement de présentation proposé, non une recette de mise en production.

---

## A. Pages publiques

### A1. Accueil

- **Objectif :** expliquer la valeur du service et orienter vers la découverte ou l’inscription.
- **Disposition :** en-tête/identité, grand hero avec message principal et illustration, cartes de bénéfices, appel à l’action.
- **Composants :** titre, texte de présentation, illustration abstraite, boutons « Créer mon espace » et « Découvrir le projet », cartes synthétiques.
- **Actions prévues :** ouvrir le formulaire d’inscription ; aller à la présentation du projet. Boutons inactifs dans la maquette.
- **Données :** proposition de valeur, bénéfices et liens publics ; aucune donnée d’exploitation.
- **Mobile :** hero et illustration empilés, titre réduit, CTA sur pleine largeur si nécessaire.

### A2. Présentation du projet

- **Objectif :** présenter le contexte, les utilisateurs visés et les principes de confidentialité.
- **Disposition :** introduction éditoriale, trois blocs de principes, appel à l’action.
- **Composants :** titre, paragraphe, cartes « prise en main », « historique », « confidentialité ».
- **Actions prévues :** découvrir les fonctionnalités, contacter l’équipe ou créer un compte.
- **Données :** description du projet et de son périmètre, pas de métrique utilisateur réelle.
- **Mobile :** sections en une colonne ; CTA empilés.

### A3. Fonctionnalités

- **Objectif :** donner une vue d’ensemble des capacités prévues.
- **Disposition :** intro suivie d’une grille de fonctions et d’un CTA.
- **Composants :** cartes élevages, effectifs, stocks, charges, ventes et rapports.
- **Actions prévues :** accéder au détail d’une rubrique ou démarrer l’inscription.
- **Données :** descriptions fonctionnelles validées au niveau du concept ; aucune donnée personnelle.
- **Mobile :** grille trois colonnes → deux → une colonne selon la largeur.

### A4. Guide de formation public

- **Objectif :** offrir des ressources d’initiation avant connexion.
- **Disposition :** introduction et cartes thématiques.
- **Composants :** cartes de ressources, durée indicative, catégories, CTA.
- **Actions prévues :** consulter une ressource, filtrer un thème ou créer un compte.
- **Données :** articles publics, catégorie et durée ; contenu non rédigé dans cette maquette.
- **Mobile :** cartes empilées ; filtres accessibles en haut ou sous forme de menu.

### A5. Contact

- **Objectif :** permettre une prise de contact.
- **Disposition :** formulaire principal et panneau latéral d’informations.
- **Composants :** nom, e-mail, sujet, message, bouton d’envoi, bloc coordonnées.
- **Actions prévues :** envoyer une demande, afficher une confirmation et protéger le formulaire contre les abus.
- **Données :** informations saisies par le visiteur ; coordonnées d’exemple dans la maquette.
- **Mobile :** formulaire puis coordonnées dans une seule colonne.

### A6. Inscription

- **Objectif :** créer un compte et amorcer la configuration.
- **Disposition :** panneau de marque/explication à gauche, formulaire à droite.
- **Composants :** nom, e-mail, mot de passe, nom d’exploitation, acceptation des conditions.
- **Actions prévues :** valider les champs, créer le compte, puis proposer la configuration de l’exploitation ; lien de connexion.
- **Données :** données de compte minimales et choix d’acceptation ; aucune inscription réelle dans la maquette.
- **Mobile :** panneau de marque plus court au-dessus du formulaire, formulaire pleine largeur.

### A7. Connexion

- **Objectif :** accéder à un compte existant.
- **Disposition :** même composition visuelle que l’inscription pour cohérence.
- **Composants :** e-mail, mot de passe, bouton de connexion, lien « Mot de passe oublié ? ».
- **Actions prévues :** authentifier, afficher une erreur générique, ouvrir la récupération.
- **Données :** identifiants saisis ; aucune authentification n’est effectuée dans le prototype.
- **Mobile :** formulaire unique en colonne, panneau d’identité réduit.

### A8. Mot de passe oublié

- **Objectif :** demander un lien de réinitialisation.
- **Disposition :** formulaire d’authentification simplifié, explication et retour connexion.
- **Composants :** champ e-mail et bouton d’envoi.
- **Actions prévues :** envoyer un lien à durée limitée ; réponse identique qu’un compte existe ou non.
- **Données :** e-mail ; aucun message n’est envoyé dans la maquette.
- **Mobile :** formulaire pleine largeur avec CTA large.

---

## B. Espace éleveur

### B1. Tableau de bord

- **Objectif :** comprendre rapidement l’activité et les points à traiter.
- **Disposition :** salutations et filtres, quatre indicateurs, graphique d’évolution, élevages en cours, activité récente.
- **Composants :** cartes statistiques, mini-courbe, badges, tableau d’activité.
- **Actions prévues :** sélectionner une période, ouvrir un élevage, exporter un aperçu, consulter une opération.
- **Données :** élevages actifs, effectifs, charges et ventes de la période, opérations récentes.
- **Mobile :** cartes sur deux colonnes puis une si étroit ; graphique et tableau empilés ; tableau défilant.

### B2. Liste des élevages

- **Objectif :** retrouver, filtrer et comparer les bandes.
- **Disposition :** filtres et indicateurs puis table des élevages.
- **Composants :** recherche, filtres statut/type, indicateurs total/actifs/clôturés, table, badges.
- **Actions prévues :** ouvrir le détail, filtrer, créer un élevage, consulter les archives.
- **Données :** code, nom, type/objectif, effectif, âge de bande et statut.
- **Mobile :** filtres empilables ; table dans un conteneur horizontal avec accès à la fiche.

### B3. Création d’un élevage

- **Objectif :** saisir l’identité et les données de départ d’une bande.
- **Disposition :** formulaire segmenté « Identification » puis actions de bas de page.
- **Composants :** nom/code, type, objectif, souche facultative, date, effectif initial, notes, indication d’étape.
- **Actions prévues :** annuler, enregistrer comme brouillon ou créer l’élevage et son mouvement initial.
- **Données :** champs de la bande ; aucun champ ne pré-remplit une donnée réelle.
- **Mobile :** formulaire sur une colonne ; actions faciles à atteindre en bas.

### B4. Détails d’un élevage

- **Objectif :** consulter le portrait opérationnel et l’historique d’une bande.
- **Disposition :** en-tête avec code/statut, indicateurs, onglets, graphique et activité récente.
- **Composants :** badges, résumé d’effectif, mortalité, poids, dépenses, onglets, mini-graphe, journal.
- **Actions prévues :** modifier, naviguer aux onglets, clôturer sous confirmation et permission adaptée.
- **Données :** informations d’élevage, totaux calculés depuis journaux et dernières opérations.
- **Mobile :** onglets horizontaux défilants ; cartes empilées ; actions principales visibles en haut.

### B5. Calendrier et progression

- **Objectif :** visualiser les étapes et rappels par date.
- **Disposition :** sélecteur de mois et grille mensuelle.
- **Composants :** navigation précédent/suivant, jours, événements colorés et rappels.
- **Actions prévues :** changer de mois, ouvrir un suivi ou ajouter un rappel.
- **Données :** dates de démarrage, événements sanitaires, pesées et rappels.
- **Mobile :** grille compacte ; textes d’événement remplacés par marqueurs ouvrables ; vues semaine/jour recommandées si la grille devient trop dense.

### B6. Suivi quotidien

- **Objectif :** consulter et consigner l’observation journalière.
- **Disposition :** indicateurs « bandes suivies / complétude », filtres de date et journal.
- **Composants :** sélecteur date/bande, résumé météo saisie si retenue, table d’observations.
- **Actions prévues :** démarrer/compléter une observation et ouvrir le détail.
- **Données :** effectif observé, température et notes datées par bande.
- **Mobile :** formulaire par sections, une colonne ; liste d’observations défilable.

### B7. Croissance et pesées

- **Objectif :** comparer les pesées de la bande à ses objectifs.
- **Disposition :** choix de bande, indicateurs, courbe, table d’échantillons.
- **Composants :** poids moyen, évolution, cible, uniformité, série temporelle et journal des prélèvements.
- **Actions prévues :** saisir une pesée, sélectionner une bande/période, ouvrir un échantillon.
- **Données :** date, taille d’échantillon, poids moyen, cible et écart calculé.
- **Mobile :** graphique sur largeur disponible ; cartes KPI réduites ; tableau horizontal.

### B8. Santé et mortalité

- **Objectif :** surveiller mortalité, pertes et événements sanitaires.
- **Disposition :** indicateurs de mortalité/alertes et journal chronologique.
- **Composants :** filtre d’élevage, déclaration d’événement, badges, rappels.
- **Actions prévues :** déclarer mortalité/perte, planifier un rappel et consulter une fiche.
- **Données :** type/date/quantité/motif, traitements planifiés et taux basé sur une définition affichée.
- **Mobile :** journal en liste compacte ; saisie guidée, motifs faciles à choisir.

### B9. Guide de formation privé

- **Objectif :** rendre les ressources consultables pendant le travail.
- **Disposition :** catégories et grille de contenus.
- **Composants :** cartes, durée, progression personnelle éventuelle, recherche.
- **Actions prévues :** ouvrir, marquer comme consulté et reprendre une ressource.
- **Données :** contenus éditoriaux et avancement du compte.
- **Mobile :** cartes en une colonne, filtres repliables.

### B10. Produits et alimentation

- **Objectif :** consulter le catalogue propre à l’exploitation.
- **Disposition :** filtres par catégorie et cartes d’articles.
- **Composants :** recherche, catégories, référence, unité, disponibilité, photo/icône.
- **Actions prévues :** ajouter/modifier une référence et ouvrir sa fiche.
- **Données :** noms, catégories, unité et seuil d’alerte ; niveaux issus du ledger stock.
- **Mobile :** cartes deux colonnes puis une ; recherche pleine largeur.

### B11. Gestion des stocks

- **Objectif :** suivre le disponible et repérer les articles bas.
- **Disposition :** indicateurs puis table des mouvements et soldes.
- **Composants :** filtres, produit/unité, stock courant, seuil, badge d’alerte, journal.
- **Actions prévues :** saisir réception, consommation, perte ou ajustement motivé.
- **Données :** quantités par unité et somme des mouvements ; aucune conversion cachée.
- **Mobile :** filtres empilables, table horizontale, alerte affichée sur la carte d’article.

### B12. Fournisseurs et achats

- **Objectif :** suivre l’approvisionnement et l’arrivée des intrants.
- **Disposition :** cartes d’état des achats et liste des commandes/réceptions.
- **Composants :** répertoire fournisseur, date, lignes d’achat, état de réception, référence de facture.
- **Actions prévues :** créer un achat, enregistrer une réception, ouvrir/modifier un fournisseur.
- **Données :** fournisseur, article, quantité, montant, dates et lien vers dépense/mouvement de stock.
- **Mobile :** achats sous forme de fiches ; détail des lignes dans un panneau séparé.

### B13. Budget prévisionnel

- **Objectif :** comparer une estimation de coûts à l’engagement réel.
- **Disposition :** sélecteur d’élevage, KPI, graphique par poste et tableau comparatif.
- **Composants :** budgets par catégorie, engagé, reste et écart.
- **Actions prévues :** créer/modifier une prévision, changer de bande/période.
- **Données :** prévisions estimées séparées des dépenses effectivement enregistrées.
- **Mobile :** KPIs en cartes, graphique empilé au-dessus des postes.

### B14. Dépenses

- **Objectif :** consulter et saisir les charges.
- **Disposition :** indicateurs et journal filtrable.
- **Composants :** période, catégorie, élevage/non affecté, statut, montant/devise et référence justificative.
- **Actions prévues :** ajouter une dépense, consulter, corriger par annulation traçable.
- **Données :** date, catégorie, montant, devise, affectation et auteur.
- **Mobile :** formulaire en une colonne ; montants et catégorie mis en évidence dans une liste compacte.

### B15. Matériel

- **Objectif :** tenir un inventaire des équipements et entretiens.
- **Disposition :** KPI équipement/maintenance et table de parc.
- **Composants :** catégorie, emplacement, état, échéance d’entretien et valeur indicative.
- **Actions prévues :** ajouter équipement, signaler une panne, consigner une intervention.
- **Données :** équipements, achats et historique d’intervention.
- **Mobile :** fiches matériel verticales avec statut en tête.

### B16. Météo et alertes

- **Objectif :** présenter les rappels de l’exploitation et éventuellement un aperçu météo.
- **Disposition :** carte météo distincte du journal d’alertes.
- **Composants :** conditions/lieu/source, prévisions, alertes de seuil et rappels.
- **Actions prévues :** configurer une alerte, marquer un rappel traité, choisir l’exploitation.
- **Données :** valeurs météo datées et sourcées si intégration future ; alertes locales, sans confondre prévision et mesure.
- **Mobile :** carte météo puis liste de rappels en une colonne.
- **Note :** contenu météo fictif dans la maquette ; connexion à un fournisseur externe non comprise.

### B17. Clients

- **Objectif :** consulter le répertoire privé des acheteurs.
- **Disposition :** indicateurs commerciaux et table de clients.
- **Composants :** recherche, filtres, coordonnées minimales, historique de commandes/solde éventuel.
- **Actions prévues :** créer/modifier/archiver une fiche et ouvrir l’historique.
- **Données :** nom, moyen de contact, ventes et solde à recevoir seulement si suivi des paiements activé.
- **Mobile :** cartes client, coordonnées exposées uniquement aux membres autorisés.

### B18. Commandes

- **Objectif :** suivre une demande de client avant la vente/livraison.
- **Disposition :** indicateurs par état et liste chronologique.
- **Composants :** référence, client, lignes, date prévue et statut.
- **Actions prévues :** créer/confirmer/préparer/livrer une commande, convertir en vente.
- **Données :** demande et disponibilité ; une commande ne modifie pas le stock tant qu’elle n’est pas confirmée selon la règle à valider.
- **Mobile :** cartes par commande avec état et date de livraison visibles.

### B19. Ventes et paiements

- **Objectif :** distinguer chiffre d’affaires et encaissements.
- **Disposition :** KPI (vente, reçu, à recevoir) et journal filtré.
- **Composants :** client, lignes, prix, quantité, statut paiement, références d’encaissement/remboursement.
- **Actions prévues :** enregistrer une vente, consigner un paiement, annuler avec motif.
- **Données :** vente confirmée, lignes produit/animaux, montants calculés côté serveur et paiements séparés.
- **Mobile :** saisie par étapes ; aperçu du total et disponibilité avant confirmation ; table compacte.

### B20. Statistiques et rentabilité

- **Objectif :** examiner les résultats simplifiés d’une période.
- **Disposition :** choix dates/bandes, KPI, courbes ventes/charges, comparaison par bande.
- **Composants :** chiffre d’affaires, charges, résultat simplifié, mortalité, graphiques et tableau.
- **Actions prévues :** filtrer, comparer et exporter.
- **Données :** agrégats des journaux, devises séparées, charges non réparties visibles.
- **Mobile :** indicateurs en cartes ; graphiques redimensionnés ; comparaisons en tableau défilant.
- **Avertissement visible :** indicateur de gestion informatif, pas une comptabilité fiscale certifiée.

### B21. Rapports PDF

- **Objectif :** sélectionner un modèle et visualiser le document projeté.
- **Disposition :** choix de rapport, période et aperçus de pages.
- **Composants :** bilan d’élevage, état des stocks, synthèse financière, options de période.
- **Actions prévues :** prévisualiser puis générer/télécharger/imprimer un PDF.
- **Données :** photographie issue des journaux de la période ; exports protégés par tenant et permissions.
- **Mobile :** aperçu vertical page par page avec contrôles accessibles en haut.
- **Statut :** aperçu et génération PDF non implémentés.

### B22. Historique des rapports

- **Objectif :** retrouver les rapports préparés.
- **Disposition :** filtres puis table d’archives.
- **Composants :** référence, modèle, période, créateur, date, statut.
- **Actions prévues :** ouvrir, retélécharger, archiver selon politique de conservation.
- **Données :** métadonnées de génération ; stockage réel des fichiers à décider.
- **Mobile :** liste de fiches avec période et type en évidence.

### B23. Profil et paramètres

- **Objectif :** modifier profil, sécurité et préférences d’exploitation.
- **Disposition :** onglets paramètre à gauche/sommet, formulaire principal, panneau membres.
- **Composants :** profil, devise, pays/fuseau, sécurité, notifications et accès collaborateurs.
- **Actions prévues :** sauvegarder, modifier mot de passe, gérer membres et préférences.
- **Données :** compte et exploitation courants ; aucun secret affiché.
- **Mobile :** onglets horizontaux ou liste en accordéon ; formulaires en une colonne.

---

## C. Espace administrateur

### C1. Tableau de bord administrateur

- **Objectif :** avoir une vue de santé globale du service, sans exposer le détail privé des exploitations.
- **Disposition :** indicateurs de comptes/activité, tendances et alertes système.
- **Composants :** KPI agrégés, graphiques et journal d’événements récents.
- **Actions prévues :** filtrer période, accéder aux comptes et journaux selon mandat.
- **Données :** métriques agrégées minimisées ; données de ferme uniquement si autorisation et nécessité.
- **Mobile :** KPI empilés, table transformée en liste.

### C2. Utilisateurs

- **Objectif :** gérer les comptes et adhésions.
- **Disposition :** KPI, recherche et table.
- **Composants :** identifiant, nom, e-mail, rôle/exploitation, état, date d’inscription.
- **Actions prévues :** consulter, suspendre/réactiver, gérer une invitation selon permission.
- **Données :** données d’identité minimales, sans affichage de mot de passe ou jeton.
- **Mobile :** fiches utilisateurs et actions derrière menu accessible.

### C3. Rôles et permissions

- **Objectif :** rendre les droits lisibles et révisables.
- **Disposition :** matrice par rôle/action et commandes d’administration.
- **Composants :** colonnes propriétaire, gestionnaire, opérateur, comptable ; états autorisé/interdit.
- **Actions prévues :** consulter ou éditer un rôle, enregistrer et auditer les changements.
- **Données :** permissions configurées, jamais un rôle envoyé comme preuve d’autorité par le navigateur.
- **Mobile :** matrice défilante ou vue d’un rôle à la fois.

### C4. Contenus de formation

- **Objectif :** gérer les ressources pédagogiques.
- **Disposition :** filtres par catégorie/statut et table éditoriale.
- **Composants :** titre, catégorie, statut de publication, date, aperçu.
- **Actions prévues :** créer, prévisualiser, publier, dépublier.
- **Données :** contenu éditorial, auteur et historique de version à ajouter à la réalisation.
- **Mobile :** fiches éditoriales verticales.

### C5. Référentiels d’élevage

- **Objectif :** configurer les listes contrôlées utilisées par les formulaires.
- **Disposition :** filtre de référentiel et table par catégorie.
- **Composants :** espèces, objectifs, statuts, unités et état actif.
- **Actions prévues :** ajouter/modifier/archiver une valeur ; éviter de casser les anciennes fiches.
- **Données :** code stable, libellé, catégorie, état et date de modification.
- **Mobile :** sections par catégorie et lignes compactes.

### C6. Produits de référence

- **Objectif :** proposer des références générales aux exploitants sans imposer leur catalogue privé.
- **Disposition :** catégories et table de références.
- **Composants :** code, nom, catégorie, unité recommandée, statut.
- **Actions prévues :** créer, modifier, archiver, prévisualiser la façon dont la référence sera proposée.
- **Données :** métadonnées génériques ; les prix/fournisseurs d’une ferme ne sont pas partagés.
- **Mobile :** cartes produit et filtres repliables.

### C7. Paramètres et journaux

- **Objectif :** consulter l’état du service et les événements d’administration.
- **Disposition :** indicateurs système et table chronologique.
- **Composants :** état, métriques, dernière sauvegarde illustrative, acteur, action, cible, date.
- **Actions prévues :** filtrer les journaux et paramètres ; exporter avec contrôle des secrets/données.
- **Données :** événements minimisés ; aucun jeton, mot de passe ou secret ne doit apparaître.
- **Mobile :** flux d’événements sous forme de cartes ; filtres dans un panneau compact.
- **Statut :** métriques et événements de démonstration fictifs.

---

## 2. Parcours visibles dans la maquette

1. Depuis **Accueil**, rejoindre les pages de présentation et le guide public.
2. Depuis **Inscription**, visualiser l’état attendu après création de compte ; aucune persistance n’est simulée.
3. Dans **Espace éleveur**, parcourir le tableau de bord, liste, création et fiche d’élevage, puis les suivis, stocks, ventes et rapports.
4. Dans **Administration**, parcourir la supervision, les comptes, permissions et référentiels.
5. Utiliser le menu latéral sur ordinateur ou le bouton de menu sur téléphone pour changer d’écran.

Les liens entre formulaires et écrans, par exemple « créer un élevage » ou « saisir une vente », servent à communiquer l’intention du design et ne valident ni ne sauvegardent de données.

## 3. Décisions de design à valider

1. Valider la palette vert forêt/sauge et le niveau de densité des tables.
2. Confirmer l’identité de marque, le logo, le ton rédactionnel et les illustrations définitives.
3. Confirmer si le site public doit être une vitrine complète ou une simple page d’accès.
4. Confirmer les permissions et la terminologie de l’espace administrateur.
5. Confirmer les modules supplémentaires demandés ici : formation, calendrier, croissance, santé, achats/fournisseurs, budget, matériel, météo, commandes, paiements et rapports PDF. Ils dépassent plusieurs fonctions du MVP du cahier des charges ; leurs règles et leur priorité devront être ajoutées au périmètre fonctionnel avant développement.
6. Confirmer si les clients, commandes, paiements partiels et comptes collaborateurs font partie du premier lot.
7. Choisir la forme de calendrier mobile (mois compact, semaine ou agenda).
8. Définir les unités, indicateurs de croissance, alertes météo, politiques de justificatifs et règles de conservation avant réalisation des écrans métier concernés.

## 4. Limites de cette livraison

- Les formulaires, boutons d’action, filtres, notifications, graphiques et tableaux sont visuels ; leurs opérations métier ne sont pas codées.
- Les seuls comportements interactifs sont la navigation de démonstration, le changement d’espace et l’ouverture/fermeture du menu sur petit écran.
- Les statistiques, dates, profils, noms et coordonnées affichés sont des exemples fictifs.
- Les fonctions nécessitant des règles métier encore à valider ou une API externe sont indiquées comme telles.
- Cette livraison attend la validation du design global ; elle ne lance pas la construction fonctionnelle du site.
