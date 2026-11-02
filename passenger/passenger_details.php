<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'passenger') {
    header("Location: login.php");
    exit;
}

$trip_id = filter_input(INPUT_GET, 'trip_id', FILTER_VALIDATE_INT);
$seat_id = filter_input(INPUT_GET, 'seat_id', FILTER_VALIDATE_INT);

if (!$trip_id || !$seat_id) {
    die("Invalid trip or seat selection. Please search again.");
}

$query = "
    SELECT
        t.trip_id,
        t.departure_time,
        t.fare,
        r.origin,
        r.destination,
        v.registration_number,
        s.seat_id,
        s.seat_number,
        s.status,
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM bookings b
                WHERE b.trip_id = t.trip_id
                AND b.seat_id = s.seat_id
                AND b.status IN ('pending', 'confirmed')
            ) THEN 1
            ELSE 0
        END AS is_booked
    FROM trips t
    JOIN routes r ON t.route_id = r.route_id
    JOIN vehicles v ON t.vehicle_id = v.vehicle_id
    JOIN seats s ON s.vehicle_id = v.vehicle_id
    WHERE t.trip_id = :trip_id
    AND s.seat_id = :seat_id
";

$stmt = $pdo->prepare($query);
$stmt->execute([
    "trip_id" => $trip_id,
    "seat_id" => $seat_id
]);

$booking = $stmt->fetch();

if (!$booking) {
    die("The selected seat does not belong to this trip.");
}

if (
    strtolower($booking['status']) !== 'available'
    || (int)$booking['is_booked'] === 1
) {
    die("Sorry, this seat is no longer available. Please select another seat.");
}

$full_name = $_SESSION['full_name'];
$phone = $_SESSION['phone'];
$email = $_SESSION['email'] ?? "";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passenger Details | SafariTrack</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .page-container {
            max-width: 850px;
            margin: 40px auto;
            padding: 20px;
        }

        .page-heading {
            text-align: center;
            margin-bottom: 25px;
        }

        .page-heading h1 {
            color: #319b35;
            margin-bottom: 10px;
        }

        .details-card, .trip-card {
            background: white;
            padding: 28px;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }

        .trip-card h2, .details-card h2 {
            color: #319b35;
            margin-bottom: 20px;
        }

        .trip-information {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .trip-information p {
            line-height: 1.6;
        }

        .passenger-information p {
            padding: 13px;
            background: #f7f7f7;
            border-radius: 5px;
            margin-bottom: 12px;
        }

        .continue-button {
            width: 100%;
            padding: 14px;
            background: #319b35;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }

        .continue-button:hover {
            background: #267d2a;
        }

        .notice {
            background: #f1f8f1;
            border-left: 4px solid #319b35;
            padding: 14px;
            margin-bottom: 20px;
        }

        @media (max-width: 600px) {
            .trip-information {
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

<main class="page-container">

    <div class="page-heading">
        <h1>Passenger Details</h1>
        <p>Confirm your details before proceeding to payment.</p>
    </div>

    <section class="trip-card">
        <h2>Booking Summary</h2>

        <div class="trip-information">
            <p><strong>Route:</strong><br>
                <?= htmlspecialchars($booking['origin']) ?>
                to
                <?= htmlspecialchars($booking['destination']) ?>
            </p>

            <p><strong>Departure:</strong><br>
                <?= date('d M Y, h:i A', strtotime($booking['departure_time'])) ?>
            </p>

            <p><strong>Vehicle:</strong><br>
                <?= htmlspecialchars($booking['registration_number']) ?>
            </p>

            <p><strong>Selected Seat:</strong><br>
                <?= htmlspecialchars($booking['seat_number']) ?>
            </p>

            <p><strong>Total Fare:</strong><br>
                KES <?= number_format((float)$booking['fare'], 2) ?>
            </p>
        </div>
    </section>

    <section class="details-card">
        <h2>Your Registered Information</h2>

       

        <div class="passenger-information">
            <p><strong>Full Name:</strong>
                <?= htmlspecialchars($full_name) ?>
            </p>

            <p><strong>Phone Number:</strong>
                <?= htmlspecialchars($phone) ?>
            </p>

            <p><strong>Email Address:</strong>
                <?= htmlspecialchars($email !== "" ? $email : "Not provided") ?>
            </p>
        </div>

        <form action="payment.php" method="POST">

            <input type="hidden" name="trip_id"
                   value="<?= (int)$booking['trip_id'] ?>">

            <input type="hidden" name="seat_id"
                   value="<?= (int)$booking['seat_id'] ?>">

            <button type="submit" class="continue-button">
                CONTINUE TO PAYMENT
            </button>

        </form>
    </section>

</main>

<footer>
    <p>&copy; <?= date('Y') ?> SafariTrack. All rights reserved.</p>
</footer>

</body>
</html>