<?php
/**
 * cancel_trip.php
 *
 * POST /cancel_trip.php
 * Body: { trip_id, reason, other_text }
 *
 * Only the trip's creator (the authenticated session user) may cancel
 * their own trip. On success the trip's status is set to 'Cancelled'
 * and it disappears from every "Ongoing" list while remaining visible
 * (with its reason) in "All Created Trips" / records.
 */

session_start();
header('Content-Type: application/json');

include "db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
    exit();
}

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "🔒 Please log in to continue."]);
    exit();
}

$userId = (int) $_SESSION['user'];

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid request data."]);
    exit();
}

$tripId    = isset($data['trip_id']) ? (int) $data['trip_id'] : 0;
$reason    = trim($data['reason'] ?? '');
$otherText = trim($data['other_text'] ?? '');

$allowedReasons = [
    "Trip dates no longer work",
    "Not enough travellers joined",
    "Personal emergency",
    "Destination is no longer available",
    "Plans changed",
    "Other",
];

if ($tripId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "A valid trip is required."]);
    exit();
}

if ($reason === '' || !in_array($reason, $allowedReasons, true)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "⚠️ Please select a cancellation reason."]);
    exit();
}

if ($reason === "Other" && $otherText === '') {
    // "Other" free text is optional per the spec, so this is allowed —
    // but keep the value sane if it was provided.
    $otherText = null;
} elseif ($reason !== "Other") {
    $otherText = null;
}

if ($otherText !== null && mb_strlen($otherText) > 500) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "That explanation is too long (max 500 characters)."]);
    exit();
}

// ---- Load the trip and confirm ownership ----
$stmt = $conn->prepare("SELECT id, creator_id, status FROM trips WHERE id = ?");
$stmt->bind_param("i", $tripId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "❌ Trip not found."]);
    exit();
}

$trip = $result->fetch_assoc();
$stmt->close();

if ((int) $trip['creator_id'] !== $userId) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "❌ Only the trip creator can end this trip."]);
    exit();
}

// ---- Duplicate-cancellation guard: already cancelled, nothing to do ----
if ($trip['status'] === 'Cancelled') {
    echo json_encode(["status" => "success", "message" => "🎉 TRIP Cancelled successfully!!!", "already" => true]);
    exit();
}

// ---- Persist: only report success once the DB write is confirmed ----
$update = $conn->prepare(
    "UPDATE trips
     SET status = 'Cancelled', cancel_reason = ?, cancel_reason_other = ?, cancelled_at = NOW()
     WHERE id = ? AND creator_id = ? AND status <> 'Cancelled'"
);
$update->bind_param("ssii", $reason, $otherText, $tripId, $userId);

if ($update->execute() && $update->affected_rows > 0) {
    echo json_encode(["status" => "success", "message" => "🎉 TRIP Cancelled successfully!!!"]);
} else {
    // Race condition: someone else's request cancelled it a moment earlier.
    $check = $conn->prepare("SELECT status FROM trips WHERE id = ?");
    $check->bind_param("i", $tripId);
    $check->execute();
    $checkRow = $check->get_result()->fetch_assoc();
    $check->close();

    if ($checkRow && $checkRow['status'] === 'Cancelled') {
        echo json_encode(["status" => "success", "message" => "🎉 TRIP Cancelled successfully!!!", "already" => true]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "❌ Unable to cancel the trip. Please try again."]);
    }
}

$update->close();
$conn->close();
