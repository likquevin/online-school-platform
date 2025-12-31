<?php
// includes/access_guard.php
// Robust access guard for teacher & student classroom pages.
// - Defines WS_URL and STUN_SERVERS_JSON (uses TURN env vars if present).
// - Provides enforce_access(), enforce_teacher_access(), enforce_student_access().
// - Performs session checks, DB fallback, schedule checks.
//
// NOTE: This file is safe to include multiple times (it checks session status).
// If you prefer to use a different signaling host/port, define WS_URL before including this file.

if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Africa/Kigali');

/* ------------------------
   SIGNALING (WebSocket) URL
   ------------------------
   Default changed to local dev signaling server on port 8080.
   You may override by defining WS_URL before including this file.
*/
if (!defined('WS_URL')) {
    // Use 127.0.0.1 to avoid IPv6/hostname resolution quirks in some environments.
    define('WS_URL', 'ws://127.0.0.1:8080');
}

/* ------------------------
   STUN / TURN configuration
   ------------------------
   Default STUN list includes public Google STUNs.
   If TURN credentials are available in environment (or defined constants),
   a TURN entry will be appended (supports comma-separated TURN_URL).
*/
$stun_list = [
    ['urls' => 'stun:stun.l.google.com:19302'],
    ['urls' => 'stun:stun1.l.google.com:19302']
];

$turn_url  = getenv('TURN_URL') ?: (defined('TURN_URL') ? TURN_URL : null);
$turn_user = getenv('TURN_USERNAME') ?: (defined('TURN_USERNAME') ? TURN_USERNAME : null);
$turn_pass = getenv('TURN_PASSWORD') ?: (defined('TURN_PASSWORD') ? TURN_PASSWORD : null);

if ($turn_url && $turn_user && $turn_pass) {
    $urls = array_map('trim', explode(',', $turn_url));
    // support either string or array format in output (we use array for 'urls')
    $stun_list[] = [
        'urls'       => $urls,
        'username'   => $turn_user,
        'credential' => $turn_pass
    ];
}

if (!defined('STUN_SERVERS_JSON')) {
    define('STUN_SERVERS_JSON', json_encode($stun_list, JSON_UNESCAPED_SLASHES));
}

/* ------------------------
   DB connection fallback
   ------------------------
   If the including script already provides $mysqli, it will be used.
   Otherwise we create a local mysqli connection using default local dev credentials.
*/
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    $DB_HOST = "localhost";
    $DB_USER = "root";
    $DB_PASS = "";
    $DB_NAME = "skill-spring";

    $mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if ($mysqli->connect_error) {
        http_response_code(500);
        echo "DB connection failed: " . htmlspecialchars($mysqli->connect_error);
        exit;
    }
}

/* Small helper to output an error and exit.
   Designed to be friendly for direct page includes.
*/
function access_denied($msg = "Access denied.", $code = 403) {
    http_response_code($code);
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Access Error</title>";
    echo "<meta name='viewport' content='width=device-width,initial-scale=1' />";
    echo "<style>body{font-family:Segoe UI,Arial,Helvetica,sans-serif;background:#111;color:#eee;margin:0;padding:30px;} .wrap{max-width:720px;margin:60px auto;text-align:center;} h2{color:#ff6b6b}</style>";
    echo "</head><body><div class='wrap'><h2>" . htmlspecialchars($msg) . "</h2></div></body></html>";
    exit;
}

/**
 * enforce_access: core guard
 *
 * @param mysqli $mysqli
 * @param string $CLASSROOM_CODE
 * @param string $role 'student'|'teacher'|'auto'
 * @return array [$CLASSROOM_assoc_array, $USER_ID, $ROLE]
 */
function enforce_access($mysqli, $CLASSROOM_CODE, $role = 'auto') {
    // Validate classroom code format (adjust regex if your format differs)
    if (!is_string($CLASSROOM_CODE) || !preg_match('/^CLS-\d{8}-[0-9A-F]{4}$/i', $CLASSROOM_CODE)) {
        access_denied("Invalid classroom code.", 400);
    }

    // Fetch classroom record
    $stmt = $mysqli->prepare("SELECT * FROM classrooms WHERE classroom_code = ?");
    if (!$stmt) access_denied("Database error (prepare/select classrooms).", 500);
    $stmt->bind_param("s", $CLASSROOM_CODE);
    $stmt->execute();
    $res = $stmt->get_result();
    $CLASSROOM = $res->fetch_assoc();
    $stmt->close();

    if (!$CLASSROOM) access_denied("Classroom not found.", 404);

    // Determine effective role (prefer global $required_role if set)
    global $required_role;
    if ($role === 'auto') {
        if (!empty($required_role) && in_array($required_role, ['teacher','student'], true)) {
            $role = $required_role;
        } else {
            $role = (strpos($_SERVER['PHP_SELF'], '/teachers/') !== false) ? 'teacher' : 'student';
        }
    }

    // Check session & user existence depending on role
    if ($role === 'teacher') {
        if (empty($_SESSION['teacher_id'])) {
            // redirect to teacher login if available (adjust path)
            if (!headers_sent()) {
                header("Location: /teachers/login.php");
                exit;
            }
            access_denied("Teacher login required.", 401);
        }
        $USER_ID = (int) $_SESSION['teacher_id'];

        // optional sanity check: ensure teacher exists
        $stmt = $mysqli->prepare("SELECT id, name, email FROM teachers WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $USER_ID);
            $stmt->execute();
            $r = $stmt->get_result();
            if ($r->num_rows !== 1) access_denied("Teacher record not found.", 403);
            $stmt->close();
        }
    } else {
        // student role
        if (empty($_SESSION['student_id'])) {
            if (!headers_sent()) {
                header("Location: /student/login.php");
                exit;
            }
            access_denied("Student login required.", 401);
        }
        $USER_ID = (int) $_SESSION['student_id'];

        // ensure student is registered for this classroom
        $stmt = $mysqli->prepare("SELECT id FROM registered_students WHERE id = ? AND classroom_id = ?");
        if ($stmt) {
            $stmt->bind_param("ii", $USER_ID, $CLASSROOM['id']);
            $stmt->execute();
            $r = $stmt->get_result();
            if ($r->num_rows !== 1) access_denied("Student not registered in this classroom.", 403);
            $stmt->close();
        }
    }

    /* ------------------------
       Schedule checks (optional)
       ------------------------
       Remove or adjust if you want classes accessible at any time.
    */
    $now = new DateTime('now', new DateTimeZone('Africa/Kigali'));
    $todayShort = $now->format('D'); // Mon, Tue, ...
    $current_time = $now->format('H:i:s');

    $days_array = json_decode($CLASSROOM['days_json'], true);
    if (!is_array($days_array)) $days_array = [];

    // if days configured, enforce today's availability
    if (count($days_array) > 0 && !in_array($todayShort, $days_array, true)) {
        access_denied("Class not scheduled for today ({$todayShort}).", 403);
    }

    // time window check (accepts HH:MM or HH:MM:SS)
    $start = isset($CLASSROOM['start_time']) && $CLASSROOM['start_time'] !== '' ? $CLASSROOM['start_time'] : null;
    $end   = isset($CLASSROOM['end_time'])   && $CLASSROOM['end_time'] !== ''   ? $CLASSROOM['end_time']   : null;

    if ($start && $end) {
        if (preg_match('/^\d{2}:\d{2}$/', $start)) $start .= ':00';
        if (preg_match('/^\d{2}:\d{2}$/', $end))   $end   .= ':00';

        if ($current_time < $start || $current_time > $end) {
            access_denied("Class not accessible at this time ({$current_time}). Allowed: {$start} - {$end}.", 403);
        }
    }

    // success: return classroom row, user id and resolved role
    return [$CLASSROOM, $USER_ID, $role];
}

/* ------------------------
   Compatibility wrappers
   ------------------------ */
function enforce_teacher_access($mysqli, $CLASSROOM_CODE) {
    list($CLASSROOM, $TEACHER_ID, $ROLE) = enforce_access($mysqli, $CLASSROOM_CODE, 'teacher');
    return [$CLASSROOM, $TEACHER_ID];
}

function enforce_student_access($mysqli, $CLASSROOM_CODE) {
    list($CLASSROOM, $STUDENT_ID, $ROLE) = enforce_access($mysqli, $CLASSROOM_CODE, 'student');
    return [$CLASSROOM, $STUDENT_ID];
}

/* ------------------------
   Utility
   ------------------------ */
function get_ice_servers() {
    $json = STUN_SERVERS_JSON;
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}
