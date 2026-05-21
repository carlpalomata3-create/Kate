<?php
// generate_hash.php
// Visit this file ONCE, copy the hashes, then DELETE it.

$accounts = [
    'librarian' => 'admin123',
    'student'   => 'student123',
];

echo "<pre style='font-family:monospace;font-size:14px;padding:20px;'>";
echo "=== Copy the UPDATE statements below into phpMyAdmin ===\n\n";

foreach ($accounts as $username => $password) {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    echo "UPDATE users SET password = '$hash' WHERE username = '$username';\n\n";
}

echo "</pre>";
?>