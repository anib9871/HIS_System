<?php
/** Central audit helper. Call within the same PDO transaction as the HIS change. */
if (!function_exists('audit_log_event')) {
    function audit_log_event(PDO $pdo, array $event): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $orgId = (int)($event['org_id'] ?? ($_SESSION['org_id'] ?? 0));
        if ($orgId <= 0) throw new RuntimeException('Audit requires a valid organization ID.');
        $userId = (int)($event['user_id'] ?? ($_SESSION['user_id'] ?? 0));
        $userName = trim((string)($_SESSION['user_name'] ?? $_SESSION['fullname'] ?? $_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
        $userRole = trim((string)($_SESSION['role_name'] ?? $_SESSION['role'] ?? $_SESSION['user_type'] ?? ((int)($_SESSION['is_admin'] ?? 0) === 1 ? 'ADMIN' : 'USER')));
        if ($userId > 0 && ($userName === '' || $userRole === 'USER')) {
            try {
                $s=$pdo->prepare('SELECT tu.fullname,tr.role_name,tr.role_type FROM tenant_users tu LEFT JOIN tenant_roles tr ON tr.role_id=tu.role_id WHERE tu.user_id=? LIMIT 1');
                $s->execute([$userId]); $a=$s->fetch(PDO::FETCH_ASSOC);
                if ($a) { if ($userName==='') $userName=trim((string)($a['fullname']??'')); if ($userRole==='USER') $userRole=trim((string)($a['role_name']??$a['role_type']??'USER')); }
            } catch (Throwable $ignored) {}
        }
        if ($userName==='') $userName=$userId>0?'USER #'.$userId:'SYSTEM';
        $eventId=sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',random_int(0,65535),random_int(0,65535),random_int(0,65535),random_int(0,4095)|16384,random_int(0,16383)|32768,random_int(0,65535),random_int(0,65535),random_int(0,65535));
        $s=$pdo->prepare('INSERT INTO audit_logs (event_id,org_id,center_id,user_id,user_name,user_role,action_type,module_name,table_name,record_id,patient_id,transaction_id,reason,ip_address,machine_name,request_method,request_url,changed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(6))');
        $s->execute([$eventId,$orgId,isset($event['center_id'])?(int)$event['center_id']:(int)($_SESSION['center_id']??0),$userId?:null,substr($userName,0,150),substr($userRole?:'USER',0,100),strtoupper((string)($event['action_type']??'UPDATE')),substr((string)($event['module_name']??'IPD'),0,100),$event['table_name']??null,isset($event['record_id'])?(string)$event['record_id']:null,isset($event['patient_id'])?(string)$event['patient_id']:null,isset($event['transaction_id'])?(string)$event['transaction_id']:null,$event['reason']??null,$_SERVER['REMOTE_ADDR']??null,$_SESSION['machine_name']??null,$_SERVER['REQUEST_METHOD']??null,substr((string)($_SERVER['REQUEST_URI']??''),0,500)]);
        $id=(int)$pdo->lastInsertId();
        if (!empty($event['changes']) && is_array($event['changes'])) {
            $c=$pdo->prepare('INSERT INTO audit_log_changes (audit_id,field_name,old_value,new_value) VALUES (?,?,?,?)');
            foreach ($event['changes'] as $x) { if (empty($x['field_name'])) continue; $old=$x['old_value']??null; $new=$x['new_value']??null; $c->execute([$id,substr((string)$x['field_name'],0,150),is_scalar($old)||$old===null?$old:json_encode($old,JSON_UNESCAPED_UNICODE),is_scalar($new)||$new===null?$new:json_encode($new,JSON_UNESCAPED_UNICODE)]); }
        }
        return $id;
    }
}
