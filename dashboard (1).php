<?php
// ============================================================
// dashboard.php — St. Thomas of Villanova College Library
// Roles: Librarian only
// Tabs: Book Inventory | Transactions
// ============================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
if ($_SESSION['role'] !== 'librarian') { header('Location: search.php'); exit(); }

$message  = '';
$msg_type = '';

function clean(string $val): string {
    return htmlspecialchars(strip_tags(trim($val)));
}

// ============================================================
// HANDLE POST ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- ADD BOOK ----
    if ($action === 'add') {
        $title     = clean($_POST['title']);
        $author    = clean($_POST['author']);
        $isbn      = clean($_POST['isbn']);
        $genre     = clean($_POST['genre']);
        $year      = intval($_POST['year_published'] ?? 0);
        $total     = max(1, intval($_POST['copies_total'] ?? 1));
        $available = max(0, intval($_POST['copies_available'] ?? $total));

        if (empty($title) || empty($author)) {
            $message = 'Title and Author are required.'; $msg_type = 'error';
        } else {
            $stmt = $conn->prepare("INSERT INTO books (title, author, isbn, genre, year_published, copies_total, copies_available) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssiii", $title, $author, $isbn, $genre, $year, $total, $available);
            if ($stmt->execute()) { $message = "Book <strong>".htmlspecialchars($title)."</strong> added!"; $msg_type = 'success'; }
            else { $message = 'Error: ' . $conn->error; $msg_type = 'error'; }
            $stmt->close();
        }
    }

    // ---- EDIT BOOK ----
    elseif ($action === 'edit') {
        $id        = intval($_POST['book_id']);
        $title     = clean($_POST['title']);
        $author    = clean($_POST['author']);
        $isbn      = clean($_POST['isbn']);
        $genre     = clean($_POST['genre']);
        $year      = intval($_POST['year_published'] ?? 0);
        $total     = max(1, intval($_POST['copies_total'] ?? 1));
        $available = max(0, intval($_POST['copies_available'] ?? 0));

        if (empty($title) || empty($author)) {
            $message = 'Title and Author are required.'; $msg_type = 'error';
        } else {
            $stmt = $conn->prepare("UPDATE books SET title=?, author=?, isbn=?, genre=?, year_published=?, copies_total=?, copies_available=? WHERE id=?");
            $stmt->bind_param("ssssiii i", $title, $author, $isbn, $genre, $year, $total, $available, $id);
            if ($stmt->execute()) { $message = "Book updated successfully."; $msg_type = 'success'; }
            else { $message = 'Error: ' . $conn->error; $msg_type = 'error'; }
            $stmt->close();
        }
    }

    // ---- DELETE BOOK ----
    elseif ($action === 'delete') {
        $id = intval($_POST['book_id']);
        $stmt = $conn->prepare("DELETE FROM books WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) { $message = 'Book deleted successfully.'; $msg_type = 'success'; }
        else { $message = 'Error: ' . $conn->error; $msg_type = 'error'; }
        $stmt->close();
    }

    // ---- ADD TRANSACTION ----
    elseif ($action === 'add_transaction') {
        $student_id   = intval($_POST['student_id']);
        $book_id      = intval($_POST['book_id_tx']);
        $librarian_id = intval($_SESSION['user_id']);
        $due_date     = clean($_POST['due_date']);
        $quantity     = max(1, intval($_POST['quantity'] ?? 1));

        if (!$student_id || !$book_id || empty($due_date)) {
            $message = 'Student, Book, and Due Date are required.'; $msg_type = 'error';
        } else {
            $avail_res = $conn->prepare("SELECT copies_available FROM books WHERE id = ?");
            $avail_res->bind_param("i", $book_id);
            $avail_res->execute();
            $avail_row = $avail_res->get_result()->fetch_assoc();
            $avail_res->close();

            if (!$avail_row || $avail_row['copies_available'] < $quantity) {
                $message = 'Not enough copies available.'; $msg_type = 'error';
            } else {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("INSERT INTO transactions (student_id, book_id, librarian_id, due_date, quantity, status) VALUES (?, ?, ?, ?, ?, 'borrowed')");
                    $stmt->bind_param("iiisi", $student_id, $book_id, $librarian_id, $due_date, $quantity);
                    $stmt->execute(); $stmt->close();
                    $upd = $conn->prepare("UPDATE books SET copies_available = copies_available - ? WHERE id = ?");
                    $upd->bind_param("ii", $quantity, $book_id);
                    $upd->execute(); $upd->close();
                    $conn->commit();
                    $message = 'Transaction recorded!'; $msg_type = 'success';
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = 'Error: ' . $e->getMessage(); $msg_type = 'error';
                }
            }
        }
    }

    // ---- RETURN BOOK ----
    elseif ($action === 'return_book') {
        $tx_id = intval($_POST['transaction_id']);
        $tx_res = $conn->prepare("SELECT book_id, quantity, status FROM transactions WHERE transaction_id = ?");
        $tx_res->bind_param("i", $tx_id);
        $tx_res->execute();
        $tx_row = $tx_res->get_result()->fetch_assoc();
        $tx_res->close();

        if (!$tx_row || $tx_row['status'] === 'returned') {
            $message = 'Transaction not found or already returned.'; $msg_type = 'error';
        } else {
            $conn->begin_transaction();
            try {
                $upd = $conn->prepare("UPDATE transactions SET status='returned', returned_date=NOW() WHERE transaction_id=?");
                $upd->bind_param("i", $tx_id); $upd->execute(); $upd->close();
                $restore = $conn->prepare("UPDATE books SET copies_available = copies_available + ? WHERE id = ?");
                $restore->bind_param("ii", $tx_row['quantity'], $tx_row['book_id']);
                $restore->execute(); $restore->close();
                $conn->commit();
                $message = 'Book marked as returned!'; $msg_type = 'success';
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Error: ' . $e->getMessage(); $msg_type = 'error';
            }
        }
    }

    // Redirect back to correct tab after POST
    $redirect_tab = $_POST['tab_redirect'] ?? ($_POST['action'] === 'add_transaction' || $_POST['action'] === 'return_book' ? 'transactions' : 'books');
    header('Location: dashboard.php?tab=' . $redirect_tab . ($message ? '&msg=' . urlencode($message) . '&mt=' . $msg_type : ''));
    exit();
}

// Show message from redirect
if (isset($_GET['msg'])) { $message = htmlspecialchars($_GET['msg']); $msg_type = $_GET['mt'] ?? 'success'; }

// ============================================================
// ACTIVE TAB
// ============================================================
$active_tab = $_GET['tab'] ?? 'books';

// ============================================================
// FETCH BOOKS
// ============================================================
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';
$sql    = "SELECT * FROM books WHERE 1=1";
$params = []; $types = '';

if (!empty($search)) {
    $sql .= " AND (title LIKE ? OR author LIKE ? OR isbn LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like]; $types .= 'sss';
}
if ($filter === 'available')   $sql .= " AND copies_available > 0";
if ($filter === 'unavailable') $sql .= " AND copies_available = 0";
$sql .= " ORDER BY title ASC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
    $books_result = $stmt->get_result();
} else {
    $books_result = $conn->query($sql);
}

// Stats
$total_books  = $conn->query("SELECT COUNT(*) as c FROM books")->fetch_assoc()['c'];
$total_copies = $conn->query("SELECT SUM(copies_total) as c FROM books")->fetch_assoc()['c'] ?? 0;
$avail_copies = $conn->query("SELECT SUM(copies_available) as c FROM books")->fetch_assoc()['c'] ?? 0;
$total_genres = $conn->query("SELECT COUNT(DISTINCT genre) as c FROM books WHERE genre != ''")->fetch_assoc()['c'];

// ============================================================
// FETCH TRANSACTIONS
// ============================================================
$tx_search = trim($_GET['tx_search'] ?? '');
$tx_status = trim($_GET['tx_status'] ?? '');

$tx_sql = "
    SELECT t.transaction_id, t.borrowed_date, t.due_date, t.returned_date, t.quantity, t.status,
           b.title AS book_title, b.isbn,
           s.id AS student_id, s.username AS student_username, s.full_name AS student_name,
           l.id AS librarian_id, l.username AS librarian_username, l.full_name AS librarian_name
    FROM transactions t
    JOIN books b ON t.book_id = b.id
    JOIN users s ON t.student_id = s.id
    JOIN users l ON t.librarian_id = l.id
    WHERE 1=1
";
$tx_params = []; $tx_types = '';

if (!empty($tx_search)) {
    $tx_sql .= " AND (b.title LIKE ? OR s.username LIKE ? OR s.full_name LIKE ? OR b.isbn LIKE ?)";
    $lk = '%' . $tx_search . '%';
    $tx_params = array_merge($tx_params, [$lk, $lk, $lk, $lk]); $tx_types .= 'ssss';
}
if (!empty($tx_status)) {
    $tx_sql .= " AND t.status = ?";
    $tx_params[] = $tx_status; $tx_types .= 's';
}
$tx_sql .= " ORDER BY t.borrowed_date DESC";

if (!empty($tx_params)) {
    $tx_stmt = $conn->prepare($tx_sql); $tx_stmt->bind_param($tx_types, ...$tx_params); $tx_stmt->execute();
    $tx_result = $tx_stmt->get_result();
} else {
    $tx_result = $conn->query($tx_sql);
}

$tx_total    = $conn->query("SELECT COUNT(*) as c FROM transactions")->fetch_assoc()['c'];
$tx_borrowed = $conn->query("SELECT COUNT(*) as c FROM transactions WHERE status='borrowed'")->fetch_assoc()['c'];
$tx_returned = $conn->query("SELECT COUNT(*) as c FROM transactions WHERE status='returned'")->fetch_assoc()['c'];
$tx_overdue  = $conn->query("SELECT COUNT(*) as c FROM transactions WHERE status='borrowed' AND due_date < NOW()")->fetch_assoc()['c'];

$students_list = $conn->query("SELECT id, username, full_name FROM users WHERE role='student' ORDER BY username ASC");
$books_list    = $conn->query("SELECT id, title, isbn, copies_available FROM books WHERE copies_available > 0 ORDER BY title ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — St. Thomas of Villanova College Library</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- ==================== HEADER ==================== -->
<header class="dashboard-header">
    <div style="display:flex;align-items:center;gap:1rem;">
        <img src="school_logo.png" alt="SSCR Logo"
             style="width:48px;height:48px;border-radius:50%;border:2px solid var(--yellow-l);object-fit:contain;background:rgba(255,255,255,0.15);padding:3px;"
             onerror="this.style.display='none'">
        <div>
            <h1>St. Thomas of Villanova College Library</h1>
            <p class="subtitle">San Sebastian College Recoletos Manila</p>
        </div>
    </div>
    <div class="hdr-right">
        <div class="hdr-user">
            <?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']) ?>
            <span class="nav-badge">Librarian</span>
        </div>
        <a href="logout.php" class="btn btn-small btn-danger">Logout →</a>
    </div>
</header>

<!-- ==================== TAB NAVIGATION ==================== -->
<nav class="tab-nav">
    <a href="?tab=books"        class="<?= $active_tab === 'books'        ? 'active' : '' ?>">📖 Book Inventory</a>
    <a href="?tab=transactions" class="<?= $active_tab === 'transactions' ? 'active' : '' ?>">📋 Transactions</a>
</nav>

<!-- ==================== MAIN CONTENT ==================== -->
<main class="main-content">

    <?php if ($message): ?>
        <div class="alert alert-<?= $msg_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- ================================================
         TAB: BOOKS
         ================================================ -->
    <?php if ($active_tab === 'books'): ?>

    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon"></div>
            <div class="stat-info"><div class="stat-number"><?= $total_books ?></div><div class="stat-label">Book Titles</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"></div>
            <div class="stat-info"><div class="stat-number"><?= $total_copies ?></div><div class="stat-label">Total Copies</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"></div>
            <div class="stat-info"><div class="stat-number"><?= $avail_copies ?></div><div class="stat-label">Available</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"></div>
            <div class="stat-info"><div class="stat-number"><?= $total_genres ?></div><div class="stat-label">Genres</div></div>
        </div>
    </div>

    <div class="card">
        <h3>Book Inventory</h3>

        <!-- Filter Bar — separate form, no conflict with edit button -->
        <form method="GET" action="" id="filterForm">
            <input type="hidden" name="tab" value="books">
            <div class="filter-bar">
                <input type="text" name="search" placeholder="Search title, author, ISBN…" value="<?= htmlspecialchars($search) ?>">
                <select name="filter">
                    <option value="all"         <?= $filter==='all'         ? 'selected':'' ?>>All Books</option>
                    <option value="available"   <?= $filter==='available'   ? 'selected':'' ?>>Available Only</option>
                    <option value="unavailable" <?= $filter==='unavailable' ? 'selected':'' ?>>Unavailable Only</option>
                </select>
                <button type="submit" class="btn btn-small btn-red">Filter</button>
                <?php if ($search || $filter !== 'all'): ?>
                    <a href="?tab=books" class="btn btn-small btn-cancel">✕ Clear</a>
                <?php endif; ?>
            </div>
        </form>
        <!-- Add Book button is OUTSIDE the filter form -->
        <div style="margin-bottom:1rem;">
            <button type="button" class="btn btn-small btn-add" onclick="openModal('add')">Add Book</button>
        </div>

        <div class="table-wrap">
            <?php if ($books_result->num_rows === 0): ?>
                <div class="no-results"><span class="no-results-icon">📭</span><p>No books found.</p></div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th><th>Title</th><th>Author</th><th>ISBN</th>
                        <th>Genre</th><th>Year</th><th>Avail / Total</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php $row_num = 1; while ($book = $books_result->fetch_assoc()):
                    $avail = intval($book['copies_available']);
                    $total = intval($book['copies_total']);
                    $badge = $avail === 0
                        ? '<span class="badge badge-none">Unavailable</span>'
                        : ($avail <= 1 ? '<span class="badge badge-low">Low Stock</span>' : '<span class="badge badge-available">Available</span>');
                ?>
                <tr>
                    <td><?= $row_num++ ?></td>
                    <td><strong><?= htmlspecialchars($book['title']) ?></strong></td>
                    <td><?= htmlspecialchars($book['author']) ?></td>
                    <td><?= htmlspecialchars($book['isbn']) ?: '<span class="text-muted">—</span>' ?></td>
                    <td><?= htmlspecialchars($book['genre']) ?: '<span class="text-muted">—</span>' ?></td>
                    <td><?= $book['year_published'] ?: '—' ?></td>
                    <td><?= $avail ?> / <?= $total ?></td>
                    <td><?= $badge ?></td>
                    <td>
                        <!-- Edit button is a plain button, NOT inside any form -->
                        <button type="button" class="btn btn-small btn-edit"
                            onclick="openEditModal(
                                <?= $book['id'] ?>,
                                <?= json_encode($book['title']) ?>,
                                <?= json_encode($book['author']) ?>,
                                <?= json_encode($book['isbn']) ?>,
                                <?= json_encode($book['genre']) ?>,
                                <?= intval($book['year_published']) ?>,
                                <?= intval($book['copies_total']) ?>,
                                <?= intval($book['copies_available']) ?>
                            )">Edit</button>
                        <!-- Delete is its own separate form -->
                        <form method="POST" action="dashboard.php" style="display:inline;"
                              onsubmit="return confirm('Delete this book? This cannot be undone.')">
                            <input type="hidden" name="action"       value="delete">
                            <input type="hidden" name="book_id"      value="<?= $book['id'] ?>">
                            <input type="hidden" name="tab_redirect" value="books">
                            <button type="submit" class="btn btn-small btn-delete">🗑 Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

    <!-- ================================================
         TAB: TRANSACTIONS
         ================================================ -->
    <?php if ($active_tab === 'transactions'): ?>

    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon"></div>
            <div class="stat-info"><div class="stat-number"><?= $tx_total ?></div><div class="stat-label">Total</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"></div>
            <div class="stat-info"><div class="stat-number"><?= $tx_borrowed ?></div><div class="stat-label">Borrowed</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"></div>
            <div class="stat-info"><div class="stat-number"><?= $tx_returned ?></div><div class="stat-label">Returned</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"></div>
            <div class="stat-info">
                <div class="stat-number" style="color:<?= $tx_overdue > 0 ? 'var(--red)' : 'var(--success)' ?>"><?= $tx_overdue ?></div>
                <div class="stat-label">Overdue</div>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>Borrow Transactions</h3>

        <form method="GET" action="">
            <input type="hidden" name="tab" value="transactions">
            <div class="filter-bar">
                <input type="text" name="tx_search" placeholder="Search borrower, book title, ISBN…" value="<?= htmlspecialchars($tx_search) ?>">
                <select name="tx_status">
                    <option value="">All Status</option>
                    <option value="borrowed" <?= $tx_status==='borrowed'?'selected':'' ?>>Borrowed</option>
                    <option value="returned" <?= $tx_status==='returned'?'selected':'' ?>>Returned</option>
                    <option value="overdue"  <?= $tx_status==='overdue' ?'selected':'' ?>>Overdue</option>
                </select>
                <button type="submit" class="btn btn-small btn-red">Filter</button>
                <?php if ($tx_search || $tx_status): ?>
                    <a href="?tab=transactions" class="btn btn-small btn-cancel">✕ Clear</a>
                <?php endif; ?>
            </div>
        </form>
        <div style="margin-bottom:1rem;">
            <button type="button" class="btn btn-small btn-add" onclick="openModal('addTx')">New Transaction</button>
        </div>

        <div class="table-wrap">
            <?php if ($tx_result->num_rows === 0): ?>
                <div class="no-results"><span class="no-results-icon">📭</span><p>No transactions found.</p></div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>TX ID</th><th>Student</th><th>Student ID</th>
                        <th>Book Title</th><th>ISBN</th><th>Qty</th>
                        <th>Borrowed</th><th>Due Date</th><th>Returned</th>
                        <th>Librarian</th><th>Lib. ID</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($tx = $tx_result->fetch_assoc()):
                    $is_overdue     = ($tx['status'] === 'borrowed' && strtotime($tx['due_date']) < time());
                    $status_display = $is_overdue ? 'overdue' : $tx['status'];
                    $status_labels  = ['borrowed'=>'Borrowed','returned'=>'Returned','overdue'=>'Overdue'];
                ?>
                <tr>
                    <td><strong>#<?= $tx['transaction_id'] ?></strong></td>
                    <td><?= htmlspecialchars($tx['student_name'] ?? $tx['student_username']) ?></td>
                    <td><code>#<?= str_pad($tx['student_id'], 5, '0', STR_PAD_LEFT) ?></code></td>
                    <td><?= htmlspecialchars($tx['book_title']) ?></td>
                    <td><?= htmlspecialchars($tx['isbn']) ?: '—' ?></td>
                    <td><?= $tx['quantity'] ?></td>
                    <td><?= date('M d, Y', strtotime($tx['borrowed_date'])) ?></td>
                    <td><?= date('M d, Y', strtotime($tx['due_date'])) ?></td>
                    <td><?= $tx['returned_date'] ? date('M d, Y', strtotime($tx['returned_date'])) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= htmlspecialchars($tx['librarian_name'] ?? $tx['librarian_username']) ?></td>
                    <td><code>#<?= str_pad($tx['librarian_id'], 5, '0', STR_PAD_LEFT) ?></code></td>
                    <td><span class="badge badge-<?= $status_display ?>"><?= $status_labels[$status_display] ?></span></td>
                    <td>
                        <?php if ($tx['status'] !== 'returned'): ?>
                            <form method="POST" action="dashboard.php"
                                  onsubmit="return confirm('Mark this book as returned?')">
                                <input type="hidden" name="action"         value="return_book">
                                <input type="hidden" name="transaction_id" value="<?= $tx['transaction_id'] ?>">
                                <input type="hidden" name="tab_redirect"   value="transactions">
                                <button type="submit" class="btn-return">Return Book</button>
                            </form>
                        <?php else: ?>
                            <span class="btn-returned">Completed</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

</main>

<!-- ============================================================
     MODAL: ADD BOOK
     ============================================================ -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <h3>Add New Book</h3>
        <form method="POST" action="dashboard.php">
            <input type="hidden" name="action"       value="add">
            <input type="hidden" name="tab_redirect" value="books">
            <div class="form-grid">
                <div class="form-group full-width">
                    <label>Title *</label>
                    <input type="text" name="title" placeholder="Book title" required>
                </div>
                <div class="form-group full-width">
                    <label>Author *</label>
                    <input type="text" name="author" placeholder="Author name" required>
                </div>
                <div class="form-group">
                    <label>ISBN</label>
                    <input type="text" name="isbn" placeholder="978-x-xxx-xxxxx-x">
                </div>
                <div class="form-group">
                    <label>Genre</label>
                    <input type="text" name="genre" placeholder="Fiction, Science…">
                </div>
                <div class="form-group">
                    <label>Year Published</label>
                    <input type="number" name="year_published" placeholder="2024" min="1000" max="2099">
                </div>
                <div class="form-group">
                    <label>Total Copies</label>
                    <input type="number" name="copies_total" value="1" min="1" required>
                </div>
                <div class="form-group full-width">
                    <label>Copies Available</label>
                    <input type="number" name="copies_available" value="1" min="0" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-add">Add Book</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     MODAL: EDIT BOOK
     ============================================================ -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <h3>✏️ Edit Book</h3>
        <form method="POST" action="dashboard.php">
            <input type="hidden" name="action"       value="edit">
            <input type="hidden" name="tab_redirect" value="books">
            <input type="hidden" name="book_id"      id="edit_id">
            <div class="form-grid">
                <div class="form-group full-width">
                    <label>Title *</label>
                    <input type="text" name="title" id="edit_title" required>
                </div>
                <div class="form-group full-width">
                    <label>Author *</label>
                    <input type="text" name="author" id="edit_author" required>
                </div>
                <div class="form-group">
                    <label>ISBN</label>
                    <input type="text" name="isbn" id="edit_isbn">
                </div>
                <div class="form-group">
                    <label>Genre</label>
                    <input type="text" name="genre" id="edit_genre">
                </div>
                <div class="form-group">
                    <label>Year Published</label>
                    <input type="number" name="year_published" id="edit_year" min="1000" max="2099">
                </div>
                <div class="form-group">
                    <label>Total Copies</label>
                    <input type="number" name="copies_total" id="edit_total" min="1" required>
                </div>
                <div class="form-group full-width">
                    <label>Copies Available</label>
                    <input type="number" name="copies_available" id="edit_available" min="0" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-edit">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     MODAL: ADD TRANSACTION
     ============================================================ -->
<div class="modal-overlay" id="addTxModal">
    <div class="modal">
        <h3>New Borrow Transaction</h3>
        <form method="POST" action="dashboard.php">
            <input type="hidden" name="action"       value="add_transaction">
            <input type="hidden" name="tab_redirect" value="transactions">
            <div class="form-grid">
                <div class="form-group full-width">
                    <label>Student (Borrower) *</label>
                    <select name="student_id" required>
                        <option value="">-- Select Student --</option>
                        <?php $students_list->data_seek(0); while ($s = $students_list->fetch_assoc()): ?>
                            <option value="<?= $s['id'] ?>">
                                [ID #<?= str_pad($s['id'],5,'0',STR_PAD_LEFT) ?>] <?= htmlspecialchars($s['full_name'] ?? $s['username']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group full-width">
                    <label>Book to Borrow *</label>
                    <select name="book_id_tx" required>
                        <option value="">-- Select Book --</option>
                        <?php $books_list->data_seek(0); while ($bk = $books_list->fetch_assoc()): ?>
                            <option value="<?= $bk['id'] ?>">
                                <?= htmlspecialchars($bk['title']) ?> (Avail: <?= $bk['copies_available'] ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quantity *</label>
                    <input type="number" name="quantity" value="1" min="1" max="10" required>
                </div>
                <div class="form-group">
                    <label>Due Date *</label>
                    <input type="datetime-local" name="due_date" required>
                </div>
                <div class="form-group full-width">
                    <label>Assisted by Librarian</label>
                    <input type="text" value="<?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']) ?> [ID: #<?= str_pad($_SESSION['user_id'],5,'0',STR_PAD_LEFT) ?>]" readonly style="background:#f7f2f2;color:var(--muted);">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" onclick="closeModal('addTxModal')">Cancel</button>
                <button type="submit" class="btn btn-add">Record Transaction</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(type) {
    document.getElementById(type + 'Modal').classList.add('active');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
function openEditModal(id, title, author, isbn, genre, year, total, available) {
    document.getElementById('edit_id').value        = id;
    document.getElementById('edit_title').value     = title;
    document.getElementById('edit_author').value    = author;
    document.getElementById('edit_isbn').value      = isbn;
    document.getElementById('edit_genre').value     = genre;
    document.getElementById('edit_year').value      = year;
    document.getElementById('edit_total').value     = total;
    document.getElementById('edit_available').value = available;
    document.getElementById('editModal').classList.add('active');
}
// Close modal when clicking outside
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.classList.remove('active');
    });
});
</script>
</body>
</html>