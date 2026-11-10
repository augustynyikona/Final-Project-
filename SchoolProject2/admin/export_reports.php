<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$format = $_GET['format'] ?? 'csv';

// Core Key Metrics for Report
$total_users        = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_children     = $pdo->query("SELECT COUNT(*) FROM children")->fetchColumn();
$total_appointments = $pdo->query("SELECT COUNT(*) FROM appointments")->fetchColumn();
$completed_appts    = $pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'completed'")->fetchColumn();
$missed_appts       = $pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'no-show'")->fetchColumn();
$coverage_rate      = $total_appointments > 0 ? round(($completed_appts / $total_appointments) * 100, 1) : 0;

// Requisition Stats
$total_reqs      = $pdo->query("SELECT COUNT(*) FROM requisitions")->fetchColumn();
$approved_reqs   = $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'Approved'")->fetchColumn();

// Vaccine Coverage Breakdown
$stmtVac = $pdo->query("
    SELECT 
        v.name as vaccine_name,
        v.description as target_disease,
        v.current_stock,
        COUNT(a.id) as total_doses_scheduled,
        SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as doses_administered
    FROM vaccines v
    LEFT JOIN appointments a ON v.id = a.vaccine_id
    GROUP BY v.id, v.name, v.description, v.current_stock
    ORDER BY v.name ASC
");
$vaccine_metrics = $stmtVac->fetchAll(PDO::FETCH_ASSOC);

// Handle CSV Export
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=VaxiCare_MOH_Health_Audit_Report_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');

    // Title Section
    fputcsv($output, ['REPUBLIC OF ZAMBIA - MINISTRY OF HEALTH']);
    fputcsv($output, ['VAXICARE PEDIATRIC IMMUNIZATION & SUPPLY CHAIN AUDIT REPORT']);
    fputcsv($output, ['Generated On:', date('Y-m-d H:i:s')]);
    fputcsv($output, ['Facility Name:', 'VaxiCare Pediatric Hospital (VX-ZAM-2026)']);
    fputcsv($output, ['District / Province:', 'Ndola Central Ward, Copperbelt Province']);
    fputcsv($output, []);

    // Summary Indicators Table
    fputcsv($output, ['=== KEY HEALTH COVERAGE INDICATORS ===']);
    fputcsv($output, ['Indicator Name', 'Recorded Metric', 'Unit / Baseline']);
    fputcsv($output, ['Registered Patient Guardians & Staff', $total_users, 'Accounts']);
    fputcsv($output, ['Registered Children (Under-5 Roster)', $total_children, 'Children']);
    fputcsv($output, ['Total Immunization Appointments Booked', $total_appointments, 'Doses']);
    fputcsv($output, ['Completed Immunizations Administered', $completed_appts, 'Doses']);
    fputcsv($output, ['Missed / Defaulted Appointments', $missed_appts, 'Doses']);
    fputcsv($output, ['National Routine Coverage Rate', $coverage_rate . '%', 'Target: >90%']);
    fputcsv($output, ['Pharmacy Drug Requisitions Approved', $approved_reqs . ' / ' . $total_reqs, 'Requisitions']);
    fputcsv($output, []);

    // Vaccine Breakdown Table
    fputcsv($output, ['=== VACCINE COVERAGE & COLD-CHAIN STOCK BREAKDOWN ===']);
    fputcsv($output, ['Vaccine Name', 'Target Disease', 'Current Cold-Chain Stock (Vials)', 'Total Scheduled Doses', 'Administered Doses']);

    foreach ($vaccine_metrics as $vm) {
        fputcsv($output, [
            $vm['vaccine_name'],
            $vm['target_disease'] ?? 'Pediatric Illness',
            $vm['current_stock'],
            $vm['total_doses_scheduled'],
            $vm['doses_administered']
        ]);
    }

    fclose($output);
    exit;
}

// Handle PDF / Printable View
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MOH Quarterly Audit Report — VaxCare Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --moh-green: #047857;
            --moh-dark: #0f172a;
            --moh-blue: #1d4ed8;
            --moh-slate: #64748b;
        }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: #f1f5f9; color: #0f172a; margin: 0; padding: 28px 16px; }
        .no-print { max-width: 960px; margin: 0 auto 20px; display: flex; justify-content: space-between; align-items: center; background: #ffffff; padding: 14px 24px; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04); }
        .back-link { display: inline-flex; align-items: center; gap: 8px; text-decoration: none; color: #475569; font-weight: 700; font-size: 13px; transition: color 0.15s ease; }
        .back-link:hover { color: #1d4ed8; }
        .btn-action { display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; border-radius: 9px; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; border: none; transition: transform 0.15s ease, box-shadow 0.15s ease; }
        .btn-action:hover { transform: translateY(-1px); }
        .btn-csv { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .btn-print { background: #047857; color: #ffffff; box-shadow: 0 4px 10px rgba(4, 120, 87, 0.2); }
        
        .report-card { max-width: 960px; margin: 0 auto; background: #ffffff; border-radius: 16px; border: 1px solid #cbd5e1; padding: 48px; box-sizing: border-box; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05); }
        .header-title { text-align: center; border-bottom: 2px solid #0f172a; padding-bottom: 24px; margin-bottom: 28px; }
        .header-title .crest-tag { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; color: var(--moh-green); margin-bottom: 8px; }
        .header-title h1 { font-size: 22px; font-weight: 800; color: #0f172a; margin: 0 0 8px 0; letter-spacing: -0.5px; }
        .header-title .meta { font-size: 12px; color: #64748b; font-weight: 600; }
        
        .grid-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 28px; }
        .stat-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; text-align: center; }
        .stat-box .val { font-size: 24px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; }
        .stat-box .lbl { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-top: 4px; letter-spacing: 0.5px; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 28px; font-size: 13px; }
        th { background: #0f172a; color: #ffffff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 12px 14px; text-align: left; }
        th:first-child { border-top-left-radius: 8px; }
        th:last-child { border-top-right-radius: 8px; }
        td { padding: 12px 14px; border-bottom: 1px solid #e2e8f0; }
        tbody tr:last-child td { border-bottom: 2px solid #cbd5e1; }
        tbody tr:hover td { background: #f8fafc; }
        
        @media print {
            body { background: #ffffff; padding: 0; }
            .no-print { display: none !important; }
            .report-card { border: none !important; border-radius: 0 !important; box-shadow: none !important; padding: 20px 0 !important; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <a href="reports.php" class="back-link">
            <i class="fa-solid fa-arrow-left"></i> Return to Audit Logs
        </a>
        <div style="display:flex; gap:10px;">
            <a href="export_reports.php?format=csv" class="btn-action btn-csv">
                <i class="fa-solid fa-file-csv"></i> Download CSV
            </a>
            <button onclick="window.print()" class="btn-action btn-print">
                <i class="fa-solid fa-print"></i> Print Official Report
            </button>
        </div>
    </div>

    <div class="report-card">
        <div class="header-title">
            <div class="crest-tag">
                <i class="fa-solid fa-shield-virus"></i> Republic of Zambia &bull; Ministry of Health
            </div>
            <h1>QUARTERLY PEDIATRIC IMMUNIZATION &amp; SUPPLY CHAIN AUDIT REPORT</h1>
            <div class="meta">Facility: <strong>VaxCare Pediatric Center</strong> &bull; District: <strong>Ndola Central Ward, Copperbelt Province</strong> &bull; Facility Code: <strong>VX-ZAM-2026</strong></div>
        </div>

        <div class="grid-stats">
            <div class="stat-box">
                <div class="val"><?= number_format($total_children) ?></div>
                <div class="lbl">Under-5 Cohort</div>
            </div>
            <div class="stat-box">
                <div class="val" style="color:var(--moh-blue);"><?= number_format($completed_appts) ?></div>
                <div class="lbl">Doses Administered</div>
            </div>
            <div class="stat-box">
                <div class="val" style="color:var(--moh-green);"><?= $coverage_rate ?>%</div>
                <div class="lbl">Routine Coverage Rate</div>
            </div>
            <div class="stat-box">
                <div class="val"><?= $approved_reqs ?> / <?= $total_reqs ?></div>
                <div class="lbl">Approved Requisitions</div>
            </div>
        </div>

        <h3 style="font-size:14px; font-weight:800; color:#0f172a; margin: 0 0 14px 0; display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-boxes-stacked" style="color:var(--moh-blue);"></i> Vaccine Stock &amp; Administration Coverage Breakdown
        </h3>
        <table>
            <thead>
                <tr>
                    <th>Vaccine Formulation</th>
                    <th>Target Disease</th>
                    <th>Cold-Chain Stock</th>
                    <th>Scheduled Doses</th>
                    <th>Administered Doses</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vaccine_metrics as $vm): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($vm['vaccine_name']) ?></strong></td>
                        <td style="color:#475569;"><?= htmlspecialchars($vm['target_disease'] ?? 'Pediatric Illness') ?></td>
                        <td><strong><?= number_format($vm['current_stock']) ?> vials</strong></td>
                        <td><?= number_format($vm['total_doses_scheduled']) ?></td>
                        <td style="color:var(--moh-green); font-weight:700;"><?= number_format($vm['doses_administered']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:48px; display:flex; justify-content:space-between; align-items:flex-end;">
            <div style="font-size:11px; color:#64748b; line-height:1.6;">
                Report Generated By: <strong><?= htmlspecialchars($admin_name) ?></strong> (Super Administrator)<br>
                Generation Timestamp: <strong><?= date('F d, Y — H:i:s') ?></strong><br>
                Verification Hash: <span style="font-family:monospace;"><?= strtoupper(substr(hash('sha256', date('Y-m-d') . $total_children . $completed_appts), 0, 16)) ?></span>
            </div>
            <div style="text-align:center; width:220px;">
                <div style="border-bottom:1.5px solid #0f172a; margin-bottom:6px; height:32px;"></div>
                <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">District Health Director Signoff</div>
            </div>
        </div>
    </div>

</body>
</html>
