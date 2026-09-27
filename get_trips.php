<?php
/**
 * get_trips.php
 *
 * GET get_trips.php?scope=all
 *   -> every upcoming/ongoing trip created by any user (ended trips,
 *      i.e. those whose effective date has already passed, are
 *      excluded), used by the "Upcoming & Ongoing Community Trips"
 *      homepage section.
 *
 * GET get_trips.php?scope=mine_ongoing
 *   -> only the logged-in user's own trips that are still active/
 *      upcoming (not Cancelled, and whose effective end date has
 *      not yet passed), used by the "Ongoing Trips" homepage section.
 *      Requires login; returns 401 if the visitor isn't logged in.
 *
 * Default (no scope, or an unrecognised value) behaves like scope=all
 * for backward compatibility with any older caller.
 */

session_start();
header("Content-Type: application/json");

include "db.php";

$scope = $_GET['scope'] ?? 'all';

if ($scope === 'mine_ongoing') {
    if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "🔒 Please log in to see your ongoing trips."]);
        $conn->close();
        exit();
    }

    $userId = (int) $_SESSION['user'];

    $sql = "
        SELECT t.*, u.name AS creator_name,
               (SELECT COUNT(*) FROM trip_members tm WHERE tm.trip_id = t.id) AS member_count
        FROM trips t
        LEFT JOIN users u ON t.creator_id = u.id
        WHERE t.creator_id = ?
          AND (t.status IS NULL OR t.status <> 'Cancelled')
          AND (
                COALESCE(t.end_date, t.start_date, t.travel_date) IS NULL
                OR COALESCE(t.end_date, t.start_date, t.travel_date) >= CURDATE()
              )
        ORDER BY t.created_at DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $trips = [];
    while ($row = $result->fetch_assoc()) {
        $row['member_count'] = (int) $row['member_count'];
        $trips[] = $row;
    }

    echo json_encode($trips);
    $stmt->close();
    $conn->close();
    exit();
}

// ---- scope=all (default) ----
// Only upcoming/ongoing trips are returned: a trip is excluded once its
// effective date (end_date, falling back to start_date, then travel_date)
// has already passed. Cancelled-but-not-yet-ended trips are still
// included here (unchanged from prior behavior) since "ended" is a
// date-based concept, not a status-based one.
$sql = "
    SELECT t.*, u.name AS creator_name,
           (SELECT COUNT(*) FROM trip_members tm WHERE tm.trip_id = t.id) AS member_count
    FROM trips t
    LEFT JOIN users u ON t.creator_id = u.id
    WHERE (
            COALESCE(t.end_date, t.start_date, t.travel_date) IS NULL
            OR COALESCE(t.end_date, t.start_date, t.travel_date) >= CURDATE()
          )
    ORDER BY t.created_at DESC
";
$result = $conn->query($sql);

$trips = [];
while ($row = $result->fetch_assoc()) {
    $row['member_count'] = (int) $row['member_count'];
    $trips[] = $row;
}

echo json_encode($trips);
$conn->close();
