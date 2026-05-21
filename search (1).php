<?php
// ============================================================
// search.php — OPAC for Students
// Features: Search books + View personal borrow history
// ============================================================

session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
if ($_SESSION['role'] !== 'student') { header('Location: dashboard.php'); exit(); }

$student_id = $_SESSION['user_id'];

// Active tab
$active_tab = $_GET['tab'] ?? 'catalog';

// ============================================================
// FETCH BOOKS
// ============================================================
$search = trim($_GET['search'] ?? '');
$genre  = trim($_GET['genre']  ?? '');
$sql    = "SELECT * FROM books WHERE 1=1";
$params = []; $types = '';

if (!empty($search)) {
    $sql .= " AND (title LIKE ? OR author LIKE ? OR isbn LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like]; $types .= 'sss';
}
if (!empty($genre)) {
    $sql .= " AND genre = ?";
    $params[] = $genre; $types .= 's';
}
$sql .= " ORDER BY title ASC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
    $books_result = $stmt->get_result();
} else {
    $books_result = $conn->query($sql);
}

$genres_result = $conn->query("SELECT DISTINCT genre FROM books WHERE genre != '' ORDER BY genre ASC");
$genres        = $genres_result->fetch_all(MYSQLI_ASSOC);

// ============================================================
// FETCH STUDENT'S BORROW HISTORY
// ============================================================
$history_sql = "
    SELECT t.transaction_id, t.borrowed_date, t.due_date, t.returned_date, t.quantity, t.status,
           b.title AS book_title, b.isbn, b.author,
           l.id AS librarian_id, l.username AS librarian_username, l.full_name AS librarian_name
    FROM transactions t
    JOIN books b ON t.book_id = b.id
    JOIN users l ON t.librarian_id = l.id
    WHERE t.student_id = ?
    ORDER BY t.borrowed_date DESC
";
$hist_stmt = $conn->prepare($history_sql);
$hist_stmt->bind_param("i", $student_id);
$hist_stmt->execute();
$history_result = $hist_stmt->get_result();

// Student info
$student_info = $conn->prepare("SELECT id, username, full_name FROM users WHERE id = ?");
$student_info->bind_param("i", $student_id);
$student_info->execute();
$student = $student_info->get_result()->fetch_assoc();

// Counts
$total_borrowed = $conn->prepare("SELECT COUNT(*) as c FROM transactions WHERE student_id = ?");
$total_borrowed->bind_param("i", $student_id); $total_borrowed->execute();
$borrow_count = $total_borrowed->get_result()->fetch_assoc()['c'];

$active_loans = $conn->prepare("SELECT COUNT(*) as c FROM transactions WHERE student_id = ? AND status = 'borrowed'");
$active_loans->bind_param("i", $student_id); $active_loans->execute();
$active_count = $active_loans->get_result()->fetch_assoc()['c'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Catalog — St. Thomas of Villanova College Library</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- ==================== OPAC HERO ==================== -->
<div class="opac-hero">
    <div style="display:flex;align-items:center;justify-content:center;gap:0.7rem;margin-bottom:0.4rem;">
        <img src="school_logo.png" alt="SSCR Logo"
             style="width:52px;height:52px;border-radius:50%;border:2px solid var(--yellow-l);background:rgba(255,255,255,0.12);padding:4px;object-fit:contain;"
             onerror="this.style.display='none'">
        <div style="text-align:left;">
            <div style="font-size:0.74rem;opacity:0.7;letter-spacing:0.07em;text-transform:uppercase;">San Sebastian College Recoletos Manila</div>
            <h2 style="margin:0;font-size:1.4rem;">St. Thomas of Villanova College Library</h2>
        </div>
    </div>
    <p>Search for books by title, author, or ISBN. Browse what's available today.</p>

    <form action="" method="GET">
        <input type="hidden" name="tab" value="catalog">
        <div class="search-bar">
            <input type="text" name="search" placeholder="Search by title, author, or ISBN…"
                   value="<?= htmlspecialchars($search) ?>" autofocus>
            <select name="genre">
                <option value="">All Genres</option>
                <?php foreach ($genres as $g): ?>
                    <option value="<?= htmlspecialchars($g['genre']) ?>" <?= $genre === $g['genre'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($g['genre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Search</button>
        </div>
    </form>
</div>

<!-- ==================== TAB NAVIGATION ==================== -->
<nav class="tab-nav">
    <a href="?tab=catalog"  class="<?= $active_tab === 'catalog'  ? 'active' : '' ?>">Book Catalog</a>
    <a href="?tab=history"  class="<?= $active_tab === 'history'  ? 'active' : '' ?>">My Borrow History</a>
    <div style="margin-left:auto;display:flex;align-items:center;gap:1rem;padding:0 0.5rem;">
        <span style="font-size:0.85rem;color:var(--muted);">
            <?= htmlspecialchars($student['full_name'] ?? $student['username']) ?>
            <span class="nav-badge" style="background:var(--red);color:var(--white);padding:0.15rem 0.6rem;border-radius:20px;font-size:0.72rem;margin-left:0.3rem;">Student</span>
        </span>
        <a href="logout.php" style="font-size:0.85rem;color:var(--red);font-weight:600;">Logout →</a>
    </div>
</nav>

<!-- ==================== MAIN CONTENT ==================== -->
<main class="main-content">

    <!-- ================================================
         TAB: CATALOG
         ================================================ -->
    <?php if ($active_tab === 'catalog'): ?>

    <div class="page-header">
        <h2 style="font-size:1.1rem;color:#555;">
            <?php if ($search || $genre): ?>
                <?= $books_result->num_rows ?> result(s)
                <?= $search ? 'for "<em>' . htmlspecialchars($search) . '</em>"' : '' ?>
                <?= $genre  ? 'in <em>' . htmlspecialchars($genre)  . '</em>'   : '' ?>
            <?php else: ?>
                All Books (<?= $books_result->num_rows ?>)
            <?php endif; ?>
        </h2>
        <?php if ($search || $genre): ?>
            <a href="?tab=catalog" class="btn btn-cancel btn-small">✕ Clear Search</a>
        <?php endif; ?>
    </div>

    <?php if ($books_result->num_rows === 0): ?>
        <div class="no-results">
            <span class="no-results-icon"></span>
            <p>No books found. Try a different keyword or <a href="?tab=catalog">browse all books</a>.</p>
        </div>
    <?php else: ?>
        <div class="book-grid">
            <?php while ($book = $books_result->fetch_assoc()):
                $avail = intval($book['copies_available']);
            ?>
            <div class="book-card">
                <div class="book-title"><?= htmlspecialchars($book['title']) ?></div>
                <div class="book-author">by <?= htmlspecialchars($book['author']) ?></div>
                <?php if ($book['genre']): ?>
                    <div class="book-meta"><span><?= htmlspecialchars($book['genre']) ?></span></div>
                <?php endif; ?>
                <?php if ($book['year_published']): ?>
                    <div class="book-meta"><span><?= $book['year_published'] ?></span></div>
                <?php endif; ?>
                <?php if ($book['isbn']): ?>
                    <div class="book-meta"><span><?= htmlspecialchars($book['isbn']) ?></span></div>
                <?php endif; ?>
                <div style="margin-top:0.75rem;">
                    <?php if ($avail === 0): ?>
                        <span class="badge badge-none">Not Available</span>
                    <?php elseif ($avail === 1): ?>
                        <span class="badge badge-low">Last Copy</span>
                    <?php else: ?>
                        <span class="badge badge-available">Available (<?= $avail ?>)</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-info mt-2" style="font-size:0.88rem;">
        <strong>To borrow a book</strong>, please visit the library counter and show this page to the librarian.
    </div>

    <?php endif; /* end tab: catalog */ ?>

    <!-- ================================================
         TAB: BORROW HISTORY
         ================================================ -->
    <?php if ($active_tab === 'history'): ?>

    <!-- Student ID Card -->
    <div class="student-id-box">
        <div class="sid-icon"></div>
        <div>
            <div class="sid-label">Student Account</div>
            <div class="sid-name"><?= htmlspecialchars($student['full_name'] ?? $student['username']) ?></div>
            <div class="sid-id">Student ID: #<?= str_pad($student['id'], 5, '0', STR_PAD_LEFT) ?> &nbsp;|&nbsp; Username: <?= htmlspecialchars($student['username']) ?></div>
        </div>
        <div style="margin-left:auto;text-align:right;">
            <div style="font-size:1.6rem;font-weight:700;color:var(--yellow-l);"><?= $active_count ?></div>
            <div style="font-size:0.72rem;opacity:0.75;text-transform:uppercase;">Active Loans</div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:1.6rem;font-weight:700;color:var(--yellow-l);"><?= $borrow_count ?></div>
            <div style="font-size:0.72rem;opacity:0.75;text-transform:uppercase;">Total Borrowed</div>
        </div>
    </div>

    <div class="card">
        <h3>My Borrow History</h3>

        <?php if ($history_result->num_rows === 0): ?>
            <div class="no-results">
                <span class="no-results-icon"></span>
                <p>You haven't borrowed any books yet.</p>
                <p><a href="?tab=catalog">Browse the catalog</a> and ask the librarian to get started!</p>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Transaction ID</th>
                        <th>Book Title</th>
                        <th>Author</th>
                        <th>ISBN</th>
                        <th>Qty</th>
                        <th>Borrowed Date</th>
                        <th>Due Date</th>
                        <th>Returned Date</th>
                        <th>Assisted by Librarian</th>
                        <th>Librarian ID</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($h = $history_result->fetch_assoc()):
                    $is_overdue = ($h['status'] === 'borrowed' && strtotime($h['due_date']) < time());
                    $status_display = $is_overdue ? 'overdue' : $h['status'];
                    $badge_class    = 'badge-' . $status_display;
                    $status_labels  = ['borrowed'=>'Borrowed','returned'=>'Returned','overdue'=>'Overdue'];
                ?>
                <tr>
                    <td><strong>#<?= $h['transaction_id'] ?></strong></td>
                    <td><?= htmlspecialchars($h['book_title']) ?></td>
                    <td><?= htmlspecialchars($h['author']) ?></td>
                    <td><?= htmlspecialchars($h['isbn']) ?: '—' ?></td>
                    <td><?= $h['quantity'] ?></td>
                    <td><?= date('M d, Y', strtotime($h['borrowed_date'])) ?></td>
                    <td><?= date('M d, Y', strtotime($h['due_date'])) ?></td>
                    <td><?= $h['returned_date'] ? date('M d, Y', strtotime($h['returned_date'])) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= htmlspecialchars($h['librarian_name'] ?? $h['librarian_username']) ?></td>
                    <td><code>#<?= str_pad($h['librarian_id'], 5, '0', STR_PAD_LEFT) ?></code></td>
                    <td><span class="badge <?= $badge_class ?>"><?= $status_labels[$status_display] ?? ucfirst($status_display) ?></span></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="alert alert-info" style="font-size:0.88rem;">
        If you have questions about your borrowed books or due dates, please visit the library counter.
    </div>

    <?php endif; /* end tab: history */ ?>

</main>

</body>
</html>