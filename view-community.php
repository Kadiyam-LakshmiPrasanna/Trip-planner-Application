<?php
session_start();
include "db.php";

if (!isset($_GET['id'])) {
    die("Community not found.");
}

$tripId = (int) $_GET['id'];

$sql = "
    SELECT t.*, u.name AS creator_name
    FROM trips t
    LEFT JOIN users u ON t.creator_id = u.id
    WHERE t.id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $tripId);
$stmt->execute();
$result = $stmt->get_result();

$fromMyTrips = false;
if ($result->num_rows == 0) {
    // Fallback for the separate "My Trips" AI planner feature, unrelated
    // to the community/trips table, kept for backward compatibility.
    $sql = "SELECT * FROM MyTrips WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $tripId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 0) {
        die("Community not found.");
    }
    $fromMyTrips = true;
}

$trip = $result->fetch_assoc();

$trip['trip_name']             = $trip['trip_name'] ?? $trip['destination'] ?? 'Trip';
$trip['destination']           = $trip['destination'] ?? 'Unknown';
$trip['description']           = $trip['description'] ?? '';
$trip['trip_type']             = $trip['trip_type'] ?? 'N/A';
$trip['start_date']            = $trip['start_date'] ?? $trip['travel_date'] ?? 'N/A';
$trip['end_date']              = $trip['end_date'] ?? 'N/A';
$trip['number_of_days']        = $trip['number_of_days'] ?? 'N/A';
$trip['max_members']           = $trip['max_members'] ?? $trip['number_of_people'] ?? 'N/A';
$trip['registration_deadline'] = $trip['registration_deadline'] ?? null;
$trip['estimated_cost']        = $trip['estimated_cost'] ?? $trip['budget'] ?? null;
$trip['age_limit']             = $trip['age_limit'] ?? null;
$trip['privacy']               = $trip['privacy'] ?? 'Everyone';
$trip['creator_name']          = $trip['creator_name'] ?? $trip['user_name'] ?? 'Creator';
$trip['status']                = $trip['status'] ?? 'Active';
$trip['cancel_reason']         = $trip['cancel_reason'] ?? null;
$trip['cancel_reason_other']   = $trip['cancel_reason_other'] ?? null;

/* User Login Check */
$loggedIn = isset($_SESSION['user']);
$sessionUserId = $loggedIn ? (int) $_SESSION['user'] : 0;
$isCreator = !$fromMyTrips && $loggedIn && $sessionUserId === (int) $trip['creator_id'];

/* Joined Check + current member count */
$joined = false;
$memberCount = 0;

if (!$fromMyTrips) {
    $countQuery = $conn->prepare("SELECT COUNT(*) AS cnt FROM trip_members WHERE trip_id = ?");
    $countQuery->bind_param("i", $tripId);
    $countQuery->execute();
    $memberCount = (int) $countQuery->get_result()->fetch_assoc()['cnt'];

    if ($loggedIn) {
        $userId = (int) $_SESSION['user'];
        $check = $conn->prepare("SELECT id FROM trip_members WHERE trip_id = ? AND user_id = ?");
        $check->bind_param("ii", $tripId, $userId);
        $check->execute();
        $joined = $check->get_result()->num_rows > 0;
    }
}

$members = [];
if (!$fromMyTrips) {
    $memberQuery = $conn->prepare("
        SELECT u.id, u.name, u.email
        FROM trip_members tm
        JOIN users u ON tm.user_id = u.id
        WHERE tm.trip_id = ?
        ORDER BY tm.joined_date ASC
    ");
    $memberQuery->bind_param("i", $tripId);
    $memberQuery->execute();
    $memberResult = $memberQuery->get_result();

    while ($member = $memberResult->fetch_assoc()) {
        $members[] = $member;
    }
}

function formatDateDisplay($value) {
    if (empty($value) || $value === 'N/A' || $value === '0000-00-00') {
        return 'N/A';
    }
    return htmlspecialchars($value);
}
?>

<!DOCTYPE html>

<html>

<head>

<title>Community Details</title>

<link rel="stylesheet" href="css/site-alert.css">

<style>
body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: linear-gradient(135deg, #f7f2ff, #f3ebff);
    margin: 0;
    color: #1f2937;
}

.header {
    min-height: 120px;
    background: linear-gradient(135deg, #7c3aed, #a855f7);
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    color: white;
    font-size: 24px;
    font-weight: 700;
    text-align: center;
    padding: 12px 16px;
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
}

.header::after {
    content: "Travel together, explore more";
    font-size: 12px;
    font-weight: 400;
    margin-top: 4px;
    opacity: 0.95;
}

.container {
    width: 80%;
    max-width: 860px;
    margin: 30px auto 50px;
}

.card {
    background: white;
    padding: 30px;
    border-radius: 18px;
    box-shadow: 0 12px 35px rgba(15, 23, 42, 0.08);
}

.card h1 {
    margin-top: 0;
    color: #0f172a;
}

.card p {
    color: #475569;
    line-height: 1.7;
}

table {
    width: 100%;
    margin: 24px 0;
    border-collapse: collapse;
}

td {
    padding: 12px 10px;
    border-bottom: 1px solid #e2e8f0;
    color: #334155;
}

td b {
    color: #0f172a;
}

.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 999px;
    background: #ede9fe;
    color: #6d28d9;
    font-weight: 600;
    font-size: 13px;
}

.button-row {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
}

button, .btn-link {
    padding: 14px 24px;
    font-size: 16px;
    border: none;
    border-radius: 999px;
    cursor: pointer;
    margin-top: 24px;
    font-weight: 600;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    text-decoration: none;
    display: inline-block;
    text-align: center;
}

button:hover, .btn-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 18px rgba(0, 0, 0, 0.12);
}

.join {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    color: white;
}

.chat {
    background: linear-gradient(135deg, #a855f7, #9333ea);
    color: white;
}

.back {
    background: #f3f4f6;
    color: #1f2937;
}

.members-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-top: 20px;
}

.member-card {
    background: linear-gradient(135deg, #faf5ff, #f3e8ff);
    border: 1px solid #e9d5ff;
    border-radius: 14px;
    padding: 16px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
}

.member-name {
    font-weight: 700;
    margin-bottom: 6px;
    color: #0f172a;
}

.member-email {
    color: #64748b;
    font-size: 14px;
    word-break: break-all;
}

@media (max-width: 768px) {
    .header {
        font-size: 28px;
        min-height: 220px;
    }

    .card {
        padding: 20px;
    }
}

.end-trip {
    background: linear-gradient(135deg, #f87171, #ef4444);
    color: white;
}

.cancelled-badge {
    display: inline-block;
    margin-top: 10px;
    padding: 6px 16px;
    border-radius: 999px;
    background: #fee2e2;
    color: #b91c1c;
    font-weight: 700;
    font-size: 13px;
}

.cancelled-reason-box {
    margin-top: 16px;
    padding: 14px 18px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 12px;
    color: #7f1d1d;
    font-size: 14px;
}

.member-card {
    position: relative;
}

.member-menu-wrap {
    position: absolute;
    top: 10px;
    right: 10px;
}

.member-menu-btn {
    background: transparent;
    border: none;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    color: #6b21a8;
    padding: 4px 8px;
    border-radius: 8px;
    margin: 0;
}

.member-menu-btn:hover {
    background: rgba(124, 58, 237, 0.12);
    transform: none;
    box-shadow: none;
}

.member-menu {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    background: white;
    border-radius: 12px;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
    min-width: 170px;
    padding: 6px;
    z-index: 50;
}

.member-menu.show {
    display: block;
}

.member-menu button {
    width: 100%;
    text-align: left;
    background: transparent;
    border: none;
    padding: 10px 12px;
    margin: 0;
    border-radius: 8px;
    color: #dc2626;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    box-shadow: none;
}

.member-menu button:hover {
    background: rgba(220, 38, 38, 0.1);
    transform: none;
    box-shadow: none;
}
</style>

</head>

<body>

<div class="header">

<?php echo htmlspecialchars($trip['trip_name']); ?>

Community

</div>

<div class="container">

<div class="card">

<h1>

<?php echo htmlspecialchars($trip['trip_name']); ?>

</h1>

<?php if ($trip['status'] === 'Cancelled'): ?>
    <span class="cancelled-badge">🚫 Trip Cancelled</span>
    <div class="cancelled-reason-box">
        <b>Reason:</b> <?php echo htmlspecialchars($trip['cancel_reason'] ?? 'Not specified'); ?>
        <?php if (!empty($trip['cancel_reason_other'])): ?>
            <br><?php echo nl2br(htmlspecialchars($trip['cancel_reason_other'])); ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<p>

Welcome to the travel community for this trip.
Meet people travelling to the same destination,
share plans and enjoy your journey together.

</p>

<?php if (!empty($trip['description'])): ?>
<p><?php echo nl2br(htmlspecialchars($trip['description'])); ?></p>
<?php endif; ?>

<table>

<tr>
<td><b>Destination</b></td>
<td><?php echo htmlspecialchars($trip['destination']); ?></td>
</tr>

<tr>
<td><b>Trip Type</b></td>
<td><?php echo htmlspecialchars($trip['trip_type']); ?></td>
</tr>

<tr>
<td><b>Start Date</b></td>
<td><?php echo formatDateDisplay($trip['start_date']); ?></td>
</tr>

<tr>
<td><b>End Date</b></td>
<td><?php echo formatDateDisplay($trip['end_date']); ?></td>
</tr>

<tr>
<td><b>Number of Days</b></td>
<td><?php echo htmlspecialchars((string)$trip['number_of_days']); ?></td>
</tr>

<tr>
<td><b>Maximum Members</b></td>
<td>
    <?php echo htmlspecialchars((string)$trip['max_members']); ?>
    <?php if (!$fromMyTrips): ?>
        (<?php echo (int)$memberCount; ?> joined)
    <?php endif; ?>
</td>
</tr>

<tr>
<td><b>Registration Deadline</b></td>
<td><?php echo $trip['registration_deadline'] ? formatDateDisplay($trip['registration_deadline']) : 'No deadline'; ?></td>
</tr>

<tr>
<td><b>Estimated Cost</b></td>
<td><?php echo $trip['estimated_cost'] !== null ? htmlspecialchars((string)$trip['estimated_cost']) : 'N/A'; ?></td>
</tr>

<tr>
<td><b>Age Limit</b></td>
<td><?php echo $trip['age_limit'] !== null ? htmlspecialchars((string)$trip['age_limit']) . '+' : 'N/A'; ?></td>
</tr>

<tr>
<td><b>Who Can Join</b></td>
<td><span class="badge"><?php echo htmlspecialchars($trip['privacy']); ?></span></td>
</tr>

<tr>
<td><b>Created By</b></td>
<td><?php echo htmlspecialchars($trip['creator_name']); ?></td>
</tr>

</table>

<div class="button-row">

<?php if (!$fromMyTrips && $trip['status'] !== 'Cancelled'): ?>

    <?php if (!$loggedIn): ?>

        <button type="button" class="join" onclick="window.location='home.html?login=1&redirect=<?php echo urlencode('view-community.php?id=' . $tripId); ?>'">
            Join Community
        </button>

    <?php elseif (!$joined): ?>

        <button type="button" class="join" onclick="window.location='join-community.php?id=<?php echo $tripId; ?>'">
            Join Community
        </button>

    <?php else: ?>

        <button type="button" class="chat" onclick="window.location.href='community-chat.php?id=<?php echo (int)$tripId; ?>'">
            Chat With People In This Community
        </button>

    <?php endif; ?>

<?php endif; ?>

<?php if ($isCreator && $trip['status'] !== 'Cancelled'): ?>
    <button type="button" class="end-trip" id="endTripBtn">
        End Trip
    </button>
<?php endif; ?>

<a class="btn-link back btn-back-home" href="home.html"><span class="bth-icon">🏠</span> Back to Home</a>

</div>

<h2>Community Members</h2>
<div class="members-grid">
<?php if (count($members) > 0): ?>
    <?php foreach ($members as $member): ?>
        <div class="member-card">
            <?php if ($loggedIn && (int) $member['id'] === $sessionUserId): ?>
                <div class="member-menu-wrap">
                    <button type="button" class="member-menu-btn" onclick="toggleMemberMenu(event)" aria-label="Member options" aria-haspopup="true">⋮</button>
                    <div class="member-menu">
                        <button type="button" onclick="requestExitCommunity(<?php echo (int)$tripId; ?>)">Exit Community</button>
                    </div>
                </div>
            <?php endif; ?>
            <div class="member-name"><?php echo htmlspecialchars($member['name']); ?></div>
            <div class="member-email"><?php echo htmlspecialchars($member['email']); ?></div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <p>No members have joined this community yet.</p>
<?php endif; ?>
</div>

</div>

</div>

<!-- End Trip reason modal -->
<div class="site-modal-overlay" id="endTripModal">
    <div class="site-modal">
        <h3>End Trip</h3>
        <p>Why are you ending this trip?</p>

        <div class="site-modal-reasons" id="endTripReasons">
            <label class="site-modal-reason">
                <input type="radio" name="endTripReason" value="Trip dates no longer work">
                Trip dates no longer work
            </label>
            <label class="site-modal-reason">
                <input type="radio" name="endTripReason" value="Not enough travellers joined">
                Not enough travellers joined
            </label>
            <label class="site-modal-reason">
                <input type="radio" name="endTripReason" value="Personal emergency">
                Personal emergency
            </label>
            <label class="site-modal-reason">
                <input type="radio" name="endTripReason" value="Destination is no longer available">
                Destination is no longer available
            </label>
            <label class="site-modal-reason">
                <input type="radio" name="endTripReason" value="Plans changed">
                Plans changed
            </label>
            <label class="site-modal-reason">
                <input type="radio" name="endTripReason" value="Other">
                Other
            </label>
            <textarea class="site-modal-other-text" id="endTripOtherText" placeholder="Tell us more (optional)" style="display:none;"></textarea>
        </div>

        <div class="site-modal-actions">
            <button type="button" class="site-modal-btn site-modal-btn-cancel" id="endTripCancelBtn">Cancel</button>
            <button type="button" class="site-modal-btn site-modal-btn-danger" id="endTripConfirmBtn">End Trip</button>
        </div>
    </div>
</div>

<script src="site-alert.js"></script>
<script>
<?php
// Alert messages passed back from join-community.php
$status = $_GET['status'] ?? null;
$msg = $_GET['msg'] ?? null;
if ($status && $msg):
?>
document.addEventListener("DOMContentLoaded", function () {
    showToast(<?php echo json_encode($msg); ?>, <?php echo json_encode($status === 'success' ? 'success' : 'error'); ?>);
});
<?php endif; ?>

/* ================= Member ⋮ menu ================= */
function toggleMemberMenu(event) {
    event.stopPropagation();
    const menu = event.currentTarget.nextElementSibling;
    document.querySelectorAll(".member-menu.show").forEach(m => {
        if (m !== menu) m.classList.remove("show");
    });
    menu.classList.toggle("show");
}

document.addEventListener("click", function () {
    document.querySelectorAll(".member-menu.show").forEach(m => m.classList.remove("show"));
});

/* ================= Exit Community ================= */
async function requestExitCommunity(tripId) {
    document.querySelectorAll(".member-menu.show").forEach(m => m.classList.remove("show"));

    const confirmed = await showConfirm({
        title: "Exit Community",
        message: "Are you sure you want to exit this community?",
        confirmLabel: "Exit Community",
        cancelLabel: "Cancel",
        danger: true
    });

    if (!confirmed) return;

    try {
        const response = await fetch("exit_community.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ trip_id: tripId })
        });
        const result = await response.json();

        if (result.status === "success") {
            showToast(result.message || "👋 You have exited this community.", "success");
            setTimeout(() => window.location.reload(), 900);
        } else {
            showToast(result.message || "❌ Unable to exit this community. Please try again.", "error");
        }
    } catch (err) {
        console.error("Exit community failed:", err);
        showToast("❌ Something went wrong. Please try again.", "error");
    }
}

/* ================= End Trip ================= */
<?php if ($isCreator && $trip['status'] !== 'Cancelled'): ?>
(function () {
    const openBtn = document.getElementById("endTripBtn");
    const modal = document.getElementById("endTripModal");
    const cancelBtn = document.getElementById("endTripCancelBtn");
    const confirmBtn = document.getElementById("endTripConfirmBtn");
    const otherText = document.getElementById("endTripOtherText");
    let submitting = false;

    openBtn.addEventListener("click", () => {
        modal.classList.add("active");
    });

    cancelBtn.addEventListener("click", () => {
        modal.classList.remove("active");
    });

    modal.addEventListener("click", (e) => {
        if (e.target === modal) modal.classList.remove("active");
    });

    document.querySelectorAll('input[name="endTripReason"]').forEach(radio => {
        radio.addEventListener("change", () => {
            otherText.style.display = radio.value === "Other" && radio.checked ? "block" : otherText.style.display;
            if (radio.checked && radio.value !== "Other") {
                otherText.style.display = "none";
            }
        });
    });

    confirmBtn.addEventListener("click", async () => {
        if (submitting) return;

        const selected = document.querySelector('input[name="endTripReason"]:checked');
        if (!selected) {
            showToast("⚠️ Please select a cancellation reason.", "warning");
            return;
        }

        submitting = true;
        confirmBtn.disabled = true;
        cancelBtn.disabled = true;

        try {
            const response = await fetch("cancel_trip.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    trip_id: <?php echo (int) $tripId; ?>,
                    reason: selected.value,
                    other_text: otherText.value.trim()
                })
            });
            const result = await response.json();

            if (result.status === "success") {
                showToast(result.message || "🎉 TRIP Cancelled successfully!!!", "success");
                modal.classList.remove("active");
                setTimeout(() => window.location.reload(), 900);
            } else {
                showToast(result.message || "❌ Unable to cancel the trip. Please try again.", "error");
                submitting = false;
                confirmBtn.disabled = false;
                cancelBtn.disabled = false;
            }
        } catch (err) {
            console.error("Cancel trip failed:", err);
            showToast("❌ Unable to cancel the trip. Please try again.", "error");
            submitting = false;
            confirmBtn.disabled = false;
            cancelBtn.disabled = false;
        }
    });
})();
<?php endif; ?>
</script>

</body>

</html>
