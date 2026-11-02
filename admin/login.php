
<?php
session_start();

require_once "../config/database.php";

if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
    header("Location: index.php");
    exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($phone === '' || $password === '') {
        $error = "Please enter your phone number and password.";
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT user_id, full_name, phone, email, password_hash, role
                FROM users
                WHERE phone = ?
                LIMIT 1
            ");

            $stmt->execute([$phone]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {

                if ($user['role'] !== 'admin') {
                    $error = "You do not have administrator access.";
                } else {
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['phone'] = $user['phone'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];

                    $audit = $pdo->prepare("
                        INSERT INTO audit_logs (user_id, action, created_at, details)
                        VALUES (?, ?, NOW(), ?)
                    ");

                    $audit->execute([
                        $user['user_id'],
                        'Admin login',
                        'Administrator logged into SafariTrack.'
                    ]);

                    header("Location: index.php");
                    exit;
                }

            } else {
                $error = "Incorrect phone number or password.";
            }

        } catch (PDOException $e) {
            error_log("Admin login error: " . $e->getMessage());
            $error = "Unable to process your login. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | SafariTrack</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        body {
            background: #f4f4f4;
        }

        .admin-login-container {
            max-width: 430px;
            margin: 65px auto;
            padding: 20px;
        }

        .admin-login-card {
            background: white;
            padding: 35px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .admin-login-card h2 {
            color: #319b35;
            text-align: center;
            margin-bottom: 10px;
        }

        .admin-login-card p {
            text-align: center;
            color: #666;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
        }

        .login-button {
            width: 100%;
            padding: 13px;
            background: #319b35;
            color: white;
            border: none;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
        }

        .login-button:hover {
            background: #267d2a;
        }

        .error-message {
            background: #fce8e8;
            color: #b42318;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 18px;
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #319b35;
            text-decoration: none;
        }
    </style>
</head>

<body>

<nav class="navbar">
    <div class="logo">SafariTrack</div>
    <div class="nav-links">
        <a href="../index.php">Passenger Home</a>
    </div>
</nav>

<div class="admin-login-container">

    <div class="admin-login-card">

        <h2>Administrator Login</h2>
        <p>Sign in to access the SafariTrack management system.</p>

        <?php if ($error !== ''): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input
                    type="text"
                    id="phone"
                    name="phone"
                    placeholder="Enter administrator phone number"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter password"
                    required
                >
            </div>

            <button type="submit" class="login-button">
                LOGIN AS ADMIN
            </button>

        </form>

        <a href="../index.php" class="back-link">
            Return to Passenger Homepage
        </a>

    </div>

</div>

</body>
</html>
