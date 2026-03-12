<?php
/**
 * ממשק ניהול נהגים — CRM
 *
 * פותח בדפדפן: https://your-server.com/management.php
 * הנהגים נשמרים בקובץ drivers.json בשרת
 *
 * API פנימי (הממשק קורא לעצמו):
 * ?api=list       — רשימת נהגים
 * ?api=save       — שמירת נהג (POST: name, phone, virtual, index)
 * ?api=delete     — מחיקת נהג (POST: index)
 * ?api=dial       — חיוג (POST: driverPhone, passengerPhone, driverName)
 */

$api = $_GET['api'] ?? '';
$driversFile = __DIR__ . '/drivers.json';

// ========== API: רשימת נהגים ==========
if ($api === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $drivers = [];
    if (file_exists($driversFile)) {
        $drivers = json_decode(file_get_contents($driversFile), true) ?: [];
    }
    echo json_encode($drivers, JSON_UNESCAPED_UNICODE);
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

    $drivers = [];
    if (file_exists($driversFile)) {
        $drivers = json_decode(file_get_contents($driversFile), true) ?: [];
    }

    $driver = ["name" => $name, "phone" => $phone, "virtual" => $virtual];

    if ($index >= 0 && $index < count($drivers)) {
        $drivers[(int)$index] = $driver;
    } else {
        $drivers[] = $driver;
    }

    file_put_contents($driversFile, json_encode($drivers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(["status" => "ok"]);
    exit;
}

// ========== API: מחיקת נהג ==========
if ($api === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $index = (int)($_POST['index'] ?? -1);

    $drivers = [];
    if (file_exists($driversFile)) {
        $drivers = json_decode(file_get_contents($driversFile), true) ?: [];
    }

    if ($index >= 0 && $index < count($drivers)) {
        array_splice($drivers, $index, 1);
        file_put_contents($driversFile, json_encode($drivers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo json_encode(["status" => "ok"]);
    } else {
        echo json_encode(["status" => "error", "message" => "אינדקס לא תקין"]);
    }
    exit;
}

// ========== API: חיוג ==========
if ($api === 'dial') {
    header('Content-Type: application/json; charset=utf-8');

    $driverPhone    = $_POST['driverPhone'] ?? '';
    $passengerPhone = $_POST['passengerPhone'] ?? '';
    $driverName     = $_POST['driverName'] ?? '';

    if (empty($driverPhone) || empty($passengerPhone)) {
        echo json_encode(["status" => "error", "message" => "חסר מספר טלפון"]);
        exit;
    }

    // שמירת מיפוי נהג→נוסע (extension.php קורא את זה)
    $mappingFile = __DIR__ . '/call_mapping.json';
    $mappings = [];
    if (file_exists($mappingFile)) {
        $mappings = json_decode(file_get_contents($mappingFile), true) ?: [];
    }

    $mappings[$driverPhone] = [
        "passengerPhone" => $passengerPhone,
        "driverName"     => $driverName,
        "timestamp"      => date('Y-m-d H:i:s')
    ];

    file_put_contents($mappingFile, json_encode($mappings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // הפעלת campaign API — מתקשר לנהג
    $data = [
        "action"              => "campaignRun",
        "apiKey"              => "798407e3a74922",
        "messagesType"        => "extensionActivation",
        "extensionActivation" => "8576",
        "phones"              => $driverPhone,
        "callLength"          => 60,
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo json_encode(["status" => "error", "message" => $error]);
    } else {
        echo json_encode([
            "status"    => "sent",
            "driver"    => $driverPhone,
            "passenger" => $passengerPhone,
            "response"  => json_decode($response, true) ?? $response
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ========== HTML ממשק ==========
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ניהול נהגים</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: #f0f2f5;
            color: #1a1a2e;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #fff;
            padding: 20px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        .header h1 { font-size: 22px; font-weight: 700; }
        .header .subtitle { font-size: 13px; opacity: 0.7; margin-top: 4px; }
        .btn-add {
            background: #4CAF50; color: #fff; border: none;
            padding: 10px 22px; border-radius: 8px; font-size: 15px;
            font-weight: 600; cursor: pointer;
        }
        .btn-add:hover { background: #43A047; }
        .container { max-width: 900px; margin: 20px auto; padding: 0 15px; }

        .stats { display: flex; gap: 12px; margin-bottom: 20px; }
        .stat-card {
            background: #fff; border-radius: 10px; padding: 15px 20px;
            flex: 1; box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        }
        .stat-card .num { font-size: 28px; font-weight: 700; color: #1a1a2e; }
        .stat-card .label { font-size: 13px; color: #888; margin-top: 2px; }

        .table-wrap {
            background: #fff; border-radius: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08); overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f8f9fa; }
        th {
            padding: 14px 16px; font-size: 13px; font-weight: 600;
            color: #666; text-align: right; border-bottom: 2px solid #eee;
        }
        td { padding: 14px 16px; font-size: 15px; border-bottom: 1px solid #f0f0f0; }
        tr:hover { background: #f8f9fa; }
        .phone-cell { direction: ltr; text-align: right; font-family: monospace; font-size: 14px; }

        .btn-dial {
            background: #4CAF50; color: #fff; border: none;
            padding: 8px 18px; border-radius: 6px; font-size: 14px;
            font-weight: 600; cursor: pointer;
        }
        .btn-dial:hover { background: #43A047; }
        .btn-edit {
            background: #2196F3; color: #fff; border: none;
            padding: 6px 12px; border-radius: 6px; font-size: 13px;
            cursor: pointer; margin-left: 6px;
        }
        .btn-delete {
            background: #f44336; color: #fff; border: none;
            padding: 6px 12px; border-radius: 6px; font-size: 13px;
            cursor: pointer; margin-left: 6px;
        }
        .actions-cell { white-space: nowrap; }

        .empty-state { text-align: center; padding: 60px 20px; color: #aaa; }
        .empty-state .icon { font-size: 48px; margin-bottom: 12px; }

        .modal-overlay {
            display: none; position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5); z-index: 1000;
            align-items: center; justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: #fff; border-radius: 14px; padding: 30px;
            width: 90%; max-width: 440px; box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .modal h2 { font-size: 20px; margin-bottom: 20px; color: #1a1a2e; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; color: #555; }
        .form-group input {
            width: 100%; padding: 10px 14px; border: 2px solid #e0e0e0;
            border-radius: 8px; font-size: 15px; outline: none;
        }
        .form-group input:focus { border-color: #4CAF50; }
        .form-group input[dir="ltr"] { text-align: left; direction: ltr; }
        .modal-actions { display: flex; gap: 10px; margin-top: 24px; }
        .btn-save {
            background: #4CAF50; color: #fff; border: none;
            padding: 10px 28px; border-radius: 8px; font-size: 15px;
            font-weight: 600; cursor: pointer; flex: 1;
        }
        .btn-cancel {
            background: #e0e0e0; color: #555; border: none;
            padding: 10px 28px; border-radius: 8px; font-size: 15px;
            font-weight: 600; cursor: pointer;
        }
        .dial-info { background: #f8f9fa; border-radius: 8px; padding: 14px; margin-bottom: 16px; }
        .dial-info .driver-name { font-size: 18px; font-weight: 700; }
        .dial-info .driver-phone { font-size: 14px; color: #666; direction: ltr; display: inline-block; margin-top: 4px; }

        .toast {
            display: none; position: fixed; bottom: 30px; left: 50%;
            transform: translateX(-50%); padding: 12px 24px; border-radius: 8px;
            color: #fff; font-size: 15px; font-weight: 600; z-index: 2000;
        }
        .toast.success { background: #4CAF50; }
        .toast.error { background: #f44336; }
        .toast.show { display: block; }

        @media (max-width: 600px) {
            .header { padding: 15px; }
            .header h1 { font-size: 18px; }
            .stats { flex-direction: column; }
            td, th { padding: 10px 12px; font-size: 13px; }
        }
    </style>
</head>
<body>

<div class="header">
    <div>
        <h1>ניהול נהגים</h1>
        <div class="subtitle">CRM חיוג נהגים</div>
    </div>
    <button class="btn-add" onclick="openAddModal()">+ הוסף נהג</button>
</div>

<div class="container">
    <div class="stats">
        <div class="stat-card">
            <div class="num" id="totalDrivers">0</div>
            <div class="label">סה"כ נהגים</div>
        </div>
        <div class="stat-card">
            <div class="num" id="totalCalls">0</div>
            <div class="label">שיחות היום</div>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>שם נהג</th>
                    <th>טלפון</th>
                    <th>מספר וירטואלי</th>
                    <th>חיוג</th>
                    <th>פעולות</th>
                </tr>
            </thead>
            <tbody id="driversTable"></tbody>
        </table>
        <div class="empty-state" id="emptyState">
            <div class="icon">🚗</div>
            <p>אין נהגים — לחץ "הוסף נהג" להתחיל</p>
        </div>
    </div>
</div>

<!-- הוספה/עריכה -->
<div class="modal-overlay" id="driverModal">
    <div class="modal">
        <h2 id="modalTitle">הוסף נהג</h2>
        <input type="hidden" id="editIndex" value="-1">
        <div class="form-group">
            <label>שם נהג</label>
            <input type="text" id="driverName" placeholder="ישראל ישראלי">
        </div>
        <div class="form-group">
            <label>מספר טלפון</label>
            <input type="tel" id="driverPhone" dir="ltr" placeholder="05XXXXXXXX">
        </div>
        <div class="form-group">
            <label>מספר וירטואלי</label>
            <input type="tel" id="driverVirtual" dir="ltr" placeholder="05XXXXXXXX">
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
        <div class="form-group">
            <label>מספר טלפון של הנוסע</label>
            <input type="tel" id="passengerPhone" dir="ltr" placeholder="05XXXXXXXX">
        </div>
        <div class="modal-actions">
            <button class="btn-save" onclick="executeDial()" style="background:#FF9800;">📞 חייג</button>
            <button class="btn-cancel" onclick="closeModal('dialModal')">ביטול</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
let drivers = [];
let todayCalls = 0;

async function loadDrivers() {
    const resp = await fetch('management.php?api=list');
    drivers = await resp.json();
    renderDrivers();
}

function renderDrivers() {
    const tbody = document.getElementById('driversTable');
    const empty = document.getElementById('emptyState');
    document.getElementById('totalDrivers').textContent = drivers.length;
    document.getElementById('totalCalls').textContent = todayCalls;

    if (drivers.length === 0) {
        tbody.innerHTML = '';
        empty.style.display = 'block';
        return;
    }
    empty.style.display = 'none';
    tbody.innerHTML = drivers.map((d, i) => `
        <tr>
            <td>${i + 1}</td>
            <td><strong>${esc(d.name)}</strong></td>
            <td class="phone-cell">${esc(d.phone)}</td>
            <td class="phone-cell">${esc(d.virtual || '')}</td>
            <td><button class="btn-dial" onclick="openDialModal(${i})">📞 חייג</button></td>
            <td class="actions-cell">
                <button class="btn-edit" onclick="openEditModal(${i})">✏️</button>
                <button class="btn-delete" onclick="deleteDriver(${i})">🗑️</button>
            </td>
        </tr>
    `).join('');
}

function openAddModal() {
    document.getElementById('modalTitle').textContent = 'הוסף נהג';
    document.getElementById('editIndex').value = -1;
    document.getElementById('driverName').value = '';
    document.getElementById('driverPhone').value = '';
    document.getElementById('driverVirtual').value = '';
    document.getElementById('driverModal').classList.add('active');
}

function openEditModal(i) {
    document.getElementById('modalTitle').textContent = 'ערוך נהג';
    document.getElementById('editIndex').value = i;
    document.getElementById('driverName').value = drivers[i].name;
    document.getElementById('driverPhone').value = drivers[i].phone;
    document.getElementById('driverVirtual').value = drivers[i].virtual || '';
    document.getElementById('driverModal').classList.add('active');
}

async function saveDriver() {
    const name = document.getElementById('driverName').value.trim();
    const phone = document.getElementById('driverPhone').value.trim();
    const virtual_ = document.getElementById('driverVirtual').value.trim();
    const index = document.getElementById('editIndex').value;

    if (!name || !phone) { showToast('נא למלא שם וטלפון', 'error'); return; }

    const form = new FormData();
    form.append('name', name);
    form.append('phone', phone);
    form.append('virtual', virtual_);
    form.append('index', index);

    await fetch('management.php?api=save', { method: 'POST', body: form });
    closeModal('driverModal');
    showToast(index >= 0 ? 'נהג עודכן' : 'נהג נוסף', 'success');
    loadDrivers();
}

async function deleteDriver(i) {
    if (!confirm('למחוק את ' + drivers[i].name + '?')) return;
    const form = new FormData();
    form.append('index', i);
    await fetch('management.php?api=delete', { method: 'POST', body: form });
    showToast('נהג נמחק', 'success');
    loadDrivers();
}

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

    try {
        const resp = await fetch('management.php?api=dial', { method: 'POST', body: form });
        const data = await resp.json();
        if (data.status === 'sent') {
            todayCalls++;
            document.getElementById('totalCalls').textContent = todayCalls;
            showToast('שיחה נשלחה!', 'success');
        } else {
            showToast('שגיאה: ' + (data.message || ''), 'error');
        }
    } catch(e) {
        showToast('שגיאת חיבור', 'error');
    }
}

function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + type + ' show';
    setTimeout(() => t.className = 'toast', 3000);
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', e => { if (e.target === o) o.classList.remove('active'); });
});

loadDrivers();
</script>
</body>
</html>
