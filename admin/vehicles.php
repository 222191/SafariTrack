
<?php
session_start();

require_once "../config/database.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = "";
$messageType = "success";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("Invalid request. Please refresh the page and try again.");
    }

    $action = $_POST['action'] ?? '';

    try {

        if ($action === 'add') {
            $registration = strtoupper(trim($_POST['registration_number'] ?? ''));
            $capacity = filter_var($_POST['capacity'] ?? '', FILTER_VALIDATE_INT);

            if ($registration === '' || !$capacity || $capacity < 1) {
                throw new Exception("Enter a valid registration number and capacity.");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO vehicles (registration_number, capacity, status)
                VALUES (?, ?, 'Active')
            ");

            $stmt->execute([$registration, $capacity]);

            $vehicle_id = (int)$pdo->lastInsertId();

            $seatStmt = $pdo->prepare("
                INSERT INTO seats (vehicle_id, seat_number, status)
                VALUES (?, ?, 'Available')
            ");

            for ($i = 1; $i <= $capacity; $i++) {
                $seatStmt->execute([$vehicle_id, 'A' . $i]);
            }

            $pdo->commit();

            $audit = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, created_at, details)
                VALUES (?, ?, NOW(), ?)
            ");

            $audit->execute([
                $_SESSION['user_id'],
                'Vehicle management',
                "Added vehicle $registration with capacity $capacity."
            ]);

            $message = "Vehicle registered successfully and $capacity seats were generated.";

        } elseif ($action === 'update') {
            $vehicle_id = filter_var($_POST['vehicle_id'] ?? '', FILTER_VALIDATE_INT);
            $registration = strtoupper(trim($_POST['registration_number'] ?? ''));
            $capacity = filter_var($_POST['capacity'] ?? '', FILTER_VALIDATE_INT);

            if (!$vehicle_id || $registration === '' || !$capacity || $capacity < 1) {
                throw new Exception("Enter valid vehicle details.");
            }

            $pdo->beginTransaction();

            $findVehicle = $pdo->prepare("
                SELECT capacity
                FROM vehicles
                WHERE vehicle_id = ?
                FOR UPDATE
            ");

            $findVehicle->execute([$vehicle_id]);
            $existingVehicle = $findVehicle->fetch();

            if (!$existingVehicle) {
                throw new Exception("Vehicle not found.");
            }

            if ($capacity < (int)$existingVehicle['capacity']) {
                throw new Exception("Vehicle capacity cannot be reduced. Existing seats and booking records must be preserved.");
            }

            $updateStmt = $pdo->prepare("
                UPDATE vehicles
                SET registration_number = ?, capacity = ?
                WHERE vehicle_id = ?
            ");

            $updateStmt->execute([$registration, $capacity, $vehicle_id]);

            $seatStmt = $pdo->prepare("
                INSERT INTO seats (vehicle_id, seat_number, status)
                VALUES (?, ?, 'Available')
                ON DUPLICATE KEY UPDATE seat_number = VALUES(seat_number)
            ");

            for ($i = 1; $i <= $capacity; $i++) {
                $seatStmt->execute([$vehicle_id, 'A' . $i]);
            }

            $pdo->commit();

            $audit = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, created_at, details)
                VALUES (?, ?, NOW(), ?)
            ");

            $audit->execute([
                $_SESSION['user_id'],
                'Vehicle management',
                "Updated vehicle ID $vehicle_id. Registration: $registration; capacity: $capacity."
            ]);

            $message = "Vehicle updated successfully.";

        } elseif ($action === 'deactivate' || $action === 'activate') {
            $vehicle_id = filter_var($_POST['vehicle_id'] ?? '', FILTER_VALIDATE_INT);

            if (!$vehicle_id) {
                throw new Exception("Invalid vehicle selected.");
            }

            $newStatus = $action === 'deactivate' ? 'Inactive' : 'Active';

            $stmt = $pdo->prepare("
                UPDATE vehicles
                SET status = ?
                WHERE vehicle_id = ?
            ");

            $stmt->execute([$newStatus, $vehicle_id]);

            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare("
                    SELECT vehicle_id FROM vehicles WHERE vehicle_id = ?
                ");
                $check->execute([$vehicle_id]);

                if (!$check->fetch()) {
                    throw new Exception("Vehicle not found.");
                }
            }

            $audit = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, created_at, details)
                VALUES (?, ?, NOW(), ?)
            ");

            $audit->execute([
                $_SESSION['user_id'],
                'Vehicle management',
                "Changed vehicle ID $vehicle_id status to $newStatus."
            ]);

            $message = "Vehicle status updated successfully.";

        } else {
            throw new Exception("Invalid action.");
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $message = $e->getMessage();
        $messageType = "error";

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("Vehicle management error: " . $e->getMessage());

        if ($e->getCode() === '23000') {
            $message = "That vehicle registration number already exists.";
        } else {
            $message = "Unable to complete the vehicle operation. Please try again.";
        }

        $messageType = "error";
    }
}

$editVehicle = null;

if (isset($_GET['edit'])) {
    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

    if ($editId) {
        $stmt = $pdo->prepare("
            SELECT * FROM vehicles WHERE vehicle_id = ?
        ");

        $stmt->execute([$editId]);
        $editVehicle = $stmt->fetch();
    }
}

$search = trim($_GET['search'] ?? '');

try {
    if ($search !== '') {
        $stmt = $pdo->prepare("
            SELECT
                v.*,
                COUNT(s.seat_id) AS seat_count
            FROM vehicles v
            LEFT JOIN seats s ON v.vehicle_id = s.vehicle_id
            WHERE v.registration_number LIKE ?
            GROUP BY v.vehicle_id
            ORDER BY v.vehicle_id DESC
        ");

        $stmt->execute(["%$search%"]);
        $vehicles = $stmt->fetchAll();

    } else {
        $vehicles = $pdo->query("
            SELECT
                v.*,
                COUNT(s.seat_id) AS seat_count
            FROM vehicles v
            LEFT JOIN seats s ON v.vehicle_id = s.vehicle_id
            GROUP BY v.vehicle_id
            ORDER BY v.vehicle_id DESC
        ")->fetchAll();
    }

} catch (PDOException $e) {
    error_log("Vehicle retrieval error: " . $e->getMessage());
    die("Unable to retrieve vehicles.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Management | SafariTrack</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        body {
            background: #f4f4f4;
        }

        .admin-layout {
            display: flex;
            min-height: calc(100vh - 60px);
        }

        .sidebar {
            width: 230px;
            background: #267d2a;
            padding: 25px 15px;
            flex-shrink: 0;
        }

        .sidebar h3 {
            color: white;
            margin: 0 0 25px;
            padding: 0 10px;
        }

        .sidebar a {
            display: block;
            color: white;
            text-decoration: none;
            padding: 13px 12px;
            margin-bottom: 7px;
            border-radius: 5px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #319b35;
        }

        .main-content {
            flex: 1;
            padding: 30px;
            min-width: 0;
        }

        .page-heading h2 {
            color: #267d2a;
            margin-bottom: 8px;
        }

        .form-card,
        .table-card {
            background: white;
            padding: 25px;
            border-radius: 9px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .form-card h3,
        .table-card h3 {
            color: #267d2a;
            margin-top: 0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        .form-group input {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
        }

        .primary-button {
            background: #319b35;
            color: white;
            border: none;
            padding: 12px 22px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            margin-top: 18px;
        }

        .message {
            padding: 13px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .success-message {
            background: #e8f5e9;
            color: #267d2a;
        }

        .error-message {
            background: #fce8e8;
            color: #b42318;
        }

        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-form input {
            flex: 1;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        .search-form button {
            background: #319b35;
            color: white;
            border: none;
            border-radius: 5px;
            padding: 10px 20px;
            cursor: pointer;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        th, td {
            padding: 13px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        th {
            background: #f1f8f1;
            color: #267d2a;
        }

        .status-active {
            color: #267d2a;
            font-weight: bold;
        }

        .status-inactive {
            color: #b42318;
            font-weight: bold;
        }

        .action-link {
            color: #267d2a;
            text-decoration: none;
            margin-right: 12px;
        }

        .action-button {
            border: none;
            background: none;
            cursor: pointer;
            font-weight: bold;
        }

        .deactivate-button {
            color: #b42318;
        }

        .activate-button {
            color: #267d2a;
        }

        .cancel-link {
            margin-left: 12px;
            color: #555;
            text-decoration: none;
        }

        @media (max-width: 900px) {
            .admin-layout {
                flex-direction: column;
            }

            .sidebar {
                width: auto;
            }

            .sidebar a {
                display: inline-block;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<nav class="navbar">
    <div class="logo">SafariTrack Admin</div>

    <div class="nav-links">
        <a href="../index.php">Passenger Site</a>
        <a href="logout.php">Logout</a>
    </div>
</nav>

<div class="admin-layout">

    <aside class="sidebar">
        <h3>Administration</h3>

        <a href="index.php">Dashboard</a>
        <a href="routes.php">Manage Routes</a>
        <a href="vehicles.php" class="active">Manage Vehicles</a>
        <a href="drivers.php">Manage Drivers</a>
        <a href="trips.php">Manage Trips</a>
        <a href="bookings.php">Manage Bookings</a>
        <a href="reports.php">Reports</a>
    </aside>

    <main class="main-content">

        <div class="page-heading">
            <h2>Vehicle Management</h2>
            <p>Register vehicles, manage their capacity and maintain the fleet.</p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="message <?= $messageType === 'success' ? 'success-message' : 'error-message' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <section class="form-card">

            <h3><?= $editVehicle ? 'Update Vehicle' : 'Register New Vehicle' ?></h3>

            <form method="POST" action="vehicles.php">

                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <input type="hidden" name="action"
                       value="<?= $editVehicle ? 'update' : 'add' ?>">

                <?php if ($editVehicle): ?>
                    <input type="hidden" name="vehicle_id"
                           value="<?= (int)$editVehicle['vehicle_id'] ?>">
                <?php endif; ?>

                <div class="form-grid">

                    <div class="form-group">
                        <label for="registration_number">Vehicle Registration Number</label>

                        <input
                            type="text"
                            id="registration_number"
                            name="registration_number"
                            value="<?= htmlspecialchars($editVehicle['registration_number'] ?? '') ?>"
                            placeholder="e.g. KDA 123A"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="capacity">Passenger Capacity</label>

                        <input
                            type="number"
                            id="capacity"
                            name="capacity"
                            min="<?= $editVehicle ? (int)$editVehicle['capacity'] : 1 ?>"
                            value="<?= htmlspecialchars((string)($editVehicle['capacity'] ?? '')) ?>"
                            placeholder="e.g. 14"
                            required
                        >
                    </div>

                </div>

                <?php if ($editVehicle): ?>
                    <p>Existing vehicle capacity can be increased but not reduced, to protect existing seat and booking records.</p>
                <?php else: ?>
                    <p>Seats will automatically be generated using the A1, A2, A3 numbering format.</p>
                <?php endif; ?>

                <button type="submit" class="primary-button">
                    <?= $editVehicle ? 'UPDATE VEHICLE' : 'REGISTER VEHICLE' ?>
                </button>

                <?php if ($editVehicle): ?>
                    <a href="vehicles.php" class="cancel-link">Cancel Editing</a>
                <?php endif; ?>

            </form>

        </section>

        <section class="table-card">

            <h3>Registered Vehicles</h3>

            <form method="GET" action="vehicles.php" class="search-form">

                <input
                    type="text"
                    name="search"
                    value="<?= htmlspecialchars($search) ?>"
                    placeholder="Search by registration number"
                >

                <button type="submit">SEARCH</button>

            </form>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Vehicle ID</th>
                            <th>Registration</th>
                            <th>Capacity</th>
                            <th>Registered Seats</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (count($vehicles) > 0): ?>

                            <?php foreach ($vehicles as $vehicle): ?>

                                <tr>

                                    <td><?= (int)$vehicle['vehicle_id'] ?></td>

                                    <td>
                                        <?= htmlspecialchars($vehicle['registration_number']) ?>
                                    </td>

                                    <td><?= (int)$vehicle['capacity'] ?></td>

                                    <td><?= (int)$vehicle['seat_count'] ?></td>

                                    <td class="<?= $vehicle['status'] === 'Active' ? 'status-active' : 'status-inactive' ?>">
                                        <?= htmlspecialchars($vehicle['status']) ?>
                                    </td>

                                    <td>

                                        <a class="action-link"
                                           href="vehicles.php?edit=<?= (int)$vehicle['vehicle_id'] ?>">
                                            Edit
                                        </a>

                                        <form method="POST" action="vehicles.php" style="display:inline;">

                                            <input type="hidden" name="csrf_token"
                                                   value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                                            <input type="hidden" name="vehicle_id"
                                                   value="<?= (int)$vehicle['vehicle_id'] ?>">

                                            <?php if ($vehicle['status'] === 'Active'): ?>

                                                <input type="hidden" name="action" value="deactivate">

                                                <button
                                                    type="submit"
                                                    class="action-button deactivate-button"
                                                    onclick="return confirm('Are you sure you want to deactivate this vehicle?')"
                                                >
                                                    Deactivate
                                                </button>

                                            <?php else: ?>

                                                <input type="hidden" name="action" value="activate">

                                                <button
                                                    type="submit"
                                                    class="action-button activate-button"
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
                                <td colspan="6">No vehicles found.</td>
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
