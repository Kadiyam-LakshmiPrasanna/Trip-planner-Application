<?php
/**
 * exit_community.php
 *
 * POST /exit_community.php
 * Body: { trip_id }
 *
 * Removes the logged-in session user's own membership row from
 * trip_members for the given trip. A user can only ever remove
 * themselves — trip_id + user_id (from the session) both appear in
 * the WHERE clause, so there is no way to exit on someone else's
 * behalf.
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

$tripId = isset($data['trip_id']) ? (int) $data['trip_id'] : 0;

if ($tripId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "A valid trip is required."]);
    exit();
}

$stmt = $conn->prepare("DELETE FROM trip_members WHERE trip_id = ? AND user_id = ?");
$stmt->bind_param("ii", $tripId, $userId);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo json_encode(["status" => "success", "message" => "👋 You have exited this community."]);
} else {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "❌ You are not a member of this community."]);
}

$stmt->close();
$conn->close();
