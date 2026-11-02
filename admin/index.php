
<?php
session_start();

require_once "../config/database.php";

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header("Location: login.php");
    exit;
}

try {
    $passengerCount = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'passenger'
    ")->fetchColumn();

    $bookingCount = $pdo->query("
        SELECT COUNT(*)
        FROM bookings
    ")->fetchColumn();

    $confirmedBookingCount = $pdo->query("
        SELECT COUNT(*)
        FROM bookings
        WHERE status = 'confirmed'
    ")->fetchColumn();

    $tripCount = $pdo->query("
        SELECT COUNT(*)
        FROM trips
        WHERE status = 'Scheduled'
    ")->fetchColumn();

    $vehicleCount = $pdo->query("
        SELECT COUNT(*)
        FROM vehicles
        WHERE status = 'Active'
    ")->fetchColumn();

    $revenue = $pdo->query("
        SELECT COALESCE(SUM(amount), 0)
        FROM payments
        WHERE status = 'Successful'
    ")->fetchColumn();

    $successfulPaymentCount = $pdo->query("
        SELECT COUNT(*)
        FROM payments
        WHERE status = 'Successful'
    ")->fetchColumn();

    $recentBookings = $pdo->query("
        SELECT
            b.booking_reference,
            u.full_name,
            r.origin,
            r.destination,
            b.total_fare,
            b.status,
            b.booking_date,
            (
                SELECT p.status
                FROM payments p
                WHERE p.booking_id = b.booking_id
                ORDER BY p.payment_id DESC
                LIMIT 1
            ) AS payment_status
        FROM bookings b
        INNER JOIN users u ON b.user_id = u.user_id
        INNER JOIN trips t ON b.trip_id = t.trip_id
        INNER JOIN routes r ON t.route_id = r.route_id
        ORDER BY b.booking_date DESC
        LIMIT 5
    ")->fetchAll();

} catch (PDOException $e) {
    error_log("Admin dashboard error: " . $e->getMessage());
    die("Unable to load the administrator dashboard.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | SafariTrack</title>
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

        .welcome-section {
            margin-bottom: 25px;
        }

        .welcome-section h2 {
            color: #267d2a;
            margin-bottom: 8px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 23px;
            border-radius: 9px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .stat-card h3 {
            color: #666;
            font-size: 15px;
            margin: 0 0 12px;
        }

        .stat-card p {
            color: #319b35;
            font-size: 25px;
            font-weight: bold;
            margin: 0;
        }

        .recent-section {
            background: white;
            padding: 22px;
            border-radius: 9px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            overflow-x: auto;
        }

        .recent-section h3 {
            color: #267d2a;
            margin-top: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
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

        .status {
            font-weight: bold;
            text-transform: capitalize;
        }

        .payment-successful {
            color: #267d2a;
        }

        .payment-failed {
            color: #b00020;
        }

        .payment-pending {
            color: #b7791f;
        }

        @media (max-width: 850px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .admin-layout {
                flex-direction: column;
            }

            .sidebar {
                width: auto;
            }

            .sidebar a {
                display: inline-block;
            }
        }

        @media (max-width: 500px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .main-content {
                padding: 18px;
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

        <a href="index.php" class="active">Dashboard</a>
        <a href="routes.php">Manage Routes</a>
        <a href="vehicles.php">Manage Vehicles</a>
        <a href="drivers.php">Manage Drivers</a>
        <a href="trips.php">Manage Trips</a>
        <a href="bookings.php">Manage Bookings</a>
        <a href="reports.php">Reports</a>
    </aside>

    <main class="main-content">

        <div class="welcome-section">
            <h2>
                Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?>
            </h2>

            <p>
                Here is an overview of your SafariTrack management system.
            </p>
        </div>

        <div class="stats-grid">

            <div class="stat-card">
                <h3>Total Passengers</h3>
                <p><?= number_format((int) $passengerCount) ?></p>
            </div>

            <div class="stat-card">
                <h3>Total Bookings</h3>
                <p><?= number_format((int) $bookingCount) ?></p>
            </div>

            <div class="stat-card">
                <h3>Confirmed Bookings</h3>
                <p><?= number_format((int) $confirmedBookingCount) ?></p>
            </div>

            <div class="stat-card">
                <h3>Scheduled Trips</h3>
                <p><?= number_format((int) $tripCount) ?></p>
            </div>

            <div class="stat-card">
                <h3>Active Vehicles</h3>
                <p><?= number_format((int) $vehicleCount) ?></p>
            </div>

            <div class="stat-card">
                <h3>Successful Payments</h3>
                <p><?= number_format((int) $successfulPaymentCount) ?></p>
            </div>

            <div class="stat-card">
                <h3>Total Recorded Revenue</h3>
                <p>KES <?= number_format((float) $revenue, 2) ?></p>
            </div>

        </div>

        <div class="recent-section">

            <h3>Recent Bookings</h3>

            <?php if (count($recentBookings) > 0): ?>

                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Passenger</th>
                            <th>Journey</th>
                            <th>Fare</th>
                            <th>Booking Status</th>
                            <th>Payment Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($recentBookings as $booking): ?>

                            <?php
                            $paymentStatus = $booking['payment_status'] ?? 'Not paid';

                            $paymentClass = '';

                            if (strtolower($paymentStatus) === 'successful') {
                                $paymentClass = 'payment-successful';
                            } elseif (strtolower($paymentStatus) === 'failed') {
                                $paymentClass = 'payment-failed';
                            } elseif (strtolower($paymentStatus) === 'pending') {
                                $paymentClass = 'payment-pending';
                            }
                            ?>

                            <tr>
                                <td>
                                    <?= htmlspecialchars($booking['booking_reference']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($booking['full_name']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($booking['origin']) ?>
                                    to
                                    <?= htmlspecialchars($booking['destination']) ?>
                                </td>

                                <td>
                                    KES <?= number_format((float) $booking['total_fare'], 2) ?>
                                </td>

                                <td class="status">
                                    <?= htmlspecialchars($booking['status']) ?>
                                </td>

                                <td class="<?= $paymentClass ?>">
                                    <?= htmlspecialchars($paymentStatus) ?>
                                </td>

                                <td>
                                    <?= date('d M Y', strtotime($booking['booking_date'])) ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>
                </table>

            <?php else: ?>

                <p>No bookings have been recorded yet.</p>

            <?php endif; ?>

        </div>

    </main>

</div>

</body>
</html>
