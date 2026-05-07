<!DOCTYPE html>
<html>

<head>
    <title>AI Property Estimator</title>
    <link rel="stylesheet" href="/css/ai-estimator.css">
</head>

<body class="app-bg">

    <div class="wrap">

        <!-- HEADER -->
        <div class="header">
            <h1>Property AI Rate Estimator</h1>
            <p>Helping you to see property prices in your dream location</p>
        </div>

        <!-- STEPS -->
        <!-- <div class="steps">
        <div class="step active">1</div>
        <div class="step">2</div>
        <div class="step">3</div>
        <div class="step">4</div>
        <div class="step">5</div>
    </div> -->
        <div class="step-wrapper">

            <div class="step-item active">
                <div class="circle">1</div>
                <span>AREA</span>
            </div>

            <div class="line"></div>

            <div class="step-item">
                <div class="circle">2</div>
                <span>CATEGORY</span>
            </div>

            <div class="line"></div>

            <div class="step-item">
                <div class="circle">3</div>
                <span>TYPE</span>
            </div>

            <div class="line"></div>

            <div class="step-item">
                <div class="circle">4</div>
                <span>SIZE</span>
            </div>

            <div class="line"></div>

            <div class="step-item">
                <div class="circle">5</div>
                <span>PRICING</span>
            </div>

        </div>


        <!-- CARD -->
        <div class="card-box">

            <!-- STEP 1 -->
            <div class="step-content active" id="step1">
                <h2>Select Location</h2>

                <div class="form-group">
                    <label>Country</label>
                    <select id="country" required>
                        <option value="">Select Country</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>State</label>
                    <select id="state" required disabled>
                        <option value="">Select State</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>City</label>
                    <select id="city" required disabled>
                        <option value="">Select City</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Area</label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input id="area" placeholder="Enter Area (e.g. Baner)" list="area_suggestions" required disabled style="flex:1 1 auto;">
                        <span id="area_loading" role="status" aria-live="polite" style="display:none; font-size:12px; opacity:.8; white-space:nowrap;">Loading...</span>
                    </div>
                    <datalist id="area_suggestions"></datalist>
                </div>

                <button onclick="nextStep(2)">Next →</button>
            </div>

            <!-- STEP 2 -->
            <div class="step-content" id="step2">
                <h2>Select Category</h2>

                <div class="category-wrap">

                    <div class="category-pill" onclick="selectCategory(this)">
                        <svg viewBox="0 0 24 24">
                            <path d="M3 21V3h18v18H3zm4-2h2v-2H7v2zm0-4h2v-2H7v2zm0-4h2V9H7v2zm4 8h2v-2h-2v2zm0-4h2v-2h-2v2zm0-4h2V9h-2v2zm4 8h2v-6h-2v6z" />
                        </svg>
                        <span>Bungalow</span>
                    </div>

                    <div class="category-pill" onclick="selectCategory(this)">
                        <svg viewBox="0 0 24 24">
                            <path d="M3 13l9-9 9 9v8H3v-8z" />
                        </svg>
                        <span>Flat</span>
                    </div>

                    <div class="category-pill" onclick="selectCategory(this)">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 2l10 6-10 6L2 8l10-6zm0 8l10 6-10 6-10-6 10-6z" />
                        </svg>
                        <span>Commercial</span>
                    </div>

                    <div class="category-pill" onclick="selectCategory(this)">
                        <svg viewBox="0 0 24 24">
                            <path d="M4 10l8-6 8 6v10H4V10z" />
                        </svg>
                        <span>House</span>
                    </div>

                    <div class="category-pill" onclick="selectCategory(this)">
                        <svg viewBox="0 0 24 24">
                            <path d="M2 12l10-8 10 8v10H2V12z" />
                        </svg>
                        <span>Villa</span>
                    </div>

                    <div class="category-pill" onclick="selectCategory(this)">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 2a7 7 0 017 7c0 5-7 13-7 13S5 14 5 9a7 7 0 017-7z" />
                        </svg>
                        <span>Plot</span>
                    </div>

                </div>

                <button onclick="prevStep(1)">← Back</button>
                <button onclick="nextStep(3)">Next →</button>
            </div>

            <!-- STEP 3 -->
            <div class="step-content" id="step3">
                <h2>Select Type</h2>

                <div class="grid">
                    <div class="cat center" onclick="selectCard(this)">Sell</div>
                    <div class="cat center" onclick="selectCard(this)">Rent</div>
                </div>

                <button onclick="prevStep(2)">← Back</button>
                <button onclick="nextStep(4)">Next →</button>
            </div>

            <!-- STEP 4 -->
            <div class="step-content" id="step4">
                <h2>Enter Size (per sq/ft)</h2>

                <input type="number" id="sqft" placeholder="Enter Size (per sq/ft)" required>

                <button onclick="prevStep(3)">← Back</button>
                <button onclick="calculate()">Calculate →</button>
            </div>

            <!-- STEP 5 -->

            <!-- <div class="step-content" id="step5">
                <h2>Estimated Price</h2>

                <h1 id="total">₹ 0</h1>
                <p id="per">₹ 0 / sqft</p>
                <p id="min_price">Minimum Price: ₹ 0 / sqft</p>
                <p id="max_price">Maximum Price: ₹ 0 / sqft</p>
                <p id="avg_price">Average Price: ₹ 0 / sqft</p>

                <button onclick="prevStep(4)">← Back</button>
            </div> -->

            <div class="step-content" id="step5">
                <h2>Price Comparison</h2>

                <div class="price-wrapper">

                    <!-- ✅ AI Estimate Box -->
                    <div class="price-box client-box">
                        <h3>Estimated Price</h3>

                        <h1 id="total">₹ 0</h1>
                    </div>

                    <!-- ✅ Client Price Box -->
                    <div class="price-box client-box">
                        <h3>Heybroker Price</h3>

                        <!-- <input type="number" id="client_price" placeholder="Enter your price per sqft"> -->

                        <h1 id="client_total">₹ 0</h1>
                        <p id="client_status"></p>
                    </div>

                </div>

                <button onclick="prevStep(4)">← Back</button>
            </div>
        </div>
        <!-- <div class="step-content" id="step5">
                <h2>Estimated Price</h2>

                <h1 id="total">₹ 0</h1>
                <p id="per">₹ 0 / sqft</p>

                <button onclick="prevStep(4)">← Back</button>
            </div> -->

    </div>

    <script src="/js/ai-estimator.js"></script>

</body>

</html>