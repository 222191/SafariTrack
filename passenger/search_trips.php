
<?php

require_once "../config/database.php";

$origin = trim($_GET['origin'] ?? '');
$destination = trim($_GET['destination'] ?? '');
$travelDate = $_GET['travel_date'] ?? '';

$trips = [];
$error = "";

if ($origin === "" || $destination === "" || $travelDate === "") {
    $error = "Please provide your departure location, destination and travel date.";
} else {
    $date = DateTime::createFromFormat('Y-m-d', $travelDate);

    if (!$date || $date->format('Y-m-d') !== $travelDate) {
        $error = "Please select a valid travel date.";
    } elseif ($travelDate < date('Y-m-d')) {
        $error = "Please select today or a future travel date.";
    } else {
        $sql = "SELECT
                    t.trip_id,
                    r.origin,
                    r.destination,
                    t.departure_time,
                    t.fare,
                    v.registration_number,
                    v.capacity
                FROM trips t
                INNER JOIN routes r ON t.route_id = r.route_id
                INNER JOIN vehicles v ON t.vehicle_id = v.vehicle_id
                INNER JOIN drivers d ON t.driver_id = d.driver_id
                WHERE LOWER(r.origin) = LOWER(:origin)
                AND LOWER(r.destination) = LOWER(:destination)
                AND DATE(t.departure_time) = :travel_date
                AND t.status = 'Scheduled'
                AND r.status = 'Active'
                AND v.status = 'Active'
                AND d.status = 'Active'
                ORDER BY t.departure_time ASC";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':origin' => $origin,
            ':destination' => $destination,
            ':travel_date' => $travelDate
        ]);

        $trips = $stmt->fetchAll();
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Trips | SafariTrack</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .results-container {
            max-width: 1000px;
            margin: 35px auto;
            padding: 25px;
            background: white;
            border-radius: 8px;
        }

        .results-container h2 {
            color: #319b35;
            margin-bottom: 20px;
        }

        .trip-card {
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 18px;
        }

        .trip-details {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }

        .trip-details p {
            margin: 5px 0;
        }

        .book-button {
            display: inline-block;
            padding: 11px 20px;
            background: #319b35;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }

        .message {
            padding: 15px;
            background: #f1f1f1;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        @media (max-width: 600px) {
            .results-container {
                margin: 20px;
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
    </div>
</nav>

<main class="results-container">

    <h2>Available Trips</h2>

    <p>
        <strong>From:</strong> <?= htmlspecialchars($origin) ?>
        &nbsp; | &nbsp;
        <strong>To:</strong> <?= htmlspecialchars($destination) ?>
        &nbsp; | &nbsp;
        <strong>Date:</strong> <?= htmlspecialchars($travelDate) ?>
    </p>

    <hr style="margin: 20px 0;">

    <?php if ($error !== ""): ?>

        <div class="message">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php elseif (count($trips) > 0): ?>

        <?php foreach ($trips as $trip): ?>

            <div class="trip-card">

                <div class="trip-details">

                    <div>
                        <p><strong>Route</strong></p>
                        <p>
                            <?= htmlspecialchars($trip['origin']) ?>
                            to
                            <?= htmlspecialchars($trip['destination']) ?>
                        </p>
                    </div>

                    <div>
                        <p><strong>Departure</strong></p>
                        <p>
                            <?= date('h:i A', strtotime($trip['departure_time'])) ?>
                        </p>
                    </div>

                    <div>
                        <p><strong>Fare</strong></p>
                        <p>
                            KES <?= number_format($trip['fare'], 2) ?>
                        </p>
                    </div>

                    <div>
                        <p><strong>Vehicle</strong></p>
                        <p>
                            <?= htmlspecialchars($trip['registration_number']) ?>
                        </p>
                    </div>

                    <div>
                        <p><strong>Capacity</strong></p>
                        <p><?= (int)$trip['capacity'] ?> seats</p>
                    </div>

                </div>

                <a class="book-button"
                   href="select_seat.php?trip_id=<?= (int)$trip['trip_id'] ?>">
                    Select Trip
                </a>

            </div>

        <?php endforeach; ?>

    <?php else: ?>

        <div class="message">
            No scheduled trips were found for your search.
            Please try another date or route.
        </div>

    <?php endif; ?>

    <a href="../index.php">Back to Home</a>

</main>

</body>
</html>
