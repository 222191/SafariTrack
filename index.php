<?php
$today = date('Y-m-d');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SafariTrack | Your Journey, Our Priority</title>

    <link rel="stylesheet" href="assets/css/style.css?v=3">
</head>
<body>

<header class="site-header">
    <div class="nav-container">

        <a href="index.php" class="logo">
            <span class="logo-icon">S</span>
            <span>SafariTrack</span>
        </a>

        <nav class="main-nav">
            <a href="index.php" class="nav-button active">Home</a>
            <a href="passenger/bookings.php" class="nav-button">My Bookings</a>
            <a href="passenger/login.php" class="nav-button">Login</a>
            <a href="passenger/register.php" class="nav-button register-button">Create Account</a>
        </nav>

    </div>
</header>

<main>

    <section class="hero">

    <div class="hero-content">
        <span class="welcome-label">WELCOME TO SAFARITRACK</span>

        <h1>
            Your Journey.<br>
            <span>Our Priority.</span>
        </h1>

        <p>
            Your journey starts here. Find available matatu trips,
            select your preferred seat and manage your bookings
            conveniently with SafariTrack.
        </p>

        <a href="#search" class="primary-button">
            Book Your Journey <span>&rarr;</span>
        </a>
    </div>

    <div class="hero-image-container">
        <img
            src="/SafariTrack/assets/images/safaritrack-hero.jpg"
            alt="SafariTrack passenger transport"
            class="hero-image"
        >
    </div>

</section>

    <section class="search-section" id="search">

        <div class="search-heading">
            <div>
                <span class="section-label">PLAN YOUR TRIP</span>
                <h2>Where would you like to go?</h2>
            </div>

            <p>Search for available trips and book your seat.</p>
        </div>

        <form action="passenger/search_trips.php" method="GET" class="search-form">

            <div class="form-group">
                <label for="origin">From</label>
                <input
                    type="text"
                    id="origin"
                    name="origin"
                    placeholder="Departure city"
                    required
                >
            </div>

            <div class="form-group">
                <label for="destination">To</label>
                <input
                    type="text"
                    id="destination"
                    name="destination"
                    placeholder="Destination city"
                    required
                >
            </div>

            <div class="form-group">
                <label for="travel_date">Travel date</label>
                <input
                    type="date"
                    id="travel_date"
                    name="travel_date"
                    min="<?php echo $today; ?>"
                    required
                >
            </div>

            <button type="submit" class="search-button">
                Search Trips <span>&rarr;</span>
            </button>

        </form>

    </section>

    <section class="benefits-section">

        <div class="benefit">
            <div class="benefit-icon">01</div>
            <div>
                <h3>Easy Booking</h3>
                <p>Search and reserve your journey online with ease.</p>
            </div>
        </div>

        <div class="benefit">
            <div class="benefit-icon">02</div>
            <div>
                <h3>Seat Selection</h3>
                <p>Select your preferred seat before travelling.</p>
            </div>
        </div>

        <div class="benefit">
            <div class="benefit-icon">03</div>
            <div>
                <h3>Booking Management</h3>
                <p>Access and keep track of your reservations.</p>
            </div>
        </div>

    </section>

</main>

<footer class="site-footer">
    <p>&copy; <?php echo date('Y'); ?> SafariTrack. Your Journey. Our Priority.</p>
</footer>

</body>
</html>