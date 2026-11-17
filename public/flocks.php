<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\FarmAccess;
use MonElevage\Database\Connection;
use MonElevage\Flocks\FlockService;
use MonElevage\Flocks\FlockStageService;
use MonElevage\Support\AuthSession;

AuthSession::start();
$pdo = Connection::get($config);
$userId = AuthSession::userId($pdo);
if ($userId === null) {
    header('Location: auth.php?view=login', true, 303);
    exit;
}

$memberships = FarmAccess::memberships($pdo, $userId);
$hasFarmIdParameter = array_key_exists('farm_id', $_GET);
$selectedFarmId = filter_var($_GET['farm_id'] ?? null, FILTER_VALIDATE_INT);
$selectedFarm = null;
if ($hasFarmIdParameter && ($selectedFarmId === false || $selectedFarmId === null)) {
    http_response_code(404);
    exit('Exploitation introuvable.');
}
foreach ($memberships as $membership) {
    if (($selectedFarmId === false || $selectedFarmId === null)
        ? $selectedFarm === null
        : (int) $membership['id'] === $selectedFarmId) {
        $selectedFarm = $membership;
        if ($selectedFarmId === false || $selectedFarmId === null) {
            break;
        }
    }
}
if ($selectedFarm === null) {
    http_response_code(404);
    exit('Exploitation introuvable.');
}

$farmId = (int) $selectedFarm['id'];
$farmRole = FarmAccess::role($pdo, $userId, $farmId);
$canViewFlocks = FarmAccess::allows($pdo, $userId, $farmId, 'flock.view');
if (!$canViewFlocks) {
    http_response_code(403);
    exit('Accès aux élevages refusé.');
}
$canManageFlocks = FarmAccess::allows($pdo, $userId, $farmId, 'flock.manage');
$canViewFinancials = FarmAccess::allows($pdo, $userId, $farmId, 'expense.view');
$view = $_GET['view'] ?? 'list';
if (!is_string($view) || !in_array($view, ['list', 'new', 'show', 'edit', 'calendar'], true)) {
    http_response_code(404);
    exit('Page introuvable.');
}
$flockId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (in_array($view, ['show', 'edit', 'calendar'], true)
    && (!is_int($flockId) || $flockId < 1)) {
    http_response_code(404);
    exit('Élevage introuvable.');
}
if (in_array($view, ['new', 'edit'], true) && !$canManageFlocks) {
    http_response_code(403);
    exit('Vous ne disposez pas des droits pour gérer les élevages.');
}

$errors = [];
$flash = AuthSession::takeFlash();
$flock = null;
$flocks = [];
$stages = [];
$formValues = [];
if (in_array($view, ['show', 'edit', 'calendar'], true)) {
    try {
        $flock = FlockService::find($pdo, $userId, $farmId, (int) $flockId);
    } catch (RuntimeException $exception) {
        http_response_code(404);
        exit('Élevage introuvable.');
    }
    if ($view === 'edit' && $flock['status'] === 'closed') {
        http_response_code(409);
        exit('Un élevage archivé ne peut plus être modifié.');
    }
    $formValues = $flock;
} elseif ($view === 'new') {
    $formValues = [
        'name' => '',
        'code' => '',
        'flock_type' => 'broiler',
        'started_on' => (new DateTimeImmutable(
            'today',
            new DateTimeZone((string) $selectedFarm['timezone'])
        ))->format('Y-m-d'),
        'initial_count' => '',
        'supplier_name' => '',
        'initial_cost' => '',
        'budget_amount' => '',
        'location' => '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errors[] = 'Le formulaire a expiré ou ne peut pas être vérifié. Rechargez la page.';
    } else {
        $action = $_POST['action'] ?? null;
        try {
            if (is_string($action) && str_starts_with($action, 'stage_')) {
                if (!is_int($flockId) || $flockId < 1) {
                    throw new RuntimeException('Élevage introuvable.');
                }
                $stageId = filter_var($_POST['stage_id'] ?? null, FILTER_VALIDATE_INT);
                if ($action !== 'stage_create' && (!is_int($stageId) || $stageId < 1)) {
                    throw new RuntimeException('Étape introuvable.');
                }
                if ($action === 'stage_create') {
                    FlockStageService::create($pdo, $userId, $farmId, $flockId, $_POST);
                } elseif ($action === 'stage_update') {
                    FlockStageService::update($pdo, $userId, $farmId, $stageId, $_POST);
                } elseif ($action === 'stage_start' || $action === 'stage_complete' || $action === 'stage_reopen') {
                    FlockStageService::changeStatus(
                        $pdo,
                        $userId,
                        $farmId,
                        $stageId,
                        substr($action, strlen('stage_')),
                        new DateTimeImmutable('today', new DateTimeZone((string) $selectedFarm['timezone'])),
                        is_string($_POST['completed_on'] ?? null) ? $_POST['completed_on'] : null
                    );
                } elseif ($action === 'stage_reschedule') {
                    FlockStageService::reschedule(
                        $pdo,
                        $userId,
                        $farmId,
                        $stageId,
                        is_string($_POST['planned_on'] ?? null) ? $_POST['planned_on'] : '',
                        is_string($_POST['reason'] ?? null) ? $_POST['reason'] : ''
                    );
                } elseif ($action === 'stage_observation') {
                    FlockStageService::addObservation(
                        $pdo,
                        $userId,
                        $farmId,
                        $stageId,
                        is_string($_POST['observation'] ?? null) ? $_POST['observation'] : ''
                    );
                } else {
                    throw new RuntimeException('Action de calendrier non reconnue.');
                }
                AuthSession::flash('success', 'Le calendrier a été mis à jour.');
                header(
                    'Location: flocks.php?farm_id=' . $farmId . '&view=calendar&id=' . $flockId,
                    true,
                    303
                );
                exit;
            }
            if (!$canManageFlocks) {
                throw new RuntimeException('Vous ne disposez pas des droits pour gérer les élevages.');
            }
            if ($action === 'create') {
                $formValues = $_POST;
                $newFlockId = FlockService::create($pdo, $userId, $farmId, $_POST);
                AuthSession::flash('success', 'L’élevage a été créé et ajouté au tableau de bord.');
                header(
                    'Location: flocks.php?farm_id=' . $farmId . '&view=show&id=' . $newFlockId,
                    true,
                    303
                );
                exit;
            }
            if ($action === 'update') {
                if (!is_int($flockId)) {
                    throw new RuntimeException('Élevage introuvable.');
                }
                $formValues = $_POST;
                FlockService::update($pdo, $userId, $farmId, $flockId, $_POST);
                AuthSession::flash('success', 'Les informations de l’élevage ont été mises à jour.');
                header(
                    'Location: flocks.php?farm_id=' . $farmId . '&view=show&id=' . $flockId,
                    true,
                    303
                );
                exit;
            }
            if ($action === 'delete') {
                if (!is_int($flockId) || $flockId < 1) {
                    throw new RuntimeException('Élevage introuvable.');
                }
                FlockService::deleteIfEmpty($pdo, $userId, $farmId, $flockId);
                AuthSession::flash('success', 'L’élevage vide a été supprimé.');
                header('Location: flocks.php?farm_id=' . $farmId, true, 303);
                exit;
            }
            if ($action === 'pause' || $action === 'resume' || $action === 'close') {
                $postedFlockId = filter_var($_POST['flock_id'] ?? null, FILTER_VALIDATE_INT);
                if (!is_int($postedFlockId) || $postedFlockId < 1) {
                    throw new RuntimeException('Élevage introuvable.');
                }
                FlockService::changeStatus(
                    $pdo,
                    $userId,
                    $farmId,
                    $postedFlockId,
                    $action,
                    is_string($_POST['reason'] ?? null) ? $_POST['reason'] : null
                );
                $message = match ($action) {
                    'pause' => 'L’élevage a été suspendu.',
                    'resume' => 'L’élevage a repris et réapparaît dans les prévisions.',
                    default => 'L’élevage a été clôturé et archivé. Son historique est conservé.',
                };
                AuthSession::flash('success', $message);
                header(
                    'Location: flocks.php?farm_id=' . $farmId . '&view=show&id=' . $postedFlockId,
                    true,
                    303
                );
                exit;
            }
            http_response_code(400);
            $errors[] = 'Action non reconnue.';
        } catch (RuntimeException $exception) {
            http_response_code(422);
            $errors[] = $exception->getMessage();
        }
    }
}

if (in_array($view, ['show', 'edit', 'calendar'], true)
    && !($view === 'edit' && $errors !== [] && $flock === null)) {
    $flock = FlockService::find($pdo, $userId, $farmId, (int) $flockId);
    if ($view === 'edit' && $errors !== []) {
        $formValues = array_merge($flock, $formValues);
    } elseif ($view === 'edit') {
        $formValues = $flock;
    }
}
if ($view === 'list') {
    $flocks = FlockService::listForFarm($pdo, $userId, $farmId);
}
if ($view === 'calendar' && is_int($flockId)) {
    $stages = FlockStageService::listForFarm(
        $pdo,
        $userId,
        $farmId,
        new DateTimeImmutable('today', new DateTimeZone((string) $selectedFarm['timezone'])),
        $flockId
    );
}

$userStatement = $pdo->prepare(
    "SELECT full_name FROM users WHERE id = :user_id AND status = 'active' LIMIT 1"
);
$userStatement->execute(['user_id' => $userId]);
$currentUserName = (string) $userStatement->fetchColumn();
$nameParts = preg_split('/\s+/u', trim($currentUserName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$userInitials = mb_strtoupper(
    mb_substr($nameParts[0] ?? 'U', 0, 1, 'UTF-8')
        . mb_substr($nameParts[1] ?? '', 0, 1, 'UTF-8'),
    'UTF-8'
);
$csrfToken = htmlspecialchars(AuthSession::csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$currentFarmName = (string) $selectedFarm['name'];
$currentFarmId = $farmId;
$currentFarmRole = $farmRole;
$farmSwitchAction = 'flocks.php';
$isLiveDashboard = true;
$isPrivateApp = true;
$pageKey = 'flocks';
$viewTitles = [
    'list' => 'Élevages',
    'new' => 'Créer un élevage',
    'show' => is_array($flock) ? (string) $flock['name'] : 'Détail de l’élevage',
    'edit' => 'Modifier un élevage',
    'calendar' => is_array($flock) ? 'Calendrier — ' . (string) $flock['name'] : 'Calendrier',
];
$page = [
    'title' => $viewTitles[$view],
    'section' => 'GESTION DES ÉLEVAGES',
    'description' => 'Consultez et gérez les bandes de cette exploitation.',
];
$topbarDate = (new DateTimeImmutable(
    'now',
    new DateTimeZone((string) $selectedFarm['timezone'])
))->format('d/m/Y');
$liveViewPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
    . DIRECTORY_SEPARATOR . 'flocks-content.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Layouts'
    . DIRECTORY_SEPARATOR . 'app.php';
