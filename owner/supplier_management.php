<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if (!$shop) {
    die('No shop assigned to this owner. Please contact support.');
}

$shop_id = (int) $shop['id'];
$success = '';
$error = '';
$editing_supplier = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_supplier') {
        $name = trim($_POST['name'] ?? '');
        $business_address = trim($_POST['business_address'] ?? '');
        $tin_permits = trim($_POST['tin_permits'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone_mobile = trim($_POST['phone_mobile'] ?? '');
        $email_address = trim($_POST['email_address'] ?? '');
        $social_viber = trim($_POST['social_viber'] ?? '');
        $item_category = trim($_POST['item_category'] ?? '');
        $price_list = trim($_POST['price_list'] ?? '');
        $moq = trim($_POST['moq'] ?? '');
        $stock_availability = trim($_POST['stock_availability'] ?? '');
        $rating = $_POST['rating'] !== '' ? (float) $_POST['rating'] : null;
        $status = $_POST['status'] ?? 'active';

        if ($name === '') {
            $error = 'Supplier name is required.';
        } else {
            $contact = implode(' | ', array_filter([$contact_person, $phone_mobile, $email_address]));
            $stmt = $pdo->prepare("\n                INSERT INTO suppliers (shop_id, name, contact, address, business_address, tin_permits, contact_person, phone_mobile, email_address, social_viber, item_category, price_list, moq, stock_availability, rating, status)\n                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n            ");
            $stmt->execute([
                $shop_id,
                $name,
                $contact !== '' ? $contact : null,
                $business_address !== '' ? $business_address : null,
                $business_address !== '' ? $business_address : null,
                $tin_permits !== '' ? $tin_permits : null,
                $contact_person !== '' ? $contact_person : null,
                $phone_mobile !== '' ? $phone_mobile : null,
                $email_address !== '' ? $email_address : null,
                $social_viber !== '' ? $social_viber : null,
                $item_category !== '' ? $item_category : null,
                $price_list !== '' ? $price_list : null,
                $moq !== '' ? $moq : null,
                $stock_availability !== '' ? $stock_availability : null,
                $rating,
                $status,
            ]);
            $success = 'Supplier added successfully.';
        }
    }

    if ($action === 'update_supplier') {
        $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $business_address = trim($_POST['business_address'] ?? '');
        $tin_permits = trim($_POST['tin_permits'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone_mobile = trim($_POST['phone_mobile'] ?? '');
        $email_address = trim($_POST['email_address'] ?? '');
        $social_viber = trim($_POST['social_viber'] ?? '');
        $item_category = trim($_POST['item_category'] ?? '');
        $price_list = trim($_POST['price_list'] ?? '');
        $moq = trim($_POST['moq'] ?? '');
        $stock_availability = trim($_POST['stock_availability'] ?? '');
        $rating = $_POST['rating'] !== '' ? (float) $_POST['rating'] : null;
        $status = $_POST['status'] ?? 'active';

        if ($supplier_id <= 0 || $name === '') {
            $error = 'Please provide the required supplier details.';
        } else {
            $contact = implode(' | ', array_filter([$contact_person, $phone_mobile, $email_address]));
            $stmt = $pdo->prepare("\n                UPDATE suppliers\n                SET name = ?, contact = ?, address = ?, business_address = ?, tin_permits = ?, contact_person = ?, phone_mobile = ?, email_address = ?, social_viber = ?, item_category = ?, price_list = ?, moq = ?, stock_availability = ?, rating = ?, status = ?\n                WHERE id = ? AND shop_id = ?\n            ");
            $stmt->execute([
                $name,
                $contact !== '' ? $contact : null,
                $business_address !== '' ? $business_address : null,
                $business_address !== '' ? $business_address : null,
                $tin_permits !== '' ? $tin_permits : null,
                $contact_person !== '' ? $contact_person : null,
                $phone_mobile !== '' ? $phone_mobile : null,
                $email_address !== '' ? $email_address : null,
                $social_viber !== '' ? $social_viber : null,
                $item_category !== '' ? $item_category : null,
                $price_list !== '' ? $price_list : null,
                $moq !== '' ? $moq : null,
                $stock_availability !== '' ? $stock_availability : null,
                $rating,
                $status,
                $supplier_id,
                $shop_id,
            ]);
            $success = 'Supplier updated successfully.';
        }
    }

    if ($action === 'delete_supplier') {
        $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
        if ($supplier_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ? AND shop_id = ?");
            $stmt->execute([$supplier_id, $shop_id]);
            $success = 'Supplier removed successfully.';
        } else {
            $error = 'Unable to delete the selected supplier.';
        }
    }
}

if (isset($_GET['edit_supplier'])) {
    $edit_id = (int) $_GET['edit_supplier'];
    if ($edit_id > 0) {
        $edit_stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ? AND shop_id = ?");
        $edit_stmt->execute([$edit_id, $shop_id]);
        $editing_supplier = $edit_stmt->fetch();
    }
}

$suppliers_stmt = $pdo->prepare("SELECT * FROM suppliers WHERE shop_id = ? ORDER BY created_at DESC");
$suppliers_stmt->execute([$shop_id]);
$suppliers = $suppliers_stmt->fetchAll();

$kpi_stmt = $pdo->prepare("\n    SELECT\n        COUNT(*) as supplier_count,\n        SUM(CASE WHEN status IN ('active','preferred') THEN 1 ELSE 0 END) as active_count\n    FROM suppliers\n    WHERE shop_id = ?\n");
$kpi_stmt->execute([$shop_id]);
$supplier_counts = $kpi_stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Management Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .supplier-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 1.5rem; margin: 2rem 0; }
        .supplier-kpi { grid-column: span 3; }
        .purpose-card, .scorecard-card { grid-column: span 12; }
        .form-card { grid-column: span 12; }
        .form-section { border: 1px solid var(--gray-200); border-radius: var(--radius); padding: 1rem; margin-bottom: 1rem; }
        .form-section h4 { margin-bottom: 0.75rem; }
        .field-help { display: block; color: var(--gray-600); font-size: 0.85rem; margin-top: 0.25rem; }
    </style>
</head>
<body>
    <?php include __DIR__ . "/includes/owner_navbar.php"; ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Supplier Management</h2>
                     <p class="text-muted">Owners can manage supplier records and maintain complete sourcing details for procurement planning.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-handshake"></i> Module 23</span>
            </div>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="supplier-grid">
            <div class="card purpose-card">
                <div class="card-header"><h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3></div>
                <p class="text-muted mb-0">Capture complete supplier business, contact, and product pricing references in one record.</p>
            </div>

            <div class="card supplier-kpi">
                <div class="kpi-item">
                    <div><p class="text-muted mb-1">Total suppliers</p><h3 class="mb-1"><?php echo (int) ($supplier_counts['supplier_count'] ?? 0); ?></h3></div>
                    <span class="badge badge-info"><i class="fas fa-truck-ramp-box"></i></span>
                </div>
            </div>
            <div class="card supplier-kpi">
                <div class="kpi-item">
                    <div><p class="text-muted mb-1">Active suppliers</p><h3 class="mb-1"><?php echo (int) ($supplier_counts['active_count'] ?? 0); ?></h3></div>
                    <span class="badge badge-success"><i class="fas fa-circle-check"></i></span>
                </div>
            </div>

            <div class="card form-card">
                <div class="card-header">
                    <h3><i class="fas fa-user-plus text-primary"></i> <?php echo $editing_supplier ? 'Update Supplier' : 'Add Supplier'; ?></h3>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="<?php echo $editing_supplier ? 'update_supplier' : 'create_supplier'; ?>">
                    <?php if ($editing_supplier): ?><input type="hidden" name="supplier_id" value="<?php echo (int) $editing_supplier['id']; ?>"><?php endif; ?>

                    <div class="form-section">
                        <h4>Section 1: Business Identity</h4>
                        <div class="form-grid">
                            <div class="form-group"><label>Supplier Name</label><input type="text" name="name" value="<?php echo htmlspecialchars($editing_supplier['name'] ?? ''); ?>" required><small class="field-help">Company name</small></div>
                            <div class="form-group"><label>Business Address</label><input type="text" name="business_address" value="<?php echo htmlspecialchars($editing_supplier['business_address'] ?? $editing_supplier['address'] ?? ''); ?>"><small class="field-help">Office and Warehouse locations</small></div>
                            <div class="form-group"><label>TIN / Business Permits</label><input type="text" name="tin_permits" value="<?php echo htmlspecialchars($editing_supplier['tin_permits'] ?? ''); ?>"><small class="field-help">For official receipts</small></div>
                        </div>
                        </div>

                    <div class="form-section">
                        <h4>Section 2: Contact Information</h4>
                        <div class="form-grid">
                            <div class="form-group"><label>Contact Person</label><input type="text" name="contact_person" value="<?php echo htmlspecialchars($editing_supplier['contact_person'] ?? ''); ?>"><small class="field-help">Account Manager/Agent</small></div>
                            <div class="form-group"><label>Phone/Mobile Number</label><input type="text" name="phone_mobile" value="<?php echo htmlspecialchars($editing_supplier['phone_mobile'] ?? ''); ?>"></div>
                            <div class="form-group"><label>Email Address</label><input type="email" name="email_address" value="<?php echo htmlspecialchars($editing_supplier['email_address'] ?? ''); ?>"></div>
                            <div class="form-group"><label>Social Media/Viber</label><input type="text" name="social_viber" value="<?php echo htmlspecialchars($editing_supplier['social_viber'] ?? ''); ?>"><small class="field-help">For quick orders</small></div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h4>Section 3: Products &amp; Pricing</h4>
                        <div class="form-grid">
                            <div class="form-group"><label>Item Category</label><input type="text" name="item_category" value="<?php echo htmlspecialchars($editing_supplier['item_category'] ?? ''); ?>"><small class="field-help">e.g., Fabrics, Threads, Needles, Stabilizers</small></div>
                            <div class="form-group"><label>Price List</label><input type="text" name="price_list" value="<?php echo htmlspecialchars($editing_supplier['price_list'] ?? ''); ?>"><small class="field-help">Wholesale and Retail rates</small></div>
                            <div class="form-group"><label>Minimum Order Quantity / MOQ</label><input type="text" name="moq" value="<?php echo htmlspecialchars($editing_supplier['moq'] ?? ''); ?>"><small class="field-help">e.g., minimum of 1 roll or 12 cones</small></div>
                            <div class="form-group"><label>Stock Availability</label><input type="text" name="stock_availability" value="<?php echo htmlspecialchars($editing_supplier['stock_availability'] ?? ''); ?>"><small class="field-help">Do they always have stock or is it pre-order?</small></div>
                        </div>
                        </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Rating</label>
                            <input type="number" step="0.1" name="rating" value="<?php echo htmlspecialchars($editing_supplier['rating'] ?? ''); ?>" placeholder="4.5">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <?php $status_options = ['active' => 'Active', 'preferred' => 'Preferred', 'watchlist' => 'Watchlist', 'inactive' => 'Inactive']; $current_status = $editing_supplier['status'] ?? 'active'; ?>
                                <?php foreach ($status_options as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $current_status === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex justify-between align-center">
                        <button type="submit" class="btn btn-primary"><?php echo $editing_supplier ? 'Update Supplier' : 'Create Supplier'; ?></button>
                        <?php if ($editing_supplier): ?><a href="supplier_management.php" class="btn btn-light">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card scorecard-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line text-primary"></i> Supplier Listing</h3>
                    <p class="text-muted">Review and manage supplier profiles.</p>
                </div>
                <table class="table">
                    <thead><tr><th>Supplier</th><th>Contact Person</th><th>Phone</th><th>Email</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($suppliers)): ?>
                            <tr><td colspan="6" class="text-muted">No suppliers added yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($suppliers as $supplier): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($supplier['name']); ?></td>
                                <td><?php echo htmlspecialchars($supplier['contact_person'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($supplier['phone_mobile'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($supplier['email_address'] ?? '—'); ?></td>
                                <td><span class="badge badge-info"><?php echo htmlspecialchars(ucfirst($supplier['status'] ?? 'active')); ?></span></td>
                                <td>
                                    <a href="supplier_management.php?edit_supplier=<?php echo (int) $supplier['id']; ?>" class="btn btn-sm btn-light">Edit</a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove this supplier?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_supplier">
                                        <input type="hidden" name="supplier_id" value="<?php echo (int) $supplier['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
