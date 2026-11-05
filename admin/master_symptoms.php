<?php

// admin/master_symptoms.php

require_once __DIR__ . '/../config/tenant_db.php';

require_once __DIR__ . '/../config/alerts.php';



$page_title = "Symptom Master";

$org_id = (int)($_SESSION['org_id'] ?? 1);

$center_id = (int)($_SESSION['center_id'] ?? 1);

$created_by = (int)($_SESSION['user_id'] ?? 0);

// Department lookup scoped to the current organization and center.
$departmentRows=[]; $departmentIdColumn=null; $departmentNameColumn=null;
try {
 $dc=$tenant_pdo->query("SHOW COLUMNS FROM master_departments")->fetchAll(PDO::FETCH_ASSOC); $df=array_column($dc,'Field');
 foreach(['department_id','id'] as $c){if(in_array($c,$df,true)){$departmentIdColumn=$c;break;}}
 foreach(['department_name','dept_name','name'] as $c){if(in_array($c,$df,true)){$departmentNameColumn=$c;break;}}
 if($departmentIdColumn && $departmentNameColumn){
  $w=[];$p=[]; if(in_array('org_id',$df,true)){$w[]='org_id = ?';$p[]=$org_id;}
  if(in_array('center_id',$df,true)){$w[]='center_id = ?';$p[]=$center_id;}
  if(in_array('status',$df,true)){$w[]='status = 1';}
  $q="SELECT `$departmentIdColumn` AS department_id, `$departmentNameColumn` AS department_name FROM master_departments";
  if($w)$q.=" WHERE ".implode(" AND ",$w); $q.=" ORDER BY `$departmentNameColumn`";
  $ds=$tenant_pdo->prepare($q);$ds->execute($p);$departmentRows=$ds->fetchAll(PDO::FETCH_ASSOC);
 }
} catch(Throwable $e){error_log('Symptom department lookup failed: '.$e->getMessage());}
$departmentIdByName=[];foreach($departmentRows as $d){$departmentIdByName[strtoupper(trim($d['department_name']))]=(int)$d['department_id'];}
function getSymptomDepartmentId($n,$map){
 $n=strtoupper(trim((string)$n));
 foreach(['CHILD','CHILDHOOD','PAEDIATRIC','PEDIATRIC','NEWBORN','NEONATAL','INFANT','BEDWETTING','POOR FEEDING'] as $t){if(strpos($n,$t)!==false && isset($map['PAEDIATRICS']))return $map['PAEDIATRICS'];}
 foreach(['CHEST PAIN','PALPITATION','BRADYCARDIA','TACHYCARDIA'] as $t){if(strpos($n,$t)!==false && isset($map['CARDIOLOGY']))return $map['CARDIOLOGY'];}
 foreach(['X-RAY','ULTRASOUND','CT ','MRI ','IMAGING'] as $t){if(strpos($n,$t)!==false && isset($map['RADIOLOGY']))return $map['RADIOLOGY'];}
 return $map['MEDICINE']??($map['GENERAL MEDICINE']??0);
}


// Seed common symptom master entries for each organization/center without overwriting existing data.
$defaultSymptoms = [['FEVER', 'Elevated body temperature'], ['HEADACHE', 'Pain in the head'], ['COUGH', 'Coughing'], ['COLD', 'Common cold symptoms'], ['RUNNY NOSE', 'Nasal discharge'], ['SORE THROAT', 'Throat pain or irritation'], ['SNEEZING', 'Repeated sneezing'], ['NASAL CONGESTION', 'Blocked nose'], ['BODY ACHE', 'Generalized body pain'], ['FATIGUE', 'Tiredness or lack of energy'], ['WEAKNESS', 'General weakness'], ['DIZZINESS', 'Feeling dizzy'], ['VERTIGO', 'Sensation of spinning'], ['NAUSEA', 'Feeling sick or urge to vomit'], ['VOMITING', 'Vomiting'], ['DIARRHEA', 'Frequent loose stools'], ['CONSTIPATION', 'Difficulty passing stools'], ['ABDOMINAL PAIN', 'Pain in abdominal region'], ['STOMACH BLOATING', 'Abdominal fullness or bloating'], ['ACIDITY', 'Burning or acid-related discomfort'], ['HEARTBURN', 'Burning sensation in chest or upper abdomen'], ['LOSS OF APPETITE', 'Reduced desire to eat'], ['INDIGESTION', 'Difficulty digesting food'], ['GAS', 'Excess intestinal gas'], ['CHEST PAIN', 'Pain or discomfort in chest'], ['SHORTNESS OF BREATH', 'Difficulty breathing'], ['WHEEZING', 'Whistling sound while breathing'], ['PALPITATION', 'Awareness of rapid or strong heartbeat'], ['SWELLING', 'Abnormal swelling'], ['LOCALIZED SWELLING', 'Localized lump or enlargement'], ['JOINT PAIN', 'Pain in a joint'], ['JOINT STIFFNESS', 'Reduced range of joint movement'], ['BACK PAIN', 'Pain in the back'], ['LOWER BACK PAIN', 'Pain in lower back'], ['NECK PAIN', 'Pain in the neck'], ['MUSCLE PAIN', 'Muscle aches'], ['LEG PAIN', 'Pain in leg'], ['KNEE PAIN', 'Pain in knee'], ['SHOULDER PAIN', 'Pain in shoulder'], ['TOOTHACHE', 'Pain in a tooth'], ['GUM PAIN', 'Pain in gums'], ['EAR PAIN', 'Pain in ear'], ['EAR DISCHARGE', 'Fluid discharge from ear'], ['EYE PAIN', 'Pain in eye'], ['RED EYE', 'Redness of eye'], ['BLURRED VISION', 'Reduced clarity of vision'], ['ITCHY EYES', 'Itching of eyes'], ['WATERY EYES', 'Excess tearing'], ['SKIN RASH', 'Abnormal skin eruption'], ['ITCHING', 'Skin itching'], ['DRY SKIN', 'Dryness of skin'], ['SKIN REDNESS', 'Redness of skin'], ['HAIR LOSS', 'Loss of hair'], ['BURNING URINATION', 'Burning sensation during urination'], ['FREQUENT URINATION', 'Urinating more frequently than usual'], ['PAINFUL URINATION', 'Pain during urination'], ['BLOOD IN URINE', 'Blood seen in urine'], ['URINARY URGENCY', 'Sudden urgent need to urinate'], ['INCONTINENCE', 'Loss of bladder control'], ['EXCESSIVE THIRST', 'Increased thirst'], ['EXCESSIVE HUNGER', 'Increased hunger'], ['UNINTENTIONAL WEIGHT LOSS', 'Weight loss without trying'], ['WEIGHT GAIN', 'Increase in body weight'], ['NIGHT SWEATS', 'Excessive sweating during sleep'], ['EXCESSIVE SWEATING', 'Sweating more than usual'], ['CHILLS', 'Feeling cold with shivering'], ['TREMORS', 'Involuntary shaking'], ['NUMBNESS', 'Reduced or absent sensation'], ['TINGLING', 'Pins-and-needles sensation'], ['ANXIETY', 'Feeling anxious or worried'], ['INSOMNIA', 'Difficulty sleeping'], ['LOW MOOD', 'Persistent low mood'], ['MEMORY PROBLEMS', 'Difficulty remembering'], ['FAINTING', 'Brief loss of consciousness'], ['SEIZURE', 'Episode of abnormal electrical activity'], ['BLEEDING', 'Bleeding symptom'], ['EASY BRUISING', 'Bruising easily'], ['PALLOR', 'Unusual paleness'], ['SWOLLEN LYMPH NODES', 'Enlarged lymph nodes'], ['MOUTH ULCERS', 'Ulcers inside the mouth'], ['DIFFICULTY SWALLOWING', 'Difficulty swallowing'], ['HOARSENESS', 'Change in voice quality'], ['SNORING', 'Noisy breathing during sleep'], ['SLEEPINESS', 'Excessive daytime sleepiness'], ['POOR FEEDING', 'Reduced feeding'], ['BEDWETTING', 'Involuntary urination during sleep'], ['MENSTRUAL PAIN', 'Pain during menstruation'], ['IRREGULAR MENSTRUATION', 'Irregular menstrual periods'], ['HEAVY MENSTRUAL BLEEDING', 'Heavy menstrual flow'], ['VAGINAL DISCHARGE', 'Vaginal discharge'], ['PELVIC PAIN', 'Pain in pelvic region'], ['BREAST PAIN', 'Pain in breast'], ['BREAST LUMP', 'Lump in breast'], ['RECTAL PAIN', 'Pain in rectal area'], ['HEMORRHOIDS', 'Symptoms related to hemorrhoids'], ['ANAL ITCHING', 'Itching around anus'], ['BLUNT TRAUMA', 'Pain or injury following blunt trauma'], ['BRADYCARDIA', 'Slow heart rate'], ['TACHYCARDIA', 'Fast heart rate'], ['RESPIRATORY DISTRESS', 'Difficulty or distress with breathing'], ['FEVER WITH RASH', 'Fever accompanied by rash'], ['DEHYDRATION', 'Symptoms related to fluid loss'], ['LOSS OF TASTE', 'Reduced or absent taste'], ['LOSS OF SMELL', 'Reduced or absent smell'], ['HOARSENESS OF VOICE', 'Hoarse voice'], ['GENERAL DISCOMFORT', 'General feeling of discomfort']];

$additionalDepartmentSymptoms = [
    ['MEDICINE', 'FEVER WITH CHILLS', 'Fever accompanied by chills or shivering'],
    ['MEDICINE', 'PERSISTENT FEVER', 'Fever lasting longer than expected'],
    ['MEDICINE', 'DRY COUGH', 'Cough without sputum'],
    ['MEDICINE', 'PRODUCTIVE COUGH', 'Cough with sputum'],
    ['MEDICINE', 'BLOOD IN SPUTUM', 'Blood-streaked sputum'],
    ['MEDICINE', 'THROAT IRRITATION', 'Irritation in the throat'],
    ['MEDICINE', 'SINUS PAIN', 'Pain or pressure around the sinuses'],
    ['MEDICINE', 'FACIAL PAIN', 'Pain in the face'],
    ['MEDICINE', 'MOUTH DRYNESS', 'Dry mouth'],
    ['MEDICINE', 'BAD BREATH', 'Unpleasant breath odor'],
    ['MEDICINE', 'LOSS OF VOICE', 'Temporary or persistent loss of voice'],
    ['MEDICINE', 'CHEST TIGHTNESS', 'Tight or constricted feeling in chest'],
    ['MEDICINE', 'UPPER ABDOMINAL PAIN', 'Pain in upper abdomen'],
    ['MEDICINE', 'LOWER ABDOMINAL PAIN', 'Pain in lower abdomen'],
    ['MEDICINE', 'RIGHT UPPER ABDOMINAL PAIN', 'Pain in right upper abdomen'],
    ['MEDICINE', 'LEFT UPPER ABDOMINAL PAIN', 'Pain in left upper abdomen'],
    ['MEDICINE', 'ABDOMINAL CRAMPS', 'Cramping abdominal pain'],
    ['MEDICINE', 'PAINFUL SWALLOWING', 'Pain while swallowing'],
    ['MEDICINE', 'BELCHING', 'Frequent belching'],
    ['MEDICINE', 'REGURGITATION', 'Backflow of stomach contents'],
    ['MEDICINE', 'BLACK STOOLS', 'Black or tarry stools'],
    ['MEDICINE', 'BLOOD IN STOOL', 'Blood noticed in stool'],
    ['MEDICINE', 'MUCUS IN STOOL', 'Mucus in stool'],
    ['MEDICINE', 'ANAL PAIN', 'Pain around the anus'],
    ['MEDICINE', 'SWOLLEN TONGUE', 'Tongue swelling'],
    ['MEDICINE', 'TONGUE ULCER', 'Sore or ulcer on tongue'],
    ['MEDICINE', 'GENERALIZED ITCHING', 'Itching over multiple body areas'],
    ['MEDICINE', 'HIVES', 'Raised itchy skin welts'],
    ['MEDICINE', 'SKIN PEELING', 'Peeling skin'],
    ['MEDICINE', 'SKIN LESION', 'Unusual skin lesion'],
    ['MEDICINE', 'PIGMENTATION CHANGE', 'Change in skin colour'],
    ['MEDICINE', 'NON-HEALING WOUND', 'Wound that is slow to heal'],
    ['MEDICINE', 'FOOT SWELLING', 'Swelling of feet'],
    ['MEDICINE', 'ANKLE SWELLING', 'Swelling around ankle'],
    ['MEDICINE', 'HAND SWELLING', 'Swelling of hands'],
    ['MEDICINE', 'FACE SWELLING', 'Swelling of face'],
    ['MEDICINE', 'CALF PAIN', 'Pain in calf'],
    ['MEDICINE', 'HEEL PAIN', 'Pain in heel'],
    ['MEDICINE', 'FOOT PAIN', 'Pain in foot'],
    ['MEDICINE', 'WRIST PAIN', 'Pain in wrist'],
    ['MEDICINE', 'ELBOW PAIN', 'Pain in elbow'],
    ['MEDICINE', 'HIP PAIN', 'Pain in hip'],
    ['MEDICINE', 'MORNING STIFFNESS', 'Joint or body stiffness after waking'],
    ['MEDICINE', 'PAINFUL LYMPH NODES', 'Tender or painful lymph nodes'],
    ['MEDICINE', 'RECURRENT INFECTIONS', 'Repeated infections'],
    ['MEDICINE', 'EXCESSIVE URINATION', 'Passing unusually large amounts of urine'],
    ['MEDICINE', 'FOAMY URINE', 'Foamy appearance of urine'],
    ['MEDICINE', 'URINE LEAKAGE', 'Unintentional urine leakage'],
    ['MEDICINE', 'FLANK PAIN', 'Pain in side between ribs and hip'],
    ['MEDICINE', 'INCREASED APPETITE', 'Increased hunger or appetite'],
    ['MEDICINE', 'MOUTH THRUSH', 'White patches or soreness in mouth'],
    ['MEDICINE', 'POST-NASAL DRIP', 'Mucus dripping at back of throat'],
    ['MEDICINE', 'EAR RINGING', 'Ringing or buzzing in ears'],
    ['MEDICINE', 'HEARING LOSS', 'Reduced ability to hear'],
    ['MEDICINE', 'NASAL BLEEDING', 'Bleeding from nose'],
    ['MEDICINE', 'SENSITIVE TEETH', 'Pain or sensitivity in teeth'],
    ['MEDICINE', 'SWOLLEN GUMS', 'Swelling of gums'],
    ['MEDICINE', 'COLD INTOLERANCE', 'Unusual sensitivity to cold'],
    ['MEDICINE', 'HEAT INTOLERANCE', 'Unusual sensitivity to heat'],
    ['CARDIOLOGY', 'CHEST DISCOMFORT', 'Discomfort or pressure in the chest'],
    ['CARDIOLOGY', 'CHEST PRESSURE', 'Pressure or heaviness in chest'],
    ['CARDIOLOGY', 'EXERTIONAL CHEST PAIN', 'Chest pain during physical activity'],
    ['CARDIOLOGY', 'RACING HEARTBEAT', 'Sensation of very fast heartbeat'],
    ['CARDIOLOGY', 'IRREGULAR HEARTBEAT', 'Sensation of an irregular heartbeat'],
    ['CARDIOLOGY', 'SKIPPED HEARTBEATS', 'Sensation of missed or skipped beats'],
    ['CARDIOLOGY', 'EXERTIONAL BREATHLESSNESS', 'Shortness of breath during activity'],
    ['CARDIOLOGY', 'BREATHLESSNESS WHEN LYING DOWN', 'Breathing difficulty while lying flat'],
    ['CARDIOLOGY', 'NIGHT-TIME BREATHLESSNESS', 'Sudden breathlessness during sleep'],
    ['CARDIOLOGY', 'FAINTING ON EXERTION', 'Fainting during or after exertion'],
    ['CARDIOLOGY', 'LEG SWELLING', 'Swelling of lower limbs'],
    ['CARDIOLOGY', 'BILATERAL ANKLE EDEMA', 'Swelling of both ankles'],
    ['CARDIOLOGY', 'CALF CRAMPING ON WALKING', 'Calf pain triggered by walking'],
    ['CARDIOLOGY', 'COLD HANDS AND FEET', 'Unusually cold extremities'],
    ['CARDIOLOGY', 'BLUE DISCOLORATION OF LIPS', 'Bluish colour of lips'],
    ['CARDIOLOGY', 'REDUCED EXERCISE TOLERANCE', 'Reduced ability to perform physical activity'],
    ['CARDIOLOGY', 'PULSATING SENSATION', 'Unusual pulsating sensation'],
    ['CARDIOLOGY', 'DIZZINESS WITH PALPITATIONS', 'Dizziness occurring with palpitations'],
    ['CARDIOLOGY', 'CHEST PAIN RADIATING TO ARM', 'Chest pain spreading to arm'],
    ['CARDIOLOGY', 'CHEST PAIN RADIATING TO JAW', 'Chest pain spreading to jaw'],
    ['PAEDIATRICS', 'POOR WEIGHT GAIN', 'Child not gaining weight as expected'],
    ['PAEDIATRICS', 'FAILURE TO THRIVE', 'Poor growth or weight gain in a child'],
    ['PAEDIATRICS', 'DELAYED DEVELOPMENT', 'Delay in developmental milestones'],
    ['PAEDIATRICS', 'DELAYED SPEECH', 'Speech development later than expected'],
    ['PAEDIATRICS', 'DELAYED WALKING', 'Delay in walking milestone'],
    ['PAEDIATRICS', 'EXCESSIVE CRYING', 'Persistent or excessive crying'],
    ['PAEDIATRICS', 'COLIC', 'Episodes of excessive infant crying'],
    ['PAEDIATRICS', 'INFANT FEEDING DIFFICULTY', 'Difficulty feeding an infant'],
    ['PAEDIATRICS', 'BREASTFEEDING DIFFICULTY', 'Difficulty with breastfeeding'],
    ['PAEDIATRICS', 'REFUSAL TO FEED', 'Child refusing feeds'],
    ['PAEDIATRICS', 'POOR SUCKING', 'Weak sucking during feeds'],
    ['PAEDIATRICS', 'DIAPER RASH', 'Rash in diaper area'],
    ['PAEDIATRICS', 'NAPKIN DERMATITIS', 'Inflammation of diaper-covered skin'],
    ['PAEDIATRICS', 'TEETHING DISCOMFORT', 'Discomfort associated with teething'],
    ['PAEDIATRICS', 'EAR PULLING', 'Child repeatedly pulling at ear'],
    ['PAEDIATRICS', 'FEBRILE SEIZURE', 'Seizure associated with fever in a child'],
    ['PAEDIATRICS', 'WHEEZE IN CHILD', 'Wheezing in a child'],
    ['PAEDIATRICS', 'FAST BREATHING IN CHILD', 'Breathing faster than usual'],
    ['PAEDIATRICS', 'CHEST INDRAWING', 'Chest wall pulling inward during breathing'],
    ['PAEDIATRICS', 'CHILDHOOD CONSTIPATION', 'Constipation in a child'],
    ['PAEDIATRICS', 'CHILDHOOD DIARRHEA', 'Loose stools in a child'],
    ['PAEDIATRICS', 'BEDWETTING', 'Involuntary urination during sleep'],
    ['PAEDIATRICS', 'DAYTIME WETTING', 'Unintentional urination during daytime'],
    ['PAEDIATRICS', 'NIGHT TERRORS', 'Episodes of intense fear during sleep'],
    ['PAEDIATRICS', 'SLEEP DISTURBANCE IN CHILD', 'Difficulty sleeping in a child'],
    ['PAEDIATRICS', 'HYPERACTIVITY', 'Excessive activity or restlessness'],
    ['PAEDIATRICS', 'ATTENTION DIFFICULTY', 'Difficulty sustaining attention'],
    ['PAEDIATRICS', 'RECURRENT CHILDHOOD FEVER', 'Repeated episodes of fever in a child'],
    ['PAEDIATRICS', 'CHILDHOOD SKIN RASH', 'Skin rash in a child'],
    ['PAEDIATRICS', 'NEWBORN JAUNDICE', 'Yellowing of skin or eyes in a newborn'],
    ['PAEDIATRICS', 'UMBILICAL DISCHARGE', 'Discharge from newborn umbilicus'],
    ['PAEDIATRICS', 'NEONATAL LETHARGY', 'Unusual reduced activity in a newborn'],
    ['PAEDIATRICS', 'INFANT VOMITING', 'Vomiting in an infant'],
    ['PAEDIATRICS', 'CHILDHOOD ABDOMINAL PAIN', 'Abdominal pain in a child'],
    ['PAEDIATRICS', 'GROWING PAINS', 'Recurrent limb pain in a growing child'],
    ['PAEDIATRICS', 'CHILDHOOD HEADACHE', 'Headache in a child'],
    ['PAEDIATRICS', 'CHILDHOOD SORE THROAT', 'Sore throat in a child'],
    ['PAEDIATRICS', 'CHILDHOOD NASAL CONGESTION', 'Blocked nose in a child'],
    ['RADIOLOGY', 'BONE PAIN', 'Pain localized to a bone'],
    ['RADIOLOGY', 'BONE TENDERNESS', 'Tenderness over a bone'],
    ['RADIOLOGY', 'SUSPECTED FRACTURE', 'Pain or deformity following injury; fracture needs assessment'],
    ['RADIOLOGY', 'LIMB DEFORMITY', 'Abnormal shape or alignment of a limb'],
    ['RADIOLOGY', 'JOINT DEFORMITY', 'Abnormal shape or alignment of a joint'],
    ['RADIOLOGY', 'POST-TRAUMATIC PAIN', 'Pain following an injury'],
    ['RADIOLOGY', 'PERSISTENT BACK PAIN', 'Back pain requiring further assessment'],
    ['RADIOLOGY', 'PERSISTENT NECK PAIN', 'Neck pain requiring further assessment'],
    ['RADIOLOGY', 'ABDOMINAL DISTENSION', 'Visible or felt abdominal enlargement'],
    ['RADIOLOGY', 'ABDOMINAL MASS', 'Lump or mass felt in abdomen'],
    ['RADIOLOGY', 'PELVIC PAIN', 'Pain in pelvic region requiring assessment'],
    ['RADIOLOGY', 'RENAL COLIC', 'Severe intermittent pain suggestive of renal colic'],
    ['RADIOLOGY', 'SWELLING AFTER INJURY', 'Swelling following trauma'],
    ['RADIOLOGY', 'SOFT TISSUE LUMP', 'Lump in soft tissue'],
    ['RADIOLOGY', 'NECK LUMP', 'Lump in neck'],
    ['RADIOLOGY', 'THYROID SWELLING', 'Swelling in thyroid region'],
    ['RADIOLOGY', 'UNEXPLAINED LIMB PAIN', 'Limb pain requiring diagnostic assessment'],
    ['RADIOLOGY', 'SHOULDER INJURY PAIN', 'Shoulder pain after injury'],
    ['RADIOLOGY', 'KNEE INJURY PAIN', 'Knee pain after injury'],
    ['RADIOLOGY', 'HIP PAIN AFTER FALL', 'Hip pain following a fall'],
    ['RADIOLOGY', 'HEAD INJURY SYMPTOMS', 'Symptoms following head injury'],
    ['RADIOLOGY', 'CHEST INJURY PAIN', 'Chest wall pain following injury'],
    ['RADIOLOGY', 'SUSPECTED FOREIGN BODY', 'Possible retained foreign body'],
    ['RADIOLOGY', 'LIMPING', 'Abnormal or painful walking'],
    ['RADIOLOGY', 'SPINAL TENDERNESS', 'Tenderness along the spine']
];


try {
    $checkSymptom = $tenant_pdo->prepare('SELECT id, department_id FROM master_symptoms WHERE org_id = ? AND center_id = ? AND LOWER(TRIM(symptom_name)) = LOWER(TRIM(?)) LIMIT 1');
    $insertSymptom = $tenant_pdo->prepare('INSERT INTO master_symptoms (org_id, center_id, department_id, symptom_name, symptom_code, description, status, created_by) VALUES (?, ?, ?, ?, NULL, ?, 1, ?)');
    $backfillSymptom=$tenant_pdo->prepare('UPDATE master_symptoms SET department_id=? WHERE id=? AND (department_id IS NULL OR department_id=0)');
    foreach($defaultSymptoms as $item){
      $target=getSymptomDepartmentId($item[0],$departmentIdByName);
      $checkSymptom->execute([$org_id,$center_id,$item[0]]);$existing=$checkSymptom->fetch(PDO::FETCH_ASSOC);
      if(!$existing){$insertSymptom->execute([$org_id,$center_id,$target?:null,$item[0],$item[1],$created_by?:null]);}
      elseif(empty($existing['department_id']) && $target>0){$backfillSymptom->execute([$target,(int)$existing['id']]);}
    }
} catch (PDOException $e) { error_log('Symptom defaults seed failed: ' . $e->getMessage()); }




// Seed additional common symptoms by department. Existing entries are never overwritten.
try {
    $findDepartmentSymptom = $tenant_pdo->prepare(
        "SELECT id FROM master_symptoms
         WHERE org_id = ? AND center_id = ? AND department_id = ?
           AND LOWER(TRIM(symptom_name)) = LOWER(TRIM(?)) LIMIT 1"
    );
    $addDepartmentSymptom = $tenant_pdo->prepare(
        "INSERT INTO master_symptoms
         (org_id, center_id, department_id, symptom_name, symptom_code, description, status, created_by)
         VALUES (?, ?, ?, ?, NULL, ?, 1, ?)"
    );
    foreach ($additionalDepartmentSymptoms as $entry) {
        [$departmentName, $symptomName, $symptomDescription] = $entry;
        $departmentId = $departmentIdByName[$departmentName] ?? 0;
        if ($departmentId <= 0) continue;
        $findDepartmentSymptom->execute([$org_id, $center_id, $departmentId, $symptomName]);
        if (!$findDepartmentSymptom->fetchColumn()) {
            $addDepartmentSymptom->execute([$org_id, $center_id, $departmentId, $symptomName, $symptomDescription, $created_by ?: null]);
        }
    }
} catch (PDOException $e) {
    error_log('Extended department symptom seed failed: ' . $e->getMessage());
}

// Save / Update

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_symptom'])) {



    $symptom_name = strtoupper(trim($_POST['symptom_name'] ?? ''));
    $department_id = (int)($_POST['department_id'] ?? 0);

    $symptom_code = strtoupper(trim($_POST['symptom_code'] ?? ''));

    $description  = trim($_POST['description'] ?? '');

    $status       = isset($_POST['status']) ? 1 : 0;

    $edit_id      = (int)($_POST['edit_id'] ?? 0);



    if ($department_id <= 0) { $_SESSION['symptom_alert']=['type'=>'error','message'=>'Please select a department.']; header('Location: master_symptoms.php'); exit; }
    elseif ($symptom_name === '') {

        $_SESSION['symptom_alert'] = ['type' => 'error', 'message' => 'Symptom Name is required.'];

    } else {

        try {
            // Server-side duplicate guard; exclude the current row during edit.
            $duplicateCheck = $tenant_pdo->prepare("SELECT id FROM master_symptoms WHERE org_id = ? AND center_id = ? AND department_id = ? AND LOWER(TRIM(symptom_name)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1");
            $duplicateCheck->execute([$org_id, $center_id, $department_id, $symptom_name, $edit_id]);
            if ($duplicateCheck->fetchColumn()) {
                $_SESSION['symptom_alert'] = ['type' => 'error', 'message' => 'This symptom already exists. Please use the existing record.'];
                header("Location: master_symptoms.php");
                exit;
            }

            if ($edit_id > 0) {

                $stmt = $tenant_pdo->prepare("

                    UPDATE master_symptoms

                    SET department_id = ?,

                        symptom_name = ?,

                        symptom_code = ?,

                        description = ?,

                        status = ?

                    WHERE id = ? AND org_id = ? AND center_id = ?

                ");



                $stmt->execute([

                    $department_id,

                    $symptom_name,

                    $symptom_code !== '' ? $symptom_code : null,

                    $description !== '' ? $description : null,

                    $status,

                    $edit_id,

                    $org_id,

                    $center_id

                ]);



                $_SESSION['symptom_alert'] = ['type' => 'success', 'message' => 'Symptom updated successfully.'];

            } else {

                $stmt = $tenant_pdo->prepare("

                    INSERT INTO master_symptoms

                    (

                        org_id,

                        center_id,

                        department_id,

                        symptom_name,

                        symptom_code,

                        description,

                        status,

                        created_by

                    )

                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)

                ");



                $stmt->execute([

                    $org_id,

                    $center_id,

                    $department_id,

                    $symptom_name,

                    $symptom_code !== '' ? $symptom_code : null,

                    $description !== '' ? $description : null,

                    $status,

                    $created_by ?: null

                ]);



                $_SESSION['symptom_alert'] = ['type' => 'success', 'message' => 'Symptom added successfully.'];

            }



            header("Location: master_symptoms.php");

            exit;

        } catch (PDOException $e) {

            $_SESSION['symptom_alert'] = ['type' => 'error', 'message' => 'Database error: ' . $e->getMessage()];

        }

    }

}



// Toggle Status

if (isset($_GET['toggle_status'])) {

    $id = (int)$_GET['toggle_status'];

    $st = (int)($_GET['st'] ?? 1) === 1 ? 0 : 1;



    $tenant_pdo->prepare("

        UPDATE master_symptoms

        SET status = ?

        WHERE id = ? AND org_id = ? AND center_id = ?

    ")->execute([$st, $id, $org_id, $center_id]);



    header("Location: master_symptoms.php");

    exit;

}



// List

$symptoms = $tenant_pdo->prepare("
 SELECT s.*, md.department_name FROM master_symptoms s
 LEFT JOIN (SELECT `$departmentIdColumn` AS department_id, `$departmentNameColumn` AS department_name FROM master_departments) md
 ON md.department_id=s.department_id
 WHERE s.org_id=? AND s.center_id=? ORDER BY s.id DESC
");

$symptoms->execute([$org_id, $center_id]);

$symptoms = $symptoms->fetchAll() ?: [];



require_once __DIR__ . '/layout_header.php';

?>



<div class="row g-2">

    <!-- Form Section -->

    <div class="col-lg-4">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-2 border-bottom">

                <span class="fw-bold small text-dark" id="formTitle">

                    <i class="bi bi-activity text-primary me-1"></i> ADD SYMPTOM

                </span>

            </div>



            <div class="card-body p-2">

                <form method="POST" action="">

                    <input type="hidden" name="action_symptom" value="1">

                    <input type="hidden" name="edit_id" id="edit_id" value="0">



                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">DEPARTMENT *</label>
                        <select name="department_id" id="department_id" class="form-select form-select-sm" required onchange="filterSymptomsByDepartment()">
                            <option value="">SELECT DEPARTMENT</option>
                            <?php foreach ($departmentRows as $department): ?>
                                <option value="<?= (int)$department['department_id'] ?>"><?= htmlspecialchars($department['department_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-8">

                            <label class="form-label small fw-semibold text-secondary mb-1">SYMPTOM NAME *</label>

                            <input type="text" name="symptom_name" id="symptom_name" class="form-control form-control-sm text-uppercase" maxlength="200" required autofocus>
<div class="tw-existing-suggestions" id="twSymptomSuggestions" role="listbox"></div>

                            <div id="symptomDuplicateMsg">This symptom already exists.</div>

                        </div>

                        <div class="col-4">

                            <label class="form-label small fw-semibold text-secondary mb-1">CODE</label>

                            <input type="text" name="symptom_code" id="symptom_code" class="form-control form-control-sm font-monospace text-uppercase" maxlength="50">

                        </div>

                    </div>



                    <div class="mb-2">

                        <label class="form-label small fw-semibold text-secondary mb-1">DESCRIPTION</label>

                        <textarea name="description" id="description" class="form-control form-control-sm" rows="2" maxlength="255"></textarea>

                    </div>



                    <div class="form-check form-switch mb-2">

                        <input class="form-check-input" type="checkbox" name="status" id="status" checked>

                        <label class="form-check-label small fw-semibold text-secondary" for="status">ACTIVE STATUS</label>

                    </div>



                    <div class="d-flex gap-2 border-top pt-2">

                        <button type="reset" class="btn btn-light btn-sm border px-3" onclick="resetForm()">RESET</button>

                        <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold" id="btnSubmit">SAVE</button>

                    </div>

                </form>

            </div>

        </div>

    </div>



    <!-- List Section -->

    <div class="col-lg-8">

        <div class="card border shadow-sm">

            <div class="card-header bg-white py-2 px-2 border-bottom d-flex justify-content-between align-items-center">

                <span class="fw-bold small text-dark text-nowrap">

                    <i class="bi bi-list-task text-primary me-1"></i> SYMPTOMS LIST

                </span>



                <div class="d-flex align-items-center gap-2 w-50">

                    <div class="input-group input-group-sm">

                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>

                        <input type="text" id="searchInput" class="form-control border-start-0" placeholder="Search symptom, code...">

                    </div>

                    <span class="badge bg-light text-secondary border text-nowrap" id="recordCount">

                        <?= count($symptoms) ?> RECORDS

                    </span>

                </div>

            </div>



            <div class="card-body p-0">

                <div class="table-responsive" style="max-height: 75vh; overflow-y: auto;">

                    <table class="table table-sm table-hover align-middle mb-0" id="symptomsTable" style="font-size: 0.85rem;">

                        <thead class="table-light sticky-top">

                            <tr>

                                <th class="ps-2" style="width: 50px;">#</th>

                                <th>DEPARTMENT</th>
                                <th>SYMPTOM</th>

                                <th>CODE</th>

                                <th>DESCRIPTION</th>

                                <th>STATUS</th>

                                <th class="text-end pe-2">ACTION</th>

                            </tr>

                        </thead>



                        <tbody>

                            <?php if (empty($symptoms)): ?>

                                <tr id="noDataRow">

                                    <td colspan="7" class="text-center py-3 text-muted">NO SYMPTOMS FOUND.</td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($symptoms as $s): ?>

                                    <?php $is_act = (int)$s['status'] === 1; ?>

                                    <tr class="searchable-row" data-department-id="<?= (int)($s['department_id'] ?? 0) ?>">

                                        <td class="ps-2 font-monospace text-muted">#<?= (int)$s['id'] ?></td>

                                        <td><?= htmlspecialchars($s['department_name'] ?? 'NOT MAPPED') ?></td>
                                        <td class="fw-bold text-dark text-uppercase"><?= htmlspecialchars($s['symptom_name']) ?></td>

                                        <td>

                                            <?php if (!empty($s['symptom_code'])): ?>

                                                <span class="badge bg-light text-primary border font-monospace text-uppercase"><?= htmlspecialchars($s['symptom_code']) ?></span>

                                            <?php else: ?>

                                                <span class="text-muted">-</span>

                                            <?php endif; ?>

                                        </td>

                                        <td class="text-secondary text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($s['description'] ?? '') ?>">

                                            <?= htmlspecialchars($s['description'] ?? '') ?: '-' ?>

                                        </td>

                                        <td>

                                            <a href="?toggle_status=<?= (int)$s['id'] ?>&st=<?= $is_act ? 1 : 0 ?>" 

                                               class="badge text-decoration-none <?= $is_act ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">

                                                <?= $is_act ? 'ACTIVE' : 'INACTIVE' ?>

                                            </a>

                                        </td>

                                        <td class="text-end pe-2">

                                            <button class="btn btn-outline-primary btn-sm py-0 px-1" 

                                                    onclick='editRow(<?= json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' 

                                                    title="EDIT">

                                                <i class="bi bi-pencil" style="font-size: 0.8rem;"></i>

                                            </button>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

</div>



<style>
#symptomAlertOverlay{position:fixed;inset:0;background:rgba(15,23,42,.28);display:none;align-items:center;justify-content:center;z-index:20000}
#symptomAlertBox{width:min(360px,calc(100vw - 32px));background:#fff;border-radius:16px;padding:22px 24px;box-shadow:0 18px 55px rgba(0,0,0,.22);text-align:center;font-family:inherit}
#symptomAlertIcon{width:48px;height:48px;border:3px solid #16a34a;color:#16a34a;border-radius:50%;font-size:30px;line-height:42px;margin:0 auto 12px}
#symptomAlertTitle{font-size:20px;font-weight:700;margin-bottom:7px;color:#172033}#symptomAlertText{font-size:14px;color:#64748b;margin-bottom:16px;overflow-wrap:anywhere}
#symptomAlertOk{border:0;border-radius:8px;padding:9px 28px;background:#2563eb;color:white;font-weight:600;display:none}
#symptomAlertProgress{height:3px;background:#e5e7eb;margin-top:17px;overflow:hidden;border-radius:3px}#symptomAlertProgress span{display:block;height:100%;width:100%;background:#16a34a}
#symptomDuplicateMsg{display:none;font-size:12px;color:#dc2626;margin-top:4px;font-weight:600}
</style>
<div id="symptomAlertOverlay" role="alertdialog" aria-modal="true"><div id="symptomAlertBox"><div id="symptomAlertIcon">✓</div><div id="symptomAlertTitle">Successful!</div><div id="symptomAlertText"></div><button id="symptomAlertOk" type="button">OK</button><div id="symptomAlertProgress"><span></span></div></div></div>
<script>
(function(){const overlay=document.getElementById('symptomAlertOverlay'),icon=document.getElementById('symptomAlertIcon'),title=document.getElementById('symptomAlertTitle'),msg=document.getElementById('symptomAlertText'),ok=document.getElementById('symptomAlertOk'),bar=document.querySelector('#symptomAlertProgress span');let timer=null;window.showSymptomAlert=function(type,message){clearTimeout(timer);const success=type==='success';overlay.style.display='flex';icon.textContent=success?'✓':'!';icon.style.borderColor=success?'#16a34a':'#dc2626';icon.style.color=success?'#16a34a':'#dc2626';title.textContent=success?'Successful!':'Unable to complete';msg.textContent=message;ok.style.display=success?'none':'inline-block';bar.style.background=success?'#16a34a':'#dc2626';document.getElementById('symptomAlertProgress').style.display=success?'block':'none';if(success){bar.style.transition='none';bar.style.width='100%';requestAnimationFrame(()=>{bar.style.transition='width 5s linear';bar.style.width='0%'});timer=setTimeout(()=>overlay.style.display='none',5000)}else{ok.onclick=()=>overlay.style.display='none'}}})();
</script>

<script>

// Search Functionality

document.getElementById('searchInput')?.addEventListener('input', function() {

    const filter = this.value.toUpperCase();

    const rows = document.querySelectorAll('.searchable-row');

    let visibleCount = 0;



    rows.forEach(row => {

        // Concatenate text content of Name, Code, and Description columns

        const textToSearch = (row.cells[2].textContent + ' ' + row.cells[3].textContent + ' ' + row.cells[4].textContent).toUpperCase();



        if (textToSearch.indexOf(filter) > -1) {

            row.style.display = '';

            visibleCount++;

        } else {

            row.style.display = 'none';

        }

    });



    document.getElementById('recordCount').innerText = visibleCount + ' RECORDS';

});



function filterSymptomsByDepartment(){
 const dep=document.getElementById('department_id')?.value||'';
 document.querySelectorAll('#symptomsTable tbody tr.searchable-row').forEach(r=>r.style.display=(!dep||String(r.dataset.departmentId||'')===String(dep))?'':'none');
}
// Edit & Reset Logic

function editRow(d) {

    document.getElementById('edit_id').value = d.id;
    document.getElementById('department_id').value = d.department_id || '';
    filterSymptomsByDepartment();

    document.getElementById('symptom_name').value = d.symptom_name || '';

    document.getElementById('symptom_code').value = d.symptom_code || '';

    document.getElementById('description').value = d.description || '';

    document.getElementById('status').checked = (parseInt(d.status) === 1);



    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil-square text-warning me-1"></i> EDIT SYMPTOM';

    document.getElementById('btnSubmit').innerText = 'UPDATE';

    document.getElementById('symptom_name').focus();

}



function resetForm() {
    const form = document.getElementById('symptom_name')?.closest('form');
    form?.reset();
    document.getElementById('edit_id').value = '0';
    document.getElementById('department_id').value = '';
    document.getElementById('symptom_name').value = '';
    document.getElementById('symptom_code').value = '';
    document.getElementById('description').value = '';
    document.getElementById('status').checked = true;
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-activity text-primary me-1"></i> ADD SYMPTOM';
    const save = document.getElementById('btnSubmit');
    save.innerText = 'SAVE';
    save.disabled = false;
    save.title = '';
    const warning = document.getElementById('symptomDuplicateMsg');
    if (warning) warning.style.display = 'none';
    const box = document.getElementById('twSymptomSuggestions');
    if (box) { box.classList.remove('show'); box.innerHTML = ''; }
    filterSymptomsByDepartment();
    document.getElementById('symptom_name').focus();
}


    // Live duplicate detection, excluding the row currently being edited.
    const symptomNames = <?= json_encode(array_map(static fn($x) => ['id'=>(int)$x['id'], 'name'=>mb_strtolower(trim($x['symptom_name'])), 'department_id'=>(int)($x['department_id']??0)], $symptoms), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const symptomInput = document.getElementById('symptom_name');
    const symptomSave = document.getElementById('btnSubmit');
    const symptomDupMsg = document.getElementById('symptomDuplicateMsg');
    function checkSymptomDuplicate() {
        const name = symptomInput.value.trim().replace(/\s+/g, ' ').toLocaleLowerCase();
        const editId = parseInt(document.getElementById('edit_id').value || '0', 10);
        const dep=parseInt(document.getElementById('department_id').value||'0',10);
        const found = !!name && dep>0 && symptomNames.some(x => x.name===name && Number(x.id)!==editId && Number(x.department_id)===dep);
        symptomDupMsg.style.display = found ? 'block' : 'none';
        symptomSave.disabled = found;
        symptomSave.title = found ? 'This symptom already exists' : '';
        return !found;
    }
    symptomInput.closest('form').addEventListener('reset', function() {
        symptomSave.disabled = false;
        symptomSave.title = '';
        symptomDupMsg.style.display = 'none';
    });
    symptomInput.addEventListener('input', checkSymptomDuplicate);
    document.getElementById('department_id').addEventListener('change', checkSymptomDuplicate);
    symptomInput.closest('form').addEventListener('submit', function(e) {
        if (!checkSymptomDuplicate()) { e.preventDefault(); showSymptomAlert('error', 'This symptom already exists. Please use the existing record.'); }
    });
    <?php if (!empty($_SESSION['symptom_alert'])): $sa = $_SESSION['symptom_alert']; unset($_SESSION['symptom_alert']); ?>
    showSymptomAlert(<?= json_encode($sa['type']) ?>, <?= json_encode($sa['message']) ?>);
    <?php endif; ?>
</script>



<script>
window.twSymptomRecords = <?= json_encode(array_map(static function($r) {
 return ['id'=>(int)($r['id']??0), 'symptom_name'=>(string)($r['symptom_name']??''), 'symptom_code'=>(string)($r['symptom_code']??''), 'description'=>(string)($r['description']??''), 'department_id'=>(int)($r['department_id']??0), 'department_name'=>(string)($r['department_name']??''), 'status'=>(int)($r['status']??0)];
}, $symptoms), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
</script>

<style>
.tw-existing-suggestions{display:none;position:absolute;left:0;right:0;top:100%;z-index:1500;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 10px 25px rgba(15,23,42,.14);max-height:250px;overflow-y:auto}
.tw-existing-suggestions.show{display:block}
.tw-existing-option{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;padding:9px 11px;border:0;border-bottom:1px solid #f1f5f9;background:#fff;text-align:left;font-size:13px;color:#0f172a}
.tw-existing-option:hover{background:#eff6ff}
.tw-existing-option small{display:block;color:#64748b;font-size:11px;margin-top:2px}
.tw-existing-tag{white-space:nowrap;font-size:10px;font-weight:700;color:#b45309;background:#fef3c7;border-radius:20px;padding:3px 7px}
</style>
<script>
(function(){
 const input=document.getElementById('symptom_name');
 const box=document.getElementById('twSymptomSuggestions');
 const dep=document.getElementById('department_id');
 const records=window.twSymptomRecords||[];
 if(!input||!box)return;
 const norm=v=>String(v||'').trim().toLocaleLowerCase();
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const close=()=>{box.classList.remove('show');box.innerHTML='';};
 function render(){
   const q=norm(input.value); if(!q){close();return;}
   const selected=String(dep?.value||'');
   const found=records.filter(r=>(!selected||String(r.department_id)===selected)&&norm(r.symptom_name).includes(q)).slice(0,8);
   if(!found.length){close();return;}
   box.innerHTML=found.map((r,i)=>`<button type="button" class="tw-existing-option" data-i="${i}"><span><strong>${esc(r.symptom_name)}</strong><small>${esc(r.department_name||'Department not specified')} · ${esc(r.symptom_code||'No code')}</small></span><span class="tw-existing-tag">ALREADY TAKEN</span></button>`).join('');
   box.classList.add('show');
   box.querySelectorAll('button').forEach((b,i)=>b.addEventListener('click',()=>{
     if(typeof window.editRow==='function')window.editRow(found[i]);
     close();
     document.getElementById('formTitle')?.scrollIntoView({behavior:'smooth',block:'center'});
   }));
 }
 input.addEventListener('input',render);
 input.addEventListener('focus',render);
 dep?.addEventListener('change',render);
 document.addEventListener('click',e=>{if(e.target!==input&&!box.contains(e.target))close();});
 const parent=input.parentElement;
 if(parent){if(getComputedStyle(parent).position==='static')parent.style.position='relative';parent.appendChild(box);}
})();
</script>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
