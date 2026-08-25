<?php
include('../commons/config.php');
include('../commons/constants.php');
include('controllers/sessions.php');
include('controllers/LoginController.php');
?>
<!DOCTYPE HTML>
<html>
<head>
    <title>i-Fitness Learning Hub</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
    <link rel="stylesheet" href="<?= BASE_URL. 'globals/assets/_auth_assets_/assets/css/main.css'?>" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="icon" type="image/x-icon" href="https://ifitness.ng/wp-content/uploads/2021/10/IFITNESS-FAVICON.png"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
        /* Utility overrides and responsive tweaks */
        body { background-color: #F0F2F5; }
        a { outline:none !important; color: #000000; font-weight: 600; }
        header { background-color: #000000; }
        .logo img { max-width: 220px; }
        .hero-img { border-radius: 12px; max-width: 100%; height: auto; display: block; margin: 0 auto; }
        .card { border-radius: 12px; }
        .forgot-pass-link { display: inline-block; }
        .input-icon { cursor: pointer; position: absolute; right: 12px; top: 10px; }
        .password-wrapper { position: relative; }
        /* Ensure form inputs show nicely on small screens */
        @media (max-width: 575.98px) {
            .logo img { width: 60%; }
            .card-body { padding: 1rem; }
            .forgot-pass-link { margin: 10px 0 18px; display:block; }
        }
        @media (min-width: 576px) {
            .hero-img { width: 100%; }
        }
        /* Limit container width for very large screens */
        .page-center { max-width: 920px; margin: 0 auto; }
    </style>
</head>
<body>
<header style="padding-top: 40px">
    <div class="container page-center">
        <div class="row align-items-center">
            <div class="col-12 col-md-6">
                <nav class="navbar p-0 logo">
                    <a class="navbar-brand m-0" href="https://learning.ifitness.ng/public/">
                        <img src="<?php echo BASE_URL .'globals/assets/img/i-fitness-logo-white-svg-01-2.svg' ?>" alt="Central LMS" class="img-fluid">
                    </a>
                </nav>
            </div>
        </div>
    </div>
</header>

<div class="container page-center">
    <div class="row mt-3 justify-content-center">
        <div class="col-12">
            <div class="row gy-4">
                <!-- On small screens image appears above the card; on md+ it sits centered -->
                <div class="col-12 col-md-10 col-lg-8 mx-auto">
                    <img class="hero-img" src="../globals/assets/img/ifitness_startpage.png" alt="i-Fitness start">
                </div>

                <div class="col-12 col-md-10 col-lg-8 mx-auto">
                    <div class="p-2">
                        <?php include("views/messages.php"); ?>
                    </div>

                    <div class="card shadow-sm">
                        <div class="card-body">
                            <p class="mb-2">Please enter your login credentials to access your account:</p>
                            <a href="reset-password" class="forgot-pass-link">Click here to reset your password</a>

                            <form method="post" class="row g-2 my-3">
                                <!-- Make inputs full width on xs, side-by-side on md+ -->
                                <div class="col-12 col-md-5">
                                    <label for="email" class="form-label visually-hidden">Email address</label>
                                    <input style="border: 2px solid #000000" placeholder="Email address" id="email" name="email_address" type="email" class="form-control" aria-describedby="emailHelp">
                                </div>
                                <div class="col-12 col-md-5 password-wrapper">
                                    <label for="password" class="form-label visually-hidden">Password</label>
                                    <input style="border: 2px solid #000000" placeholder="Password" id="password" type="password" class="form-control password" name="password">
                                    <span toggle="#password" class="fa fa-eye input-icon toggle-password" title="Show/Hide password" aria-hidden="true"></span>
                                </div>

                                <div class="col-12 col-md-2 d-grid">
                                    <button type="submit" name="public_login" style="background-color: #F60B01; color: #e7e7e7;" class="btn">Sign In</button>
                                </div>
                            </form>

                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?= include "../includes/footer.php"; ?>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
