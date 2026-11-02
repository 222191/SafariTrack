
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
            $origin = trim($_POST['origin'] ?? '');
            $destination = trim($_POST['destination'] ?? '');
            $base_fare = filter_var($_POST['base_fare'] ?? '', FILTER_VALIDATE_FLOAT);

            if ($origin === '' || $destination === '' || $base_fare === false || $base_fare < 0) {
                throw new Exception("Enter valid route details and a fare of zero or more.");
            }

            if (strcasecmp($origin, $destination) === 0) {
                throw new Exception("Origin and destination cannot be the same.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO routes (origin, destination, base_fare, status)
                VALUES (?, ?, ?, 'Active')
            ");

            $stmt->execute([$origin, $destination, $base_fare]);

            $message = "Route added successfully.";

        } elseif ($action === 'update') {
            $route_id = filter_var($_POST['route_id'] ?? '', FILTER_VALIDATE_INT);
            $origin = trim($_POST['origin'] ?? '');
            $destination = trim($_POST['destination'] ?? '');
            $base_fare = filter_var($_POST['base_fare'] ?? '', FILTER_VALIDATE_FLOAT);

            if (!$route_id || $origin === '' || $destination === '' || $base_fare === false || $base_fare < 0) {
                throw new Exception("Enter valid route details.");
            }

            if (strcasecmp($origin, $destination) === 0) {
                throw new Exception("Origin and destination cannot be the same.");
            }

            $stmt = $pdo->prepare("
                UPDATE routes
                SET origin = ?, destination = ?, base_fare = ?
                WHERE route_id = ?
            ");

            $stmt->execute([$origin, $destination, $base_fare, $route_id]);

            $message = "Route updated successfully.";

        } elseif ($action === 'deactivate' || $action === 'activate') {
            $route_id = filter_var($_POST['route_id'] ?? '', FILTER_VALIDATE_INT);

            if (!$route_id) {
                throw new Exception("Invalid route selected.");
            }

            $newStatus = $action === 'deactivate' ? 'Inactive' : 'Active';

            $stmt = $pdo->prepare("
                UPDATE routes
                SET status = ?
                WHERE route_id = ?
            ");

            $stmt->execute([$newStatus, $route_id]);

            $message = "Route status updated successfully.";

        } else {
            throw new Exception("Invalid action.");
        }

        $audit = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, created_at, details)
            VALUES (?, ?, NOW(), ?)
        ");

        $audit->execute([
            $_SESSION['user_id'],
            'Route management',
            ucfirst($action) . " route. Route ID: " . ($route_id ?? $pdo->lastInsertId())
        ]);

    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = "error";
    } catch (PDOException $e) {
        error_log("Route management error: " . $e->getMessage());
        $message = "Unable to complete the route operation. Please try again.";
        $messageType = "error";
    }
}

$editRoute = null;

if (isset($_GET['edit'])) {
    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

    if ($editId) {
        $stmt = $pdo->prepare("
            SELECT * FROM routes WHERE route_id = ?
        ");
        $stmt->execute([$editId]);
        $editRoute = $stmt->fetch();
    }
}

try {
    $search = trim($_GET['search'] ?? '');

    if ($search !== '') {
        $stmt = $pdo->prepare("
            SELECT * FROM routes
            WHERE origin LIKE ? OR destination LIKE ?
            ORDER BY route_id DESC
        ");

        $term = "%" . $search . "%";
        $stmt->execute([$term, $term]);
        $routes = $stmt->fetchAll();

    } else {
        $routes = $pdo->query("
            SELECT * FROM routes
            ORDER BY route_id DESC
        ")->fetchAll();
    }

} catch (PDOException $e) {
    error_log("Route retrieval error: " . $e->getMessage());
    die("Unable to retrieve routes.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Management | SafariTrack</title>
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
            grid-template-columns: repeat(3, 1fr);
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

        .primary-button:hover {
            background: #267d2a;
        }

        .cancel-link {
            margin-left: 12px;
            color: #555;
            text-decoration: none;
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
        <a href="routes.php" class="active">Manage Routes</a>
        <a href="vehicles.php">Manage Vehicles</a>
        <a href="drivers.php">Manage Drivers</a>
        <a href="trips.php">Manage Trips</a>
        <a href="bookings.php">Manage Bookings</a>
        <a href="reports.php">Reports</a>
    </aside>

    <main class="main-content">

        <div class="page-heading">
            <h2>Route Management</h2>
            <p>Add, update, search and manage SafariTrack routes.</p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="message <?= $messageType === 'success' ? 'success-message' : 'error-message' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <section class="form-card">

            <h3><?= $editRoute ? 'Update Route' : 'Add New Route' ?></h3>

            <form method="POST" action="routes.php">

                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <input type="hidden" name="action"
                       value="<?= $editRoute ? 'update' : 'add' ?>">

                <?php if ($editRoute): ?>
                    <input type="hidden" name="route_id"
                           value="<?= (int)$editRoute['route_id'] ?>">
                <?php endif; ?>

                <div class="form-grid">

                    <div class="form-group">
                        <label for="origin">Origin</label>
                        <input type="text" id="origin" name="origin"
                               value="<?= htmlspecialchars($editRoute['origin'] ?? '') ?>"
                               placeholder="e.g. Nairobi" required>
                    </div>

                    <div class="form-group">
                        <label for="destination">Destination</label>
                        <input type="text" id="destination" name="destination"
                               value="<?= htmlspecialchars($editRoute['destination'] ?? '') ?>"
                               placeholder="e.g. Nakuru" required>
                    </div>

                    <div class="form-group">
                        <label for="base_fare">Base Fare (KES)</label>
                        <input type="number" id="base_fare" name="base_fare"
                               min="0" step="0.01"
                               value="<?= htmlspecialchars((string)($editRoute['base_fare'] ?? '')) ?>"
                               placeholder="e.g. 1000" required>
                    </div>

                </div>

                <button type="submit" class="primary-button">
                    <?= $editRoute ? 'UPDATE ROUTE' : 'ADD ROUTE' ?>
                </button>

                <?php if ($editRoute): ?>
                    <a href="routes.php" class="cancel-link">Cancel Editing</a>
                <?php endif; ?>

            </form>

        </section>

        <section class="table-card">

            <h3>Existing Routes</h3>

            <form method="GET" action="routes.php" class="search-form">
                <input type="text" name="search"
                       value="<?= htmlspecialchars($search) ?>"
                       placeholder="Search by origin or destination">

                <button type="submit">SEARCH</button>
            </form>

            <div class="table-wrapper">

                <table>
                    <thead>
                        <tr>
                            <th>Route ID</th>
                            <th>Origin</th>
                            <th>Destination</th>
                            <th>Base Fare</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (count($routes) > 0): ?>

                            <?php foreach ($routes as $route): ?>

                                <tr>
                                    <td><?= (int)$route['route_id'] ?></td>

                                    <td><?= htmlspecialchars($route['origin']) ?></td>

                                    <td><?= htmlspecialchars($route['destination']) ?></td>

                                    <td>
                                        KES <?= number_format((float)$route['base_fare'], 2) ?>
                                    </td>

                                    <td class="<?= $route['status'] === 'Active' ? 'status-active' : 'status-inactive' ?>">
                                        <?= htmlspecialchars($route['status']) ?>
                                    </td>

                                    <td>
                                        <a class="action-link"
                                           href="routes.php?edit=<?= (int)$route['route_id'] ?>">
                                            Edit
                                        </a>

                                        <form method="POST" action="routes.php" style="display:inline;">

                                            <input type="hidden" name="csrf_token"
                                                   value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                                            <input type="hidden" name="route_id"
                                                   value="<?= (int)$route['route_id'] ?>">

                                            <?php if ($route['status'] === 'Active'): ?>

                                                <input type="hidden" name="action" value="deactivate">

                                                <button type="submit"
                                                        class="action-button deactivate-button"
                                                        onclick="return confirm('Are you sure you want to deactivate this route?')">
                                                    Deactivate
                                                </button>

                                            <?php else: ?>

                                                <input type="hidden" name="action" value="activate">

                                                <button type="submit"
                                                        class="action-button activate-button">
                                                    Activate
                                                </button>

                                            <?php endif; ?>

                                        </form>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="6">No routes found.</td>
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
