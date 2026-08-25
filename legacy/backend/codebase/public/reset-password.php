<?php
include('../commons/config.php');
include('../commons/constants.php');
include('controllers/sessions.php');
include('controllers/EmailController.php');
include('controllers/ResetController.php');
?>
<!DOCTYPE HTML>
<html>
	<head>
		  <title>i-Fitness Learning Hub</title>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
		<link rel="stylesheet" href="<?= BASE_URL. 'globals/assets/_auth_assets_/assets/css/main.css'?>" />		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/fontawesome.min.css" integrity="sha512-RvQxwf+3zJuNwl4e0sZjQeX7kUa3o82bDETpgVCH2RiwYSZVDdFJ7N/woNigN/ldyOOoKw8584jM4plQdt8bhA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
        <link rel="icon" type="image/x-icon" href="https://ifitness.ng/wp-content/uploads/2021/10/IFITNESS-FAVICON.png"/>
		<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-Zenh87qX5JnK2Jl0vWa8Ck2rdkQ2Bzep5IDxbcnCeuOxjzrPF/et3URy9Bv1WTRi" crossorigin="anonymous">
		<style>
		      a {
                  outline:none !important;
                  color: #000000;
                  font-weight: 600;
                }
		</style>
</head>
<!-- Main -->
<!-- END MAIN CONTAINER -->
<body class="is-preload" style="background-color: #F0F2F5">
    <header style="background-color: #000000">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <nav class="navbar pt-5 pb-3 logo">
                        <a class="navbar-brand mt-0" href="https://ifitness.centrallms.com/public/">
                			    <img src="<?php echo BASE_URL .'globals/assets/img/i-fitness-logo-white-svg-01-2.svg' ?>" alt="Central LMS" style="width: 15%;">
                        </a>
                   </nav>
                </div>
            </div>
        </div>

    </header>
<div class="container">
    <div class="row mt-3">
        <div class="col-12">
              <div class="row">
                    <div class="col-8 offset-2">
                                <img style="border-radius: 12px;" class="img-fluid" src="../globals/assets/img/ifitness_startpage.png">
                                <div class="p-2">
                    				<?php include("views/messages.php"); ?>
                    			</div>
                    			<div class="card shadow-sm">
                    			    <div class="card-body">
                    			        <h2 class="page-header">Forgot Password</h2>
                    			        <p>Need to reset your password? no problem! Just enter your registered email address below and click the button: </p>
                                        <form method="post" class="row my-4 w-100 g-1">
                                          <div class="col-auto">
                                            <label for="staticEmail2" class="visually-hidden">Email address</label>
                                            <input  style="border: 2px solid #000000" placeholder="Email address" id="email" name="email_address" type="email" class="form-control" aria-describedby="emailHelp">
                                          </div>
                                          <div class="col-auto">
                                             <button type="submit" style="background-color: #F60B01; color: #e7e7e7;" class="btn mb-3">&nbsp; Reset &nbsp; </button>
                                          </div>
                                        </form>
                    			    </div>
                    			</div>
                            </div>
             </div>
        </div>
     </div>
</div>
<?php include "../includes/footer.php"; ?>
