<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\FarmAccess;
use MonElevage\Database\Connection;
use MonElevage\Finance\FinanceService;
use MonElevage\Support\AuthSession;

AuthSession::start();
$pdo = Connection::get($config);
$userId = AuthSession::userId($pdo);
if ($userId === null) {
    header('Location: auth.php?view=login', true, 303);
    exit;
}

$memberships = FarmAccess::memberships($pdo, $userId);
$requestedFarmId = filter_var($_GET['farm_id'] ?? null, FILTER_VALIDATE_INT);
$hasFarmId = array_key_exists('farm_id', $_GET);
if ($hasFarmId && (!is_int($requestedFarmId) || $requestedFarmId < 1)) {
    http_response_code(404);
    exit('Exploitation introuvable.');
}
$selectedFarm = null;
foreach ($memberships as $membership) {
    if ((!$hasFarmId && $selectedFarm === null)
        || (int) $membership['id'] === $requestedFarmId) {
        $selectedFarm = $membership;
        if ($hasFarmId) {
            break;
        }
    }
}
if (!is_array($selectedFarm)) {
    http_response_code(404);
    exit('Exploitation introuvable.');
}

$farmId = (int) $selectedFarm['id'];
$farmTimezone = new DateTimeZone((string) $selectedFarm['timezone']);
$today = new DateTimeImmutable('today', $farmTimezone);
$canView = FarmAccess::allows($pdo, $userId, $farmId, 'expense.view');
$canManage = FarmAccess::allows($pdo, $userId, $farmId, 'expense.manage');
if (!$canView) {
    http_response_code(403);
    exit('Accès financier refusé.');
}
if ((string) $selectedFarm['currency_code'] !== 'XOF') {
    http_response_code(422);
    exit('Le module financier enregistre les montants en FCFA (XOF). Aucune conversion automatique ne sera effectuée ; configurez cette exploitation en XOF avant de continuer.');
}

$errors = [];
$monthStart = $today->modify('first day of this month')->format('Y-m-d');
$monthEnd = $today->format('Y-m-d');
$fromDate = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : $monthStart;
$toDate = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : $monthEnd;
$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
$flocks = FarmAccess::allows($pdo, $userId, $farmId, 'flock.view')
    ? FinanceService::flocks($pdo, $userId, $farmId)
    : [];
$selectedFlockId = filter_var($_GET['flock_id'] ?? null, FILTER_VALIDATE_INT);
$selectedFlockId = is_int($selectedFlockId) && $selectedFlockId > 0
    ? $selectedFlockId
    : null;
$flockIds = array_map(static fn (array $flock): int => (int) $flock['id'], $flocks);
if ($selectedFlockId !== null && !in_array($selectedFlockId, $flockIds, true)) {
    http_response_code(404);
    exit('Élevage introuvable.');
}
if (!$validDate($fromDate) || !$validDate($toDate) || $fromDate > $toDate) {
    http_response_code(422);
    $fromDate = $monthStart;
    $toDate = $monthEnd;
    $errors[] = 'La période du filtre est invalide.';
}

$receiptDirectory = MON_ELEVAGE_ROOT . DIRECTORY_SEPARATOR . 'storage'
    . DIRECTORY_SEPARATOR . 'receipts';
$receiptPath = static function (string $key, string $mimeType) use ($receiptDirectory): string {
    if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
        throw new RuntimeException('Référence de justificatif invalide.');
    }
    $extension = match ($mimeType) {
        'application/pdf' => '.pdf',
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        default => throw new RuntimeException('Type de justificatif non autorisé.'),
    };
    return $receiptDirectory . DIRECTORY_SEPARATOR . $key . $extension;
};
$removeReceiptFile = static function (?string $key, ?string $mimeType) use ($receiptPath): void {
    if ($key === null || $key === '' || $mimeType === null) {
        return;
    }
    $path = $receiptPath($key, $mimeType);
    if (is_file($path) && !unlink($path)) {
        error_log('Unable to remove retired finance receipt from private storage.');
    }
};

if (isset($_GET['download'])) {
    $downloadId = filter_var($_GET['download'], FILTER_VALIDATE_INT);
    if (!is_int($downloadId) || $downloadId < 1) {
        http_response_code(404);
        exit('Justificatif introuvable.');
    }
    $receipt = FinanceService::receipt($pdo, $userId, $farmId, $downloadId);
    $path = $receiptPath((string) $receipt['receipt_storage_key'], (string) $receipt['receipt_mime_type']);
    if (!is_file($path)) {
        http_response_code(404);
        exit('Le fichier du justificatif est indisponible.');
    }
    header('Content-Type: ' . $receipt['receipt_mime_type']);
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: attachment; filename="justificatif-' . $downloadId
        . (str_ends_with($path, '.pdf') ? '.pdf' : (str_ends_with($path, '.png') ? '.png' : '.jpg')) . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

$expenseValues = [
    'flock_id' => '',
    'incurred_on' => $today->format('Y-m-d'),
    'category' => 'feed',
    'expense_type' => 'charge',
    'description' => '',
    'amount' => '',
];
$budgetValues = [
    'name' => '',
    'flock_id' => '',
    'starts_on' => $monthStart,
    'ends_on' => $today->modify('last day of this month')->format('Y-m-d'),
    'lines' => [[
        'financial_type' => 'cash',
        'category' => 'feed',
        'planned_amount' => '',
    ]],
];
$editingExpense = null;
$editingBudget = null;
$flash = AuthSession::takeFlash();

if (isset($_GET['edit_expense'])) {
    $editId = filter_var($_GET['edit_expense'], FILTER_VALIDATE_INT);
    if (!is_int($editId) || $editId < 1 || !$canManage) {
        http_response_code($canManage ? 404 : 403);
        exit('Dépense introuvable ou modification refusée.');
    }
    try {
        $editingExpense = FinanceService::findExpense($pdo, $userId, $farmId, $editId);
        $expenseValues = $editingExpense;
    } catch (RuntimeException) {
        http_response_code(404);
        exit('Dépense introuvable ou non modifiable.');
    }
}
if (isset($_GET['edit_budget'])) {
    $editBudgetId = filter_var($_GET['edit_budget'], FILTER_VALIDATE_INT);
    if (!is_int($editBudgetId) || $editBudgetId < 1 || !$canManage) {
        http_response_code($canManage ? 404 : 403);
        exit('Budget introuvable ou modification refusée.');
    }
    try {
        $editingBudget = FinanceService::budget($pdo, $userId, $farmId, $editBudgetId);
        if ($editingBudget['status'] !== 'active') {
            throw new RuntimeException('Budget archivé.');
        }
        $budgetValues = $editingBudget;
    } catch (RuntimeException) {
        http_response_code(404);
        exit('Budget introuvable ou archivé.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newReceiptPath = null;
    $newReceipt = null;
    if (!AuthSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errors[] = 'Le formulaire a expiré ou ne peut pas être vérifié. Rechargez la page.';
    } elseif (!$canManage) {
        http_response_code(403);
        $errors[] = 'Vous ne disposez pas du droit de modifier les budgets et dépenses.';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        try {
            if ($action === 'save_expense') {
                $expenseValues = $_POST;
                $file = $_FILES['receipt'] ?? null;
                if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
                    if ($uploadError !== UPLOAD_ERR_OK) {
                        throw new RuntimeException(match ($uploadError) {
                            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Le justificatif dépasse la taille maximale autorisée (5 Mio).',
                            UPLOAD_ERR_PARTIAL => 'Le justificatif n’a été reçu que partiellement. Réessayez.',
                            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'Le justificatif n’a pas pu être enregistré par le serveur.',
                            default => 'Le téléversement du justificatif a échoué.',
                        });
                    }
                    $tempPath = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
                    $fileSize = is_int($file['size'] ?? null) ? $file['size'] : 0;
                    if ($tempPath === '' || !is_uploaded_file($tempPath)
                        || $fileSize < 1 || $fileSize > 5 * 1024 * 1024) {
                        throw new RuntimeException('Le justificatif doit être un fichier non vide de 5 Mio maximum.');
                    }
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file($tempPath);
                    if (!is_string($mimeType)
                        || !in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
                        throw new RuntimeException('Format de justificatif refusé. Utilisez un PDF, un JPEG ou un PNG.');
                    }
                    $originalName = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'justificatif')));
                    $originalName = preg_replace('/[\r\n\x00-\x1F\x7F]/', '', $originalName) ?? '';
                    $originalName = mb_substr($originalName, 0, 255, 'UTF-8');
                    if ($originalName === '') {
                        $originalName = 'justificatif';
                    }
                    $newReceipt = [
                        'name' => $originalName,
                        'key' => bin2hex(random_bytes(32)),
                        'mime_type' => $mimeType,
                    ];
                    if (!is_dir($receiptDirectory)
                        && !mkdir($receiptDirectory, 0770, true)
                        && !is_dir($receiptDirectory)) {
                        throw new RuntimeException('Le stockage privé des justificatifs est indisponible.');
                    }
                    $newReceiptPath = $receiptPath($newReceipt['key'], $mimeType);
                    if (!move_uploaded_file($tempPath, $newReceiptPath)) {
                        throw new RuntimeException('Le justificatif n’a pas pu être stocké. Réessayez.');
                    }
                }
                $expenseRaw = $_POST['expense_id'] ?? null;
                $expenseId = $expenseRaw === null || $expenseRaw === ''
                    ? null
                    : filter_var($expenseRaw, FILTER_VALIDATE_INT);
                if ($expenseRaw !== null && $expenseRaw !== ''
                    && (!is_int($expenseId) || $expenseId < 1)) {
                    throw new RuntimeException('Identifiant de dépense invalide.');
                }
                $result = FinanceService::saveExpense(
                    $pdo,
                    $userId,
                    $farmId,
                    $_POST,
                    $today,
                    $newReceipt,
                    isset($_POST['remove_receipt']),
                    $expenseId
                );
                if ($result['old_receipt_key'] !== null) {
                    $removeReceiptFile(
                        $result['old_receipt_key'],
                        is_string($result['old_receipt_mime_type'])
                            ? $result['old_receipt_mime_type']
                            : null
                    );
                }
                AuthSession::flash('success', $expenseId === null
                    ? 'La dépense en FCFA a été enregistrée.'
                    : 'La dépense a été modifiée.');
            } elseif ($action === 'void_expense') {
                $postedId = filter_var($_POST['expense_id'] ?? null, FILTER_VALIDATE_INT);
                if (!is_int($postedId) || $postedId < 1) {
                    throw new RuntimeException('Dépense à annuler invalide.');
                }
                $receipt = FinanceService::voidExpense(
                    $pdo,
                    $userId,
                    $farmId,
                    $postedId,
                    is_string($_POST['void_reason'] ?? null) ? $_POST['void_reason'] : ''
                );
                if ($receipt !== null) {
                    $removeReceiptFile($receipt['key'], $receipt['mime_type']);
                }
                AuthSession::flash('success', 'La dépense est annulée et conservée dans l’historique.');
            } elseif ($action === 'save_budget') {
                $budgetValues = $_POST;
                $budgetRaw = $_POST['budget_id'] ?? null;
                $postedBudgetId = $budgetRaw === null || $budgetRaw === ''
                    ? null
                    : filter_var($budgetRaw, FILTER_VALIDATE_INT);
                if ($budgetRaw !== null && $budgetRaw !== ''
                    && (!is_int($postedBudgetId) || $postedBudgetId < 1)) {
                    throw new RuntimeException('Identifiant de budget invalide.');
                }
                FinanceService::saveBudget(
                    $pdo,
                    $userId,
                    $farmId,
                    $_POST,
                    $postedBudgetId
                );
                AuthSession::flash('success', $postedBudgetId === null
                    ? 'Le budget prévisionnel a été créé.'
                    : 'Le budget prévisionnel a été modifié.');
            } elseif ($action === 'archive_budget') {
                $postedBudgetId = filter_var($_POST['budget_id'] ?? null, FILTER_VALIDATE_INT);
                if (!is_int($postedBudgetId) || $postedBudgetId < 1) {
                    throw new RuntimeException('Budget à archiver invalide.');
                }
                FinanceService::archiveBudget($pdo, $userId, $farmId, $postedBudgetId);
                AuthSession::flash('success', 'Le budget a été archivé ; les écritures restent conservées.');
            } else {
                http_response_code(400);
                throw new RuntimeException('Action financière inconnue.');
            }
            header(
                'Location: finance.php?farm_id=' . $farmId . '&from='
                    . rawurlencode($fromDate) . '&to=' . rawurlencode($toDate)
                    . ($selectedFlockId === null ? '' : '&flock_id=' . $selectedFlockId),
                true,
                303
            );
            exit;
        } catch (RuntimeException $exception) {
            if ($newReceiptPath !== null && is_file($newReceiptPath) && !unlink($newReceiptPath)) {
                error_log('Unable to remove staged finance receipt after failed save.');
            }
            if (str_contains($exception->getMessage(), 'Permission insuffisante')) {
                http_response_code(403);
            } elseif (http_response_code() < 400) {
                http_response_code(422);
            }
            $errors[] = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($newReceiptPath !== null && is_file($newReceiptPath) && !unlink($newReceiptPath)) {
                error_log('Unable to remove staged finance receipt after failed save.');
            }
            error_log(sprintf('Finance operation failed: %s', $exception->getMessage()));
            http_response_code(500);
            $errors[] = 'L’opération financière n’a pas pu être enregistrée. Consultez le journal local.';
        } catch (Throwable $exception) {
            if ($newReceiptPath !== null && is_file($newReceiptPath) && !unlink($newReceiptPath)) {
                error_log('Unable to remove staged finance receipt after failed save.');
            }
            throw $exception;
        }
    }
}

$postedExpenseId = filter_var($_POST['expense_id'] ?? null, FILTER_VALIDATE_INT);
$expenseEditId = is_array($editingExpense)
    ? (int) $editingExpense['id']
    : (is_int($postedExpenseId) && $postedExpenseId > 0 ? $postedExpenseId : null);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $expenseEditId !== null && $canManage) {
    try {
        $editingExpense = FinanceService::findExpense($pdo, $userId, $farmId, $expenseEditId);
    } catch (RuntimeException) {
        $editingExpense = null;
    }
}
$postedBudgetId = filter_var($_POST['budget_id'] ?? null, FILTER_VALIDATE_INT);
$budgetEditId = is_array($editingBudget)
    ? (int) $editingBudget['id']
    : (is_int($postedBudgetId) && $postedBudgetId > 0 ? $postedBudgetId : null);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $budgetEditId !== null && $canManage) {
    try {
        $editingBudget = FinanceService::budget($pdo, $userId, $farmId, $budgetEditId);
    } catch (RuntimeException) {
        $editingBudget = null;
    }
}
$financeFormAction = 'finance.php?farm_id=' . $farmId . '&from='
    . rawurlencode($fromDate) . '&to=' . rawurlencode($toDate)
    . ($selectedFlockId === null ? '' : '&flock_id=' . $selectedFlockId);

$summary = FinanceService::summary(
    $pdo,
    $userId,
    $farmId,
    $fromDate,
    $toDate,
    $selectedFlockId
);
$profitability = FinanceService::profitability(
    $pdo,
    $userId,
    $farmId,
    $fromDate,
    $toDate,
    $selectedFlockId
);
$expenses = FinanceService::expenses(
    $pdo,
    $userId,
    $farmId,
    $fromDate,
    $toDate,
    $selectedFlockId
);
$budgets = FinanceService::budgets($pdo, $userId, $farmId);
$productCategories = $pdo->prepare(
    "SELECT DISTINCT category FROM products
     WHERE farm_id = :farm_id AND status = 'active'
     ORDER BY category"
);
$productCategories->execute(['farm_id' => $farmId]);
$expenseCategories = FinanceService::categories($pdo);
$budgetCategories = array_values(array_unique(array_merge(
    array_keys($expenseCategories),
    $productCategories->fetchAll(PDO::FETCH_COLUMN),
    ['stock_purchase']
)));
$userQuery = $pdo->prepare(
    "SELECT full_name FROM users WHERE id = :user_id AND status = 'active' LIMIT 1"
);
$userQuery->execute(['user_id' => $userId]);
$currentUserName = (string) $userQuery->fetchColumn();
$nameParts = preg_split('/\s+/u', trim($currentUserName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$userInitials = mb_strtoupper(
    mb_substr($nameParts[0] ?? 'U', 0, 1, 'UTF-8')
        . mb_substr($nameParts[1] ?? '', 0, 1, 'UTF-8'),
    'UTF-8'
);
$csrfToken = htmlspecialchars(AuthSession::csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$currentFarmName = (string) $selectedFarm['name'];
$currentFarmId = $farmId;
$currentFarmRole = FarmAccess::role($pdo, $userId, $farmId);
$farmSwitchAction = 'finance.php';
$isLiveDashboard = true;
$isPrivateApp = true;
$pageKey = 'finance';
$page = [
    'title' => 'Finances et budgets',
    'section' => 'GESTION FINANCIÈRE',
    'description' => 'Suivez les flux de trésorerie, les charges consommées et les investissements, sans double comptage.',
];
$topbarDate = $today->format('d/m/Y');
$liveViewPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
    . DIRECTORY_SEPARATOR . 'finance-content.php';
$extraScripts = [
    'assets/vendor/chart.umd.js',
    'assets/js/finance.js',
    'assets/js/profitability.js',
];

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Layouts'
    . DIRECTORY_SEPARATOR . 'app.php';
