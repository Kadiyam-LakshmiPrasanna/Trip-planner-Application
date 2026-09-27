// ================= CREATE TRIP PAGE LOGIC =================

const form = document.getElementById("createTripForm");
const submitBtn = document.getElementById("submitBtn");
const loginNotice = document.getElementById("loginNotice");
const createdByField = document.getElementById("createdBy");

const startDateEl = document.getElementById("startDate");
const endDateEl = document.getElementById("endDate");
const numberOfDaysEl = document.getElementById("numberOfDays");

let currentUser = null; // populated from whoami.php, display-only

// ---------- Toast notification (more reliable than alert(), which
// browsers like Chrome will silently suppress after a page triggers
// several dialogs in a row during testing) ----------
const toastEl = document.getElementById("ctToast");
let toastTimer = null;

function showToast(message, type) {
    if (!toastEl) {
        // Fallback if the toast element is somehow missing.
        alert(message);
        return;
    }
    toastEl.textContent = message;
    toastEl.className = "ct-toast show " + (type === "error" ? "ct-toast-error" : "ct-toast-success");

    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastEl.classList.remove("show");
    }, 3500);
}

// ---------- Session check (Created By is display-only here; the
// backend independently re-derives the real authenticated user) ----------
async function loadCurrentUser() {
    try {
        const response = await fetch("whoami.php");
        const data = await response.json();

        if (data.loggedIn) {
            currentUser = data;
            createdByField.value = data.name;
            submitBtn.disabled = false;
        } else {
            createdByField.value = "Not logged in";
            loginNotice.classList.add("show");
            submitBtn.disabled = true;
        }
    } catch (err) {
        console.error("Failed to check session:", err);
        createdByField.value = "Unable to verify login";
        loginNotice.classList.add("show");
        submitBtn.disabled = true;
    }
}

// ---------- Auto-calculate Number of Days ----------
function recalculateDays() {
    const start = startDateEl.value;
    const end = endDateEl.value;

    if (!start || !end) {
        numberOfDaysEl.value = "";
        return null;
    }

    const startDate = new Date(start + "T00:00:00");
    const endDate = new Date(end + "T00:00:00");
    const diffMs = endDate - startDate;
    const diffDays = Math.round(diffMs / (1000 * 60 * 60 * 24)) + 1; // inclusive of both start and end day

    if (diffDays <= 0) {
        numberOfDaysEl.value = "";
        return null;
    }

    numberOfDaysEl.value = diffDays + (diffDays === 1 ? " day" : " days");
    return diffDays;
}

startDateEl.addEventListener("change", recalculateDays);
endDateEl.addEventListener("change", recalculateDays);

// ---------- Helpers ----------
function setError(fieldId, message) {
    const el = document.getElementById("err-" + fieldId);
    if (el) el.textContent = message || "";
}

function clearAllErrors() {
    document.querySelectorAll(".ct-error-text").forEach(el => (el.textContent = ""));
}

// ---------- Validation ----------
function validateForm() {
    clearAllErrors();
    let isValid = true;

    const tripName = document.getElementById("tripName").value.trim();
    const destination = document.getElementById("destination").value.trim();
    const tripType = document.getElementById("tripType").value;
    const startDate = startDateEl.value;
    const endDate = endDateEl.value;
    const maxMembers = document.getElementById("maxMembers").value;
    const registrationDeadline = document.getElementById("registrationDeadline").value;
    const estimatedCost = document.getElementById("estimatedCost").value;
    const ageLimit = document.getElementById("ageLimit").value;
    const whoCanJoin = document.getElementById("whoCanJoin").value;

    if (tripName === "") {
        setError("tripName", "Trip name is required.");
        isValid = false;
    }

    if (destination === "") {
        setError("destination", "Destination is required.");
        isValid = false;
    }

    if (tripType === "") {
        setError("tripType", "Please select a trip type.");
        isValid = false;
    }

    if (startDate === "") {
        setError("startDate", "Start date is required.");
        isValid = false;
    }

    if (endDate === "") {
        setError("endDate", "End date is required.");
        isValid = false;
    }

    let numberOfDays = null;
    if (startDate && endDate) {
        numberOfDays = recalculateDays();
        if (numberOfDays === null) {
            setError("endDate", "End date cannot be earlier than start date.");
            isValid = false;
        }
    }

    if (maxMembers === "" || !Number.isInteger(Number(maxMembers)) || Number(maxMembers) < 1) {
        setError("maxMembers", "Enter a valid positive number of members.");
        isValid = false;
    }

    if (registrationDeadline && startDate) {
        if (new Date(registrationDeadline + "T00:00:00") > new Date(startDate + "T00:00:00")) {
            setError("registrationDeadline", "Registration deadline cannot be after the start date.");
            isValid = false;
        }
    }

    if (estimatedCost !== "" && (isNaN(Number(estimatedCost)) || Number(estimatedCost) < 0)) {
        setError("estimatedCost", "Estimated cost must be a valid non-negative number.");
        isValid = false;
    }

    if (ageLimit === "" || !Number.isInteger(Number(ageLimit)) || Number(ageLimit) < 0 || Number(ageLimit) > 120) {
        setError("ageLimit", "Enter a valid age limit.");
        isValid = false;
    }

    if (whoCanJoin === "") {
        setError("whoCanJoin", "Please select who can join.");
        isValid = false;
    }

    return { isValid, numberOfDays };
}

// ---------- Submit ----------
form.addEventListener("submit", async function (e) {
    e.preventDefault();

    if (!currentUser) {
        showToast("Please log in before creating a trip.", "error");
        return;
    }

    const { isValid, numberOfDays } = validateForm();
    if (!isValid) {
        return;
    }

    const payload = {
        trip_name: document.getElementById("tripName").value.trim(),
        destination: document.getElementById("destination").value.trim(),
        description: document.getElementById("description").value.trim(),
        trip_type: document.getElementById("tripType").value,
        start_date: startDateEl.value,
        end_date: endDateEl.value,
        number_of_days: numberOfDays,
        max_members: Number(document.getElementById("maxMembers").value),
        registration_deadline: document.getElementById("registrationDeadline").value || null,
        estimated_cost: document.getElementById("estimatedCost").value === "" ? null : Number(document.getElementById("estimatedCost").value),
        age_limit: Number(document.getElementById("ageLimit").value),
        who_can_join: document.getElementById("whoCanJoin").value
        // NOTE: "created by" is intentionally NOT sent here — the server
        // determines the real creator from the authenticated session.
    };

    submitBtn.disabled = true;
    submitBtn.textContent = "Creating...";

    try {
        const response = await fetch("create_trip.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        });

        // Read as text first so a non-JSON error page (e.g. a PHP fatal
        // error) doesn't just silently fail — we can see it in console.
        const rawText = await response.text();
        let result;
        try {
            result = JSON.parse(rawText);
        } catch (parseErr) {
            console.error("Server did not return valid JSON:", rawText);
            showToast("The server returned an unexpected response. Check the browser console / PHP error log.", "error");
            return;
        }

        if (result.status === "success") {
            showToast(result.message || "Trip created successfully!", "success");
            setTimeout(() => {
                window.location.href = "home.html";
            }, 1200);
        } else {
            showToast(result.message || "Failed to create trip. Please try again.", "error");
        }
    } catch (err) {
        console.error("Create trip request failed:", err);
        showToast("Something went wrong while creating your trip. Please try again.", "error");
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = "Create Trip";
    }
});

document.addEventListener("DOMContentLoaded", loadCurrentUser);
