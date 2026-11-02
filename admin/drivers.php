
<?php
session_start();

require_once "../config/database.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: login.php");
    exit;
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];
$success = "";
$error = "";
$search = trim($_GET["search"] ?? "");

function escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function recordAudit($pdo, $action, $details) {
    $userId = $_SESSION["user_id"] ?? null;

    $stmt = $pdo->prepare(
        "INSERT INTO audit_logs (user_id, action, details)
         VALUES (?, ?, ?)"
    );

    $stmt->execute([$userId, $action, $details]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($csrfToken, $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {
        $action = $_POST["action"] ?? "";

        try {
            if ($action === "add") {
                $name = trim($_POST["full_name"] ?? "");
                $phone = trim($_POST["phone"] ?? "");
                $license = trim($_POST["license_number"] ?? "");

                if ($name === "" || $phone === "" || $license === "") {
                    throw new Exception("Please fill in all driver details.");
                }

                if (!preg_match("/^[0-9+ ]{7,20}$/", $phone)) {
                    throw new Exception("Enter a valid phone number.");
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO drivers (full_name, phone, license_number, status)
                     VALUES (?, ?, ?, 'Active')"
                );

                $stmt->execute([$name, $phone, $license]);

                recordAudit(
                    $pdo,
                    "Driver added",
                    "Added driver: $name, Licence: $license"
                );

                $success = "Driver added successfully.";
            }

            elseif ($action === "update") {
                $driverId = (int)($_POST["driver_id"] ?? 0);
                $name = trim($_POST["full_name"] ?? "");
                $phone = trim($_POST["phone"] ?? "");
                $license = trim($_POST["license_number"] ?? "");

                if ($driverId <= 0 || $name === "" || $phone === "" || $license === "") {
                    throw new Exception("Please provide all required driver details.");
                }

                if (!preg_match("/^[0-9+ ]{7,20}$/", $phone)) {
                    throw new Exception("Enter a valid phone number.");
                }

                $stmt = $pdo->prepare(
                    "UPDATE drivers
                     SET full_name = ?, phone = ?, license_number = ?
                     WHERE driver_id = ?"
                );

                $stmt->execute([$name, $phone, $license, $driverId]);

                recordAudit(
                    $pdo,
                    "Driver updated",
                    "Updated driver ID $driverId: $name"
                );

                $success = "Driver details updated successfully.";
            }

            elseif ($action === "toggle_status") {
                $driverId = (int)($_POST["driver_id"] ?? 0);

                $stmt = $pdo->prepare(
                    "SELECT full_name, status FROM drivers WHERE driver_id = ?"
                );
                $stmt->execute([$driverId]);
                $driver = $stmt->fetch();

                if (!$driver) {
                    throw new Exception("Driver not found.");
                }

                $newStatus = $driver["status"] === "Active" ? "Inactive" : "Active";

                $stmt = $pdo->prepare(
                    "UPDATE drivers SET status = ? WHERE driver_id = ?"
                );
                $stmt->execute([$newStatus, $driverId]);

                recordAudit(
                    $pdo,
                    "Driver status changed",
                    $driver["full_name"] . " changed to " . $newStatus
                );

                $success = "Driver status updated successfully.";
            }

            else {
                throw new Exception("Invalid action.");
            }
        } catch (PDOException $e) {
            error_log("Driver management error: " . $e->getMessage());

            if ($e->getCode() === "23000") {
                $error = "That licence number is already registered. Please check the details.";
            } else {
                $error = "The operation could not be completed. Please try again.";
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$editDriver = null;

if (isset($_GET["edit"])) {
    $editId = (int)$_GET["edit"];

    $stmt = $pdo->prepare(
        "SELECT * FROM drivers WHERE driver_id = ?"
    );
    $stmt->execute([$editId]);
    $editDriver = $stmt->fetch();
}

$sql = "SELECT * FROM drivers";
$params = [];

if ($search !== "") {
    $sql .= " WHERE full_name LIKE ? OR phone LIKE ? OR license_number LIKE ?";
    $term = "%$search%";
    $params = [$term, $term, $term];
}

$sql .= " ORDER BY driver_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$drivers = $stmt->fetchAll();

$totalDrivers = count($drivers);
$activeDrivers = 0;
$inactiveDrivers = 0;

foreach ($drivers as $driver) {
    if ($driver["status"] === "Active") {
        $activeDrivers++;
    } else {
        $inactiveDrivers++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Management | SafariTrack</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
        }

        body {
            background: #f4f7f4;
            color: #333;
        }

        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 240px;
            background: #238b2b;
            color: white;
            padding: 25px 15px;
            flex-shrink: 0;
        }

        .sidebar h2 {
            text-align: center;
            margin-bottom: 35px;
            font-size: 25px;
        }

        .sidebar a {
            display: block;
            color: white;
            text-decoration: none;
            padding: 13px 15px;
            margin-bottom: 8px;
            border-radius: 6px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #176d20;
        }

        .main-content {
            flex: 1;
            padding: 30px;
            min-width: 0;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .topbar h1 {
            color: #238b2b;
            font-size: 27px;
        }

        .logout {
            background: #238b2b;
            color: white;
            text-decoration: none;
            padding: 10px 18px;
            border-radius: 5px;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            padding: 22px;
            border-radius: 9px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .summary-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .summary-card p {
            color: #238b2b;
            font-size: 27px;
            font-weight: bold;
        }

        .form-card,
        .table-card {
            background: white;
            padding: 25px;
            border-radius: 9px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .form-card h2,
        .table-card h2 {
            margin-bottom: 20px;
            color: #238b2b;
            font-size: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            font-size: 14px;
        }

        .form-group input {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
        }

        .form-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        button,
        .cancel-btn {
            border: none;
            cursor: pointer;
            padding: 11px 18px;
            border-radius: 5px;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }

        .primary-btn {
            background: #238b2b;
            color: white;
        }

        .primary-btn:hover {
            background: #176d20;
        }

        .cancel-btn {
            background: #777;
            color: white;
        }

        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .search-form input {
            flex: 1;
            min-width: 200px;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        th,
        td {
            padding: 13px;
            border-bottom: 1px solid #eee;
            text-align: left;
            font-size: 14px;
        }

        th {
            background: #edf7ed;
            color: #238b2b;
        }

        tr:hover {
            background: #fafafa;
        }

        .status {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-active {
            background: #e0f5e2;
            color: #238b2b;
        }

        .status-inactive {
            background: #eee;
            color: #666;
        }

        .edit-btn {
            background: #e5f1ff;
            color: #1764a0;
            text-decoration: none;
            padding: 7px 11px;
            border-radius: 4px;
            font-size: 13px;
        }

        .toggle-btn {
            padding: 7px 11px;
            border-radius: 4px;
            font-size: 13px;
            color: white;
        }

        .deactivate-btn {
            background: #c74646;
        }

        .activate-btn {
            background: #238b2b;
        }

        .message {
            padding: 13px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .success {
            background: #e0f5e2;
            color: #176d20;
        }

        .error {
            background: #fde8e8;
            color: #a12626;
        }

        .empty-message {
            text-align: center;
            padding: 25px;
            color: #777;
        }

        @media (max-width: 900px) {
            .sidebar {
                width: 200px;
            }

            .summary-cards,
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 650px) {
            .admin-layout {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
            }

            .main-content {
                padding: 18px;
            }
        }
    </style>
</head>

<body>

<div class="admin-layout">

    <aside class="sidebar">
        <h2>SafariTrack</h2>

        <a href="index.php">Dashboard</a>
        <a href="routes.php">Route Management</a>
        <a href="vehicles.php">Vehicle Management</a>
        <a href="drivers.php" class="active">Driver Management</a>
        <a href="trips.php">Trip Management</a>
        <a href="bookings.php">Booking Management</a>
        <a href="reports.php">Reports</a>
    </aside>

    <main class="main-content">

        <div class="topbar">
            <h1>Driver Management</h1>
            <a href="logout.php" class="logout">Logout</a>
        </div>

        <?php if ($success): ?>
            <div class="message success">
                <?= escape($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error">
                <?= escape($error) ?>
            </div>
        <?php endif; ?>

        <section class="summary-cards">

            <div class="summary-card">
                <h3>Total Drivers</h3>
                <p><?= $totalDrivers ?></p>
            </div>

            <div class="summary-card">
                <h3>Active Drivers</h3>
                <p><?= $activeDrivers ?></p>
            </div>

            <div class="summary-card">
                <h3>Inactive Drivers</h3>
                <p><?= $inactiveDrivers ?></p>
            </div>

        </section>

        <section class="form-card">

            <h2>
                <?= $editDriver ? "Edit Driver Details" : "Register New Driver" ?>
            </h2>

            <form method="POST" action="drivers.php">

                <input type="hidden" name="csrf_token"
                       value="<?= escape($csrfToken) ?>">

                <input type="hidden" name="action"
                       value="<?= $editDriver ? "update" : "add" ?>">

                <?php if ($editDriver): ?>
                    <input type="hidden" name="driver_id"
                           value="<?= (int)$editDriver["driver_id"] ?>">
                <?php endif; ?>

                <div class="form-grid">

                    <div class="form-group">
                        <label for="full_name">Driver Full Name</label>
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            maxlength="100"
                            required
                            value="<?= escape($editDriver["full_name"] ?? "") ?>"
                            placeholder="Enter full name"
                        >
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            maxlength="20"
                            required
                            value="<?= escape($editDriver["phone"] ?? "") ?>"
                            placeholder="e.g. 0712345678"
                        >
                    </div>

                    <div class="form-group">
                        <label for="license_number">Driving Licence Number</label>
                        <input
                            type="text"
                            id="license_number"
                            name="license_number"
                            maxlength="50"
                            required
                            value="<?= escape($editDriver["license_number"] ?? "") ?>"
                            placeholder="Enter licence number"
                        >
                    </div>

                </div>

                <div class="form-actions">

                    <button type="submit" class="primary-btn">
                        <?= $editDriver ? "Update Driver" : "Add Driver" ?>
                    </button>

                    <?php if ($editDriver): ?>
                        <a href="drivers.php" class="cancel-btn">Cancel</a>
                    <?php endif; ?>

                </div>

            </form>

        </section>

        <section class="table-card">

            <h2>Registered Drivers</h2>

            <form method="GET" action="drivers.php" class="search-form">

                <input
                    type="text"
                    name="search"
                    placeholder="Search by name, phone or licence number"
                    value="<?= escape($search) ?>"
                >

                <button type="submit" class="primary-btn">Search</button>

                <a href="drivers.php" class="cancel-btn">Clear</a>

            </form>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Driver ID</th>
                            <th>Full Name</th>
                            <th>Phone Number</th>
                            <th>Licence Number</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (count($drivers) > 0): ?>

                        <?php foreach ($drivers as $driver): ?>

                            <tr>

                                <td><?= (int)$driver["driver_id"] ?></td>

                                <td><?= escape($driver["full_name"]) ?></td>

                                <td><?= escape($driver["phone"]) ?></td>

                                <td><?= escape($driver["license_number"]) ?></td>

                                <td>
                                    <?php if ($driver["status"] === "Active"): ?>
                                        <span class="status status-active">Active</span>
                                    <?php else: ?>
                                        <span class="status status-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>

                                <td>

                                    <a
                                        class="edit-btn"
                                        href="drivers.php?edit=<?= (int)$driver["driver_id"] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form method="POST"
                                          action="drivers.php"
                                          style="display:inline;">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= escape($csrfToken) ?>">

                                        <input type="hidden"
                                               name="action"
                                               value="toggle_status">

                                        <input type="hidden"
                                               name="driver_id"
                                               value="<?= (int)$driver["driver_id"] ?>">

                                        <?php if ($driver["status"] === "Active"): ?>

                                            <button
                                                type="submit"
                                                class="toggle-btn deactivate-btn"
                                                onclick="return confirm('Are you sure you want to deactivate this driver?')"
                                            >
                                                Deactivate
                                            </button>

                                        <?php else: ?>

                                            <button
                                                type="submit"
                                                class="toggle-btn activate-btn"
                                                onclick="return confirm('Are you sure you want to activate this driver?')"
                                            >
                                                Activate
                                            </button>

                                        <?php endif; ?>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6" class="empty-message">
                                No drivers found.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </main>

</div>

</body>
</html>
