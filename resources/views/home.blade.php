<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HeyBrokr Clone</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <style>
        body {
            font-family: Arial, sans-serif;
        }

        /* Top Bar */
        .top-bar {
            background: #f39c12;
            color: #fff;
            padding: 8px 0;
            font-size: 14px;
        }

        .top-bar i {
            margin-right: 5px;
        }

        /* Navbar */
        .navbar {
            background: #fff;
            padding: 15px;
        }

        .navbar-brand {
            font-weight: bold;
            color: #f39c12 !important;
            font-size: 22px;
        }

        .nav-link {
            color: #333 !important;
            margin-right: 15px;
        }

        .login-btn {
            background: #000;
            color: #fff;
            border-radius: 5px;
            padding: 8px 15px;
        }

        /* Hero */
        .hero {
            background: url('https://images.unsplash.com/photo-1505691938895-1758d7feb511') no-repeat center center/cover;
            height: 400px;
            position: relative;
        }

        /* Search Box */
        .search-box {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            position: relative;
            top: -50px;
            box-shadow: 0px 5px 20px rgba(0,0,0,0.1);
        }

        .search-btn {
            background: #000;
            color: #fff;
            padding: 10px 20px;
        }

        .filter-btn {
            border: 1px solid #ccc;
            padding: 10px 20px;
        }
    </style>
</head>
<body>

<!-- Top Bar -->
<div class="top-bar text-center">
    <span><i class="fa fa-envelope"></i> support@heybrokr.com</span>
    &nbsp;&nbsp;
    <span><i class="fa fa-phone"></i> +91 733 833 5958</span>
</div>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg shadow-sm">
    <div class="container">
        <a class="navbar-brand" href="#">Heybrokr</a>

        <button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#menu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="menu">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="#">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="#">Properties</a></li>
                <li class="nav-item"><a class="nav-link" href="#">Services</a></li>
                <li class="nav-item"><a class="nav-link" href="#">About Us</a></li>
                <li class="nav-item"><a class="nav-link" href="#">Contact</a></li>
            </ul>

            <a href="#" class="btn login-btn ms-3">Login/Register</a>
        </div>
    </div>
</nav>

<!-- Hero Section -->
<div class="hero"></div>

<!-- Search Section -->
<div class="container">
    <div class="search-box row align-items-center">

        <div class="col-md-2">
            <label>Property Type</label>
            <select class="form-control">
                <option>All</option>
            </select>
        </div>

        <div class="col-md-2">
            <label>Category</label>
            <select class="form-control">
                <option>Select Category</option>
            </select>
        </div>

        <div class="col-md-3">
            <label>Location</label>
            <input type="text" class="form-control" placeholder="Enter Location">
        </div>

        <div class="col-md-3">
            <label>Keywords</label>
            <input type="text" class="form-control" placeholder="Enter Keywords">
        </div>

        <div class="col-md-2 mt-4 d-flex">
            <button class="btn filter-btn me-2">Smart Filters</button>
            <button class="btn search-btn">Search</button>
        </div>

    </div>
</div>

</body>
</html>