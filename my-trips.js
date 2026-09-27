/* =========================================================
   my-trips.js
   Fetches the logged-in user's saved trips from
   /api/mytrips.php and renders them as cards.
   ========================================================= */

const API_BASE = "mytrips.php";

let cachedTrips = [];

document.addEventListener("DOMContentLoaded", loadMyTrips);

async function loadMyTrips() {
  const loadingEl   = document.getElementById("loadingState");
  const authEl      = document.getElementById("authRequired");
  const authTextEl  = document.getElementById("authRequiredText");
  const authLinkEl  = document.getElementById("authRequiredLink");
  const emptyEl     = document.getElementById("emptyState");
  const gridEl      = document.getElementById("tripsGrid");

  loadingEl.classList.remove("hidden");
  authEl.classList.add("hidden");
  emptyEl.classList.add("hidden");
  gridEl.classList.add("hidden");

  try {
    const response = await fetch(API_BASE, {
      method: "GET",
      headers: { "Content-Type": "application/json" }
    });

    if (response.status === 401) {
      loadingEl.classList.add("hidden");
      authTextEl.textContent = "Please log in to view your trips.";
      authLinkEl.classList.remove("hidden");
      authEl.classList.remove("hidden");
      return;
    }

    const result = await response.json();
    loadingEl.classList.add("hidden");

    if (result.status !== "success") {
      authTextEl.textContent = result.message || "Something went wrong loading your trips.";
      authLinkEl.classList.add("hidden");
      authEl.classList.remove("hidden");
      return;
    }

    cachedTrips = result.trips || [];

    if (cachedTrips.length === 0) {
      emptyEl.classList.remove("hidden");
      return;
    }

    renderTrips(cachedTrips);
    gridEl.classList.remove("hidden");

  } catch (err) {
    console.error("Failed to load trips:", err);
    loadingEl.classList.add("hidden");
    authTextEl.textContent = "Could not reach the server. Please try again.";
    authLinkEl.classList.add("hidden");
    authEl.classList.remove("hidden");
  }
}

function renderTrips(trips) {
  const gridEl = document.getElementById("tripsGrid");
  gridEl.innerHTML = "";

  trips.forEach(trip => {
    const card = document.createElement("div");
    card.className = "trip-card";

    card.innerHTML = `
      ${trip.destination_image
        ? `<img class="trip-card-image" src="${trip.destination_image}" alt="${escapeHtml(trip.destination)}">`
        : `<div class="trip-card-image trip-card-image-placeholder"><i class="fa-solid fa-image"></i></div>`
      }
      <h3><i class="fa-solid fa-location-dot"></i> ${escapeHtml(trip.destination)}</h3>

      <div class="trip-meta">
        <span><i class="fa-regular fa-calendar"></i> ${trip.number_of_days} day(s)</span>
        <span><i class="fa-solid fa-users"></i> ${trip.number_of_people || 1} traveler(s)</span>
        ${trip.start_date ? `<span><i class="fa-regular fa-calendar-check"></i> ${formatDate(trip.start_date)} → ${formatDate(trip.end_date)}</span>` : ""}
      </div>

      <div class="trip-meta">
        <span><i class="fa-solid fa-wallet"></i> Budget: ${trip.currency} ${formatMoney(trip.budget)}</span>
      </div>

      <div class="trip-cost">
        Est. Cost: ${trip.currency} ${formatMoney(trip.estimated_cost)}
      </div>

      <div class="trip-meta">
        <span><i class="fa-regular fa-clock"></i> Created ${formatDate(trip.created_at)}</span>
      </div>

      <div class="trip-card-actions">
        <button class="view-btn" onclick="openTripModal(${trip.id})">View Details</button>
        <button class="delete-btn" onclick="deleteTrip(${trip.id})">Delete</button>
      </div>
    `;

    gridEl.appendChild(card);
  });
}

function openTripModal(tripId) {
  const trip = cachedTrips.find(t => Number(t.id) === Number(tripId));
  if (!trip) return;

  const body = document.getElementById("tripModalBody");

  body.innerHTML = `
    ${trip.destination_image
      ? `<img class="modal-hero-image" src="${trip.destination_image}" alt="${escapeHtml(trip.destination)}">`
      : `<div class="modal-hero-image modal-hero-placeholder"><i class="fa-solid fa-image"></i> No verified photo found</div>`
    }

    <h2>${escapeHtml(trip.destination)}</h2>

    <p>
      <strong>Dates:</strong>
      ${trip.start_date ? `${formatDate(trip.start_date)} → ${formatDate(trip.end_date)}` : "Not specified"}
      &nbsp;|&nbsp; <strong>Days:</strong> ${trip.number_of_days}
      &nbsp;|&nbsp; <strong>Travelers:</strong> ${trip.number_of_people || 1}
    </p>

    <p><strong>Budget:</strong> ${trip.currency} ${formatMoney(trip.budget)}</p>
    <p><strong>Estimated Cost:</strong> ${trip.currency} ${formatMoney(trip.estimated_cost)}</p>

    ${trip.ai_itinerary_html
      ? `<h4>Destination & Itinerary</h4><div class="modal-ai-itinerary">${trip.ai_itinerary_html}</div>`
      : `<h4>Itinerary</h4>${renderListOrText(trip.itinerary)}`
    }

    <h4>Weather</h4>
    ${renderWeather(trip.weather_data)}

    <h4>Budget Breakdown</h4>
    ${renderBudgetBreakdown(trip.budget_breakdown)}

    <h4>Hotels</h4>
    ${renderListOrText(trip.hotels)}

    <h4>Transportation</h4>
    ${renderListOrText(trip.transportation)}

    <h4>Activities</h4>
    ${renderListOrText(trip.activities)}
  `;

  document.getElementById("tripModal").classList.add("active");
}

function renderWeather(weatherData) {
  if (!weatherData || !Array.isArray(weatherData) || weatherData.length === 0) {
    return `<p>🌦️ Weather forecast wasn't available when this trip was generated.</p>`;
  }
  return `<div class="modal-weather-grid">
    ${weatherData.map((day, i) => `
      <div class="modal-weather-day">
        <strong>Day ${i + 1}</strong>
        <span>${day.emoji || "🌡"} ${escapeHtml(day.condition || "")}</span>
        <span>${day.tempMin}° – ${day.tempMax}°</span>
        <span>🌧 ${day.precipitationChance}%</span>
      </div>
    `).join("")}
  </div>`;
}

function renderBudgetBreakdown(breakdown) {
  if (!breakdown || typeof breakdown !== "object") {
    return `<p>Not available.</p>`;
  }
  return `<ul>${Object.entries(breakdown).map(([category, amount]) =>
    `<li><strong>${escapeHtml(category)}:</strong> ${formatMoney(amount)}</li>`
  ).join("")}</ul>`;
}

function closeTripModal() {
  document.getElementById("tripModal").classList.remove("active");
}

document.addEventListener("click", function (event) {
  const modal = document.getElementById("tripModal");
  if (event.target === modal) {
    closeTripModal();
  }
});

async function deleteTrip(tripId) {
  const confirmed = window.showConfirm
    ? await window.showConfirm({
        title: "Delete Trip",
        message: "Delete this trip? This cannot be undone.",
        confirmLabel: "Delete",
        cancelLabel: "Cancel",
        danger: true
      })
    : confirm("Delete this trip? This cannot be undone.");

  if (!confirmed) return;

  try {
    const response = await fetch(`${API_BASE}?id=${encodeURIComponent(tripId)}`, {
      method: "DELETE"
    });

    const result = await response.json();

    if (result.status === "success") {
      cachedTrips = cachedTrips.filter(t => Number(t.id) !== Number(tripId));

      if (window.showToast) showToast("🗑️ Trip deleted successfully!", "success");

      if (cachedTrips.length === 0) {
        document.getElementById("tripsGrid").classList.add("hidden");
        document.getElementById("emptyState").classList.remove("hidden");
      } else {
        renderTrips(cachedTrips);
      }
    } else {
      if (window.showToast) {
        showToast("❌ " + (result.message || "Could not delete trip."), "error");
      } else {
        alert(result.message || "Could not delete trip.");
      }
    }

  } catch (err) {
    console.error("Delete failed:", err);
    if (window.showToast) {
      showToast("❌ Something went wrong while deleting the trip.", "error");
    } else {
      alert("Something went wrong while deleting the trip.");
    }
  }
}

/* =========================================
   Helpers
   ========================================= */

function renderListOrText(value) {
  if (!value) return "<p>Not available.</p>";

  if (Array.isArray(value)) {
    return `<ul>${value.map(item => `<li>${escapeHtml(typeof item === "string" ? item : JSON.stringify(item))}</li>`).join("")}</ul>`;
  }

  if (typeof value === "object") {
    return `<ul>${Object.entries(value).map(([k, v]) => `<li><strong>${escapeHtml(k)}:</strong> ${escapeHtml(String(v))}</li>`).join("")}</ul>`;
  }

  return `<p>${escapeHtml(String(value))}</p>`;
}

function formatDate(dateStr) {
  if (!dateStr) return "";
  const d = new Date(dateStr);
  if (isNaN(d)) return dateStr;
  return d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}

function formatMoney(value) {
  const num = Number(value || 0);
  return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function escapeHtml(str) {
  const div = document.createElement("div");
  div.textContent = str;
  return div.innerHTML;
}
