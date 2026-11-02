
<?php
require_once "../config/database.php";

$checkAdmin = $pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'admin'"
);

$adminExists = (int) $checkAdmin->fetchColumn() > 0;

$message = "";
$messageType = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$adminExists) {

    $full_name = trim($_POST["full_name"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if (
        $full_name === "" ||
        $phone === "" ||
        $password === ""
    ) {
        $message = "Please fill in all required fields.";
        $messageType = "error";

    } elseif (strlen($password) < 8) {
        $message = "Password must contain at least 8 characters.";
        $messageType = "error";

    } else {
        try {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO users
                (full_name, phone, email, password_hash, role)
                VALUES
                (:full_name, :phone, :email, :password_hash, 'admin')
            ");

            $stmt->execute([
                "full_name" => $full_name,
                "phone" => $phone,
                "email" => $email !== "" ? $email : null,
                "password_hash" => $password_hash
            ]);

            $message = "Administrator account created successfully. You can now log in.";
            $messageType = "success";
            $adminExists = true;

        } catch (PDOException $e) {
            $message = "Account could not be created. Check whether the phone number or email already exists.";
            $messageType = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Admin | SafariTrack</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f6faf7;
            margin: 0;
            padding: 30px 15px;
        }

        .container {
            max-width: 430px;
            margin: 50px auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.08);
        }

        h2 {
            text-align: center;
            color: #319b35;
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin: 15px 0 7px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        button {
            width: 100%;
            padding: 13px;
            margin-top: 22px;
            background: #319b35;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: #267d2a;
        }

        .message {
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 5px;
            text-align: center;
        }

        .success {
            background: #e8f5ec;
            color: #267d2a;
        }

        .error {
            background: #fdeaea;
            color: #b00020;
        }

        .notice {
            text-align: center;
            line-height: 1.6;
        }

        a {
            color: #319b35;
            font-weight: bold;
        }
    </style>
</head>

<body>

<div class="container">

    <h2>Create Administrator Account</h2>

    <?php if ($message !== ""): ?>
        <div class="message <?= htmlspecialchars($messageType) ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($adminExists): ?>

        <p class="notice">
            An administrator account already exists.
            <br><br>
            <a href="login.php">Proceed to Admin Login</a>
        </p>

    <?php else: ?>

        <form method="POST">

            <label>Full Name</label>
            <input type="text" name="full_name" required>

            <label>Phone Number</label>
            <input type="text" name="phone" required>

            <label>Email Address (Optional)</label>
            <input type="email" name="email">

            <label>Password</label>
            <input type="password" name="password" minlength="8" required>

            <button type="submit">Create Admin Account</button>

        </form>

    <?php endif; ?>

</div>

</body>
</html>
