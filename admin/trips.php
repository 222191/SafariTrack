
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
    $stmt = $pdo->prepare(
        "INSERT INTO audit_logs (user_id, action, details)
         VALUES (?, ?, ?)"
    );

    $stmt->execute([
        $_SESSION["user_id"] ?? null,
        $action,
        $details
    ]);
}

function checkTripConflict($pdo, $vehicleId, $driverId, $departureTime, $excludeId = 0) {
    $sql = "
        SELECT trip_id
        FROM trips
        WHERE departure_time = ?
          AND status NOT IN ('Cancelled', 'Completed')
          AND (vehicle_id = ? OR driver_id = ?)
    ";

    $params = [$departureTime, $vehicleId, $driverId];

    if ($excludeId > 0) {
        $sql .= " AND trip_id != ?";
        $params[] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetch() !== false;
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
            if ($action === "add" || $action === "update") {
                $tripId = (int)($_POST["trip_id"] ?? 0);
                $routeId = (int)($_POST["route_id"] ?? 0);
                $vehicleId = (int)($_POST["vehicle_id"] ?? 0);
                $driverId = (int)($_POST["driver_id"] ?? 0);
                $departureInput = trim($_POST["departure_time"] ?? "");
                $fareInput = trim($_POST["fare"] ?? "");

                if (
                    $routeId <= 0 ||
                    $vehicleId <= 0 ||
                    $driverId <= 0 ||
                    $departureInput === "" ||
                    $fareInput === ""
                ) {
                    throw new Exception("Please fill in all trip details.");
                }

                if (!is_numeric($fareInput) || (float)$fareInput < 0) {
                    throw new Exception("Enter a valid trip fare.");
                }

                $departureTime = str_replace("T", " ", $departureInput);

                if (strlen($departureTime) === 16) {
                    $departureTime .= ":00";
                }

                $date = DateTime::createFromFormat("Y-m-d H:i:s", $departureTime);

                if (!$date || $date->format("Y-m-d H:i:s") !== $departureTime) {
                    throw new Exception("Please enter a valid departure date and time.");
                }

                $stmt = $pdo->prepare(
                    "SELECT route_id FROM routes
                     WHERE route_id = ? AND status = 'Active'"
                );
                $stmt->execute([$routeId]);

                if (!$stmt->fetch()) {
                    throw new Exception("Select an active route.");
                }

                $stmt = $pdo->prepare(
                    "SELECT vehicle_id FROM vehicles
                     WHERE vehicle_id = ? AND status = 'Active'"
                );
                $stmt->execute([$vehicleId]);

                if (!$stmt->fetch()) {
                    throw new Exception("Select an active vehicle.");
                }

                $stmt = $pdo->prepare(
                    "SELECT driver_id FROM drivers
                     WHERE driver_id = ? AND status = 'Active'"
                );
                $stmt->execute([$driverId]);

                if (!$stmt->fetch()) {
                    throw new Exception("Select an active driver.");
                }

                if (
                    checkTripConflict(
                        $pdo,
                        $vehicleId,
                        $driverId,
                        $departureTime,
                        $action === "update" ? $tripId : 0
                    )
                ) {
                    throw new Exception(
                        "This vehicle or driver is already assigned to another trip at that departure time."
                    );
                }

                if ($action === "add") {
                    $stmt = $pdo->prepare(
                        "INSERT INTO trips
                         (route_id, vehicle_id, driver_id, departure_time, fare, status)
                         VALUES (?, ?, ?, ?, ?, 'Scheduled')"
                    );

                    $stmt->execute([
                        $routeId,
                        $vehicleId,
                        $driverId,
                        $departureTime,
                        $fareInput
                    ]);

                    $newTripId = $pdo->lastInsertId();

                    recordAudit(
                        $pdo,
                        "Trip scheduled",
                        "Created trip ID $newTripId"
                    );

                    $success = "Trip scheduled successfully.";
                } else {
                    $stmt = $pdo->prepare(
                        "SELECT status FROM trips WHERE trip_id = ?"
                    );
                    $stmt->execute([$tripId]);
                    $existingTrip = $stmt->fetch();

                    if (!$existingTrip) {
                        throw new Exception("Trip not found.");
                    }

                    if ($existingTrip["status"] === "Cancelled" ||
                        $existingTrip["status"] === "Completed") {
                        throw new Exception("Cancelled or completed trips cannot be edited.");
                    }

                    $stmt = $pdo->prepare(
                        "UPDATE trips
                         SET route_id = ?,
                             vehicle_id = ?,
                             driver_id = ?,
                             departure_time = ?,
                             fare = ?
                         WHERE trip_id = ?"
                    );

                    $stmt->execute([
                        $routeId,
                        $vehicleId,
                        $driverId,
                        $departureTime,
                        $fareInput,
                        $tripId
                    ]);

                    recordAudit(
                        $pdo,
                        "Trip updated",
                        "Updated trip ID $tripId"
                    );

                    $success = "Trip details updated successfully.";
                }
            }

            elseif ($action === "cancel" || $action === "restore") {
                $tripId = (int)($_POST["trip_id"] ?? 0);

                $stmt = $pdo->prepare(
                    "SELECT * FROM trips WHERE trip_id = ?"
                );
                $stmt->execute([$tripId]);
                $trip = $stmt->fetch();

                if (!$trip) {
                    throw new Exception("Trip not found.");
                }

                if ($action === "cancel") {
                    if ($trip["status"] === "Cancelled") {
                        throw new Exception("This trip is already cancelled.");
                    }

                    if ($trip["status"] === "Completed") {
                        throw new Exception("A completed trip cannot be cancelled.");
                    }

                    $newStatus = "Cancelled";
                    $auditAction = "Trip cancelled";
                    $success = "Trip cancelled successfully.";
                } else {
                    if ($trip["status"] !== "Cancelled") {
                        throw new Exception("Only cancelled trips can be restored.");
                    }

                    $stmt = $pdo->prepare(
                        "SELECT route_id FROM routes
                         WHERE route_id = ? AND status = 'Active'"
                    );
                    $stmt->execute([$trip["route_id"]]);

                    if (!$stmt->fetch()) {
                        throw new Exception("The route is inactive. Activate it before restoring this trip.");
                    }

                    $stmt = $pdo->prepare(
                        "SELECT vehicle_id FROM vehicles
                         WHERE vehicle_id = ? AND status = 'Active'"
                    );
                    $stmt->execute([$trip["vehicle_id"]]);

                    if (!$stmt->fetch()) {
                        throw new Exception("The vehicle is inactive. Activate it before restoring this trip.");
                    }

                    $stmt = $pdo->prepare(
                        "SELECT driver_id FROM drivers
                         WHERE driver_id = ? AND status = 'Active'"
                    );
                    $stmt->execute([$trip["driver_id"]]);

                    if (!$stmt->fetch()) {
                        throw new Exception("The driver is inactive. Activate them before restoring this trip.");
                    }

                    if (
                        checkTripConflict(
                            $pdo,
                            $trip["vehicle_id"],
                            $trip["driver_id"],
                            $trip["departure_time"],
                            $tripId
                        )
                    ) {
                        throw new Exception(
                            "This vehicle or driver has already been assigned to another trip at that departure time. Resolve the conflict before restoring."
                        );
                    }

                    $newStatus = "Scheduled";
                    $auditAction = "Trip restored";
                    $success = "Trip restored successfully and is now scheduled.";
                }

                $stmt = $pdo->prepare(
                    "UPDATE trips SET status = ? WHERE trip_id = ?"
                );
                $stmt->execute([$newStatus, $tripId]);

                recordAudit(
                    $pdo,
                    $auditAction,
                    "Trip ID $tripId changed to $newStatus"
                );
            }

            else {
                throw new Exception("Invalid action.");
            }

        } catch (PDOException $e) {
            error_log("Trip management error: " . $e->getMessage());
            $error = "The operation could not be completed. Please check the details and try again.";
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$editTrip = null;

if (isset($_GET["edit"])) {
    $editId = (int)$_GET["edit"];

    $stmt = $pdo->prepare(
        "SELECT * FROM trips WHERE trip_id = ?"
    );
    $stmt->execute([$editId]);
    $editTrip = $stmt->fetch();

    if (!$editTrip) {
        $error = "The selected trip could not be found.";
    }
}

$routes = $pdo->query(
    "SELECT route_id, origin, destination, base_fare
     FROM routes
     WHERE status = 'Active'
     ORDER BY origin, destination"
)->fetchAll();

$vehicles = $pdo->query(
    "SELECT vehicle_id, registration_number, capacity
     FROM vehicles
     WHERE status = 'Active'
     ORDER BY registration_number"
)->fetchAll();

$drivers = $pdo->query(
    "SELECT driver_id, full_name, phone
     FROM drivers
     WHERE status = 'Active'
     ORDER BY full_name"
)->fetchAll();

$sql = "
    SELECT
        t.trip_id,
        t.departure_time,
        t.fare,
        t.status,
        r.origin,
        r.destination,
        v.registration_number,
        v.capacity,
        d.full_name AS driver_name,
        d.phone AS driver_phone
    FROM trips t
    INNER JOIN routes r ON t.route_id = r.route_id
    INNER JOIN vehicles v ON t.vehicle_id = v.vehicle_id
    INNER JOIN drivers d ON t.driver_id = d.driver_id
";

$params = [];

if ($search !== "") {
    $sql .= "
        WHERE r.origin LIKE ?
           OR r.destination LIKE ?
           OR v.registration_number LIKE ?
           OR d.full_name LIKE ?
           OR t.status LIKE ?
    ";

    $term = "%$search%";
    $params = [$term, $term, $term, $term, $term];
}

$sql .= " ORDER BY t.departure_time DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$trips = $stmt->fetchAll();

$totalTrips = count($trips);
$scheduledTrips = 0;
$cancelledTrips = 0;

foreach ($trips as $trip) {
    if ($trip["status"] === "Scheduled" || $trip["status"] === "Active") {
        $scheduledTrips++;
    }

    if ($trip["status"] === "Cancelled") {
        $cancelledTrips++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trip Management | SafariTrack</title>

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
            min-width: 0;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 30px;
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

        .summary-card,
        .form-card,
        .table-card {
            background: white;
            padding: 23px;
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
            margin-bottom: 25px;
        }

        .form-card h2,
        .table-card h2 {
            color: #238b2b;
            font-size: 20px;
            margin-bottom: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: bold;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
            background: white;
            font-size: 14px;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        button,
        .cancel-btn {
            border: none;
            cursor: pointer;
            padding: 10px 16px;
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
            flex-wrap: wrap;
            margin-bottom: 20px;
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
            min-width: 950px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 13px;
            border-bottom: 1px solid #eee;
            text-align: left;
            font-size: 13px;
        }

        th {
            background: #edf7ed;
            color: #238b2b;
        }

        tr:hover {
            background: #fafafa;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-scheduled {
            background: #e0f5e2;
            color: #238b2b;
        }

        .status-active {
            background: #e5f1ff;
            color: #1764a0;
        }

        .status-cancelled {
            background: #fde8e8;
            color: #a12626;
        }

        .status-completed {
            background: #eee;
            color: #555;
        }

        .edit-btn {
            background: #e5f1ff;
            color: #1764a0;
            text-decoration: none;
            padding: 7px 10px;
            border-radius: 4px;
            font-size: 12px;
        }

        .cancel-trip-btn {
            background: #c74646;
            color: white;
            padding: 7px 10px;
            font-size: 12px;
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

        .help-text {
            color: #777;
            font-size: 12px;
            margin-top: 6px;
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
        <a href="drivers.php">Driver Management</a>
        <a href="trips.php" class="active">Trip Management</a>
        <a href="bookings.php">Booking Management</a>
        <a href="reports.php">Reports</a>
    </aside>

    <main class="main-content">

        <div class="topbar">
            <h1>Trip Management</h1>
            <a href="logout.php" class="logout">Logout</a>
        </div>

        <?php if ($success): ?>
            <div class="message success"><?= escape($success) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?= escape($error) ?></div>
        <?php endif; ?>

        <section class="summary-cards">

            <div class="summary-card">
                <h3>Total Trips</h3>
                <p><?= $totalTrips ?></p>
            </div>

            <div class="summary-card">
                <h3>Scheduled / Active Trips</h3>
                <p><?= $scheduledTrips ?></p>
            </div>

            <div class="summary-card">
                <h3>Cancelled Trips</h3>
                <p><?= $cancelledTrips ?></p>
            </div>

        </section>

        <section class="form-card">

            <h2>
                <?= $editTrip ? "Edit Trip Details" : "Schedule a New Trip" ?>
            </h2>

            <?php if (count($routes) === 0 || count($vehicles) === 0 || count($drivers) === 0): ?>

                <div class="message error">
                    You need at least one active route, one active vehicle and one active driver before scheduling a trip.
                </div>

            <?php else: ?>

                <form method="POST" action="trips.php">

                    <input type="hidden" name="csrf_token"
                           value="<?= escape($csrfToken) ?>">

                    <input type="hidden" name="action"
                           value="<?= $editTrip ? "update" : "add" ?>">

                    <?php if ($editTrip): ?>
                        <input type="hidden" name="trip_id"
                               value="<?= (int)$editTrip["trip_id"] ?>">
                    <?php endif; ?>

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="route_id">Select Route</label>

                            <select name="route_id" id="route_id" required>
                                <option value="">Choose a route</option>

                                <?php foreach ($routes as $route): ?>
                                    <option
                                        value="<?= (int)$route["route_id"] ?>"
                                        <?= isset($editTrip["route_id"]) && $editTrip["route_id"] == $route["route_id"] ? "selected" : "" ?>
                                    >
                                        <?= escape($route["origin"]) ?> to <?= escape($route["destination"]) ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="form-group">
                            <label for="vehicle_id">Select Vehicle</label>

                            <select name="vehicle_id" id="vehicle_id" required>
                                <option value="">Choose a vehicle</option>

                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option
                                        value="<?= (int)$vehicle["vehicle_id"] ?>"
                                        <?= isset($editTrip["vehicle_id"]) && $editTrip["vehicle_id"] == $vehicle["vehicle_id"] ? "selected" : "" ?>
                                    >
                                        <?= escape($vehicle["registration_number"]) ?>
                                        (<?= (int)$vehicle["capacity"] ?> seats)
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="form-group">
                            <label for="driver_id">Select Driver</label>

                            <select name="driver_id" id="driver_id" required>
                                <option value="">Choose a driver</option>

                                <?php foreach ($drivers as $driver): ?>
                                    <option
                                        value="<?= (int)$driver["driver_id"] ?>"
                                        <?= isset($editTrip["driver_id"]) && $editTrip["driver_id"] == $driver["driver_id"] ? "selected" : "" ?>
                                    >
                                        <?= escape($driver["full_name"]) ?>
                                        (<?= escape($driver["phone"]) ?>)
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div class="form-group">
                            <label for="departure_time">Departure Date and Time</label>

                            <input
                                type="datetime-local"
                                id="departure_time"
                                name="departure_time"
                                required
                                value="<?= $editTrip ? escape(date("Y-m-d\TH:i", strtotime($editTrip["departure_time"]))) : "" ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label for="fare">Trip Fare (KES)</label>

                            <input
                                type="number"
                                id="fare"
                                name="fare"
                                min="0"
                                step="0.01"
                                required
                                value="<?= $editTrip ? escape($editTrip["fare"]) : "" ?>"
                                placeholder="Enter trip fare"
                            >
                        </div>

                    </div>

                    <p class="help-text">
                        Only active routes, vehicles and drivers can be assigned to new or updated trips.
                    </p>

                    <div class="form-actions">

                        <button type="submit" class="primary-btn">
                            <?= $editTrip ? "Update Trip" : "Schedule Trip" ?>
                        </button>

                        <?php if ($editTrip): ?>
                            <a href="trips.php" class="cancel-btn">Cancel</a>
                        <?php endif; ?>

                    </div>

                </form>

            <?php endif; ?>

        </section>

        <section class="table-card">

            <h2>All Scheduled Trips</h2>

            <form method="GET" action="trips.php" class="search-form">

                <input
                    type="text"
                    name="search"
                    placeholder="Search route, vehicle, driver or status"
                    value="<?= escape($search) ?>"
                >

                <button type="submit" class="primary-btn">Search</button>

                <a href="trips.php" class="cancel-btn">Clear</a>

            </form>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Trip ID</th>
                            <th>Route</th>
                            <th>Vehicle</th>
                            <th>Driver</th>
                            <th>Departure</th>
                            <th>Fare (KES)</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (count($trips) > 0): ?>

                        <?php foreach ($trips as $trip): ?>

                            <tr>

                                <td><?= (int)$trip["trip_id"] ?></td>

                                <td>
                                    <?= escape($trip["origin"]) ?>
                                    to
                                    <?= escape($trip["destination"]) ?>
                                </td>

                                <td><?= escape($trip["registration_number"]) ?></td>

                                <td>
                                    <?= escape($trip["driver_name"]) ?>
                                    <br>
                                    <small><?= escape($trip["driver_phone"]) ?></small>
                                </td>

                                <td>
                                    <?= escape(date("d M Y, h:i A", strtotime($trip["departure_time"]))) ?>
                                </td>

                                <td><?= number_format((float)$trip["fare"], 2) ?></td>

                                <td>
                                    <?php
                                    $statusClass = "status-scheduled";

                                    if ($trip["status"] === "Active") {
                                        $statusClass = "status-active";
                                    } elseif ($trip["status"] === "Cancelled") {
                                        $statusClass = "status-cancelled";
                                    } elseif ($trip["status"] === "Completed") {
                                        $statusClass = "status-completed";
                                    }
                                    ?>

                                    <span class="status <?= $statusClass ?>">
                                        <?= escape($trip["status"]) ?>
                                    </span>
                                </td>

                                <td>

                                    <?php if ($trip["status"] === "Scheduled" || $trip["status"] === "Active"): ?>

                                        <a
                                            href="trips.php?edit=<?= (int)$trip["trip_id"] ?>"
                                            class="edit-btn"
                                        >
                                            Edit
                                        </a>

                                        <form method="POST" action="trips.php" style="display:inline;">

                                            <input type="hidden" name="csrf_token"
                                                   value="<?= escape($csrfToken) ?>">

                                            <input type="hidden" name="action" value="cancel">

                                            <input type="hidden" name="trip_id"
                                                   value="<?= (int)$trip["trip_id"] ?>">

                                            <button
                                                type="submit"
                                                class="cancel-trip-btn"
                                                onclick="return confirm('Are you sure you want to cancel this trip?')"
                                            >
                                                Cancel Trip
                                            </button>

                                        </form>

                                    <?php elseif ($trip["status"] === "Cancelled"): ?>

                                        <form method="POST" action="trips.php" style="display:inline;">

                                            <input type="hidden" name="csrf_token"
                                                   value="<?= escape($csrfToken) ?>">

                                            <input type="hidden" name="action" value="restore">

                                            <input type="hidden" name="trip_id"
                                                   value="<?= (int)$trip["trip_id"] ?>">

                                            <button
                                                type="submit"
                                                class="primary-btn"
                                                onclick="return confirm('Restore this trip and set it back to Scheduled?')"
                                            >
                                                Restore Trip
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span>—</span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="8" class="empty-message">
                                No trips found.
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
