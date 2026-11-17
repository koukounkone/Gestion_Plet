# Système visuel — Mon Élevage

**Version :** proposition issue des maquettes visuelles, soumise à validation  
**État actuel :** les fondations et le catalogue visuels documentés ici ont été repris dans le squelette partagé du site. Les vues réutilisables se trouvent dans `app/Views`, avec les styles globaux et le JavaScript dans `public/assets`. Les écrans disponibles restent des démonstrations : aucun module métier, appel réseau, sauvegarde, validation de données ou authentification n’est activé.

## 1. Aperçu de la bibliothèque

- [Prévisualiser les composants UI](./maquettes/ui-kit.html)
- [Parcourir les maquettes de pages](./maquettes/index.html)
- Feuille de style partagée : [`maquettes/styles.css`](./maquettes/styles.css)
- Squelette PHP responsive : [`public/index.php`](./public/index.php)
- Styles de l’interface : [`public/assets/css/app.css`](./public/assets/css/app.css)
- Navigation mobile : [`public/assets/js/app.js`](./public/assets/js/app.js)

Un lien **Bibliothèque UI** est également disponible au pied de la prévisualisation générale.

La bibliothèque réutilise les classes présentes dans les maquettes (`.button`, `.card`, `.field`, `.data-table`, `.pill`, `.alert`, `.modal`, `.empty-state`, etc.). L’objectif est de conserver la même identité sur les pages publiques, l’espace éleveur et l’administration.

Les contrôles de la page UI kit sont illustratifs. Aucun bouton métier n’est branché ; les exemples de succès, d’erreur et de données sont fictifs.

## 2. Fondations visuelles

### Palette

| Jeton CSS | Couleur | Usage |
|---|---|---|
| `--color-brand-950` | `#153B30` | identité forte, titre principal, barre de démonstration |
| `--color-brand-900` | `#194638` | surfaces de mise en avant et blocs de marque |
| `--color-brand-800` | `#205A46` | liens/actions secondaires de marque |
| `--color-brand-700` | `#287451` | action primaire, sélection de navigation, accent |
| `--color-brand-600` | `#32885E` | survol/accent secondaire |
| `--color-brand-100` | `#E7F2EB` | navigation et sélection douces |
| `--color-brand-50` | `#F3F8F4` | fond d’éléments secondaires |
| `--color-canvas` | `#F7F8F6` | fond général de l’application |
| `--color-surface` | `#FFFFFF` | cartes, formulaires, navigation |
| `--color-text` | `#1D2D27` | texte principal |
| `--color-text-secondary` | `#536158` | texte de lecture secondaire |
| `--color-text-muted` | `#78847E` | aide, date, métadonnée |
| `--color-border` | `#E8ECE9` | bordures et séparateurs |
| `--color-success` / fond | `#287451` / `#EAF4EC` | opération réussie, actif |
| `--color-warning` / fond | `#A76A19` / `#FFF6E8` | attention, en attente, seuil bas |
| `--color-danger` / fond | `#B45349` / `#FFF0EE` | erreur, action destructive, clôture |
| `--color-info` / fond | `#4E719D` / `#EFF5FC` | information neutre |

Règles :

- le vert est la couleur de marque, non un indicateur universel de succès ;
- chaque état textuel reste écrit dans son badge/alerte : la couleur ne porte jamais seule le sens ;
- les fonds d’alerte restent doux pour privilégier la lisibilité ;
- ne pas utiliser le rouge pour une simple action secondaire ; le réserver à l’erreur et au destructif.

### Typographie

- **Famille :** `Segoe UI`, puis `Arial`, puis sans-serif système ; aucun chargement de police externe.
- **Graisses :** 400 lecture, 600 contrôles/labels, 700 titres et valeurs.
- **Échelle de référence :**

| Niveau | Taille desktop | Mobile | Usage |
|---|---:|---:|---|
| Display | 32 px | 27 px | grand titre éditorial de la page d’accueil |
| H1 | 24 px | 21 px | titre d’écran privé |
| H2 | 18 px | 17 px | titre de section ou de dialogue |
| H3 | 15 px | 14 px | sous-section ou carte |
| Corps | 14 px | 14 px | contenu principal |
| Corps secondaire | 12 px | 12 px | description, aide de lecture |
| Label/table secondaire | 10–11 px | 10–11 px | formulaire et tableau dense |
| Micro-label | 9 px minimum | 9 px minimum | catégorie ou métadonnée courte, jamais une instruction critique |

La taille ne suffit pas à la hiérarchie : utiliser aussi le poids, l’espacement et la couleur du texte.

### Espacement, grille et formes

Les jetons d’espacement sont basés sur 4 px : `--space-1` 4, `--space-2` 8, `--space-3` 12, `--space-4` 16, `--space-5` 20, `--space-6` 24, `--space-8` 32, `--space-10` 40.

- intérieur de champ : 8–12 px ; entre champs : 16–20 px ;
- intérieur de carte : 16–18 px ; entre cartes : 12–16 px ;
- séparation de sections : 24–32 px ;
- rayons : 5 px petits contrôles, 7 px champs/boutons, 9 px cartes, 12 px panneaux importants, pilule 999 px ;
- bordures fines et ombres discrètes ; éviter les ombres décoratives fortes.

## 3. Navigation et structure de page

### Desktop (> 1050 px)

- barre latérale blanche fixe, largeur 252 px, zones de navigation groupées ;
- barre supérieure de 62 px avec fil d’Ariane, notifications et profil ;
- contenu maximal 1600 px, gouttière de 34 px ;
- sélection de menu : fond vert très pâle, texte vert et graisse renforcée.

### Tablette (761–1050 px)

- barre latérale ramenée à 220 px ;
- contenu avec gouttière de 22 px ;
- statistiques et grilles de cartes sur deux colonnes ;
- tables dans un conteneur à défilement horizontal, sans comprimer les colonnes critiques.

### Téléphone (≤ 760 px)

- barre latérale transformée en panneau coulissant, largeur maximale 290 px ou 84vw, ouverte par le bouton menu ;
- barre du haut réduite à 55 px ; fil d’Ariane simplifié ;
- contenu avec gouttière de 14 px ;
- formulaires sur une colonne ; les actions importantes sont à portée de pouce ;
- cartes statistiques sur deux colonnes, une seule si le contenu l’exige ;
- tableaux défilables horizontalement, ou convertis en cartes si la comparaison tabulaire reste lisible ;
- onglets horizontaux défilants, jamais tronqués sans indication ;
- navigation publique horizontale défilable sous la marque.

Au développement, le menu mobile doit également fermer au clic extérieur/Escape, rendre le fond inerte si modal, gérer le focus et exposer son état `aria-expanded`.

## 4. Bibliothèque de composants

Les noms ci-dessous sont les classes de référence déjà définies dans `maquettes/styles.css`. Les handlers, permissions, contenu réel et règles métier restent à construire.

### Boutons

- `.button` : secondaire, fond blanc et bordure douce ;
- `.button.primary` : action principale, vert feuille ;
- `.button.subtle` : action discrète sur fond sauge pâle ;
- `.button.danger` : action destructive, fond blanc et texte rouge ;
- `.button.small`, `.button.large`, `.button.full-width`, `.button.icon-only` : variantes de taille/largeur ;
- états `:hover`, `:focus-visible`, `:disabled` et `[aria-disabled="true"]`.

**Règles :** privilégier un seul primaire par groupe ; utiliser un bouton pour changer l’état, un lien pour naviguer ; libeller les icônes seules avec un nom accessible. Une action de clôture/annulation reste distinguée et demande une confirmation quand elle est irréversible.

### Champs et formulaires

- `.field` associe un label persistant, contrôle et aide ;
- `.input`, `.select`, `.textarea` avec hauteur de contrôle cible de 40 px ;
- `.field.is-invalid` + `.error-text` pour une erreur associée au champ ;
- `.field.is-valid` + `.success-text` uniquement si un retour positif aide réellement ;
- `.hint` pour l’aide non bloquante ;
- `.checkbox-field` pour consentement ou préférences.

**Règles :** indiquer le caractère obligatoire près du libellé ; ne pas dépendre du placeholder ; garder la valeur après erreur ; associer `aria-describedby` et `aria-invalid` côté HTML lors de l’intégration ; expliquer comment corriger l’erreur.

### Cartes et indicateurs

- `.card`, `.card-head`, `.card-body`, `.card-footer` ;
- `.card.interactive` pour les cartes qui sont réellement navigables ;
- `.stat-card`, `.stat-top`, `.stat-value`, `.stat-foot`, `.stat-icon` pour KPI ;
- `.progress` et `.progress span` pour une progression toujours accompagnée d’un texte/valeur.

Les KPI affichent un intitulé explicite, une valeur et une période/unité en contexte. Les nombres financiers utilisent les chiffres tabulaires et affichent la devise.

### Tableaux

- `.data-table-wrap` permet le défilement maîtrisé ;
- `.data-table` avec en-têtes contrastés, lignes aérées, survol très léger et colonnes numériques alignées ;
- `.table-pagination` pour état de pagination ;
- `.table-empty` pour un état vide de tableau.

**Règles :** conserver un ordre de colonnes stable ; fournir en-têtes accessibles ; présenter les actions de ligne dans un menu accessible ; pagination/filtre, résultat vide et erreur de chargement sont des états différents.

### Badges de statut

`.pill` combiné aux variantes sémantiques :

- `.success`, `.active`, `.paid` : actif/payé/terminé normalement ;
- `.warning`, `.pending`, `.paused` : en attente, à surveiller, suspendu ;
- `.danger`, `.closed`, `.overdue` : clôturé, en retard ou état bloquant — vérifier le sens métier avant d’associer ces états ;
- `.info`, `.draft`, `.neutral` : brouillon ou état informatif ;
- `.archived` : archivé, visible en historique.

Toujours présenter un libellé textuel à côté de la couleur. Les états métier doivent être validés avec le cahier des charges, plutôt que déduits arbitrairement de la palette.

### Alertes, toasts et messages

- `.alert.success|warning|danger|info` pour les messages liés au contenu et aux corrections à effectuer ;
- `.toast` pour un résultat bref et non bloquant, `.toast.error` et `.toast.warning` pour les variantes ;
- `.field.is-invalid` pour les erreurs locales.

**Règles :**

- erreur : dire ce qui a échoué, préserver les champs et proposer la correction ;
- succès : confirmer seulement après réussite réelle du serveur ;
- alerte importante : rester visible dans la page, pas uniquement dans une notification éphémère ;
- après enregistrement invalide, placer le focus sur un résumé d’erreurs ou le premier champ fautif ;
- ne pas exposer de trace technique, identifiant de secret ou détail de base de données.

### Fenêtres de confirmation

- `.modal-backdrop`, `.modal`, `.modal-header`, `.modal-body`, `.modal-footer`, `.modal-icon`.
- Le thème danger est explicite, mais ne remplace pas le texte expliquant la conséquence.
- Inclure l’objet concerné, une explication, l’action d’annulation et le libellé précis du CTA destructif.

La maquette montre le style, pas l’interaction complète. L’intégration devra gérer rôle `dialog`, `aria-modal`, titre associé, focus piégé, fermeture Escape si permise, retour du focus et absence d’action par clic involontaire.

### Menus et onglets

- navigation latérale `.nav-list`, `.nav-group`, `.nav-link.active` ;
- onglets `.tabs` et `.tab.active` ;
- menu contextuel `.dropdown-menu`, `.dropdown-item`, `.dropdown-divider` ;
- groupe compact `.context-menu`.

Les menus doivent pouvoir être utilisés au clavier, conserver un focus visible, ne pas être coupés par un parent avec `overflow` et afficher le rôle/état sélectionné.

### Chargement, états vides et données absentes

- `.spinner` et `.loading-indicator` pour une attente courte et contextualisée ;
- `.skeleton`, `.skeleton-line`, `.skeleton-block` si la forme des données est prévisible ;
- `.empty-state` avec illustration, titre, explication et action adaptée si elle est autorisée.

Ne pas montrer un spinner indéfini. Après un délai, fournir l’option de réessayer ou expliquer l’indisponibilité. Un refus d’accès, un échec de chargement et une liste réellement vide doivent avoir trois traitements visuels différents.

### Graphiques et progression

- `.chart`, `.chart-line`, `.chart-grid`, `.chart-label`, `.chart-legend` ;
- utiliser couleurs de marque et secondaires ; fournir la période, l’unité, une légende et une alternative tabulaire/texte ;
- ne pas coder uniquement la différence par couleur ; garder les valeurs accessibles sans survol.

## 5. Jetons exposés par la feuille CSS

Les fondations documentées sont définies en haut de `maquettes/styles.css`. Les couleurs et espacements déjà présents dans la maquette restent compatibles avec les alias existants (`--green-*`, `--ink`, `--muted`, `--line`, `--canvas`, `--white`, `--red`, `--amber`, `--blue`) pour éviter de rompre la prévisualisation lors de cette étape.

Pour les nouvelles vues, préférer les jetons sémantiques :

```css
color: var(--color-text);
background: var(--color-surface);
border-color: var(--color-border);
gap: var(--space-4);
border-radius: var(--radius-lg);
font-family: var(--font-sans);
```

## 6. Contrôle de cohérence avec les maquettes validées

- La palette forêt/sauge, le fond clair et les surfaces blanches sont conservés.
- La police système remplace les dépendances de police distante et fonctionne sans connexion.
- La barre latérale, la barre du haut, les cartes de statistiques et les tables restent basées sur leurs composants existants.
- Les contrôles partagés gagnent un focus visible, des états de validation, des badges sémantiques, des alertes, une confirmation visuelle et des motifs de chargement/vide.
- La mise en page mobile et le respect de `prefers-reduced-motion` sont inclus dans les styles de la bibliothèque.
- La page [`maquettes/ui-kit.html`](./maquettes/ui-kit.html) montre les composants côte à côte pour revue visuelle.

## 7. Limites et décisions à valider

1. Valider la palette vert forêt/sauge, les couleurs d’état et la police système.
2. Valider le niveau de densité des tableaux, les tailles typographiques et le point de rupture mobile.
3. Valider l’apparence des confirmations dangereuses et la terminologie des badges (en particulier « clôturé » : état historique, pas nécessairement erreur).
4. Confirmer les besoins de contraste/accessibilité comme critères de recette WCAG visés avant mise en production.
5. Confirmer si le thème clair est le seul thème prévu ; aucun thème sombre ni changement de marque n’est inclus.

**Après validation seulement**, ces styles peuvent devenir le référentiel des pages fonctionnelles. Cette livraison ne développe pas de module métier.
