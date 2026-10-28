<?php
// admin/opd_bills.php — dedicated saved OPD bills and receipt list
$page_title = 'OPD Bills';
require_once __DIR__ . '/../config/tenant_db.php';
require_once __DIR__ . '/../config/alerts.php';
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$search = trim((string)($_GET['search'] ?? ''));
$date_from = trim((string)($_GET['from'] ?? ''));
$date_to = trim((string)($_GET['to'] ?? ''));
$bills = [];
$error_message = '';
try {
    $sql = "SELECT v.visit_id, v.token_no, v.receipt_no, v.visit_date, v.consultation_fee,
                   v.discount_amount, v.discount_percent, v.amount_paid, v.change_return,
                   v.payment_mode, v.payment_breakdown, v.payment_status, v.remark, v.created_at,
                   p.uhid, p.fullname, p.mobile, p.dob, p.age, p.gender, d.full_name AS doctor_name
            FROM opd_visits v
            INNER JOIN patient_master p ON p.patient_id = v.patient_id
            LEFT JOIN master_doctors d ON d.id = v.doctor_id
            WHERE v.org_id = ? AND v.center_id = ?";
    $params = [$org_id, $center_id];
    if ($search !== '') {
        $sql .= " AND (p.fullname LIKE ? OR p.uhid LIKE ? OR p.mobile LIKE ? OR CAST(v.receipt_no AS CHAR) LIKE ? OR CAST(v.token_no AS CHAR) LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $sql .= ' AND v.visit_date >= ?'; $params[] = $date_from; }
    if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) { $sql .= ' AND v.visit_date <= ?'; $params[] = $date_to; }
    $sql .= ' ORDER BY v.visit_id DESC LIMIT 1000';
    $stmt = $tenant_pdo->prepare($sql);
    $stmt->execute($params);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $error_message = 'Bills could not be loaded. Please verify the OPD visit and patient tables.'; }
function bill_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function bill_age($row) {
    if (!empty($row['dob'])) { try { return (new DateTime($row['dob']))->diff(new DateTime('today'))->y; } catch (Throwable $e) {} }
    return $row['age'] ?? '';
}
require_once __DIR__ . '/layout_header.php';
?>
<style>
.opd-bills-hero{background:linear-gradient(120deg,#fff,#eff6ff);border:1px solid #dbeafe;border-radius:14px;padding:17px 20px}
.opd-bills-hero .hero-icon{height:46px;width:46px;border-radius:12px;display:grid;place-items:center;background:#2563eb;color:#fff;font-size:21px}
.opd-bills-card{border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;background:#fff;box-shadow:0 4px 14px rgba(15,23,42,.04)}
.opd-bills-table th{font-size:10px;text-transform:uppercase;letter-spacing:.35px;background:#f8fafc;color:#475569;white-space:nowrap}
.opd-bills-table td{font-size:12px;vertical-align:middle}
.opd-bills-table .patient-name{font-weight:750;color:#0f172a}
.opd-bills-table .muted{font-size:10px;color:#64748b}
</style>
<div class="opd-bills-hero mb-3">
 <div class="d-flex align-items-center gap-3 flex-wrap">
  <div class="hero-icon"><i class="bi bi-receipt-cutoff"></i></div>
  <div class="flex-grow-1"><div class="text-primary fw-bold" style="font-size:10px;letter-spacing:1px">OPD SERVICES / ACCOUNTS</div><h4 class="mb-1 fw-bold">OPD Bills</h4><div class="text-secondary small">Saved receipts, patient bills and payment status in one place.</div></div>
  <a href="opd_registration.php" class="btn btn-primary btn-sm fw-semibold"><i class="bi bi-plus-lg me-1"></i> New OPD Payment</a>
  <a href="opd_appointments.php" class="btn btn-outline-secondary btn-sm fw-semibold"><i class="bi bi-calendar-check me-1"></i> Appointments</a>
 </div>
</div>
<?php if ($error_message): ?><div class="alert alert-warning py-2 small"><?= bill_h($error_message) ?></div><?php endif; ?>
<div class="opd-bills-card">
 <form method="GET" class="p-3 border-bottom bg-white"><div class="row g-2 align-items-end">
  <div class="col-lg-5"><label class="form-label small fw-semibold">Search patient / UHID / mobile / receipt / token</label><input class="form-control form-control-sm" name="search" value="<?= bill_h($search) ?>" placeholder="Name, UHID, mobile, receipt number..."></div>
  <div class="col-6 col-lg-2"><label class="form-label small fw-semibold">From date</label><input type="date" class="form-control form-control-sm" name="from" value="<?= bill_h($date_from) ?>"></div>
  <div class="col-6 col-lg-2"><label class="form-label small fw-semibold">To date</label><input type="date" class="form-control form-control-sm" name="to" value="<?= bill_h($date_to) ?>"></div>
  <div class="col-lg-3 d-flex gap-2"><button class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-search me-1"></i> Search</button><a class="btn btn-light border btn-sm" href="opd_bills.php">Reset</a></div>
 </div></form>
 <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center"><span class="fw-bold small">SAVED OPD BILLS</span><span class="badge text-bg-primary"><?= count($bills) ?> records</span></div>
 <div class="table-responsive" style="max-height:calc(100vh - 360px);min-height:220px;overflow:auto"><table class="table table-hover opd-bills-table mb-0">
 <thead class="sticky-top"><tr><th>Receipt / Token</th><th>Bill Date</th><th>Patient / UHID</th><th>Doctor</th><th>Bill Amount</th><th>Paid / Balance</th><th>Payment Status</th><th class="text-end">Actions</th></tr></thead><tbody>
 <?php if (!$bills): ?><tr><td colspan="8" class="text-center text-secondary py-5">No saved OPD bills found for the selected filters.</td></tr><?php else: foreach ($bills as $b):
   $fee=(float)($b['consultation_fee']??0); $discount=(float)($b['discount_amount']??0); $payable=max(0,$fee-$discount); $paid=(float)($b['amount_paid']??0); $balance=max(0,$payable-$paid); $status=(int)($b['payment_status']??3); $label=$status===1?'PAID':($status===2?'PARTIAL':'UNPAID'); $badge=$status===1?'success':($status===2?'warning':'danger');
   $receipt=(string)($b['receipt_no']??''); $token=(string)($b['token_no']??'');
   $billData=['receipt'=>$receipt,'token'=>$token,'date'=>!empty($b['visit_date'])?date('d-m-Y',strtotime($b['visit_date'])):'','name'=>$b['fullname']??'','uhid'=>$b['uhid']??'','mobile'=>$b['mobile']??'','age'=>bill_age($b),'gender'=>$b['gender']??'','doctor'=>$b['doctor_name']??'General OPD','fee'=>$fee,'discount'=>$discount,'payable'=>$payable,'paid'=>$paid,'balance'=>$balance,'mode'=>$b['payment_mode']??'','remark'=>$b['remark']??''];
 ?>
 <tr><td><div class="fw-bold text-primary">R-<?= bill_h($receipt ?: '—') ?></div><div class="muted">Token: <?= bill_h($token ?: '—') ?></div></td>
 <td><div class="fw-semibold"><?= !empty($b['visit_date'])?bill_h(date('d-m-Y',strtotime($b['visit_date']))):'—' ?></div><div class="muted"><?= !empty($b['created_at'])?bill_h(date('h:i A',strtotime($b['created_at']))):'' ?></div></td>
 <td><div class="patient-name"><?= bill_h($b['fullname']??'') ?></div><div class="muted"><?= bill_h($b['uhid']??'') ?> · <?= bill_h(bill_age($b)) ?> YRS / <?= bill_h($b['gender']??'') ?></div><div class="muted"><?= bill_h($b['mobile']??'') ?></div></td>
 <td><?= bill_h($b['doctor_name']??'General OPD') ?></td><td><div class="fw-bold">₹<?= number_format($payable,2) ?></div><?php if($discount>0): ?><div class="muted text-danger">Discount ₹<?= number_format($discount,2) ?></div><?php endif; ?></td>
 <td><div class="text-success fw-bold">₹<?= number_format($paid,2) ?></div><div class="muted <?= $balance>0?'text-danger':'' ?>">Balance ₹<?= number_format($balance,2) ?></div></td><td><span class="badge text-bg-<?= $badge ?>"><?= $label ?></span></td>
 <td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-outline-primary" title="View / Print Bill" onclick='printOpdBill(<?= json_encode($billData, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>)'><i class="bi bi-printer"></i> Print</button><a class="btn btn-sm btn-outline-secondary ms-1" title="Edit OPD payment" href="opd_registration.php?edit_visit=<?= (int)$b['visit_id'] ?>"><i class="bi bi-pencil"></i></a></td></tr>
 <?php endforeach; endif; ?>
 </tbody></table></div>
 <div class="small text-secondary px-3 py-2 border-top">Showing up to 1,000 records. Use date filters to narrow the list.</div>
</div>
<script>
function printOpdBill(b){
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const w=window.open('','_blank','width=760,height=850'); if(!w){alert('Please allow pop-ups to print the bill.');return;}
 w.document.write(`<!doctype html><html><head><title>OPD Bill ${esc(b.receipt)}</title><style>body{font:13px Arial,sans-serif;color:#111;padding:28px}h2{text-align:center;margin:0}p{text-align:center;margin:5px 0 18px}.meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;border:1px solid #aaa;padding:12px;margin-bottom:16px}.meta div{padding:2px}.line{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #ddd}.total{font-size:18px;font-weight:bold}.muted{color:#555}@media print{button{display:none}}</style></head><body><h2>OPD PAYMENT RECEIPT</h2><p>Receipt: ${esc(b.receipt)} &nbsp; | &nbsp; Token: ${esc(b.token)} &nbsp; | &nbsp; Date: ${esc(b.date)}</p><div class="meta"><div><b>Patient:</b> ${esc(b.name)}</div><div><b>UHID:</b> ${esc(b.uhid)}</div><div><b>Mobile:</b> ${esc(b.mobile)}</div><div><b>Age / Gender:</b> ${esc(b.age)} / ${esc(b.gender)}</div><div><b>Doctor:</b> ${esc(b.doctor)}</div><div><b>Payment Mode:</b> ${esc(b.mode)}</div></div><div class="line"><span>Consultation Fee</span><b>₹${Number(b.fee).toFixed(2)}</b></div><div class="line"><span>Discount</span><b>− ₹${Number(b.discount).toFixed(2)}</b></div><div class="line total"><span>Net Payable</span><span>₹${Number(b.payable).toFixed(2)}</span></div><div class="line"><span>Amount Paid</span><b>₹${Number(b.paid).toFixed(2)}</b></div><div class="line"><span>Balance Due</span><b>₹${Number(b.balance).toFixed(2)}</b></div><p style="text-align:left;margin-top:18px"><b>Remarks:</b> ${esc(b.remark)}</p><p class="muted" style="margin-top:30px">This is a computer-generated OPD receipt.</p><button onclick="window.print()">Print Receipt</button></body></html>`);
 w.document.close(); w.focus();
}
</script>
<?php require_once __DIR__ . '/layout_footer.php'; ?>
