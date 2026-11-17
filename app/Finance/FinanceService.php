<?php
declare(strict_types=1);

namespace MonElevage\Finance;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use MonElevage\Auth\FarmAccess;

final class FinanceService
{
    public const CATEGORIES = [
        'feed' => 'Alimentation',
        'health' => 'Santé et prévention',
        'labor' => 'Main-d’œuvre',
        'utilities' => 'Eau et énergie',
        'transport' => 'Transport',
        'maintenance' => 'Entretien et réparations',
        'equipment' => 'Matériel réutilisable',
        'other' => 'Autres charges',
    ];

    private const FINANCIAL_TYPES = ['cash', 'consumed', 'investment'];
    private const MAX_AMOUNT = 9999999999999;

    public static function categories(PDO $pdo): array
    {
        $statement = $pdo->query(
            "SELECT code, label, status FROM system_reference_items
             WHERE reference_type = 'expense_category'
             ORDER BY position, label, id"
        );
        $categories = [];
        $rows = $statement->fetchAll();
        foreach ($rows as $row) {
            if ($row['status'] !== 'active') {
                continue;
            }
            $categories[(string) $row['code']] = (string) $row['label'];
        }
        return $rows === [] ? self::CATEGORIES : $categories;
    }

    public static function validateExpense(
        array $input,
        DateTimeImmutable $today,
        ?array $categories = null
    ): array
    {
        $errors = [];
        $amountRaw = self::text($input['amount'] ?? null);
        $amount = preg_match('/^([0-9]{1,13})(?:\.0{1,2})?$/D', $amountRaw, $amountParts) === 1
            ? (int) $amountParts[1]
            : null;
        $category = self::text($input['category'] ?? null);
        $type = self::text($input['expense_type'] ?? null);
        $description = self::text($input['description'] ?? null);
        $date = self::date($input['incurred_on'] ?? null);
        $flockRaw = $input['flock_id'] ?? '';
        $flockId = $flockRaw === '' ? null : filter_var($flockRaw, FILTER_VALIDATE_INT);

        if ($amount === null || $amount < 1 || $amount > self::MAX_AMOUNT) {
            $errors[] = 'Le montant doit être un nombre entier positif en FCFA (maximum 9 999 999 999 999).';
        }
        if (!array_key_exists($category, $categories ?? self::CATEGORIES)) {
            $errors[] = 'Choisissez une catégorie de dépense valide.';
        }
        if (!in_array($type, ['consumable', 'charge', 'investment'], true)) {
            $errors[] = 'Choisissez consommable, charge ou matériel réutilisable.';
        }
        if ($description === '' || mb_strlen($description, 'UTF-8') > 500) {
            $errors[] = 'La description est obligatoire (500 caractères maximum).';
        }
        if ($date === null || $date > $today->format('Y-m-d')) {
            $errors[] = 'La date de dépense doit être valide et ne peut pas être future.';
        }
        if ($flockRaw !== '' && (!is_int($flockId) || $flockId < 1)) {
            $errors[] = 'Choisissez un élevage valide ou laissez ce champ vide.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'amount' => $amount,
                'category' => $category,
                'expense_type' => $type,
                'description' => $description,
                'incurred_on' => $date ?? '',
                'flock_id' => is_int($flockId) ? $flockId : null,
            ],
        ];
    }

    public static function validateBudget(array $input): array
    {
        $errors = [];
        $name = self::text($input['name'] ?? null);
        $startsOn = self::date($input['starts_on'] ?? null);
        $endsOn = self::date($input['ends_on'] ?? null);
        $flockRaw = $input['flock_id'] ?? '';
        $flockId = $flockRaw === '' ? null : filter_var($flockRaw, FILTER_VALIDATE_INT);
        $lines = $input['lines'] ?? null;
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            $errors[] = 'Le nom du budget est obligatoire (160 caractères maximum).';
        }
        if ($startsOn === null || $endsOn === null || $endsOn < $startsOn) {
            $errors[] = 'La période du budget est invalide.';
        }
        if ($flockRaw !== '' && (!is_int($flockId) || $flockId < 1)) {
            $errors[] = 'Choisissez un élevage valide ou laissez le budget global.';
        }
        if (!is_array($lines) || $lines === [] || count($lines) > 30) {
            $errors[] = 'Un budget doit contenir entre 1 et 30 postes.';
            $lines = [];
        }
        $validatedLines = [];
        $keys = [];
        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                $errors[] = sprintf('Poste %d : données invalides.', $index + 1);
                continue;
            }
            $type = self::text($line['financial_type'] ?? null);
            $category = self::text($line['category'] ?? null);
            $amountRaw = self::text($line['planned_amount'] ?? null);
            $amount = preg_match('/^([0-9]{1,13})(?:\.0{1,2})?$/D', $amountRaw, $amountParts) === 1
                ? (int) $amountParts[1]
                : null;
            if (!in_array($type, self::FINANCIAL_TYPES, true)) {
                $errors[] = sprintf('Poste %d : type financier invalide.', $index + 1);
            }
            if ($category === '' || mb_strlen($category, 'UTF-8') > 60) {
                $errors[] = sprintf('Poste %d : indiquez une catégorie valide.', $index + 1);
            }
            if ($amount === null || $amount < 1 || $amount > self::MAX_AMOUNT) {
                $errors[] = sprintf('Poste %d : le montant prévu doit être un entier positif en FCFA.', $index + 1);
            }
            $key = $type . ':' . $category;
            if (isset($keys[$key])) {
                $errors[] = sprintf('Poste %d : ce type et cette catégorie figurent déjà dans le budget.', $index + 1);
            }
            $keys[$key] = true;
            if (in_array($type, self::FINANCIAL_TYPES, true)
                && $category !== '' && mb_strlen($category, 'UTF-8') <= 60
                && $amount !== null && $amount > 0) {
                $validatedLines[] = [
                    'financial_type' => $type,
                    'category' => $category,
                    'planned_amount' => $amount,
                ];
            }
        }
        return [
            'errors' => $errors,
            'values' => [
                'name' => $name,
                'starts_on' => $startsOn ?? '',
                'ends_on' => $endsOn ?? '',
                'flock_id' => is_int($flockId) ? $flockId : null,
                'lines' => $validatedLines,
            ],
        ];
    }

    public static function saveExpense(
        PDO $pdo,
        int $userId,
        int $farmId,
        array $input,
        DateTimeImmutable $today,
        ?array $receipt = null,
        bool $removeReceipt = false,
        ?int $expenseId = null
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'expense.manage');
        $validated = self::validateExpense($input, $today, self::categories($pdo));
        self::requireValid($validated);
        $values = $validated['values'];
        if ($values['flock_id'] !== null) {
            self::lockFlock($pdo, $farmId, $values['flock_id']);
        }

        $pdo->beginTransaction();
        try {
            if ($expenseId !== null) {
                $existing = $pdo->prepare(
                    'SELECT e.receipt_file_name, e.receipt_storage_key, e.receipt_mime_type
                     FROM expenses e
                     LEFT JOIN purchases p ON p.farm_id = e.farm_id AND p.expense_id = e.id
                     WHERE e.farm_id = :farm_id AND e.id = :id
                       AND e.status = \'posted\' AND p.id IS NULL
                     FOR UPDATE'
                );
                $existing->execute(['farm_id' => $farmId, 'id' => $expenseId]);
                $existingReceipt = $existing->fetch();
                if (!is_array($existingReceipt)) {
                    throw new RuntimeException(
                        'Cette dépense est introuvable, déjà annulée ou liée à un achat de stock non modifiable.'
                    );
                }
                $newReceipt = $receipt ?? null;
                $keepReceipt = $newReceipt === null && !$removeReceipt;
                $statement = $pdo->prepare(
                    'UPDATE expenses
                     SET flock_id = :flock_id, incurred_on = :incurred_on, category = :category,
                         expense_type = :expense_type, description = :description, amount = :amount,
                         receipt_file_name = :receipt_file_name,
                         receipt_storage_key = :receipt_storage_key,
                         receipt_mime_type = :receipt_mime_type
                     WHERE farm_id = :farm_id AND id = :id AND status = \'posted\''
                );
                $statement->execute([
                    'flock_id' => $values['flock_id'],
                    'incurred_on' => $values['incurred_on'],
                    'category' => $values['category'],
                    'expense_type' => $values['expense_type'],
                    'description' => $values['description'],
                    'amount' => $values['amount'],
                    'receipt_file_name' => $keepReceipt
                        ? $existingReceipt['receipt_file_name']
                        : ($newReceipt['name'] ?? null),
                    'receipt_storage_key' => $keepReceipt ? $existingReceipt['receipt_storage_key'] : ($newReceipt['key'] ?? null),
                    'receipt_mime_type' => $keepReceipt
                        ? $existingReceipt['receipt_mime_type']
                        : ($newReceipt['mime_type'] ?? null),
                    'farm_id' => $farmId,
                    'id' => $expenseId,
                ]);
                $pdo->commit();
                return [
                    'id' => $expenseId,
                    'old_receipt_key' => is_string($existingReceipt['receipt_storage_key'])
                        && $existingReceipt['receipt_storage_key'] !== ''
                        && $existingReceipt['receipt_storage_key'] !== ($receipt['key'] ?? null)
                        ? (string) $existingReceipt['receipt_storage_key']
                        : null,
                    'old_receipt_mime_type' => $existingReceipt['receipt_mime_type'],
                ];
            }

            $statement = $pdo->prepare(
                "INSERT INTO expenses
                    (farm_id, flock_id, incurred_on, category, expense_type, description,
                     amount, currency_code, receipt_file_name, receipt_storage_key,
                     receipt_mime_type, created_by_user_id)
                 VALUES
                    (:farm_id, :flock_id, :incurred_on, :category, :expense_type, :description,
                     :amount, 'XOF', :receipt_file_name, :receipt_storage_key,
                     :receipt_mime_type, :user_id)"
            );
            $statement->execute([
                'farm_id' => $farmId,
                'flock_id' => $values['flock_id'],
                'incurred_on' => $values['incurred_on'],
                'category' => $values['category'],
                'expense_type' => $values['expense_type'],
                'description' => $values['description'],
                'amount' => $values['amount'],
                'receipt_file_name' => $receipt['name'] ?? null,
                'receipt_storage_key' => $receipt['key'] ?? null,
                'receipt_mime_type' => $receipt['mime_type'] ?? null,
                'user_id' => $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            return ['id' => $id, 'old_receipt_key' => null];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function voidExpense(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $expenseId,
        string $reason
    ): ?array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'expense.manage');
        $reason = trim($reason);
        if ($expenseId < 1 || mb_strlen($reason, 'UTF-8') < 3 || mb_strlen($reason, 'UTF-8') > 500) {
            throw new RuntimeException('Indiquez un motif d’annulation de 3 à 500 caractères.');
        }
        $pdo->beginTransaction();
        try {
            $query = $pdo->prepare(
                'SELECT e.receipt_storage_key, e.receipt_mime_type
                 FROM expenses e
                 LEFT JOIN purchases p ON p.farm_id = e.farm_id AND p.expense_id = e.id
                 WHERE e.farm_id = :farm_id AND e.id = :id AND e.status = \'posted\'
                   AND p.id IS NULL
                 FOR UPDATE'
            );
            $query->execute(['farm_id' => $farmId, 'id' => $expenseId]);
            $receipt = $query->fetch();
            if (!is_array($receipt)) {
                throw new RuntimeException(
                    'Cette dépense est introuvable, déjà annulée ou liée à un achat de stock non annulable ici.'
                );
            }
            $update = $pdo->prepare(
                "UPDATE expenses
                 SET status = 'void', voided_at = UTC_TIMESTAMP(),
                     voided_by_user_id = :user_id, void_reason = :reason
                 WHERE farm_id = :farm_id AND id = :id AND status = 'posted'"
            );
            $update->execute([
                'user_id' => $userId,
                'reason' => $reason,
                'farm_id' => $farmId,
                'id' => $expenseId,
            ]);
            $pdo->commit();
            return !is_string($receipt['receipt_storage_key'])
                || $receipt['receipt_storage_key'] === ''
                ? null
                : [
                    'key' => (string) $receipt['receipt_storage_key'],
                    'mime_type' => (string) $receipt['receipt_mime_type'],
                ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function saveBudget(
        PDO $pdo,
        int $userId,
        int $farmId,
        array $input,
        ?int $budgetId = null
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'expense.manage');
        $validated = self::validateBudget($input);
        self::requireValid($validated);
        $values = $validated['values'];
        $pdo->beginTransaction();
        try {
            $farmLock = $pdo->prepare('SELECT id FROM farms WHERE id = :farm_id FOR UPDATE');
            $farmLock->execute(['farm_id' => $farmId]);
            if ($farmLock->fetchColumn() === false) {
                throw new RuntimeException('Exploitation introuvable.');
            }
            if ($values['flock_id'] !== null) {
                self::lockFlock($pdo, $farmId, $values['flock_id']);
            }
            $overlap = $pdo->prepare(
                "SELECT id FROM financial_budgets
                 WHERE farm_id = :farm_id AND status = 'active'
                   AND starts_on <= :ends_on AND ends_on >= :starts_on
                   AND flock_id <=> :flock_id
                   AND (:budget_id IS NULL OR id <> :excluded_budget_id)
                 LIMIT 1"
            );
            $overlap->execute([
                'farm_id' => $farmId,
                'ends_on' => $values['ends_on'],
                'starts_on' => $values['starts_on'],
                'flock_id' => $values['flock_id'],
                'budget_id' => $budgetId,
                'excluded_budget_id' => $budgetId,
            ]);
            if ($overlap->fetchColumn() !== false) {
                throw new RuntimeException('Un budget actif couvre déjà cette période pour ce périmètre.');
            }
            if ($budgetId === null) {
                $insert = $pdo->prepare(
                    "INSERT INTO financial_budgets
                        (farm_id, flock_id, name, starts_on, ends_on, created_by_user_id)
                     VALUES (:farm_id, :flock_id, :name, :starts_on, :ends_on, :user_id)"
                );
                $insert->execute([
                    'farm_id' => $farmId,
                    'flock_id' => $values['flock_id'],
                    'name' => $values['name'],
                    'starts_on' => $values['starts_on'],
                    'ends_on' => $values['ends_on'],
                    'user_id' => $userId,
                ]);
                $budgetId = (int) $pdo->lastInsertId();
            } else {
                $update = $pdo->prepare(
                    "UPDATE financial_budgets
                     SET flock_id = :flock_id, name = :name, starts_on = :starts_on, ends_on = :ends_on
                     WHERE farm_id = :farm_id AND id = :id AND status = 'active'"
                );
                $update->execute([
                    'flock_id' => $values['flock_id'],
                    'name' => $values['name'],
                    'starts_on' => $values['starts_on'],
                    'ends_on' => $values['ends_on'],
                    'farm_id' => $farmId,
                    'id' => $budgetId,
                ]);
                if ($update->rowCount() === 0) {
                    $exists = $pdo->prepare(
                        "SELECT id FROM financial_budgets
                         WHERE farm_id = :farm_id AND id = :id AND status = 'active'"
                    );
                    $exists->execute(['farm_id' => $farmId, 'id' => $budgetId]);
                    if ($exists->fetchColumn() === false) {
                        throw new RuntimeException('Le budget à modifier est introuvable ou archivé.');
                    }
                }
                $pdo->prepare(
                    'DELETE FROM financial_budget_lines WHERE farm_id = :farm_id AND budget_id = :budget_id'
                )->execute(['farm_id' => $farmId, 'budget_id' => $budgetId]);
            }
            $lineInsert = $pdo->prepare(
                'INSERT INTO financial_budget_lines
                    (farm_id, budget_id, financial_type, category, planned_amount)
                 VALUES (:farm_id, :budget_id, :financial_type, :category, :planned_amount)'
            );
            foreach ($values['lines'] as $line) {
                $lineInsert->execute([
                    'farm_id' => $farmId,
                    'budget_id' => $budgetId,
                    'financial_type' => $line['financial_type'],
                    'category' => $line['category'],
                    'planned_amount' => $line['planned_amount'],
                ]);
            }
            $pdo->commit();
            return $budgetId;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('Ce type de dépense et cette catégorie figurent déjà dans le budget.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function archiveBudget(PDO $pdo, int $userId, int $farmId, int $budgetId): void
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'expense.manage');
        $statement = $pdo->prepare(
            "UPDATE financial_budgets SET status = 'archived'
             WHERE farm_id = :farm_id AND id = :id AND status = 'active'"
        );
        $statement->execute(['farm_id' => $farmId, 'id' => $budgetId]);
        if ($statement->rowCount() === 0) {
            throw new RuntimeException('Le budget est introuvable ou déjà archivé.');
        }
    }

    public static function budget(PDO $pdo, int $userId, int $farmId, int $budgetId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'expense.view');
        $statement = $pdo->prepare(
            "SELECT b.id, b.flock_id, b.name, b.starts_on, b.ends_on, b.status,
                    f.name AS flock_name
             FROM financial_budgets b
             LEFT JOIN flocks f ON f.farm_id = b.farm_id AND f.id = b.flock_id
             WHERE b.farm_id = :farm_id AND b.id = :id
             LIMIT 1"
        );
        $statement->execute(['farm_id' => $farmId, 'id' => $budgetId]);
        $budget = $statement->fetch();
        if (!is_array($budget)) {
            throw new RuntimeException('Budget introuvable.');
        }
        $lines = $pdo->prepare(
            'SELECT financial_type, category, planned_amount
             FROM financial_budget_lines
             WHERE farm_id = :farm_id AND budget_id = :budget_id
             ORDER BY financial_type, category'
        );
        $lines->execute(['farm_id' => $farmId, 'budget_id' => $budgetId]);
        $budget['lines'] = $lines->fetchAll();
        foreach ($budget['lines'] as &$line) {
            $line['planned_amount'] = (string) (int) $line['planned_amount'];
        }
        unset($line);
        return $budget;
    }

    public static function budgets(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'expense.view');
        $statement = $pdo->prepare(
            "SELECT b.id, b.flock_id, b.name, b.starts_on, b.ends_on, b.status,
                    f.name AS flock_name
             FROM financial_budgets b
             LEFT JOIN flocks f ON f.farm_id = b.farm_id AND f.id = b.flock_id
             WHERE b.farm_id = :farm_id AND b.status = 'active'
             ORDER BY b.starts_on DESC, b.id DESC"
        );
        $statement->execute(['farm_id' => $farmId]);
        $budgets = $statement->fetchAll();
        $lineQuery = $pdo->prepare(
            'SELECT financial_type, category, planned_amount
             FROM financial_budget_lines
             WHERE farm_id = :farm_id AND budget_id = :budget_id
             ORDER BY financial_type, category'
        );
        foreach ($budgets as &$budget) {
            $lineQuery->execute(['farm_id' => $farmId, 'budget_id' => $budget['id']]);
            $budget['lines'] = $lineQuery->fetchAll();
            $actuals = self::actuals(
                $pdo,
                $farmId,
                (string) $budget['starts_on'],
                (string) $budget['ends_on'],
                $budget['flock_id'] === null ? null : (int) $budget['flock_id']
            );
            $budget['totals'] = [];
            foreach ($budget['lines'] as &$line) {
                $key = $line['financial_type'] . ':' . $line['category'];
                $line['actual_amount'] = $actuals[$key] ?? 0;
                $line['remaining_amount'] = (int) $line['planned_amount'] - $line['actual_amount'];
                $budget['totals'][$line['financial_type']]['planned'] =
                    ($budget['totals'][$line['financial_type']]['planned'] ?? 0)
                    + (int) $line['planned_amount'];
                $budget['totals'][$line['financial_type']]['actual'] =
                    ($budget['totals'][$line['financial_type']]['actual'] ?? 0)
                    + $line['actual_amount'];
            }
            unset($line);
            foreach ($budget['totals'] as &$totals) {
                $totals['remaining'] = $totals['planned'] - $totals['actual'];
            }
            unset($totals);
        }
        unset($budget);
        return $budgets;
    }

    public static function expenses(
        PDO $pdo,
        int $userId,
        int $farmId,
        string $from,
        string $to,
        ?int $flockId = null
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'expense.view');
        $sql = "SELECT e.id, e.flock_id, e.incurred_on, e.category, e.expense_type,
                       e.description, e.amount, e.status, e.receipt_file_name,
                       e.receipt_storage_key, f.name AS flock_name,
                       p.id AS purchase_id, p.reference AS purchase_reference
                FROM expenses e
                LEFT JOIN flocks f ON f.farm_id = e.farm_id AND f.id = e.flock_id
                LEFT JOIN purchases p ON p.farm_id = e.farm_id AND p.expense_id = e.id
                WHERE e.farm_id = :farm_id AND e.currency_code = 'XOF'
                  AND e.incurred_on >= :starts_on AND e.incurred_on <= :ends_on";
        $parameters = [
            'farm_id' => $farmId,
            'starts_on' => $from,
            'ends_on' => $to,
        ];
        if ($flockId !== null) {
            $sql .= ' AND e.flock_id = :flock_id';
            $parameters['flock_id'] = $flockId;
        }
        $sql .= ' ORDER BY e.incurred_on DESC, e.id DESC LIMIT 500';
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    public static function summary(
        PDO $pdo,
        int $userId,
        int $farmId,
        string $from,
        string $to,
        ?int $flockId = null
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'expense.view');
        $actuals = self::actuals($pdo, $farmId, $from, $to, $flockId);
        $cash = 0;
        $consumed = 0;
        $investment = 0;
        foreach ($actuals as $key => $amount) {
            [$type] = explode(':', $key, 2);
            if ($type === 'cash') {
                $cash += $amount;
            } elseif ($type === 'consumed') {
                $consumed += $amount;
            } elseif ($type === 'investment') {
                $investment += $amount;
            }
        }
        return [
            'cash_outflow' => $cash,
            'consumed_charges' => $consumed,
            'reusable_investments' => $investment,
        ];
    }

    public static function profitability(
        PDO $pdo,
        int $userId,
        int $farmId,
        string $from,
        string $to,
        ?int $flockId = null
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'expense.view');
        if (self::date($from) !== $from || self::date($to) !== $to || $from > $to) {
            throw new RuntimeException('La période du bilan de rentabilité est invalide.');
        }

        $animalLineScope = $flockId === null ? '' : ' AND sl.flock_id = :animal_sales_flock_id';
        $saleTotals = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN sale_totals.status = 'posted'
                                      THEN sale_totals.period_amount ELSE 0 END), 0) AS revenue_total,
                    COALESCE(SUM(CASE WHEN sale_totals.status = 'posted'
                                      THEN GREATEST(sale_totals.period_amount - sale_totals.paid_amount, 0)
                                      ELSE 0 END), 0) AS receivables
             FROM (
                 SELECT s.id, s.status,
                        COALESCE(line_totals.period_amount, 0) AS period_amount,
                        CASE WHEN COALESCE(line_totals.all_amount, 0) > 0
                             THEN ROUND(COALESCE(payments.paid_amount, 0)
                                        * line_totals.period_amount / line_totals.all_amount, 0)
                             ELSE 0 END AS paid_amount
                 FROM sales s
                 LEFT JOIN (
                     SELECT sl.farm_id, sl.sale_id,
                            SUM(CASE WHEN s2.sold_at >= CONCAT(:line_from, ' 00:00:00')
                                          AND s2.sold_at < DATE_ADD(:line_to, INTERVAL 1 DAY)
                                          AND (sl.flock_id = :line_scope_flock OR :line_scope_enabled = 0)
                                     THEN sl.line_total ELSE 0 END) AS period_amount,
                            SUM(CASE WHEN sl.flock_id = :line_all_flock OR :line_all_scope = 0
                                     THEN sl.line_total ELSE 0 END) AS all_amount
                     FROM sale_lines sl
                     INNER JOIN sales s2 ON s2.farm_id = sl.farm_id AND s2.id = sl.sale_id
                     WHERE sl.farm_id = :line_farm_id
                     GROUP BY sl.farm_id, sl.sale_id
                 ) line_totals ON line_totals.farm_id = s.farm_id AND line_totals.sale_id = s.id
                 LEFT JOIN (
                     SELECT farm_id, sale_id,
                            SUM(CASE direction WHEN 'received' THEN amount ELSE -amount END) AS paid_amount
                     FROM sale_payments WHERE status = 'posted'
                     GROUP BY farm_id, sale_id
                 ) payments ON payments.farm_id = s.farm_id AND payments.sale_id = s.id
                 WHERE s.farm_id = :sale_farm_id
                   AND s.sold_at < DATE_ADD(:sale_to, INTERVAL 1 DAY)
             ) sale_totals"
        );
        $saleParameters = [
            'line_from' => $from,
            'line_to' => $to,
            'line_farm_id' => $farmId,
            'sale_farm_id' => $farmId,
            'sale_to' => $to,
        ];
        if ($flockId !== null) {
            $saleParameters['line_scope_flock'] = $flockId;
            $saleParameters['line_scope_enabled'] = 1;
            $saleParameters['line_all_flock'] = $flockId;
            $saleParameters['line_all_scope'] = 1;
        } else {
            $saleParameters['line_scope_flock'] = 0;
            $saleParameters['line_scope_enabled'] = 0;
            $saleParameters['line_all_flock'] = 0;
            $saleParameters['line_all_scope'] = 0;
        }
        $saleTotals->execute($saleParameters);
        $sales = $saleTotals->fetch();
        $revenue = is_array($sales) ? (int) round((float) $sales['revenue_total']) : 0;
        $receivables = is_array($sales) ? (int) round((float) $sales['receivables']) : 0;

        $paymentQuery = $pdo->prepare(
            "SELECT COALESCE(SUM(
                        CASE sp.direction WHEN 'received' THEN sp.amount ELSE -sp.amount END
                        * CASE WHEN :scope_enabled = 0 THEN 1
                               WHEN COALESCE(sale_lines.all_amount, 0) > 0
                               THEN sale_lines.scope_amount / sale_lines.all_amount
                               ELSE 0 END
                    ), 0)
             FROM sale_payments sp
             INNER JOIN sales s ON s.farm_id = sp.farm_id AND s.id = sp.sale_id
             LEFT JOIN (
                 SELECT sl.farm_id, sl.sale_id,
                        SUM(sl.line_total) AS all_amount,
                        SUM(CASE WHEN :scope_enabled_lines = 0 OR sl.flock_id = :payment_flock_id
                                 THEN sl.line_total ELSE 0 END) AS scope_amount
                 FROM sale_lines sl
                 WHERE sl.farm_id = :payment_line_farm_id
                 GROUP BY sl.farm_id, sl.sale_id
             ) sale_lines ON sale_lines.farm_id = sp.farm_id AND sale_lines.sale_id = sp.sale_id
             WHERE sp.farm_id = :payment_farm_id AND sp.status = 'posted'
               AND sp.paid_at >= CONCAT(:payment_from, ' 00:00:00')
               AND sp.paid_at < DATE_ADD(:payment_to, INTERVAL 1 DAY)"
        );
        $paymentParameters = [
            'scope_enabled' => $flockId === null ? 0 : 1,
            'scope_enabled_lines' => $flockId === null ? 0 : 1,
            'payment_line_farm_id' => $farmId,
            'payment_farm_id' => $farmId,
            'payment_from' => $from,
            'payment_to' => $to,
        ];
        $paymentParameters['payment_flock_id'] = $flockId ?? 0;
        $paymentQuery->execute($paymentParameters);
        $cashCollected = (int) round((float) $paymentQuery->fetchColumn());

        $expenseFlock = $flockId === null ? '' : ' AND e.flock_id = :expense_flock_id';
        $expenseQuery = $pdo->prepare(
            "SELECT COALESCE(SUM(e.amount), 0) AS expenses_total,
                    COALESCE(SUM(CASE
                        WHEN e.expense_type IN ('consumable', 'charge') AND p.id IS NULL
                        THEN e.amount ELSE 0 END), 0) AS direct_consumed_costs,
                    COALESCE(SUM(CASE
                        WHEN e.expense_type = 'investment' AND p.id IS NULL
                        THEN e.amount ELSE 0 END), 0) AS direct_investments,
                    COALESCE(SUM(CASE
                        WHEN e.expense_type IN ('consumable', 'charge') AND p.id IS NULL
                             AND e.flock_id IS NOT NULL
                        THEN e.amount ELSE 0 END), 0) AS flock_expenses
             FROM expenses e
             LEFT JOIN purchases p ON p.farm_id = e.farm_id AND p.expense_id = e.id
             WHERE e.farm_id = :expense_farm_id AND e.status = 'posted'
               AND e.currency_code = 'XOF'
               AND e.incurred_on BETWEEN :expense_from AND :expense_to $expenseFlock"
        );
        $expenseParameters = [
            'expense_farm_id' => $farmId,
            'expense_from' => $from,
            'expense_to' => $to,
        ];
        if ($flockId !== null) {
            $expenseParameters['expense_flock_id'] = $flockId;
        }
        $expenseQuery->execute($expenseParameters);
        $expenseTotals = $expenseQuery->fetch();

        $purchaseFlock = $flockId === null ? '' : ' AND pu.flock_id = :equipment_purchase_flock_id';
        $equipmentPurchaseQuery = $pdo->prepare(
            "SELECT COALESCE(SUM(pi.line_total), 0)
             FROM purchases pu
             INNER JOIN expenses e ON e.farm_id = pu.farm_id AND e.id = pu.expense_id
             INNER JOIN purchase_items pi ON pi.farm_id = pu.farm_id AND pi.purchase_id = pu.id
             INNER JOIN products pr ON pr.farm_id = pi.farm_id AND pr.id = pi.product_id
             WHERE pu.farm_id = :equipment_purchase_farm_id AND e.status = 'posted'
               AND pu.currency_code = 'XOF' AND pr.product_type = 'equipment'
               AND pu.purchased_on BETWEEN :equipment_purchase_from AND :equipment_purchase_to
               $purchaseFlock"
        );
        $equipmentPurchaseParameters = [
            'equipment_purchase_farm_id' => $farmId,
            'equipment_purchase_from' => $from,
            'equipment_purchase_to' => $to,
        ];
        if ($flockId !== null) {
            $equipmentPurchaseParameters['equipment_purchase_flock_id'] = $flockId;
        }
        $equipmentPurchaseQuery->execute($equipmentPurchaseParameters);
        $equipmentPurchases = (int) round((float) $equipmentPurchaseQuery->fetchColumn());

        $movementFlock = $flockId === null ? '' : ' AND sm.flock_id = :movement_flock_id';
        $consumptionQuery = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN pi.unit_price IS NOT NULL
                                      THEN ABS(sm.quantity_delta) * pi.unit_price ELSE 0 END), 0) AS valued_cost,
                    COALESCE(SUM(CASE WHEN pi.unit_price IS NULL
                                      THEN ABS(sm.quantity_delta) ELSE 0 END), 0) AS unvalued_quantity,
                    COALESCE(SUM(CASE WHEN pi.unit_price IS NULL AND sm.flock_id IS NOT NULL
                                      THEN ABS(sm.quantity_delta) ELSE 0 END), 0) AS unvalued_flock_quantity,
                    COALESCE(SUM(CASE WHEN sm.flock_id IS NOT NULL AND pi.unit_price IS NOT NULL
                                      THEN ABS(sm.quantity_delta) * pi.unit_price ELSE 0 END), 0) AS flock_cost
             FROM stock_movements sm
             INNER JOIN products pr ON pr.farm_id = sm.farm_id AND pr.id = sm.product_id
             LEFT JOIN purchase_items pi
               ON pi.farm_id = sm.farm_id AND pi.lot_id = sm.lot_id AND pi.product_id = sm.product_id
             WHERE sm.farm_id = :consumption_farm_id AND sm.movement_type = 'consumption'
               AND pr.product_type = 'consumable'
               AND sm.occurred_at >= CONCAT(:consumption_from, ' 00:00:00')
               AND sm.occurred_at < DATE_ADD(:consumption_to, INTERVAL 1 DAY) $movementFlock"
        );
        $consumptionParameters = [
            'consumption_farm_id' => $farmId,
            'consumption_from' => $from,
            'consumption_to' => $to,
        ];
        if ($flockId !== null) {
            $consumptionParameters['movement_flock_id'] = $flockId;
        }
        $consumptionQuery->execute($consumptionParameters);
        $consumptionTotals = $consumptionQuery->fetch();

        $saleCostQuery = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN pi.unit_price IS NOT NULL
                                      THEN ABS(sm.quantity_delta) * pi.unit_price ELSE 0 END), 0) AS valued_cost,
                    COALESCE(SUM(CASE WHEN pi.unit_price IS NULL
                                      THEN ABS(sm.quantity_delta) ELSE 0 END), 0) AS unvalued_quantity
             FROM stock_movements sm
             INNER JOIN sale_lines sl ON sl.farm_id = sm.farm_id
                                     AND sl.id = sm.sale_line_id
                                     AND sl.source_type = 'product'
             INNER JOIN sales s ON s.farm_id = sl.farm_id AND s.id = sl.sale_id
             LEFT JOIN purchase_items pi
               ON pi.farm_id = sm.farm_id AND pi.lot_id = sm.lot_id AND pi.product_id = sm.product_id
             WHERE sm.farm_id = :sale_cost_farm_id AND sm.movement_type = 'sale'
               AND s.status = 'posted'
               AND s.sold_at >= CONCAT(:sale_cost_from, ' 00:00:00')
               AND s.sold_at < DATE_ADD(:sale_cost_to, INTERVAL 1 DAY)
               $movementFlock"
        );
        $saleCostParameters = [
            'sale_cost_farm_id' => $farmId,
            'sale_cost_from' => $from,
            'sale_cost_to' => $to,
        ];
        if ($flockId !== null) {
            $saleCostParameters['movement_flock_id'] = $flockId;
        }
        $saleCostQuery->execute($saleCostParameters);
        $saleCostTotals = $saleCostQuery->fetch();

        $inventoryFlock = $flockId === null ? '' : ' AND sm.flock_id = :inventory_flock_id';
        $inventoryQuery = $pdo->prepare(
            "SELECT pr.product_type,
                    COALESCE(SUM(GREATEST(stock.quantity, 0) * pi.unit_price), 0) AS valued_amount,
                    COALESCE(SUM(CASE WHEN pi.unit_price IS NULL
                                      THEN GREATEST(stock.quantity, 0) ELSE 0 END), 0) AS unvalued_quantity
             FROM stock_lots l
             INNER JOIN products pr ON pr.farm_id = l.farm_id AND pr.id = l.product_id
             LEFT JOIN purchase_items pi
               ON pi.farm_id = l.farm_id AND pi.lot_id = l.id AND pi.product_id = l.product_id
             LEFT JOIN (
                 SELECT sm.farm_id, sm.lot_id, sm.product_id,
                        SUM(sm.quantity_delta) AS quantity
                 FROM stock_movements sm
                 WHERE sm.farm_id = :inventory_farm_id
                   AND sm.occurred_at < DATE_ADD(:inventory_to, INTERVAL 1 DAY) $inventoryFlock
                 GROUP BY sm.farm_id, sm.lot_id, sm.product_id
             ) stock ON stock.farm_id = l.farm_id AND stock.lot_id = l.id
                    AND stock.product_id = l.product_id
             WHERE l.farm_id = :lot_farm_id
             GROUP BY pr.product_type"
        );
        $inventoryParameters = ['inventory_farm_id' => $farmId, 'inventory_to' => $to, 'lot_farm_id' => $farmId];
        if ($flockId !== null) {
            $inventoryParameters['inventory_flock_id'] = $flockId;
        }
        $inventoryQuery->execute($inventoryParameters);
        $inventory = ['consumable' => ['value' => 0, 'unvalued' => 0], 'equipment' => ['value' => 0, 'unvalued' => 0]];
        foreach ($inventoryQuery->fetchAll() as $row) {
            $type = (string) $row['product_type'];
            if (isset($inventory[$type])) {
                $inventory[$type] = [
                    'value' => (int) round((float) $row['valued_amount']),
                    'unvalued' => (float) $row['unvalued_quantity'],
                ];
            }
        }

        $untrackedFlock = $flockId === null ? '' : ' AND sm.flock_id = :untracked_flock_id';
        $untrackedQuery = $pdo->prepare(
            "SELECT COALESCE(SUM(sm.quantity_delta), 0)
             FROM stock_movements sm
             WHERE sm.farm_id = :untracked_farm_id AND sm.lot_id IS NULL
               AND sm.occurred_at < DATE_ADD(:untracked_to, INTERVAL 1 DAY) $untrackedFlock"
        );
        $untrackedParameters = ['untracked_farm_id' => $farmId, 'untracked_to' => $to];
        if ($flockId !== null) {
            $untrackedParameters['untracked_flock_id'] = $flockId;
        }
        $untrackedQuery->execute($untrackedParameters);
        $untrackedStock = max(0.0, (float) $untrackedQuery->fetchColumn());

        $mortalityFlock = $flockId === null ? '' : ' AND am.flock_id = :mortality_flock_id';
        $mortalityQuery = $pdo->prepare(
            "SELECT COALESCE(SUM(ABS(am.quantity_delta)), 0)
             FROM animal_movements am
             WHERE am.farm_id = :mortality_farm_id AND am.movement_type = 'mortality'
               AND am.reversal_of_id IS NULL
               AND am.occurred_at >= CONCAT(:mortality_from, ' 00:00:00')
               AND am.occurred_at < DATE_ADD(:mortality_to, INTERVAL 1 DAY) $mortalityFlock"
        );
        $mortalityParameters = [
            'mortality_farm_id' => $farmId,
            'mortality_from' => $from,
            'mortality_to' => $to,
        ];
        if ($flockId !== null) {
            $mortalityParameters['mortality_flock_id'] = $flockId;
        }
        $mortalityQuery->execute($mortalityParameters);
        $mortalityCount = (int) $mortalityQuery->fetchColumn();

        $animalSalesQuery = $pdo->prepare(
            "SELECT COALESCE(SUM(sl.quantity), 0)
             FROM sale_lines sl
             INNER JOIN sales s ON s.farm_id = sl.farm_id AND s.id = sl.sale_id
             WHERE sl.farm_id = :animal_sales_farm_id AND sl.source_type = 'animal'
               AND s.status = 'posted'
               AND s.sold_at >= CONCAT(:animal_sales_from, ' 00:00:00')
               AND s.sold_at < DATE_ADD(:animal_sales_to, INTERVAL 1 DAY) $animalLineScope"
        );
        $animalSalesParameters = [
            'animal_sales_farm_id' => $farmId,
            'animal_sales_from' => $from,
            'animal_sales_to' => $to,
        ];
        if ($flockId !== null) {
            $animalSalesParameters['animal_sales_flock_id'] = $flockId;
        }
        $animalSalesQuery->execute($animalSalesParameters);
        $animalsSold = (int) $animalSalesQuery->fetchColumn();

        $directCosts = (int) ($expenseTotals['direct_consumed_costs'] ?? 0);
        $stockConsumed = (int) round((float) ($consumptionTotals['valued_cost'] ?? 0));
        $productCostOfSales = (int) round((float) ($saleCostTotals['valued_cost'] ?? 0));
        $economicCosts = $directCosts + $stockConsumed + $productCostOfSales;
        $profit = $revenue - $economicCosts;
        $flockCost = (int) ($expenseTotals['flock_expenses'] ?? 0)
            + (int) round((float) ($consumptionTotals['flock_cost'] ?? 0));
        $unknownCostQuantity = (float) ($consumptionTotals['unvalued_quantity'] ?? 0)
            + (float) ($saleCostTotals['unvalued_quantity'] ?? 0);
        $unvaluedStock = $inventory['consumable']['unvalued']
            + $inventory['equipment']['unvalued']
            + $untrackedStock;
        $incompleteReasons = [];
        if ($unknownCostQuantity > 0.00001) {
            $incompleteReasons[] = 'Une partie des consommations ou ventes de stock provient de lots sans coût d’achat.';
        }
        if ($unvaluedStock > 0.00001) {
            $incompleteReasons[] = 'Une partie du stock restant n’a pas de coût d’achat enregistré.';
        }
        if ($animalsSold > 0 && $flockCost === 0) {
            $incompleteReasons[] = 'Aucun coût n’est affecté à l’élevage des animaux vendus ; le coût unitaire est indisponible.';
        }
        if ($animalsSold > 0) {
            $incompleteReasons[] = 'Le coût par animal est une approximation des charges affectées à la période ; les coûts d’acquisition et des périodes antérieures ne sont pas inclus.';
        }
        $cashOutflow = (int) ($expenseTotals['expenses_total'] ?? 0);

        return [
            'revenue_total' => $revenue,
            'cash_collected' => $cashCollected,
            'receivables' => $receivables,
            'expenses_total' => $cashOutflow,
            'cash_outflow' => $cashOutflow,
            'net_cash_flow' => $cashCollected - $cashOutflow,
            'direct_consumed_costs' => $directCosts,
            'stock_consumed_cost' => $stockConsumed,
            'product_cost_of_sales' => $productCostOfSales,
            'economic_costs' => $economicCosts,
            'direct_investments' => (int) ($expenseTotals['direct_investments'] ?? 0),
            'equipment_purchases' => $equipmentPurchases,
            'reusable_investments' => (int) ($expenseTotals['direct_investments'] ?? 0) + $equipmentPurchases,
            'remaining_stock_value' => $inventory['consumable']['value'] + $inventory['equipment']['value'],
            'remaining_consumables_value' => $inventory['consumable']['value'],
            'remaining_equipment_value' => $inventory['equipment']['value'],
            'unvalued_stock_quantity' => $unvaluedStock,
            'unvalued_cost_quantity' => $unknownCostQuantity,
            'animals_sold' => $animalsSold,
            'mortality_count' => $mortalityCount,
            'flock_cost_for_animals' => $flockCost,
            'cost_per_animal_sold' => $animalsSold > 0 ? (int) round($flockCost / $animalsSold) : null,
            'estimated_profit' => $profit,
            'margin_percent' => $revenue > 0 ? round($profit * 100 / $revenue, 2) : null,
            'is_complete' => $incompleteReasons === [],
            'incomplete_reasons' => $incompleteReasons,
            'budget_comparison' => self::budgetComparison($pdo, $farmId, $from, $to, $flockId),
        ];
    }

    private static function budgetComparison(
        PDO $pdo,
        int $farmId,
        string $from,
        string $to,
        ?int $flockId
    ): ?array {
        $scopeSql = $flockId === null
            ? 'flock_id IS NULL'
            : 'flock_id = :budget_flock_id';
        $statement = $pdo->prepare(
            "SELECT id, name, starts_on, ends_on
             FROM financial_budgets
             WHERE farm_id = :budget_farm_id AND status = 'active'
               AND starts_on <= :budget_from AND ends_on >= :budget_to
               AND $scopeSql
             ORDER BY starts_on DESC, id DESC
             LIMIT 1"
        );
        $parameters = ['budget_farm_id' => $farmId, 'budget_from' => $from, 'budget_to' => $to];
        if ($flockId !== null) {
            $parameters['budget_flock_id'] = $flockId;
        }
        $statement->execute($parameters);
        $budget = $statement->fetch();
        if (!is_array($budget)) {
            return null;
        }
        $actuals = self::actuals($pdo, $farmId, (string) $budget['starts_on'], (string) $budget['ends_on'], $flockId);
        $linesQuery = $pdo->prepare(
            'SELECT financial_type, category, planned_amount
             FROM financial_budget_lines
             WHERE farm_id = :line_farm_id AND budget_id = :budget_id
             ORDER BY financial_type, category'
        );
        $linesQuery->execute(['line_farm_id' => $farmId, 'budget_id' => $budget['id']]);
        $lines = [];
        $totals = [];
        foreach ($linesQuery->fetchAll() as $line) {
            $key = $line['financial_type'] . ':' . $line['category'];
            $planned = (int) $line['planned_amount'];
            $actual = $actuals[$key] ?? 0;
            $lines[] = [
                'financial_type' => $line['financial_type'],
                'category' => $line['category'],
                'planned_amount' => $planned,
                'actual_amount' => $actual,
                'remaining_amount' => $planned - $actual,
            ];
            $type = (string) $line['financial_type'];
            $totals[$type]['planned'] = ($totals[$type]['planned'] ?? 0) + $planned;
            $totals[$type]['actual'] = ($totals[$type]['actual'] ?? 0) + $actual;
            $totals[$type]['remaining'] = ($totals[$type]['remaining'] ?? 0) + $planned - $actual;
        }
        return [
            'name' => (string) $budget['name'],
            'starts_on' => (string) $budget['starts_on'],
            'ends_on' => (string) $budget['ends_on'],
            'lines' => $lines,
            'totals' => $totals,
        ];
    }

    public static function flocks(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.view');
        $statement = $pdo->prepare(
            "SELECT id, name, code, status FROM flocks
             WHERE farm_id = :farm_id AND status IN ('active', 'paused', 'closed')
             ORDER BY name, id"
        );
        $statement->execute(['farm_id' => $farmId]);
        return $statement->fetchAll();
    }

    public static function findExpense(PDO $pdo, int $userId, int $farmId, int $expenseId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'expense.manage');
        $statement = $pdo->prepare(
            "SELECT e.id, e.flock_id, e.incurred_on, e.category, e.expense_type,
                    e.description, e.amount, e.receipt_file_name, e.receipt_storage_key,
                    e.receipt_mime_type
             FROM expenses e
             LEFT JOIN purchases p ON p.farm_id = e.farm_id AND p.expense_id = e.id
             WHERE e.farm_id = :farm_id AND e.id = :id AND e.status = 'posted'
               AND e.currency_code = 'XOF' AND p.id IS NULL
             LIMIT 1"
        );
        $statement->execute(['farm_id' => $farmId, 'id' => $expenseId]);
        $expense = $statement->fetch();
        if (!is_array($expense)) {
            throw new RuntimeException('Dépense modifiable introuvable.');
        }
        $expense['amount'] = (string) (int) $expense['amount'];
        return $expense;
    }

    public static function receipt(PDO $pdo, int $userId, int $farmId, int $expenseId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'expense.view');
        $statement = $pdo->prepare(
            "SELECT receipt_file_name, receipt_storage_key, receipt_mime_type
             FROM expenses
             WHERE farm_id = :farm_id AND id = :id
               AND status = 'posted' AND receipt_storage_key IS NOT NULL
             LIMIT 1"
        );
        $statement->execute(['farm_id' => $farmId, 'id' => $expenseId]);
        $receipt = $statement->fetch();
        if (!is_array($receipt)) {
            throw new RuntimeException('Justificatif introuvable.');
        }
        return $receipt;
    }

    private static function actuals(
        PDO $pdo,
        int $farmId,
        string $from,
        string $to,
        ?int $flockId
    ): array {
        $cashFlock = $flockId === null ? '' : ' AND e.flock_id = :cash_flock_id';
        $chargeFlock = $flockId === null ? '' : ' AND e.flock_id = :charge_flock_id';
        $investmentFlock = $flockId === null ? '' : ' AND e.flock_id = :investment_flock_id';
        $movementFlock = $flockId === null ? '' : ' AND sm.flock_id = :movement_flock_id';
        $purchaseFlock = $flockId === null ? '' : ' AND pu.flock_id = :purchase_flock_id';
        $sql = "SELECT 'cash' AS financial_type, e.category,
                       CAST(ROUND(SUM(e.amount), 0) AS UNSIGNED) AS amount
                FROM expenses e
                WHERE e.farm_id = :cash_farm_id AND e.status = 'posted'
                  AND e.currency_code = 'XOF'
                  AND e.incurred_on BETWEEN :cash_from AND :cash_to $cashFlock
                GROUP BY e.category
                UNION ALL
                SELECT 'consumed', e.category,
                       CAST(ROUND(SUM(e.amount), 0) AS UNSIGNED)
                FROM expenses e
                WHERE e.farm_id = :charge_farm_id AND e.status = 'posted'
                  AND e.currency_code = 'XOF'
                  AND e.expense_type IN ('consumable', 'charge')
                  AND e.incurred_on BETWEEN :charge_from AND :charge_to $chargeFlock
                GROUP BY e.category
                UNION ALL
                SELECT 'consumed', pr.category,
                       CAST(ROUND(SUM(ABS(sm.quantity_delta) * pi.unit_price), 0) AS UNSIGNED)
                FROM stock_movements sm
                INNER JOIN stock_lots l
                  ON l.farm_id = sm.farm_id AND l.id = sm.lot_id AND l.product_id = sm.product_id
                INNER JOIN purchase_items pi
                  ON pi.farm_id = l.farm_id AND pi.lot_id = l.id AND pi.product_id = l.product_id
                INNER JOIN products pr
                  ON pr.farm_id = sm.farm_id AND pr.id = sm.product_id
                WHERE sm.farm_id = :consumption_farm_id AND sm.movement_type = 'consumption'
                  AND sm.occurred_at >= CONCAT(:consumption_from, ' 00:00:00')
                  AND sm.occurred_at < DATE_ADD(:consumption_to, INTERVAL 1 DAY)
                  $movementFlock
                GROUP BY pr.category
                UNION ALL
                SELECT 'investment', e.category,
                       CAST(ROUND(SUM(e.amount), 0) AS UNSIGNED)
                FROM expenses e
                WHERE e.farm_id = :investment_farm_id AND e.status = 'posted'
                  AND e.currency_code = 'XOF' AND e.expense_type = 'investment'
                  AND e.incurred_on BETWEEN :investment_from AND :investment_to $investmentFlock
                GROUP BY e.category
                UNION ALL
                SELECT 'investment', pr.category,
                       CAST(ROUND(SUM(pi.line_total), 0) AS UNSIGNED)
                FROM purchases pu
                INNER JOIN expenses e ON e.farm_id = pu.farm_id AND e.id = pu.expense_id
                INNER JOIN purchase_items pi ON pi.farm_id = pu.farm_id AND pi.purchase_id = pu.id
                INNER JOIN products pr ON pr.farm_id = pi.farm_id AND pr.id = pi.product_id
                WHERE pu.farm_id = :equipment_farm_id AND e.status = 'posted'
                  AND pu.currency_code = 'XOF' AND pr.product_type = 'equipment'
                  AND pu.purchased_on BETWEEN :equipment_from AND :equipment_to
                  $purchaseFlock
                GROUP BY pr.category";
        $statement = $pdo->prepare($sql);
        $parameters = [
            'cash_farm_id' => $farmId,
            'cash_from' => $from,
            'cash_to' => $to,
            'charge_farm_id' => $farmId,
            'charge_from' => $from,
            'charge_to' => $to,
            'consumption_farm_id' => $farmId,
            'consumption_from' => $from,
            'consumption_to' => $to,
            'investment_farm_id' => $farmId,
            'investment_from' => $from,
            'investment_to' => $to,
            'equipment_farm_id' => $farmId,
            'equipment_from' => $from,
            'equipment_to' => $to,
        ];
        if ($flockId !== null) {
            $parameters += [
                'cash_flock_id' => $flockId,
                'charge_flock_id' => $flockId,
                'investment_flock_id' => $flockId,
                'movement_flock_id' => $flockId,
                'purchase_flock_id' => $flockId,
            ];
        }
        $statement->execute($parameters);
        $actuals = [];
        foreach ($statement->fetchAll() as $row) {
            $key = $row['financial_type'] . ':' . $row['category'];
            $actuals[$key] = ($actuals[$key] ?? 0) + (int) $row['amount'];
        }
        return $actuals;
    }

    private static function lockFlock(PDO $pdo, int $farmId, int $flockId): void
    {
        $statement = $pdo->prepare(
            'SELECT id FROM flocks WHERE farm_id = :farm_id AND id = :id FOR UPDATE'
        );
        $statement->execute(['farm_id' => $farmId, 'id' => $flockId]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('L’élevage choisi n’appartient pas à cette exploitation.');
        }
    }

    private static function requireValid(array $validated): void
    {
        if ($validated['errors'] !== []) {
            throw new RuntimeException(implode(' ', $validated['errors']));
        }
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
