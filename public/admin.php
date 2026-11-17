<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Admin\AdminService;
use MonElevage\Admin\AccountDeletionService;
use MonElevage\Database\Connection;
use MonElevage\Reports\AccountArchivePdfRenderer;
use MonElevage\Support\AuthSession;

AuthSession::start();
$pdo = Connection::get($config);
$userId = AuthSession::userId($pdo);
if ($userId === null) {
    header('Location: auth.php?view=login', true, 303);
    exit;
}
try {
    AdminService::requireAdministrator($pdo, $userId);
} catch (RuntimeException) {
    http_response_code(403);
    exit('Accès réservé aux administrateurs.');
}

$errors = [];
$flash = AuthSession::takeFlash();
$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'overview';
if (!in_array($view, ['overview', 'user-permissions'], true)) {
    http_response_code(404);
    exit('Page d’administration introuvable.');
}
if (array_key_exists('download_user_archive', $_GET)) {
    $archiveUserId = filter_var($_GET['download_user_archive'], FILTER_VALIDATE_INT);
    if (!is_int($archiveUserId) || $archiveUserId < 1) {
        http_response_code(404);
        exit('Archive introuvable.');
    }
    try {
        $archiveData = AccountDeletionService::archiveData($pdo, $userId, $archiveUserId);
    } catch (RuntimeException $exception) {
        http_response_code(404);
        exit($exception->getMessage());
    }
    $pdf = AccountArchivePdfRenderer::render($archiveData);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header('Content-Disposition: attachment; filename="archive-compte-'
        . $archiveUserId . '.pdf"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errors[] = 'Le formulaire a expiré ou ne peut pas être vérifié. Rechargez la page.';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $deleteResult = null;
        try {
            switch ($action) {
                case 'user_save':
                    AdminService::updateUser(
                        $pdo,
                        $userId,
                        adminPositiveId($_POST['user_id'] ?? null),
                        $_POST
                    );
                    break;
                case 'user_delete':
                    $deleteResult = AccountDeletionService::deleteUser(
                        $pdo,
                        $userId,
                        adminPositiveId($_POST['user_id'] ?? null)
                    );
                    break;
                case 'membership_save':
                    AdminService::updateMembership(
                        $pdo,
                        $userId,
                        adminPositiveId($_POST['farm_id'] ?? null),
                        adminPositiveId($_POST['user_id'] ?? null),
                        $_POST
                    );
                    break;
                case 'permissions_save':
                    $role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
                    $permissions = is_array($_POST['permissions'] ?? null)
                        ? $_POST['permissions']
                        : [];
                    AdminService::saveRolePermissions($pdo, $userId, $role, $permissions);
                    break;
                case 'member_permissions_save':
                    AdminService::saveMemberPermissions(
                        $pdo,
                        $userId,
                        adminPositiveId($_POST['farm_id'] ?? null),
                        adminPositiveId($_POST['user_id'] ?? null),
                        is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : []
                    );
                    break;
                case 'reference_save':
                    $itemId = adminOptionalPositiveId($_POST['item_id'] ?? null);
                    AdminService::saveReference($pdo, $userId, $_POST, $itemId);
                    break;
                case 'calendar_save':
                    AdminService::saveCalendarDefault($pdo, $userId, $_POST);
                    break;
                case 'setting_save':
                    $key = is_string($_POST['setting_key'] ?? null) ? $_POST['setting_key'] : '';
                    $value = is_string($_POST['setting_value'] ?? null) ? $_POST['setting_value'] : '';
                    AdminService::saveSetting($pdo, $userId, $key, $value);
                    break;
                case 'threshold_save':
                    $key = is_string($_POST['threshold_key'] ?? null) ? $_POST['threshold_key'] : '';
                    AdminService::saveThreshold($pdo, $userId, $key, $_POST['threshold_value'] ?? null);
                    break;
                case 'service_save':
                    $enabled = ($_POST['is_enabled'] ?? '') === '1';
                    $endpoint = is_string($_POST['endpoint_url'] ?? null)
                        ? $_POST['endpoint_url']
                        : '';
                    AdminService::saveExternalService($pdo, $userId, 'weather', $enabled, $endpoint);
                    break;
                default:
                    throw new RuntimeException('Action d’administration inconnue.');
            }
            if ($action === 'user_delete' && is_array($deleteResult)) {
                $message = 'Compte supprimé avec succès. '
                    . 'L’exploitation et toutes ses données ont également été supprimées définitivement.';
                if ($deleteResult['file_cleanup_failed']) {
                    $message .= ' Certains fichiers privés n’ont pas pu être supprimés ; consultez le journal serveur.';
                }
                AuthSession::flash('success', $message);
            } else {
                AuthSession::flash('success', 'La modification a été enregistrée et journalisée.');
            }
            if ($action === 'member_permissions_save') {
                header(
                    'Location: admin.php?view=user-permissions&user_id='
                        . adminPositiveId($_POST['user_id'] ?? null)
                        . '&farm_id=' . adminPositiveId($_POST['farm_id'] ?? null)
                        . '#permissions',
                    true,
                    303
                );
                exit;
            }
            header('Location: admin.php#' . adminAnchor($action), true, 303);
            exit;
        } catch (RuntimeException $exception) {
            http_response_code(422);
            $errors[] = $exception->getMessage();
        }
    }
}

$memberPermissionDetail = null;
if ($view === 'user-permissions') {
    $targetUserId = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT);
    $targetFarmId = isset($_GET['farm_id'])
        ? filter_var($_GET['farm_id'], FILTER_VALIDATE_INT)
        : null;
    if (!is_int($targetUserId) || $targetUserId < 1
        || (isset($_GET['farm_id']) && (!is_int($targetFarmId) || $targetFarmId < 1))) {
        http_response_code(404);
        exit('Utilisateur ou exploitation introuvable.');
    }
    try {
        $memberPermissionDetail = AdminService::memberPermissions(
            $pdo,
            $userId,
            $targetUserId,
            $targetFarmId
        );
    } catch (RuntimeException) {
        http_response_code(404);
        exit('Utilisateur ou exploitation introuvable.');
    }
    $data = [];
    $permissionViewPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
        . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
        . DIRECTORY_SEPARATOR . 'user-permissions-content.php';
} else {
    $data = AdminService::overview($pdo, $userId);
    $permissionViewPath = null;
}
$csrfToken = htmlspecialchars(
    AuthSession::csrfToken(),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$userQuery = $pdo->prepare('SELECT full_name FROM users WHERE id = :user_id');
$userQuery->execute(['user_id' => $userId]);
$currentUserName = (string) $userQuery->fetchColumn();
$nameParts = preg_split('/\s+/u', trim($currentUserName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$userInitials = mb_strtoupper(
    mb_substr($nameParts[0] ?? 'A', 0, 1, 'UTF-8')
        . mb_substr($nameParts[1] ?? '', 0, 1, 'UTF-8'),
    'UTF-8'
);
$memberships = [];
$currentFarmName = 'Administration du site';
$topbarDate = (new DateTimeImmutable())->format('d/m/Y');
$pageKey = 'admin';
$isLiveDashboard = true;
$isTraining = true;
$isTrainingAdmin = true;
$isPrivateApp = true;
$page = [
    'title' => $memberPermissionDetail !== null
        ? 'Permissions de ' . (string) $memberPermissionDetail['user']['full_name']
        : 'Administration',
    'section' => 'ADMINISTRATION DU SITE',
    'description' => $memberPermissionDetail !== null
        ? 'Consultez et configurez les droits de cet utilisateur pour chaque exploitation.'
        : 'Comptes, accès, référentiels, paramètres et actions sensibles.',
];
$liveViewPath = $permissionViewPath ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
    . DIRECTORY_SEPARATOR . 'admin-content.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Layouts'
    . DIRECTORY_SEPARATOR . 'app.php';

function adminPositiveId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if (!is_int($id) || $id < 1) {
        throw new RuntimeException('Identifiant invalide.');
    }
    return $id;
}

function adminOptionalPositiveId(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    return adminPositiveId($value);
}

function adminAnchor(string $action): string
{
    return match ($action) {
        'user_save', 'user_delete' => 'users',
        'membership_save' => 'memberships',
        'permissions_save' => 'permissions',
        'reference_save' => 'references',
        'calendar_save' => 'calendar',
        'setting_save' => 'settings',
        'threshold_save' => 'thresholds',
        'service_save' => 'services',
        default => 'audit',
    };
}
