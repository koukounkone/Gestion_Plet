<?php
declare(strict_types=1);

$metricsByPage = [
    'dashboard' => [
        ['label' => 'Élevages en cours', 'value' => '3', 'note' => '2 bandes chair · 1 ponte', 'icon' => '♧'],
        ['label' => 'Effectif total', 'value' => '2 786', 'note' => 'animaux · valeur fictive', 'icon' => '◉'],
        ['label' => 'Dépenses du mois', 'value' => '486 500', 'note' => 'FCFA · estimation', 'icon' => '−'],
        ['label' => 'Ventes du mois', 'value' => '693 500', 'note' => 'FCFA · estimation', 'icon' => '↗'],
    ],
    'flock' => [
        ['label' => 'Effectif actuel', 'value' => '1 176', 'note' => 'sur 1 200 au départ', 'icon' => '♧'],
        ['label' => 'Mortalité cumulée', 'value' => '2,0 %', 'note' => '24 pertes · exemple', 'icon' => '＋'],
        ['label' => 'Poids moyen', 'value' => '1 240 g', 'note' => 'pesée fictive · 10 oct.', 'icon' => '⌁'],
        ['label' => 'Dépenses affectées', 'value' => '1 140 000', 'note' => 'FCFA · estimation', 'icon' => '−'],
    ],
];
$metrics = $metricsByPage[$pageKey] ?? [];

if ($page['view'] === 'dashboard' || $page['view'] === 'detail'):
    $tableTitle = $page['view'] === 'dashboard' ? 'Activité récente' : 'Historique de l’élevage';
    $tableColumns = $page['view'] === 'dashboard'
        ? ['DATE', 'ACTIVITÉ', 'ÉLEVAGE', 'DÉTAIL', 'ÉTAT']
        : ['DATE', 'ACTIVITÉ', 'RÉSUMÉ', 'ÉTAT'];
    $tableRows = $page['view'] === 'dashboard'
        ? [
            ['11 oct. 2026', 'Suivi quotidien', 'Bande A — Chair', 'Observation d’exemple', ['label' => 'Exemple', 'tone' => 'info']],
            ['10 oct. 2026', 'Mouvement de stock', 'Aliment croissance', '4 sacs · valeur fictive', ['label' => 'Exemple', 'tone' => 'info']],
            ['09 oct. 2026', 'Vente', 'Marché Central', '385 000 FCFA · fictif', ['label' => 'Exemple', 'tone' => 'info']],
        ]
        : [
            ['11 oct. 2026', 'Suivi quotidien', '1 176 sujets · exemple', ['label' => 'Exemple', 'tone' => 'info']],
            ['10 oct. 2026', 'Pesée', '1 240 g moyen · fictif', ['label' => 'Exemple', 'tone' => 'info']],
            ['08 oct. 2026', 'Dépense', 'Aliment · 560 000 FCFA · fictif', ['label' => 'Exemple', 'tone' => 'info']],
        ];
    ?>
    <?php if ($metrics !== []): ?>
        <section class="metrics-grid" aria-label="Indicateurs de démonstration">
            <?php foreach ($metrics as $metric): ?>
                <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Components'
                    . DIRECTORY_SEPARATOR . 'stat-card.php'; ?>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <section class="dashboard-grid">
        <article class="card chart-card">
            <div class="card-heading">
                <div>
                    <h2><?= $page['view'] === 'dashboard' ? 'Évolution des effectifs' : 'Progression de la bande' ?></h2>
                    <p>Illustration sans données réelles</p>
                </div>
                <span class="period-chip">7 derniers jours</span>
            </div>
            <div class="chart" role="img" aria-label="Graphique illustratif de sept points, données fictives">
                <div class="chart-y-axis"><span>3 000</span><span>2 000</span><span>1 000</span><span>0</span></div>
                <div class="chart-plot">
                    <div class="chart-grid-lines"><i></i><i></i><i></i><i></i></div>
                    <svg viewBox="0 0 600 190" preserveAspectRatio="none" aria-hidden="true">
                        <defs><linearGradient id="chart-fill" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#32885e" stop-opacity=".2"/><stop offset="1" stop-color="#32885e" stop-opacity="0"/></linearGradient></defs>
                        <path class="chart-area" d="M0 142 C45 130 65 136 100 113 S162 122 200 93 S260 101 300 77 S360 88 400 59 S460 70 500 46 S555 53 600 25 L600 190 L0 190Z"/>
                        <path class="chart-line" d="M0 142 C45 130 65 136 100 113 S162 122 200 93 S260 101 300 77 S360 88 400 59 S460 70 500 46 S555 53 600 25"/>
                    </svg>
                    <div class="chart-x-axis"><span>05 oct.</span><span>07 oct.</span><span>09 oct.</span><span>11 oct.</span></div>
                </div>
            </div>
            <div class="chart-legend"><span><i></i>Effectif observé · exemple</span><span class="chart-note">Illustration uniquement</span></div>
        </article>

        <article class="card focus-card">
            <div class="card-heading">
                <div>
                    <h2>À suivre</h2>
                    <p>Éléments de démonstration</p>
                </div>
                <span class="focus-icon" aria-hidden="true">✦</span>
            </div>
            <div class="focus-item">
                <span class="focus-symbol warning-symbol" aria-hidden="true">!</span>
                <div><strong>Vérifier le stock</strong><small>Aliment croissance · quantité fictive</small></div>
            </div>
            <div class="focus-item">
                <span class="focus-symbol info-symbol" aria-hidden="true">i</span>
                <div><strong>Compléter une observation</strong><small>Pondeuses — lot 4 · exemple</small></div>
            </div>
            <div class="focus-footer">Les alertes réelles seront définies dans les modules métier.</div>
        </article>
    </section>

    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Components'
        . DIRECTORY_SEPARATOR . 'data-table.php'; ?>

<?php elseif ($page['view'] === 'table'): ?>
    <?php
    $tableTitle = $page['tableTitle'];
    $tableColumns = $page['columns'];
    $tableRows = $page['rows'];
    ?>
    <div class="list-toolbar">
        <label class="search-field">
            <span class="visually-hidden">Rechercher dans l’exemple</span>
            <svg aria-hidden="true"><use href="#icon-chart"></use></svg>
            <input type="search" placeholder="Rechercher…" disabled>
        </label>
        <button class="button button-primary" type="button" disabled
                title="Action illustrative, pas encore disponible">＋ Ajouter</button>
    </div>
    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Components'
        . DIRECTORY_SEPARATOR . 'data-table.php'; ?>
    <p class="below-table-note">Les filtres, ajouts et modifications ne sont pas activés dans ce squelette.</p>

<?php elseif ($page['view'] === 'analytics'): ?>
    <?php
    $metrics = [
        ['label' => 'Recettes estimées', 'value' => '1 275 000', 'note' => 'FCFA · exemple mensuel', 'icon' => '↗'],
        ['label' => 'Dépenses estimées', 'value' => '486 500', 'note' => 'FCFA · exemple mensuel', 'icon' => '−'],
        ['label' => 'Marge indicative', 'value' => '788 500', 'note' => 'FCFA · non comptable', 'icon' => '='],
    ];
    ?>
    <section class="metrics-grid metrics-grid-three" aria-label="Statistiques fictives">
        <?php foreach ($metrics as $metric): ?>
            <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Components'
                . DIRECTORY_SEPARATOR . 'stat-card.php'; ?>
        <?php endforeach; ?>
    </section>
    <article class="card analytics-card">
        <div class="card-heading">
            <div><h2>Recettes et dépenses</h2><p>Illustration — FCFA, montants fictifs</p></div>
            <span class="badge badge-info">Démonstration</span>
        </div>
        <div class="bar-chart" role="img" aria-label="Graphique en barres illustratif des recettes et dépenses">
            <div class="bar-group"><span class="bar-pair"><i style="--bar-height:42%"></i><i class="expense-bar" style="--bar-height:26%"></i></span><span>Mai</span></div>
            <div class="bar-group"><span class="bar-pair"><i style="--bar-height:61%"></i><i class="expense-bar" style="--bar-height:37%"></i></span><span>Juin</span></div>
            <div class="bar-group"><span class="bar-pair"><i style="--bar-height:53%"></i><i class="expense-bar" style="--bar-height:44%"></i></span><span>Juil.</span></div>
            <div class="bar-group"><span class="bar-pair"><i style="--bar-height:78%"></i><i class="expense-bar" style="--bar-height:49%"></i></span><span>Août</span></div>
            <div class="bar-group"><span class="bar-pair"><i style="--bar-height:68%"></i><i class="expense-bar" style="--bar-height:39%"></i></span><span>Sept.</span></div>
            <div class="bar-group"><span class="bar-pair"><i style="--bar-height:89%"></i><i class="expense-bar" style="--bar-height:54%"></i></span><span>Oct.</span></div>
        </div>
        <div class="chart-legend"><span><i></i>Recettes fictives</span><span class="legend-expense"><i></i>Dépenses fictives</span></div>
        <p class="calculation-note">Cette illustration ne constitue pas un calcul de bénéfice et ne représente aucune transaction enregistrée.</p>
    </article>

<?php elseif ($page['view'] === 'settings'): ?>
    <div class="settings-grid">
        <article class="card settings-card">
            <div class="card-heading">
                <div><h2>Informations du compte</h2><p>Valeurs fictives, champs désactivés</p></div>
            </div>
            <div class="settings-fields">
                <label class="field">Nom affiché<input type="text" value="Aminata K." disabled></label>
                <label class="field">E-mail<input type="email" value="aminata@example.test" disabled></label>
                <label class="field">Nom de l’exploitation<input type="text" value="Ferme du Soleil" disabled></label>
                <label class="field">Devise<select disabled><option>FCFA</option></select></label>
                <button class="button button-primary" type="button" disabled>Enregistrer les modifications</button>
            </div>
        </article>
        <article class="card settings-note">
            <span class="empty-icon" aria-hidden="true">⚙</span>
            <h2>Paramètres de démonstration</h2>
            <p>La gestion du compte, les notifications et les préférences seront activées après validation du squelette et développement des modules correspondants.</p>
        </article>
    </div>
<?php endif; ?>
