
<?php
session_start();

require_once "../config/database.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'passenger') {
    header("Location: login.php");
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            b.booking_id,
            b.booking_reference,
            b.booking_date,
            b.total_fare,
            b.status AS booking_status,
            r.origin,
            r.destination,
            t.departure_time,
            v.registration_number,
            s.seat_number,
            p.method,
            p.status AS payment_status
        FROM bookings b
        INNER JOIN trips t ON b.trip_id = t.trip_id
        INNER JOIN routes r ON t.route_id = r.route_id
        INNER JOIN vehicles v ON t.vehicle_id = v.vehicle_id
        INNER JOIN seats s ON b.seat_id = s.seat_id
        LEFT JOIN payments p ON b.booking_id = p.booking_id
        WHERE b.user_id = ?
        ORDER BY b.booking_date DESC
    ");

    $stmt->execute([$_SESSION['user_id']]);
    $bookings = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Booking history error: " . $e->getMessage());
    die("Unable to retrieve your bookings. Please try again.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings | SafariTrack</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .bookings-container {
            max-width: 1100px;
            margin: 40px auto;
            padding: 20px;
        }

        .bookings-container h2 {
            color: #319b35;
            margin-bottom: 10px;
        }

        .booking-card {
            background: white;
            padding: 22px;
            margin: 20px 0;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .booking-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }

        .booking-header h3 {
            color: #319b35;
            margin: 0;
        }

        .booking-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .detail strong {
            display: block;
            color: #555;
            margin-bottom: 5px;
        }

        .detail span {
            color: #222;
        }

        .booking-status {
            font-weight: bold;
            text-transform: capitalize;
        }

        .confirmed {
            color: #267d2a;
        }

        .pending {
            color: #b7791f;
        }

        .cancelled,
        .expired {
            color: #c0392b;
        }

        .view-ticket {
            display: inline-block;
            margin-top: 20px;
            padding: 11px 20px;
            background: #319b35;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }

        .view-ticket:hover {
            background: #267d2a;
        }

        .empty-bookings {
            background: white;
            padding: 35px;
            text-align: center;
            border-radius: 10px;
            margin-top: 25px;
        }

        .empty-bookings a {
            color: #319b35;
            font-weight: bold;
        }

        @media (max-width: 600px) {
            .booking-details {
                grid-template-columns: 1fr;
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

<div class="bookings-container">

    <h2>My Bookings</h2>
    <p>View your journey history and access your passenger tickets.</p>

    <?php if (count($bookings) > 0): ?>

        <?php foreach ($bookings as $booking): ?>

            <?php
            $statusClass = strtolower($booking['booking_status']);
            ?>

            <div class="booking-card">

                <div class="booking-header">
                    <h3>
                        <?= htmlspecialchars($booking['origin']) ?>
                        to
                        <?= htmlspecialchars($booking['destination']) ?>
                    </h3>

                    <span class="booking-status <?= htmlspecialchars($statusClass) ?>">
                        <?= htmlspecialchars(ucfirst($booking['booking_status'])) ?>
                    </span>
                </div>

                <div class="booking-details">

                    <div class="detail">
                        <strong>Booking Reference</strong>
                        <span>
                            <?= htmlspecialchars($booking['booking_reference']) ?>
                        </span>
                    </div>

                    <div class="detail">
                        <strong>Departure</strong>
                        <span>
                            <?= date('d M Y, h:i A', strtotime($booking['departure_time'])) ?>
                        </span>
                    </div>

                    <div class="detail">
                        <strong>Vehicle</strong>
                        <span>
                            <?= htmlspecialchars($booking['registration_number']) ?>
                        </span>
                    </div>

                    <div class="detail">
                        <strong>Seat Number</strong>
                        <span>
                            <?= htmlspecialchars($booking['seat_number']) ?>
                        </span>
                    </div>

                    <div class="detail">
                        <strong>Total Fare</strong>
                        <span>
                            KES <?= number_format((float)$booking['total_fare'], 2) ?>
                        </span>
                    </div>

                    <div class="detail">
                        <strong>Payment Status</strong>
                        <span>
                            <?= htmlspecialchars($booking['payment_status'] ?? 'Not recorded') ?>
                        </span>
                    </div>

                    <div class="detail">
                        <strong>Payment Method</strong>
                        <span>
                            <?= htmlspecialchars($booking['method'] ?? 'N/A') ?>
                        </span>
                    </div>

                    <div class="detail">
                        <strong>Booking Date</strong>
                        <span>
                            <?= date('d M Y, h:i A', strtotime($booking['booking_date'])) ?>
                        </span>
                    </div>

                </div>

                <a class="view-ticket"
                   href="booking_confirmation.php?booking_id=<?= (int)$booking['booking_id'] ?>">
                    View Ticket
                </a>

            </div>

        <?php endforeach; ?>

    <?php else: ?>

        <div class="empty-bookings">
            <h3>No Bookings Yet</h3>
            <p>You have not made any bookings.</p>
            <a href="../index.php">Search for Available Trips</a>
        </div>

    <?php endif; ?>

</div>

</body>
</html>
