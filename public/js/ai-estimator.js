let step = 1;
let lastClientFetchKey = null;

function aiEstimatorUserMessage(payload, genericFallback) {
    if (payload && typeof payload.message === 'string' && payload.message.trim().length > 0) {
        return payload.message.trim();
    }
    return genericFallback;
}

function nextStep(n) {
    if (step === 1) {
        let country = document.getElementById("country").value;
        let state = document.getElementById("state").value;
        let city = document.getElementById("city").value;
        let area = document.getElementById("area").value;
    

        if (!country || !state || !city || !area) {
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

    // Auto-fetch Heybroker price when entering pricing step
    if (step === 5) {
        calculateClient({ silent: true });
    }
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
    if (!country || !state || !city || !area || !sqft || Number(sqft) <= 0 || !category || !propertyType) {
        alert("Please fill all fields");
        return;
    }

    // ðŸ”„ Loading UI
    document.getElementById("total").innerText = "Calculating...";
    const perEl = document.getElementById("per");
    if (perEl) perEl.innerText = "";

    // âœ… API Call
    fetch("/api/price-estimate", {
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
        .then(async (res) => {
            const body = await res.json().catch(() => ({}));
            return { body };
        })
        .then(({ body }) => {

            if (!body.status) {
                const msg = aiEstimatorUserMessage(
                    body,
                    "We couldn't estimate the price right now. Please try different details or try again later."
                );
                document.getElementById("total").innerText = "—";
                alert(msg);
                return;
            }

            let data = body.data;

             // ✅ Update UI (total only)
    document.getElementById("total").innerText =
        "₹ " + data.total_price.toLocaleString();

            changeStep(5);
        })
        .catch(err => {
            console.error(err);
            document.getElementById("total").innerText = "—";
            alert("We couldn't fetch the price with the selected filters. Please try different filters or try again later.");
        });
}

function calculateClient(opts) {
    const silent = Boolean(opts && opts.silent);

    let country = document.getElementById("country").value;
    let state = document.getElementById("state").value;
    let city = document.getElementById("city").value;
    let area = document.getElementById("area").value;
    let sqft = document.getElementById("sqft").value;

     let category = document.querySelector(".category-pill.active")?.textContent.trim() || '';
  let propertyType = document.querySelector(".cat.active")?.textContent.trim() || '';

    // âœ… Validation
    if (!country || !state || !city || !area || !sqft || Number(sqft) <= 0 || !category || !propertyType) {
        if (!silent) alert("Please fill all fields");
        return;
    }

    const key = [
        country, state, city, area, String(propertyType), String(category), String(sqft)
    ].join("|").toLowerCase();
    if (lastClientFetchKey === key) return;
    lastClientFetchKey = key;

    // ðŸ”„ Loading UI
    document.getElementById("client_total").innerText = "Loading...";
    const clientStatusEl = document.getElementById("client_status");
    if (clientStatusEl) clientStatusEl.innerText = "";

    // âœ… API CALL
    fetch("/api/price-estimate-client", {
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
    .then(async (res) => {
        const body = await res.json().catch(() => ({}));
        return { httpStatus: res.status, ok: res.ok, body };
    })
    .then(res => {
        const genericHeybroker = "We couldn't load Heybroker pricing right now. Please try again or adjust your selections.";

        if (!res.ok || !res.body?.status) {
            const msg = aiEstimatorUserMessage(res.body, genericHeybroker);
            if (res.httpStatus === 404) {
                document.getElementById("client_total").innerText = "Not available right now";
                if (clientStatusEl) clientStatusEl.innerText = msg;
                return;
            }
            document.getElementById("client_total").innerText = "—";
            if (clientStatusEl) clientStatusEl.innerText = msg;
            if (!silent) alert(msg);
            return;
        }

        let data = res.body.data || {};
        let minPrice = Number(data.min_price);

        if (!Number.isFinite(minPrice) || minPrice <= 0) {
            document.getElementById("client_total").innerText = "Not available right now";
            return;
        }

        let total = minPrice * Number(sqft);

        // âœ… Update UI (total only)
        document.getElementById("client_total").innerText =
            "₹ " + total.toLocaleString();
        if (clientStatusEl) clientStatusEl.innerText = "";

    })
    .catch(err => {
        console.error(err);
        document.getElementById("client_total").innerText = "—";
        if (clientStatusEl) clientStatusEl.innerText = "We couldn't load Heybroker pricing right now. Please try again.";
        if (!silent) alert("We couldn't fetch the price with the selected filters. Please try different filters or try again later.");
    });
}
function selectCategory(el) {
    document.querySelectorAll(".category-pill").forEach(e => e.classList.remove("active"));
    el.classList.add("active");
}
// ================= LOCATION API =================

document.addEventListener("DOMContentLoaded", function () {

    const countryEl = document.getElementById("country");
    const stateEl = document.getElementById("state");
    const cityEl = document.getElementById("city");
    const areaEl = document.getElementById("area");
    const areaListEl = document.getElementById("area_suggestions");
    const areaLoadingEl = document.getElementById("area_loading");
    const areaProvider = (areaEl?.dataset?.provider || window.AI_ESTIMATOR_AREA_PROVIDER || "google").toString().toLowerCase();

    // Step bar behavior:
    // - cannot skip ahead
    // - can click current/previous steps to go back
    document.querySelectorAll(".step-item").forEach((el, index) => {
        const targetStep = index + 1;
        el.style.pointerEvents = "auto";
        el.style.cursor = "pointer";
        el.addEventListener("click", function () {
            if (targetStep <= step) changeStep(targetStep);
        });
    });

    if (!areaListEl) {
        console.error("[ai-estimator] Missing datalist#area_suggestions for #area");
        return;
    }

    const cache = {
        states: new Map(), // key: country
        cities: new Map(), // key: `${country}|${state}`
        areas: new Map(),  // key: `${country}|${state}|${city}|${s}`
    };

    function escapeHtml(str) {
        return String(str)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#039;");
    }

    function setSelectOptions(select, options, placeholder) {
        const ph = placeholder || "Select";
        select.innerHTML = `<option value="">${escapeHtml(ph)}</option>`;
        options.forEach(v => {
            select.innerHTML += `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`;
        });
    }

    function debounce(fn, waitMs) {
        let t = null;
        return (...args) => {
            clearTimeout(t);
            t = setTimeout(() => fn(...args), waitMs);
        };
    }

    function resetDownstreamFromCountry() {
        stateEl.disabled = true;
        cityEl.disabled = true;
        areaEl.disabled = true;
        setSelectOptions(stateEl, [], "Select State");
        setSelectOptions(cityEl, [], "Select City");
        areaEl.value = "";
        areaListEl.innerHTML = "";
        setAreaLoading(false);
        if (areasAbort) {
            try { areasAbort.abort(); } catch (_) {}
        }
    }

    function resetDownstreamFromState() {
        cityEl.disabled = true;
        areaEl.disabled = true;
        setSelectOptions(cityEl, [], "Select City");
        areaEl.value = "";
        areaListEl.innerHTML = "";
        setAreaLoading(false);
        if (areasAbort) {
            try { areasAbort.abort(); } catch (_) {}
        }
    }

    function resetDownstreamFromCity() {
        areaEl.disabled = true;
        areaEl.value = "";
        areaListEl.innerHTML = "";
        setAreaLoading(false);
        if (areasAbort) {
            try { areasAbort.abort(); } catch (_) {}
        }
    }

    function setAreaLoading(isLoading) {
        if (!areaLoadingEl) return;
        areaLoadingEl.style.display = isLoading ? "inline" : "none";
        areaLoadingEl.setAttribute("aria-busy", isLoading ? "true" : "false");
    }

    async function loadCountries() {
        countryEl.innerHTML = `<option value="">Loading...</option>`;
        try {
            const res = await fetch("/api/countries");
            const data = await res.json();
            const names = Array.isArray(data) ? data.map(c => c?.name).filter(Boolean) : [];
            setSelectOptions(countryEl, names, "Select Country");
        } catch (e) {
            countryEl.innerHTML = `<option value="">Error loading countries</option>`;
        }
    }

    async function loadStates(country) {
        resetDownstreamFromCountry();
        if (!country) return;

        stateEl.disabled = true;
        stateEl.innerHTML = `<option value="">Loading...</option>`;

        if (cache.states.has(country)) {
            const states = cache.states.get(country);
            setSelectOptions(stateEl, states, "Select State");
            stateEl.disabled = states.length === 0;
            return;
        }

        try {
            const res = await fetch(`/api/states?country=${encodeURIComponent(country)}`);
            const data = await res.json();
            const states = Array.isArray(data) ? data : [];
            cache.states.set(country, states);
            setSelectOptions(stateEl, states, "Select State");
            stateEl.disabled = states.length === 0;
        } catch (e) {
            setSelectOptions(stateEl, [], "Error loading states");
            stateEl.disabled = true;
        }
    }

    async function loadCities(country, state) {
        resetDownstreamFromState();
        if (!country || !state) return;

        const key = `${country}|${state}`;
        cityEl.disabled = true;
        cityEl.innerHTML = `<option value="">Loading...</option>`;

        if (cache.cities.has(key)) {
            const cities = cache.cities.get(key);
            setSelectOptions(cityEl, cities, "Select City");
            cityEl.disabled = cities.length === 0;
            return;
        }

        try {
            const res = await fetch(`/api/cities?country=${encodeURIComponent(country)}&state=${encodeURIComponent(state)}`);
            const data = await res.json();
            const cities = Array.isArray(data) ? data : [];
            cache.cities.set(key, cities);
            setSelectOptions(cityEl, cities, "Select City");
            cityEl.disabled = cities.length === 0;
        } catch (e) {
            setSelectOptions(cityEl, [], "Error loading cities");
            cityEl.disabled = true;
        }
    }

    let areasAbort = null;
    let areasRequestSeq = 0;
    let lastAreasRequestId = 0;
    const loadAreas = debounce(async function () {
        const country = countryEl.value;
        const state = stateEl.value;
        const city = cityEl.value;
        const s = areaEl.value.trim();

        areaListEl.innerHTML = "";
        if (!country || !state || !city || s.length < 3) {
            setAreaLoading(false);
            return; // trigger after 3 chars
        }

        let cityParam = city;
        if (String(country).trim().toLowerCase() === "india") {
            cityParam = String(cityParam).replace(/\s+urban$/i, "").trim();
        }

        const key = `${country}|${state}|${cityParam}|${s.toLowerCase()}`;
        if (cache.areas.has(key)) {
            const areas = cache.areas.get(key);
            areaListEl.innerHTML = areas.map(a => `<option value="${escapeHtml(a)}"></option>`).join("");
            setAreaLoading(false);
            return;
        }

        if (areasAbort) {
            try { areasAbort.abort(); } catch (_) {}
        }

        const requestId = ++areasRequestSeq;
        lastAreasRequestId = requestId;
        areasAbort = new AbortController();
        setAreaLoading(true);

        try {
            const url = areaProvider === "google"
                ? `/api/area-autocomplete?input=${encodeURIComponent(s)}&country=${encodeURIComponent(country)}&state=${encodeURIComponent(state)}&city=${encodeURIComponent(cityParam)}`
                : `/api/areas?s=${encodeURIComponent(s)}&country=${encodeURIComponent(country)}&state=${encodeURIComponent(state)}&city=${encodeURIComponent(cityParam)}`;
            const res = await fetch(url, { signal: areasAbort.signal });
            const data = await res.json();
            const areas = Array.isArray(data) ? data : [];
            cache.areas.set(key, areas);
            areaListEl.innerHTML = areas.map(a => `<option value="${escapeHtml(a)}"></option>`).join("");
        } catch (e) {
            if (e && e.name === "AbortError") return;
            console.error("[ai-estimator] Failed loading areas", e);
        } finally {
            if (requestId === lastAreasRequestId) setAreaLoading(false);
        }
    }, 300);

    countryEl.addEventListener("change", function () {
        loadStates(countryEl.value);
    });

    stateEl.addEventListener("change", function () {
        loadCities(countryEl.value, stateEl.value);
    });

    cityEl.addEventListener("change", function () {
        resetDownstreamFromCity();
        if (countryEl.value && stateEl.value && cityEl.value) {
            areaEl.disabled = false;
            areaEl.focus();
        }
    });

    areaEl.addEventListener("input", loadAreas);
    // Native datalist should fill input, but some UIs
    // rely on change/blur to persist selection.
    areaEl.addEventListener("change", function () {
        areaEl.value = areaEl.value;
    });

    resetDownstreamFromCountry();
    loadCountries();

});