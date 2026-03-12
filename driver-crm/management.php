<?php
/**
 * ממשק ניהול נהגים — CRM
 *
 * API:
 * ?api=list          — רשימת נהגים
 * ?api=save          — שמירת נהג (POST: name, phone, virtual, index)
 * ?api=delete        — מחיקת נהג (POST: index)
 * ?api=dial          — חיוג (POST: driverPhone, passengerPhone, driverName, virtualNumber)
 * ?api=log           — יומן שיחות (GET: driverPhone=optional)
 * ?api=log_delete    — מחיקת שיחות (POST: ids=[...])
 * ?api=vnums         — רשימת מספרים וירטואליים
 * ?api=vnum_add      — הוספת מספרים (POST: numbers=text with numbers)
 * ?api=vnum_delete   — מחיקת מספר (POST: number)
 * ?api=vnum_unassign — הסרת הקצאה (POST: number)
 */

$api = $_GET['api'] ?? '';
$driversFile  = __DIR__ . '/drivers.json';
$callLogFile  = __DIR__ . '/call_log.json';
$vnumsFile    = __DIR__ . '/virtual_numbers.json';

// Helper: load JSON file
function loadJson($file) {
    if (file_exists($file)) return json_decode(file_get_contents($file), true) ?: [];
    return [];
}
function saveJson($file, $data) {
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ========== API: רשימת נהגים ==========
if ($api === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(loadJson($driversFile), JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== API: שמירת נהג ==========
if ($api === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $name    = $_POST['name'] ?? '';
    $phone   = $_POST['phone'] ?? '';
    $virtual = $_POST['virtual'] ?? '';
    $index   = $_POST['index'] ?? '-1';

    if (empty($name) || empty($phone)) {
        echo json_encode(["status" => "error", "message" => "חסר שם או טלפון"]);
        exit;
    }

    $drivers = loadJson($driversFile);
    $vnums = loadJson($vnumsFile);

    // Free old virtual number if driver had one
    if ($index >= 0 && $index < count($drivers)) {
        $oldVirtual = $drivers[(int)$index]['virtual'] ?? '';
        if (!empty($oldVirtual) && $oldVirtual !== $virtual) {
            foreach ($vnums as &$v) {
                if ($v['number'] === $oldVirtual) { $v['assignedTo'] = ''; $v['assignedName'] = ''; break; }
            }
        }
    }

    // Assign new virtual number
    if (!empty($virtual)) {
        foreach ($vnums as &$v) {
            if ($v['number'] === $virtual) { $v['assignedTo'] = $phone; $v['assignedName'] = $name; break; }
        }
    }
    unset($v);
    saveJson($vnumsFile, $vnums);

    $driver = ["name" => $name, "phone" => $phone, "virtual" => $virtual];
    if ($index >= 0 && $index < count($drivers)) {
        $drivers[(int)$index] = $driver;
    } else {
        $drivers[] = $driver;
    }
    saveJson($driversFile, $drivers);
    echo json_encode(["status" => "ok"]);
    exit;
}

// ========== API: מחיקת נהג ==========
if ($api === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $index = (int)($_POST['index'] ?? -1);
    $drivers = loadJson($driversFile);

    if ($index >= 0 && $index < count($drivers)) {
        // Free virtual number
        $oldVirtual = $drivers[$index]['virtual'] ?? '';
        if (!empty($oldVirtual)) {
            $vnums = loadJson($vnumsFile);
            foreach ($vnums as &$v) {
                if ($v['number'] === $oldVirtual) { $v['assignedTo'] = ''; $v['assignedName'] = ''; break; }
            }
            unset($v);
            saveJson($vnumsFile, $vnums);
        }
        array_splice($drivers, $index, 1);
        saveJson($driversFile, $drivers);
        echo json_encode(["status" => "ok"]);
    } else {
        echo json_encode(["status" => "error", "message" => "אינדקס לא תקין"]);
    }
    exit;
}

// ========== API: יומן שיחות ==========
if ($api === 'log') {
    header('Content-Type: application/json; charset=utf-8');
    $log = loadJson($callLogFile);
    $filterDriver = $_GET['driverPhone'] ?? '';
    if (!empty($filterDriver)) {
        $log = array_values(array_filter($log, function($e) use ($filterDriver) {
            return ($e['driverPhone'] ?? '') === $filterDriver;
        }));
    }
    echo json_encode($log, JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== API: מחיקת שיחות ==========
if ($api === 'log_delete') {
    header('Content-Type: application/json; charset=utf-8');
    $ids = json_decode($_POST['ids'] ?? '[]', true) ?: [];
    $log = loadJson($callLogFile);
    $log = array_values(array_filter($log, function($e) use ($ids) {
        return !in_array($e['id'] ?? '', $ids);
    }));
    saveJson($callLogFile, $log);
    echo json_encode(["status" => "ok"]);
    exit;
}

// ========== API: מספרים וירטואליים — רשימה ==========
if ($api === 'vnums') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(loadJson($vnumsFile), JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== API: מספרים וירטואליים — הוספה ==========
if ($api === 'vnum_add') {
    header('Content-Type: application/json; charset=utf-8');
    $text = $_POST['numbers'] ?? '';
    // Extract phone numbers from text (digits, dashes, spaces)
    preg_match_all('/[\d\-+]{7,15}/', $text, $matches);
    $newNums = array_map(function($n) { return preg_replace('/[^0-9+]/', '', $n); }, $matches[0]);
    $newNums = array_unique(array_filter($newNums));

    $vnums = loadJson($vnumsFile);
    $existing = array_column($vnums, 'number');
    $added = 0;

    foreach ($newNums as $num) {
        if (!in_array($num, $existing)) {
            $vnums[] = ["number" => $num, "assignedTo" => "", "assignedName" => ""];
            $added++;
        }
    }

    saveJson($vnumsFile, $vnums);
    echo json_encode(["status" => "ok", "added" => $added, "total" => count($vnums)]);
    exit;
}

// ========== API: מספרים וירטואליים — מחיקה ==========
if ($api === 'vnum_delete') {
    header('Content-Type: application/json; charset=utf-8');
    $number = $_POST['number'] ?? '';
    $vnums = loadJson($vnumsFile);

    // Also remove from driver if assigned
    $drivers = loadJson($driversFile);
    $changed = false;
    foreach ($drivers as &$d) {
        if (($d['virtual'] ?? '') === $number) { $d['virtual'] = ''; $changed = true; }
    }
    unset($d);
    if ($changed) saveJson($driversFile, $drivers);

    $vnums = array_values(array_filter($vnums, function($v) use ($number) { return $v['number'] !== $number; }));
    saveJson($vnumsFile, $vnums);
    echo json_encode(["status" => "ok"]);
    exit;
}

// ========== API: מספרים וירטואליים — הסרת הקצאה ==========
if ($api === 'vnum_unassign') {
    header('Content-Type: application/json; charset=utf-8');
    $number = $_POST['number'] ?? '';
    $vnums = loadJson($vnumsFile);

    foreach ($vnums as &$v) {
        if ($v['number'] === $number) { $v['assignedTo'] = ''; $v['assignedName'] = ''; break; }
    }
    unset($v);
    saveJson($vnumsFile, $vnums);

    // Also remove from driver
    $drivers = loadJson($driversFile);
    foreach ($drivers as &$d) {
        if (($d['virtual'] ?? '') === $number) { $d['virtual'] = ''; }
    }
    unset($d);
    saveJson($driversFile, $drivers);

    echo json_encode(["status" => "ok"]);
    exit;
}

// ========== API: חיוג ==========
if ($api === 'dial') {
    header('Content-Type: application/json; charset=utf-8');
    $driverPhone    = $_POST['driverPhone'] ?? '';
    $passengerPhone = $_POST['passengerPhone'] ?? '';
    $driverName     = $_POST['driverName'] ?? '';
    $virtualNumber  = $_POST['virtualNumber'] ?? '';

    if (empty($driverPhone) || empty($passengerPhone)) {
        echo json_encode(["status" => "error", "message" => "חסר מספר טלפון"]);
        exit;
    }

    $mappingFile = __DIR__ . '/call_mapping.json';
    $mappings = loadJson($mappingFile);
    $mappings[$driverPhone] = [
        "passengerPhone" => $passengerPhone,
        "driverName"     => $driverName,
        "virtualNumber"  => $virtualNumber,
        "timestamp"      => date('Y-m-d H:i:s')
    ];
    saveJson($mappingFile, $mappings);

    $data = [
        "action"              => "campaignRun",
        "apiKey"              => "7c2cf8346c7633",
        "messagesType"        => "extensionActivation",
        "extensionActivation" => "8580",
        "phones"              => $driverPhone,
        "callId"              => $virtualNumber ?: $driverPhone,
        "callLength"          => 30,
        "dialRetries"         => 1,
        "betweenRetries"      => 20,
        "reasonableHours"     => "no"
    ];

    $ch = curl_init("https://app.ipsales.co.il/campaignApi.php");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    $log = loadJson($callLogFile);
    $log[] = [
        "id" => uniqid('call_'), "time" => date('Y-m-d H:i:s'),
        "driverName" => $driverName, "driverPhone" => $driverPhone,
        "passengerPhone" => $passengerPhone, "virtualNumber" => $virtualNumber,
        "type" => "outgoing", "duration" => "", "recording" => "",
        "status" => $error ? "error" : "sent"
    ];
    if (count($log) > 1000) $log = array_slice($log, -1000);
    saveJson($callLogFile, $log);

    if ($error) {
        echo json_encode(["status" => "error", "message" => $error]);
    } else {
        echo json_encode([
            "status" => "sent", "driver" => $driverPhone, "passenger" => $passengerPhone,
            "response" => json_decode($response, true) ?? $response
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ========== HTML ==========
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ניהול נהגים</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f0f2f5; color: #1a1a2e; min-height: 100vh; }
        .header { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: #fff; padding: 16px 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .header-top { display: flex; align-items: center; justify-content: space-between; }
        .header h1 { font-size: 22px; font-weight: 700; }
        .header .subtitle { font-size: 13px; opacity: 0.7; margin-top: 2px; }
        .nav { display: flex; gap: 0; margin-top: 14px; border-bottom: 2px solid rgba(255,255,255,0.1); }
        .nav-item { padding: 10px 24px; color: rgba(255,255,255,0.6); cursor: pointer; font-size: 15px; font-weight: 600; border-bottom: 3px solid transparent; transition: all 0.2s; user-select: none; }
        .nav-item:hover { color: rgba(255,255,255,0.8); }
        .nav-item.active { color: #fff; border-bottom-color: #4CAF50; }
        .btn-add { background: #4CAF50; color: #fff; border: none; padding: 10px 22px; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; }
        .btn-add:hover { background: #43A047; }
        .container { max-width: 960px; margin: 20px auto; padding: 0 15px; }
        .page { display: none; }
        .page.active { display: block; }
        .stats { display: flex; gap: 12px; margin-bottom: 20px; }
        .stat-card { background: #fff; border-radius: 10px; padding: 15px 20px; flex: 1; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
        .stat-card .num { font-size: 28px; font-weight: 700; color: #1a1a2e; }
        .stat-card .label { font-size: 13px; color: #888; margin-top: 2px; }
        .table-wrap { background: #fff; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f8f9fa; }
        th { padding: 12px 14px; font-size: 13px; font-weight: 600; color: #666; text-align: right; border-bottom: 2px solid #eee; white-space: nowrap; }
        td { padding: 12px 14px; font-size: 14px; border-bottom: 1px solid #f0f0f0; }
        tr:hover { background: #f8f9fa; }
        .phone-cell { direction: ltr; text-align: right; font-family: monospace; font-size: 13px; }
        .btn-dial { background: #4CAF50; color: #fff; border: none; padding: 7px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .btn-dial:hover { background: #43A047; }
        .btn-sm { border: none; padding: 5px 10px; border-radius: 5px; font-size: 12px; cursor: pointer; margin-left: 4px; }
        .btn-edit { background: #2196F3; color: #fff; }
        .btn-delete { background: #f44336; color: #fff; }
        .btn-log { background: #9C27B0; color: #fff; }
        .btn-play { background: #FF9800; color: #fff; }
        .btn-unassign { background: #607D8B; color: #fff; }
        .actions-cell { white-space: nowrap; }
        .empty-state { text-align: center; padding: 60px 20px; color: #aaa; }
        .empty-state .icon { font-size: 48px; margin-bottom: 12px; }
        .bulk-bar { display: none; background: #1a1a2e; color: #fff; padding: 10px 16px; border-radius: 8px; margin-bottom: 12px; align-items: center; justify-content: space-between; }
        .bulk-bar.show { display: flex; }
        .bulk-bar .count { font-size: 14px; font-weight: 600; }
        .bulk-bar button { background: #f44336; color: #fff; border: none; padding: 8px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .badge-out { background: #E3F2FD; color: #1565C0; }
        .badge-in { background: #E8F5E9; color: #2E7D32; }
        .badge-free { background: #E8F5E9; color: #2E7D32; }
        .badge-taken { background: #FFF3E0; color: #E65100; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal { background: #fff; border-radius: 14px; padding: 30px; width: 90%; max-width: 500px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); max-height: 90vh; overflow-y: auto; }
        .modal h2 { font-size: 20px; margin-bottom: 20px; color: #1a1a2e; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; color: #555; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 15px; outline: none; font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #4CAF50; }
        .form-group input[dir="ltr"], .form-group textarea[dir="ltr"] { text-align: left; direction: ltr; }
        .form-group select { direction: ltr; }
        .modal-actions { display: flex; gap: 10px; margin-top: 24px; }
        .btn-save { background: #4CAF50; color: #fff; border: none; padding: 10px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; flex: 1; }
        .btn-cancel { background: #e0e0e0; color: #555; border: none; padding: 10px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; }
        .dial-info { background: #f8f9fa; border-radius: 8px; padding: 14px; margin-bottom: 16px; }
        .dial-info .driver-name { font-size: 18px; font-weight: 700; }
        .dial-info .driver-phone { font-size: 14px; color: #666; direction: ltr; display: inline-block; margin-top: 4px; }
        .driver-log-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .driver-log-table th { padding: 8px 10px; font-size: 12px; background: #f0f0f0; text-align: right; }
        .driver-log-table td { padding: 8px 10px; font-size: 13px; border-bottom: 1px solid #f0f0f0; }
        .toast { display: none; position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); padding: 12px 24px; border-radius: 8px; color: #fff; font-size: 15px; font-weight: 600; z-index: 2000; }
        .toast.success { background: #4CAF50; }
        .toast.error { background: #f44336; }
        .toast.show { display: block; }
        input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        @media (max-width: 600px) {
            .header { padding: 12px; }
            .header h1 { font-size: 18px; }
            .nav-item { padding: 8px 12px; font-size: 13px; }
            .stats { flex-direction: column; }
            td, th { padding: 8px 10px; font-size: 12px; }
        }
    </style>
</head>
<body>

<div class="header">
    <div class="header-top">
        <div>
            <h1>ניהול נהגים</h1>
            <div class="subtitle">CRM חיוג נהגים</div>
        </div>
        <button class="btn-add" id="headerBtn" onclick="headerAction()">+ הוסף נהג</button>
    </div>
    <div class="nav" id="navBar">
        <div class="nav-item active" data-page="drivers" onclick="switchPage('drivers')">נהגים</div>
        <div class="nav-item" data-page="vnums" onclick="switchPage('vnums')">מספרים וירטואליים</div>
        <div class="nav-item" data-page="log" onclick="switchPage('log')">יומן שיחות</div>
    </div>
</div>

<div class="container">

    <!-- ===== נהגים ===== -->
    <div class="page active" id="page-drivers">
        <div class="stats">
            <div class="stat-card"><div class="num" id="totalDrivers">0</div><div class="label">סה"כ נהגים</div></div>
            <div class="stat-card"><div class="num" id="totalCalls">0</div><div class="label">שיחות היום</div></div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>#</th><th>שם נהג</th><th>טלפון</th><th>מספר וירטואלי</th><th>חיוג</th><th>פעולות</th></tr></thead>
                <tbody id="driversTable"></tbody>
            </table>
            <div class="empty-state" id="emptyDrivers"><div class="icon">🚗</div><p>אין נהגים — לחץ "הוסף נהג" להתחיל</p></div>
        </div>
    </div>

    <!-- ===== מספרים וירטואליים ===== -->
    <div class="page" id="page-vnums">
        <div class="stats">
            <div class="stat-card"><div class="num" id="totalVnums">0</div><div class="label">סה"כ מספרים</div></div>
            <div class="stat-card"><div class="num" id="freeVnums">0</div><div class="label">פנויים</div></div>
            <div class="stat-card"><div class="num" id="takenVnums">0</div><div class="label">מוקצים</div></div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>#</th><th>מספר וירטואלי</th><th>סטטוס</th><th>מוקצה לנהג</th><th>פעולות</th></tr></thead>
                <tbody id="vnumsTable"></tbody>
            </table>
            <div class="empty-state" id="emptyVnums"><div class="icon">📱</div><p>אין מספרים — לחץ "הוסף מספרים" להתחיל</p></div>
        </div>
    </div>

    <!-- ===== יומן שיחות ===== -->
    <div class="page" id="page-log">
        <div class="bulk-bar" id="bulkBar">
            <span class="count" id="selectedCount">0 נבחרו</span>
            <button onclick="bulkDeleteLog()">מחק נבחרים</button>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                    <th>תאריך</th><th>נהג</th><th>נוסע</th><th>סוג</th><th>משך</th><th>פעולות</th>
                </tr></thead>
                <tbody id="logTable"></tbody>
            </table>
            <div class="empty-state" id="emptyLog"><div class="icon">📋</div><p>אין שיחות ביומן</p></div>
        </div>
    </div>
</div>

<!-- הוספה/עריכה נהג -->
<div class="modal-overlay" id="driverModal">
    <div class="modal">
        <h2 id="modalTitle">הוסף נהג</h2>
        <input type="hidden" id="editIndex" value="-1">
        <div class="form-group"><label>שם נהג</label><input type="text" id="driverName" placeholder="ישראל ישראלי"></div>
        <div class="form-group"><label>מספר טלפון</label><input type="tel" id="driverPhone" dir="ltr" placeholder="05XXXXXXXX"></div>
        <div class="form-group">
            <label>מספר וירטואלי</label>
            <select id="driverVirtual" dir="ltr">
                <option value="">— ללא —</option>
            </select>
        </div>
        <div class="modal-actions">
            <button class="btn-save" onclick="saveDriver()">שמור</button>
            <button class="btn-cancel" onclick="closeModal('driverModal')">ביטול</button>
        </div>
    </div>
</div>

<!-- חיוג -->
<div class="modal-overlay" id="dialModal">
    <div class="modal">
        <h2>חייג לנוסע</h2>
        <div class="dial-info">
            <div class="driver-name" id="dialDriverName"></div>
            <div class="driver-phone" id="dialDriverPhone"></div>
        </div>
        <input type="hidden" id="dialDriverIndex">
        <div class="form-group"><label>מספר טלפון של הנוסע</label><input type="tel" id="passengerPhone" dir="ltr" placeholder="05XXXXXXXX"></div>
        <div class="modal-actions">
            <button class="btn-save" onclick="executeDial()" style="background:#FF9800;">📞 חייג</button>
            <button class="btn-cancel" onclick="closeModal('dialModal')">ביטול</button>
        </div>
    </div>
</div>

<!-- הוספת מספרים וירטואליים -->
<div class="modal-overlay" id="addVnumsModal">
    <div class="modal">
        <h2>הוסף מספרים וירטואליים</h2>
        <div class="form-group">
            <label>הדבק או הקלד מספרים (כל מספר בשורה חדשה)</label>
            <textarea id="vnumsInput" dir="ltr" rows="8" placeholder="0771234567&#10;0779876543&#10;0775554433"></textarea>
        </div>
        <div class="modal-actions">
            <button class="btn-save" onclick="addVnums()">הוסף</button>
            <button class="btn-cancel" onclick="closeModal('addVnumsModal')">ביטול</button>
        </div>
    </div>
</div>

<!-- יומן שיחות של נהג -->
<div class="modal-overlay" id="driverLogModal">
    <div class="modal" style="max-width:600px;">
        <h2 id="driverLogTitle">יומן שיחות</h2>
        <div id="driverLogContent"></div>
        <div class="modal-actions"><button class="btn-cancel" onclick="closeModal('driverLogModal')" style="flex:1;">סגור</button></div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
const SELF = location.pathname.split('/').pop() || 'management.php';
let drivers = [], callLog = [], vnums = [];
let selectedIds = new Set();
let currentPage = 'drivers';

// ===== ניווט =====
function switchPage(page) {
    currentPage = page;
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    document.getElementById('page-' + page).classList.add('active');
    document.querySelector(`.nav-item[data-page="${page}"]`).classList.add('active');

    const btn = document.getElementById('headerBtn');
    if (page === 'drivers') { btn.style.display = ''; btn.textContent = '+ הוסף נהג'; }
    else if (page === 'vnums') { btn.style.display = ''; btn.textContent = '+ הוסף מספרים'; }
    else { btn.style.display = 'none'; }

    if (page === 'log') loadLog();
    if (page === 'vnums') loadVnums();
}

function headerAction() {
    if (currentPage === 'drivers') openAddModal();
    else if (currentPage === 'vnums') document.getElementById('addVnumsModal').classList.add('active');
}

// ===== נהגים =====
async function loadDrivers() {
    drivers = await (await fetch(SELF + '?api=list')).json();
    renderDrivers();
}

function renderDrivers() {
    const tbody = document.getElementById('driversTable');
    const empty = document.getElementById('emptyDrivers');
    document.getElementById('totalDrivers').textContent = drivers.length;
    if (drivers.length === 0) { tbody.innerHTML = ''; empty.style.display = 'block'; return; }
    empty.style.display = 'none';
    tbody.innerHTML = drivers.map((d, i) => `
        <tr>
            <td>${i+1}</td>
            <td><strong>${esc(d.name)}</strong></td>
            <td class="phone-cell">${esc(d.phone)}</td>
            <td class="phone-cell">${esc(d.virtual || '—')}</td>
            <td><button class="btn-dial" onclick="openDialModal(${i})">📞 חייג</button></td>
            <td class="actions-cell">
                <button class="btn-sm btn-log" onclick="showDriverLog(${i})" title="יומן שיחות">📋</button>
                <button class="btn-sm btn-edit" onclick="openEditModal(${i})" title="ערוך">✏️</button>
                <button class="btn-sm btn-delete" onclick="deleteDriver(${i})" title="מחק">🗑️</button>
            </td>
        </tr>`).join('');
}

async function loadVnumsForSelect(currentVirtual) {
    vnums = await (await fetch(SELF + '?api=vnums')).json();
    const sel = document.getElementById('driverVirtual');
    sel.innerHTML = '<option value="">— ללא —</option>';
    vnums.forEach(v => {
        const free = !v.assignedTo || v.number === currentVirtual;
        if (free) {
            const opt = document.createElement('option');
            opt.value = v.number;
            opt.textContent = v.number;
            if (v.number === currentVirtual) opt.selected = true;
            sel.appendChild(opt);
        }
    });
}

function openAddModal() {
    document.getElementById('modalTitle').textContent = 'הוסף נהג';
    document.getElementById('editIndex').value = -1;
    document.getElementById('driverName').value = '';
    document.getElementById('driverPhone').value = '';
    loadVnumsForSelect('');
    document.getElementById('driverModal').classList.add('active');
}

function openEditModal(i) {
    document.getElementById('modalTitle').textContent = 'ערוך נהג';
    document.getElementById('editIndex').value = i;
    document.getElementById('driverName').value = drivers[i].name;
    document.getElementById('driverPhone').value = drivers[i].phone;
    loadVnumsForSelect(drivers[i].virtual || '');
    document.getElementById('driverModal').classList.add('active');
}

async function saveDriver() {
    const name = document.getElementById('driverName').value.trim();
    const phone = document.getElementById('driverPhone').value.trim();
    const virtual_ = document.getElementById('driverVirtual').value;
    const index = document.getElementById('editIndex').value;
    if (!name || !phone) { showToast('נא למלא שם וטלפון', 'error'); return; }
    const form = new FormData();
    form.append('name', name); form.append('phone', phone);
    form.append('virtual', virtual_); form.append('index', index);
    await fetch(SELF + '?api=save', { method: 'POST', body: form });
    closeModal('driverModal');
    showToast(index >= 0 ? 'נהג עודכן' : 'נהג נוסף', 'success');
    loadDrivers();
}

async function deleteDriver(i) {
    if (!confirm('למחוק את ' + drivers[i].name + '?')) return;
    const form = new FormData(); form.append('index', i);
    await fetch(SELF + '?api=delete', { method: 'POST', body: form });
    showToast('נהג נמחק', 'success');
    loadDrivers();
}

// ===== חיוג =====
function openDialModal(i) {
    document.getElementById('dialDriverName').textContent = drivers[i].name;
    document.getElementById('dialDriverPhone').textContent = drivers[i].phone;
    document.getElementById('dialDriverIndex').value = i;
    document.getElementById('passengerPhone').value = '';
    document.getElementById('dialModal').classList.add('active');
}

async function executeDial() {
    const i = parseInt(document.getElementById('dialDriverIndex').value);
    const passenger = document.getElementById('passengerPhone').value.trim();
    if (!passenger) { showToast('נא להכניס מספר נוסע', 'error'); return; }
    closeModal('dialModal');
    showToast('מחייג...', 'success');
    const form = new FormData();
    form.append('driverPhone', drivers[i].phone);
    form.append('passengerPhone', passenger);
    form.append('driverName', drivers[i].name);
    form.append('virtualNumber', drivers[i].virtual || '');
    try {
        const data = await (await fetch(SELF + '?api=dial', { method: 'POST', body: form })).json();
        if (data.status === 'sent' && (!data.response || !data.response.errorCode)) {
            showToast('שיחה נשלחה!', 'success');
        } else {
            showToast('שגיאה: ' + (data.response?.messige || data.response?.message || data.message || ''), 'error');
        }
    } catch(e) { showToast('שגיאת חיבור', 'error'); }
}

// ===== מספרים וירטואליים =====
async function loadVnums() {
    vnums = await (await fetch(SELF + '?api=vnums')).json();
    renderVnums();
}

function renderVnums() {
    const tbody = document.getElementById('vnumsTable');
    const empty = document.getElementById('emptyVnums');
    const free = vnums.filter(v => !v.assignedTo).length;
    const taken = vnums.length - free;
    document.getElementById('totalVnums').textContent = vnums.length;
    document.getElementById('freeVnums').textContent = free;
    document.getElementById('takenVnums').textContent = taken;

    if (vnums.length === 0) { tbody.innerHTML = ''; empty.style.display = 'block'; return; }
    empty.style.display = 'none';
    tbody.innerHTML = vnums.map((v, i) => {
        const isTaken = !!v.assignedTo;
        const statusBadge = isTaken
            ? '<span class="badge badge-taken">מוקצה</span>'
            : '<span class="badge badge-free">פנוי</span>';
        const assignedTo = isTaken ? `<strong>${esc(v.assignedName || '')}</strong><br><span class="phone-cell" style="font-size:12px">${esc(v.assignedTo)}</span>` : '—';
        return `<tr>
            <td>${i+1}</td>
            <td class="phone-cell" style="font-size:15px;font-weight:600;">${esc(v.number)}</td>
            <td>${statusBadge}</td>
            <td>${assignedTo}</td>
            <td class="actions-cell">
                ${isTaken ? `<button class="btn-sm btn-unassign" onclick="unassignVnum('${esc(v.number)}')" title="הסר הקצאה">🔓</button>` : ''}
                <button class="btn-sm btn-delete" onclick="deleteVnum('${esc(v.number)}')" title="מחק מספר">🗑️</button>
            </td>
        </tr>`;
    }).join('');
}

async function addVnums() {
    const text = document.getElementById('vnumsInput').value.trim();
    if (!text) { showToast('נא להכניס מספרים', 'error'); return; }
    const form = new FormData(); form.append('numbers', text);
    const data = await (await fetch(SELF + '?api=vnum_add', { method: 'POST', body: form })).json();
    closeModal('addVnumsModal');
    document.getElementById('vnumsInput').value = '';
    showToast(`נוספו ${data.added} מספרים (סה"כ ${data.total})`, 'success');
    loadVnums();
}

async function deleteVnum(number) {
    if (!confirm('למחוק מספר ' + number + '?')) return;
    const form = new FormData(); form.append('number', number);
    await fetch(SELF + '?api=vnum_delete', { method: 'POST', body: form });
    showToast('מספר נמחק', 'success');
    loadVnums(); loadDrivers();
}

async function unassignVnum(number) {
    if (!confirm('להסיר הקצאה ממספר ' + number + '?')) return;
    const form = new FormData(); form.append('number', number);
    await fetch(SELF + '?api=vnum_unassign', { method: 'POST', body: form });
    showToast('הקצאה הוסרה', 'success');
    loadVnums(); loadDrivers();
}

// ===== יומן שיחות =====
async function loadLog() {
    callLog = await (await fetch(SELF + '?api=log')).json();
    document.getElementById('totalCalls').textContent = callLog.filter(l => new Date(l.time).toDateString() === new Date().toDateString()).length;
    renderLog();
}

function renderLog() {
    const tbody = document.getElementById('logTable');
    const empty = document.getElementById('emptyLog');
    selectedIds.clear(); updateBulkBar();
    if (callLog.length === 0) { tbody.innerHTML = ''; empty.style.display = 'block'; return; }
    empty.style.display = 'none';
    tbody.innerHTML = [...callLog].reverse().map(l => {
        const typeLabel = l.type === 'incoming' ? '<span class="badge badge-in">נכנסת</span>' : '<span class="badge badge-out">יוצאת</span>';
        return `<tr>
            <td><input type="checkbox" value="${esc(l.id)}" onchange="toggleSelect(this)"></td>
            <td style="white-space:nowrap;font-size:13px;">${esc(l.time||'')}</td>
            <td><strong>${esc(l.driverName||'')}</strong><br><span class="phone-cell" style="font-size:12px">${esc(l.driverPhone||'')}</span></td>
            <td class="phone-cell">${esc(l.passengerPhone||'')}</td>
            <td>${typeLabel}</td>
            <td style="direction:ltr;text-align:right;">${esc(l.duration||'--:--')}</td>
            <td class="actions-cell">
                <button class="btn-sm btn-play" onclick="playRecording('${esc(l.id)}')" title="שמיעת הקלטה">🎧</button>
                <button class="btn-sm btn-delete" onclick="deleteLogEntry('${esc(l.id)}')" title="מחק">🗑️</button>
            </td>
        </tr>`;
    }).join('');
}

function toggleSelect(cb) { cb.checked ? selectedIds.add(cb.value) : selectedIds.delete(cb.value); updateBulkBar(); }
function toggleSelectAll() {
    const c = document.getElementById('selectAll').checked;
    document.querySelectorAll('#logTable input[type="checkbox"]').forEach(cb => { cb.checked = c; c ? selectedIds.add(cb.value) : selectedIds.delete(cb.value); });
    updateBulkBar();
}
function updateBulkBar() {
    const bar = document.getElementById('bulkBar');
    if (selectedIds.size > 0) { bar.classList.add('show'); document.getElementById('selectedCount').textContent = selectedIds.size + ' נבחרו'; }
    else bar.classList.remove('show');
}

async function bulkDeleteLog() {
    if (!confirm('למחוק ' + selectedIds.size + ' שיחות?')) return;
    const form = new FormData(); form.append('ids', JSON.stringify([...selectedIds]));
    await fetch(SELF + '?api=log_delete', { method: 'POST', body: form });
    showToast('שיחות נמחקו', 'success'); loadLog();
}
async function deleteLogEntry(id) {
    if (!confirm('למחוק שיחה?')) return;
    const form = new FormData(); form.append('ids', JSON.stringify([id]));
    await fetch(SELF + '?api=log_delete', { method: 'POST', body: form });
    showToast('שיחה נמחקה', 'success'); loadLog();
}
function playRecording(id) { alert('עדיין לא עובד'); }

// ===== יומן נהג =====
async function showDriverLog(i) {
    const d = drivers[i];
    document.getElementById('driverLogTitle').textContent = 'יומן שיחות — ' + d.name;
    const log = await (await fetch(SELF + '?api=log&driverPhone=' + encodeURIComponent(d.phone))).json();
    if (log.length === 0) {
        document.getElementById('driverLogContent').innerHTML = '<p style="color:#aaa;text-align:center;padding:20px;">אין שיחות לנהג זה</p>';
    } else {
        const rows = [...log].reverse().map(l => {
            const t = l.type === 'incoming' ? '<span class="badge badge-in">נכנסת</span>' : '<span class="badge badge-out">יוצאת</span>';
            return `<tr><td>${esc(l.time||'')}</td><td class="phone-cell">${esc(l.passengerPhone||'')}</td><td>${t}</td><td style="direction:ltr">${esc(l.duration||'--:--')}</td><td><button class="btn-sm btn-play" onclick="playRecording('${esc(l.id)}')">🎧</button></td></tr>`;
        }).join('');
        document.getElementById('driverLogContent').innerHTML = `<table class="driver-log-table"><thead><tr><th>תאריך</th><th>נוסע</th><th>סוג</th><th>משך</th><th>הקלטה</th></tr></thead><tbody>${rows}</tbody></table>`;
    }
    document.getElementById('driverLogModal').classList.add('active');
}

// ===== Helpers =====
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function showToast(msg, type) { const t = document.getElementById('toast'); t.textContent = msg; t.className = 'toast ' + type + ' show'; setTimeout(() => t.className = 'toast', 3000); }
function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
document.querySelectorAll('.modal-overlay').forEach(o => { o.addEventListener('click', e => { if (e.target === o) o.classList.remove('active'); }); });

loadDrivers();
loadLog();
</script>
</body>
</html>
