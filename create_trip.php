<?php
session_start();
header('Content-Type: application/json');

include "db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
    exit;
}

// ---- Authentication: the creator is ALWAYS the logged-in session user.
// Any "created_by" value sent from the client is ignored entirely.
if (!isset($_SESSION["user"]) || empty($_SESSION["user"])) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "🔒 Please log in before creating a trip."]);
    exit;
}

$creatorId = (int) $_SESSION["user"];

// Confirm the session user actually exists (defensive check).
$userCheck = $conn->prepare("SELECT id, name FROM users WHERE id = ?");
$userCheck->bind_param("i", $creatorId);
$userCheck->execute();
$userResult = $userCheck->get_result();
if ($userResult->num_rows === 0) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Your session is invalid. Please log in again."]);
    exit;
}
$userCheck->close();

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid request data."]);
    exit;
}

// ---- Collect + sanitize inputs ----
$tripName             = trim($data["trip_name"] ?? "");
$destination          = trim($data["destination"] ?? "");
$description          = trim($data["description"] ?? "");
$tripType             = trim($data["trip_type"] ?? "");
$startDate            = trim($data["start_date"] ?? "");
$endDate              = trim($data["end_date"] ?? "");
$maxMembersRaw        = $data["max_members"] ?? null;
$registrationDeadline = trim($data["registration_deadline"] ?? "");
$estimatedCostRaw     = $data["estimated_cost"] ?? null;
$ageLimitRaw          = $data["age_limit"] ?? null;
$whoCanJoin           = trim($data["who_can_join"] ?? "");

$errors = [];

// Trip Name
if ($tripName === "" || mb_strlen($tripName) > 100) {
    $errors[] = "Trip name is required and must be under 100 characters.";
}

// Destination
if ($destination === "" || mb_strlen($destination) > 100) {
    $errors[] = "Destination is required and must be under 100 characters.";
}

// Trip Type
$allowedTripTypes = ["Adventure", "Leisure", "Business", "Educational", "Family", "Other"];
if (!in_array($tripType, $allowedTripTypes, true)) {
    $errors[] = "Please select a valid trip type.";
}

// Dates
$startDateTime = DateTime::createFromFormat("Y-m-d", $startDate);
$endDateTime   = DateTime::createFromFormat("Y-m-d", $endDate);

if (!$startDateTime) {
    $errors[] = "A valid start date is required.";
}

if (!$endDateTime) {
    $errors[] = "A valid end date is required.";
}

$numberOfDays = null;
if ($startDateTime && $endDateTime) {
    if ($endDateTime < $startDateTime) {
        $errors[] = "End date cannot be earlier than start date.";
    } else {
        $numberOfDays = (int) $startDateTime->diff($endDateTime)->days + 1;
        if ($numberOfDays < 1) {
            $errors[] = "No. of days must be a positive number.";
        }
    }
}

// Maximum Members
if (!is_numeric($maxMembersRaw) || (int)$maxMembersRaw != $maxMembersRaw || (int)$maxMembersRaw < 1) {
    $errors[] = "Maximum members must be a valid positive integer.";
} else {
    $maxMembers = (int) $maxMembersRaw;
}

// Registration Deadline (optional)
$registrationDeadlineValue = null;
if ($registrationDeadline !== "") {
    $regDeadlineTime = DateTime::createFromFormat("Y-m-d", $registrationDeadline);
    if (!$regDeadlineTime) {
        $errors[] = "Registration deadline is not a valid date.";
    } elseif ($startDateTime && $regDeadlineTime > $startDateTime) {
        $errors[] = "Registration deadline should not be after the trip's start date.";
    } else {
        $registrationDeadlineValue = $registrationDeadline;
    }
}

// Estimated Cost (optional)
$estimatedCostValue = null;
if ($estimatedCostRaw !== null && $estimatedCostRaw !== "") {
    if (!is_numeric($estimatedCostRaw) || (float)$estimatedCostRaw < 0) {
        $errors[] = "Estimated cost must be a valid non-negative number.";
    } else {
        $estimatedCostValue = (float) $estimatedCostRaw;
    }
}

// Age Limit
if (!is_numeric($ageLimitRaw) || (int)$ageLimitRaw != $ageLimitRaw || (int)$ageLimitRaw < 0 || (int)$ageLimitRaw > 120) {
    $errors[] = "Age limit must be a valid age.";
} else {
    $ageLimit = (int) $ageLimitRaw;
}

// Who Can Join
$allowedWhoCanJoin = ["Everyone", "Women", "Men"];
if (!in_array($whoCanJoin, $allowedWhoCanJoin, true)) {
    $errors[] = "Who Can Join must be one of Everyone, Women, or Men.";
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => implode(" ", $errors)]);
    exit;
}

// ---- Persist ----
// NOTE: the existing `privacy` column is reused to store the
// "Who Can Join" value (Everyone / Women / Men) so no duplicate
// column/table is introduced. `travel_date` (legacy single-date
// column) is kept in sync with start_date for backward compatibility.
$inviteCode = "TRIP" . rand(1000, 9999);

try {
    $stmt = $conn->prepare("
        INSERT INTO trips (
            creator_id, trip_name, destination, description, travel_date,
            start_date, end_date, number_of_days, max_members,
            registration_deadline, estimated_cost, age_limit,
            privacy, trip_type, invite_code
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "issssssiisdisss",
        $creatorId,
        $tripName,
        $destination,
        $description,
        $startDate,           // travel_date kept in sync with start_date
        $startDate,
        $endDate,
        $numberOfDays,
        $maxMembers,
        $registrationDeadlineValue,
        $estimatedCostValue,
        $ageLimit,
        $whoCanJoin,           // stored in the existing `privacy` column
        $tripType,
        $inviteCode
    );

    if ($stmt->execute()) {
        echo json_encode([
            "status" => "success",
            "message" => "✅ Trip created successfully!",
            "trip_id" => $stmt->insert_id
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "❌ Failed to create trip: " . $stmt->error]);
    }

    $stmt->close();
    $conn->close();
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database error while creating trip: " . $e->getMessage() .
            " (Did you run migration_trip_communities.sql against this database?)"
    ]);
}
