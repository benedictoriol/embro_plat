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
$editing_material = null;

$category_options = ['Threads', 'Backing', 'Needles', 'Apparel Blanks'];
$unit_options = ['Cones', 'Rolls', 'Boxes', 'Pieces', 'Yards'];
$size_options = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
$material_optional_columns = [
    'sku_internal_id',
    'color_code',
    'storage_location',
    'retail_price',
    'lead_time_days',
    'style_number',
    'size',
    'fabric_type',
];
$material_column_availability = [];
foreach ($material_optional_columns as $column_name) {
    $material_column_availability[$column_name] = column_exists($pdo, 'raw_materials', $column_name);
}

function resolve_material_category(array $post_data, array $category_options): array
{
    $category_selection = trim($post_data['category_selection'] ?? '');
    $custom_category = trim($post_data['category_custom'] ?? '');

    if ($category_selection === 'add_new') {
        $resolved_category = $custom_category;
    } elseif (in_array($category_selection, $category_options, true)) {
        $resolved_category = $category_selection;
    } else {
        $resolved_category = $custom_category;
    }

    return [$resolved_category, $category_selection, $custom_category];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_material') {
        $name = trim($_POST['name'] ?? '');
        [$category, $category_selection, $category_custom] = resolve_material_category($_POST, $category_options);
        $unit = trim($_POST['unit'] ?? '');
        $current_stock = $_POST['current_stock'] ?? '';
        $min_stock_level = $_POST['min_stock_level'] ?? null;
        $max_stock_level = $_POST['max_stock_level'] ?? null;
        $unit_cost = $_POST['unit_cost'] ?? null;
        $supplier = trim($_POST['supplier'] ?? '');
        $sku_internal_id = trim($_POST['sku_internal_id'] ?? '');
        $color_code = trim($_POST['color_code'] ?? '');
        $storage_location = trim($_POST['storage_location'] ?? '');
        $retail_price = $_POST['retail_price'] ?? null;
        $lead_time_days = $_POST['lead_time_days'] ?? null;
        $style_number = trim($_POST['style_number'] ?? '');
        $size = trim($_POST['size'] ?? '');
        $fabric_type = trim($_POST['fabric_type'] ?? '');
        $status = $_POST['status'] ?? 'active';

        $is_apparel = $category === 'Apparel Blanks';
        if (!$is_apparel) {
            $style_number = '';
            $size = '';
            $fabric_type = '';
        }

        if ($name === '' || $unit === '' || $current_stock === '' || $category === '') {
            $error = 'Please provide item name & brand, category, unit, and initial quantity.';
        } elseif (!is_numeric($current_stock)) {
            $error = 'Initial quantity must be a valid number.';
        } elseif ($lead_time_days !== '' && $lead_time_days !== null && !is_numeric($lead_time_days)) {
            $error = 'Lead time must be a valid number of days.';
        } else {
            $create_data = [
                $shop_id,
                $name,
                $category !== '' ? $category : null,
                $unit,
                $current_stock,
                $min_stock_level !== '' ? $min_stock_level : null,
                $max_stock_level !== '' ? $max_stock_level : null,
                $unit_cost !== '' ? $unit_cost : null,
                $supplier !== '' ? $supplier : null,
                $status,
                ];
            $create_columns = ['shop_id', 'name', 'category', 'unit', 'current_stock', 'min_stock_level', 'max_stock_level', 'unit_cost', 'supplier', 'status'];

            $optional_create_data = [
                'sku_internal_id' => $sku_internal_id !== '' ? $sku_internal_id : null,
                'color_code' => $color_code !== '' ? $color_code : null,
                'storage_location' => $storage_location !== '' ? $storage_location : null,
                'retail_price' => $retail_price !== '' ? $retail_price : null,
                'lead_time_days' => $lead_time_days !== '' ? $lead_time_days : null,
                'style_number' => $style_number !== '' ? $style_number : null,
                'size' => $size !== '' ? $size : null,
                'fabric_type' => $fabric_type !== '' ? $fabric_type : null,
            ];
            foreach ($optional_create_data as $column => $value) {
                if (!($material_column_availability[$column] ?? false)) {
                    continue;
                }

                $create_columns[] = $column;
                $create_data[] = $value;
            }

            $placeholders = implode(', ', array_fill(0, count($create_columns), '?'));
            $stmt = $pdo->prepare("INSERT INTO raw_materials (" . implode(', ', $create_columns) . ") VALUES ({$placeholders})");
            $stmt->execute($create_data);
            $success = 'Raw material added successfully.';
        }
    }

    if ($action === 'update_material') {
        $material_id = (int) ($_POST['material_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        [$category, $category_selection, $category_custom] = resolve_material_category($_POST, $category_options);
        $unit = trim($_POST['unit'] ?? '');
        $current_stock = $_POST['current_stock'] ?? '';
        $min_stock_level = $_POST['min_stock_level'] ?? null;
        $max_stock_level = $_POST['max_stock_level'] ?? null;
        $unit_cost = $_POST['unit_cost'] ?? null;
        $supplier = trim($_POST['supplier'] ?? '');
        $sku_internal_id = trim($_POST['sku_internal_id'] ?? '');
        $color_code = trim($_POST['color_code'] ?? '');
        $storage_location = trim($_POST['storage_location'] ?? '');
        $retail_price = $_POST['retail_price'] ?? null;
        $lead_time_days = $_POST['lead_time_days'] ?? null;
        $style_number = trim($_POST['style_number'] ?? '');
        $size = trim($_POST['size'] ?? '');
        $fabric_type = trim($_POST['fabric_type'] ?? '');
        $status = $_POST['status'] ?? 'active';

        $is_apparel = $category === 'Apparel Blanks';
        if (!$is_apparel) {
            $style_number = '';
            $size = '';
            $fabric_type = '';
        }

        if ($material_id <= 0 || $name === '' || $unit === '' || $current_stock === '' || $category === '') {
            $error = 'Please provide all required fields to update the material.';
        } elseif (!is_numeric($current_stock)) {
            $error = 'Initial quantity must be a valid number.';
        } elseif ($lead_time_days !== '' && $lead_time_days !== null && !is_numeric($lead_time_days)) {
            $error = 'Lead time must be a valid number of days.';
        } else {
            $update_assignments = [
                'name = ?',
                'category = ?',
                'unit = ?',
                'current_stock = ?',
                'min_stock_level = ?',
                'max_stock_level = ?',
                'unit_cost = ?',
                'supplier = ?',
                'status = ?',
            ];
            $update_values = [
                $name,
                $category !== '' ? $category : null,
                $unit,
                $current_stock,
                $min_stock_level !== '' ? $min_stock_level : null,
                $max_stock_level !== '' ? $max_stock_level : null,
                $unit_cost !== '' ? $unit_cost : null,
                $supplier !== '' ? $supplier : null,
                $status,
                ];

            $optional_update_data = [
                'sku_internal_id' => $sku_internal_id !== '' ? $sku_internal_id : null,
                'color_code' => $color_code !== '' ? $color_code : null,
                'storage_location' => $storage_location !== '' ? $storage_location : null,
                'retail_price' => $retail_price !== '' ? $retail_price : null,
                'lead_time_days' => $lead_time_days !== '' ? $lead_time_days : null,
                'style_number' => $style_number !== '' ? $style_number : null,
                'size' => $size !== '' ? $size : null,
                'fabric_type' => $fabric_type !== '' ? $fabric_type : null,
            ];
            foreach ($optional_update_data as $column => $value) {
                if (!($material_column_availability[$column] ?? false)) {
                    continue;
                }

                $update_assignments[] = "{$column} = ?";
                $update_values[] = $value;
            }

            $update_values[] = $material_id;
            $update_values[] = $shop_id;

            $stmt = $pdo->prepare(
                "UPDATE raw_materials
                SET " . implode(', ', $update_assignments) . "
                WHERE id = ? AND shop_id = ?"
            );
            $stmt->execute($update_values);
            $success = 'Raw material updated successfully.';
        }
    }

    if ($action === 'delete_material') {
        $material_id = (int) ($_POST['material_id'] ?? 0);
        if ($material_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM raw_materials WHERE id = ? AND shop_id = ?");
            $stmt->execute([$material_id, $shop_id]);
            $success = 'Raw material removed successfully.';
        } else {
            $error = 'Unable to delete the selected material.';
        }
    }
}

if (isset($_GET['edit_id'])) {
    $edit_id = (int) $_GET['edit_id'];
    if ($edit_id > 0) {
        $edit_stmt = $pdo->prepare("SELECT * FROM raw_materials WHERE id = ? AND shop_id = ?");
        $edit_stmt->execute([$edit_id, $shop_id]);
        $editing_material = $edit_stmt->fetch();
    }
}

$editing_category = $editing_material['category'] ?? '';
$editing_category_selection = in_array($editing_category, $category_options, true) ? $editing_category : 'add_new';
$editing_category_custom = $editing_category_selection === 'add_new' ? $editing_category : '';

$materials_stmt = $pdo->prepare("SELECT * FROM raw_materials WHERE shop_id = ? ORDER BY created_at DESC");
$materials_stmt->execute([$shop_id]);
$materials = $materials_stmt->fetchAll();

$restock_automation = create_low_stock_supplier_drafts($pdo, $owner_id, $shop_id);

$inventory_value = 0.0;
$reorder_value = 0.0;
$low_stock_materials = [];

foreach ($materials as $material) {
    $current_stock = (float) $material['current_stock'];
    $unit_cost = $material['unit_cost'] !== null ? (float) $material['unit_cost'] : 0.0;
    $inventory_value += $current_stock * $unit_cost;

    if ($material['min_stock_level'] !== null && $current_stock <= (float) $material['min_stock_level']) {
        $low_stock_materials[] = $material;
        $deficit = (float) $material['min_stock_level'] - $current_stock;
        if ($deficit > 0) {
            $reorder_value += $deficit * $unit_cost;
        }
    }
}

$inventory_kpis = [
    [
        'label' => 'Materials tracked',
        'value' => count($materials),
        'note' => 'Active SKUs available to production.',
        'icon' => 'fas fa-boxes-stacked',
        'tone' => 'primary',
    ],
    [
        'label' => 'Low-stock items',
        'value' => count($low_stock_materials),
        'note' => 'Below or equal to reorder point.',
        'icon' => 'fas fa-triangle-exclamation',
        'tone' => 'warning',
    ],
    [
        'label' => 'On-hand value',
        'value' => '₱' . number_format($inventory_value, 2),
        'note' => 'Estimated value of materials on hand.',
        'icon' => 'fas fa-wallet',
        'tone' => 'info',
    ],
    [
        'label' => 'Days of coverage',
        'value' => '14',
        'note' => 'Average run rate coverage.',
        'icon' => 'fas fa-calendar-day',
        'tone' => 'success',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raw Material Inventory Management Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .inventory-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .inventory-kpi {
            grid-column: span 3;
        }

        .purpose-card {
            grid-column: span 12;
        }

        .form-card {
            grid-column: span 12;
        }

        .stock-card {
            grid-column: span 8;
        }

        .automation-card {
            grid-column: span 4;
        }

        .alerts-card {
            grid-column: span 12;
        }

        .kpi-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .kpi-item i {
            font-size: 1.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .form-grid .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-grid label {
            font-weight: 600;
            margin-bottom: 0.35rem;
        }

        .form-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            margin-top: 1rem;
        }

        .automation-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .automation-item + .automation-item {
            margin-top: 1rem;
        }

        .alert-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .alert-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }

        .alert-item i {
            color: var(--primary-600);
            margin-top: 0.25rem;
        }
        
        .stock-table td {
            vertical-align: top;
        }
        

        .form-section {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            margin-bottom: 1rem;
            background: #fff;
        }

        .form-section h4 {
            margin: 0 0 0.35rem 0;
            font-size: 1rem;
        }

        .form-section .section-subtitle {
            margin: 0 0 1rem 0;
            color: var(--gray-600);
            font-size: 0.85rem;
        }

        .hidden {
            display: none;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . "/includes/owner_navbar.php"; ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Raw Material Inventory Management</h2>
                    <p class="text-muted">Track embroidery materials with live stock visibility and automated replenishment support.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-warehouse"></i> Module 22</span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="inventory-grid">
            <div class="card purpose-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Tracks embroidery materials such as thread, stabilizers, and backing fabric while keeping stock levels aligned
                    with live production demand and supplier lead times.
                </p>
            </div>

            <?php foreach ($inventory_kpis as $kpi): ?>
                <div class="card inventory-kpi">
                    <div class="kpi-item">
                        <div>
                            <p class="text-muted mb-1"><?php echo $kpi['label']; ?></p>
                            <h3 class="mb-1"><?php echo $kpi['value']; ?></h3>
                            <small class="text-muted"><?php echo $kpi['note']; ?></small>
                        </div>
                        <span class="badge badge-<?php echo $kpi['tone']; ?>">
                            <i class="<?php echo $kpi['icon']; ?>"></i>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="card form-card">
                <div class="card-header">
                    <h3><i class="fas fa-pen text-primary"></i> <?php echo $editing_material ? 'Update Raw Material' : 'Add Raw Material'; ?></h3>
                    <p class="text-muted">Capture detailed inventory information with category-specific attributes.</p>
                </div>
                <form method="post" id="raw-material-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="<?php echo $editing_material ? 'update_material' : 'create_material'; ?>">
                    <?php if ($editing_material): ?>
                        <input type="hidden" name="material_id" value="<?php echo (int) $editing_material['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-section">
                        <h4>Section 1: Item Identification</h4>
                        <p class="section-subtitle">Classify, label, and identify each raw material record.</p>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="category_selection">Category *</label>
                                <select id="category_selection" name="category_selection" required>
                                    <option value="">Select category</option>
                                    <?php foreach ($category_options as $option): ?>
                                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $editing_category_selection === $option ? 'selected' : ''; ?>><?php echo htmlspecialchars($option); ?></option>
                                    <?php endforeach; ?>
                                    <option value="add_new" <?php echo $editing_category_selection === 'add_new' ? 'selected' : ''; ?>>Add New Category</option>
                                </select>
                            </div>
                            <div class="form-group <?php echo $editing_category_selection === 'add_new' ? '' : 'hidden'; ?>" id="category_custom_group">
                                <label for="category_custom">Custom Category *</label>
                                <input type="text" id="category_custom" name="category_custom" value="<?php echo htmlspecialchars($editing_category_custom); ?>" placeholder="e.g., Bobbins">
                            </div>
                            <div class="form-group">
                                <label for="name">Item Name &amp; Brand *</label>
                                <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($editing_material['name'] ?? ''); ?>" placeholder="e.g., Madeira Rayon 40wt">
                            </div>
                            <div class="form-group">
                                <label for="sku_internal_id">SKU / Internal ID</label>
                                <input type="text" id="sku_internal_id" name="sku_internal_id" value="<?php echo htmlspecialchars($editing_material['sku_internal_id'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="color_code">Color Name/Code</label>
                                <input type="text" id="color_code" name="color_code" value="<?php echo htmlspecialchars($editing_material['color_code'] ?? ''); ?>">
                            </div>
                        </div>
                        </div>

                    <div class="form-section">
                        <h4>Section 2: Stock &amp; Storage</h4>
                        <p class="section-subtitle">Set stock controls and where the material is physically stored.</p>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="unit">Unit of Measure *</label>
                                <select id="unit" name="unit" required>
                                    <option value="">Select unit</option>
                                    <?php foreach ($unit_options as $option): ?>
                                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo ($editing_material['unit'] ?? '') === $option ? 'selected' : ''; ?>><?php echo htmlspecialchars($option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="current_stock">Initial Quantity *</label>
                                <input type="number" step="0.01" id="current_stock" name="current_stock" required value="<?php echo htmlspecialchars($editing_material['current_stock'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="min_stock_level">Reorder Point</label>
                                <input type="number" step="0.01" id="min_stock_level" name="min_stock_level" value="<?php echo htmlspecialchars($editing_material['min_stock_level'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="storage_location">Storage Location</label>
                                <input type="text" id="storage_location" name="storage_location" value="<?php echo htmlspecialchars($editing_material['storage_location'] ?? ''); ?>" placeholder="Rack A / Shelf 2">
                            </div>
                            <div class="form-group">
                                <label for="max_stock_level">Max Stock Level</label>
                                <input type="number" step="0.01" id="max_stock_level" name="max_stock_level" value="<?php echo htmlspecialchars($editing_material['max_stock_level'] ?? ''); ?>">
                            </div>
                        </div>
                        </div>

                    <div class="form-section">
                        <h4>Section 3: Financial &amp; Vendor Details</h4>
                        <p class="section-subtitle">Capture costing, selling price, and supplier lead time data.</p>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="unit_cost">Unit Cost</label>
                                <input type="number" step="0.01" id="unit_cost" name="unit_cost" value="<?php echo htmlspecialchars($editing_material['unit_cost'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="retail_price">Retail Price</label>
                                <input type="number" step="0.01" id="retail_price" name="retail_price" value="<?php echo htmlspecialchars($editing_material['retail_price'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="supplier">Supplier Name</label>
                                <input type="text" id="supplier" name="supplier" value="<?php echo htmlspecialchars($editing_material['supplier'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="lead_time_days">Lead Time (days)</label>
                                <input type="number" min="0" step="1" id="lead_time_days" name="lead_time_days" value="<?php echo htmlspecialchars($editing_material['lead_time_days'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select id="status" name="status">
                                    <option value="active" <?php echo ($editing_material['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($editing_material['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>
                        </div>

                    <div class="form-section <?php echo $editing_category === 'Apparel Blanks' ? '' : 'hidden'; ?>" id="apparel-section">
                        <h4>Section 4: Apparel Specifics</h4>
                        <p class="section-subtitle">Visible only for category: Apparel Blanks.</p>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="style_number">Style Number</label>
                                <input type="text" id="style_number" name="style_number" value="<?php echo htmlspecialchars($editing_material['style_number'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="size">Size</label>
                                <select id="size" name="size">
                                    <option value="">Select size</option>
                                    <?php foreach ($size_options as $option): ?>
                                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo ($editing_material['size'] ?? '') === $option ? 'selected' : ''; ?>><?php echo htmlspecialchars($option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="fabric_type">Material/Fabric Type</label>
                                <input type="text" id="fabric_type" name="fabric_type" value="<?php echo htmlspecialchars($editing_material['fabric_type'] ?? ''); ?>" placeholder="Cotton, Polyester, Blend">
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <?php echo $editing_material ? 'Update Material' : 'Add Material'; ?>
                        </button>
                        <?php if ($editing_material): ?>
                            <a href="raw_material_inventory.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card stock-card">
                <div class="card-header">
                    <h3><i class="fas fa-layer-group text-primary"></i> Material Stock Levels</h3>
                    <p class="text-muted">Current on-hand quantities with reorder thresholds.</p>
                </div>
                <table class="table stock-table">
                    <thead>
                        <tr>
                            <th>Material</th>
                            <th>Category</th>
                            <th>SKU</th>
                            <th>On-hand</th>
                            <th>Reorder point</th>
                            <th>Unit cost</th>
                            <th>Retail</th>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$materials): ?>
                            <tr>
                                <td colspan="10" class="text-muted">No raw materials recorded yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($materials as $material): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($material['name']); ?></strong><br>
                                    <small class="text-muted">Unit: <?php echo htmlspecialchars($material['unit']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($material['category'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($material['sku_internal_id'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($material['current_stock']); ?></td>
                                <td><?php echo htmlspecialchars($material['min_stock_level'] ?? '—'); ?></td>
                                <td><?php echo $material['unit_cost'] !== null ? '₱' . number_format((float) $material['unit_cost'], 2) : '—'; ?></td>
                                <td><?php echo ($material['retail_price'] ?? null) !== null ? '₱' . number_format((float) $material['retail_price'], 2) : '—'; ?></td>
                                <td><?php echo htmlspecialchars($material['supplier'] ?? '—'); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $material['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo htmlspecialchars(ucfirst($material['status'])); ?>
                                    </span>
                                    <?php if ($material['min_stock_level'] !== null && (float) $material['current_stock'] <= (float) $material['min_stock_level']): ?>
                                        <span class="badge badge-warning">Low</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary" href="raw_material_inventory.php?edit_id=<?php echo (int) $material['id']; ?>">Edit</a>
                                    <form method="post" style="display:inline-block" onsubmit="return confirm('Remove this material from inventory?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_material">
                                        <input type="hidden" name="material_id" value="<?php echo (int) $material['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-gear text-primary"></i> Low-stock Alerts</h3>
                    <p class="text-muted">Materials that need replenishment soon.</p>
                </div>
                <?php if (!$low_stock_materials): ?>
                    <div class="automation-item">
                        <p class="text-muted mb-0">All tracked materials are above reorder point.</p>
                    </div>
                <?php endif; ?>
                <?php foreach ($low_stock_materials as $material): ?>
                    <div class="automation-item">
                        <div class="d-flex align-center gap-2 mb-2">
                            <i class="fas fa-triangle-exclamation text-warning"></i>
                            <strong><?php echo htmlspecialchars($material['name']); ?></strong>
                        </div>
                        <p class="text-muted mb-0">
                            On-hand: <?php echo htmlspecialchars($material['current_stock']); ?> <?php echo htmlspecialchars($material['unit']); ?> ·
                            Reorder point: <?php echo htmlspecialchars($material['min_stock_level']); ?> <?php echo htmlspecialchars($material['unit']); ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card alerts-card">
                <div class="card-header">
                    <h3><i class="fas fa-bell text-primary"></i> Actionable Notes</h3>
                    <p class="text-muted">Use this module as the source of truth for production inventory.</p>
                </div>
                <div class="alert-list">
                    <div class="alert-item">
                        <i class="fas fa-bell"></i>
                        <div>
                            <strong>Low-stock alerts</strong>
                            <p class="text-muted mb-0">Materials below reorder point appear immediately for owner follow-up.</p>
                        </div>
                    </div>
                    <div class="alert-item">
                        <i class="fas fa-clipboard-check"></i>
                        <div>
                            <strong>Inventory hygiene</strong>
                            <p class="text-muted mb-0">Update stock levels after every delivery or production batch.</p>
                        </div>
                    </div>
                    <div class="alert-item">
                        <i class="fas fa-box-open"></i>
                        <div>
                            <strong>Supplier coordination</strong>
                            <p class="text-muted mb-0">Track suppliers and unit costs to support smarter reorder decisions.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        (function () {
            const categorySelection = document.getElementById('category_selection');
            const categoryCustomGroup = document.getElementById('category_custom_group');
            const categoryCustom = document.getElementById('category_custom');
            const apparelSection = document.getElementById('apparel-section');
            const apparelFields = ['style_number', 'size', 'fabric_type'].map((id) => document.getElementById(id));

            function syncFormState() {
                const selectedCategory = categorySelection ? categorySelection.value : '';
                const showCustomCategory = selectedCategory === 'add_new';
                if (categoryCustomGroup) {
                    categoryCustomGroup.classList.toggle('hidden', !showCustomCategory);
                }
                if (categoryCustom) {
                    categoryCustom.required = showCustomCategory;
                }

                const showApparel = selectedCategory === 'Apparel Blanks';
                if (apparelSection) {
                    apparelSection.classList.toggle('hidden', !showApparel);
                }

                if (!showApparel) {
                    apparelFields.forEach((field) => {
                        if (field) {
                            field.value = '';
                        }
                    });
                }
            }

            if (categorySelection) {
                categorySelection.addEventListener('change', syncFormState);
                syncFormState();
            }
        })();
    </script>
</body>
</html>
