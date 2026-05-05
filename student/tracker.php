<?php
require_once __DIR__ . '/../includes/session.php';
require_login(['student']);

$user = current_user();

$dashFlash = null;
if (!empty($_SESSION['student_dash_flash'])) {
  $dashFlash = $_SESSION['student_dash_flash'];
  unset($_SESSION['student_dash_flash']);
}

$thesis_id = $_GET['id'] ?? null;

if (!$thesis_id) {
  // If no ID passed, try to fetch the latest active one (either as author or co-author)
  $stmt = $pdo->prepare("SELECT DISTINCT t.id FROM theses t 
                         LEFT JOIN thesis_authors ta ON t.id = ta.thesis_id
                         WHERE t.author_id = :author_id OR ta.author_id = :author_id2
                         ORDER BY t.created_at DESC LIMIT 1");
  $stmt->execute(['author_id' => $user['id'], 'author_id2' => $user['id']]);
  $latest = $stmt->fetch();
  if ($latest) {
    $thesis_id = $latest['id'];
  } else {
    header('Location: index.php');
    exit;
  }
}

// Fetch the specific thesis
$stmt = $pdo->prepare("SELECT t.*, u.first_name as adviser_first, u.last_name as adviser_last 
                       FROM theses t 
                       LEFT JOIN users u ON t.adviser_id = u.id 
                       LEFT JOIN thesis_authors ta ON t.id = ta.thesis_id
                       WHERE t.id = :id AND (t.author_id = :author_id OR ta.author_id = :author_id2)");
$stmt->execute(['id' => $thesis_id, 'author_id' => $user['id'], 'author_id2' => $user['id']]);
$thesis = $stmt->fetch();

if (!$thesis) {
  header('Location: index.php');
  exit;
}

// Fetch versions
$vStmt = $pdo->prepare("SELECT * FROM thesis_versions WHERE thesis_id = :thesis_id ORDER BY submitted_at DESC");
$vStmt->execute(['thesis_id' => $thesis['id']]);
$versions = $vStmt->fetchAll();

$latestVersion = $versions[0] ?? null;

// Custom CSS for Detail View
ob_start();
?>
<style>
  .submission-header {
    background: white;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    padding: 2rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-sm);
    position: relative;
    overflow: hidden;
  }

  .submission-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 6px;
    height: 100%;
    background: var(--crimson);
  }

  .type-label {
    font-size: 0.65rem;
    font-weight: 800;
    letter-spacing: 0.15em;
    color: var(--gold);
    text-transform: uppercase;
    margin-bottom: 0.75rem;
    display: block;
  }

  .thesis-title-big {
    font-family: var(--font-serif);
    font-size: 2rem;
    color: var(--text-dark);
    margin-bottom: 1.5rem;
    line-height: 1.2;
  }

  .meta-pills {
    display: flex;
    gap: 2rem;
    margin-bottom: 1.5rem;
    border-bottom: 1px solid var(--off-white);
    padding-bottom: 1.5rem;
  }

  .meta-pill-item {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
  }

  .pill-label {
    font-size: 0.6rem;
    font-weight: 800;
    letter-spacing: 0.1em;
    color: var(--text-muted);
    text-transform: uppercase;
  }

  .pill-value {
    font-family: var(--font-base);
    font-weight: 700;
    color: var(--text-dark);
    font-size: 0.95rem;
  }

  .abstract-wrap h3 {
    font-family: var(--font-serif);
    font-size: 1.2rem;
    margin-bottom: 0.75rem;
    color: var(--text-dark);
  }

  .abstract-text {
    font-family: 'Georgia', serif;
    font-size: 1rem;
    line-height: 1.6;
    color: var(--text-dark);
    text-align: justify;
  }

  .side-actions-card {
    background: white;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    padding: 2rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-sm);
  }

  .side-badge {
    padding: 0.5rem 1rem;
    border-radius: 4px;
    font-weight: 800;
    font-size: 0.75rem;
    width: 100%;
    text-align: center;
    margin-bottom: 1.5rem;
    display: block;
  }

  /* Iterations Sync */
  .iteration-mini-card {
    background: white;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    padding: 1.25rem;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: all 0.2s;
  }

  .iteration-mini-card:hover {
    border-color: var(--crimson);
    transform: translateX(5px);
  }

  .mini-file-icon {
    font-size: 2rem;
    color: var(--crimson);
  }

  /* Feedback Note Enhancements */
  .feedback-note {
    background: #FFFBEB;
    border: 1px solid #FEF3C7;
    border-left: 4px solid var(--gold);
    padding: 1.25rem;
    border-radius: var(--radius-sm);
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-sm);
  }

  .feedback-note-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.7rem;
    font-weight: 800;
    color: #92400E;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-bottom: 0.5rem;
  }

  .feedback-note-header i {
    font-size: 1.1rem;
    color: var(--gold);
  }

  .feedback-body {
    font-size: 0.88rem;
    color: #78350F;
    line-height: 1.5;
    font-style: italic;
    font-family: var(--font-base);
  }

  /* Prominent Feedback Layout */
  .feedback-note-prominent {
    background: linear-gradient(135deg, #FEF3C7 0%, #FFFBEB 100%);
    border: 2px solid var(--gold);
    border-left: 5px solid var(--crimson);
    padding: 2rem;
    border-radius: var(--radius-lg);
    margin-top: 3rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-md);
  }

  .feedback-note-header-prominent {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 0.85rem;
    font-weight: 900;
    color: #92400E;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    margin-bottom: 1.25rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid var(--gold);
  }

  .feedback-note-header-prominent i {
    font-size: 1.4rem;
    color: var(--crimson);
  }

  .feedback-body-prominent {
    font-size: 1.02rem;
    color: #78350F;
    line-height: 1.7;
    font-family: var(--font-base);
    white-space: pre-wrap;
    word-wrap: break-word;
  }

  /* PDF Preview Modal */
  .pdf-preview-modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.7);
    animation: fadeIn 0.3s ease;
  }

  .pdf-preview-modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .pdf-preview-content {
    background: white;
    border-radius: var(--radius-lg);
    width: 90%;
    height: 90vh;
    max-width: 1000px;
    display: flex;
    flex-direction: column;
    box-shadow: var(--shadow-lg);
    animation: slideUp 0.3s ease;
  }

  .pdf-preview-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
  }

  .pdf-preview-header h3 {
    margin: 0;
    font-size: 1.2rem;
    color: var(--text-dark);
  }

  .pdf-preview-close {
    background: none;
    border: none;
    font-size: 1.8rem;
    cursor: pointer;
    color: var(--text-muted);
    padding: 0;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: background 0.2s, color 0.2s;
  }

  .pdf-preview-close:hover {
    background: var(--off-white);
    color: var(--text-dark);
  }

  .pdf-preview-viewer {
    flex: 1;
    overflow: auto;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f5f5f5;
  }

  .pdf-preview-viewer iframe {
    width: 100%;
    height: 100%;
    border: none;
  }

  @keyframes fadeIn {
    from {
      opacity: 0;
    }
    to {
      opacity: 1;
    }
  }

  @keyframes slideUp {
    from {
      transform: translateY(30px);
      opacity: 0;
    }
    to {
      transform: translateY(0);
      opacity: 1;
    }
  }

  /* Expandable History Section */
  .history-toggle-button {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-family: var(--font-serif);
    font-size: 1.3rem;
    margin: 2.5rem 0 0;
    padding: 1.5rem;
    border: none;
    border-bottom: 2px solid var(--border);
    background: transparent;
    cursor: pointer;
    width: 100%;
    text-align: left;
    color: var(--text-dark);
    font-weight: 700;
    transition: all 0.3s ease;
  }

  .history-toggle-button:hover {
    background: var(--off-white);
    border-radius: 4px 4px 0 0;
  }

  .history-toggle-icon {
    font-size: 1.5rem;
    transition: transform 0.3s ease;
    color: var(--crimson);
  }

  .history-toggle-button.expanded .history-toggle-icon {
    transform: rotate(180deg);
  }

  .history-container {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.4s ease;
  }

  .history-container.expanded {
    max-height: 5000px;
  }

  .history-content {
    padding-top: 1rem;
  }

  .history-empty-state {
    text-align: center;
    padding: 3rem 1.5rem;
    color: var(--text-muted);
    font-size: 0.95rem;
  }

  .history-empty-state i {
    font-size: 3rem;
    opacity: 0.3;
    margin-bottom: 1rem;
    display: block;
  }
</style>
<?php
$extraCss = ob_get_clean();

require_once __DIR__ . '/../includes/layout_top.php';
require_once __DIR__ . '/../includes/layout_sidebar.php';
?>

<main class="main-content">

  <!-- Breadcrumb -->
  <nav style="margin-bottom: 1.5rem; font-size: 0.75rem; color: var(--text-muted); font-weight: 700;">
    <a href="index.php" style="color:var(--text-muted); text-decoration:none;">DASHBOARD</a>
    <i class="ph ph-caret-right" style="margin: 0 0.5rem; opacity: 0.5;"></i>
    <a href="index.php?view=submissions" style="color:var(--text-muted); text-decoration:none;">MY SUBMISSIONS</a>
    <i class="ph ph-caret-right" style="margin: 0 0.5rem; opacity: 0.5;"></i>
    <span style="color: var(--crimson);"><?= htmlspecialchars($thesis['thesis_code']) ?></span>
  </nav>

  <?php if (!empty($dashFlash)): ?>
    <div
      style="display:flex;align-items:flex-start;gap:0.65rem;margin-bottom:1.5rem;padding:0.9rem 1.1rem;border-radius:var(--radius-sm);font-size:0.88rem;font-weight:700;line-height:1.45;background:<?= ($dashFlash['type'] ?? '') === 'success' ? '#D1FAE5' : '#FEE2E2' ?>;color:<?= ($dashFlash['type'] ?? '') === 'success' ? '#065F46' : '#991B1B' ?>;border:1px solid <?= ($dashFlash['type'] ?? '') === 'success' ? '#6EE7B7' : '#FECACA' ?>;">
      <i class="ph-bold <?= ($dashFlash['type'] ?? '') === 'success' ? 'ph-check-circle' : 'ph-warning-circle' ?>"
        style="font-size:1.2rem;flex-shrink:0;"></i>
      <span><?= htmlspecialchars($dashFlash['message'] ?? '') ?></span>
    </div>
  <?php endif; ?>

  <div style="display: grid; grid-template-columns: 2.4fr 1fr; gap: 2rem;">

    <!-- Primary Area -->
    <div>
      <article class="submission-header">
        <span class="type-label">Thesis Entry</span>
        <h1 class="thesis-title-big"><?= htmlspecialchars($thesis['title']) ?></h1>

        <div class="meta-pills">
          <div class="meta-pill-item">
            <span class="pill-label">Primary Contributor</span>
            <span class="pill-value"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></span>
          </div>
          <div class="meta-pill-item">
            <span class="pill-label">Registry Date</span>
            <span class="pill-value"><?= date('F j, Y', strtotime($thesis['created_at'])) ?></span>
          </div>
          <div class="meta-pill-item">
            <span class="pill-label">Assigned Faculty</span>
            <span class="pill-value">Dr. <?= htmlspecialchars($thesis['adviser_last']) ?></span>
          </div>
        </div>

        <div class="abstract-wrap">
          <h3>Abstract</h3>
          <p class="abstract-text"><?= nl2br(htmlspecialchars($thesis['abstract'])) ?></p>
        </div>
      </article>

      <?php if ($latestVersion && !empty($latestVersion['feedback'])): ?>
        <div class="feedback-note-prominent">
          <div class="feedback-note-header-prominent"><i class="ph-fill ph-chat-centered-text"></i> Faculty Review Notes</div>
          <div class="feedback-body-prominent"><?= nl2br(htmlspecialchars($latestVersion['feedback'])) ?></div>
        </div>
      <?php endif; ?>

      <!-- Expandable Thesis History Section -->
      <button type="button" class="history-toggle-button" id="historyToggle" onclick="toggleHistory()">
        <i class="ph-fill ph-caret-down history-toggle-icon"></i>
        <span>Thesis History</span>
      </button>

      <div class="history-container" id="historyContainer">
        <div class="history-content">
          <?php if (count($versions) > 0): ?>
            <?php foreach ($versions as $index => $v): ?>
              <div class="iteration-mini-card">
                <div style="display: flex; align-items: center; gap: 1.25rem;">
                  <i class="ph-fill ph-file-pdf mini-file-icon"></i>
                  <div>
                    <div style="font-weight: 800; font-size: 0.95rem; color: var(--text-dark);">Version
                      <?= htmlspecialchars($v['version_number']) ?>   <?php if ($index === 0): ?><span
                          style="color:var(--gold); font-size:0.6rem; margin-left:0.5rem;">[LATEST]</span><?php endif; ?>
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.2rem;">Processed on
                      <?= date('M j, Y', strtotime($v['submitted_at'])) ?> &bull; <?= round($v['file_size'] / 1024 / 1024, 2) ?>
                      MB
                    </div>
                  </div>
                </div>
                <div style="display: flex; align-items: center; gap: 1.5rem;">
                  <?php if ($v['status'] === 'approved'): ?>
                    <span style="font-size: 0.65rem; font-weight: 800; color: #059669; letter-spacing: 0.1em;">VERIFIED</span>
                  <?php elseif ($v['status'] === 'revision_requested'): ?>
                    <span style="font-size: 0.65rem; font-weight: 800; color: #DC2626; letter-spacing: 0.1em;">REVISION</span>
                  <?php else: ?>
                    <span style="font-size: 0.65rem; font-weight: 800; color: var(--gold); letter-spacing: 0.1em;">PENDING</span>
                  <?php endif; ?>
                  <a href="../public/uploads/<?= htmlspecialchars($v['file_path']) ?>" target="_blank"
                    class="btn-action-outline"><i class="ph-bold ph-eye"></i></a>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="history-empty-state">
              <i class="ph-fill ph-file-x"></i>
              <p>No file versions submitted yet</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Actions Area -->
    <div>
      <div class="side-actions-card">
        <span
          style="font-size: 0.65rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; display: block; margin-bottom: 1rem;">CURRENT
          STATUS</span>
        <?php
        $c = 'var(--text-muted)';
        $bg = 'var(--off-white)';
        $label = ucfirst($thesis['status']);
        if ($thesis['status'] === 'approved') {
          $c = '#065F46';
          $bg = '#D1FAE5';
          $label = 'RECORD ARCHIVED';
        } elseif ($thesis['status'] === 'revision_requested') {
          $c = '#991B1B';
          $bg = '#FEE2E2';
          $label = 'REVISION REQUIRED';
        }
        ?>
        <div class="side-badge" style="color: <?= $c ?>; background: <?= $bg ?>;"><?= $label ?></div>

        <?php if ($thesis['status'] === 'revision_requested'): ?>
          <a href="resubmit.php?id=<?= $thesis['id'] ?>" class="btn btn-primary"
            style="width:100%; text-decoration: none; justify-content: center; margin-bottom: 0.5rem;">SUBMIT REVISION</a>
        <?php endif; ?>

        <?php if ($latestVersion): ?>
          <button type="button" id="previewBtn" class="btn btn-secondary" 
            style="width:100%; justify-content: center; margin-bottom: 0.5rem; cursor: pointer;">PREVIEW PDF</button>
          <a href="../public/uploads/<?= htmlspecialchars($latestVersion['file_path']) ?>" download
            class="btn btn-secondary" style="width:100%; text-decoration: none; justify-content: center;">DOWNLOAD
            MANUSCRIPT</a>
        <?php endif; ?>
      </div>

      <div class="card-academic" style="padding: 1.5rem;">
        <h4
          style="font-size: 0.75rem; font-weight: 800; color: var(--text-muted); margin-bottom: 1.25rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">
          REGISTRY ANALYTICS</h4>
        <div style="display: flex; justify-content: space-between; gap: 1rem; text-align: center;">
          <div style="flex:1;">
            <div style="font-weight: 800; font-size: 1.25rem; color: var(--text-dark);">
              <?= number_format($thesis['views']) ?>
            </div>
            <div style="font-size: 0.6rem; color: var(--text-muted); font-weight: 800; letter-spacing: 0.05em;">VIEWS
            </div>
          </div>
          <div style="flex:1; border-left: 1px solid var(--border);">
            <div style="font-weight: 800; font-size: 1.25rem; color: var(--text-dark);">
              <?= number_format($thesis['downloads']) ?>
            </div>
            <div style="font-size: 0.6rem; color: var(--text-muted); font-weight: 800; letter-spacing: 0.05em;">
              DOWNLOADS</div>
          </div>
        </div>
      </div>
    </div>

  </div>

  <!-- PDF Preview Modal -->
  <div id="pdfPreviewModal" class="pdf-preview-modal">
    <div class="pdf-preview-content">
      <div class="pdf-preview-header">
        <h3>Thesis Preview</h3>
        <button class="pdf-preview-close" id="closePreview">&times;</button>
      </div>
      <div class="pdf-preview-viewer">
        <iframe id="pdfFrame" src="" type="application/pdf"></iframe>
      </div>
    </div>
  </div>

  <script>
    // Toggle Thesis History
    function toggleHistory() {
      const toggle = document.getElementById('historyToggle');
      const container = document.getElementById('historyContainer');
      
      toggle.classList.toggle('expanded');
      container.classList.toggle('expanded');
    }

    document.addEventListener('DOMContentLoaded', function() {
      const previewBtn = document.getElementById('previewBtn');
      const pdfModal = document.getElementById('pdfPreviewModal');
      const closeBtn = document.getElementById('closePreview');
      const pdfFrame = document.getElementById('pdfFrame');
      const latestPath = '<?= htmlspecialchars($latestVersion['file_path'] ?? '') ?>';

      if (previewBtn && latestPath) {
        previewBtn.addEventListener('click', function() {
          pdfFrame.src = '../public/uploads/' + latestPath;
          pdfModal.classList.add('show');
          document.body.style.overflow = 'hidden';
        });

        closeBtn.addEventListener('click', function() {
          pdfModal.classList.remove('show');
          document.body.style.overflow = 'auto';
        });

        pdfModal.addEventListener('click', function(e) {
          if (e.target === pdfModal) {
            pdfModal.classList.remove('show');
            document.body.style.overflow = 'auto';
          }
        });

        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape' && pdfModal.classList.contains('show')) {
            pdfModal.classList.remove('show');
            document.body.style.overflow = 'auto';
          }
        });
      }
    });
  </script>

</main>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>