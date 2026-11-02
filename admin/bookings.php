
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
$filter = $_GET["status"] ?? "";

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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($csrfToken, $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {
        $action = $_POST["action"] ?? "";

        try {
            if ($action === "cancel_pending") {
                $bookingId = (int)($_POST["booking_id"] ?? 0);

                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "SELECT booking_id, booking_reference, status
                     FROM bookings
                     WHERE booking_id = ?
                     FOR UPDATE"
                );

                $stmt->execute([$bookingId]);
                $booking = $stmt->fetch();

                if (!$booking) {
                    throw new Exception("Booking not found.");
                }

                if ($booking["status"] !== "Pending") {
                    throw new Exception(
                        "Only unpaid pending bookings can be cancelled here."
                    );
                }

                $stmt = $pdo->prepare(
                    "SELECT status
                     FROM payments
                     WHERE booking_id = ?
                     ORDER BY payment_id DESC
                     LIMIT 1"
                );

                $stmt->execute([$bookingId]);
                $payment = $stmt->fetch();

                if ($payment && $payment["status"] === "Successful") {
                    throw new Exception(
                        "This booking has a successful payment. It cannot be cancelled through this action."
                    );
                }

                $stmt = $pdo->prepare(
                    "UPDATE bookings
                     SET status = 'Cancelled'
                     WHERE booking_id = ?"
                );

                $stmt->execute([$bookingId]);

                recordAudit(
                    $pdo,
                    "Pending booking cancelled",
                    "Cancelled unpaid booking " . $booking["booking_reference"]
                );

                $pdo->commit();

                $success = "Pending booking cancelled successfully.";
            } else {
                throw new Exception("Invalid action.");
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Booking management error: " . $e->getMessage());
            $error = "The booking operation could not be completed.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}

$sql = "
    SELECT
        b.booking_id,
        b.booking_reference,
        b.booking_date,
        b.total_fare,
        b.status AS booking_status,

        u.full_name AS passenger_name,
        u.phone AS passenger_phone,
        u.email AS passenger_email,

        t.departure_time,
        t.status AS trip_status,

        r.origin,
        r.destination,

        v.registration_number,

        s.seat_number,

        p.method AS payment_method,
        p.transaction_reference,
        p.status AS payment_status,
        p.payment_date

    FROM bookings b

    INNER JOIN users u ON b.user_id = u.user_id
    INNER JOIN trips t ON b.trip_id = t.trip_id
    INNER JOIN routes r ON t.route_id = r.route_id
    INNER JOIN vehicles v ON t.vehicle_id = v.vehicle_id
    INNER JOIN seats s ON b.seat_id = s.seat_id

    LEFT JOIN payments p ON p.payment_id = (
        SELECT MAX(p2.payment_id)
        FROM payments p2
        WHERE p2.booking_id = b.booking_id
    )
";

$conditions = [];
$params = [];

if ($search !== "") {
    $conditions[] = "
        (
            b.booking_reference LIKE ?
            OR u.full_name LIKE ?
            OR u.phone LIKE ?
            OR r.origin LIKE ?
            OR r.destination LIKE ?
            OR v.registration_number LIKE ?
        )
    ";

    $term = "%$search%";

    $params = [
        $term,
        $term,
        $term,
        $term,
        $term,
        $term
    ];
}

$allowedStatuses = [
    "Pending",
    "Confirmed",
    "Cancelled",
    "Expired"
];

if (in_array($filter, $allowedStatuses, true)) {
    $conditions[] = "b.status = ?";
    $params[] = $filter;
}

if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY b.booking_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

$totalBookings = count($bookings);
$pendingBookings = 0;
$confirmedBookings = 0;
$cancelledBookings = 0;

foreach ($bookings as $booking) {
    if ($booking["booking_status"] === "Pending") {
        $pendingBookings++;
    } elseif ($booking["booking_status"] === "Confirmed") {
        $confirmedBookings++;
    } elseif ($booking["booking_status"] === "Cancelled") {
        $cancelledBookings++;
    }
}

$selectedBooking = null;

if (isset($_GET["view"])) {
    $viewId = (int)$_GET["view"];

    foreach ($bookings as $booking) {
        if ((int)$booking["booking_id"] === $viewId) {
            $selectedBooking = $booking;
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Management | SafariTrack</title>

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
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 25px;
        }

        .summary-card,
        .table-card,
        .details-card {
            background: white;
            padding: 23px;
            border-radius: 9px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            margin-bottom: 25px;
        }

        .summary-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .summary-card p {
            color: #238b2b;
            font-size: 26px;
            font-weight: bold;
        }

        .table-card h2,
        .details-card h2 {
            color: #238b2b;
            font-size: 20px;
            margin-bottom: 20px;
        }

        .search-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .search-form input,
        .search-form select {
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
            background: white;
        }

        .search-form input {
            flex: 1;
            min-width: 200px;
        }

        button,
        .clear-btn,
        .view-btn {
            border: none;
            cursor: pointer;
            padding: 9px 14px;
            border-radius: 5px;
            font-size: 13px;
            text-decoration: none;
            display: inline-block;
        }

        .primary-btn {
            background: #238b2b;
            color: white;
        }

        .clear-btn {
            background: #777;
            color: white;
        }

        .view-btn {
            background: #e5f1ff;
            color: #1764a0;
        }

        .cancel-btn {
            background: #c74646;
            color: white;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1100px;
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

        .status-confirmed,
        .status-successful {
            background: #e0f5e2;
            color: #238b2b;
        }

        .status-pending {
            background: #fff3d6;
            color: #8a6500;
        }

        .status-cancelled,
        .status-failed {
            background: #fde8e8;
            color: #a12626;
        }

        .status-expired {
            background: #eee;
            color: #666;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .detail-item {
            padding: 12px;
            background: #f7faf7;
            border-radius: 6px;
        }

        .detail-item strong {
            display: block;
            color: #666;
            font-size: 12px;
            margin-bottom: 6px;
        }

        .detail-item span {
            font-size: 14px;
            overflow-wrap: anywhere;
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

        .warning-text {
            background: #fff3d6;
            color: #755400;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 13px;
        }

        @media (max-width: 1000px) {
            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
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

            .summary-cards,
            .details-grid {
                grid-template-columns: 1fr;
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
        <a href="bookings.php" class="active">Booking Management</a>
        <a href="reports.php">Reports</a>
    </aside>

    <main class="main-content">

        <div class="topbar">
            <h1>Booking Management</h1>
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
                <h3>Total Bookings</h3>
                <p><?= $totalBookings ?></p>
            </div>

            <div class="summary-card">
                <h3>Pending Bookings</h3>
                <p><?= $pendingBookings ?></p>
            </div>

            <div class="summary-card">
                <h3>Confirmed Bookings</h3>
                <p><?= $confirmedBookings ?></p>
            </div>

            <div class="summary-card">
                <h3>Cancelled Bookings</h3>
                <p><?= $cancelledBookings ?></p>
            </div>

        </section>

        <?php if ($selectedBooking): ?>

            <section class="details-card">

                <h2>Booking Details</h2>

                <div class="details-grid">

                    <div class="detail-item">
                        <strong>Booking Reference</strong>
                        <span><?= escape($selectedBooking["booking_reference"]) ?></span>
                    </div>

                    <div class="detail-item">
                        <strong>Passenger Name</strong>
                        <span><?= escape($selectedBooking["passenger_name"]) ?></span>
                    </div>

                    <div class="detail-item">
                        <strong>Passenger Phone</strong>
                        <span><?= escape($selectedBooking["passenger_phone"]) ?></span>
                    </div>

                    <div class="detail-item">
                        <strong>Passenger Email</strong>
                        <span><?= escape($selectedBooking["passenger_email"] ?: "Not provided") ?></span>
                    </div>

                    <div class="detail-item">
                        <strong>Journey</strong>
                        <span><?= escape($selectedBooking["origin"]) ?> to <?= escape($selectedBooking["destination"]) ?></span>
                    </div>

                    <div class="detail-item">
                        <strong>Departure</strong>
                        <span><?= escape(date("d M Y, h:i A", strtotime($selectedBooking["departure_time"]))) ?></span>
                    </div>

                    <div class="detail-item">
                        <strong>Vehicle</strong>
                        <span><?= escape($selectedBooking["registration_number"]) ?></span>
                    </div>

                    <div class="detail-item">
                        <strong>Seat Number</strong>
                        <span><?= escape($selectedBooking["seat_number"]) ?></span>
                    </div>

                    <div class="detail-item">
                        <strong>Booking Date</strong>
                        <span><?= escape(date("d M Y, h:i A", strtotime($selectedBooking["booking_date"]))) ?></span>
                    </div>

                    <div class="detail-item">
                        <strong>Total Fare</strong>
                        <span>KES <?= number_format((float)$selectedBooking["total_fare"], 2) ?></span>
                    </div>

                    <div class="detail-item">
                        <strong>Booking Status</strong>
                        <span><?= escape($selectedBooking["booking_status"]) ?></span>
                    </div>

                    <div class="detail-item">
                        <strong>Payment Status</strong>
                        <span><?= escape($selectedBooking["payment_status"] ?? "No payment record") ?></span>
                    </div>

                    <div class="detail-item">
                        <strong>Payment Method</strong>
                        <span><?= escape($selectedBooking["payment_method"] ?? "Not provided") ?></span>
                    </div>

                    <div class="detail-item">
                        <strong>Transaction Reference</strong>
                        <span><?= escape($selectedBooking["transaction_reference"] ?? "Not available") ?></span>
                    </div>

                </div>

                <br>

                <a href="bookings.php" class="clear-btn">Close Details</a>

            </section>

        <?php endif; ?>

        <section class="table-card">

            <h2>All Passenger Bookings</h2>

            <form method="GET" action="bookings.php" class="search-form">

                <input
                    type="text"
                    name="search"
                    placeholder="Search reference, passenger, phone, route or vehicle"
                    value="<?= escape($search) ?>"
                >

                <select name="status">
                    <option value="">All Booking Statuses</option>

                    <?php foreach ($allowedStatuses as $status): ?>
                        <option
                            value="<?= escape($status) ?>"
                            <?= $filter === $status ? "selected" : "" ?>
                        >
                            <?= escape($status) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

                <button type="submit" class="primary-btn">Search</button>

                <a href="bookings.php" class="clear-btn">Clear</a>

            </form>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Booking Ref</th>
                            <th>Passenger</th>
                            <th>Journey</th>
                            <th>Departure</th>
                            <th>Vehicle</th>
                            <th>Seat</th>
                            <th>Fare (KES)</th>
                            <th>Booking Status</th>
                            <th>Payment Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (count($bookings) > 0): ?>

                        <?php foreach ($bookings as $booking): ?>

                            <tr>

                                <td><?= escape($booking["booking_reference"]) ?></td>

                                <td>
                                    <?= escape($booking["passenger_name"]) ?>
                                    <br>
                                    <small><?= escape($booking["passenger_phone"]) ?></small>
                                </td>

                                <td>
                                    <?= escape($booking["origin"]) ?>
                                    to
                                    <?= escape($booking["destination"]) ?>
                                </td>

                                <td>
                                    <?= escape(date("d M Y, h:i A", strtotime($booking["departure_time"]))) ?>
                                </td>

                                <td><?= escape($booking["registration_number"]) ?></td>

                                <td><?= escape($booking["seat_number"]) ?></td>

                                <td><?= number_format((float)$booking["total_fare"], 2) ?></td>

                                <td>
                                    <?php
                                    $bookingClass = "status-pending";

                                    if ($booking["booking_status"] === "Confirmed") {
                                        $bookingClass = "status-confirmed";
                                    } elseif ($booking["booking_status"] === "Cancelled") {
                                        $bookingClass = "status-cancelled";
                                    } elseif ($booking["booking_status"] === "Expired") {
                                        $bookingClass = "status-expired";
                                    }
                                    ?>

                                    <span class="status <?= $bookingClass ?>">
                                        <?= escape($booking["booking_status"]) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php
                                    $paymentStatus = $booking["payment_status"] ?? "Not paid";
                                    $paymentClass = "status-pending";

                                    if ($paymentStatus === "Successful") {
                                        $paymentClass = "status-successful";
                                    } elseif ($paymentStatus === "Failed") {
                                        $paymentClass = "status-failed";
                                    }
                                    ?>

                                    <span class="status <?= $paymentClass ?>">
                                        <?= escape($paymentStatus) ?>
                                    </span>
                                </td>

                                <td>

                                    <a
                                        href="bookings.php?view=<?= (int)$booking["booking_id"] ?>"
                                        class="view-btn"
                                    >
                                        View
                                    </a>

                                    <?php if (
                                        $booking["booking_status"] === "Pending" &&
                                        $booking["payment_status"] !== "Successful"
                                    ): ?>

                                        <form
                                            method="POST"
                                            action="bookings.php"
                                            style="display:inline;"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= escape($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="cancel_pending"
                                            >

                                            <input
                                                type="hidden"
                                                name="booking_id"
                                                value="<?= (int)$booking["booking_id"] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="cancel-btn"
                                                onclick="return confirm('Cancel this unpaid pending booking?')"
                                            >
                                                Cancel
                                            </button>

                                        </form>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="10" class="empty-message">
                                No bookings found.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <p style="font-size:12px;color:#777;margin-top:15px;">
                Confirmed bookings are protected from cancellation here.
                Refunds and paid-booking cancellations require a separate process.
            </p>

        </section>

    </main>

</div>

</body>
</html>
