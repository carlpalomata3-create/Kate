<?php
// ============================================================
// register.php — Student Self-Registration
// Students register using their school email (@sscrmnl.edu.ph)
// ============================================================
session_start();
require_once 'db.php';

// Already logged in? Redirect.
if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role'] === 'librarian' ? 'dashboard.php' : 'search.php'));
    exit();
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    // --- Validation ---
    if (empty($full_name) || empty($email) || empty($username) || empty($password) || empty($confirm)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!str_ends_with(strtolower($email), '@sscrmnl.edu.ph')) {
        $error = 'Only San Sebastian College (@sscrmnl.edu.ph) email addresses are allowed.';
    } elseif (strlen($username) < 4) {
        $error = 'Username must be at least 4 characters.';
    } elseif (!preg_match('/^[a-zA-Z0-9._]+$/', $username)) {
        $error = 'Username can only contain letters, numbers, dots, and underscores.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Check if username or email already exists
        $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = 'Username or email is already taken. Please choose another.';
        } else {
            // Hash password and insert
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $insert = $conn->prepare("INSERT INTO users (username, full_name, email, password, role) VALUES (?, ?, ?, ?, 'student')");
            $insert->bind_param("ssss", $username, $full_name, $email, $hashed);

            if ($insert->execute()) {
                $success = 'Account created successfully! You can now log in.';
            } else {
                // If email column doesn't exist yet, try without it
                $insert2 = $conn->prepare("INSERT INTO users (username, full_name, password, role) VALUES (?, ?, ?, 'student')");
                $insert2->bind_param("sss", $username, $full_name, $hashed);
                if ($insert2->execute()) {
                    $success = 'Account created successfully! You can now log in.';
                } else {
                    $error = 'Registration failed. Please try again. Error: ' . $conn->error;
                }
                $insert2->close();
            }
            $insert->close();
        }
        $check->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — St. Thomas of Villanova College Library</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="login-wrapper">
    <div class="login-bg-overlay"></div>
    <div class="login-card" style="max-width:460px;">

        <div class="login-logo">
            <img src="school_logo.png" alt="SSCR Logo"
                 onerror="this.style.display='none'; document.getElementById('logoFallback').style.display='flex';">
            <div class="logo-fallback" id="logoFallback" style="display:none;"></div>
        </div>

        <div class="school-name">San Sebastian College&nbsp;-&nbsp;Recoletos Manila</div>
        <div class="school-location">Established 1941 &bull; Manila, Philippines</div>
        <div class="sscr-divider"><span></span><span></span></div>

        <h1>Create Your Account</h1>
        <p class="welcome-text" style="margin-bottom:1rem;">
            Register using your school email<br>
            <strong style="color:var(--red);">@sscrmnl.edu.ph</strong>
        </p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success) ?>
                <br><a href="login.php" style="color:var(--success);font-weight:700;">Click here to login →</a>
            </div>
        <?php endif; ?>

        <?php if (!$success): ?>
        <form action="" method="POST">

            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="full_name"
                       placeholder="e.g. Juan Dela Cruz"
                       value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                       required>
            </div>

            <div class="form-group">
                <label>School Email *</label>
                <input type="email" name="email"
                       placeholder="yourname@sscrmnl.edu.ph"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       required>
                <div style="font-size:0.77rem;color:var(--muted);margin-top:0.25rem;">
                    Only @sscrmnl.edu.ph emails are accepted.
                </div>
            </div>

            <div class="form-group">
                <label>Username *</label>
                <input type="text" name="username"
                       placeholder="e.g. juan.delacruz"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       pattern="[a-zA-Z0-9._]+" minlength="4"
                       required>
                <div style="font-size:0.77rem;color:var(--muted);margin-top:0.25rem;">
                    Min. 4 characters. Letters, numbers, dots, underscores only.
                </div>
            </div>

            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="password" id="passwordInput"
                       placeholder="Min. 8 characters"
                       minlength="8" required
                       oninput="checkStrength(this.value)">
                <div class="strength-bar"><div class="fill" id="strengthFill" style="width:0%"></div></div>
                <div class="strength-label" id="strengthLabel" style="color:var(--muted);">Enter a password</div>
            </div>

            <div class="form-group">
                <label>Confirm Password *</label>
                <input type="password" name="confirm_password"
                       placeholder="Re-enter your password"
                       minlength="8" required>
            </div>

            <button type="submit" class="btn btn-primary">Create Account →</button>
        </form>
        <?php endif; ?>

        <div class="divider">or</div>
        <div class="register-note">
            Already have an account? <a href="login.php">Sign in here</a>
        </div>

    </div>
</div>

<script>
function checkStrength(password) {
    var fill  = document.getElementById('strengthFill');
    var label = document.getElementById('strengthLabel');
    var score = 0;
    if (password.length >= 8)  score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;

    var levels = [
        { pct: '0%',   color: '#ccc',              text: 'Enter a password',  labelColor: 'var(--muted)' },
        { pct: '25%',  color: 'var(--red)',         text: 'Weak',              labelColor: 'var(--red)' },
        { pct: '50%',  color: '#e07b00',            text: 'Fair',              labelColor: '#e07b00' },
        { pct: '75%',  color: 'var(--yellow-d)',    text: 'Good',              labelColor: 'var(--yellow-d)' },
        { pct: '100%', color: 'var(--success)',     text: 'Strong ✓',          labelColor: 'var(--success)' },
    ];
    var lvl = password.length === 0 ? levels[0] : levels[score];
    fill.style.width      = lvl.pct;
    fill.style.background = lvl.color;
    label.textContent     = lvl.text;
    label.style.color     = lvl.labelColor;
}
</script>

</body>
</html>
