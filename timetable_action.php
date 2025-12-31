<?php
require_once 'db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Weekdays & Time slots
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$time_slots = ['08_00_10_00', '10_00_12_00', '13_00_15_00', '15_00_17_00'];

// Fetch classrooms
$classrooms = [];
$result = $conn->query("SELECT id, classroom_name FROM classrooms");
while ($row = $result->fetch_assoc()) {
    $classrooms[$row['id']] = $row['classroom_name'];
}

// Fetch teachers
$teachers = [];
$result = $conn->query("SELECT id, name FROM teachers");
while ($row = $result->fetch_assoc()) {
    $teachers[$row['id']] = $row['name'];
}

// Existing timetable
$existing_timetable = [];
$timetable_res = $conn->query("SELECT * FROM timetable");
while ($row = $timetable_res->fetch_assoc()) {
    $key = $row['classroom_id'] . '_' . $row['day'] . '_' . str_replace([':', ' ', '-'], ['_', '', '_'], $row['time_slot']);
    $existing_timetable[$key] = [
        'module_name' => $row['module_name'],
        'teacher_id'  => $row['teacher_id']
    ];
}

// Save timetable
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_timetable'])) {
    $conn->query("DELETE FROM timetable"); // clear old timetable

    $stmt = $conn->prepare("INSERT INTO timetable (day, time_slot, module_name, classroom_id, teacher_id) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }

    foreach ($classrooms as $classroom_id => $classroom_name) {
        foreach ($days as $day) {
            foreach ($time_slots as $slot) {
                $field_module = "module_{$day}_{$slot}_{$classroom_id}";
                $field_teacher = "teacher_{$day}_{$slot}_{$classroom_id}";

                $module_name = trim($_POST[$field_module] ?? '');
                $teacher_id = $_POST[$field_teacher] ?? '';

                if (!empty($module_name) && !empty($teacher_id)) {
                    $slot_formatted = str_replace('_', ':', substr($slot, 0, 5)) . '-' . str_replace('_', ':', substr($slot, 6));
                    $stmt->bind_param("ssssi", $day, $slot_formatted, $module_name, $classroom_id, $teacher_id);
                    $stmt->execute();
                }
            }
        }
    }
    $stmt->close();

    // Send notification
    $title = "New Timetable Published";
    $message = "The timetable has been updated. <a href='timetable.php'>View</a>";
    $notif_stmt = $conn->prepare("INSERT INTO notifications (title, message) VALUES (?, ?)");
    $notif_stmt->bind_param("ss", $title, $message);
    $notif_stmt->execute();
    $notif_stmt->close();

    echo "<p style='color:green; font-weight:bold; text-align:center;'>✅ Timetable saved successfully!</p>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Timetable</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #eef2f7;
            margin: 0;
            padding: 20px;
        }
        h2 {
            text-align: center;
            color: #2c3e50;
        }
        h3 {
            background: #34495e;
            color: white;
            padding: 10px;
            border-radius: 4px;
            margin-top: 30px;
        }
        form {
            max-width: 95%;
            margin: auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        table thead {
            background: #2c3e50;
            color: white;
        }
        table th, table td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: center;
        }
        .time-slot {
            background: #ecf0f1;
            font-weight: bold;
        }
        input[type="text"], select {
            width: 95%;
            padding: 5px;
            border: 1px solid #bbb;
            border-radius: 4px;
            margin-top: 4px;
        }
        button {
            display: block;
            margin: auto;
            padding: 12px 24px;
            background: #27ae60;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
        }
        button:hover {
            background: #219150;
        }
    </style>
</head>
<body>
<h2>Manage Timetable (By Principal)</h2>
<form method="POST">
    <?php foreach ($classrooms as $classroom_id => $classroom_name): ?>
        <h3><?php echo htmlspecialchars($classroom_name); ?></h3>
        <table>
            <thead>
                <tr>
                    <th>Time / Day</th>
                    <?php foreach ($days as $day): ?>
                        <th><?php echo $day; ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($time_slots as $slot): ?>
                    <tr>
                        <td class="time-slot">
                            <?php echo str_replace('_', ':', substr($slot, 0, 5)) . ' - ' . str_replace('_', ':', substr($slot, 6)); ?>
                        </td>
                        <?php foreach ($days as $day):
                            $key = $classroom_id . '_' . $day . '_' . $slot;
                            $prefill_module = $existing_timetable[$key]['module_name'] ?? '';
                            $prefill_teacher = $existing_timetable[$key]['teacher_id'] ?? '';
                        ?>
                        <td>
                            <input type="text" name="module_<?php echo $day . '_' . $slot . '_' . $classroom_id; ?>"
                                   placeholder="Andika Module" value="<?php echo htmlspecialchars($prefill_module); ?>">
                            <select name="teacher_<?php echo $day . '_' . $slot . '_' . $classroom_id; ?>">
                                <option value="">-- Hitamo Teacher --</option>
                                <?php foreach ($teachers as $id => $name): ?>
                                    <option value="<?php echo $id; ?>" <?php if ($id == $prefill_teacher) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
    <button type="submit" name="save_timetable">💾 Save Timetable</button>
</form>
</body>
</html>
