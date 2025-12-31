<?php
$servername = "localhost";
$username = "root"; // default username for localhost
$password = ""; // default password for localhost
$dbname = "skill-spring";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
