<?php
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$userId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT id, name, email, department, role, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$user) {
    header("Location: logout.php");
    exit();
}

if (!isset($_SESSION['email']) && !empty($user['email'])) {
    $_SESSION['email'] = $user['email'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        .profile-shell {
            max-width: 980px;
            margin: 0 auto;
            padding: 30px;
        }

        .profile-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 24px;
        }

        .profile-title {
            margin: 0;
            color: #172033;
            font-size: 30px;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .profile-subtitle {
            margin: 6px 0 0;
            color: #64748b;
            font-size: 14px;
            font-weight: 600;
        }

        .profile-card {
            background: #ffffff;
            border: 1px solid #e5ebdf;
            border-radius: 18px;
            box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07);
            overflow: hidden;
        }

        .profile-card-top {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 26px;
            background:
                linear-gradient(90deg, rgba(27, 94, 32, 0.08), rgba(244, 196, 48, 0.12)),
                #ffffff;
            border-bottom: 1px solid #e5ebdf;
        }

        .profile-avatar {
            width: 68px;
            height: 68px;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #1B5E20;
            color: #ffffff;
            font-size: 26px;
            box-shadow: inset 0 -4px 0 rgba(244, 196, 48, 0.86);
            flex: 0 0 68px;
        }

        .profile-name {
            margin: 0 0 5px;
            color: #172033;
            font-size: 22px;
            font-weight: 800;
        }

        .profile-email {
            color: #64748b;
            font-size: 14px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            padding: 26px;
        }

        .profile-field {
            border: 1px solid #e8eee4;
            border-radius: 14px;
            padding: 16px;
            background: #fbfdf9;
        }

        .profile-label {
            margin-bottom: 8px;
            color: #6b7c6d;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .profile-value {
            color: #1f2937;
            font-size: 15px;
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        @media (max-width: 720px) {
            .profile-shell {
                padding: 18px 14px 28px;
            }

            .profile-header,
            .profile-card-top {
                align-items: flex-start;
                flex-direction: column;
            }

            .profile-grid {
                grid-template-columns: 1fr;
                padding: 18px;
            }
        }
    </style>
</head>
<body>
<div class="admin-page">
    <?php include '../includes/admin_navbar.php'; ?>

    <main class="profile-shell">
        <div class="profile-header">
            <div>
                <h1 class="profile-title">Profile</h1>
                <p class="profile-subtitle">Your admin account details.</p>
            </div>
        </div>

        <section class="profile-card">
            <div class="profile-card-top">
                <div class="profile-avatar" aria-hidden="true">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div>
                    <h2 class="profile-name"><?= htmlspecialchars($user['name'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?></h2>
                    <div class="profile-email"><?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>

            <div class="profile-grid">
                <div class="profile-field">
                    <div class="profile-label">Full Name</div>
                    <div class="profile-value"><?= htmlspecialchars($user['name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="profile-field">
                    <div class="profile-label">Email</div>
                    <div class="profile-value"><?= htmlspecialchars($user['email'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="profile-field">
                    <div class="profile-label">Department</div>
                    <div class="profile-value"><?= htmlspecialchars($user['department'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="profile-field">
                    <div class="profile-label">Role</div>
                    <div class="profile-value"><?= htmlspecialchars(ucfirst((string) ($user['role'] ?? 'admin')), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="profile-field">
                    <div class="profile-label">Account Created</div>
                    <div class="profile-value"><?= htmlspecialchars($user['created_at'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="profile-field">
                    <div class="profile-label">User ID</div>
                    <div class="profile-value">#<?= (int) ($user['id'] ?? 0); ?></div>
                </div>
            </div>
        </section>
    </main>
</div>
</body>
</html>
