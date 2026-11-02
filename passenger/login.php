<?php
session_start();
require_once "../config/database.php";

if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$error = "";
$phone = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $phone = trim($_POST["phone"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($phone === "" || $password === "") {
        $error = "Please enter your phone number and password.";
    } else {
        $stmt = $pdo->prepare("
            SELECT user_id, full_name, phone, email, password_hash, role
            FROM users
            WHERE phone = :phone
            LIMIT 1
        ");

        $stmt->execute(["phone" => $phone]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['role'] !== 'passenger') {
                $error = "This login page is for passengers only.";
            } else {
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['phone'] = $user['phone'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];

                header("Location: ../index.php");
                exit;
            }
        } else {
            $error = "Incorrect phone number or password.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | SafariTrack</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .auth-container {
            max-width: 480px;
            margin: 55px auto;
            padding: 20px;
        }

        .auth-card {
            background: white;
            padding: 35px;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }

        .auth-card h1 {
            color: #319b35;
            text-align: center;
            margin-bottom: 10px;
        }

        .intro {
            text-align: center;
            color: #666;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .form-group input {
            width: 100%;
            padding: 13px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 15px;
        }

        .form-group input:focus {
            outline: none;
            border-color: #319b35;
        }

        .auth-button {
            width: 100%;
            padding: 14px;
            background: #319b35;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }

        .auth-button:hover {
            background: #267d2a;
        }

        .auth-link {
            text-align: center;
            margin-top: 20px;
        }

        .auth-link a {
            color: #319b35;
            text-decoration: none;
            font-weight: bold;
        }

        .error-message {
            background: #ffeaea;
            color: #a00000;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 18px;
        }

        .success-message {
            background: #eaf7ea;
            color: #267d2a;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 18px;
        }
    </style>
</head>

<body>

<nav class="navbar">
    <div class="logo">SafariTrack</div>
    <div class="nav-links">
        <a href="../index.php">Home</a>
        <a href="register.php">Register</a>
    </div>
</nav>

<div class="auth-container">
    <div class="auth-card">

        <h1>Passenger Login</h1>
        <p class="intro">Welcome back to SafariTrack.</p>

        <?php if (isset($_GET['registered'])): ?>
            <div class="success-message">
                Your account has been created successfully. Please log in.
            </div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    maxlength="20"
                    value="<?= htmlspecialchars($phone) ?>"
                    placeholder="Enter your registered phone number"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    required
                >
            </div>

            <button type="submit" class="auth-button">
                LOGIN
            </button>

        </form>

        <div class="auth-link">
            Don't have an account?
            <a href="register.php">Register here</a>
        </div>

    </div>
</div>

<footer>
    <p>&copy; <?= date('Y') ?> SafariTrack. All rights reserved.</p>
</footer>

</body>
</html>