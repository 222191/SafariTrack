
<?php
session_start();

require_once "../config/database.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'passenger') {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../index.php");
    exit;
}

$trip_id = filter_input(INPUT_POST, 'trip_id', FILTER_VALIDATE_INT);
$seat_id = filter_input(INPUT_POST, 'seat_id', FILTER_VALIDATE_INT);

if (!$trip_id || !$seat_id) {
    die("Invalid booking details. Please select your trip and seat again.");
}

try {
    $stmt = $pdo->prepare("
        SELECT
            t.trip_id,
            t.departure_time,
            t.fare,
            t.status AS trip_status,
            r.origin,
            r.destination,
            v.registration_number,
            s.seat_id,
            s.seat_number,
            s.status AS seat_status
        FROM trips t
        INNER JOIN routes r ON t.route_id = r.route_id
        INNER JOIN vehicles v ON t.vehicle_id = v.vehicle_id
        INNER JOIN seats s ON s.vehicle_id = v.vehicle_id
        WHERE t.trip_id = ?
          AND s.seat_id = ?
        LIMIT 1
    ");

    $stmt->execute([$trip_id, $seat_id]);
    $trip = $stmt->fetch();

    if (!$trip) {
        die("The selected trip or seat could not be found.");
    }

    if ($trip['trip_status'] !== 'Scheduled' && $trip['trip_status'] !== 'Active') {
        die("This trip is no longer available for booking.");
    }

    if ($trip['seat_status'] !== 'Available') {
        die("This seat is not available. Please select another seat.");
    }

    $bookingCheck = $pdo->prepare("
        SELECT booking_id
        FROM bookings
        WHERE trip_id = ?
          AND seat_id = ?
          AND status IN ('pending', 'confirmed')
        LIMIT 1
    ");

    $bookingCheck->execute([$trip_id, $seat_id]);

    if ($bookingCheck->fetch()) {
        die("This seat has already been booked. Please select another seat.");
    }

} catch (PDOException $e) {
    error_log("Payment page error: " . $e->getMessage());
    die("Unable to load payment details. Please try again.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment | SafariTrack</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .payment-container {
            max-width: 850px;
            margin: 40px auto;
            padding: 25px;
        }

        .payment-card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .payment-card h2 {
            color: #319b35;
            margin-bottom: 20px;
        }

        .trip-summary {
            background: #f4f4f4;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 10px 0;
            border-bottom: 1px solid #ddd;
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .fare {
            color: #319b35;
            font-size: 22px;
            font-weight: bold;
        }

        .payment-option {
            border: 2px solid #319b35;
            border-radius: 8px;
            padding: 18px;
            margin: 20px 0;
            background: #f8fff8;
        }

        .payment-option input {
            margin-right: 10px;
        }

        .demo-notice {
            background: #fff3cd;
            color: #664d03;
            border: 1px solid #ffecb5;
            padding: 14px;
            border-radius: 6px;
            margin: 20px 0;
        }

        .pay-button {
            width: 100%;
            padding: 14px;
            background: #319b35;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        .pay-button:hover {
            background: #267d2a;
        }

        .cancel-link {
            display: block;
            text-align: center;
            margin-top: 18px;
            color: #319b35;
            text-decoration: none;
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

<div class="payment-container">
    <div class="payment-card">

        <h2>Complete Your Payment</h2>
        <p>Review your journey details before proceeding.</p>

        <div class="trip-summary">

            <div class="summary-row">
                <strong>Journey</strong>
                <span>
                    <?= htmlspecialchars($trip['origin']) ?>
                    to
                    <?= htmlspecialchars($trip['destination']) ?>
                </span>
            </div>

            <div class="summary-row">
                <strong>Departure</strong>
                <span>
                    <?= date('d M Y, h:i A', strtotime($trip['departure_time'])) ?>
                </span>
            </div>

            <div class="summary-row">
                <strong>Vehicle</strong>
                <span>
                    <?= htmlspecialchars($trip['registration_number']) ?>
                </span>
            </div>

            <div class="summary-row">
                <strong>Selected Seat</strong>
                <span>
                    <?= htmlspecialchars($trip['seat_number']) ?>
                </span>
            </div>

            <div class="summary-row">
                <strong>Passenger</strong>
                <span>
                    <?= htmlspecialchars($_SESSION['full_name']) ?>
                </span>
            </div>

            <div class="summary-row">
                <strong>Total Fare</strong>
                <span class="fare">
                    KES <?= number_format((float)$trip['fare'], 2) ?>
                </span>
            </div>

        </div>

        <h3>Select Payment Method</h3>

        <form action="process_payment.php" method="POST">

            <input type="hidden" name="trip_id"
                   value="<?= (int)$trip['trip_id'] ?>">

            <input type="hidden" name="seat_id"
                   value="<?= (int)$trip['seat_id'] ?>">

            <div class="payment-option">
                <label>
                    <input type="radio" name="method"
                           value="M-Pesa" checked required>
                    <strong>M-Pesa</strong>
                </label>

                <p>Pay using the M-Pesa payment option.</p>
            </div>

            <div class="demo-notice">
                <strong>Demonstration payment:</strong>
                This is a simulated payment for your project.
                No real money will be transferred.
            </div>

            <button type="submit" class="pay-button">
                SIMULATE SUCCESSFUL PAYMENT
            </button>

        </form>

        <a href="select_seat.php?trip_id=<?= (int)$trip['trip_id'] ?>"
           class="cancel-link">
            Go Back and Change Seat
        </a>

    </div>
</div>

</body>
</html>
