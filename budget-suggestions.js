/* =========================================================
   budget-suggestions.js
   Powers the "Tell us your travel budget" section on the
   homepage. Given the user's exact current location and
   budget, asks the AI for destinations that are realistically
   achievable within that budget — with estimated cost, trip
   duration, and key cost factors for each — and falls back to
   cheaper, closer alternatives when the budget is too small
   for typical picks.

   Reuses GROQ_API_KEY / the Groq chat-completions endpoint
   already set up in service_request.js (must load first).
   ========================================================= */

async function findDestinationsForBudget() {
  const location   = document.getElementById("suggestLocation")?.value.trim();
  const budget     = parseFloat(document.getElementById("suggestBudget")?.value);
  const travelers  = document.getElementById("travelers")?.value || "";
  const tripStyle  = document.getElementById("trip-style")?.value || "";
  const resultsEl  = document.getElementById("budgetSuggestions");

  if (!resultsEl) return;

  if (!location) {
    resultsEl.innerHTML = `<div class="suggestion-error">📍 Please enter your current location.</div>`;
    return;
  }
  if (!budget || budget <= 0) {
    resultsEl.innerHTML = `<div class="suggestion-error">⚠️ Please enter a valid budget.</div>`;
    return;
  }

  resultsEl.innerHTML = `<div class="suggestion-loading">✨ Finding destinations that fit your budget from ${escapeHtmlBS(location)}...</div>`;

  try {
    const suggestions = await fetchBudgetDestinationSuggestions({ location, budget, travelers, tripStyle });
    renderBudgetSuggestions(suggestions, { location, budget });
  } catch (err) {
    console.error("Budget suggestion generation failed:", err);
    resultsEl.innerHTML = `<div class="suggestion-error">❌ ${err.message && err.message !== "Unexpected response shape" ? err.message : "Couldn't generate destination suggestions right now. Please try again."}</div>`;
  }
}

async function fetchBudgetDestinationSuggestions({ location, budget, travelers, tripStyle }) {
  const prompt = `
You are a travel-budgeting assistant. A traveler is starting from exactly: "${location}".
Their total trip budget is: ${budget} (currency unspecified — treat it as the local currency of their starting location unless the number clearly implies otherwise).
${travelers && travelers !== "Select No of Travelers" ? `Number of travelers: ${travelers}.` : ""}
${tripStyle && tripStyle !== "Select Trip Style" ? `Preferred trip style: ${tripStyle}.` : ""}

Suggest 4 to 6 REAL, achievable travel destinations for this exact budget, starting from this exact location.
Only include destinations whose typical total trip cost (round-trip transport + stay + food + local travel for a reasonable short trip) realistically fits within the given budget for the stated number of travelers.
Prefer destinations that make geographic and cost sense given the starting location (nearer/cheaper destinations for smaller budgets, more distant ones only if the budget genuinely supports it).
If the budget is too low for popular or far-away destinations, DO NOT include those — instead fill the list with affordable destinations closer to the starting location.

Respond with ONLY raw JSON (no markdown, no code fences, no explanation) matching exactly this shape:
[
  {
    "destination": "City, Region/Country",
    "estimatedCost": <number, total cost in the same currency as the budget>,
    "tripDurationDays": <integer>,
    "distanceCategory": "nearby" | "regional" | "far",
    "costFactors": ["short phrase", "short phrase", "short phrase"],
    "whyThisFits": "one short sentence"
  }
]
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
      temperature: 0.5
    })
  });

  const data = await response.json();

  if (data?.error) {
    console.error("Groq API error:", data.error);
    throw new Error(data.error.message || "AI suggestion service returned an error.");
  }

  const raw = data?.choices?.[0]?.message?.content || "";

  // Defensive parsing: strip any accidental markdown fences, then
  // pull out the first [...] block in case the model added stray text.
  const cleaned = raw.replace(/```json|```/g, "").trim();
  const match = cleaned.match(/\[[\s\S]*\]/);
  const jsonText = match ? match[0] : cleaned;

  const parsed = JSON.parse(jsonText);
  if (!Array.isArray(parsed)) throw new Error("Unexpected response shape");
  return parsed;
}

function renderBudgetSuggestions(suggestions, { location, budget }) {
  const resultsEl = document.getElementById("budgetSuggestions");
  const currencySymbol = (typeof selectedCurrency !== "undefined" && selectedCurrency === "INR") ? "₹" : "$";

  if (!suggestions || suggestions.length === 0) {
    resultsEl.innerHTML = `<div class="suggestion-error">😕 No destinations found that fit ${currencySymbol}${budget.toLocaleString()} from ${escapeHtmlBS(location)}. Try increasing your budget.</div>`;
    return;
  }

  resultsEl.innerHTML = `
    <p class="suggestion-intro">✅ Destinations that fit your budget of <strong>${currencySymbol}${budget.toLocaleString()}</strong> from <strong>${escapeHtmlBS(location)}</strong>:</p>
    <div class="suggestion-grid">
      ${suggestions.map(s => `
        <div class="suggestion-card">
          <h4>${escapeHtmlBS(s.destination || "Destination")}</h4>
          <span class="suggestion-tag suggestion-tag-${(s.distanceCategory || "regional").toLowerCase()}">${escapeHtmlBS(s.distanceCategory || "regional")}</span>
          <p class="suggestion-cost">💰 Est. cost: ${currencySymbol}${Number(s.estimatedCost || 0).toLocaleString()}</p>
          <p class="suggestion-duration">🗓 ${s.tripDurationDays || "—"} day trip</p>
          ${Array.isArray(s.costFactors) && s.costFactors.length ? `
            <ul class="suggestion-factors">
              ${s.costFactors.map(f => `<li>${escapeHtmlBS(f)}</li>`).join("")}
            </ul>
          ` : ""}
          ${s.whyThisFits ? `<p class="suggestion-why">${escapeHtmlBS(s.whyThisFits)}</p>` : ""}
          <button type="button" class="suggestion-plan-btn" onclick="planSuggestedTrip('${encodeURIComponent(s.destination || "")}', '${encodeURIComponent(location)}', ${Number(s.tripDurationDays) || 5}, ${Number(s.estimatedCost) || budget})">
            ✨ Plan This Trip
          </button>
        </div>
      `).join("")}
    </div>
  `;
}

// Pre-fills the main "AI Powered Trip Planner" form above with the
// chosen suggestion and scrolls the user to it, so they can review
// and click "Generate Trip Plan" themselves.
function planSuggestedTrip(destinationEncoded, locationEncoded, days, cost) {
  const destination = decodeURIComponent(destinationEncoded);
  const location = decodeURIComponent(locationEncoded);

  const destInput = document.getElementById("destination");
  const locInput  = document.getElementById("currentLocation");
  const daysInput = document.getElementById("days");
  const budgetInput = document.getElementById("budget");

  if (destInput) destInput.value = destination;
  if (locInput) locInput.value = location;
  if (daysInput) daysInput.value = days;
  if (budgetInput) budgetInput.value = cost;

  document.querySelector(".planner-section")?.scrollIntoView({ behavior: "smooth", block: "start" });
}

function escapeHtmlBS(str) {
  const div = document.createElement("div");
  div.textContent = str == null ? "" : String(str);
  return div.innerHTML;
}
