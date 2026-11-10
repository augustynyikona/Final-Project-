<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'nurse') {
    header("Location: ../auth/login.php");
    exit;
}
// Inventory has been removed from nurse desk. Redirect to Pharmacy Requisitions.
header("Location: requisition.php");
exit;
?>
