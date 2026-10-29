<?php
require_once __DIR__ . '/../includes/functions.php';
require_login(); // accessible by Admin and Operator

$pdo = db();
$user = current_user();
$isAdmin = is_admin();
$page_title = 'Daily Expense Management';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 'add');

    if ($action === 'add') {
        $title = post_string('category_title');
        $amount = post_float('amount');
        $mode = post_string('payment_mode', 'Cash');
        $date = post_string('date', date('Y-m-d'));
        $notes = post_string('notes');

        if ($title && $amount > 0) {
            $stmt = $pdo->prepare("INSERT INTO expenses (category_title, amount, payment_mode, date, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $amount, $mode, $date, $notes, $user['id']]);
            flash('success', "Expense of Rs " . money($amount) . " for '$title' recorded successfully.");
        } else {
            flash('error', 'Please enter a valid expense title and amount.');
        }
    } elseif ($action === 'edit' && $isAdmin) {
        $id = post_int('id');
        $title = post_string('category_title');
        $amount = post_float('amount');
        $mode = post_string('payment_mode', 'Cash');
        $date = post_string('date');
        $notes = post_string('notes');

        if ($id && $title && $amount > 0) {
            $stmt = $pdo->prepare("UPDATE expenses SET category_title = ?, amount = ?, payment_mode = ?, date = ?, notes = ? WHERE id = ?");
            $stmt->execute([$title, $amount, $mode, $date, $notes, $id]);
            flash('success', 'Expense updated successfully.');
        }
    } elseif ($action === 'delete' && $isAdmin) {
        $id = post_int('id');
        if ($id) {
            $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);
            flash('success', 'Expense deleted successfully.');
        }
    }

    redirect($isAdmin ? '/admin/expenses.php' : '/operator/expenses.php');
}

// Summary Metrics
$todayExpenses = $pdo->query("SELECT 
    COALESCE(SUM(amount), 0) as total,
    COALESCE(SUM(CASE WHEN payment_mode = 'Cash' THEN amount ELSE 0 END), 0) as cash_part,
    COALESCE(SUM(CASE WHEN payment_mode = 'Bank' THEN amount ELSE 0 END), 0) as bank_part
    FROM expenses WHERE date = CURDATE()")->fetch();

$monthExpenses = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())")->fetchColumn();
$allTimeExpenses = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses")->fetchColumn();

// Filter
$dateFilter = $_GET['filter'] ?? '';
$where = '';
if ($dateFilter === 'today') {
    $where = "WHERE e.date = CURDATE()";
} elseif ($dateFilter === 'month') {
    $where = "WHERE MONTH(e.date) = MONTH(CURDATE()) AND YEAR(e.date) = YEAR(CURDATE())";
}

$expenses = $pdo->query("SELECT e.*, u.username as recorded_by_user 
    FROM expenses e 
    LEFT JOIN users u ON u.id = e.recorded_by 
    $where 
    ORDER BY e.date DESC, e.id DESC")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- Metrics Row -->
<div class="stats-row" style="margin-bottom: 20px;">
    <div class="stat-box danger">
        <div class="stat-box-header"><span class="stat-title">Today's Expenses</span></div>
        <div class="stat-value text-danger">Rs <?= money($todayExpenses['total']) ?></div>
        <div class="stat-desc">
            Cash: <strong>Rs <?= money($todayExpenses['cash_part']) ?></strong> · Bank: <strong>Rs <?= money($todayExpenses['bank_part']) ?></strong>
        </div>
    </div>
    <div class="stat-box warning">
        <div class="stat-box-header"><span class="stat-title">This Month's Expenses</span></div>
        <div class="stat-value text-warning">Rs <?= money($monthExpenses) ?></div>
        <div class="stat-desc"><?= date('F Y') ?> Total</div>
    </div>
    <div class="stat-box primary">
        <div class="stat-box-header"><span class="stat-title">All-Time Recorded</span></div>
        <div class="stat-value">Rs <?= money($allTimeExpenses) ?></div>
        <div class="stat-desc">Cumulative store expenditures</div>
    </div>
</div>

<!-- Toolbar -->
<div class="filter-bar">
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <div class="search-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" data-table-search="[data-expense-row]" placeholder="Search expense title, notes...">
        </div>

        <div style="display: flex; gap: 6px;">
            <a href="<?= url('/admin/expenses.php') ?>" class="btn btn-sm <?= empty($dateFilter) ? 'btn-primary' : 'btn-light' ?>">All Expenses</a>
            <a href="<?= url('/admin/expenses.php?filter=today') ?>" class="btn btn-sm <?= $dateFilter === 'today' ? 'btn-danger' : 'btn-light' ?>">Today Only</a>
            <a href="<?= url('/admin/expenses.php?filter=month') ?>" class="btn btn-sm <?= $dateFilter === 'month' ? 'btn-warning' : 'btn-light' ?>">This Month</a>
        </div>
    </div>

    <div>
        <button type="button" class="btn btn-danger" data-modal-open="addExpenseModal">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            + Record New Expense
        </button>
    </div>
</div>

<!-- Expenses Table -->
<div class="card">
    <div class="card-header">
        <h3>Store Expense Ledger (<?= count($expenses) ?> items)</h3>
        <span class="text-muted" style="font-size: 12px;">Cash expenses are subtracted from drawer till estimate</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Expense Title / Category</th>
                        <th>Date</th>
                        <th>Payment Mode</th>
                        <th class="text-right">Amount</th>
                        <th>Recorded By</th>
                        <th>Remarks / Notes</th>
                        <?php if ($isAdmin): ?>
                            <th class="text-right">Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expenses)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted" style="padding: 35px;">
                                No expenses recorded yet. Click <strong>+ Record New Expense</strong> to log shop expenses.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expenses as $ex): ?>
                            <tr data-expense-row>
                                <td><strong><?= e($ex['category_title']) ?></strong></td>
                                <td><?= date('d M Y', strtotime($ex['date'])) ?></td>
                                <td>
                                    <span class="badge badge-<?= $ex['payment_mode'] === 'Cash' ? 'success' : 'info' ?>">
                                        <?= e($ex['payment_mode']) ?>
                                    </span>
                                </td>
                                <td class="text-right font-bold text-danger" style="font-size: 14px;">
                                    Rs <?= money($ex['amount']) ?>
                                </td>
                                <td><?= e($ex['recorded_by_user'] ?? 'System') ?></td>
                                <td><small class="text-muted"><?= e($ex['notes'] ?: '—') ?></small></td>
                                <?php if ($isAdmin): ?>
                                    <td class="text-right">
                                        <div style="display: inline-flex; gap: 6px;">
                                            <button type="button" class="btn btn-sm btn-light" 
                                                onclick="openEditExpenseModal(<?= htmlspecialchars(json_encode($ex), ENT_QUOTES, 'UTF-8') ?>)">
                                                Edit
                                            </button>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this expense?');">
                                                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">✕</button>
                                            </form>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==================== ADD EXPENSE MODAL ==================== -->
<div id="addExpenseModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Record New Shop Expense</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add">

                <div class="form-group">
                    <label>Expense Category / Title <span class="text-danger">*</span></label>
                    <input type="text" name="category_title" class="form-control" placeholder="e.g. Staff Tea & Lunch, Shop Electricity Bill, Shop Rent, Delivery Fuel" required list="expenseSuggestions">
                    <datalist id="expenseSuggestions">
                        <option value="Staff Tea & Lunch">
                        <option value="Shop Rent">
                        <option value="Electricity Bill">
                        <option value="Internet / Phone Bill">
                        <option value="Cleaning & Supplies">
                        <option value="Delivery / Fuel">
                        <option value="Repairs & Maintenance">
                        <option value="Packaging Bags">
                        <option value="Miscellaneous">
                    </datalist>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Amount (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" class="form-control" placeholder="Enter amount" required>
                    </div>

                    <div class="form-group">
                        <label>Payment Mode</label>
                        <select name="payment_mode" class="form-control" required>
                            <option value="Cash">Cash (Deducts from Till Drawer)</option>
                            <option value="Bank">Bank Account / Card / Cheque</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Expense Date</label>
                        <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Remarks / Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional notes or receipt #">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-danger">Save Expense</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== EDIT EXPENSE MODAL ==================== -->
<?php if ($isAdmin): ?>
<div id="editExpenseModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Expense</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_expense_id">

                <div class="form-group">
                    <label>Expense Title / Category <span class="text-danger">*</span></label>
                    <input type="text" name="category_title" id="edit_category_title" class="form-control" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Amount (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" id="edit_amount" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Payment Mode</label>
                        <select name="payment_mode" id="edit_payment_mode" class="form-control" required>
                            <option value="Cash">Cash</option>
                            <option value="Bank">Bank</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Expense Date</label>
                        <input type="date" name="date" id="edit_date" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Remarks / Notes</label>
                        <input type="text" name="notes" id="edit_notes" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Update Expense</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openEditExpenseModal(item) {
    document.getElementById('edit_expense_id').value = item.id;
    document.getElementById('edit_category_title').value = item.category_title;
    document.getElementById('edit_amount').value = item.amount;
    document.getElementById('edit_payment_mode').value = item.payment_mode;
    document.getElementById('edit_date').value = item.date;
    document.getElementById('edit_notes').value = item.notes || '';
    document.getElementById('editExpenseModal').classList.add('active');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
