<?php
require_once 'config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agriculture Marketplace - Fresh from Farm to Table</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            overflow-x: hidden;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            border-radius: 5px;
        }

        /* Navigation Bar */
        .navbar {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .navbar .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo i {
            font-size: 2rem;
            animation: rotate 10s infinite linear;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .logo h1 {
            font-size: 1.5rem;
            background: linear-gradient(135deg, #fff, #ffd700);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .nav-links {
            display: flex;
            list-style: none;
            gap: 2rem;
            align-items: center;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            position: relative;
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: #ffd700;
            transition: width 0.3s;
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        .nav-links a:hover {
            color: #ffd700;
        }

        .welcome {
            background: #ff9800;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 6rem 0;
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '🌾';
            position: absolute;
            font-size: 300px;
            opacity: 0.1;
            bottom: -100px;
            left: -100px;
            animation: float 20s infinite;
        }

        .hero::after {
            content: '🍅';
            position: absolute;
            font-size: 250px;
            opacity: 0.1;
            top: -80px;
            right: -80px;
            animation: float 15s infinite reverse;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(10deg); }
        }

        .hero-content {
            position: relative;
            z-index: 1;
            text-align: center;
            animation: slideUp 0.8s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .hero h2 {
            font-size: 3rem;
            margin-bottom: 1rem;
            font-weight: 800;
        }

        .hero p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            opacity: 0.95;
        }

        .btn-primary {
            display: inline-block;
            background: #ff9800;
            color: white;
            padding: 1rem 2rem;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
            background: #f57c00;
        }

        /* Search Section */
        .search-section {
            background: white;
            padding: 2rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 70px;
            z-index: 99;
        }

        .search-box {
            display: flex;
            gap: 1rem;
            max-width: 800px;
            margin: 0 auto;
            flex-wrap: wrap;
        }

        .search-input {
            flex: 1;
            padding: 1rem;
            border: 2px solid #e0e0e0;
            border-radius: 50px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .search-input:focus {
            outline: none;
            border-color: #2e7d32;
            box-shadow: 0 0 0 3px rgba(46,125,50,0.1);
        }

        .search-btn {
            padding: 1rem 2rem;
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46,125,50,0.3);
        }

        /* Categories Section */
        .categories-section {
            padding: 4rem 0;
            background: #f8f9fa;
        }

        .section-title {
            text-align: center;
            font-size: 2.5rem;
            margin-bottom: 3rem;
            color: #2e7d32;
            position: relative;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 3px;
            background: #ff9800;
        }

        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 2rem;
        }

        .category-card {
            background: white;
            padding: 2rem;
            text-align: center;
            border-radius: 15px;
            transition: all 0.3s;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .category-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .category-card i {
            font-size: 3rem;
            color: #2e7d32;
            margin-bottom: 1rem;
        }

        .category-card h3 {
            font-size: 1.1rem;
            color: #333;
        }

        /* Products Section */
        .products-section {
            padding: 4rem 0;
            background: white;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }

        .product-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            transition: all 0.3s;
            position: relative;
        }

        .product-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.2);
        }

        .product-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: #ff9800;
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            z-index: 1;
        }

        .product-image {
            height: 200px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .product-image i {
            font-size: 5rem;
            color: rgba(255,255,255,0.8);
        }

        .product-info {
            padding: 1.5rem;
        }

        .product-info h3 {
            font-size: 1.3rem;
            color: #2e7d32;
            margin-bottom: 0.5rem;
        }

        .farmer {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .farmer i {
            margin-right: 0.5rem;
            color: #ff9800;
        }

        .category {
            display: inline-block;
            background: #e8f5e9;
            color: #2e7d32;
            padding: 0.2rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-bottom: 0.5rem;
        }

        .quantity {
            color: #666;
            font-size: 0.9rem;
            margin: 0.5rem 0;
        }

        .price {
            font-size: 1.8rem;
            color: #ff9800;
            font-weight: bold;
            margin: 0.5rem 0;
        }

        .description {
            color: #999;
            font-size: 0.85rem;
            margin: 0.5rem 0;
            line-height: 1.4;
        }

        .btn-buy {
            display: inline-block;
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
            padding: 0.8rem 1.5rem;
            text-decoration: none;
            border-radius: 10px;
            margin-top: 1rem;
            width: 100%;
            text-align: center;
            transition: all 0.3s;
        }

        .btn-buy:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46,125,50,0.3);
        }

        /* Features Section */
        .features-section {
            padding: 4rem 0;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .feature-card {
            text-align: center;
            padding: 2rem;
            background: white;
            border-radius: 15px;
            transition: all 0.3s;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .feature-card i {
            font-size: 3rem;
            color: #2e7d32;
            margin-bottom: 1rem;
        }

        .feature-card h3 {
            font-size: 1.3rem;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .feature-card p {
            color: #666;
        }

        /* Testimonials Section */
        .testimonials-section {
            padding: 4rem 0;
            background: white;
        }

        .testimonials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }

        .testimonial-card {
            background: #f8f9fa;
            padding: 2rem;
            border-radius: 15px;
            text-align: center;
            transition: all 0.3s;
        }

        .testimonial-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .testimonial-card i {
            font-size: 2rem;
            color: #ff9800;
            margin-bottom: 1rem;
        }

        .testimonial-text {
            color: #666;
            font-style: italic;
            margin-bottom: 1rem;
        }

        .testimonial-author {
            font-weight: 600;
            color: #2e7d32;
        }

        /* CTA Section */
        .cta-section {
            padding: 4rem 0;
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
            text-align: center;
        }

        .cta-section h2 {
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .cta-section p {
            margin-bottom: 2rem;
            opacity: 0.9;
        }

        .btn-cta {
            display: inline-block;
            background: #ff9800;
            color: white;
            padding: 1rem 2rem;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            background: #f57c00;
        }

        /* Footer */
        .footer {
            background: linear-gradient(135deg, #1a1a1a, #2d2d2d);
            color: white;
            padding: 3rem 0 1rem 0;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .footer-section h3 {
            margin-bottom: 1rem;
            color: #ff9800;
        }

        .footer-section ul {
            list-style: none;
        }

        .footer-section ul li {
            margin-bottom: 0.5rem;
        }

        .footer-section a {
            color: white;
            text-decoration: none;
            transition: color 0.3s;
        }

        .footer-section a:hover {
            color: #ff9800;
            padding-left: 5px;
        }

        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .social-links a {
            display: inline-block;
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            text-align: center;
            line-height: 40px;
            transition: all 0.3s;
        }

        .social-links a:hover {
            background: #ff9800;
            transform: translateY(-3px);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 2rem;
            border-top: 1px solid #444;
        }

        /* No Products Message */
        .no-products {
            text-align: center;
            padding: 3rem;
            background: #f8f9fa;
            border-radius: 15px;
            grid-column: 1 / -1;
        }

        .no-products i {
            font-size: 4rem;
            color: #2e7d32;
            margin-bottom: 1rem;
        }

        /* Loading Animation */
        .loading-spinner {
            text-align: center;
            padding: 2rem;
            grid-column: 1 / -1;
        }

        .loading-spinner::after {
            content: '';
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #2e7d32;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .navbar .container {
                flex-direction: column;
                gap: 1rem;
            }

            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
                gap: 1rem;
            }

            .hero h2 {
                font-size: 2rem;
            }

            .hero p {
                font-size: 1rem;
            }

            .section-title {
                font-size: 2rem;
            }

            .product-grid {
                grid-template-columns: 1fr;
            }

            .search-box {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="container">
            <div class="logo">
                <i class="fas fa-seedling"></i>
                <h1>AgriMarket</h1>
            </div>
            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-home"></i> Home</a></li>
                <li><a href="#products"><i class="fas fa-shopping-bag"></i> Products</a></li>
                <li><a href="#features"><i class="fas fa-star"></i> Features</a></li>
                <li><a href="#testimonials"><i class="fas fa-comments"></i> Testimonials</a></li>
                <?php if(isset($_SESSION['user_id'])): ?>
                    <li><a href="dashboard/<?php echo htmlspecialchars($_SESSION['role']); ?>.php"><i class="fas fa-dashboard"></i> Dashboard</a></li>
                    <li><a href="auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                    <li class="welcome"><i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['username']); ?></li>
                <?php else: ?>
                    <li><a href="auth/login.php"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                    <li><a href="auth/register.php"><i class="fas fa-user-plus"></i> Register</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <h2>Fresh Agricultural Products <br> Direct from Farmers</h2>
                <p>Connect with local farmers, get the best prices, and ensure quality produce</p>
                <?php if(!isset($_SESSION['user_id'])): ?>
                    <a href="auth/register.php" class="btn-primary"><i class="fas fa-rocket"></i> Get Started</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Search Section -->
    <section class="search-section">
        <div class="container">
            <div class="search-box">
                <input type="text" id="searchInput" class="search-input" placeholder="Search for products, categories, or farmers...">
                <button class="search-btn" onclick="searchProducts()"><i class="fas fa-search"></i> Search</button>
            </div>
        </div>
    </section>

    <!-- Categories Section -->
    <section class="categories-section">
        <div class="container">
            <h2 class="section-title">Shop by Category</h2>
            <div class="categories-grid">
                <div class="category-card" onclick="filterCategory('Vegetables')">
                    <i class="fas fa-carrot"></i>
                    <h3>Vegetables</h3>
                </div>
                <div class="category-card" onclick="filterCategory('Fruits')">
                    <i class="fas fa-apple-alt"></i>
                    <h3>Fruits</h3>
                </div>
                <div class="category-card" onclick="filterCategory('Grains')">
                    <i class="fas fa-wheat-alt"></i>
                    <h3>Grains</h3>
                </div>
                <div class="category-card" onclick="filterCategory('Dairy')">
                    <i class="fas fa-cheese"></i>
                    <h3>Dairy</h3>
                </div>
                <div class="category-card" onclick="filterCategory('Meat')">
                    <i class="fas fa-drumstick-bite"></i>
                    <h3>Meat</h3>
                </div>
                <div class="category-card" onclick="filterCategory('Organic')">
                    <i class="fas fa-leaf"></i>
                    <h3>Organic</h3>
                </div>
            </div>
        </div>
    </section>

    <!-- Products Section -->
    <section id="products" class="products-section">
        <div class="container">
            <h2 class="section-title">Featured Products</h2>
            <div class="product-grid" id="productGrid">
                <?php
                if (isset($pdo)) {
                    try {
                        $stmt = $pdo->query("
                            SELECT p.*, u.username as farmer_name 
                            FROM products p 
                            JOIN users u ON p.farmer_id = u.id 
                            WHERE p.status = 'available' 
                            ORDER BY p.created_at DESC 
                            LIMIT 6
                        ");
                        
                        $productCount = $stmt->rowCount();
                        
                        if($productCount > 0):
                            while($product = $stmt->fetch(PDO::FETCH_ASSOC)):
                ?>
                                <div class="product-card" data-category="<?php echo htmlspecialchars($product['category']); ?>">
                                    <div class="product-badge">Fresh</div>
                                    <div class="product-image">
                                        <i class="fas fa-apple-alt"></i>
                                    </div>
                                    <div class="product-info">
                                        <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                                        <p class="farmer"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($product['farmer_name']); ?></p>
                                        <span class="category"><?php echo htmlspecialchars($product['category']); ?></span>
                                        <p class="quantity"><i class="fas fa-weight-hanging"></i> <?php echo htmlspecialchars($product['quantity']); ?> <?php echo htmlspecialchars($product['unit']); ?></p>
                                        <p class="price">₱<?php echo number_format($product['price'], 2); ?></p>
                                        <p class="description"><?php echo htmlspecialchars(substr($product['description'], 0, 80)); ?>...</p>
                                        <?php if(isset($_SESSION['user_id']) && $_SESSION['role'] == 'user'): ?>
                                            <a href="dashboard/user.php?action=buy&id=<?php echo $product['id']; ?>" class="btn-buy" onclick="return confirm('Do you want to purchase this product?')"><i class="fas fa-shopping-cart"></i> Buy Now</a>
                                        <?php elseif(!isset($_SESSION['user_id'])): ?>
                                            <a href="auth/login.php" class="btn-buy"><i class="fas fa-sign-in-alt"></i> Login to Buy</a>
                                        <?php elseif(isset($_SESSION['user_id']) && $_SESSION['role'] == 'farmer'): ?>
                                            <a href="dashboard/farmer.php" class="btn-buy" style="background: linear-gradient(135deg, #ff9800, #f57c00);"><i class="fas fa-plus-circle"></i> Manage Products</a>
                                        <?php elseif(isset($_SESSION['user_id']) && $_SESSION['role'] == 'transport'): ?>
                                            <a href="dashboard/transport.php" class="btn-buy" style="background: linear-gradient(135deg, #2196f3, #1976d2);"><i class="fas fa-truck"></i> View Deliveries</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                <?php 
                            endwhile;
                        else:
                ?>
                            <div class="no-products">
                                <i class="fas fa-box-open"></i>
                                <h3>No Products Available</h3>
                                <p>Check back later for fresh products from our farmers.</p>
                            </div>
                <?php
                        endif;
                    } catch(PDOException $e) {
                        echo "<div class='no-products'><i class='fas fa-exclamation-triangle'></i><h3>Error loading products</h3><p>" . htmlspecialchars($e->getMessage()) . "</p></div>";
                    }
                } else {
                    echo "<div class='no-products'><i class='fas fa-database'></i><h3>Database Connection Error</h3><p>Please check your database configuration.</p></div>";
                }
                ?>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="features-section">
        <div class="container">
            <h2 class="section-title">Why Choose Us?</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <i class="fas fa-tractor"></i>
                    <h3>Direct from Farmers</h3>
                    <p>No middlemen, get fresh products directly from local farmers</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-tag"></i>
                    <h3>Best Prices</h3>
                    <p>Get competitive prices without market markups</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-truck"></i>
                    <h3>Fast Delivery</h3>
                    <p>Quick and reliable delivery to your doorstep</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-leaf"></i>
                    <h3>Fresh & Organic</h3>
                    <p>Quality assured products from trusted farmers</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials Section -->
    <section id="testimonials" class="testimonials-section">
        <div class="container">
            <h2 class="section-title">What Our Customers Say</h2>
            <div class="testimonials-grid">
                <div class="testimonial-card">
                    <i class="fas fa-quote-left"></i>
                    <p class="testimonial-text">"Amazing quality products! The vegetables are always fresh and delivered on time."</p>
                    <p class="testimonial-author">- Maria Santos, Buyer</p>
                </div>
                <div class="testimonial-card">
                    <i class="fas fa-quote-left"></i>
                    <p class="testimonial-text">"As a farmer, this platform helped me reach more customers and get better prices."</p>
                    <p class="testimonial-author">- Juan Dela Cruz, Farmer</p>
                </div>
                <div class="testimonial-card">
                    <i class="fas fa-quote-left"></i>
                    <p class="testimonial-text">"Reliable transport service! My orders always arrive fresh and on schedule."</p>
                    <p class="testimonial-author">- Ana Reyes, Regular Customer</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="container">
            <h2>Ready to Start Your Agricultural Journey?</h2>
            <p>Join thousands of farmers, buyers, and transport providers in our community</p>
            <?php if(!isset($_SESSION['user_id'])): ?>
                <a href="auth/register.php" class="btn-cta"><i class="fas fa-user-plus"></i> Register Now</a>
            <?php else: ?>
                <a href="dashboard/<?php echo $_SESSION['role']; ?>.php" class="btn-cta"><i class="fas fa-dashboard"></i> Go to Dashboard</a>
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3><i class="fas fa-seedling"></i> AgriMarket</h3>
                    <p>Connecting local farmers directly with consumers for fresh, quality agricultural products.</p>
                    <div class="social-links">
                        <a href="#"><i class="fab fa-facebook"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                <div class="footer-section">
                    <h3>Quick Links</h3>
                    <ul>
                        <li><a href="index.php">Home</a></li>
                        <li><a href="#products">Products</a></li>
                        <li><a href="#features">Features</a></li>
                        <li><a href="#testimonials">Testimonials</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h3>For Users</h3>
                    <ul>
                        <?php if(!isset($_SESSION['user_id'])): ?>
                            <li><a href="auth/register.php">Register</a></li>
                            <li><a href="auth/login.php">Login</a></li>
                        <?php endif; ?>
                        <li><a href="#">How to Buy</a></li>
                        <li><a href="#">Become a Farmer</a></li>
                        <li><a href="#">Join as Transport</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h3>Contact Info</h3>
                    <p><i class="fas fa-envelope"></i> clemenceniyomubyeyi@gmail.com</p>
                    <p><i class="fas fa-phone"></i> 0796336669</p>
                    <p><i class="fas fa-map-marker-alt"></i> Carfonia</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2024 AgriMarket. All rights reserved. | <i class="fas fa-heart" style="color: #ff6b6b;"></i> Supporting Local Farmers</p>
            </div>
        </div>
    </footer>

    <script>
        // Search functionality
        function searchProducts() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const products = document.querySelectorAll('.product-card');
            
            products.forEach(product => {
                const title = product.querySelector('h3').textContent.toLowerCase();
                const farmer = product.querySelector('.farmer').textContent.toLowerCase();
                const category = product.querySelector('.category').textContent.toLowerCase();
                
                if(title.includes(searchTerm) || farmer.includes(searchTerm) || category.includes(searchTerm)) {
                    product.style.display = 'block';
                } else {
                    product.style.display = 'none';
                }
            });
        }

        // Filter by category
        function filterCategory(category) {
            const products = document.querySelectorAll('.product-card');
            products.forEach(product => {
                const productCategory = product.getAttribute('data-category');
                if(productCategory === category) {
                    product.style.display = 'block';
                    product.scrollIntoView({ behavior: 'smooth' });
                } else {
                    product.style.display = 'none';
                }
            });
        }

        // Reset filter (show all products)
        function showAllProducts() {
            const products = document.querySelectorAll('.product-card');
            products.forEach(product => {
                product.style.display = 'block';
            });
        }

        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if(target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

        // Add animation on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if(entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        document.querySelectorAll('.category-card, .product-card, .feature-card, .testimonial-card').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            el.style.transition = 'all 0.6s ease';
            observer.observe(el);
        });

        // Search input enter key
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if(e.key === 'Enter') {
                searchProducts();
            }
        });
    </script>
</body>
</html>