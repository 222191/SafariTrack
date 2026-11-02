
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
$method = $_POST['method'] ?? '';

if (!$trip_id || !$seat_id || $method !== 'M-Pesa') {
    die("Invalid payment details. Please try again.");
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT
            t.trip_id,
            t.fare,
            t.status AS trip_status,
            s.seat_id,
            s.status AS seat_status
        FROM trips t
        INNER JOIN vehicles v ON t.vehicle_id = v.vehicle_id
        INNER JOIN seats s ON s.vehicle_id = v.vehicle_id
        WHERE t.trip_id = ?
          AND s.seat_id = ?
        FOR UPDATE
    ");

    $stmt->execute([$trip_id, $seat_id]);
    $trip = $stmt->fetch();

    if (!$trip) {
        throw new Exception("The selected trip or seat could not be found.");
    }

    if (!in_array($trip['trip_status'], ['Scheduled', 'Active'], true)) {
        throw new Exception("This trip is no longer available.");
    }

    if ($trip['seat_status'] !== 'Available') {
        throw new Exception("This seat is not available.");
    }

    $check = $pdo->prepare("
        SELECT booking_id
        FROM bookings
        WHERE trip_id = ?
          AND seat_id = ?
          AND status IN ('pending', 'confirmed')
        LIMIT 1
    ");

    $check->execute([$trip_id, $seat_id]);

    if ($check->fetch()) {
        throw new Exception("This seat has already been booked. Please select another seat.");
    }

    $booking_reference = 'ST' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));

    $fare = (float)$trip['fare'];

    $bookingStmt = $pdo->prepare("
        INSERT INTO bookings (
            booking_reference,
            user_id,
            trip_id,
            seat_id,
            booking_date,
            total_fare,
            status
        )
        VALUES (?, ?, ?, ?, NOW(), ?, 'pending')
    ");

    $bookingStmt->execute([
        $booking_reference,
        $_SESSION['user_id'],
        $trip_id,
        $seat_id,
        $fare
    ]);

    $booking_id = $pdo->lastInsertId();

    $transaction_reference = 'SIM-' . strtoupper(bin2hex(random_bytes(5)));

    $paymentStmt = $pdo->prepare("
        INSERT INTO payments (
            booking_id,
            amount,
            method,
            transaction_reference,
            status,
            payment_date
        )
        VALUES (?, ?, ?, ?, 'Successful', NOW())
    ");

    $paymentStmt->execute([
        $booking_id,
        $fare,
        $method,
        $transaction_reference
    ]);

    $updateBooking = $pdo->prepare("
        UPDATE bookings
        SET status = 'confirmed'
        WHERE booking_id = ?
    ");

    $updateBooking->execute([$booking_id]);

    $auditStmt = $pdo->prepare("
        INSERT INTO audit_logs (
            user_id,
            action,
            created_at,
            details
        )
        VALUES (?, ?, NOW(), ?)
    ");

    $auditStmt->execute([
        $_SESSION['user_id'],
        'Booking and payment completed',
        "Booking reference: $booking_reference; Payment reference: $transaction_reference; Amount: KES $fare"
    ]);

    $pdo->commit();

    header("Location: booking_confirmation.php?booking_id=" . $booking_id);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("SafariTrack payment processing error: " . $e->getMessage());

    die(
        "Your payment could not be processed. " .
        htmlspecialchars($e->getMessage()) .
        " Please return and try again."
    );
}
