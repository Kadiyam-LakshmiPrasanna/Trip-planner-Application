/* =========================================================
   service_request.js
   Generates a trip plan from the search form and renders it.
   A visible "Save Trip" button lets the user save the plan to
   their "My Trips" history via POST /mytrips.php, with clear
   success/error feedback shown on screen.
   ========================================================= */

let selectedCurrency = "USD";
let lastGeneratedTrip = null; // holds the most recent plan, used by saveTripToMyTrips()

// ======================================
// AI TRIP PLAN GENERATION (Groq)
// NOTE: this key is exposed to anyone viewing the page source since it
// runs client-side. For production, proxy this call through your own
// backend instead of calling Groq directly from the browser.
// ======================================
const GROQ_API_KEY = "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";

/**
 * Turns the raw AI-generated travel plan text into the styled HTML
 * used inside the Itinerary section (day cards, section headings,
 * bullet points).
 */
function formatResponse(text) {
  if (!text) {
    return `<p>No information available.</p>`;
  }

  text = String(text);

  let lines = text.split("\n");
  let html = "";
  let dayOpen = false;

  lines.forEach(line => {
    line = line.trim();

    if (line === "") {
      return;
    }

    line = line.replace(/\*\*/g, "");

    // DAY CARD DETECTION
    if (/🕘\s*Day\s*\d+/i.test(line) || /^Day\s*\d+/i.test(line)) {
      if (dayOpen) {
        html += `</div>`;
      }

      html += `
      <div class="day-card">
        <div class="day-header">
          ${line.startsWith("🕘") ? line : "🕘 " + line}
        </div>
      `;

      dayOpen = true;
      return;
    }

    // Section headings
    if (
      line.includes("Destination Description") ||
      line.includes("Famous Tourist Places") ||
      line.includes("Food Suggestions") ||
      line.includes("Travel Tips") ||
      line.includes("Budget Breakdown")
    ) {
      if (dayOpen) {
        html += `</div>`;
        dayOpen = false;
      }

      html += `<h3 class="travel-heading">${line}</h3>`;
      return;
    }

    // Bullet points
    if (line.startsWith("-") || line.startsWith("*") || line.match(/^\d+\./)) {
      html += `<div class="place-row">📍 ${line.replace(/^[-*]\s*|\d+\.\s*/, "").trim()}</div>`;
      return;
    }

    // Normal description
    if (dayOpen) {
      html += `<div class="place-description">${line}</div>`;
    } else {
      html += `<p>${line}</p>`;
    }
  });

  if (dayOpen) {
    html += `</div>`;
  }

  return html;
}

/**
 * Calls the Groq chat completions API to generate a real AI travel
 * plan (destination description, day-wise itinerary, tourist places,
 * food suggestions, travel tips). Returns the raw text response, or
 * null if the request fails — callers should fall back to the
 * template-based itinerary in that case.
 */
async function fetchAIItinerary({ currentLocation, destination, days, budget, currency }) {
  const currencySymbol = currency === "USD" ? "$" : "₹";

  const prompt = `
Create a beautiful travel plan.

Destination:
${destination}

Starting Location:
${currentLocation || "Not specified"}

Trip Days:
${days}

Budget:
${currencySymbol}${budget}

Include:

1. Destination Description
2. Day-wise Itinerary
3. Famous Tourist Places
4. Food Suggestions
5. Travel Tips

Keep response concise, beautiful and modern.
`;

  const response = await fetch("https://api.groq.com/openai/v1/chat/completions", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "Authorization": `Bearer ${GROQ_API_KEY}`
    },
    body: JSON.stringify({
      model: "openai/gpt-oss-20b",
      messages: [{ role: "user", content: prompt }],
      temperature: 0.7
    })
  });

  const data = await response.json();
  return data?.choices?.[0]?.message?.content || null;
}

// ======================================
// REAL DESTINATION IMAGE (Wikipedia REST API — no API key required)
// Looks up the exact destination text the user typed and returns
// the lead photo from the matching Wikipedia page, so the image
// actually corresponds to that place instead of a generic/similar one.
// ======================================
async function fetchDestinationImage(destination) {
  try {
    const searchUrl = `https://en.wikipedia.org/w/api.php?action=query&list=search&srsearch=${encodeURIComponent(destination)}&format=json&origin=*&srlimit=1`;
    const searchRes = await fetch(searchUrl);
    const searchData = await searchRes.json();
    const title = searchData?.query?.search?.[0]?.title;
    if (!title) return null;

    const summaryUrl = `https://en.wikipedia.org/api/rest_v1/page/summary/${encodeURIComponent(title)}`;
    const summaryRes = await fetch(summaryUrl);
    if (!summaryRes.ok) return null;
    const summaryData = await summaryRes.json();

    const image = summaryData?.originalimage?.source || summaryData?.thumbnail?.source || null;
    return image ? { url: image, sourceTitle: summaryData.title } : null;
  } catch (err) {
    console.error("Destination image lookup failed:", err);
    return null;
  }
}

// ======================================
// REAL WEATHER (Open-Meteo — geocoding + forecast, no API key required)
// Geocodes the EXACT destination text entered by the user to get
// precise coordinates, then pulls a real daily forecast for those
// coordinates covering the trip length starting tomorrow.
// ======================================
const WEATHER_CODES = {
  0: ["Clear sky", "☀️"], 1: ["Mainly clear", "🌤"], 2: ["Partly cloudy", "⛅"], 3: ["Overcast", "☁️"],
  45: ["Fog", "🌫"], 48: ["Fog", "🌫"],
  51: ["Light drizzle", "🌦"], 53: ["Drizzle", "🌦"], 55: ["Dense drizzle", "🌧"],
  61: ["Light rain", "🌧"], 63: ["Rain", "🌧"], 65: ["Heavy rain", "🌧"],
  71: ["Light snow", "🌨"], 73: ["Snow", "🌨"], 75: ["Heavy snow", "❄️"],
  80: ["Rain showers", "🌦"], 81: ["Rain showers", "🌦"], 82: ["Violent showers", "⛈"],
  95: ["Thunderstorm", "⛈"], 96: ["Thunderstorm w/ hail", "⛈"], 99: ["Thunderstorm w/ hail", "⛈"]
};

async function fetchDestinationWeather(destination, days) {
  try {
    const geoUrl = `https://geocoding-api.open-meteo.com/v1/search?name=${encodeURIComponent(destination)}&count=1&language=en&format=json`;
    const geoRes = await fetch(geoUrl);
    const geoData = await geoRes.json();
    const place = geoData?.results?.[0];
    if (!place) return { forecast: null, location: null, reason: "location-not-found" };

    const forecastDays = Math.min(Math.max(days, 1), 16); // Open-Meteo daily forecast tops out at 16 days
    const forecastUrl = `https://api.open-meteo.com/v1/forecast?latitude=${place.latitude}&longitude=${place.longitude}&daily=weathercode,temperature_2m_max,temperature_2m_min,precipitation_probability_max&forecast_days=${forecastDays}&timezone=auto`;
    const forecastRes = await fetch(forecastUrl);
    if (!forecastRes.ok) return { forecast: null, location: place, reason: "forecast-unavailable" };
    const forecastData = await forecastRes.json();

    const daily = forecastData?.daily;
    if (!daily?.time) return { forecast: null, location: place, reason: "forecast-unavailable" };

    const forecast = daily.time.map((date, i) => {
      const [label, emoji] = WEATHER_CODES[daily.weathercode[i]] || ["Weather data unavailable", "🌡"];
      return {
        date,
        condition: label,
        emoji,
        tempMax: Math.round(daily.temperature_2m_max[i]),
        tempMin: Math.round(daily.temperature_2m_min[i]),
        precipitationChance: daily.precipitation_probability_max[i]
      };
    });

    return {
      forecast,
      location: `${place.name}${place.admin1 ? ", " + place.admin1 : ""}${place.country ? ", " + place.country : ""}`,
      reason: null
    };
  } catch (err) {
    console.error("Weather lookup failed:", err);
    return { forecast: null, location: null, reason: "error" };
  }
}

document.addEventListener("DOMContentLoaded", () => {
  document.getElementById("usdBtn")?.addEventListener("click", () => setCurrency("USD"));
  document.getElementById("inrBtn")?.addEventListener("click", () => setCurrency("INR"));
  window.runTripPlanFromHome = generateTripPlan;
  window.generateTripPlan = generateTripPlan;
});
function setCurrency(currency) {
  selectedCurrency = currency;
  document.getElementById("usdBtn").classList.toggle("active-currency", currency === "USD");
  document.getElementById("inrBtn").classList.toggle("active-currency", currency === "INR");
}
// Called by the "✨ Generate Trip Plan" button, and by home.html's
// startPlanning() if it detects this function is available.
async function generateTripPlan() {
  const currentLocation = document.getElementById("currentLocation").value.trim();
  const destination      = document.getElementById("destination").value.trim();
  const days              = parseInt(document.getElementById("days").value, 10);
  const budget            = parseFloat(document.getElementById("budget").value);

  const output = document.getElementById("trip-output");
  const nav    = document.querySelector(".trip-navigation");

  if (!destination || !days || days <= 0 || !budget || budget <= 0) {
    output.innerHTML = `<div class="error-box">📍 Please fill in destination, number of days, and budget correctly.</div>`;
    if (nav) nav.style.display = "none";
    return;
  }

  output.innerHTML = `<div class="loading">✨ Generating your personalized trip plan, fetching a real destination photo and live weather...</div>`;
  if (nav) nav.style.display = "none";

  try {
    const plan = await buildTripPlan({ currentLocation, destination, days, budget, currency: selectedCurrency });

    lastGeneratedTrip = plan;

    renderTripPlan(plan);
    if (nav) nav.style.display = "flex";

  } catch (err) {
    console.error("Trip generation failed:", err);
    output.innerHTML = `<div class="error-box">Something went wrong while generating your trip. Please try again.</div>`;
  }
}

/**
 * Builds a structured trip plan. Tries to generate a real AI itinerary
 * via fetchAIItinerary() first (rendered as plan.aiItineraryHTML); if
 * that call fails for any reason, the template-based itinerary/hotels/
 * transportation/activities below are used as a fallback so the page
 * never breaks.
 */
async function buildTripPlan({ currentLocation, destination, days, budget, currency }) {
  const perDayBudget = budget / days;

  const itinerary = Array.from({ length: days }, (_, i) => ({
    day: i + 1,
    title: `Day ${i + 1} in ${destination}`,
    plan: `Explore top attractions and local experiences in ${destination}, with time for food and relaxation.`
  }));

  const hotels = [
    { name: `${destination} Comfort Inn`, pricePerNight: Math.round(perDayBudget * 0.35) },
    { name: `${destination} Central Suites`, pricePerNight: Math.round(perDayBudget * 0.5) }
  ];

  const transportation = [
    currentLocation ? `Travel from ${currentLocation} to ${destination}` : `Travel to ${destination}`,
    "Local transport: taxis, metro, or rented scooters depending on the city"
  ];

  const activities = [
    "Guided city/local sightseeing tour",
    "Popular local food tasting experience",
    "Free time for shopping and relaxation"
  ];

  const estimatedCost = Math.round(budget * 0.92); // simple placeholder cost model

  const budgetBreakdown = {
    Hotels: Math.round(budget * 0.4),
    Transportation: Math.round(budget * 0.25),
    Activities: Math.round(budget * 0.2),
    Buffer: Math.round(budget * 0.15)
  };

  // Attempt to get a real AI-generated itinerary; fall back to the
  // template-based `itinerary` array above if the API call fails.
  let aiItineraryHTML = null;
  try {
    const aiText = await fetchAIItinerary({ currentLocation, destination, days, budget, currency });
    if (aiText) aiItineraryHTML = formatResponse(aiText);
  } catch (err) {
    console.error("AI trip plan generation failed, using fallback itinerary:", err);
  }

  // Real destination image + real weather, both looked up from the
  // exact destination text the user typed (run in parallel).
  const [imageResult, weatherResult] = await Promise.all([
    fetchDestinationImage(destination),
    fetchDestinationWeather(destination, days)
  ]);

  return {
    currentLocation,
    destination,
    destinationImage: imageResult?.url || null,
    numberOfDays: days,
    numberOfPeople: 1,
    startDate: null,
    endDate: null,
    budget,
    currency,
    itinerary,
    aiItineraryHTML,
    hotels,
    transportation,
    activities,
    estimatedCost,
    budgetBreakdown,
    weather: weatherResult.forecast,
    weatherLocation: weatherResult.location,
    weatherUnavailableReason: weatherResult.reason
  };
}

function renderTripPlan(plan) {
  const output = document.getElementById("trip-output");

  output.innerHTML = `
    <div class="dashboard">

      <div class="hero-section" id="description">
        ${plan.destinationImage
          ? `<img class="hero-image" src="${plan.destinationImage}" alt="${plan.destination}">`
          : `<div class="hero-image hero-image-placeholder"><i class="fa-solid fa-image"></i><span>No verified photo found for "${plan.destination}"</span></div>`
        }
        <div class="hero-overlay">
          <h1>${plan.destination}</h1>
          <p>${plan.numberOfDays}-day trip • Budget: ${plan.currency} ${plan.budget.toLocaleString()}</p>
        </div>
      </div>

      <div class="top-cards">
        <div class="info-card">
          <h3>Duration</h3>
          <p>${plan.numberOfDays} Days</p>
        </div>
        <div class="info-card">
          <h3>Budget</h3>
          <p>${plan.currency} ${plan.budget.toLocaleString()}</p>
        </div>
        <div class="info-card">
          <h3>Estimated Cost</h3>
          <p>${plan.currency} ${plan.estimatedCost.toLocaleString()}</p>
        </div>
      </div>

      <div class="main-content">

        <div class="content-card">

          <section id="itinerary-section">
            <h2>Itinerary</h2>
            <div class="trip-result">
              ${plan.aiItineraryHTML || plan.itinerary.map(day => `
                <h3>${day.title}</h3>
                <p>${day.plan}</p>
              `).join("")}
            </div>
          </section>

          <section id="map-section">
            <h2>Route Map</h2>
            <iframe
              class="map-frame"
              src="https://www.google.com/maps?q=${encodeURIComponent(plan.destination)}&output=embed"
              loading="lazy">
            </iframe>
          </section>

          <section id="weather-section">
            <h2>Weather Overview</h2>
            ${plan.weatherLocation ? `<p class="weather-location">📍 Forecast for ${plan.weatherLocation}</p>` : ""}
            ${
              plan.weather && plan.weather.length
                ? `<div class="weather-box">
                    ${plan.weather.map((day, i) => `
                      <div>
                        <h3>Day ${i + 1}</h3>
                        <p>${day.emoji} ${day.condition}</p>
                        <p class="weather-temp">${day.tempMin}° – ${day.tempMax}°</p>
                        <p class="weather-rain">🌧 ${day.precipitationChance}% rain</p>
                      </div>
                    `).join("")}
                  </div>`
                : `<div class="error-box">🌦️ Real-time weather isn't available for "${plan.destination}"${plan.weatherUnavailableReason === "location-not-found" ? " — we couldn't find that exact location" : plan.weatherUnavailableReason === "forecast-unavailable" && plan.numberOfDays > 16 ? " this far in advance (forecasts only cover the next 16 days)" : " right now"}. Please try again closer to your travel dates.</div>`
            }
          </section>

          <section id="budget-section">
            <h2>Budget Breakdown</h2>
            <div class="chart-container">
              <canvas id="budgetChart"></canvas>
            </div>
          </section>

        </div>
        <div class="right-panel">
          <div class="side-card">
            <h3>Hotels</h3>
            <p>${plan.hotels.map(h => `${h.name} — ${plan.currency} ${h.pricePerNight}/night`).join("<br>")}</p>
          </div>
          <div class="side-card">
            <h3>Transportation</h3>
            <p>${plan.transportation.join("<br>")}</p>
          </div>
          <div class="side-card">
            <h3>Activities</h3>
            <p>${plan.activities.join("<br>")}</p>
          </div>
          <div class="side-card">
            <h3>Save This Trip</h3>
            <p>Save this plan to your account so you can find it later in "My Trips".</p>
            <button
              class="search-btn"
              id="saveTripBtn"
              type="button"
              onclick="saveTripToMyTrips()"
              style="margin-top:12px;"
            >
              💾 Save Trip
            </button>
            <div id="saveTripStatus" style="margin-top:12px; font-size:14px;"></div>
          </div>

        </div>

      </div>

    </div>
  `;

  renderBudgetChart(plan);
}

function renderBudgetChart(plan) {
  const ctx = document.getElementById("budgetChart");
  if (!ctx || typeof Chart === "undefined" || !plan.budgetBreakdown) return;

  const labels = Object.keys(plan.budgetBreakdown);
  const data = Object.values(plan.budgetBreakdown);

  new Chart(ctx, {
    type: "doughnut",
    data: {
      labels,
      datasets: [{
        data,
        backgroundColor: ["#d946ef", "#8b5cf6", "#a855f7", "#f0abfc"]
      }]
    },
    options: {
      plugins: {
        legend: { labels: { color: "white" } }
      }
    }
  });
}

function scrollToSection(id) {
  document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });
}

/**
 * Saves the most recently generated trip plan to the logged-in
 * user's My Trips history. Shows a clear success/error message
 * next to the Save button instead of failing silently.
 *
 * FIXED: was fetching "api/mytrips.php" (a path that doesn't exist
 * unless mytrips.php is placed in an /api/ subfolder). Now fetches
 * "mytrips.php" directly, matching where the file actually lives
 * (same folder as db.php / login.php / my-trips.html).
 */
async function saveTripToMyTrips() {
  const plan = lastGeneratedTrip;
  const statusEl = document.getElementById("saveTripStatus");
  const btnEl = document.getElementById("saveTripBtn");

  if (!plan) {
    if (statusEl) statusEl.innerHTML = `<span style="color:#fca5a5;">⚠️ No trip to save yet — generate a plan first.</span>`;
    return;
  }

  if (btnEl) btnEl.disabled = true;
  if (statusEl) statusEl.innerHTML = `💾 Saving...`;

  try {
    const response = await fetch("mytrips.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(plan) // includes destinationImage, weather, budgetBreakdown, aiItineraryHTML
    });

    if (response.status === 401) {
      if (statusEl) statusEl.innerHTML = `<span style="color:#fca5a5;">🔒 Please log in to save trips. <a href="home.html?login=1&redirect=service_request.html" style="color:#f0abfc;">Log in</a></span>`;
      return;
    }

    const result = await response.json();

    if (result.status !== "success") {
      if (statusEl) statusEl.innerHTML = `<span style="color:#fca5a5;">${result.message || "❌ Could not save trip."}</span>`;
      return;
    }

    if (statusEl) statusEl.innerHTML = `<span style="color:#86efac;">${result.message || "✅ Saved to My Trips!"}</span>`;
    console.log("Trip saved to My Trips with id:", result.id);

  } catch (err) {
    console.error("Failed to save trip to My Trips:", err);
    if (statusEl) statusEl.innerHTML = `<span style="color:#fca5a5;">❌ Could not reach the server. Please try again.</span>`;
  } finally {
    if (btnEl) btnEl.disabled = false;
  }
}
