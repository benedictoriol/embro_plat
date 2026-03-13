<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = (int) $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$error_messages = [];
$success_message = '';

$profile_stmt = $pdo->prepare("SELECT fullname, email, phone, created_at, last_login, email_verified, phone_verified FROM users WHERE id = ? LIMIT 1");
$profile_stmt->execute([$client_id]);
$user_profile = $profile_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$stored_profile = fetch_client_profile($pdo, $client_id);
$addresses = fetch_client_addresses($pdo, $client_id);
$payment_preferences = fetch_client_payment_preferences($pdo, $client_id);

$full_name = trim((string) ($user_profile['fullname'] ?? ''));
$name_parts = preg_split('/\s+/', $full_name, -1, PREG_SPLIT_NO_EMPTY);
$first_name = $stored_profile['first_name'] ?? ($name_parts[0] ?? '');
$last_name = $stored_profile['last_name'] ?? '';
$middle_name = $stored_profile['middle_name'] ?? '';
if ($last_name === '' && count($name_parts) >= 2) {
    $last_name = array_pop($name_parts);
    $first_name = array_shift($name_parts) ?? $first_name;
    $middle_name = implode(' ', $name_parts);
}

$active_address = null;
$edit_address_id = (int) ($_GET['edit_address'] ?? 0);
if ($edit_address_id > 0) {
    foreach ($addresses as $row) {
        if ((int) $row['id'] === $edit_address_id) {
            $active_address = $row;
            break;
        }
    }
}
if (!$active_address) {
    $active_address = $addresses[0] ?? [
        'id' => 0,
        'label' => '',
        'recipient_name' => '',
        'phone' => (string) ($user_profile['phone'] ?? ''),
        'country' => 'Philippines',
        'province' => '',
        'city' => '',
        'barangay' => '',
        'street_address' => '',
        'address_line2' => '',
        'postal_code' => '',
        'is_default' => 1,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    try {
        if ($action === 'save_profile') {
            $first_name = sanitize($_POST['first_name'] ?? '');
            $middle_name = sanitize($_POST['middle_name'] ?? '');
            $last_name = sanitize($_POST['last_name'] ?? '');
            $phone = preg_replace('/[^\d+]/', '', (string) ($_POST['phone'] ?? ''));
            $billing_contact_name = sanitize($_POST['billing_contact_name'] ?? '');
            $billing_phone = preg_replace('/[^\d+]/', '', (string) ($_POST['billing_phone'] ?? ''));
            $billing_email = filter_var(trim((string) ($_POST['billing_email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: null;

            if ($first_name === '' || $last_name === '') {
                throw new RuntimeException('First name and last name are required.');
            }
            if ($phone !== '' && preg_match('/^(\+63\d{10}|09\d{9})$/', $phone) !== 1) {
                throw new RuntimeException('Phone must be in +63XXXXXXXXXX or 09XXXXXXXXX format.');
            }
            if ($billing_phone !== '' && preg_match('/^(\+63\d{10}|09\d{9})$/', $billing_phone) !== 1) {
                throw new RuntimeException('Billing phone must be in +63XXXXXXXXXX or 09XXXXXXXXX format.');
            }

            $pdo->beginTransaction();
            $update_user_stmt = $pdo->prepare("UPDATE users SET fullname = ?, phone = ? WHERE id = ?");
            $update_user_stmt->execute([
                normalize_full_name_from_parts($first_name, $middle_name, $last_name),
                $phone !== '' ? $phone : null,
                $client_id,
            ]);

            $profile_upsert = $pdo->prepare("\n                INSERT INTO client_profiles (client_id, first_name, middle_name, last_name, contact_email, billing_contact_name, billing_phone, billing_email)\n                VALUES (?, ?, ?, ?, ?, ?, ?, ?)\n                ON DUPLICATE KEY UPDATE\n                    first_name = VALUES(first_name),\n                    middle_name = VALUES(middle_name),\n                    last_name = VALUES(last_name),\n                    contact_email = VALUES(contact_email),\n                    billing_contact_name = VALUES(billing_contact_name),\n                    billing_phone = VALUES(billing_phone),\n                    billing_email = VALUES(billing_email),\n                    updated_at = CURRENT_TIMESTAMP\n            ");
            $profile_upsert->execute([
                $client_id,
                $first_name,
                $middle_name !== '' ? $middle_name : null,
                $last_name,
                $user_profile['email'] ?? null,
                $billing_contact_name !== '' ? $billing_contact_name : null,
                $billing_phone !== '' ? $billing_phone : null,
                $billing_email,
            ]);

            $pdo->commit();
            $success_message = 'Personal and billing details were saved successfully.';
        }

        if ($action === 'save_address') {
            $address_id = (int) ($_POST['address_id'] ?? 0);
            $label = sanitize($_POST['address_label'] ?? '');
            $recipient_name = sanitize($_POST['recipient_name'] ?? '');
            $phone = preg_replace('/[^\d+]/', '', (string) ($_POST['address_phone'] ?? ''));
            $country = sanitize($_POST['country'] ?? '');
            $province = sanitize($_POST['province'] ?? '');
            $city = sanitize($_POST['city'] ?? '');
            $barangay = sanitize($_POST['barangay'] ?? '');
            $street_address = sanitize($_POST['street_address'] ?? '');
            $address_line2 = sanitize($_POST['address_line2'] ?? '');
            $postal_code = sanitize($_POST['postal_code'] ?? '');
            $is_default = isset($_POST['is_default']) ? 1 : 0;

            foreach (['country' => $country, 'province' => $province, 'city' => $city, 'barangay' => $barangay, 'street address' => $street_address] as $field => $value) {
                if ($value === '') {
                    throw new RuntimeException(ucfirst($field) . ' is required.');
                }
            }
            if ($phone !== '' && preg_match('/^(\+63\d{10}|09\d{9})$/', $phone) !== 1) {
                throw new RuntimeException('Address phone must be in +63XXXXXXXXXX or 09XXXXXXXXX format.');
            }

            $pdo->beginTransaction();
            if ($is_default === 1) {
                $pdo->prepare("UPDATE client_addresses SET is_default = 0 WHERE client_id = ?")->execute([$client_id]);
            }

            if ($address_id > 0) {
                $update_stmt = $pdo->prepare("\n                    UPDATE client_addresses\n                    SET label = ?, recipient_name = ?, phone = ?, country = ?, province = ?, city = ?, barangay = ?,\n                        street_address = ?, address_line2 = ?, postal_code = ?, is_default = ?\n                    WHERE id = ? AND client_id = ?\n                ");
                $update_stmt->execute([
                    $label !== '' ? $label : null,
                    $recipient_name !== '' ? $recipient_name : null,
                    $phone !== '' ? $phone : null,
                    $country,
                    $province,
                    $city,
                    $barangay,
                    $street_address,
                    $address_line2 !== '' ? $address_line2 : null,
                    $postal_code !== '' ? $postal_code : null,
                    $is_default,
                    $address_id,
                    $client_id,
                ]);
            } else {
                $insert_stmt = $pdo->prepare("\n                    INSERT INTO client_addresses\n                    (client_id, label, recipient_name, phone, country, province, city, barangay, street_address, address_line2, postal_code, is_default)\n                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n                ");
                $insert_stmt->execute([
                    $client_id,
                    $label !== '' ? $label : null,
                    $recipient_name !== '' ? $recipient_name : null,
                    $phone !== '' ? $phone : null,
                    $country,
                    $province,
                    $city,
                    $barangay,
                    $street_address,
                    $address_line2 !== '' ? $address_line2 : null,
                    $postal_code !== '' ? $postal_code : null,
                    $is_default,
                ]);
            }

            $has_default_stmt = $pdo->prepare("SELECT id FROM client_addresses WHERE client_id = ? AND is_default = 1 LIMIT 1");
            $has_default_stmt->execute([$client_id]);
            if (!$has_default_stmt->fetch()) {
                $pdo->prepare("UPDATE client_addresses SET is_default = 1 WHERE client_id = ? ORDER BY id ASC LIMIT 1")->execute([$client_id]);
            }

            $pdo->commit();
            $success_message = 'Delivery address saved successfully.';
        }

        if ($action === 'set_default_address') {
            $address_id = (int) ($_POST['address_id'] ?? 0);
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE client_addresses SET is_default = 0 WHERE client_id = ?")->execute([$client_id]);
            $update_default = $pdo->prepare("UPDATE client_addresses SET is_default = 1 WHERE id = ? AND client_id = ?");
            $update_default->execute([$address_id, $client_id]);
            $pdo->commit();
            $success_message = 'Default address updated.';
        }

        if ($action === 'save_payments') {
            $enabled_methods = $_POST['payment_methods'] ?? [];
            if (!is_array($enabled_methods)) {
                $enabled_methods = [];
            }
            $enabled_methods = array_values(array_intersect($enabled_methods, ['gcash', 'card', 'cod', 'pickup']));
            $default_payment_method = sanitize($_POST['default_payment_method'] ?? '');

            $prefs_to_save = [];
            foreach (['gcash', 'card', 'cod', 'pickup'] as $method) {
                $identifier = sanitize($_POST[$method . '_account_identifier'] ?? '');
                $name = sanitize($_POST[$method . '_account_name'] ?? '');
                $is_enabled = in_array($method, $enabled_methods, true) ? 1 : 0;

                if ($method === 'gcash' && $is_enabled && preg_match('/^09\d{9}$/', $identifier) !== 1) {
                    throw new RuntimeException('GCash number must be 11 digits and start with 09.');
                }
                if ($method === 'card' && $is_enabled && preg_match('/^\d{12,19}$/', $identifier) !== 1) {
                    throw new RuntimeException('Card number must contain 12 to 19 digits.');
                }

                $verification_status = 'not_required';
                $verified_at = null;
                if (in_array($method, ['gcash', 'card'], true)) {
                    $is_verified = ($method === 'gcash')
                        ? ((int) ($user_profile['phone_verified'] ?? 0) === 1)
                        : ((int) ($user_profile['email_verified'] ?? 0) === 1);
                    $verification_status = $is_verified ? 'verified' : 'pending';
                    if ($is_enabled && !$is_verified) {
                        $is_enabled = 0;
                    }
                    if ($is_verified) {
                        $verified_at = date('Y-m-d H:i:s');
                    }
                }

                $prefs_to_save[$method] = [
                    'account_name' => $name !== '' ? $name : null,
                    'account_identifier' => $identifier !== '' ? $identifier : null,
                    'is_enabled' => $is_enabled,
                    'is_default' => 0,
                    'verification_status' => $verification_status,
                    'verified_at' => $verified_at,
                ];
            }

            if (!isset($prefs_to_save[$default_payment_method]) || (int) $prefs_to_save[$default_payment_method]['is_enabled'] !== 1) {
                foreach (['cod', 'pickup', 'gcash', 'card'] as $fallback) {
                    if ((int) $prefs_to_save[$fallback]['is_enabled'] === 1) {
                        $default_payment_method = $fallback;
                        break;
                    }
                }
            }
            if ($default_payment_method !== '' && isset($prefs_to_save[$default_payment_method])) {
                $prefs_to_save[$default_payment_method]['is_default'] = 1;
            }

            upsert_client_payment_preferences($pdo, $client_id, $prefs_to_save);
            $success_message = 'Payment preferences updated successfully.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_messages[] = $e->getMessage();
    }

    $profile_stmt->execute([$client_id]);
    $user_profile = $profile_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stored_profile = fetch_client_profile($pdo, $client_id);
    $addresses = fetch_client_addresses($pdo, $client_id);
    $payment_preferences = fetch_client_payment_preferences($pdo, $client_id);
}

$display_first_name = $stored_profile['first_name'] ?? $first_name;
$display_middle_name = $stored_profile['middle_name'] ?? $middle_name;
$display_last_name = $stored_profile['last_name'] ?? $last_name;
$default_address = fetch_client_default_address($pdo, $client_id);
$csrf_token_value = csrf_token();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Profile</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>

    <div class="container">
        <div class="dashboard-header">
            <h2>Customer Profile</h2>
             <p class="text-muted">Manage your profile, addresses, and payment preferences for faster checkout.</p>
        </div>

        <?php if ($success_message !== ''): ?>
            <div class="alert alert-success mb-3"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php foreach ($error_messages as $error): ?>
            <div class="alert alert-danger mb-2"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <div class="card mb-3">
            <div class="card-header"><h3><i class="fas fa-id-card text-primary"></i> Personal & Billing Information</h3></div>
            <form method="POST" class="form-grid">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_profile">
                <div>
                     <label for="first_name">First name</label>
                    <input id="first_name" name="first_name" class="form-control" type="text" value="<?php echo htmlspecialchars((string) $display_first_name); ?>" required>
                </div>
                <div>
                    <label for="middle_name">Middle name</label>
                    <input id="middle_name" name="middle_name" class="form-control" type="text" value="<?php echo htmlspecialchars((string) $display_middle_name); ?>">
                </div>
                <div>
                    <label for="last_name">Last name</label>
                    <input id="last_name" name="last_name" class="form-control" type="text" value="<?php echo htmlspecialchars((string) $display_last_name); ?>" required>
                </div>
                <div>
                    <label>Email</label>
                    <div class="form-control bg-light"><?php echo htmlspecialchars($user_profile['email'] ?? 'Not available'); ?></div>
                </div>
                <div>
                    <label for="phone">Phone</label>
                    <input id="phone" name="phone" class="form-control" type="tel" inputmode="numeric" value="<?php echo htmlspecialchars((string) ($user_profile['phone'] ?? '')); ?>" placeholder="+63XXXXXXXXXX or 09XXXXXXXXX">
                </div>
                <div>
                    <label>Member since</label>
                    <div class="form-control bg-light"><?php echo !empty($user_profile['created_at']) ? date('M d, Y', strtotime((string) $user_profile['created_at'])) : 'Not available'; ?></div>
                </div>
                <div>
                    <label for="billing_contact_name">Billing contact name</label>
                    <input id="billing_contact_name" name="billing_contact_name" class="form-control" type="text" value="<?php echo htmlspecialchars((string) ($stored_profile['billing_contact_name'] ?? '')); ?>">
                </div>
                <div>
                    <label for="billing_phone">Billing phone</label>
                    <input id="billing_phone" name="billing_phone" class="form-control" type="tel" inputmode="numeric" value="<?php echo htmlspecialchars((string) ($stored_profile['billing_phone'] ?? '')); ?>" placeholder="+63XXXXXXXXXX or 09XXXXXXXXX">
                </div>
                <div>
                    <label for="billing_email">Billing email</label>
                    <input id="billing_email" name="billing_email" class="form-control" type="email" value="<?php echo htmlspecialchars((string) ($stored_profile['billing_email'] ?? '')); ?>">
                </div>
                <div style="display:flex;align-items:end;">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save Profile</button>
                </div>
           </form>
        </div>

        <div class="card mb-3" id="delivery-address">
            <div class="card-header"><h3><i class="fas fa-truck text-primary"></i> Delivery Addresses</h3></div>
            <?php if ($default_address): ?>
                <p class="text-muted">Default: <?php echo htmlspecialchars(trim(($default_address['street_address'] ?? '') . ', ' . ($default_address['barangay'] ?? '') . ', ' . ($default_address['city'] ?? '') . ', ' . ($default_address['province'] ?? ''))); ?></p>
            <?php endif; ?>
            <form method="POST" class="form-grid">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_address">
                <input type="hidden" name="address_id" value="<?php echo (int) ($active_address['id'] ?? 0); ?>">
                <div><label>Label</label><input name="address_label" class="form-control" type="text" value="<?php echo htmlspecialchars((string) ($active_address['label'] ?? '')); ?>" placeholder="Home / Office"></div>
                <div><label>Recipient</label><input name="recipient_name" class="form-control" type="text" value="<?php echo htmlspecialchars((string) ($active_address['recipient_name'] ?? '')); ?>"></div>
                <div><label>Phone</label><input name="address_phone" class="form-control" type="tel" value="<?php echo htmlspecialchars((string) ($active_address['phone'] ?? '')); ?>"></div>
                <div><label>Country</label><input name="country" class="form-control" type="text" value="<?php echo htmlspecialchars((string) ($active_address['country'] ?? '')); ?>" required></div>
                <div><label>Province</label><input name="province" class="form-control" type="text" value="<?php echo htmlspecialchars((string) ($active_address['province'] ?? '')); ?>" required></div>
                <div><label>City / Municipality</label><input name="city" class="form-control" type="text" value="<?php echo htmlspecialchars((string) ($active_address['city'] ?? '')); ?>" required></div>
                <div><label>Barangay</label><input name="barangay" class="form-control" type="text" value="<?php echo htmlspecialchars((string) ($active_address['barangay'] ?? '')); ?>" required></div>
                <div><label>House Number / Street</label><input name="street_address" class="form-control" type="text" value="<?php echo htmlspecialchars((string) ($active_address['street_address'] ?? '')); ?>" required></div>
                <div><label>Other House Information</label><input name="address_line2" class="form-control" type="text" value="<?php echo htmlspecialchars((string) ($active_address['address_line2'] ?? '')); ?>"></div>
                <div><label>Postal Code</label><input name="postal_code" class="form-control" type="text" value="<?php echo htmlspecialchars((string) ($active_address['postal_code'] ?? '')); ?>"></div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <label><input type="checkbox" name="is_default" <?php echo ((int) ($active_address['is_default'] ?? 0) === 1) ? 'checked' : ''; ?>> Set as default</label>
                    <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save Address</button>
                </div>
                </form>
            <?php if (!empty($addresses)): ?>
                <div class="mt-3">
                    <h4 class="mb-2">Saved Addresses</h4>
                    <?php foreach ($addresses as $address): ?>
                        <div class="card mb-2" style="padding:12px;">
                            <strong><?php echo htmlspecialchars((string) ($address['label'] ?: 'Address #' . $address['id'])); ?></strong>
                            <?php if ((int) $address['is_default'] === 1): ?><span class="badge badge-success">Default</span><?php endif; ?>
                            <div class="text-muted small"><?php echo htmlspecialchars(trim(($address['street_address'] ?? '') . ', ' . ($address['barangay'] ?? '') . ', ' . ($address['city'] ?? '') . ', ' . ($address['province'] ?? '') . ', ' . ($address['country'] ?? ''))); ?></div>
                            <div class="mt-2" style="display:flex;gap:8px;flex-wrap:wrap;">
                                <a class="btn btn-outline-primary btn-sm" href="customer_profile.php?edit_address=<?php echo (int) $address['id']; ?>#delivery-address">Edit</a>
                                <?php if ((int) $address['is_default'] !== 1): ?>
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="set_default_address">
                                        <input type="hidden" name="address_id" value="<?php echo (int) $address['id']; ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">Set default</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header"><h3><i class="fas fa-credit-card text-primary"></i> Payment Preferences</h3></div>
            <p class="text-muted">Enable methods you want to use. Verification buttons are replaced by real status indicators.</p>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_payments">
                <div class="form-grid">
                    <?php
                    $method_labels = ['gcash' => 'GCash', 'card' => 'Cards (Visa / Mastercard)', 'cod' => 'Cash on Delivery (COD)', 'pickup' => 'Pick Up Pay'];
                    foreach ($method_labels as $method => $label):
                        $pref = $payment_preferences[$method] ?? [];
                        $is_enabled = (int) ($pref['is_enabled'] ?? 0) === 1;
                        $is_default = (int) ($pref['is_default'] ?? 0) === 1;
                        $status = (string) ($pref['verification_status'] ?? 'not_required');
                    ?>
                        <div>
                            <label><strong><?php echo htmlspecialchars($label); ?></strong></label>
                            <label class="d-block mb-2"><input type="checkbox" class="payment-method-toggle" name="payment_methods[]" value="<?php echo htmlspecialchars($method); ?>" <?php echo $is_enabled ? 'checked' : ''; ?>> Enable</label>
                            <input class="form-control mb-2" type="text" name="<?php echo htmlspecialchars($method); ?>_account_name" placeholder="Account name (optional)" value="<?php echo htmlspecialchars((string) ($pref['account_name'] ?? '')); ?>">
                            <input class="form-control mb-2" type="text" name="<?php echo htmlspecialchars($method); ?>_account_identifier" placeholder="<?php echo $method === 'gcash' ? 'GCash number' : ($method === 'card' ? 'Card number' : 'Reference details'); ?>" value="<?php echo htmlspecialchars((string) ($pref['account_identifier'] ?? '')); ?>">
                            <?php if (in_array($method, ['gcash', 'card'], true)): ?>
                                <small class="text-muted d-block">
                                    Verification status:
                                    <strong><?php echo $status === 'verified' ? 'Verified' : 'Pending verification'; ?></strong>
                                    <?php echo $method === 'gcash' ? '(based on verified phone)' : '(based on verified email)'; ?>
                                </small>
                            <?php else: ?>
                                <small class="text-muted d-block">No external verification required.</small>
                            <?php endif; ?>
                            <label class="d-block mt-2"><input type="radio" name="default_payment_method" value="<?php echo htmlspecialchars($method); ?>" class="default-payment-option" <?php echo $is_default ? 'checked' : ''; ?>> Set as default</label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-primary mt-3" type="submit"><i class="fas fa-save"></i> Save Payment Preferences</button>
            </form>
        </div>
    </div>

     <script>
        const csrfToken = <?php echo json_encode($csrf_token_value); ?>;
            document.querySelectorAll('form[method="POST"], form[method="post"]').forEach((form) => {
                if (form.querySelector('input[name="csrf_token"]')) {
                    return;
                }
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'csrf_token';
                hidden.value = csrfToken;
                form.prepend(hidden);
            });

        (function () {
            const paymentToggles = document.querySelectorAll('.payment-method-toggle');
            const defaultOptions = document.querySelectorAll('.default-payment-option');
            const syncDefaultPaymentSelection = () => {
                defaultOptions.forEach((option) => {
                    const matchingToggle = document.querySelector(`.payment-method-toggle[value="${option.value}"]`);
                    option.disabled = !matchingToggle?.checked;
                    if (!matchingToggle?.checked && option.checked) {
                        option.checked = false;
                    }
                });
            };
            paymentToggles.forEach((toggle) => toggle.addEventListener('change', syncDefaultPaymentSelection));
            syncDefaultPaymentSelection();
        })();
    </script>
</body>
</html>