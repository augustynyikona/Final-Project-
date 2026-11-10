<?php
include __DIR__ . '/db.php';

echo "Starting comprehensive database seeding...\n\n";

// Disable foreign key checks for clean seeding
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

// Option: truncate tables to provide clean test dataset
$tables_to_truncate = [
    'sms_logs', 'requisition_items', 'requisitions', 'purchase_orders', 
    'invoices', 'consultations', 'triage_referrals', 'growth_logs', 
    'clinical_vitals', 'appointments', 'vaccine_batches', 'vaccines', 
    'suppliers', 'messages', 'children', 'users'
];

foreach ($tables_to_truncate as $tbl) {
    $pdo->exec("TRUNCATE TABLE `$tbl`");
}

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
echo "Tables truncated for fresh seed.\n";

$password_hash = password_hash('password', PASSWORD_DEFAULT);

// 1. SEED USERS
$users_data = [
    // Admin
    ['name' => 'Admin Director Mukuka', 'phone' => '0970000001', 'role' => 'admin'],
    
    // Doctors
    ['name' => 'Dr. Sarah Chen', 'phone' => '0971725901', 'role' => 'doctor'],
    ['name' => 'Dr. James Kabwe', 'phone' => '0971725902', 'role' => 'doctor'],
    ['name' => 'Dr. Chipo Banda', 'phone' => '0971725903', 'role' => 'doctor'],

    // Nurses
    ['name' => 'Nurse Grace Mulenga', 'phone' => '0978000001', 'role' => 'nurse'],
    ['name' => 'Nurse Mary Tembo', 'phone' => '0978000002', 'role' => 'nurse'],
    ['name' => 'Nurse Alice Phiri', 'phone' => '0978000003', 'role' => 'nurse'],

    // Pharmacists
    ['name' => 'Augustine Nguzayikona', 'phone' => '+260111222444', 'role' => 'pharmacy'],
    ['name' => 'Pharmacist Kelvin Zimba', 'phone' => '0979000002', 'role' => 'pharmacy'],
];

// Parents / Guardians
$parent_names = [
    'Robert Davis', 'Patricia Mulenga', 'Brian Chilufya', 'Catherine Zimba', 
    'Emmanuel Mwape', 'Dorothy Sakala', 'Godfrey Lungu', 'Helen Kaunda', 
    'Isaac Bwalya', 'Joyce Chanda', 'Kennedy Musonda', 'Lillian Zulu', 
    'Michael Kasongo', 'Nelly Kapepula', 'Oliver Simwinga', 'Prudence Mwansa', 
    'Richard Kapwepwe', 'Stella Chileshe', 'Timothy Njobvu', 'Violet Hamusonde', 
    'Walter Mainza', 'Yvonne Soko', 'Zachariah Miti', 'Agnes Chola', 
    'Bernard Kakoma', 'Christine Mwiinga', 'David Chipimo', 'Esther Nyirenda', 
    'Francis Kalunga', 'Florence Mpundu', 'George Silavwe', 'Hannah Mvula', 
    'Josephine Kampamba', 'Lazarus Situmbeko', 'Margaret Nkandu', 'Nicholas Haimbe', 
    'Phoebe Lyambai', 'Reuben Moonga', 'Susan Simasiku', 'Thomas Mutale'
];

$phone_idx = 101;
foreach ($parent_names as $pname) {
    $users_data[] = [
        'name' => $pname,
        'phone' => '0971000' . str_pad($phone_idx++, 3, '0', STR_PAD_LEFT),
        'role' => 'guardian'
    ];
}

$stmt_user = $pdo->prepare("INSERT INTO users (name, phone, password_hash, role, created_at) VALUES (?, ?, ?, ?, NOW())");
$user_ids_by_role = ['admin' => [], 'doctor' => [], 'nurse' => [], 'pharmacy' => [], 'guardian' => []];

foreach ($users_data as $u) {
    $stmt_user->execute([$u['name'], $u['phone'], $password_hash, $u['role']]);
    $uid = $pdo->lastInsertId();
    $user_ids_by_role[$u['role']][] = ['id' => $uid, 'name' => $u['name'], 'phone' => $u['phone']];
}

echo "Created " . count($users_data) . " user accounts across 5 roles.\n";

// 2. SEED SUPPLIERS
$suppliers = ['BioMed Global Ltd', 'Zambia Medicines Agency', 'PharmaCare Logistics', 'MediSupply Africa'];
$supplier_ids = [];
$stmt_sup = $pdo->prepare("INSERT INTO suppliers (name, status) VALUES (?, 'Active')");
foreach ($suppliers as $sup) {
    $stmt_sup->execute([$sup]);
    $supplier_ids[] = $pdo->lastInsertId();
}

// 3. SEED VACCINES (Low stock, Moderate stock, Full stock)
$vaccines_data = [
    // Critical Low Stock
    ['name' => 'MMR Vaccine', 'desc' => 'Measles, Mumps, Rubella', 'stock' => 22, 'threshold' => 100, 'mfg' => 'GlaxoSmithKline'],
    ['name' => 'HPV Vaccine', 'desc' => 'Gardasil 9 Human Papillomavirus', 'stock' => 15, 'threshold' => 120, 'mfg' => 'Merck & Co'],
    ['name' => 'Flu Vaccine', 'desc' => 'Quadrivalent Influenza Vaccine', 'stock' => 10, 'threshold' => 150, 'mfg' => 'Sanofi Pasteur'],
    ['name' => 'RotaVirus Vaccine', 'desc' => 'Rotarix Oral Suspension', 'stock' => 18, 'threshold' => 100, 'mfg' => 'GSK Biologicals'],
    
    // Out of Stock
    ['name' => 'Yellow Fever Vaccine', 'desc' => 'Live attenuated yellow fever', 'stock' => 0, 'threshold' => 50, 'mfg' => 'Sanofi Pasteur'],
    ['name' => 'Typhoid Vaccine', 'desc' => 'Typhoid Vi polysaccharide', 'stock' => 0, 'threshold' => 50, 'mfg' => 'Bharat Biotech'],

    
    // Optimal / Full Stock
    ['name' => 'DPT Vaccine', 'desc' => 'Diphtheria, Pertussis, Tetanus', 'stock' => 499, 'threshold' => 100, 'mfg' => 'Serum Institute'],
    ['name' => 'Measles Vaccine', 'desc' => 'Live Attenuated Measles Doses', 'stock' => 500, 'threshold' => 100, 'mfg' => 'WHO Prequalified'],
    ['name' => 'BCG Vaccine', 'desc' => 'Tuberculosis Protection', 'stock' => 710, 'threshold' => 150, 'mfg' => 'Statens Serum Institut'],
    ['name' => 'OPV (Oral Polio)', 'desc' => 'Bivalent Oral Poliovirus', 'stock' => 989, 'threshold' => 200, 'mfg' => 'Bio-Med Pvt Ltd'],
    ['name' => 'Hepatitis B Vaccine', 'desc' => 'Recombinant HepB Vaccine', 'stock' => 380, 'threshold' => 80, 'mfg' => 'Sanofi'],
    ['name' => 'Vitamin A Supplement', 'desc' => 'High-potency Vitamin A Capsules', 'stock' => 650, 'threshold' => 100, 'mfg' => 'UNICEF Global'],
];

$stmt_vac = $pdo->prepare("INSERT INTO vaccines (name, description, current_stock, low_stock_threshold, manufacturer) VALUES (?, ?, ?, ?, ?)");
$vaccine_ids = [];
foreach ($vaccines_data as $v) {
    $stmt_vac->execute([$v['name'], $v['desc'], $v['stock'], $v['threshold'], $v['mfg']]);
    $vid = $pdo->lastInsertId();
    $vaccine_ids[] = ['id' => $vid, 'name' => $v['name'], 'stock' => $v['stock'], 'threshold' => $v['threshold']];

    // Seed Batch for vaccine
    $stmt_batch = $pdo->prepare("INSERT INTO vaccine_batches (vaccine_id, batch_number, quantity, expiry_date, location, supplier_id, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
    $batch_num = 'BATCH-' . rand(10000, 99999);
    $exp_date = date('Y-m-d', strtotime('+' . rand(6, 24) . ' months'));
    $stmt_batch->execute([$vid, $batch_num, $v['stock'], $exp_date, 'Main Pharmacy Cold Storage', $supplier_ids[array_rand($supplier_ids)]]);
}

echo "Created " . count($vaccines_data) . " vaccines and inventory batches.\n";

// 4. SEED CHILDREN (PATIENTS)
$child_names = [
    'Junior Davis', 'Chloe Davis', 'Nathan Chilufya', 'Grace Chilufya', 'Kofi Zimba', 
    'Blessing Mwape', 'Faith Mwape', 'Hope Sakala', 'Joy Lungu', 'Caleb Lungu', 
    'Daniel Bwalya', 'Hannah Chanda', 'Elijah Musonda', 'Deborah Zulu', 'Joshua Kasongo', 
    'Naomi Kapepula', 'Micah Simwinga', 'Rachel Mwansa', 'Samuel Kapwepwe', 'Sarah Chileshe', 
    'Benjamin Njobvu', 'Miriam Hamusonde', 'Gideon Mainza', 'Esther Soko', 'Asher Miti', 
    'Lydia Chola', 'Simeon Kakoma', 'Ruth Mwiinga', 'Titus Chipimo', 'Abigail Nyirenda', 
    'Ezekiel Kalunga', 'Tabitha Mpundu', 'Zion Silavwe', 'Priscila Mvula', 'Bartholomew Kampamba', 
    'Joel Situmbeko', 'Rebecca Nkandu', 'Solomon Haimbe', 'Lois Lyambai', 'Silas Moonga', 
    'Zion Simasiku', 'Eve Mutale', 'Adam Mutale', 'Noah Davis', 'Liam Chilufya', 
    'Mia Zimba', 'Sophia Mwape', 'Jackson Lungu', 'Aiden Bwalya', 'Lucas Musonda'
];

$stmt_child = $pdo->prepare("INSERT INTO children (guardian_id, name, date_of_birth, gender, created_at) VALUES (?, ?, ?, ?, NOW())");
$children = [];
$guardians = $user_ids_by_role['guardian'];

foreach ($child_names as $cname) {
    $guardian = $guardians[array_rand($guardians)];
    $gender = rand(0, 1) ? 'Male' : 'Female';
    // DOB between 2 weeks ago and 5 years ago
    $days_ago = rand(14, 1800);
    $dob = date('Y-m-d', strtotime("-$days_ago days"));
    
    $stmt_child->execute([$guardian['id'], $cname, $dob, $gender]);
    $cid = $pdo->lastInsertId();
    $children[] = ['id' => $cid, 'name' => $cname, 'dob' => $dob, 'guardian_id' => $guardian['id']];
}

echo "Created " . count($children) . " child patient records.\n";

// 5. SEED APPOINTMENTS (including 50+ MISSED appointments, confirmed, completed, arrived)
$stmt_app = $pdo->prepare("
    INSERT INTO appointments 
    (child_id, vaccine_id, appointment_date, appointment_time, status, batch_number, site, route, manufacturer, notes, room_number, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$statuses = ['completed', 'no-show', 'cancelled', 'scheduled', 'confirmed', 'arrived'];
$sites = ['Left Thigh', 'Right Thigh', 'Left Upper Arm', 'Right Upper Arm'];
$routes = ['Intramuscular (IM)', 'Subcutaneous (SC)', 'Oral (PO)'];
$rooms = ['Room 1 (Immunization)', 'Room 2 (Triage)', 'Room 3 (Pediatric)', 'Room 4 (Observation)'];

$appointment_count = 0;

// A. Missed Appointments (Past 60 days)
for ($i = 0; $i < 65; $i++) {
    $child = $children[array_rand($children)];
    $vaccine = $vaccine_ids[array_rand($vaccine_ids)];
    $days_past = rand(1, 60);
    $app_date = date('Y-m-d', strtotime("-$days_past days"));
    $app_time = sprintf('%02d:%02d:00', rand(8, 16), rand(0, 59));
    $status = (rand(0, 1) == 0) ? 'no-show' : 'cancelled';
    $notes = ($status === 'no-show') ? 'Parent failed to bring child for appointment. SMS reminder sent.' : 'Cancelled by guardian due to child illness.';

    $stmt_app->execute([
        $child['id'], $vaccine['id'], $app_date, $app_time, $status,
        'BATCH-' . rand(10000, 99999), $sites[array_rand($sites)], $routes[array_rand($routes)],
        'General Pharma', $notes, $rooms[array_rand($rooms)], date('Y-m-d H:i:s', strtotime("-$days_past days -2 days"))
    ]);
    $appointment_count++;
}

// B. Completed Appointments (Past 90 days)
for ($i = 0; $i < 70; $i++) {
    $child = $children[array_rand($children)];
    $vaccine = $vaccine_ids[array_rand($vaccine_ids)];
    $days_past = rand(1, 90);
    $app_date = date('Y-m-d', strtotime("-$days_past days"));
    $app_time = sprintf('%02d:%02d:00', rand(8, 16), rand(0, 59));

    $stmt_app->execute([
        $child['id'], $vaccine['id'], $app_date, $app_time, 'completed',
        'BATCH-' . rand(10000, 99999), $sites[array_rand($sites)], $routes[array_rand($routes)],
        'Sanofi Pasteur', 'Successfully administered. No adverse reaction observed during 15-min wait.',
        $rooms[array_rand($rooms)], date('Y-m-d H:i:s', strtotime("-$days_past days"))
    ]);
    $appointment_count++;
}

// C. Today's Appointments (Scheduled, Arrived, Confirmed, Completed)
$today_date = date('Y-m-d');
for ($i = 0; $i < 25; $i++) {
    $child = $children[array_rand($children)];
    $vaccine = $vaccine_ids[array_rand($vaccine_ids)];
    $app_time = sprintf('%02d:%02d:00', rand(8, 16), rand(0, 59));
    $st = ['scheduled', 'confirmed', 'arrived', 'completed'][rand(0, 3)];

    $stmt_app->execute([
        $child['id'], $vaccine['id'], $today_date, $app_time, $st,
        'BATCH-' . rand(10000, 99999), $sites[array_rand($sites)], $routes[array_rand($routes)],
        'GSK', 'Today immunization appointment.', $rooms[array_rand($rooms)], date('Y-m-d H:i:s')
    ]);
    $appointment_count++;
}

// D. Upcoming Appointments (Next 30 days)
for ($i = 0; $i < 45; $i++) {
    $child = $children[array_rand($children)];
    $vaccine = $vaccine_ids[array_rand($vaccine_ids)];
    $days_ahead = rand(1, 30);
    $app_date = date('Y-m-d', strtotime("+$days_ahead days"));
    $app_time = sprintf('%02d:%02d:00', rand(8, 16), rand(0, 59));

    $app_status = (rand(0, 3) == 0) ? 'rescheduled' : 'scheduled';
    $stmt_app->execute([
        $child['id'], $vaccine['id'], $app_date, $app_time, $app_status,
        'BATCH-' . rand(10000, 99999), $sites[array_rand($sites)], $routes[array_rand($routes)],
        'Serum Institute', 'Scheduled routine follow-up immunization.', $rooms[array_rand($rooms)], date('Y-m-d H:i:s')
    ]);
    $appointment_count++;
}

echo "Created $appointment_count total appointments (including 65+ missed/no-show appointments).\n";

// 6. SEED CLINICAL VITALS (with CRITICAL CONDITIONS)
$stmt_vital = $pdo->prepare("
    INSERT INTO clinical_vitals 
    (child_id, recorded_by_id, temperature, heart_rate, weight_kg, symptoms, is_critical, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

$nurses = $user_ids_by_role['nurse'];
$doctors = $user_ids_by_role['doctor'];

$vitals_count = 0;
// Critical Condition Children (High fever >39.5, tachycardia, severe symptoms)
$critical_symptoms = [
    'Severe fever 39.8°C, rapid shallow breathing, lethargy and poor feeding.',
    'High grade fever 40.1°C, acute post-vaccine allergic swelling on upper arm.',
    'Hyperpyrexia 40.3°C, convulsions reported by guardian, respiratory distress.',
    'Persistent vomiting, high temperature 39.6°C, severe dehydration signs.',
    'High fever 39.9°C, tachypnea, stridor and severe cough.'
];

foreach ($children as $idx => $child) {
    $nurse = $nurses[array_rand($nurses)];
    
    // Make 8 children CRITICAL
    if ($idx < 8) {
        $temp = sprintf('%.1f', rand(395, 404) / 10);
        $hr = rand(145, 175);
        $weight = sprintf('%.2f', rand(40, 150) / 10);
        $sym = $critical_symptoms[$idx % count($critical_symptoms)];
        
        $stmt_vital->execute([$child['id'], $nurse['id'], $temp, $hr, $weight, $sym, 1, date('Y-m-d H:i:s', strtotime('-' . rand(0, 5) . ' hours'))]);
        $vitals_count++;

        // Create Triage Referral to Doctor for Critical Children
        $stmt_ref = $pdo->prepare("INSERT INTO triage_referrals (child_id, nurse_id, reason, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
        $stmt_ref->execute([$child['id'], $nurse['id'], "CRITICAL VITALS: Temp {$temp}°C, HR {$hr} bpm. $sym"]);

        // Create Doctor Consultation Note
        $doc = $doctors[array_rand($doctors)];
        $stmt_con = $pdo->prepare("INSERT INTO consultations (child_id, doctor_id, notes, next_review_date, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt_con->execute([
            $child['id'], $doc['id'], 
            "URGENT CONSULTATION: Patient evaluated with high fever {$temp}°C. Administered IV antipyretics and ordered close monitoring in pediatric room.",
            date('Y-m-d', strtotime('+2 days'))
        ]);

    } else if ($idx < 35) {
        // Normal Condition Children
        $temp = sprintf('%.1f', rand(365, 373) / 10);
        $hr = rand(100, 130);
        $weight = sprintf('%.2f', rand(35, 180) / 10);
        $sym = 'Child healthy, active, normal growth milestones.';

        $stmt_vital->execute([$child['id'], $nurse['id'], $temp, $hr, $weight, $sym, 0, date('Y-m-d H:i:s', strtotime('-' . rand(1, 30) . ' days'))]);
        $vitals_count++;
    }
}

echo "Created $vitals_count clinical vitals records (including 8 CRITICAL CONDITION pediatric cases with doctor referrals).\n";

// 7. SEED REQUISITIONS (PHARMACY & NURSE)
$stmt_req = $pdo->prepare("INSERT INTO requisitions (nurse_id, shift_date, status, approved_at, dispatch_type, dispatch_time, dispatch_notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmt_req_item = $pdo->prepare("INSERT INTO requisition_items (requisition_id, vaccine_id, requested_quantity, notes) VALUES (?, ?, ?, ?)");

for ($r = 0; $r < 12; $r++) {
    $nurse = $nurses[array_rand($nurses)];
    $s_date = date('Y-m-d', strtotime("-$r days"));
    
    if ($r === 0) {
        // Current Pending Requisition
        $stmt_req->execute([$nurse['id'], $s_date, 'Pending', null, 'Immediate', null, null, date('Y-m-d H:i:s')]);
        $req_id = $pdo->lastInsertId();
        $stmt_req_item->execute([$req_id, $vaccine_ids[0]['id'], 35, 'Urgent stock needed for morning shift']);
        $stmt_req_item->execute([$req_id, $vaccine_ids[4]['id'], 50, 'Standard daily supply']);
    } else {
        // Approved Requisitions
        $dis_type = ($r % 2 === 0) ? 'Immediate' : 'Scheduled';
        $dis_time = date('Y-m-d H:i:s', strtotime("-$r days +2 hours"));
        $dis_note = ($dis_type === 'Immediate') ? 'Dispatched immediately via pharmacy porter' : 'Scheduled for morning shift delivery at 09:00 AM';

        $stmt_req->execute([$nurse['id'], $s_date, 'Approved', date('Y-m-d H:i:s', strtotime("-$r days +1 hour")), $dis_type, $dis_time, $dis_note, date('Y-m-d H:i:s', strtotime("-$r days"))]);
        $req_id = $pdo->lastInsertId();
        $stmt_req_item->execute([$req_id, $vaccine_ids[array_rand($vaccine_ids)]['id'], rand(20, 80), 'Daily shift allotment']);
        $stmt_req_item->execute([$req_id, $vaccine_ids[array_rand($vaccine_ids)]['id'], rand(15, 60), 'Vaccination routine supply']);
    }
}

echo "Created 12 drug requisitions with dispatch schedules.\n";

// 8. SEED PURCHASE ORDERS (PHARMACY)
$stmt_po = $pdo->prepare("INSERT INTO purchase_orders (vaccine_id, supplier_id, quantity, unit_type, order_date, requested_delivery, status, notes, created_at) VALUES (?, ?, ?, 'Vials', ?, ?, ?, ?, ?)");

$po_statuses = ['Pending', 'Received', 'In-Transit', 'Approved'];
for ($p = 0; $p < 10; $p++) {
    $v = $vaccine_ids[array_rand($vaccine_ids)];
    $s = $supplier_ids[array_rand($supplier_ids)];
    $ord_date = date('Y-m-d', strtotime("-$p days"));
    $del_date = date('Y-m-d', strtotime("+$p days + 3 days"));
    $st = $po_statuses[$p % count($po_statuses)];

    $stmt_po->execute([$v['id'], $s, rand(200, 1000), $ord_date, $del_date, $st, "Restock order for {$v['name']}", date('Y-m-d H:i:s', strtotime("-$p days"))]);
}

echo "Created 10 pharmacy purchase orders.\n";

// 9. SEED INVOICES (BILLING)
$stmt_inv = $pdo->prepare("INSERT INTO invoices (guardian_id, child_id, amount_due, status, admission_date, created_at) VALUES (?, ?, ?, ?, ?, ?)");
$inv_statuses = ['Pending', 'Paid', 'Overdue'];

for ($inv = 0; $inv < 28; $inv++) {
    $g = $guardians[array_rand($guardians)];
    $ch = $children[array_rand($children)];
    $amt = sprintf('%.2f', rand(15000, 450000) / 100);
    $st = $inv_statuses[$inv % count($inv_statuses)];
    $adm_date = date('Y-m-d', strtotime('-' . rand(1, 45) . ' days'));

    $stmt_inv->execute([$g['id'], $ch['id'], $amt, $st, $adm_date, date('Y-m-d H:i:s', strtotime('-' . rand(1, 45) . ' days'))]);
}

echo "Created 28 billing invoices (Pending, Paid, Overdue).\n";

// 10. SEED MESSAGES
$stmt_msg = $pdo->prepare("INSERT INTO messages (sender_id, recipient_role, receiver_id, subject, body, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");

$head_pharmacist = $user_ids_by_role['pharmacy'][0]['id'];
$lead_doctor = $user_ids_by_role['doctor'][0]['id'];
$head_nurse = $user_ids_by_role['nurse'][0]['id'];

$messages_sample = [
    [$head_pharmacist, 'nurse', $head_nurse, 'Vaccine Restock Arrival Notice', 'Hi Nurse Grace, the MMR and RotaVirus stock has been replenished and cold-box dispatched.', 0],
    [$lead_doctor, 'pharmacy', $head_pharmacist, 'Urgent Medication Inquiry', 'Hello Augustine, please confirm availability of Quadrivalent Flu vaccines for pediatric ward.', 1],
    [$head_nurse, 'doctor', $lead_doctor, 'Critical Patient Referral Notification', 'Dr. Sarah, patient Junior Davis has been referred with a fever of 39.8°C post-immunization.', 0],
    [$head_pharmacist, 'admin', null, 'Monthly Vaccine Inventory Audit Report', 'Director Mukuka, attached is the end of month inventory audit log for all 10 vaccine lines.', 0]
];

foreach ($messages_sample as $msg) {
    $stmt_msg->execute([$msg[0], $msg[1], $msg[2], $msg[3], $msg[4], $msg[5], date('Y-m-d H:i:s', strtotime('-' . rand(1, 5) . ' days'))]);
}

echo "Created internal inter-departmental messages.\n\n";

echo "========================================================================\n";
echo "SUCCESS! OVER 200 REALISTIC DUMMY DATA RECORDS SEEDED ACROSS ALL DEPARTMENTS.\n";
echo "========================================================================\n";
