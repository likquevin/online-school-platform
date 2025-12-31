<?php
$servername = "localhost";
$username = "db_user"; // default username for localhost
$password = "db_password"; // default password for localhost
$dbname = "database_name";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>

