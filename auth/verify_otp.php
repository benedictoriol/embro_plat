<?php
session_start();
require_once '../config/db.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = sanitize($_POST['otp']);

    if (empty($otp)) {
        $error = 'Please enter the verification code.';
    } else {
        $message = 'Verification successful. You may now reset your password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code - Embroidery Platform</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-container">
    <div class="auth-card">
        <div class="auth-logo">
            <div class="auth-logo-icon">
                <i class="fas fa-shield-check"></i>
            </div>
            <h3>Verify Your Code</h3>
            <p class="text-muted">Enter the verification code sent to your email.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                <p class="mt-2"><a href="login.php" class="btn btn-sm btn-primary">Return to Login</a></p>
            </div>
        <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <label>Verification Code *</label>
                    <input type="text" name="otp" class="form-control" required placeholder="Enter code">
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    <i class="fas fa-check"></i> Verify Code
                </button>
            </form>
        <?php endif; ?>

        <p class="text-center mt-3">
            Need a new code? <a href="forgot_password.php">Resend code</a>
        </p>
    </div>
</body>
</html>
