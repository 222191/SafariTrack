
<?php
require_once "../config/database.php";

if (
    !isset($_GET['trip_id']) ||
    !filter_var($_GET['trip_id'], FILTER_VALIDATE_INT)
) {
    die("Invalid trip selected. Please search for a trip again.");
}

$trip_id = (int) $_GET['trip_id'];

$trip_query = "
    SELECT
        t.trip_id,
        t.departure_time,
        t.fare,
        t.vehicle_id,
        r.origin,
        r.destination,
        v.registration_number,
        v.capacity
    FROM trips t
    INNER JOIN routes r ON t.route_id = r.route_id
    INNER JOIN vehicles v ON t.vehicle_id = v.vehicle_id
    INNER JOIN drivers d ON t.driver_id = d.driver_id
    WHERE t.trip_id = :trip_id
    AND t.status = 'Scheduled'
    AND r.status = 'Active'
    AND v.status = 'Active'
    AND d.status = 'Active'
    AND t.departure_time >= NOW()
";

$stmt = $pdo->prepare($trip_query);
$stmt->execute(['trip_id' => $trip_id]);
$trip = $stmt->fetch();

if (!$trip) {
    die("This trip is no longer available. Please search for another trip.");
}

$seat_query = "
    SELECT
        s.seat_id,
        s.seat_number,
        s.status,
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM bookings b
                WHERE b.trip_id = :trip_id
                AND b.seat_id = s.seat_id
                AND b.status IN ('pending', 'confirmed')
            ) THEN 1
            ELSE 0
        END AS is_booked
    FROM seats s
    WHERE s.vehicle_id = :vehicle_id
    ORDER BY s.seat_number
";

$stmt = $pdo->prepare($seat_query);

$stmt->execute([
    'trip_id' => $trip_id,
    'vehicle_id' => $trip['vehicle_id']
]);

$seats = $stmt->fetchAll();

$availableSeats = array_filter($seats, function ($seat) {
    return strtolower($seat['status']) === 'available'
        && (int) $seat['is_booked'] === 0;
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Seat | SafariTrack</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .page-container {
            max-width: 850px;
            margin: 35px auto;
            padding: 20px;
        }

        .trip-summary,
        .seat-section {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }

        .trip-summary h2 {
            color: #319b35;
            margin-bottom: 12px;
        }

        .seat-section h2 {
            text-align: center;
            margin-bottom: 25px;
        }

        .seat-legend {
            display: flex;
            justify-content: center;
            gap: 25px;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .legend-box {
            width: 20px;
            height: 20px;
            border-radius: 4px;
        }

        .available-box {
            background: #319b35;
        }

        .booked-box {
            background: #999;
        }

        .selected-box {
            background: #f0ad4e;
        }

        .bus-layout {
            max-width: 390px;
            margin: auto;
            padding: 25px;
            border: 2px solid #ddd;
            border-radius: 45px 45px 12px 12px;
            background: #fafafa;
        }

        .driver-area {
            text-align: right;
            padding-bottom: 25px;
            border-bottom: 1px solid #ddd;
            margin-bottom: 20px;
            color: #555;
            font-weight: bold;
        }

        .seat-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr) 35px repeat(2, 1fr);
            gap: 12px;
            justify-items: center;
        }

        .seat-option {
            width: 55px;
            height: 48px;
            border-radius: 8px;
            background: #319b35;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-weight: bold;
            border: 2px solid #319b35;
        }

        .seat-option input {
            display: none;
        }

        .seat-option:has(input:checked) {
            background: #f0ad4e;
            border-color: #d58a16;
            color: #222;
        }

        .seat-option.unavailable {
            background: #999;
            border-color: #999;
            cursor: not-allowed;
        }

        .aisle {
            width: 35px;
        }

        .seat-submit {
            width: 100%;
            margin-top: 30px;
            padding: 14px;
            background: #319b35;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }

        .seat-submit:hover {
            background: #267d2a;
        }

        .no-seats {
            text-align: center;
            color: #b00020;
            padding: 20px;
        }

        @media (max-width: 480px) {
            .bus-layout {
                padding: 15px;
            }

            .seat-option {
                width: 45px;
                height: 43px;
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

<div class="page-container">

    <div class="trip-summary">
        <h2>Trip Details</h2>

        <p>
            <strong>Route:</strong>
            <?= htmlspecialchars($trip['origin']) ?>
            to
            <?= htmlspecialchars($trip['destination']) ?>
        </p>

        <p>
            <strong>Departure:</strong>
            <?= date('d M Y, h:i A', strtotime($trip['departure_time'])) ?>
        </p>

        <p>
            <strong>Vehicle:</strong>
            <?= htmlspecialchars($trip['registration_number']) ?>
        </p>

        <p>
            <strong>Fare:</strong>
            KES <?= number_format((float) $trip['fare'], 2) ?>
        </p>
    </div>

    <div class="seat-section">

        <h2>Select Your Seat</h2>

        <div class="seat-legend">
            <div class="legend-item">
                <span class="legend-box available-box"></span>
                Available
            </div>

            <div class="legend-item">
                <span class="legend-box booked-box"></span>
                Booked
            </div>

            <div class="legend-item">
                <span class="legend-box selected-box"></span>
                Selected
            </div>
        </div>

        <?php if (empty($seats)): ?>

            <p class="no-seats">
                No seats have been registered for this vehicle yet.
            </p>

        <?php elseif (count($availableSeats) === 0): ?>

            <p class="no-seats">
                Sorry, there are no available seats on this trip.
            </p>

        <?php else: ?>

            <form action="passenger_details.php" method="GET">

                <input
                    type="hidden"
                    name="trip_id"
                    value="<?= $trip_id ?>"
                >

                <div class="bus-layout">

                    <div class="driver-area">DRIVER</div>

                    <div class="seat-grid">

                        <?php foreach ($seats as $index => $seat): ?>

                            <?php
                            $isAvailable =
                                strtolower($seat['status']) === 'available'
                                && (int) $seat['is_booked'] === 0;
                            ?>

                            <?php if ($index > 0 && $index % 4 === 0): ?>
                                <div class="aisle"></div>
                            <?php endif; ?>

                            <label class="seat-option <?= $isAvailable ? '' : 'unavailable' ?>">

                                <?php if ($isAvailable): ?>

                                    <input
                                        type="radio"
                                        name="seat_id"
                                        value="<?= (int) $seat['seat_id'] ?>"
                                        required
                                    >

                                <?php endif; ?>

                                <?= htmlspecialchars($seat['seat_number']) ?>

                            </label>

                        <?php endforeach; ?>

                    </div>

                </div>

                <button type="submit" class="seat-submit">
                    CONTINUE
                </button>

            </form>

        <?php endif; ?>

    </div>

</div>

<footer>
    <p>&copy; <?= date('Y') ?> SafariTrack. All rights reserved.</p>
</footer>

</body>
</html>
