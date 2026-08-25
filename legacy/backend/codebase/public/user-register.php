<?php
include('../commons/config.php');
include('../commons/constants.php');
//include('../includes/header.php');
include('controllers/sessions.php');
include('controllers/RegisterController.php');
?>
<!DOCTYPE HTML>
<!--
	Strata by HTML5 UP
	html5up.net | @ajlkn
	Free for personal and commercial use under the CCA 3.0 license (html5up.net/license)
--> 
<html>
	<head>
		<title>Central Learning Management System</title>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
		<link rel="stylesheet" href="<?= BASE_URL. 'globals/assets/_auth_assets_/assets/css/main.css'?>" />		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/fontawesome.min.css" integrity="sha512-RvQxwf+3zJuNwl4e0sZjQeX7kUa3o82bDETpgVCH2RiwYSZVDdFJ7N/woNigN/ldyOOoKw8584jM4plQdt8bhA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
        <link rel="icon" type="image/x-icon" href="<?php echo BASE_URL . 'globals/assets/img/central-favicon.png' ?>"/>
		<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-Zenh87qX5JnK2Jl0vWa8Ck2rdkQ2Bzep5IDxbcnCeuOxjzrPF/et3URy9Bv1WTRi" crossorigin="anonymous">
		<style>
		      a {
                  outline:none !important;
                  color: #000000;
                  font-weight: 600;
                }
		</style>
</head>
<body class="is-preload" style="background-color: #F0F2F5;" ">
<!-- Header -->

	<!-- Header -->
	<header id="header" style="background-image: url(<?= BASE_URL .'globals/assets/_auth_assets_/assets/images/bg-img.png' ?>);background-repeat: no-repeat;
		background-size: contain;">
	</header>
<!-- Main -->
    <div id="main" style="background-color: #F0F2F5">

            
            <nav class="navbar logo">
                <div class="container pt-0 d-flex align-items-center">
                    <a class="navbar-brand mt-0" href="#">
                    <img src="<?= BASE_URL .'globals/assets/_auth_assets_/assets/images/logo.png' ?>" alt="Central LMS" style="height: 80%;">
                    </a>
                </div>
              </nav>
            <section id="one" class="pt-5 mt-lg-4">
                <div class="d-flex justify-content-center align-items-center">
                    <h2 class="page-header">Get started with Central LMS</h2>
                </div>
                <div class="d-flex justify-content-center align-items-center">
                    <p>Getting started is easy!</p>
                </div>
                <?php include("views/messages.php"); ?>
                <div class="px-5 d-flex justify-content-center align-items-center">
                    <form method="post" class="col-12 col-sm-8">
                        <div class="mb-2">
                            <label  class="form-label">First name</label>
                            <input name="first_name" type="text" class="form-style">
                          </div>
                        <div class="mb-2">
                            <label class="form-label">Last name</label>
                            <input name="last_name" type="text" class="form-style">
                          </div>
                        <div class="mb-2">
                          <label for="exampleInputEmail1" class="form-label">Email address</label>
                          <input type="email" value="<?php echo $email_address ?>" id="email" name="email_address" class="form-style" id="exampleInputEmail1" aria-describedby="emailHelp">
                          <div id="emailHelp" class="form-text"></div>
                        </div>
                        <div class="mb-2">
                          <div class="d-flex justify-content-between">
                            <label for="exampleInputPassword1" class="form-label">Password</label>

                          </div>
                          <div class="form-group">
                            <input id="password" type="password" class="form-style password" name="password" >
                            <span toggle=".password" class="fa fa-fw fa-eye input-icon toggle-password"></span>	
                          </div>
                        </div>
                        <div class="my-3">
                          <input type="checkbox" name="_terms" class="form-style" id="exampleCheck1">
                          <label class="form-check-label" for="exampleCheck1"><span class="new-control-indicator"></span><span>I agree to the <a class="font-weight-bolder" target="_blank" href="terms">  terms and conditions </a><span class="text-danger h6">*</span></span></label>
                          <!--a href="#"class="forgot-password"><label for="exampleInputPassword1" class="form-label" >Forgot password</label></a-->
                        </div>
                        
                        <div class="my-3">
                          <button type="submit" class="form-control" style="border-radius: 4px;
                          padding: 12px;
                          background-color: #0E630E;
                          color: #e7e7e7;" id="exampleInputPassword1" name="user_register">Register</button>
                        </div>
                        <p>Already own an account? <a href="<?= BASE_URL.'public/user-login.php' ?>">Login</a></p>
                      </form>

                </div>
            </section>
    </div>



    <?= include "../includes/footer.php"; ?>
