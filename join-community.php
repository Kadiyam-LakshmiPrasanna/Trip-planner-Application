<?php
session_start();
include "db.php";

if (!isset($_GET['id'])) {
    die("Community not found.");
}

$tripId = (int) $_GET['id'];

function redirectWithMessage($tripId, $status, $message) {
    header("Location: view-community.php?id=" . $tripId . "&status=" . urlencode($status) . "&msg=" . urlencode($message));
    exit();
}

// ---- Step 1: Authentication check ----
// Never trust a client-submitted identity — the logged-in session is the
// only source of truth for who is attempting to join.
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header("Location: home.html?login=1&redirect=" . urlencode("view-community.php?id=" . $tripId));
    exit();
}

$userId = (int) $_SESSION['user'];

// ---- Step 2: Retrieve trusted user profile info (never from the client) ----
$userStmt = $conn->prepare("SELECT id, name, age, gender FROM users WHERE id = ?");
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$userResult = $userStmt->get_result();

if ($userResult->num_rows === 0) {
    redirectWithMessage($tripId, "error", "❌ Your account could not be found. Please log in again.");
}

$user = $userResult->fetch_assoc();
$userStmt->close();

// ---- Load the trip/community ----
$tripStmt = $conn->prepare("SELECT * FROM trips WHERE id = ?");
$tripStmt->bind_param("i", $tripId);
$tripStmt->execute();
$tripResult = $tripStmt->get_result();

if ($tripResult->num_rows === 0) {
    die("Community not found.");
}

$trip = $tripResult->fetch_assoc();
$tripStmt->close();

// ---- Step 7 (checked early to short-circuit cleanly): Duplicate membership ----
$dupCheck = $conn->prepare("SELECT id FROM trip_members WHERE trip_id = ? AND user_id = ?");
$dupCheck->bind_param("ii", $tripId, $userId);
$dupCheck->execute();
if ($dupCheck->get_result()->num_rows > 0) {
    redirectWithMessage($tripId, "error", "⚠️ You have already joined this community.");
}
$dupCheck->close();

// ---- Step 3: Age Limit check ----
if ($trip['age_limit'] !== null && $trip['age_limit'] !== '') {
    $ageLimit = (int) $trip['age_limit'];
    $userAge = $user['age'] !== null ? (int) $user['age'] : null;

    if ($userAge === null || $userAge < $ageLimit) {
        redirectWithMessage($tripId, "error", "⚠️ You do not meet the minimum age requirement for this community.");
    }
}

// ---- Step 4: Who Can Join (gender) check ----
$whoCanJoin = $trip['privacy'] ?? 'Everyone';
$userGender = $user['gender'];

if ($whoCanJoin === 'Women' && $userGender !== 'Women') {
    redirectWithMessage($tripId, "error", "⚠️ You are not eligible to join this community because of the community's membership restriction.");
}

if ($whoCanJoin === 'Men' && $userGender !== 'Men') {
    redirectWithMessage($tripId, "error", "⚠️ You are not eligible to join this community because of the community's membership restriction.");
}
// "Everyone" imposes no gender restriction.

// ---- Step 5: Registration Deadline check ----
if (!empty($trip['registration_deadline'])) {
    $deadline = DateTime::createFromFormat("Y-m-d", $trip['registration_deadline']);
    $today = new DateTime("today");

    if ($deadline && $today > $deadline) {
        redirectWithMessage($tripId, "error", "⚠️ The registration deadline for this community has passed.");
    }
}

// ---- Step 6: Maximum Members check ----
$countStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM trip_members WHERE trip_id = ?");
$countStmt->bind_param("i", $tripId);
$countStmt->execute();
$currentMembers = (int) $countStmt->get_result()->fetch_assoc()['cnt'];
$countStmt->close();

if (!empty($trip['max_members']) && $currentMembers >= (int) $trip['max_members']) {
    redirectWithMessage($tripId, "error", "⚠️ This community has reached its maximum member limit.");
}

// ---- Step 8: Add the user to the community ----
$insert = $conn->prepare("INSERT INTO trip_members (trip_id, user_id, status, joined_date) VALUES (?, ?, 'joined', NOW())");
$insert->bind_param("ii", $tripId, $userId);

if (!$insert->execute()) {
    // Race condition safety net: the UNIQUE(trip_id, user_id) key may
    // reject a concurrent duplicate insert.
    redirectWithMessage($tripId, "error", "⚠️ You have already joined this community.");
}
$insert->close();

redirectWithMessage($tripId, "success", "🎉 " . $user['name'] . "! Joined the community successfully.");
