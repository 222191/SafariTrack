<?php
session_start();
require_once "../config/database.php";

$errors = [];
$full_name = "";
$phone = "";
$email = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST["full_name"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($full_name === "" || strlen($full_name) > 100) {
        $errors[] = "Please enter a valid full name.";
    }

    if ($phone === "" || strlen($phone) > 20) {
        $errors[] = "Please enter a valid phone number.";
    }

    if ($email !== "" && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100)) {
        $errors[] = "Please enter a valid email address.";
    }

    if (strlen($password) < 8) {
        $errors[] = "Your password must contain at least 8 characters.";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Your passwords do not match.";
    }

    if (empty($errors)) {
        try {
            $check = $pdo->prepare("
                SELECT user_id
                FROM users
                WHERE phone = :phone
                OR (:email_check IS NOT NULL AND email = :email)
                LIMIT 1
            ");

            $email_value = $email === "" ? null : $email;

            $check->execute([
                "phone" => $phone,
                "email_check" => $email_value,
                "email" => $email_value
            ]);

            if ($check->fetch()) {
                $errors[] = "An account with this phone number or email already exists.";
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                $insert = $pdo->prepare("
                    INSERT INTO users
                    (full_name, phone, email, password_hash, role, created_at)
                    VALUES
                    (:full_name, :phone, :email, :password_hash, 'passenger', NOW())
                ");

                $insert->execute([
                    "full_name" => $full_name,
                    "phone" => $phone,
                    "email" => $email_value,
                    "password_hash" => $password_hash
                ]);

                header("Location: login.php?registered=1");
                exit;
            }
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            $errors[] = "Registration could not be completed. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | SafariTrack</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .auth-container {
            max-width: 520px;
            margin: 45px auto;
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

        .auth-card .intro {
            text-align: center;
            color: #666;
            margin-bottom: 25px;
        }

        .auth-card .form-group {
            margin-bottom: 18px;
        }

        .auth-card label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .auth-card input {
            width: 100%;
            padding: 13px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 15px;
        }

        .auth-card input:focus {
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
    </style>
</head>

<body>

<nav class="navbar">
    <div class="logo">SafariTrack</div>
    <div class="nav-links">
        <a href="../index.php">Home</a>
        <a href="login.php">Login</a>
    </div>
</nav>

<div class="auth-container">
    <div class="auth-card">

        <h1>Create Account</h1>
        <p class="intro">Register to book your journeys with SafariTrack.</p>

        <?php if (!empty($errors)): ?>
            <div class="error-message">
                <?php foreach ($errors as $error): ?>
                    <p><?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php">

            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    maxlength="100"
                    value="<?= htmlspecialchars($full_name) ?>"
                    placeholder="Enter your full name"
                    required
                >
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    maxlength="20"
                    value="<?= htmlspecialchars($phone) ?>"
                    placeholder="e.g. 0712345678"
                    required
                >
            </div>

            <div class="form-group">
                <label for="email">Email Address (Optional)</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="100"
                    value="<?= htmlspecialchars($email) ?>"
                    placeholder="Enter your email address"
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    minlength="8"
                    placeholder="At least 8 characters"
                    required
                >
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    minlength="8"
                    placeholder="Enter your password again"
                    required
                >
            </div>

            <button type="submit" class="auth-button">
                CREATE ACCOUNT
            </button>

        </form>

        <div class="auth-link">
            Already have an account?
            <a href="login.php">Login here</a>
        </div>

    </div>
</div>

<footer>
    <p>&copy; <?= date('Y') ?> SafariTrack. All rights reserved.</p>
</footer>

</body>
</html>