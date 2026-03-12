<?php
/**
 * Driver CRM - All-in-one
 *
 * URLs:
 * - drivers.php              → מציג את הממשק
 * - drivers.php?action=dial  → מחייג לנהג ומחבר לנוסע
 * - drivers.php?action=ivr   → URL לשלוחה 8576 במרכזייה
 */

$action = $_GET['action'] ?? '';

// ==================== IVR API (for PBX extension 8576) ====================
if ($action === 'ivr') {
    header('Content-Type: application/json; charset=utf-8');

    $phone       = $_GET['PBXphone'] ?? '';
    $callStatus  = $_GET['PBXcallStatus'] ?? '';

    if ($callStatus === 'HANGUP') {
        echo json_encode(["type" => "goTo", "goTo" => ""]);
        exit;
    }

    // Look up passenger phone from mapping
    $mappingFile = __DIR__ . '/call_mapping.json';
    $passengerPhone = '';

    if (file_exists($mappingFile)) {
        $mappings = json_decode(file_get_contents($mappingFile), true) ?: [];

        if (isset($mappings[$phone])) {
            $passengerPhone = $mappings[$phone]['passengerPhone'];
        } else {
            // Try matching without leading 0 / with +972
            foreach ($mappings as $key => $val) {
                $normalKey = preg_replace('/^(\+?972|0)/', '', $key);
                $normalPhone = preg_replace('/^(\+?972|0)/', '', $phone);
                if ($normalKey === $normalPhone) {
                    $passengerPhone = $val['passengerPhone'];
                    break;
                }
            }
        }
    }

    if (empty($passengerPhone)) {
        $passengerPhone = '0533124489'; // Default fallback
    }

    echo json_encode([
        "type"          => "simpleRouting",
        "name"          => "dialPassenger",
        "dialPhone"     => $passengerPhone,
        "displayNumber" => $passengerPhone,
        "routingMusic"  => "yes",
        "ringSec"       => 30,
        "limit"         => ""
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==================== DIAL API ====================
if ($action === 'dial') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');

    $driverPhone    = $_GET['driverPhone'] ?? '';
    $passengerPhone = $_GET['passengerPhone'] ?? '';
    $driverName     = $_GET['driverName'] ?? '';

    if (empty($driverPhone) || empty($passengerPhone)) {
        echo json_encode(["status" => "error", "message" => "חסר מספר טלפון"]);
        exit;
    }

    // Save mapping: driver phone → passenger phone
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

    // Trigger Campaign API to call the driver
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
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo json_encode(["status" => "error", "message" => $error]);
    } else {
        echo json_encode([
            "status"   => "sent",
            "httpCode" => $httpCode,
            "driver"   => $driverPhone,
            "passenger"=> $passengerPhone,
            "response" => json_decode($response, true) ?? $response
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ==================== HTML INTERFACE ====================
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
            background: #4CAF50;
            color: #fff;
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-add:hover { background: #43A047; }
        .container { max-width: 900px; margin: 20px auto; padding: 0 15px; }

        .stats { display: flex; gap: 12px; margin-bottom: 20px; }
        .stat-card {
            background: #fff;
            border-radius: 10px;
            padding: 15px 20px;
            flex: 1;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        }
        .stat-card .num { font-size: 28px; font-weight: 700; color: #1a1a2e; }
        .stat-card .label { font-size: 13px; color: #888; margin-top: 2px; }

        .table-wrap {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f8f9fa; }
        th {
            padding: 14px 16px;
            font-size: 13px;
            font-weight: 600;
            color: #666;
            text-align: right;
            border-bottom: 2px solid #eee;
        }
        td {
            padding: 14px 16px;
            font-size: 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        tr:hover { background: #f8f9fa; }
        .phone-cell { direction: ltr; text-align: right; font-family: monospace; font-size: 14px; }

        .btn-dial {
            background: #4CAF50;
            color: #fff;
            border: none;
            padding: 8px 18px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-dial:hover { background: #43A047; }
        .btn-edit {
            background: #2196F3;
            color: #fff;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            margin-left: 6px;
        }
        .btn-edit:hover { background: #1E88E5; }
        .btn-delete {
            background: #f44336;
            color: #fff;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            margin-left: 6px;
        }
        .btn-delete:hover { background: #E53935; }
        .actions-cell { white-space: nowrap; }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #aaa;
        }
        .empty-state .icon { font-size: 48px; margin-bottom: 12px; }
        .empty-state p { font-size: 16px; }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: #fff;
            border-radius: 14px;
            padding: 30px;
            width: 90%;
            max-width: 440px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .modal h2 { font-size: 20px; margin-bottom: 20px; color: #1a1a2e; }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #555;
        }
        .form-group input {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-group input:focus { border-color: #4CAF50; }
        .form-group input[dir="ltr"] { text-align: left; direction: ltr; }
        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 24px;
        }
        .btn-save {
            background: #4CAF50;
            color: #fff;
            border: none;
            padding: 10px 28px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            flex: 1;
        }
        .btn-save:hover { background: #43A047; }
        .btn-cancel {
            background: #e0e0e0;
            color: #555;
            border: none;
            padding: 10px 28px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-cancel:hover { background: #d5d5d5; }

        .dial-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 16px;
        }
        .dial-info .driver-name { font-size: 18px; font-weight: 700; color: #1a1a2e; }
        .dial-info .driver-phone { font-size: 14px; color: #666; direction: ltr; display: inline-block; margin-top: 4px; }

        .toast {
            display: none;
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            padding: 12px 24px;
            border-radius: 8px;
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            z-index: 2000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .toast.success { background: #4CAF50; }
        .toast.error { background: #f44336; }
        .toast.show { display: block; animation: fadeInUp 0.3s ease; }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateX(-50%) translateY(10px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }

        .log-section {
            margin-top: 20px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .log-header {
            padding: 14px 16px;
            font-size: 15px;
            font-weight: 600;
            color: #666;
            border-bottom: 1px solid #f0f0f0;
        }
        .log-list { max-height: 200px; overflow-y: auto; }
        .log-item {
            padding: 10px 16px;
            font-size: 13px;
            border-bottom: 1px solid #f8f8f8;
            display: flex;
            justify-content: space-between;
        }
        .log-item .time { color: #aaa; direction: ltr; font-family: monospace; }
        .log-item.success .msg { color: #4CAF50; }
        .log-item.error .msg { color: #f44336; }

        @media (max-width: 600px) {
            .header { padding: 15px; }
            .header h1 { font-size: 18px; }
            .stats { flex-direction: column; }
            td, th { padding: 10px 12px; font-size: 13px; }
            .btn-dial { padding: 6px 12px; font-size: 13px; }
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

    <div class="log-section">
        <div class="log-header">יומן שיחות</div>
        <div class="log-list" id="callLog"></div>
    </div>
</div>

<!-- Add/Edit Driver Modal -->
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

<!-- Dial Modal -->
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
// API points to this same file
const DIAL_API_URL = "drivers.php?action=dial";

let drivers = [];
let callLog = [];
let todayCalls = 0;

function loadData() {
    try {
        drivers = JSON.parse(localStorage.getItem("drivers") || "[]");
        callLog = JSON.parse(localStorage.getItem("callLog") || "[]");
        const today = new Date().toDateString();
        todayCalls = callLog.filter(l => new Date(l.time).toDateString() === today).length;
    } catch(e) {
        drivers = [];
        callLog = [];
    }
}

function saveData() {
    localStorage.setItem("drivers", JSON.stringify(drivers));
    localStorage.setItem("callLog", JSON.stringify(callLog));
}

function renderDrivers() {
    const tbody = document.getElementById("driversTable");
    const empty = document.getElementById("emptyState");

    document.getElementById("totalDrivers").textContent = drivers.length;
    document.getElementById("totalCalls").textContent = todayCalls;

    if (drivers.length === 0) {
        tbody.innerHTML = "";
        empty.style.display = "block";
        return;
    }
    empty.style.display = "none";

    tbody.innerHTML = drivers.map((d, i) => `
        <tr>
            <td>${i + 1}</td>
            <td><strong>${escHtml(d.name)}</strong></td>
            <td class="phone-cell">${escHtml(d.phone)}</td>
            <td class="phone-cell">${escHtml(d.virtual)}</td>
            <td>
                <button class="btn-dial" onclick="openDialModal(${i})">📞 חייג</button>
            </td>
            <td class="actions-cell">
                <button class="btn-edit" onclick="openEditModal(${i})">✏️</button>
                <button class="btn-delete" onclick="deleteDriver(${i})">🗑️</button>
            </td>
        </tr>
    `).join("");
}

function renderLog() {
    const logEl = document.getElementById("callLog");
    if (callLog.length === 0) {
        logEl.innerHTML = '<div class="log-item"><span class="msg" style="color:#aaa;">אין שיחות עדיין</span></div>';
        return;
    }
    const recent = callLog.slice(-20).reverse();
    logEl.innerHTML = recent.map(l => `
        <div class="log-item ${l.status}">
            <span class="msg">${escHtml(l.msg)}</span>
            <span class="time">${formatTime(l.time)}</span>
        </div>
    `).join("");
}

function openAddModal() {
    document.getElementById("modalTitle").textContent = "הוסף נהג";
    document.getElementById("editIndex").value = -1;
    document.getElementById("driverName").value = "";
    document.getElementById("driverPhone").value = "";
    document.getElementById("driverVirtual").value = "";
    document.getElementById("driverModal").classList.add("active");
    document.getElementById("driverName").focus();
}

function openEditModal(index) {
    const d = drivers[index];
    document.getElementById("modalTitle").textContent = "ערוך נהג";
    document.getElementById("editIndex").value = index;
    document.getElementById("driverName").value = d.name;
    document.getElementById("driverPhone").value = d.phone;
    document.getElementById("driverVirtual").value = d.virtual;
    document.getElementById("driverModal").classList.add("active");
    document.getElementById("driverName").focus();
}

function saveDriver() {
    const name = document.getElementById("driverName").value.trim();
    const phone = document.getElementById("driverPhone").value.trim();
    const virtual_ = document.getElementById("driverVirtual").value.trim();
    const idx = parseInt(document.getElementById("editIndex").value);

    if (!name || !phone) {
        showToast("נא למלא שם וטלפון", "error");
        return;
    }

    if (idx >= 0) {
        drivers[idx] = { name, phone, virtual: virtual_ };
    } else {
        drivers.push({ name, phone, virtual: virtual_ });
    }
    saveData();
    renderDrivers();
    closeModal("driverModal");
    showToast(idx >= 0 ? "נהג עודכן" : "נהג נוסף", "success");
}

function deleteDriver(index) {
    if (!confirm(`למחוק את ${drivers[index].name}?`)) return;
    drivers.splice(index, 1);
    saveData();
    renderDrivers();
    showToast("נהג נמחק", "success");
}

function openDialModal(index) {
    const d = drivers[index];
    document.getElementById("dialDriverName").textContent = d.name;
    document.getElementById("dialDriverPhone").textContent = d.phone;
    document.getElementById("dialDriverIndex").value = index;
    document.getElementById("passengerPhone").value = "";
    document.getElementById("dialModal").classList.add("active");
    document.getElementById("passengerPhone").focus();
}

async function executeDial() {
    const index = parseInt(document.getElementById("dialDriverIndex").value);
    const passenger = document.getElementById("passengerPhone").value.trim();
    const d = drivers[index];

    if (!passenger) {
        showToast("נא להכניס מספר נוסע", "error");
        return;
    }

    closeModal("dialModal");
    showToast("מחייג...", "success");

    try {
        const params = new URLSearchParams({
            driverPhone: d.phone,
            passengerPhone: passenger,
            driverName: d.name
        });

        const resp = await fetch(DIAL_API_URL + "&" + params.toString());
        const data = await resp.json();

        if (data.status === "sent" || data.status === "ok") {
            const logEntry = {
                time: new Date().toISOString(),
                msg: `${d.name} (${d.phone}) → נוסע ${passenger}`,
                status: "success"
            };
            callLog.push(logEntry);
            todayCalls++;
            saveData();
            renderLog();
            document.getElementById("totalCalls").textContent = todayCalls;
            showToast("שיחה נשלחה בהצלחה!", "success");
        } else {
            const logEntry = {
                time: new Date().toISOString(),
                msg: `שגיאה: ${d.name} → ${passenger}: ${data.message || "unknown"}`,
                status: "error"
            };
            callLog.push(logEntry);
            saveData();
            renderLog();
            showToast("שגיאה: " + (data.message || "לא ידוע"), "error");
        }
    } catch(e) {
        const logEntry = {
            time: new Date().toISOString(),
            msg: `שגיאה: ${d.name} → ${passenger}: ${e.message}`,
            status: "error"
        };
        callLog.push(logEntry);
        saveData();
        renderLog();
        showToast("שגיאה בחיבור: " + e.message, "error");
    }
}

function closeModal(id) {
    document.getElementById(id).classList.remove("active");
}

function showToast(msg, type) {
    const t = document.getElementById("toast");
    t.textContent = msg;
    t.className = "toast " + type + " show";
    setTimeout(() => t.className = "toast", 3000);
}

function escHtml(str) {
    const d = document.createElement("div");
    d.textContent = str;
    return d.innerHTML;
}

function formatTime(iso) {
    const d = new Date(iso);
    return d.toLocaleTimeString("he-IL", { hour: "2-digit", minute: "2-digit" })
        + " " + d.toLocaleDateString("he-IL");
}

document.querySelectorAll(".modal-overlay").forEach(overlay => {
    overlay.addEventListener("click", e => {
        if (e.target === overlay) overlay.classList.remove("active");
    });
});

loadData();
renderDrivers();
renderLog();
</script>
</body>
</html>
