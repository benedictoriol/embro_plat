<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_role('employee');

$employee_id = $_SESSION['user']['id'];

$emp_stmt = $pdo->prepare("
    SELECT se.*, s.shop_name, s.logo 
    FROM shop_employees se 
    JOIN shops s ON se.shop_id = s.id 
    WHERE se.user_id = ? AND se.status = 'active'
");
$emp_stmt->execute([$employee_id]);
$employee = $emp_stmt->fetch();

if(!$employee) {
    die("You are not assigned to any shop. Please contact your shop owner.");
}

$jobs_stmt = $pdo->prepare("
    SELECT 
        o.id,
        o.order_number,
        o.service_type,
        u.fullname as client_name,
        COALESCE(js.scheduled_date, o.scheduled_date) as schedule_date,
        js.scheduled_time as schedule_time
    FROM orders o
    JOIN users u ON o.client_id = u.id
    LEFT JOIN job_schedule js ON js.order_id = o.id AND js.employee_id = ?
    WHERE (o.assigned_to = ? OR js.employee_id = ?)
      AND o.status IN ('accepted', 'in_progress')
    ORDER BY schedule_date ASC, js.scheduled_time ASC
");
$jobs_stmt->execute([$employee_id, $employee_id, $employee_id]);
$jobs = $jobs_stmt->fetchAll();

$error = '';
$success = '';

if(isset($_POST['upload_photo'])) {
    $order_id = $_POST['order_id'] ?? '';
    $caption = sanitize($_POST['caption'] ?? '');

    $order_stmt = $pdo->prepare("
        SELECT o.id
        FROM orders o
        LEFT JOIN job_schedule js ON js.order_id = o.id AND js.employee_id = ?
        WHERE o.id = ? AND (o.assigned_to = ? OR js.employee_id = ?)
        LIMIT 1
    ");
    $order_stmt->execute([$employee_id, $order_id, $employee_id, $employee_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        $error = 'Please select a valid job.';
    } elseif(!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please upload a valid image file.';
    } else {
        $file = $_FILES['photo'];
        $file_size = $file['size'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if($file_size > MAX_FILE_SIZE) {
            $error = 'File size exceeds the 5MB limit.';
        } elseif(!in_array($file_ext, ALLOWED_IMAGE_TYPES, true)) {
            $error = 'Only JPG, JPEG, PNG, and GIF files are allowed.';
        } else {
            $upload_dir = '../assets/uploads/job_photos/';
            if(!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $filename = $order_id . '_' . $employee_id . '_' . uniqid('job_', true) . '.' . $file_ext;
            $target_file = $upload_dir . $filename;

            if(move_uploaded_file($file['tmp_name'], $target_file)) {
                $photo_path = 'job_photos/' . $filename;
                $photo_stmt = $pdo->prepare("
                    INSERT INTO order_photos (order_id, employee_id, photo_url, caption)
                    VALUES (?, ?, ?, ?)
                ");
                $photo_stmt->execute([$order_id, $employee_id, $photo_path, $caption]);
                $success = 'Photo uploaded successfully!';
            } else {
                $error = 'Failed to upload the photo. Please try again.';
            }
        }
    }
}

$photos_stmt = $pdo->prepare("
    SELECT op.*, o.order_number, o.service_type
    FROM order_photos op
    JOIN orders o ON op.order_id = o.id
    WHERE op.employee_id = ?
    ORDER BY op.uploaded_at DESC
    LIMIT 10
");
$photos_stmt->execute([$employee_id]);
$recent_photos = $photos_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Photos - <?php echo htmlspecialchars($employee['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .upload-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .photo-card {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }
        .photo-card img {
            width: 100%;
            height: 160px;
            object-fit: cover;
        }
        .photo-card .photo-body {
            padding: 12px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-user-tie"></i> Employee Dashboard
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="assigned_jobs.php" class="nav-link">My Jobs</a></li>
                <li><a href="update_status.php" class="nav-link">Update Status</a></li>
                <li><a href="upload_photos.php" class="nav-link active">Upload Photos</a></li>
                <li><a href="schedule.php" class="nav-link">Schedule</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-user"></i> <?php echo $_SESSION['user']['fullname']; ?>
                    </a>
                    <div class="dropdown-menu">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-cog"></i> Profile</a>
                        <a href="../auth/logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </li>
            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="dashboard-header">
            <h2>Upload Job Photos</h2>
            <p class="text-muted">Share progress or completion photos for your assigned jobs.</p>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="upload-card">
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Select Job</label>
                    <select name="order_id" class="form-control" required>
                        <option value="">Choose an assigned job</option>
                        <?php foreach($jobs as $job): ?>
                            <option value="<?php echo $job['id']; ?>">
                                #<?php echo htmlspecialchars($job['order_number']); ?> - <?php echo htmlspecialchars($job['service_type']); ?>
                                (<?php echo htmlspecialchars($job['client_name']); ?>)
                                <?php if(!empty($job['schedule_date'])): ?>
                                    - <?php echo date('M d, Y', strtotime($job['schedule_date'])); ?>
                                    <?php if(!empty($job['schedule_time'])): ?>
                                        <?php echo date('h:i A', strtotime($job['schedule_time'])); ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Photo</label>
                    <input type="file" name="photo" class="form-control" accept="image/*" required>
                    <small class="text-muted">Max size: 5MB. Supported formats: JPG, PNG, GIF.</small>
                </div>
                <div class="form-group">
                    <label>Caption (Optional)</label>
                    <textarea name="caption" class="form-control" rows="3" placeholder="Add a short description..."></textarea>
                </div>
                <div class="text-right">
                    <button type="submit" name="upload_photo" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Upload Photo
                    </button>
                </div>
            </form>
        </div>

        <div class="card">
            <h3><i class="fas fa-images"></i> Recent Uploads</h3>
            <?php if(!empty($recent_photos)): ?>
                <div class="photo-grid">
                    <?php foreach($recent_photos as $photo): ?>
                        <div class="photo-card">
                            <img src="../assets/uploads/<?php echo htmlspecialchars($photo['photo_url']); ?>" alt="Job photo">
                            <div class="photo-body">
                                <div class="text-muted">
                                    #<?php echo htmlspecialchars($photo['order_number']); ?> - <?php echo htmlspecialchars($photo['service_type']); ?>
                                </div>
                                <?php if(!empty($photo['caption'])): ?>
                                    <p class="mb-1"><?php echo htmlspecialchars($photo['caption']); ?></p>
                                <?php endif; ?>
                                <small class="text-muted">Uploaded <?php echo date('M d, Y', strtotime($photo['uploaded_at'])); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center p-4">
                    <i class="fas fa-camera-retro fa-3x text-muted mb-3"></i>
                    <h4>No Photos Yet</h4>
                    <p class="text-muted">Upload photos to share progress with the client.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
