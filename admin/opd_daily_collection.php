<?php
// admin/opd_daily_collection.php - OPD collection ledger and refund register
// IPD can use the same ledger later by setting source_type='IPD'.
date_default_timezone_set('Asia/Kolkata');
$page_title = 'Daily OPD Collection';
require_once __DIR__ . '/../config/tenant_db.php';
$org_id = (int)($_SESSION['org_id'] ?? 1);
$center_id = (int)($_SESSION['center_id'] ?? 1);
$today = date('Y-m-d');

$tenant_pdo->exec("CREATE TABLE IF NOT EXISTS opd_payment_transactions (
 transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 org_id INT NOT NULL DEFAULT 1, center_id INT NOT NULL DEFAULT 1,
 source_type VARCHAR(10) NOT NULL DEFAULT 'OPD', source_id BIGINT UNSIGNED NOT NULL,
 transaction_type VARCHAR(10) NOT NULL DEFAULT 'PAYMENT', payment_date DATE NOT NULL,
 payment_mode VARCHAR(50) NOT NULL, amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
 transaction_ref VARCHAR(150) NULL, remarks TEXT NULL, received_by INT NULL,
 status VARCHAR(12) NOT NULL DEFAULT 'ACTIVE', legacy_key VARCHAR(100) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (transaction_id), UNIQUE KEY uq_opd_payment_legacy (legacy_key),
 KEY idx_payment_day (org_id, center_id, payment_date, status),
 KEY idx_payment_source (org_id, center_id, source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// One-time, repeat-safe import of existing visit-level payment snapshots.
// Visits already represented in the ledger are skipped to prevent double counting.
try {
 $visits = $tenant_pdo->prepare("SELECT v.visit_id,v.org_id,v.center_id,v.receipt_no,v.payment_date,v.payment_mode,v.amount_paid,v.payment_breakdown,v.remark
  FROM opd_visits v WHERE v.org_id=? AND v.center_id=? AND COALESCE(v.amount_paid,0)>0");
 $visits->execute([$org_id,$center_id]);
 $hasLedger = $tenant_pdo->prepare("SELECT COUNT(*) FROM opd_payment_transactions WHERE org_id=? AND center_id=? AND source_type='OPD' AND source_id=? AND status='ACTIVE'");
 $legacyInsert = $tenant_pdo->prepare("INSERT IGNORE INTO opd_payment_transactions
  (org_id,center_id,source_type,source_id,transaction_type,payment_date,payment_mode,amount,transaction_ref,remarks,status,legacy_key)
  VALUES (?,?,'OPD',?,'PAYMENT',?,?,?,?,?,'ACTIVE',?)");
 foreach ($visits->fetchAll(PDO::FETCH_ASSOC) as $v) {
  $hasLedger->execute([$org_id,$center_id,(int)$v['visit_id']]);
  if ((int)$hasLedger->fetchColumn()>0) continue;
  $rows = json_decode((string)$v['payment_breakdown'], true);
  if (!is_array($rows) || !$rows) {
   $mode = trim((string)($v['payment_mode'] ?? 'Cash'));
   if (stripos($mode,'upi')!==false) $mode='UPI';
   elseif (stripos($mode,'card')!==false) $mode='Card';
   elseif (stripos($mode,'net')!==false || stripos($mode,'bank')!==false) $mode='Net Banking';
   else $mode='Cash';
   $rows = [$mode => ['amount'=>(float)$v['amount_paid']]];
  }
  $line=0;
  foreach ($rows as $label=>$data) {
   $amount = is_array($data) ? (float)($data['amount'] ?? 0) : (float)$data;
   if ($amount<=0) continue;
   $key='OPD:'.$v['visit_id'].':'.(++$line);
   $raw=strtolower((string)$label);
   $mode=(strpos($raw,'cash')!==false)?'Cash':((strpos($raw,'upi')!==false)?'UPI':((strpos($raw,'card')!==false)?'Card':((strpos($raw,'net')!==false || strpos($raw,'bank')!==false)?'Net Banking':ucfirst((string)$label))));
   $ref=is_array($data)?(string)($data['utr'] ?? ''):'';
   $legacyInsert->execute([$org_id,$center_id,(int)$v['visit_id'],($v['payment_date'] ?: $today),$mode,$amount,($ref!==''?$ref:null),$v['remark'],$key]);
  }
 }
} catch (Throwable $e) {
 // Keep the collection page usable; the visible warning below reports migration issues.
 $migration_error = $e->getMessage();
}

$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_transaction'])) {
 $visit_id=(int)($_POST['visit_id'] ?? 0);
 $type=($_POST['transaction_type'] ?? 'PAYMENT')==='REFUND'?'REFUND':'PAYMENT';
 $date=trim((string)($_POST['payment_date'] ?? $today));
 $mode=trim((string)($_POST['payment_mode'] ?? 'Cash'));
 $amount=round((float)($_POST['amount'] ?? 0),2);
 $ref=trim((string)($_POST['transaction_ref'] ?? ''));
 $remarks=trim((string)($_POST['remarks'] ?? ''));
 $d=DateTime::createFromFormat('!Y-m-d',$date);
 try {
  if ($visit_id<=0 || !$d || $d->format('Y-m-d')!==$date || $amount<=0 || !in_array($mode,['Cash','UPI','Card','Net Banking'],true)) throw new Exception('Valid visit, date, payment mode and amount are required.');
  $tenant_pdo->beginTransaction();
  $lock=$tenant_pdo->prepare("SELECT visit_id,consultation_fee,discount_amount,receipt_no FROM opd_visits WHERE visit_id=? AND org_id=? AND center_id=? FOR UPDATE");
  $lock->execute([$visit_id,$org_id,$center_id]); $visit=$lock->fetch(PDO::FETCH_ASSOC);
  if (!$visit) throw new Exception('OPD bill not found for this branch.');
  $sum=$tenant_pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type='PAYMENT' THEN amount WHEN transaction_type='REFUND' THEN -amount ELSE 0 END),0) FROM opd_payment_transactions WHERE org_id=? AND center_id=? AND source_type='OPD' AND source_id=? AND status='ACTIVE'");
  $sum->execute([$org_id,$center_id,$visit_id]); $netBefore=(float)$sum->fetchColumn();
  if ($type==='REFUND' && $amount>$netBefore) throw new Exception('Refund amount cannot exceed net payments recorded for this bill.');
  $ins=$tenant_pdo->prepare("INSERT INTO opd_payment_transactions (org_id,center_id,source_type,source_id,transaction_type,payment_date,payment_mode,amount,transaction_ref,remarks,received_by,status) VALUES (?,?,'OPD',?,?,?,?,?,?,?,?, 'ACTIVE')");
  $ins->execute([$org_id,$center_id,$visit_id,$type,$date,$mode,$amount,($ref?:null),($remarks?:null),(int)($_SESSION['user_id'] ?? 0) ?: null]);
  $netAfter=$netBefore+($type==='PAYMENT'?$amount:-$amount);
  $payable=max(0,(float)$visit['consultation_fee']-(float)$visit['discount_amount']);
  $status=($payable<=0 || $netAfter >= $payable)?1:($netAfter>0?2:3);
  $tenant_pdo->prepare("UPDATE opd_visits SET amount_paid=?,payment_status=? WHERE visit_id=? AND org_id=? AND center_id=?")
   ->execute([max(0,$netAfter),$status,$visit_id,$org_id,$center_id]);
  $tenant_pdo->commit();
  $msg=$type==='REFUND'?'Refund entry saved.':'Payment entry saved.';
 } catch(Throwable $e) { if($tenant_pdo->inTransaction()) $tenant_pdo->rollBack(); $err=$e->getMessage(); }
}

$fromDate=trim((string)($_GET['from_date'] ?? $_GET['date'] ?? $today));
$toDate=trim((string)($_GET['to_date'] ?? $_GET['date'] ?? $today));
$validFrom=DateTime::createFromFormat('!Y-m-d',$fromDate);
$validTo=DateTime::createFromFormat('!Y-m-d',$toDate);
if(!$validFrom || $validFrom->format('Y-m-d')!==$fromDate) $fromDate=$today;
if(!$validTo || $validTo->format('Y-m-d')!==$toDate) $toDate=$today;
if($fromDate>$toDate){ $swap=$fromDate; $fromDate=$toDate; $toDate=$swap; }
$tab=($_GET['tab'] ?? 'collection')==='refunds'?'REFUND':'PAYMENT';
$modeFilter=trim((string)($_GET['mode'] ?? ''));
$doctorFilter=(int)($_GET['doctor_id'] ?? 0);
$where="t.org_id=? AND t.center_id=? AND t.source_type='OPD' AND t.status='ACTIVE' AND t.payment_date BETWEEN ? AND ? AND t.transaction_type=?";
$params=[$org_id,$center_id,$fromDate,$toDate,$tab];
if(in_array($modeFilter,['Cash','UPI','Card','Net Banking'],true)){ $where.=' AND t.payment_mode=?'; $params[]=$modeFilter; }
if($doctorFilter>0){$where.=' AND v.doctor_id=?';$params[]=$doctorFilter;}
$q=$tenant_pdo->prepare("SELECT t.*,v.receipt_no,v.token_no,v.doctor_id,p.fullname,p.uhid,d.full_name AS doctor_name FROM opd_payment_transactions t JOIN opd_visits v ON v.visit_id=t.source_id AND v.org_id=t.org_id AND v.center_id=t.center_id JOIN patient_master p ON p.patient_id=v.patient_id LEFT JOIN master_doctors d ON d.id=v.doctor_id WHERE $where ORDER BY t.transaction_id DESC");
$q->execute($params);$transactions=$q->fetchAll(PDO::FETCH_ASSOC);
$sumQ=$tenant_pdo->prepare("SELECT transaction_type,payment_mode,SUM(amount) total FROM opd_payment_transactions WHERE org_id=? AND center_id=? AND source_type='OPD' AND status='ACTIVE' AND payment_date BETWEEN ? AND ? GROUP BY transaction_type,payment_mode");
$sumQ->execute([$org_id,$center_id,$fromDate,$toDate]);$totals=['PAYMENT'=>0,'REFUND'=>0,'Cash'=>0,'UPI'=>0,'Card'=>0,'Net Banking'=>0];
foreach($sumQ->fetchAll(PDO::FETCH_ASSOC) as $r){$totals[$r['transaction_type']]+=(float)$r['total']; if($r['transaction_type']==='PAYMENT')$totals[$r['payment_mode']]=($totals[$r['payment_mode']]??0)+(float)$r['total'];}
$doctorsQ=$tenant_pdo->prepare("SELECT id,full_name FROM master_doctors WHERE org_id=? AND center_id=? AND status=1 ORDER BY full_name");$doctorsQ->execute([$org_id,$center_id]);$doctors=$doctorsQ->fetchAll(PDO::FETCH_ASSOC);
$visitsQ=$tenant_pdo->prepare("SELECT v.visit_id,v.receipt_no,v.token_no,p.fullname,p.uhid FROM opd_visits v JOIN patient_master p ON p.patient_id=v.patient_id WHERE v.org_id=? AND v.center_id=? ORDER BY v.visit_id DESC LIMIT 300");$visitsQ->execute([$org_id,$center_id]);$visits=$visitsQ->fetchAll(PDO::FETCH_ASSOC);
require_once __DIR__.'/layout_header.php';
?>
<div class="container-fluid py-3">
 <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div><h4 class="mb-0">Daily OPD Collection</h4><small class="text-muted">Payment and refund transaction register</small></div><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#entryModal">+ Add Payment / Refund</button></div>
 <?php if($msg):?><div class="alert alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
 <?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>
 <?php if(!empty($migration_error)):?><div class="alert alert-warning">Legacy payment import warning: <?=htmlspecialchars($migration_error)?></div><?php endif;?>
 <form class="row g-2 align-items-end mb-3" method="get"><div class="col-auto"><label class="form-label">From Date</label><input type="date" name="from_date" class="form-control" value="<?=htmlspecialchars($fromDate)?>" required></div><div class="col-auto"><label class="form-label">To Date</label><input type="date" name="to_date" class="form-control" value="<?=htmlspecialchars($toDate)?>" required></div><div class="col-auto"><label class="form-label">Doctor</label><select name="doctor_id" class="form-select"><option value="0">All Doctors</option><?php foreach($doctors as $doc):?><option value="<?=$doc['id']?>" <?=$doctorFilter===(int)$doc['id']?'selected':''?>><?=htmlspecialchars($doc['full_name'])?></option><?php endforeach;?></select></div><div class="col-auto"><label class="form-label">Mode</label><select name="mode" class="form-select"><option value="">All Modes</option><?php foreach(['Cash','UPI','Card','Net Banking'] as $m):?><option <?=$modeFilter===$m?'selected':''?>><?=$m?></option><?php endforeach;?></select></div><input type="hidden" name="tab" value="<?=strtolower($tab)==='refund'?'refunds':'collection'?>"><div class="col-auto"><button class="btn btn-dark">Apply</button> <a class="btn btn-outline-secondary" href="opd_daily_collection.php">Today</a></div></form>
 <div class="row g-2 mb-3"><div class="col-6 col-lg-3"><div class="card"><div class="card-body"><small class="text-muted">Total Collection</small><h4 class="mb-0">₹<?=number_format($totals['PAYMENT'],2)?></h4></div></div></div><div class="col-6 col-lg-3"><div class="card"><div class="card-body"><small class="text-muted">Total Refund</small><h4 class="mb-0 text-danger">₹<?=number_format($totals['REFUND'],2)?></h4></div></div></div><div class="col-6 col-lg-3"><div class="card"><div class="card-body"><small class="text-muted">Net Collection</small><h4 class="mb-0 text-success">₹<?=number_format($totals['PAYMENT']-$totals['REFUND'],2)?></h4></div></div></div><div class="col-6 col-lg-3"><div class="card"><div class="card-body"><small class="text-muted">Cash / UPI / Card / Bank</small><div class="small fw-semibold">₹<?=number_format($totals['Cash'],2)?> / ₹<?=number_format($totals['UPI'],2)?><br>₹<?=number_format($totals['Card'],2)?> / ₹<?=number_format($totals['Net Banking'],2)?></div></div></div></div></div>
 <ul class="nav nav-tabs mb-2"><li class="nav-item"><a class="nav-link <?=$tab==='PAYMENT'?'active':''?>" href="?from_date=<?=urlencode($fromDate)?>&to_date=<?=urlencode($toDate)?>&doctor_id=<?=$doctorFilter?>&mode=<?=urlencode($modeFilter)?>&tab=collection">Collections</a></li><li class="nav-item"><a class="nav-link <?=$tab==='REFUND'?'active':''?>" href="?from_date=<?=urlencode($fromDate)?>&to_date=<?=urlencode($toDate)?>&doctor_id=<?=$doctorFilter?>&mode=<?=urlencode($modeFilter)?>&tab=refunds">Refunds</a></li></ul>
 <div class="card"><div class="table-responsive"><table class="table table-sm table-striped table-hover mb-0"><thead><tr><th>Time</th><th>Receipt</th><th>Patient / UHID</th><th>Doctor</th><th>Mode</th><th>Reference</th><th class="text-end">Amount</th><th>Remarks</th></tr></thead><tbody>
 <?php if(!$transactions):?><tr><td colspan="8" class="text-center text-muted py-4">No <?=strtolower($tab)?> entries for this date range.</td></tr><?php else: foreach($transactions as $t):?><tr><td><?=date('h:i A',strtotime($t['created_at']))?></td><td><?=htmlspecialchars((string)($t['receipt_no'] ?? $t['source_id']))?></td><td><strong><?=htmlspecialchars($t['fullname'])?></strong><br><small><?=htmlspecialchars($t['uhid'] ?? '')?></small></td><td><?=htmlspecialchars($t['doctor_name'] ?? '-')?></td><td><?=htmlspecialchars($t['payment_mode'])?></td><td><?=htmlspecialchars($t['transaction_ref'] ?? '-')?></td><td class="text-end fw-bold">₹<?=number_format((float)$t['amount'],2)?></td><td><?=htmlspecialchars($t['remarks'] ?? '')?></td></tr><?php endforeach;endif;?>
 </tbody></table></div></div>
</div>
<div class="modal fade" id="entryModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><div class="modal-header"><h5 class="modal-title">Add Payment / Refund</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="save_transaction" value="1"><div class="mb-2"><label class="form-label">OPD Bill / Patient</label><select class="form-select" name="visit_id" required><option value="">Select bill</option><?php foreach($visits as $v):?><option value="<?=$v['visit_id']?>"><?=htmlspecialchars(($v['receipt_no']?:$v['visit_id']).' - '.$v['fullname'].' ('.$v['uhid'].')')?></option><?php endforeach;?></select></div><div class="row g-2"><div class="col-6"><label class="form-label">Type</label><select name="transaction_type" class="form-select"><option value="PAYMENT">Payment</option><option value="REFUND">Refund</option></select></div><div class="col-6"><label class="form-label">Date</label><input type="date" name="payment_date" class="form-control" value="<?=$today?>" required></div><div class="col-6"><label class="form-label">Mode</label><select name="payment_mode" class="form-select"><option>Cash</option><option>UPI</option><option>Card</option><option>Net Banking</option></select></div><div class="col-6"><label class="form-label">Amount</label><input type="number" name="amount" class="form-control" min="0.01" step="0.01" required></div><div class="col-12"><label class="form-label">UTR / Transaction Reference</label><input name="transaction_ref" class="form-control" maxlength="150"></div><div class="col-12"><label class="form-label">Remarks</label><input name="remarks" class="form-control"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Entry</button></div></form></div></div>
<?php require_once __DIR__.'/layout_footer.php'; ?>
