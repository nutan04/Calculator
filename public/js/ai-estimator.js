let step = 1;

function nextStep(n) {
    console.log(n);
    if (step === 1) {
        let state = document.getElementById("state").value;
        let city = document.getElementById("city").value;
        let area = document.getElementById("area").value;
    

        if (!state || !city || !area) {
            alert("Please fill all fields");
            return;
        }
    }
    
  // ✅ STEP 2 VALIDATION (Category)
    if (step === 2) {
        let category = document.querySelector(".category-pill.active");

        if (!category) {
            alert("Please select a category");
            return;
        }
    }

    // ✅ STEP 3 VALIDATION (Type)
    if (step === 3) {
        let type = document.querySelector(".cat.active");

        if (!type) {
            alert("Please select a type");
            return;
        }
    }
    changeStep(n);
}

function prevStep(n) {
    changeStep(n);
}

function changeStep(n) {

    // âœ… HIDE ALL STEPS
    document.querySelectorAll(".step-content").forEach(el => {
        el.classList.remove("active");
    });

    // âœ… SHOW CURRENT STEP ONLY
    document.getElementById("step" + n).classList.add("active");

    // âœ… UPDATE STEP BAR
    updateStepUI(n);

    step = n;
}

function updateStepUI(current) {
    let steps = document.querySelectorAll(".step-item");

    steps.forEach((el, index) => {
        el.classList.remove("active");

        if (index + 1 === current) {
            el.classList.add("active");
        }
    });
}

function selectCard(el) {
    let parent = el.parentElement;
    parent.querySelectorAll(".cat").forEach(e => e.classList.remove("active"));
    el.classList.add("active");
}

function calculate() {

    let country = document.getElementById("country").value;
    let state = document.getElementById("state").value;
    let city = document.getElementById("city").value;
    let area = document.getElementById("area").value;
    let sqft = document.getElementById("sqft").value;

   let category = document.querySelector(".category-pill.active")?.textContent.trim() || '';
  let propertyType = document.querySelector(".cat.active")?.textContent.trim() || '';

    


    // âœ… Validation
    if (!state || !city || !area || !sqft || !category || !propertyType) {
        alert("Please fill all fields");
        return;
    }

    // ðŸ”„ Loading UI
    document.getElementById("total").innerText = "Calculating...";
    document.getElementById("per").innerText = "";

    // âœ… API Call
    fetch("https://calculator.heybrokr.com/api/price-estimate", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            country: country,
            state: state,
            city: city,
            area: area,
            property_type: propertyType,
            category: category,
            sqft: sqft
        })
    })
        .then(res => res.json())
        .then(res => {

            if (!res.status) {
                alert("Error fetching price");
                return;
            }

            let data = res.data;

             // ✅ Update UI
    document.getElementById("total").innerText =
        "₹ " + data.total_price.toLocaleString();

    document.getElementById("per").innerText =
        "₹ " + data.per_sqft + " / sqft";

    document.getElementById("min_price").innerText =
        "Min: ₹ " + data.min_price + " / sqft";

    document.getElementById("max_price").innerText =
        "Max: ₹ " + data.max_price + " / sqft";

    document.getElementById("avg_price").innerText =
        "Avg: ₹ " + data.avg_price + " / sqft";

            changeStep(5);
        })
        .catch(err => {
            console.error(err);
            alert("We couldn't fetch the price with the selected filters. Please try different filters or try again later.");
        });
}

function calculateClient() {

    let country = document.getElementById("country").value;
    let state = document.getElementById("state").value;
    let city = document.getElementById("city").value;
    let area = document.getElementById("area").value;
    let sqft = document.getElementById("sqft").value;

     let category = document.querySelector(".category-pill.active")?.textContent.trim() || '';
  let propertyType = document.querySelector(".cat.active")?.textContent.trim() || '';

    // ðŸ”„ Loading UI
    document.getElementById("client_total").innerText = "Loading...";
    document.getElementById("client_per").innerText = "";

    // âœ… API CALL
    fetch("https://calculator.heybrokr.com/api/price-estimate-client", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            country: country,
            state: state,
            city: city,
            area: area,
            property_type: propertyType,
            category: category,
            sqft: sqft
        })
    })
    .then(res => res.json())
    .then(res => {

        if (!res.status) {
            alert("Error fetching client price");
            return;
        }

        let data = res.data;

        let avg = parseFloat(data.avg_price);
        let total = avg * sqft;

        // âœ… Update UI
        document.getElementById("client_total").innerText =
            "â‚¹ " + total.toLocaleString();

        document.getElementById("client_per").innerText =
            "â‚¹ " + avg + " / sqft";

    })
    .catch(err => {
        console.error(err);
        alert("We couldn't fetch the price with the selected filters. Please try different filters or try again later.");
    });
}
function selectCategory(el) {
    document.querySelectorAll(".category-pill").forEach(e => e.classList.remove("active"));
    el.classList.add("active");
}
// ================= LOCATION API =================

document.addEventListener("DOMContentLoaded", function () {

    const stateEl = document.getElementById("state");
    const cityEl = document.getElementById("city");

    // âœ… Load states (India default)
    fetch('/api/states/india')
        .then(res => res.json())
        .then(data => {

            stateEl.innerHTML = '<option value="">Select State</option>';

            data.forEach(state => {
                stateEl.innerHTML += `<option value="${state}">${state}</option>`;
            });

        })
        .catch(() => {
            stateEl.innerHTML = '<option>Error loading states</option>';
        });

    // âœ… When state changes â†’ load cities
    stateEl.addEventListener("change", function () {

        let selectedState = this.value;

        cityEl.innerHTML = '<option>Loading...</option>';

        fetch(`/api/cities?state=${selectedState}`)
            .then(res => res.json())
            .then(data => {

                cityEl.innerHTML = '<option value="">Select City</option>';

                data.forEach(city => {
                    cityEl.innerHTML += `<option value="${city}">${city}</option>`;
                });

            })
            .catch(() => {
                cityEl.innerHTML = '<option>Error loading cities</option>';
            });
    });

});