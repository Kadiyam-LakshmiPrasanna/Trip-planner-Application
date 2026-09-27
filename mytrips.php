<?php
/**
 * mytrips.php
 *
 * Handles:
 *   POST   /mytrips.php            -> save a generated trip plan
 *   GET    /mytrips.php            -> list the logged-in user's trips
 *   DELETE /mytrips.php?id=123     -> delete one of the logged-in user's trips
 *
 * Auth: reuses the existing session-based login system
 * (the same $_SESSION['user'] set in login.php).
 *
 * FIXED: This file now lives in the SAME directory as db.php
 * (same level as login.php / register.php / logout.php), so the
 * include below is "db.php", not "../db.php". If you move this
 * file into a subfolder, update the include path to match.
 */

header('Content-Type: application/json');

session_start();
include "db.php";

// ---------------------------------------------------------
// 1. Require authentication for every action on this endpoint
// ---------------------------------------------------------
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode([
        "status"  => "error",
        "message" => "You must be logged in to do this."
    ]);
    exit();
}

$userId = (int) $_SESSION['user'];
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'POST':
        handleSaveTrip($conn, $userId);
        break;

    case 'GET':
        handleListTrips($conn, $userId);
        break;

    case 'DELETE':
        handleDeleteTrip($conn, $userId);
        break;

    default:
        http_response_code(405);
        echo json_encode([
            "status"  => "error",
            "message" => "Method not allowed."
        ]);
        break;
}

$conn->close();


// ===========================================================
// POST /mytrips.php  — Save a generated trip plan
// ===========================================================
function handleSaveTrip($conn, $userId)
{
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode([
            "status"  => "error",
            "message" => "Invalid request data."
        ]);
        return;
    }

    $destination       = trim($data['destination'] ?? '');
    $destinationImage  = trim($data['destinationImage'] ?? '');
    $currentLocation   = trim($data['currentLocation'] ?? '');
    $startDate         = $data['startDate'] ?? null;
    $endDate           = $data['endDate'] ?? null;
    $numberOfDays      = (int)($data['numberOfDays'] ?? 0);
    $numberOfPeople    = (int)($data['numberOfPeople'] ?? 1);
    $budget            = (float)($data['budget'] ?? 0);
    $currency          = trim($data['currency'] ?? 'USD');
    $estimatedCost     = (float)($data['estimatedCost'] ?? 0);
    $userName          = trim($data['userName'] ?? '');

    // Complex fields are stored as JSON text so the frontend
    // can shape them however the trip-generation logic produces them.
    $itinerary       = isset($data['itinerary'])       ? json_encode($data['itinerary'])       : null;
    $hotels          = isset($data['hotels'])          ? json_encode($data['hotels'])          : null;
    $transportation  = isset($data['transportation'])  ? json_encode($data['transportation'])  : null;
    $activities      = isset($data['activities'])      ? json_encode($data['activities'])      : null;
    $weatherData     = isset($data['weather'])         ? json_encode($data['weather'])         : null;
    $budgetBreakdown = isset($data['budgetBreakdown']) ? json_encode($data['budgetBreakdown']) : null;
    $aiItineraryHtml = isset($data['aiItineraryHTML']) ? (string) $data['aiItineraryHTML']      : null;

    // -------- Validation: only save a plan that actually generated --------
    if ($destination === '' || $numberOfDays <= 0) {
        http_response_code(400);
        echo json_encode([
            "status"  => "error",
            "message" => "📍 A destination and number of days are required to save a trip."
        ]);
        return;
    }

    $startDate = $startDate ?: null;
    $endDate   = $endDate ?: null;

    // -------- Duplicate prevention --------
    // Hash the fields that define "the same generated plan" for this user.
    // If they click Save Trip twice on the same generated plan, the second
    // attempt is treated as a friendly "already saved" rather than a
    // duplicate row.
    $tripHash = hash('sha256', implode('|', [
        $userId, $destination, $startDate, $endDate, $numberOfDays,
        $numberOfPeople, $budget, $currency, $itinerary
    ]));

    $existing = $conn->prepare("SELECT id FROM MyTrips WHERE user_id = ? AND trip_hash = ? LIMIT 1");
    $existing->bind_param("is", $userId, $tripHash);
    $existing->execute();
    $existingResult = $existing->get_result();
    if ($existingRow = $existingResult->fetch_assoc()) {
        $existing->close();
        echo json_encode([
            "status"  => "success",
            "message" => "✅ This trip is already saved in My Trips.",
            "id"      => (int) $existingRow['id'],
            "duplicate" => true
        ]);
        return;
    }
    $existing->close();

    $stmt = $conn->prepare(
        "INSERT INTO MyTrips
            (user_id, user_name, destination, destination_image, current_location, start_date, end_date,
             number_of_days, number_of_people, budget, currency,
             itinerary, hotels, transportation, activities, estimated_cost,
             weather_data, budget_breakdown, ai_itinerary_html, trip_hash)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "❌ Failed to prepare statement: " . $conn->error]);
        return;
    }

    $stmt->bind_param(
        "issssssiidsssssdssss",
        $userId,
        $userName,
        $destination,
        $destinationImage,
        $currentLocation,
        $startDate,
        $endDate,
        $numberOfDays,
        $numberOfPeople,
        $budget,
        $currency,
        $itinerary,
        $hotels,
        $transportation,
        $activities,
        $estimatedCost,
        $weatherData,
        $budgetBreakdown,
        $aiItineraryHtml,
        $tripHash
    );

    try {
        $stmt->execute();
        echo json_encode([
            "status"  => "success",
            "message" => "✅ Trip saved to My Trips.",
            "id"      => $stmt->insert_id,
            "duplicate" => false
        ]);
    } catch (mysqli_sql_exception $e) {
        // A race between two rapid clicks could still hit the unique key;
        // treat that as "already saved" rather than a hard failure.
        if ($conn->errno === 1062) {
            echo json_encode([
                "status"  => "success",
                "message" => "✅ This trip is already saved in My Trips.",
                "duplicate" => true
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                "status"  => "error",
                "message" => "❌ Could not save trip: " . $e->getMessage()
            ]);
        }
    }

    $stmt->close();
}


// ===========================================================
// GET /mytrips.php — List only the logged-in user's trips
// ===========================================================
function handleListTrips($conn, $userId)
{
    $stmt = $conn->prepare(
        "SELECT id, destination, destination_image, current_location, start_date, end_date,
                number_of_days, number_of_people, budget, currency,
                itinerary, hotels, transportation, activities,
                estimated_cost, weather_data, budget_breakdown, ai_itinerary_html,
                created_at, updated_at
         FROM MyTrips
         WHERE user_id = ?
         ORDER BY created_at DESC"
    );

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to prepare statement: " . $conn->error]);
        return;
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $trips = [];
    while ($row = $result->fetch_assoc()) {
        $row['itinerary']       = $row['itinerary']       ? json_decode($row['itinerary'], true)       : null;
        $row['hotels']          = $row['hotels']          ? json_decode($row['hotels'], true)          : null;
        $row['transportation']  = $row['transportation']  ? json_decode($row['transportation'], true)  : null;
        $row['activities']      = $row['activities']      ? json_decode($row['activities'], true)      : null;
        $row['weather_data']    = $row['weather_data']    ? json_decode($row['weather_data'], true)    : null;
        $row['budget_breakdown']= $row['budget_breakdown']? json_decode($row['budget_breakdown'], true): null;
        $trips[] = $row;
    }

    echo json_encode([
        "status" => "success",
        "trips"  => $trips
    ]);

    $stmt->close();
}


// ===========================================================
// DELETE /mytrips.php?id=123 — Delete one of the user's own trips
// ===========================================================
function handleDeleteTrip($conn, $userId)
{
    $tripId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($tripId <= 0) {
        http_response_code(400);
        echo json_encode([
            "status"  => "error",
            "message" => "A valid trip id is required."
        ]);
        return;
    }

    // user_id = ? in the WHERE clause guarantees a user can never
    // delete a trip that isn't their own, even if they guess an id.
    $stmt = $conn->prepare("DELETE FROM MyTrips WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $tripId, $userId);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo json_encode([
            "status"  => "success",
            "message" => "Trip deleted."
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            "status"  => "error",
            "message" => "Trip not found."
        ]);
    }

    $stmt->close();
}
