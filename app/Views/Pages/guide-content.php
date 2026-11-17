<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$farmQuery = $currentFarmId === null ? '' : '?farm_id=' . $currentFarmId;
$moduleLink = static function (string $label, string $path, bool $allowed) use (
    $currentFarmId,
    $escape,
    $farmQuery
): string {
    if (!$allowed || $currentFarmId === null) {
        return $escape($label);
    }

    return '<a href="' . $escape($path . $farmQuery) . '">' . $escape($label) . '</a>';
};
?>
<section class="guide-hero card" aria-labelledby="guide-intro-title">
    <div class="guide-hero-copy">
        <p class="guide-kicker">Un parcours simple, étape par étape</p>
        <h2 id="guide-intro-title">Gardez les informations de votre élevage au même endroit.</h2>
        <p>
            Choisissez une rubrique ci-dessous pour apprendre où saisir vos données,
            comment les relire et quelles limites garder en tête.
        </p>
        <?php if ($currentFarmId !== null): ?>
            <a class="button button-primary" href="./?farm_id=<?= $currentFarmId ?>">Ouvrir mon tableau de bord</a>
        <?php else: ?>
            <a class="button button-primary" href="profile.php">Consulter mon profil</a>
        <?php endif; ?>
    </div>
    <figure class="guide-hero-figure">
        <img src="assets/images/farm-workflow.svg"
         data-fallback-src="assets/images/farm-workflow-fallback.svg"
         alt="Illustration d’une exploitation avicole : poulailler, poulet, aliments et œufs réunis autour d’un cycle de suivi.">
        <figcaption>Un schéma original pour illustrer le suivi de l’élevage et de ses opérations.</figcaption>
    </figure>
</section>

<nav class="guide-toc card" aria-label="Sommaire du guide">
    <h2>Dans ce guide</h2>
    <ol>
        <li><a href="#guide-start">Avant de commencer</a></li>
        <li><a href="#guide-flocks">Créer et suivre un élevage</a></li>
        <li><a href="#guide-daily">Suivi quotidien, croissance et ponte</a></li>
        <li><a href="#guide-stock">Produits, achats et stocks</a></li>
        <li><a href="#guide-finance">Budgets et dépenses</a></li>
        <li><a href="#guide-health">Santé et alertes</a></li>
        <li><a href="#guide-sales">Clients et ventes</a></li>
        <li><a href="#guide-results">Rentabilité et rapports PDF</a></li>
        <li><a href="#guide-help">Sécurité et aide</a></li>
    </ol>
</nav>

<section class="guide-section" id="guide-start" aria-labelledby="guide-start-title">
    <div class="guide-section-heading">
        <span class="guide-step" aria-hidden="true">1</span>
        <div>
            <p class="guide-kicker">Votre espace</p>
            <h2 id="guide-start-title">Avant de commencer</h2>
        </div>
    </div>
    <div class="guide-card-grid">
        <article class="card guide-card">
            <h3>Choisissez la bonne exploitation</h3>
            <p>Les données sont rattachées à l’exploitation affichée. Vérifiez son nom dans le menu avant d’ajouter ou de consulter une information.</p>
        </article>
        <article class="card guide-card">
            <h3>Les droits dépendent de votre rôle</h3>
            <p>Certains boutons ou rubriques peuvent être absents si votre rôle ne permet pas l’action. Demandez l’accès au propriétaire de l’exploitation.</p>
        </article>
        <article class="card guide-card">
            <h3>Enregistrez des faits vérifiables</h3>
            <p>Utilisez les dates, quantités et observations réellement constatées. Une donnée absente n’est pas nécessairement égale à zéro.</p>
        </article>
    </div>
</section>

<section class="guide-section" id="guide-flocks" aria-labelledby="guide-flocks-title">
    <div class="guide-section-heading">
        <span class="guide-step" aria-hidden="true">2</span>
        <div>
            <p class="guide-kicker">Démarrer un cycle</p>
            <h2 id="guide-flocks-title">Créer et suivre un élevage</h2>
        </div>
    </div>
    <article class="card guide-card guide-card-wide">
        <ol class="guide-steps">
            <li>Ouvrez <?= $moduleLink('Élevages', 'flocks.php', $canViewFlocks) ?>, puis choisissez <strong>Créer un élevage</strong> si votre rôle le permet.</li>
            <li>Renseignez le nom, les types de volaille et de production, la date de démarrage et l’effectif initial demandé par le formulaire.</li>
            <li>Relisez la fiche créée. Les actions de modification, pause, reprise ou archivage proposées dépendent de son état et de vos permissions.</li>
            <li>La suppression définitive demande une confirmation et n’est possible que si aucune opération ni aucun historique n’est lié. Sinon, les données restent conservées : clôturez et archivez l’élevage.</li>
            <li>Utilisez le tableau de bord pour retrouver l’effectif courant calculé depuis les mouvements enregistrés.</li>
        </ol>
        <h3>Calendrier et progression</h3>
        <p>Les prochaines étapes enregistrées et leur état apparaissent sur le tableau de bord. Les dates de cycle ou de maturité sont des repères calculés à partir des paramètres disponibles; elles ne remplacent pas une observation réelle.</p>
        <p class="guide-note">
            Le coût initial enregistré sur la fiche n’est pas automatiquement transformé en dépense.
            Les durées de cycle et dates de vente estimées sont des repères configurables, pas des mesures de croissance ni de progression réelle.
        </p>
    </article>
</section>

<section class="guide-section" id="guide-daily" aria-labelledby="guide-daily-title">
    <div class="guide-section-heading">
        <span class="guide-step" aria-hidden="true">3</span>
        <div>
            <p class="guide-kicker">Tenir les registres</p>
            <h2 id="guide-daily-title">Suivi quotidien, croissance et ponte</h2>
        </div>
    </div>
    <div class="guide-card-grid">
        <article class="card guide-card">
            <h3>Suivi quotidien</h3>
            <p>Dans <?= $moduleLink('Suivi quotidien', 'followups.php', $canViewFlocks) ?>, sélectionnez l’élevage et la date, puis consignez les effectifs observés, les mortalités, les animaux malades, l’alimentation et vos observations.</p>
            <p>Les ventes déjà saisies dans le module commercial sont intégrées aux mouvements : ne les inscrivez pas une deuxième fois comme sorties.</p>
        </article>
        <article class="card guide-card">
            <h3>Croissance et ponte</h3>
            <p>Dans <?= $moduleLink('Croissance et ponte', 'performance.php', $canViewFlocks) ?>, ajoutez les pesées ou les relevés d’œufs qui concernent votre élevage. Les graphiques représentent les relevés saisis; ils ne reconstituent pas les jours manquants.</p>
        </article>
    </div>
</section>

<section class="guide-section" id="guide-stock" aria-labelledby="guide-stock-title">
    <div class="guide-section-heading">
        <span class="guide-step" aria-hidden="true">4</span>
        <div>
            <p class="guide-kicker">Suivre les produits</p>
            <h2 id="guide-stock-title">Produits, achats et stocks</h2>
        </div>
    </div>
    <article class="card guide-card guide-stock-card">
        <div>
            <ol class="guide-steps">
                <li>Dans <?= $moduleLink('Produits et stocks', 'stock.php', $canViewStock) ?>, créez le produit, sa catégorie, son unité et son seuil d’alerte si votre rôle le permet.</li>
                <li>Précisez s’il s’agit d’un <strong>consommable</strong> ou d’un <strong>équipement réutilisable</strong>, puis ajoutez le fournisseur si nécessaire.</li>
                <li>Enregistrez les achats et leurs quantités. Les réceptions et sorties alimentent l’historique des mouvements.</li>
                <li>Consignez les consommations, pertes, retours ou ajustements d’inventaire avec le motif demandé. Associez la sortie à un élevage lorsque le formulaire le propose.</li>
                <li>Consultez les alertes de stock faible et les lots proches de leur date de péremption.</li>
            </ol>
            <p class="guide-note">Les sorties qui rendraient le stock négatif sont refusées, sauf opération autorisée avec une justification tracée.</p>
        </div>
        <figure class="guide-stock-figure">
            <img src="assets/images/stock-guide.svg"
                 data-fallback-src="assets/images/stock-guide-fallback.svg"
                 alt="Un sac d’aliment consommable rappelle le suivi de la quantité, du lot et de la péremption; un abreuvoir réutilisable rappelle l’entretien et le retour.">
            <figcaption>Consommables et matériel réutilisable ne se comptent pas de la même façon.</figcaption>
        </figure>
    </article>
</section>

<section class="guide-section" id="guide-finance" aria-labelledby="guide-finance-title">
    <div class="guide-section-heading">
        <span class="guide-step" aria-hidden="true">5</span>
        <div>
            <p class="guide-kicker">Garder une trace des coûts</p>
            <h2 id="guide-finance-title">Budgets et dépenses</h2>
        </div>
    </div>
    <article class="card guide-card">
        <p>Dans <?= $moduleLink('Finances et budgets', 'finance.php', $canViewFinancials) ?>, créez d’abord un budget prévisionnel si nécessaire, puis enregistrez les dépenses avec leur catégorie, date, montant, élevage et justificatif éventuel.</p>
        <ul class="guide-list">
            <li>La trésorerie décrit les paiements réellement enregistrés.</li>
            <li>Les charges consommées décrivent les produits utilisés.</li>
            <li>Les investissements réutilisables concernent le matériel durable.</li>
        </ul>
        <p>Ces vues sont séparées pour éviter de compter un achat et sa consommation comme deux charges identiques. Les montants sont présentés en FCFA, selon les données saisies.</p>
    </article>
</section>

<section class="guide-section" id="guide-health" aria-labelledby="guide-health-title">
    <div class="guide-section-heading">
        <span class="guide-step" aria-hidden="true">6</span>
        <div>
            <p class="guide-kicker">Prévention et vigilance</p>
            <h2 id="guide-health-title">Santé et alertes</h2>
        </div>
    </div>
    <article class="card guide-card">
        <p>Dans <?= $moduleLink('Santé et alertes', 'health-monitoring.php', $canViewHealth) ?>, consignez les signes observés, le nombre d’animaux concernés, les vaccinations, interventions vétérinaires et traitements prescrits par un professionnel.</p>
        <p>Les prévisions météo disponibles décrivent les conditions extérieures et ne sont pas des mesures du poulailler. La météo peut être indisponible ou ancienne; consultez l’horodatage affiché.</p>
        <p class="guide-warning"><strong>Important :</strong> le site ne diagnostique pas les maladies et ne prescrit pas de médicaments. Pour une situation préoccupante, contactez un vétérinaire.</p>
    </article>
</section>

<section class="guide-section" id="guide-sales" aria-labelledby="guide-sales-title">
    <div class="guide-section-heading">
        <span class="guide-step" aria-hidden="true">7</span>
        <div>
            <p class="guide-kicker">Préparer la vente</p>
            <h2 id="guide-sales-title">Clients et ventes</h2>
        </div>
    </div>
    <article class="card guide-card">
        <ol class="guide-steps">
            <li>Dans <?= $moduleLink('Commandes et ventes', 'sales.php', $canViewSales) ?>, ajoutez le client si nécessaire.</li>
            <li>Choisissez le produit ou l’élevage vendu, puis vérifiez la quantité, le prix unitaire et la date.</li>
            <li>Enregistrez le montant reçu et le statut de livraison. Le solde restant distingue une vente partiellement payée ou impayée d’une recette encaissée.</li>
            <li>Le système vérifie la disponibilité avant de confirmer la vente. Les annulations créent des mouvements de correction traçables.</li>
        </ol>
        <p>Un reçu est disponible depuis l’historique selon les droits de votre compte. Vérifiez les détails avant de remettre ou d’imprimer un reçu.</p>
    </article>
</section>

<section class="guide-section" id="guide-results" aria-labelledby="guide-results-title">
    <div class="guide-section-heading">
        <span class="guide-step" aria-hidden="true">8</span>
        <div>
            <p class="guide-kicker">Faire le point</p>
            <h2 id="guide-results-title">Rentabilité et rapports PDF</h2>
        </div>
    </div>
    <div class="guide-card-grid">
        <article class="card guide-card">
            <h3>Rentabilité</h3>
            <p>La page <?= $moduleLink('Finances et budgets', 'finance.php', $canViewFinancials) ?> présente recettes, encaissements, créances et coûts économiques, ainsi que des comparaisons de période. Les résultats restent estimatifs et dépendent de données complètes.</p>
            <p>Flux de trésorerie et résultat économique sont deux lectures différentes. Le stock restant et le matériel réutilisable sont traités séparément des charges consommées.</p>
        </article>
        <article class="card guide-card">
            <h3>Rapports</h3>
            <p>Dans <?= $moduleLink('Rapports PDF', 'reports.php', $canViewReports) ?>, choisissez un élevage et une période, ajoutez si utile une observation, puis générez le PDF. L’historique permet de retrouver une version autorisée.</p>
            <p>Les rapports sont privés et reprennent les données enregistrées. Une information absente ne prouve pas qu’aucun événement n’a eu lieu.</p>
        </article>
    </div>
</section>

<section class="guide-section" id="guide-help" aria-labelledby="guide-help-title">
    <div class="guide-section-heading">
        <span class="guide-step" aria-hidden="true">9</span>
        <div>
            <p class="guide-kicker">Protéger votre compte</p>
            <h2 id="guide-help-title">Sécurité et aide</h2>
        </div>
    </div>
    <article class="card guide-card guide-help-card">
        <ul class="guide-list">
            <li>Déconnectez-vous sur un appareil partagé et ne communiquez jamais votre mot de passe ni un code de confirmation.</li>
            <li>Avant un enregistrement, vérifiez l’exploitation, l’élevage, la date et les unités.</li>
            <li>Utilisez les actions prévues pour modifier ou annuler une opération; les historiques servent à garder la trace des corrections.</li>
            <li>Pour demander de l’aide, indiquez la page et l’action réalisée, sans transmettre de mot de passe, code temporaire ou donnée sensible.</li>
        </ul>
        <a class="button button-secondary" href="training.php">Voir le guide de formation</a>
    </article>
</section>
