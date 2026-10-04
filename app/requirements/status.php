<?php
session_start();
require_once __DIR__ . '/../shared/db.php';

// ── Load docs from DB (not session — upload.php saves directly to DB now) ──
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { header('Location: ../auth/signin.php'); exit; }

$pre_reg_id  = 0;
$ref_number  = '';
$pre_reg_name = '';

if (enrollment_tables_exist($conn)) {
    $r = $conn->prepare(
        "SELECT id, ref_number, first_name, last_name
         FROM pre_registrations
         WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1"
    );
    $r->bind_param('i', $user_id);
    $r->execute();
    $pr = $r->get_result()->fetch_assoc();
    $r->close();

    if ($pr) {
        $pre_reg_id   = (int)$pr['id'];
        $ref_number   = $pr['ref_number'] ?? '';
        $pre_reg_name = trim(($pr['first_name'] ?? '') . ' ' . ($pr['last_name'] ?? ''));
    }
}

if (!$pre_reg_id) { header('Location: upload.php'); exit; }

// Fetch all documents for this pre-registration
$docs = [];
if (enrollment_tables_exist($conn)) {
    $res = $conn->query(
        "SELECT id, document_type, file_name, file_path, file_size,
                redacted_path, redaction_status, ai_result, ai_inspected_at,
                extracted_name, extracted_dob, extracted_sex, extracted_citizenship,
                name_match_status, name_match_notes, status, uploaded_at
         FROM enrollment_documents
         WHERE pre_reg_id = $pre_reg_id
         ORDER BY uploaded_at ASC"
    );
    if ($res) while ($row = $res->fetch_assoc()) $docs[] = $row;
}

if (empty($docs)) { header('Location: upload.php'); exit; }

$conn->close();

$current_step = 3;
include __DIR__ . '/header.php';

$doc_type_labels = [
    'BirthCertificate' => 'PSA Birth Certificate',
    'ReportCard'       => 'Report Card (Form 138)',
    'GoodMoral'        => 'Certificate of Good Moral Character',
    'Form137'          => 'Form 137',
    'IDPhoto'          => 'ID Photo',
    'Other'            => 'Other Document',
];

$total_size   = array_sum(array_column($docs, 'file_size'));
$ai_done      = count(array_filter($docs, fn($d) => !empty($d['ai_result'])));
$redacted_ok  = count(array_filter($docs, fn($d) => ($d['redaction_status'] ?? '') === 'done'));
$mismatches   = array_filter($docs, fn($d) => ($d['name_match_status'] ?? '') === 'mismatch');
$mismatch_cnt = count($mismatches);
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">Review Your Documents</h2>
    <p style="text-align:center;font-size:.82rem;color:#666;margin-bottom:24px;">
      These are the documents saved under your application.
      Go back to upload more, or submit now.
    </p>

    <!-- Summary stat cards -->
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:24px;">
      <div style="flex:1;min-width:110px;background:#eff6ff;border-radius:10px;padding:14px 18px;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:#2563eb;"><?= count($docs) ?></div>
        <div style="font-size:.72rem;color:#555;margin-top:3px;">Documents</div>
      </div>
      <?php
      $n_approved = count(array_filter($docs, fn($d) => $d['status'] === 'Approved'));
      $n_pending  = count(array_filter($docs, fn($d) => $d['status'] === 'Pending'));
      $n_rejected = count(array_filter($docs, fn($d) => $d['status'] === 'Rejected'));
      ?>
      <div style="flex:1;min-width:110px;background:#f0fdf4;border-radius:10px;padding:14px 18px;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:#16a34a;"><?= $n_approved ?></div>
        <div style="font-size:.72rem;color:#555;margin-top:3px;">Approved</div>
      </div>
      <div style="flex:1;min-width:110px;background:#fffbeb;border-radius:10px;padding:14px 18px;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:#d97706;"><?= $n_pending ?></div>
        <div style="font-size:.72rem;color:#555;margin-top:3px;">Pending</div>
      </div>
      <?php if ($n_rejected > 0): ?>
      <div style="flex:1;min-width:110px;background:#fff1f2;border-radius:10px;padding:14px 18px;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:#dc2626;"><?= $n_rejected ?></div>
        <div style="font-size:.72rem;color:#dc2626;margin-top:3px;">Rejected</div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Reference number banner -->
    <?php if ($ref_number): ?>
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;
                padding:10px 16px;margin-bottom:18px;font-size:.82rem;color:#1d4ed8;">
      <i class="fa-solid fa-hashtag"></i>
      Application Reference: <strong><?= htmlspecialchars($ref_number) ?></strong>
      &nbsp;·&nbsp; Applicant: <strong><?= htmlspecialchars($pre_reg_name) ?></strong>
    </div>
    <?php endif; ?>

    <!-- Document table -->
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
      <thead style="background:#1a3a8c;color:#fff;">
        <tr>
          <th style="padding:10px 14px;text-align:left;">#</th>
          <th style="padding:10px 14px;text-align:left;">Document</th>
          <th style="padding:10px 14px;text-align:left;">File</th>
          <th style="padding:10px 14px;text-align:left;">Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($docs as $i => $doc): ?>
        <tr style="border-bottom:1px solid #f0f2f5;<?= $i % 2 === 0 ? 'background:#fafafa;' : '' ?>">
          <td style="padding:10px 14px;color:#aaa;"><?= $i + 1 ?></td>

          <!-- Document type -->
          <td style="padding:10px 14px;font-weight:600;color:#1a1a2e;">
            <i class="fa-solid fa-file-lines" style="color:#2563eb;margin-right:6px;"></i>
            <?= htmlspecialchars($doc_type_labels[$doc['document_type']] ?? $doc['document_type']) ?>
          </td>

          <!-- File -->
          <td style="padding:10px 14px;font-size:.75rem;color:#555;max-width:160px;
                     overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?= htmlspecialchars($doc['file_name']) ?><br>
            <span style="color:#aaa;"><?= round($doc['file_size'] / 1024, 1) ?> KB</span><br>
            <span style="color:#bbb;font-size:.68rem;"><?= date('M d, g:i A', strtotime($doc['uploaded_at'])) ?></span>
          </td>

          <!-- Admin review status -->
          <td style="padding:10px 14px;">
            <?php
            $st  = $doc['status'];
            $stc = match($st){ 'Approved'=>'#16a34a','Rejected'=>'#dc2626',default=>'#d97706' };
            $sti = match($st){ 'Approved'=>'fa-circle-check','Rejected'=>'fa-circle-xmark',default=>'fa-clock' };
            ?>
            <span style="color:<?= $stc ?>;font-size:.82rem;font-weight:700;display:flex;align-items:center;gap:5px;">
              <i class="fa-solid <?= $sti ?>"></i> <?= htmlspecialchars($st) ?>
            </span>
            <?php if ($st === 'Rejected'): ?>
            <div style="font-size:.72rem;color:#dc2626;margin-top:3px;">
              Please upload a new copy in the Upload page.
            </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <!-- Disclaimer -->
    <div style="background:#fff7ed;border:1px solid #fcd34d;border-radius:8px;
                padding:12px 16px;margin-top:16px;font-size:.8rem;color:#92400e;">
      <i class="fa-solid fa-triangle-exclamation"></i>
      Once submitted, documents are sent to the registrar for review.
      Contact the admissions office if corrections are needed.
    </div>

    <div class="enroll-actions" style="margin-top:20px;">
      <a href="upload.php" class="btn-back">
        <i class="fa-solid fa-arrow-left"></i> Add More
      </a>
      <form method="POST" action="submit.php" style="display:inline;">
        <button type="submit" class="btn-proceed">
          Submit Requirements <i class="fa-solid fa-paper-plane"></i>
        </button>
      </form>
    </div>

  </div>
</div>

</body>
</html>
