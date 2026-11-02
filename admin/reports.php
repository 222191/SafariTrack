<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

require_once "../config/database.php";

$adminName = $_SESSION['full_name'] ?? 'Administrator';

$startDate = $_GET['start_date'] ?? '2000-01-01';
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$errors = [];

$startDateObject = DateTime::createFromFormat('!Y-m-d', $startDate);
$endDateObject = DateTime::createFromFormat('!Y-m-d', $endDate);

if (!$startDateObject || $startDateObject->format('Y-m-d') !== $startDate) {
    $errors[] = "Please enter a valid start date.";
}

if (!$endDateObject || $endDateObject->format('Y-m-d') !== $endDate) {
    $errors[] = "Please enter a valid end date.";
}

if (!$errors && $startDate > $endDate) {
    $errors[] = "The start date cannot be after the end date.";
}

if ($errors) {
    $startDate = '2000-01-01';
    $endDate = date('Y-m-d');
}

$startDateTime = $startDate . ' 00:00:00';
$endDateTime = date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00';

try {
    // Booking summary
    $bookingSummaryQuery = $pdo->prepare("
        SELECT
            COUNT(*) AS total_bookings,
            SUM(CASE WHEN LOWER(status) = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_bookings,
            SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending_bookings,
            SUM(CASE WHEN LOWER(status) = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_bookings,
            SUM(CASE WHEN LOWER(status) = 'expired' THEN 1 ELSE 0 END) AS expired_bookings
        FROM bookings
        WHERE booking_date >= ?
        AND booking_date < ?
    ");

    $bookingSummaryQuery->execute([$startDateTime, $endDateTime]);
    $bookingSummary = $bookingSummaryQuery->fetch();

    // Revenue summary
    // Revenue summary
$revenueQuery = $pdo->prepare("
    SELECT
        COUNT(*) AS successful_payments,
        COALESCE(SUM(amount), 0) AS total_revenue
    FROM payments
    WHERE status = 'Successful'
    AND payment_date >= ?
    AND payment_date < ?
");

$revenueQuery->execute([$startDateTime, $endDateTime]);
$revenueSummary = $revenueQuery->fetch();
    // Payment method breakdown
    $paymentMethodsQuery = $pdo->prepare("
        SELECT
            method,
            COUNT(*) AS payment_count,
            COALESCE(SUM(amount), 0) AS total_amount
        FROM payments
        WHERE status = 'Successful'
        AND payment_date >= ?
        AND payment_date < ?
        GROUP BY method
        ORDER BY total_amount DESC
    ");

    $paymentMethodsQuery->execute([$startDateTime, $endDateTime]);
    $paymentMethods = $paymentMethodsQuery->fetchAll();

    // Trip summary
    $tripSummaryQuery = $pdo->prepare("
        SELECT
            COUNT(*) AS total_trips,
            SUM(CASE WHEN LOWER(status) = 'completed' THEN 1 ELSE 0 END) AS completed_trips,
            SUM(CASE WHEN LOWER(status) = 'scheduled' THEN 1 ELSE 0 END) AS scheduled_trips,
            SUM(CASE WHEN LOWER(status) = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_trips
        FROM trips
        WHERE departure_time >= ?
        AND departure_time < ?
    ");

    $tripSummaryQuery->execute([$startDateTime, $endDateTime]);
    $tripSummary = $tripSummaryQuery->fetch();

    // Recent bookings
    $recentBookingsQuery = $pdo->prepare("
        SELECT
            b.booking_id,
            b.booking_reference,
            b.booking_date,
            b.total_fare,
            b.status AS booking_status,
            u.full_name AS passenger_name,
            r.origin,
            r.destination,
            t.departure_time,
            p.method AS payment_method,
            p.status AS payment_status
        FROM bookings b
        JOIN users u ON b.user_id = u.user_id
        JOIN trips t ON b.trip_id = t.trip_id
        JOIN routes r ON t.route_id = r.route_id
        LEFT JOIN payments p ON p.payment_id = (
            SELECT MAX(p2.payment_id)
            FROM payments p2
            WHERE p2.booking_id = b.booking_id
        )
        WHERE b.booking_date >= ?
        AND b.booking_date < ?
        ORDER BY b.booking_date DESC
        LIMIT 20
    ");

    $recentBookingsQuery->execute([$startDateTime, $endDateTime]);
    $recentBookings = $recentBookingsQuery->fetchAll();

} catch (PDOException $e) {
    error_log("Reports error: " . $e->getMessage());
    die("Unable to load reports at the moment. Please check the database configuration.");
}

$totalBookings = (int) ($bookingSummary['total_bookings'] ?? 0);
$confirmedBookings = (int) ($bookingSummary['confirmed_bookings'] ?? 0);
$pendingBookings = (int) ($bookingSummary['pending_bookings'] ?? 0);
$cancelledBookings = (int) ($bookingSummary['cancelled_bookings'] ?? 0);
$expiredBookings = (int) ($bookingSummary['expired_bookings'] ?? 0);

$totalRevenue = (float) ($revenueSummary['total_revenue'] ?? 0);
$successfulPayments = (int) ($revenueSummary['successful_payments'] ?? 0);

$totalTrips = (int) ($tripSummary['total_trips'] ?? 0);
$completedTrips = (int) ($tripSummary['completed_trips'] ?? 0);
$scheduledTrips = (int) ($tripSummary['scheduled_trips'] ?? 0);
$cancelledTrips = (int) ($tripSummary['cancelled_trips'] ?? 0);

function escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function statusClass($status) {
    $status = strtolower((string) $status);

    switch ($status) {
        case 'confirmed':
        case 'successful':
        case 'completed':
        case 'active':
            return 'success';

        case 'pending':
        case 'scheduled':
            return 'warning';

        case 'cancelled':
        case 'failed':
        case 'expired':
        case 'inactive':
            return 'danger';

        default:
            return 'secondary';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - SafariTrack</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f5f7f5;
            font-family: Arial, sans-serif;
            color: #333;
        }

        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 240px;
            background: #319b35;
            color: white;
            padding: 25px 15px;
            flex-shrink: 0;
        }

        .sidebar h2 {
            text-align: center;
            margin: 0 0 35px;
            font-size: 25px;
        }

        .sidebar a {
            display: block;
            color: white;
            text-decoration: none;
            padding: 13px 15px;
            margin-bottom: 8px;
            border-radius: 6px;
            transition: 0.2s;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #267d2a;
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
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .topbar h1 {
            margin: 0;
            color: #267d2a;
        }

        .admin-info {
            color: #555;
        }

        .filter-card,
        .report-card,
        .table-card {
            background: white;
            border-radius: 10px;
            padding: 22px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .filter-form {
            display: flex;
            align-items: end;
            gap: 15px;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .filter-group label {
            font-weight: bold;
            font-size: 14px;
        }

        .filter-group input {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            min-width: 170px;
        }

        .btn {
            border: none;
            padding: 11px 18px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            display: inline-block;
        }

        .btn-primary {
            background: #319b35;
            color: white;
        }

        .btn-primary:hover {
            background: #267d2a;
        }

        .btn-secondary {
            background: #e5e7eb;
            color: #333;
        }

        .btn-secondary:hover {
            background: #d1d5db;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            padding: 22px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-left: 5px solid #319b35;
        }

        .summary-card h3 {
            margin: 0 0 12px;
            color: #666;
            font-size: 14px;
            font-weight: normal;
        }

        .summary-card p {
            margin: 0;
            font-size: 27px;
            font-weight: bold;
            color: #267d2a;
        }

        .report-card h2,
        .table-card h2 {
            margin-top: 0;
            color: #267d2a;
        }

        .report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 15px;
        }

        .report-item {
            background: #f5f7f5;
            padding: 15px;
            border-radius: 7px;
        }

        .report-item span {
            display: block;
            color: #666;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .report-item strong {
            font-size: 21px;
            color: #333;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th,
        td {
            padding: 13px 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }

        th {
            background: #f0f5f0;
            color: #267d2a;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .success {
            background: #d9f3dc;
            color: #267d2a;
        }

        .warning {
            background: #fff1cc;
            color: #8a6500;
        }

        .danger {
            background: #fbdada;
            color: #a12626;
        }

        .secondary {
            background: #e5e7eb;
            color: #555;
        }

        .empty-message {
            text-align: center;
            padding: 25px;
            color: #777;
        }

        .error-message {
            background: #fbdada;
            color: #a12626;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        .section-heading {
            margin: 28px 0 15px;
            color: #267d2a;
        }

        @media print {
            .sidebar,
            .filter-card,
            .no-print {
                display: none !important;
            }

            .admin-layout {
                display: block;
            }

            .main-content {
                padding: 0;
            }

            body {
                background: white;
            }

            .summary-card,
            .report-card,
            .table-card {
                box-shadow: none;
                border: 1px solid #ddd;
                break-inside: avoid;
            }
        }

        @media (max-width: 750px) {
            .admin-layout {
                display: block;
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
        <a href="trips.php">Trip Management</a>
        <a href="bookings.php">Booking Management</a>
        <a href="reports.php" class="active">Reports</a>
        <a href="logout.php">Logout</a>
    </aside>

    <main class="main-content">

        <div class="topbar">
            <div>
                <h1>Reports Management</h1>
                <p>View booking, payment and trip performance.</p>
            </div>

            <div class="admin-info">
                Welcome, <strong><?= escape($adminName) ?></strong>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="error-message">
                <?php foreach ($errors as $error): ?>
                    <p><?= escape($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="filter-card">
            <h2>Filter Reports</h2>

            <form method="GET" class="filter-form">

                <div class="filter-group">
                    <label for="start_date">Start Date</label>
                    <input
                        type="date"
                        id="start_date"
                        name="start_date"
                        value="<?= escape($startDate) ?>"
                        required
                    >
                </div>

                <div class="filter-group">
                    <label for="end_date">End Date</label>
                    <input
                        type="date"
                        id="end_date"
                        name="end_date"
                        value="<?= escape($endDate) ?>"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary">
                    Generate Report
                </button>

                <a href="reports.php" class="btn btn-secondary">
                    Show All Records
                </a>

                <button
                    type="button"
                    onclick="window.print()"
                    class="btn btn-secondary no-print"
                >
                    Print Report
                </button>

            </form>
        </section>

        <h2 class="section-heading">Booking Overview</h2>

        <section class="summary-grid">

            <div class="summary-card">
                <h3>Total Bookings</h3>
                <p><?= number_format($totalBookings) ?></p>
            </div>

            <div class="summary-card">
                <h3>Confirmed Bookings</h3>
                <p><?= number_format($confirmedBookings) ?></p>
            </div>

            <div class="summary-card">
                <h3>Pending Bookings</h3>
                <p><?= number_format($pendingBookings) ?></p>
            </div>

            <div class="summary-card">
                <h3>Cancelled Bookings</h3>
                <p><?= number_format($cancelledBookings) ?></p>
            </div>

            <div class="summary-card">
                <h3>Expired Bookings</h3>
                <p><?= number_format($expiredBookings) ?></p>
            </div>

        </section>

        <h2 class="section-heading">Financial Overview</h2>

        <section class="summary-grid">

            <div class="summary-card">
                <h3>Total Revenue</h3>
                <p>KES <?= number_format($totalRevenue, 2) ?></p>
            </div>

            <div class="summary-card">
                <h3>Successful Payments</h3>
                <p><?= number_format($successfulPayments) ?></p>
            </div>

        </section>

        <section class="report-card">
            <h2>Payment Methods</h2>

            <?php if (count($paymentMethods) > 0): ?>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Payment Method</th>
                                <th>Number of Payments</th>
                                <th>Total Amount</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($paymentMethods as $payment): ?>
                                <tr>
                                    <td><?= escape($payment['method']) ?></td>
                                    <td><?= number_format($payment['payment_count']) ?></td>
                                    <td>KES <?= number_format((float) $payment['total_amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>

                <p class="empty-message">
                    No successful payment records found for this date range.
                </p>

            <?php endif; ?>
        </section>

        <h2 class="section-heading">Trip Overview</h2>

        <section class="summary-grid">

            <div class="summary-card">
                <h3>Total Trips</h3>
                <p><?= number_format($totalTrips) ?></p>
            </div>

            <div class="summary-card">
                <h3>Completed Trips</h3>
                <p><?= number_format($completedTrips) ?></p>
            </div>

            <div class="summary-card">
                <h3>Scheduled Trips</h3>
                <p><?= number_format($scheduledTrips) ?></p>
            </div>

            <div class="summary-card">
                <h3>Cancelled Trips</h3>
                <p><?= number_format($cancelledTrips) ?></p>
            </div>

        </section>

        <section class="table-card">
            <h2>Recent Bookings</h2>

            <?php if (count($recentBookings) > 0): ?>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Passenger</th>
                                <th>Journey</th>
                                <th>Departure</th>
                                <th>Booking Date</th>
                                <th>Fare</th>
                                <th>Booking Status</th>
                                <th>Payment Method</th>
                                <th>Payment Status</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($recentBookings as $booking): ?>

                                <tr>
                                    <td><?= escape($booking['booking_reference']) ?></td>

                                    <td><?= escape($booking['passenger_name']) ?></td>

                                    <td>
                                        <?= escape($booking['origin']) ?>
                                        →
                                        <?= escape($booking['destination']) ?>
                                    </td>

                                    <td>
                                        <?= escape(date(
                                            'd M Y, h:i A',
                                            strtotime($booking['departure_time'])
                                        )) ?>
                                    </td>

                                    <td>
                                        <?= escape(date(
                                            'd M Y, h:i A',
                                            strtotime($booking['booking_date'])
                                        )) ?>
                                    </td>

                                    <td>
                                        KES <?= number_format((float) $booking['total_fare'], 2) ?>
                                    </td>

                                    <td>
                                        <span class="status <?= statusClass($booking['booking_status']) ?>">
                                            <?= escape($booking['booking_status']) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= escape($booking['payment_method'] ?? 'N/A') ?>
                                    </td>

                                    <td>
                                        <span class="status <?= statusClass($booking['payment_status'] ?? '') ?>">
                                            <?= escape($booking['payment_status'] ?? 'No payment') ?>
                                        </span>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        </tbody>
                    </table>
                </div>

            <?php else: ?>

                <p class="empty-message">
                    No bookings found for the selected date range.
                </p>

            <?php endif; ?>

        </section>

    </main>

</div>

</body>
</html>