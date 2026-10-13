<?php
require_once __DIR__ . '/../includes/functions.php';
require_login('Operator');

$pdo = db();
$user = current_user();
$page_title = 'Record Shop Expense';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
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
    redirect('/POS_software/operator/expenses.php');
}

// Fetch today's expenses recorded by operator
$todayExpenses = $pdo->prepare("SELECT * FROM expenses WHERE date = CURDATE() ORDER BY id DESC");
$todayExpenses->execute();
$expenses = $todayExpenses->fetchAll();

$todayTotal = 0;
foreach ($expenses as $ex) {
    $todayTotal += (float) $ex['amount'];
}

include __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-6">
        <div class="card">
            <div class="card-header">
                <h3>Record New Expense (Petty Cash / Store)</h3>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

                    <div class="form-group">
                        <label>Expense Category / Title <span class="text-danger">*</span></label>
                        <input type="text" name="category_title" class="form-control" placeholder="e.g. Staff Tea & Lunch, Cleaning, Fuel" required list="expenseSuggestions">
                        <datalist id="expenseSuggestions">
                            <option value="Staff Tea & Lunch">
                            <option value="Cleaning & Supplies">
                            <option value="Delivery / Fuel">
                            <option value="Shop Supplies / Bags">
                            <option value="Maintenance / Minor Repairs">
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
                                <option value="Bank">Bank Account</option>
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
                            <input type="text" name="notes" class="form-control" placeholder="Optional remarks">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-danger btn-block">Save Expense</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-6">
        <div class="card">
            <div class="card-header">
                <h3>Today's Recorded Expenses</h3>
                <span class="badge badge-danger">Total: Rs <?= money($todayTotal) ?></span>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Mode</th>
                                <th class="text-right">Amount</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($expenses)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted" style="padding: 30px;">
                                        No expenses recorded yet today.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($expenses as $ex): ?>
                                    <tr>
                                        <td><strong><?= e($ex['category_title']) ?></strong></td>
                                        <td><span class="badge badge-<?= $ex['payment_mode'] === 'Cash' ? 'success' : 'info' ?>"><?= e($ex['payment_mode']) ?></span></td>
                                        <td class="text-right font-bold text-danger">Rs <?= money($ex['amount']) ?></td>
                                        <td><small class="text-muted"><?= e($ex['notes'] ?: '—') ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
