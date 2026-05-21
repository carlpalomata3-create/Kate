<?php
session_start();
require_once 'db.php';

// Redirect already-logged-in users
if (isset($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role'] === 'student' ? 'search.php' : 'dashboard.php'));
    exit();
}

$error   = '';
$success = '';

// ================= LOGIN =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both your username and password.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, role, full_name FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            $password_ok = false;

            // Try bcrypt verify first (secure accounts)
            if (password_verify($password, $user['password'])) {
                $password_ok = true;
            }
            // Fallback: plain text comparison (old accounts not yet hashed)
            elseif ($password === $user['password']) {
                $password_ok = true;
                // Auto-upgrade to bcrypt hash on successful plain text login
                $new_hash = password_hash($password, PASSWORD_BCRYPT);
                $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $upd->bind_param("si", $new_hash, $user['id']);
                $upd->execute();
                $upd->close();
            }

            if ($password_ok) {
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];

                header('Location: ' . ($user['role'] === 'student' ? 'search.php' : 'dashboard.php'));
                exit();
            } else {
                $error = 'Incorrect password. Please try again.';
            }
        } else {
            $error = 'No account found with that username.';
        }
        $stmt->close();
    }
}

// ================= FORGOT PASSWORD =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forgot') {
    $fp_username = trim($_POST['fp_username'] ?? '');
    if (empty($fp_username)) {
        $error = 'Please enter your username to submit a reset request.';
    } else {
        $success = 'Your password reset request has been noted for <strong>'
                 . htmlspecialchars($fp_username)
                 . '</strong>. Please visit the library counter or contact a librarian for assistance.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SSCR Manila — Library Portal Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="login-wrapper">
    <div class="login-bg-overlay"></div>

    <div class="login-card">

        <!-- School Logo -->
        <div class="login-logo">
            <a href="login.php" style="text-decoration:none;display:inline-block;">
                <img src="school_logo.png" alt="SSC-R Manila Logo"
                     onerror="this.style.display='none'; document.getElementById('logoFallback').style.display='flex';">
                <div class="logo-fallback" id="logoFallback" style="display:none;"></div>
            </a>
        </div>

        <div class="school-name">
            San Sebastian College — Recoletos Manila<br>
            <span style="font-size:0.9em;">Saint Thomas of Villanova Library</span>
        </div>
        <div class="school-location">Manila, Philippines &nbsp;·&nbsp; Est. 1941</div>

        <div class="sscr-divider"><span></span><span></span></div>

        <h1>Library Portal</h1>
        <p class="welcome-text">
            Welcome, Sebastinians! <br>
            Sign in with your school credentials to access the library system.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php endif; ?>

        <?php if ($error && ($_POST['action'] ?? '') === 'login'): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Login Form -->
        <form action="" method="POST" autocomplete="on">
            <input type="hidden" name="action" value="login">

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       placeholder="Enter your username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       placeholder="Enter your password" required>
            </div>

            <div style="text-align:right;margin-bottom:1rem;">
                <span class="forgot-link" onclick="openForgotModal()"
                      style="font-size:0.83rem;color:var(--red);cursor:pointer;font-weight:600;">
                    Forgot your password?
                </span>
            </div>

            <button type="submit" class="btn btn-primary">Sign In →</button>
        </form>

        <!-- Register link -->
        <div class="divider" style="display:flex;align-items:center;gap:0.75rem;margin:1rem 0;color:var(--muted);font-size:0.82rem;">
            <span style="flex:1;height:1px;background:var(--border);display:block;"></span>
            or
            <span style="flex:1;height:1px;background:var(--border);display:block;"></span>
        </div>
        <div style="text-align:center;font-size:0.84rem;color:var(--muted);">
            No account yet? <a href="register.php" style="color:var(--red);font-weight:700;">Register with your school email →</a>
        </div>

        <!-- Demo accounts (remove in production) -->
        <div style="margin-top:1rem;background:#f4f6f9;border-radius:8px;padding:0.8rem;text-align:left;font-size:0.82rem;">
            <strong>Demo Accounts:</strong><br>
            Librarian: <code>librarian</code> / <code>admin123</code><br>
            Student: <code>student</code> / <code>student123</code>
        </div>

    </div>

    <div style="text-align:center;color:rgba(255,255,255,0.5);font-size:0.78rem;margin-top:1.2rem;position:relative;z-index:1;">
        &copy; <?= date('Y') ?> San Sebastian College Recoletos Manila &nbsp;&middot;&nbsp; Library Management System
    </div>

</div>

<!-- ============================================================
     FORGOT PASSWORD MODAL
     ============================================================ -->
<div class="modal-overlay" id="forgotModal">
    <div class="modal" style="max-width:400px;">
        <div style="text-align:center;font-size:2.5rem;margin-bottom:0.5rem;"></div>
        <h3 style="text-align:center;">Forgot Password?</h3>
        <p style="font-size:0.88rem;color:var(--muted);text-align:center;margin-bottom:1rem;">
            Enter your username and a librarian will assist you at the library counter.
        </p>

        <?php if ($error && ($_POST['action'] ?? '') === 'forgot'): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php endif; ?>

        <form action="" method="POST">
            <input type="hidden" name="action" value="forgot">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="fp_username" placeholder="Enter your username" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" onclick="closeForgotModal()">Close</button>
                <button type="submit" class="btn btn-yellow">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<script>
function openForgotModal()  { document.getElementById('forgotModal').classList.add('active'); }
function closeForgotModal() { document.getElementById('forgotModal').classList.remove('active'); }
</script>

</body>
</html>