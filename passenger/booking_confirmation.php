
<?php
session_start();

require_once "../config/database.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'passenger') {
    header("Location: login.php");
    exit;
}

$booking_id = filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT);

if (!$booking_id) {
    die("Invalid booking reference.");
}

try {
    $stmt = $pdo->prepare("
        SELECT
            b.booking_id,
            b.booking_reference,
            b.booking_date,
            b.total_fare,
            b.status AS booking_status,
            u.full_name,
            u.phone,
            r.origin,
            r.destination,
            t.departure_time,
            v.registration_number,
            s.seat_number,
            p.method,
            p.transaction_reference,
            p.status AS payment_status
        FROM bookings b
        INNER JOIN users u ON b.user_id = u.user_id
        INNER JOIN trips t ON b.trip_id = t.trip_id
        INNER JOIN routes r ON t.route_id = r.route_id
        INNER JOIN vehicles v ON t.vehicle_id = v.vehicle_id
        INNER JOIN seats s ON b.seat_id = s.seat_id
        LEFT JOIN payments p ON b.booking_id = p.booking_id
        WHERE b.booking_id = ?
          AND b.user_id = ?
        LIMIT 1
    ");

    $stmt->execute([$booking_id, $_SESSION['user_id']]);
    $booking = $stmt->fetch();

    if (!$booking) {
        die("Booking not found.");
    }

} catch (PDOException $e) {
    error_log("Booking confirmation error: " . $e->getMessage());
    die("Unable to load your booking confirmation.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmation | SafariTrack</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .confirmation-container {
            max-width: 750px;
            margin: 40px auto;
            padding: 20px;
        }

        .confirmation-card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .success-message {
            text-align: center;
            margin-bottom: 25px;
        }

        .success-message h2 {
            color: #319b35;
        }

        .ticket {
            border: 2px dashed #319b35;
            border-radius: 10px;
            padding: 25px;
            margin-top: 20px;
        }

        .ticket h3 {
            text-align: center;
            color: #319b35;
            margin-bottom: 20px;
        }

        .ticket-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }

        .ticket-row:last-child {
            border-bottom: none;
        }

        .reference {
            text-align: center;
            background: #f1f8f1;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .reference strong {
            color: #319b35;
            font-size: 20px;
        }

        .status {
            color: #267d2a;
            font-weight: bold;
        }

        .ticket-actions {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }

        .ticket-actions a,
        .ticket-actions button {
            flex: 1;
            text-align: center;
            padding: 13px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            font-weight: bold;
        }

        .home-button {
            background: #319b35;
            color: white;
        }

        .print-button {
            background: #e8e8e8;
            color: #333;
        }

        @media print {
            .navbar,
            .ticket-actions {
                display: none;
            }

            body {
                background: white;
            }

            .confirmation-card {
                box-shadow: none;
            }
        }
    </style>
</head>

<body>

<nav class="navbar">
    <div class="logo">SafariTrack</div>

    <div class="nav-links">
        <a href="../index.php">Home</a>
        <a href="bookings.php">My Bookings</a>
        <a href="logout.php">Logout</a>
    </div>
</nav>

<div class="confirmation-container">

    <div class="confirmation-card">

        <div class="success-message">
            <h2>Booking Confirmed!</h2>
            <p>Your payment was successfully recorded.</p>
        </div>

        <div class="ticket">

            <h3>SafariTrack Passenger Ticket</h3>

            <div class="reference">
                <p>Booking Reference</p>
                <strong>
                    <?= htmlspecialchars($booking['booking_reference']) ?>
                </strong>
            </div>

            <div class="ticket-row">
                <strong>Passenger</strong>
                <span><?= htmlspecialchars($booking['full_name']) ?></span>
            </div>

            <div class="ticket-row">
                <strong>Phone Number</strong>
                <span><?= htmlspecialchars($booking['phone']) ?></span>
            </div>

            <div class="ticket-row">
                <strong>Journey</strong>
                <span>
                    <?= htmlspecialchars($booking['origin']) ?>
                    to
                    <?= htmlspecialchars($booking['destination']) ?>
                </span>
            </div>

            <div class="ticket-row">
                <strong>Departure</strong>
                <span>
                    <?= date('d M Y, h:i A', strtotime($booking['departure_time'])) ?>
                </span>
            </div>

            <div class="ticket-row">
                <strong>Vehicle</strong>
                <span><?= htmlspecialchars($booking['registration_number']) ?></span>
            </div>

            <div class="ticket-row">
                <strong>Seat Number</strong>
                <span><?= htmlspecialchars($booking['seat_number']) ?></span>
            </div>

            <div class="ticket-row">
                <strong>Booking Date</strong>
                <span>
                    <?= date('d M Y, h:i A', strtotime($booking['booking_date'])) ?>
                </span>
            </div>

            <div class="ticket-row">
                <strong>Payment Method</strong>
                <span><?= htmlspecialchars($booking['method'] ?? 'N/A') ?></span>
            </div>

            <div class="ticket-row">
                <strong>Payment Reference</strong>
                <span>
                    <?= htmlspecialchars($booking['transaction_reference'] ?? 'N/A') ?>
                </span>
            </div>

            <div class="ticket-row">
                <strong>Total Paid</strong>
                <strong>
                    KES <?= number_format((float)$booking['total_fare'], 2) ?>
                </strong>
            </div>

            <div class="ticket-row">
                <strong>Booking Status</strong>
                <span class="status">
                    <?= htmlspecialchars($booking['booking_status']) ?>
                </span>
            </div>

            <div class="ticket-row">
                <strong>Payment Status</strong>
                <span class="status">
                    <?= htmlspecialchars($booking['payment_status'] ?? 'Not recorded') ?>
                </span>
            </div>

        </div>

        <div class="ticket-actions">
            <button class="print-button" onclick="window.print()">
                Print Ticket
            </button>

            <a href="../index.php" class="home-button">
                Return Home
            </a>
        </div>

    </div>

</div>

</body>
</html>
