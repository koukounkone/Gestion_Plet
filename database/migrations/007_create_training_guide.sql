SET @training_admin_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'is_admin'
);
SET @training_admin_column_sql = IF(
    @training_admin_column_exists = 0,
    'ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
    'SELECT 1'
);
PREPARE training_admin_column_statement FROM @training_admin_column_sql;
EXECUTE training_admin_column_statement;
DEALLOCATE PREPARE training_admin_column_statement;

CREATE TABLE IF NOT EXISTS training_modules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(80) NOT NULL,
    title VARCHAR(160) NOT NULL,
    summary VARCHAR(1000) NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_modules_slug (slug),
    KEY idx_training_modules_status_order (status, position, id),
    CONSTRAINT fk_training_modules_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT fk_training_modules_editor FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_lessons (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    objectives TEXT NOT NULL,
    content MEDIUMTEXT NOT NULL,
    validated_advice TEXT NOT NULL,
    checklist TEXT NOT NULL,
    references_text TEXT NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_lessons_module_title (module_id, title),
    KEY idx_training_lessons_module_status (module_id, status, position, id),
    CONSTRAINT fk_training_lessons_module FOREIGN KEY (module_id)
        REFERENCES training_modules (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_training_lessons_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT fk_training_lessons_editor FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_progress (
    user_id BIGINT UNSIGNED NOT NULL,
    lesson_id BIGINT UNSIGNED NOT NULL,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, lesson_id),
    KEY idx_training_progress_lesson (lesson_id, completed_at),
    CONSTRAINT fk_training_progress_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_training_progress_lesson FOREIGN KEY (lesson_id)
        REFERENCES training_lessons (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO training_modules (slug, title, summary, position, status, published_at)
VALUES
('preparer-poulailler', 'Préparer le poulailler', 'Organiser le bâtiment, le matériel et les vérifications avant l’arrivée des animaux.', 10, 'published', UTC_TIMESTAMP()),
('alimentation', 'Alimentation', 'Lire les informations des aliments, organiser leur stockage et noter les consommations.', 20, 'published', UTC_TIMESTAMP()),
('eau', 'Eau et abreuvement', 'Surveiller la disponibilité, la propreté et le fonctionnement des points d’eau.', 30, 'published', UTC_TIMESTAMP()),
('biosecurite', 'Biosécurité', 'Réduire les risques d’introduction et de diffusion des agents infectieux par des pratiques organisées.', 40, 'published', UTC_TIMESTAMP()),
('croissance', 'Croissance et suivi', 'Consigner les effectifs et mesures réellement observés sans substituer des valeurs types aux données locales.', 50, 'published', UTC_TIMESTAMP()),
('prevention-sanitaire', 'Prévention sanitaire', 'Mettre en place un suivi d’observation et savoir quand solliciter un professionnel.', 60, 'published', UTC_TIMESTAMP()),
('mortalite', 'Mortalité et enregistrement', 'Documenter les mortalités, les anomalies et les démarches effectuées.', 70, 'published', UTC_TIMESTAMP()),
('budget', 'Budget et coûts', 'Préparer un budget prévisionnel, conserver des justificatifs et comparer prévisions et dépenses réelles.', 80, 'published', UTC_TIMESTAMP()),
('commercialisation', 'Commercialisation', 'Préparer les ventes en tenant compte des besoins des clients et des exigences locales.', 90, 'published', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Faire l’inventaire du bâtiment et du matériel',
'Identifier les équipements utiles; repérer les points à réparer; consigner les vérifications avant l’arrivée.',
'Commencez par décrire le bâtiment, ses accès et les équipements effectivement disponibles. Vérifiez leur état, leur stabilité et la possibilité de les nettoyer. Repérez les ouvertures, les zones humides et les éléments qui compliquent l’entretien. Préparez une liste des réparations et des responsables; ne déclarez pas le bâtiment prêt tant que les points critiques restent non résolus.',
'Appuyez-vous sur les exigences applicables dans votre localité et sur les instructions du fournisseur des équipements. Les besoins de ventilation, de densité et de température dépendent du bâtiment, du type d’élevage et des règles locales; faites-les confirmer par un technicien avicole compétent.',
'Décrire les accès et les zones; inventorier les équipements; noter les réparations; dater la vérification finale.',
'FAO – Poultry production and products: https://www.fao.org/poultry-production-products/en/',
10, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'preparer-poulailler'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Nettoyer et préparer les surfaces',
'Planifier le retrait des matières organiques; distinguer nettoyage et désinfection; garder une trace des produits utilisés.',
'Définissez une séquence de préparation adaptée au bâtiment: retrait des salissures, nettoyage selon l’étiquette du produit, rinçage si requis, séchage et vérification. La désinfection ne remplace pas un nettoyage préalable. Conservez les consignes de sécurité, les fiches des produits et l’identité de la personne intervenante.',
'Respectez strictement l’étiquette, les équipements de protection et les règles locales de stockage et d’élimination. Ne mélangez pas des produits et ne réutilisez pas le bâtiment avant le délai ou les conditions de sécurité indiqués par le fabricant.',
'Retirer les matières visibles; lire l’étiquette; noter le produit et la date; vérifier le séchage et les consignes de réentrée.',
'FAO – Poultry production and products: https://www.fao.org/poultry-production-products/en/',
20, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'preparer-poulailler'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Choisir et réceptionner un aliment',
'Lire l’étiquette et le lot; vérifier la livraison; relier les achats aux enregistrements de l’élevage.',
'À la réception, rapprochez le produit de la commande et conservez l’étiquette, le fabricant, le numéro de lot, la date et les quantités. Vérifiez l’intégrité des emballages et l’absence d’altération visible. Utilisez uniquement un aliment destiné aux animaux concernés et suivez les indications du fournisseur.',
'Les besoins nutritionnels varient selon l’espèce, l’âge, la souche, le système et l’objectif de production. Ne formulez pas une ration ou un dosage à partir de ce guide: demandez une recommandation écrite à un nutritionniste avicole ou à un professionnel qualifié.',
'Comparer commande et livraison; noter fabricant et lot; contrôler les emballages; archiver l’étiquette et le justificatif.',
'FAO – Poultry production and products: https://www.fao.org/poultry-production-products/en/',
10, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'alimentation'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Stocker et suivre les consommations',
'Organiser un stockage propre et sec; appliquer la rotation des lots; enregistrer les quantités distribuées.',
'Séparez les aliments des produits chimiques et protégez-les de l’humidité, des nuisibles et des contaminations. Identifiez les lots et utilisez une méthode de rotation cohérente avec les dates et instructions du fabricant. Notez les quantités entrées et distribuées pour comparer les achats, les stocks et les consommations observées.',
'La durée et les conditions de conservation sont celles indiquées par le fabricant et peuvent varier selon l’aliment et le climat. Isolez un lot suspect et contactez le fournisseur plutôt que de le distribuer.',
'Stockage sec et propre; lots identifiés; dates suivies; entrées et distributions enregistrées; anomalie signalée.',
'FAO – Poultry production and products: https://www.fao.org/poultry-production-products/en/',
20, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'alimentation'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Contrôler la disponibilité de l’eau',
'Vérifier régulièrement l’accès à l’eau; contrôler les équipements; noter les interruptions et interventions.',
'Parcourez les lignes et abreuvoirs selon une fréquence adaptée à l’installation. Vérifiez qu’ils sont accessibles et fonctionnels, recherchez les fuites et les obstructions, et consignez les anomalies et réparations. Une interruption d’approvisionnement doit être traitée rapidement et documentée.',
'Le débit, le nombre et le réglage des abreuvoirs dépendent de leur modèle et du type d’oiseaux. Suivez les instructions du fabricant; sollicitez un technicien si le réglage ou la qualité de l’eau est incertain.',
'Points d’eau parcourus; fuites ou obstructions notées; réparations datées; interruption remontée au responsable.',
'FAO – Poultry production and products: https://www.fao.org/poultry-production-products/en/',
10, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'eau'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Gérer l’hygiène et la qualité de l’eau',
'Documenter l’origine de l’eau; organiser le nettoyage des équipements; demander une analyse si nécessaire.',
'Décrivez la source d’eau et les étapes de distribution. Établissez un calendrier d’entretien du matériel en fonction de son fabricant et consignez les produits et opérations utilisés. Si l’aspect, l’odeur ou les résultats d’analyse sont préoccupants, suspendez toute conclusion improvisée et demandez un avis professionnel.',
'Une eau claire n’est pas nécessairement potable. Faites analyser l’eau par un laboratoire compétent selon les exigences locales et demandez au vétérinaire ou au service technique d’interpréter les résultats pertinents pour votre élevage.',
'Source documentée; entretien planifié; produit et rinçage consignés; résultat d’analyse et actions archivés.',
'FAO – Poultry production and products: https://www.fao.org/poultry-production-products/en/',
20, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'eau'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Organiser les accès et les visiteurs',
'Définir qui peut entrer; tenir un registre; limiter les déplacements et le partage de matériel.',
'Mettez par écrit les règles d’accès: responsable autorisant les entrées, registre des visiteurs, zones accessibles, matériel dédié et procédure de nettoyage à la sortie. Expliquez ces règles aux intervenants avant leur arrivée et conservez une trace des exceptions.',
'Les mesures de biosécurité doivent être adaptées aux risques locaux et aux obligations nationales. Demandez conseil au vétérinaire ou au service officiel; ne vous fiez pas à une durée universelle de vide sanitaire.',
'Accès identifié; entrées notées; matériel dédié ou nettoyé; règles présentées aux visiteurs.',
'WOAH – Code sanitaire pour les animaux terrestres: https://www.woah.org/en/what-we-do/standards/codes-and-manuals/terrestrial-code-online-access/',
10, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'biosecurite'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Nettoyer le matériel et éviter les contaminations croisées',
'Identifier le matériel partagé; appliquer une méthode de nettoyage documentée; séparer les zones propres et sales.',
'Repérez le matériel utilisé dans plusieurs bâtiments ou bandes. Lorsque possible, affectez-le à une zone; sinon, documentez son nettoyage entre usages. Stockez les équipements propres à l’abri et séparez les flux de déchets, de visiteurs et d’aliments.',
'Choisissez des produits et méthodes selon leur étiquette, les surfaces et les recommandations d’un professionnel. Une procédure affichée et tenue à jour est préférable à une consigne orale non vérifiable.',
'Matériel inventorié; nettoyage entre usages; produit et méthode écrits; zones propres et sales identifiées.',
'WOAH – Code sanitaire pour les animaux terrestres: https://www.woah.org/en/what-we-do/standards/codes-and-manuals/terrestrial-code-online-access/',
20, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'biosecurite'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Constituer des observations comparables',
'Consigner une date, un effectif et une méthode; distinguer mesures observées et estimations.',
'Choisissez des informations que vous pouvez réellement observer et répéter: effectifs, pesées échantillonnées, consommation ou remarques. Indiquez la date, les personnes, le matériel et la méthode utilisés. Une comparaison n’est utile que si les méthodes et les unités sont suffisamment constantes.',
'Les courbes et valeurs de référence varient selon la souche, le sexe, l’alimentation, l’environnement et le système. Ce guide ne fixe aucun objectif de poids ou de croissance; demandez au fournisseur ou à un professionnel qualifié les références adaptées.',
'Date et unité saisies; méthode documentée; données manquantes signalées; corrections conservées sans effacer l’original.',
'FAO – Poultry production and products: https://www.fao.org/poultry-production-products/en/',
10, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'croissance'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Analyser une tendance sans surinterpréter',
'Comparer des relevés cohérents; repérer les écarts; documenter les facteurs susceptibles de les expliquer.',
'Présentez les relevés sur une période et repérez les changements notables. Vérifiez d’abord la qualité de la saisie et l’évolution des méthodes. Notez les changements d’aliment, d’effectif, de matériel ou d’environnement; une corrélation observée ne permet pas à elle seule d’établir une cause.',
'En cas d’écart préoccupant, d’abattement ou de signe clinique, contactez rapidement un vétérinaire ou le service compétent. Le suivi de croissance est un outil de description et ne permet pas de poser un diagnostic.',
'Période et méthode comparables; saisies vérifiées; changement de contexte noté; doute transmis à un professionnel.',
'FAO – Poultry production and products: https://www.fao.org/poultry-production-products/en/',
20, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'croissance'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Mettre en place une observation sanitaire',
'Définir les personnes et horaires de contrôle; consigner des signes observés sans diagnostic; savoir qui alerter.',
'Organisez des passages réguliers et un cahier de suivi factuel: date, lot, observations, nombre d’oiseaux concernés et mesures prises. Décrivez ce qui est vu ou entendu plutôt que d’attribuer une maladie. Prévoyez les coordonnées du vétérinaire et du service vétérinaire officiel.',
'Ce support ne remplace pas une consultation et ne propose ni diagnostic, ni médicament, ni posologie. Toute détérioration, mortalité inhabituelle ou suspicion de maladie réglementée doit être signalée sans délai au vétérinaire ou à l’autorité compétente.',
'Contacts d’urgence disponibles; observation datée; description factuelle; anomalies transmises; suites consignées.',
'FAO – Santé animale: https://www.fao.org/animal-health/en/ ; WOAH: https://www.woah.org/',
10, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'prevention-sanitaire'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Préparer les échanges avec le vétérinaire',
'Rassembler les données utiles; décrire la chronologie; conserver les prescriptions et interventions.',
'Avant un échange, préparez l’identification de la bande, son âge connu, les effectifs, la chronologie des faits, les changements récents et les enregistrements disponibles. Après avis, consignez les instructions reçues, leur date, le professionnel et les suites demandées.',
'Vaccination, diagnostic, traitement, dosage et délais d’attente relèvent du vétérinaire et des règles locales. N’administrez pas de médicament sur la base d’un conseil générique ou d’une leçon de formation.',
'Bande identifiée; chronologie préparée; données disponibles réunies; prescription archivée; suivi planifié avec le professionnel.',
'FAO – Santé animale: https://www.fao.org/animal-health/en/ ; WOAH: https://www.woah.org/',
20, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'prevention-sanitaire'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Enregistrer une mortalité ou une anomalie',
'Noter les faits rapidement; rapprocher le relevé des effectifs; conserver les corrections et les suites données.',
'Notez la date et l’heure, la bande, le nombre d’oiseaux concernés, les observations factuelles et la personne qui a constaté l’événement. Comparez le chiffre au registre d’effectifs et expliquez toute correction. N’attribuez pas une cause sans diagnostic.',
'Une hausse inhabituelle, des signes groupés ou une suspicion de maladie doivent être signalés rapidement à un vétérinaire ou au service officiel. Les obligations de déclaration et les méthodes de gestion des cadavres dépendent du lieu et de la situation.',
'Événement daté; effectif mis à jour; faits décrits sans diagnostic; responsable averti; corrections traçables.',
'WOAH – Code sanitaire pour les animaux terrestres: https://www.woah.org/en/what-we-do/standards/codes-and-manuals/terrestrial-code-online-access/ ; FAO – Santé animale: https://www.fao.org/animal-health/en/',
10, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'mortalite'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Organiser la traçabilité et la gestion des cadavres',
'Distinguer enregistrement et interprétation; connaître les consignes locales de collecte, de stockage et d’élimination.',
'Identifiez à l’avance les contacts et le circuit autorisé pour les cadavres. Formez les personnes responsables aux consignes écrites et aux règles d’hygiène de l’exploitation. Tenez un registre daté des quantités, de l’emplacement temporaire si autorisé, de la collecte et du prestataire.',
'Les méthodes autorisées varient selon les pays, les maladies suspectées et l’environnement. N’improvisez pas l’enfouissement, le brûlage ou le transport; demandez les instructions de l’autorité vétérinaire locale.',
'Prestataire ou service identifié; consignes affichées; collecte tracée; équipement nettoyé selon procédure; signalement fait si requis.',
'FAO – Santé animale: https://www.fao.org/animal-health/en/ ; WOAH: https://www.woah.org/',
20, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'mortalite'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Construire un budget prévisionnel',
'Lister les postes et hypothèses; distinguer prévision, engagement et dépense réelle; documenter les devis.',
'Établissez une période et des hypothèses explicites. Listez les quantités prévues et prix unitaires disponibles, les coûts de démarrage, les besoins d’exploitation et une marge de trésorerie adaptée à votre situation. Séparez les sommes prévues des achats engagés et des dépenses effectivement payées.',
'Les prix, taxes, besoins de trésorerie et obligations comptables varient localement. Utilisez des devis datés et consultez un comptable ou conseiller local pour les obligations fiscales et le financement.',
'Période définie; hypothèses documentées; devis datés; imprévus envisagés; prévisions séparées des dépenses réelles.',
'FAO – Poultry production and products: https://www.fao.org/poultry-production-products/en/',
10, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'budget'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Suivre les coûts et comparer les résultats',
'Conserver les justificatifs; classer les dépenses; expliquer les écarts sans confondre chiffre d’affaires et bénéfice.',
'Enregistrez chaque opération avec sa date, son montant, sa devise, sa catégorie, son fournisseur et son justificatif. Comparez périodiquement le réalisé au budget et expliquez les écarts. Pour calculer un résultat, définissez les postes inclus, la période, les stocks restants et les charges partagées.',
'Un calcul simple revenus moins dépenses n’est pas nécessairement un bénéfice comptable ou fiscal. Pour déclarations, amortissements, taxes et décisions de financement, demandez conseil à un professionnel local.',
'Justificatif conservé; devise précisée; dépense enregistrée une seule fois; écarts expliqués; période et méthode de calcul explicites.',
'FAO – Poultry production and products: https://www.fao.org/poultry-production-products/en/',
20, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'budget'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Identifier les clients et les exigences de vente',
'Décrire le produit vendu; convenir des quantités, dates, prix et modalités; vérifier les règles locales.',
'Recueillez les besoins des acheteurs: type de produit, présentation, quantité, calendrier, lieu de remise et modalités de paiement. Confirmez les engagements par écrit. Vérifiez les conditions de transport, d’abattage, de transformation, d’étiquetage et de vente applicables avant de proposer un produit.',
'Les exigences sanitaires et commerciales dépendent du produit et de la juridiction. Consultez les services officiels compétents avant la vente; ne présumez pas qu’une vente directe dispense des obligations.',
'Acheteur identifié; commande confirmée; prix et quantité consignés; exigences sanitaires et de transport vérifiées.',
'FAO – Poultry production and products: https://www.fao.org/poultry-production-products/en/ ; Codex Alimentarius: https://www.fao.org/fao-who-codexalimentarius/en/',
10, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'commercialisation'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO training_lessons
(module_id, title, objectives, content, validated_advice, checklist, references_text, position, status, published_at)
SELECT id, 'Préparer la livraison et clôturer une vente',
'Vérifier la commande; documenter quantité et remise; rapprocher facture, paiement et stock ou effectif.',
'Avant la remise, rapprochez le produit et les quantités de la commande confirmée. Enregistrez la date, le client, le document de vente et le paiement reçu ou restant dû. Les mouvements de stock ou d’animaux doivent correspondre aux quantités effectivement transférées.',
'Respectez les règles officielles de transport, de chaîne du froid, d’abattage et de remise applicables au produit. En cas de doute, ne livrez pas avant d’avoir obtenu une clarification du service compétent.',
'Commande rapprochée; produit et quantité vérifiés; documents remis; paiement enregistré; stock ou effectif mis à jour.',
'Codex Alimentarius: https://www.fao.org/fao-who-codexalimentarius/en/ ; FAO – Poultry production and products: https://www.fao.org/poultry-production-products/en/',
20, 'published', UTC_TIMESTAMP()
FROM training_modules WHERE slug = 'commercialisation'
ON DUPLICATE KEY UPDATE title = VALUES(title);
