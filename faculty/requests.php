<?php
require_once __DIR__ . '/../includes/session.php';
require_login(['adviser']);

$user = current_user();
$error = null;
$success = null;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $request_id = (int)($_POST['request_id'] ?? 0);

    if ($request_id > 0) {
        try {
            $pdo->beginTransaction();

            // Lock the request
            $reqStmt = $pdo->prepare("SELECT student_id, status FROM adviser_requests WHERE id = ? AND adviser_id = ? FOR UPDATE");
            $reqStmt->execute([$request_id, $user['id']]);
            $reqData = $reqStmt->fetch();

            if (!$reqData || $reqData['status'] !== 'pending') {
                throw new Exception("Request not found or no longer pending.");
            }

            if ($action === 'accept') {
                // Lock the adviser row to safely check max_advisees
                $stmt = $pdo->prepare("SELECT max_advisees, (SELECT COUNT(*) FROM users WHERE adviser_id = ?) as current_advisees FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$user['id'], $user['id']]);
                $adviserData = $stmt->fetch();

                if ((int)$adviserData['current_advisees'] >= (int)$adviserData['max_advisees']) {
                    throw new Exception("You have reached your maximum number of advisees.");
                }

                // Update request
                $pdo->prepare("UPDATE adviser_requests SET status = 'approved' WHERE id = ?")->execute([$request_id]);
                
                // Assign adviser to student (this handles both new students and 'change adviser' replacements)
                $pdo->prepare("UPDATE users SET adviser_id = ? WHERE id = ?")->execute([$user['id'], $reqData['student_id']]);
                
                // Notify student of approval
                $notifyMsg = "Prof. " . htmlspecialchars($user['last_name']) . " has accepted your adviser request!";
                $pdo->prepare("INSERT INTO notifications (recipient_user_id, sender_user_id, type, message) VALUES (?, ?, 'adviser_request_approved', ?)")
                    ->execute([$reqData['student_id'], $user['id'], $notifyMsg]);
                
                $success = "Student request approved. They have been added to your advisees.";
            } elseif ($action === 'reject') {
                $pdo->prepare("UPDATE adviser_requests SET status = 'rejected' WHERE id = ?")->execute([$request_id]);
                
                // Notify student of rejection
                $notifyMsg = "Prof. " . htmlspecialchars($user['last_name']) . " has declined your adviser request. You can request another adviser.";
                $pdo->prepare("INSERT INTO notifications (recipient_user_id, sender_user_id, type, message) VALUES (?, ?, 'adviser_request_rejected', ?)")
                    ->execute([$reqData['student_id'], $user['id'], $notifyMsg]);
                
                $success = "Student request rejected.";
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

// Fetch current stats
$stmt = $pdo->prepare("SELECT max_advisees, (SELECT COUNT(*) FROM users WHERE adviser_id = ?) as current_advisees FROM users WHERE id = ?");
$stmt->execute([$user['id'], $user['id']]);
$stats = $stmt->fetch();
$current = (int)$stats['current_advisees'];
$max = (int)$stats['max_advisees'];
$isFull = $current >= $max;

// Fetch pending requests
$reqStmt = $pdo->prepare("
    SELECT ar.id, ar.created_at, u.first_name, u.last_name, u.college
    FROM adviser_requests ar
    JOIN users u ON ar.student_id = u.id
    WHERE ar.adviser_id = ? AND ar.status = 'pending'
    ORDER BY ar.created_at ASC
");
$reqStmt->execute([$user['id']]);
$requests = $reqStmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<style>
  .req-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.5rem;
    margin-bottom: 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: var(--shadow-sm);
  }
  .req-info h4 { margin-bottom: 0.25rem; font-size: 1.1rem; }
  .req-meta { font-size: 0.85rem; color: var(--text-muted); }
  .req-actions { display: flex; gap: 0.75rem; }
  
  .capacity-bar {
    background: var(--off-white);
    border-radius: 100px;
    height: 8px;
    width: 100%;
    margin-top: 0.5rem;
    overflow: hidden;
  }
  .capacity-fill {
    height: 100%;
    background: var(--crimson);
    transition: width 0.3s ease;
  }
  .capacity-fill.full {
    background: #DC2626;
  }
</style>
<?php
$extraCss = ob_get_clean();

$current_page = 'requests.php';
require_once __DIR__ . '/../includes/layout_top.php';
require_once __DIR__ . '/../includes/layout_sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-title">
      <h1>Advisee <span>Requests</span></h1>
      <p>Manage incoming requests from students who wish to select you as their adviser.</p>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error" style="background:#FEE2E2; color:#991B1B; padding:1rem; border-radius:4px; margin-bottom:1.5rem; border:1px solid #FECACA;">
      <i class="ph-bold ph-warning-circle"></i> <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>
  
  <?php if ($success): ?>
    <div class="alert alert-success" style="background:#D1FAE5; color:#065F46; padding:1rem; border-radius:4px; margin-bottom:1.5rem; border:1px solid #6EE7B7;">
      <i class="ph-bold ph-check-circle"></i> <?= htmlspecialchars($success) ?>
    </div>
  <?php endif; ?>

  <div style="display: grid; grid-template-columns: 2.5fr 1fr; gap: 2rem;">
    <div>
        <h3 style="font-family: var(--font-serif); font-size: 1.3rem; margin-bottom: 1.5rem;">Pending Requests (<?= count($requests) ?>)</h3>
        
        <?php if (empty($requests)): ?>
            <div class="empty-state card-academic" style="padding: 3rem 2rem;">
                <i class="ph-fill ph-inbox" style="font-size:3rem; color:var(--text-muted); opacity:0.3;"></i>
                <p style="margin-top:1rem;">You currently have no pending advisee requests.</p>
            </div>
        <?php else: ?>
            <?php foreach ($requests as $req): ?>
                <div class="req-card">
                    <div class="req-info">
                        <h4><?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?></h4>
                        <div class="req-meta">
                            <?= htmlspecialchars($req['college']) ?>
                        </div>
                        <div class="req-meta" style="margin-top:0.25rem; font-size:0.75rem;">
                            Requested on: <?= date('M j, Y h:i A', strtotime($req['created_at'])) ?>
                        </div>
                    </div>
                    <div class="req-actions">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                            <button type="submit" name="action" value="reject" class="btn btn-secondary" style="color:#DC2626; border-color:#FECACA;" onclick="return confirm('Reject this student?');">Reject</button>
                        </form>
                        
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                            <?php if ($isFull): ?>
                                <button type="button" class="btn btn-primary" style="opacity:0.5; cursor:not-allowed;" title="Capacity reached">Accept</button>
                            <?php else: ?>
                                <button type="submit" name="action" value="accept" class="btn btn-primary">Accept</button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <div>
        <div class="card-academic" style="padding: 1.5rem;">
            <h4 style="font-size: 0.75rem; font-weight: 800; color: var(--text-muted); margin-bottom: 1.25rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">CAPACITY</h4>
            
            <div style="font-size: 2rem; font-weight: 800; color: var(--text-dark); line-height: 1;">
                <?= $current ?> <span style="font-size:1rem; color:var(--text-muted);">/ <?= $max ?></span>
            </div>
            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem;">Active Advisees</div>
            
            <?php $pct = min(100, ($current / max(1, $max)) * 100); ?>
            <div class="capacity-bar">
                <div class="capacity-fill <?= $isFull ? 'full' : '' ?>" style="width: <?= $pct ?>%;"></div>
            </div>
            
            <?php if ($isFull): ?>
                <div style="margin-top: 1rem; font-size: 0.8rem; color: #DC2626; font-weight: 700;">
                    <i class="ph-bold ph-warning"></i> You have reached your maximum advisee limit. You cannot accept new requests.
                </div>
            <?php endif; ?>
        </div>
    </div>
  </div>

</main>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
