<?php
$env_user = 'admin'; 
$env_pass = 'PASSWORD'; // Sifrenizi buraya girin

if (($_SERVER['PHP_AUTH_USER'] ?? null) !== $env_user || ($_SERVER['PHP_AUTH_PW'] ?? null) !== $env_pass) {
    header('WWW-Authenticate: Basic realm="MQTT Admin Panel"'); header('HTTP/1.0 401 Unauthorized'); exit;
}
$db_file = '/config/traffic_db.json';
$default_db = [
    'global_limits' => [
        'secondly' => 10, 'secondly_ban_val' => 1, 'secondly_ban_unit' => 'm',
        'minutely' => 100, 'minutely_ban_val' => 5, 'minutely_ban_unit' => 'm',
        'hourly' => 1000, 'hourly_ban_val' => 1, 'hourly_ban_unit' => 'h',
        'daily' => 10000, 'daily_ban_val' => 1, 'daily_ban_unit' => 'd',
        'monthly' => 100000, 'monthly_ban_val' => 30, 'monthly_ban_unit' => 'd',
        'max_kb' => 50, 'max_kb_ban_val' => 5, 'max_kb_ban_unit' => 'm'
    ], 'user_limits' => [], 'usage' => [], 'mqtt_users' => []
];
if (!file_exists($db_file)) { $db = $default_db; @file_put_contents($db_file, json_encode($db)); @chmod($db_file, 0666); } 
else { $db = json_decode(file_get_contents($db_file), true); if (!is_array($db)) $db = $default_db; }

if (!isset($db['mqtt_users'])) $db['mqtt_users'] = [];

function format_remaining_time($seconds) {
    if ($seconds <= 0) return "";
    $d = floor($seconds / 86400); $h = floor(($seconds % 86400) / 3600); $m = floor(($seconds % 3600) / 60); $s = $seconds % 60;
    $parts = [];
    if ($d > 0) $parts[] = $d . "g"; if ($h > 0) $parts[] = $h . "sa"; if ($m > 0) $parts[] = $m . "dk"; if ($s > 0 || empty($parts)) $parts[] = $s . "sn";
    return "(" . implode(" ", $parts) . ")";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'save_global_limits') {
        $db['global_limits'] = [
            'secondly' => intval($_POST['g_sec']), 'secondly_ban_val' => intval($_POST['g_sec_bv']), 'secondly_ban_unit' => $_POST['g_sec_bu'],
            'minutely' => intval($_POST['g_min']), 'minutely_ban_val' => intval($_POST['g_min_bv']), 'minutely_ban_unit' => $_POST['g_min_bu'],
            'hourly' => intval($_POST['g_hr']), 'hourly_ban_val' => intval($_POST['g_hr_bv']), 'hourly_ban_unit' => $_POST['g_hr_bu'],
            'daily' => intval($_POST['g_day']), 'daily_ban_val' => intval($_POST['g_day_bv']), 'daily_ban_unit' => $_POST['g_day_bu'],
            'monthly' => intval($_POST['g_mo']), 'monthly_ban_val' => intval($_POST['g_mo_bv']), 'monthly_ban_unit' => $_POST['g_mo_bu'],
            'max_kb' => intval($_POST['g_kb']), 'max_kb_ban_val' => intval($_POST['g_kb_bv']), 'max_kb_ban_unit' => $_POST['g_kb_bu']
        ];
        file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT));
    }
    if ($action === 'save_user_limit') {
        $u = trim($_POST['username']);
        if ($_POST['limit_type'] === 'custom') {
            $db['user_limits'][$u] = [
                'secondly' => intval($_POST['u_sec']), 'secondly_ban_val' => intval($_POST['u_sec_bv']), 'secondly_ban_unit' => $_POST['u_sec_bu'],
                'minutely' => intval($_POST['u_min']), 'minutely_ban_val' => intval($_POST['u_min_bv']), 'minutely_ban_unit' => $_POST['u_min_bu'],
                'hourly' => intval($_POST['u_hr']), 'hourly_ban_val' => intval($_POST['u_hr_bv']), 'hourly_ban_unit' => $_POST['u_hr_bu'],
                'daily' => intval($_POST['u_day']), 'daily_ban_val' => intval($_POST['u_day_bv']), 'daily_ban_unit' => $_POST['u_day_bu'],
                'monthly' => intval($_POST['u_mo']), 'monthly_ban_val' => intval($_POST['u_mo_bv']), 'monthly_ban_unit' => $_POST['u_mo_bu'],
                'max_kb' => intval($_POST['u_kb']), 'max_kb_ban_val' => intval($_POST['u_kb_bv']), 'max_kb_ban_unit' => $_POST['u_kb_bu']
            ];
        } else { unset($db['user_limits'][$u]); }
        file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT));
    }
    if ($action === 'reactivate') {
        $u = trim($_POST['username'] ?? '');
        if (isset($db['usage'][$u])) {
            $db['usage'][$u]['blk'] = false; $db['usage'][$u]['ban_until'] = 0;
            $db['usage'][$u]['s_c'] = 0; $db['usage'][$u]['min_c'] = 0; $db['usage'][$u]['h_c'] = 0; $db['usage'][$u]['d_c'] = 0; $db['usage'][$u]['m_c'] = 0;
        }
        file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT));
    }
    if ($action === 'save_user') {
        $u = trim($_POST['username'] ?? ''); $p = trim($_POST['password'] ?? '');
        if (!empty($u) && !empty($p)) {
            $salt = random_bytes(12);
            $hash = hash_pbkdf2("sha512", $p, $salt, 101, 64, true);
            $hash_str = '$7$101$' . base64_encode($salt) . '$' . base64_encode($hash);
            $db['mqtt_users'][$u] = $hash_str;
            file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT));
        }
    }
    if ($action === 'delete') {
        $u = trim($_POST['username'] ?? '');
        unset($db['mqtt_users'][$u]); unset($db['user_limits'][$u]); unset($db['usage'][$u]);
        file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT));
    }
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}
$ordered_users = []; foreach ($db['mqtt_users'] as $u => $h) { $ordered_users[$u] = $db['usage'][$u]['m_c'] ?? 0; } arsort($ordered_users);
?>
<!DOCTYPE html><html><head><title>MQTT Panel</title><meta charset="utf-8">
<script>
    if(localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark-mode');
    function toggleTheme() {
        document.documentElement.classList.toggle('dark-mode');
        localStorage.setItem('theme', document.documentElement.classList.contains('dark-mode') ? 'dark' : 'light');
    }
</script>
<style>
    :root { --bg: #f4f6f9; --cbg: #fff; --txt: #333; --brd: #ddd; --thbg: #f8f9fa; }
    .dark-mode { --bg: #121212; --cbg: #1e1e1e; --txt: #e0e0e0; --brd: #333; --thbg: #2c2c2c; }
    body { font-family: sans-serif; background: var(--bg); color: var(--txt); padding: 20px; margin: 0; transition: background 0.3s, color 0.3s; }
    .container { display: flex; gap: 20px; margin-top: 10px; }
    @media (max-width: 1100px) { .container { flex-direction: column; } }
    .col { flex: 1; display: flex; flex-direction: column; gap: 20px; }
    .card { background: var(--cbg); padding: 20px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 10px; text-align: left; border-bottom: 1px solid var(--brd); font-size: 12px; }
    th { background: var(--thbg); }
    .flex-row { display: flex; gap: 10px; margin-bottom: 8px; }
    .flex-row div { flex: 1; }
    input, select { width: 100%; padding: 6px; border: 1px solid var(--brd); border-radius: 4px; box-sizing: border-box; font-size: 12px; background: var(--cbg); color: var(--txt); }
    label { font-size: 11px; font-weight: bold; color: var(--txt); display: block; margin-bottom: 2px; opacity: 0.8; }
    button { padding: 6px 10px; background: #007bff; color: #fff; border: 0; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 11px; }
    .btn-danger { background: #dc3545; } .btn-warning { background: #ffc107; color: #212529; } .btn-success { background: #28a745; } .btn-secondary { background: #6c757d; }
    .badge { padding: 3px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; color: #fff; }
    .bg-success { background: #28a745; } .bg-danger { background: #dc3545; }
    .theme-btn { position: absolute; top: 15px; right: 20px; background: #6c757d; }
</style></head><body>

<button onclick="toggleTheme()" class="theme-btn">🌓 Tema</button>
<h2 style="margin-top:0;">MQTT Canlı Trafik Sınırları ve Ceza Sistemi</h2>

<div class="container">
<div class="col" style="flex: 2.5;">
    <div class="card">
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr><th>Kullanıcı</th><th>Anlık (Sn)</th><th>Dakikalık</th><th>Saatlik</th><th>Günlük</th><th>Aylık</th><th>Maks KB</th><th>Durum</th><th>İşlemler</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($ordered_users as $u => $total): 
                        $usg = $db['usage'][$u] ?? ['s_c'=>0,'min_c'=>0,'h_c'=>0,'d_c'=>0,'m_c'=>0,'ban_until'=>0];
                        $lim = $db['user_limits'][$u] ?? $db['global_limits'] ?? $default_db['global_limits'];
                        $is_blocked = $usg['blk'] ?? false;
                        $rem_sec = max(0, intval($usg['ban_until'] - time()));
                        $time_str = ($is_blocked && $rem_sec > 0) ? format_remaining_time($rem_sec) : "";
                    ?>
                    <tr>
                        <td><strong>👤 <?php echo htmlspecialchars($u); ?></strong></td>
                        <td><?php echo $usg['s_c'] ?? 0; ?> / <?php echo $lim['secondly']; ?></td>
                        <td><?php echo $usg['min_c'] ?? 0; ?> / <?php echo $lim['minutely']; ?></td>
                        <td><?php echo $usg['h_c'] ?? 0; ?> / <?php echo $lim['hourly']; ?></td>
                        <td><?php echo $usg['d_c'] ?? 0; ?> / <?php echo $lim['daily']; ?></td>
                        <td><?php echo $usg['m_c'] ?? 0; ?> / <?php echo $lim['monthly']; ?></td>
                        <td><?php echo $lim['max_kb']; ?> KB</td>
                        <td>
                            <?php if ($is_blocked): ?>
                                <span class="badge bg-danger">PASİF <?php echo $time_str; ?></span>
                            <?php else: ?>
                                <span class="badge bg-success">AKTİF</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space: nowrap;">
                            <?php if ($is_blocked): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="reactivate"><input type="hidden" name="username" value="<?php echo htmlspecialchars($u); ?>">
                                    <button type="submit" class="btn-success">Aktifleştir</button>
                                </form>
                            <?php endif; ?>
                            <button onclick="openUserLimit('<?php echo htmlspecialchars($u); ?>')">Sınır</button>
                            <button onclick="editUser('<?php echo htmlspecialchars($u); ?>')" class="btn-warning">Şifre</button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Silinsin mi?');">
                                <input type="hidden" name="action" value="delete"><input type="hidden" name="username" value="<?php echo htmlspecialchars($u); ?>">
                                <button type="submit" class="btn-danger">Sil</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="col" style="flex: 1;">
    <div class="card">
        <h3>Genel Standart Sınırlar ve Cezalar</h3>
        <form method="POST">
            <input type="hidden" name="action" value="save_global_limits">
            <?php 
            $types = ['sec' => ['Saniyelik', 'secondly'], 'min' => ['Dakikalık', 'minutely'], 'hr' => ['Saatlik', 'hourly'], 'day' => ['Günlük', 'daily'], 'mo' => ['Aylık', 'monthly'], 'kb' => ['Maks KB', 'max_kb']];
            foreach($types as $k => $v): 
                $l_key = $v[1];
            ?>
            <div class="flex-row">
                <div><label><?php echo $v[0]; ?> Sınır:</label><input type="number" name="g_<?php echo $k; ?>" value="<?php echo $db['global_limits'][$l_key] ?? 0; ?>" required></div>
                <div><label>Ceza Süresi:</label><input type="number" name="g_<?php echo $k; ?>_bv" value="<?php echo $db['global_limits'][$l_key.'_ban_val'] ?? 1; ?>" required></div>
                <div style="max-width:65px;"><label>Birim:</label>
                    <select name="g_<?php echo $k; ?>_bu">
                        <?php foreach(['s'=>'Sn','m'=>'Dk','h'=>'St','d'=>'Gün'] as $uk => $uv): ?>
                            <option value="<?php echo $uk; ?>" <?php echo ($db['global_limits'][$l_key.'_ban_unit'] ?? 'm') === $uk ? 'selected' : ''; ?>><?php echo $uv; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endforeach; ?>
            <button type="submit" style="width:100%; margin-top:5px;">Sınırları ve Cezaları Güncelle</button>
        </form>
    </div>

    <div class="card" id="user-limit-card" style="display:none;">
        <h3 id="limit-title">Kullanıcı Sınırı Düzenle</h3>
        <form method="POST">
            <input type="hidden" name="action" value="save_user_limit"><input type="hidden" name="username" id="limit-username">
            <label>Sınır Tipi:</label>
            <select name="limit_type" id="limit_type" onchange="toggleLimitFields()">
                <option value="global">Genel Varsayılan</option><option value="custom">Özel Sınır Tanımla</option>
            </select>
            <div id="custom-limit-fields" style="display:none; margin-top:10px;">
                <?php foreach($types as $k => $v): ?>
                <div class="flex-row">
                    <div><label>Özel <?php echo $v[0]; ?>:</label><input type="number" name="u_<?php echo $k; ?>" id="u_<?php echo $k; ?>"></div>
                    <div><label>Ceza Süresi:</label><input type="number" name="u_<?php echo $k; ?>_bv" id="u_<?php echo $k; ?>_bv"></div>
                    <div style="max-width:65px;"><label>Birim:</label>
                        <select name="u_<?php echo $k; ?>_bu" id="u_<?php echo $k; ?>_bu">
                            <option value="s">Sn</option><option value="m">Dk</option><option value="h">St</option><option value="d">Gün</option>
                        </select>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" style="width:100%; margin-top:10px;">Uygula</button>
            <button type="button" class="btn-secondary" style="width:100%; margin-top:5px;" onclick="closeUserLimit()">Kapat</button>
        </form>
    </div>

    <div class="card">
        <h3 id="user-form-title">Yeni İstemci Ekle</h3>
        <form method="POST">
            <input type="hidden" name="action" value="save_user">
            <input type="text" name="username" id="form-username" placeholder="Kullanıcı Adı" required style="margin-bottom:8px;">
            <input type="password" name="password" id="form-password" placeholder="Şifre" required style="margin-bottom:8px;">
            <button type="submit" id="submit-user-btn" style="width:100%;">Kaydet</button>
            <button type="button" class="btn-danger" id="cancel-user-btn" style="display:none; width:100%; margin-top:5px;" onclick="resetUserForm()">İptal</button>
        </form>
    </div>
</div>
</div>

<script>
    var userLimits = <?php echo json_encode($db['user_limits'] ?? []); ?>;
    var globalLimits = <?php echo json_encode($db['global_limits'] ?? []); ?>;
    var fields = ['sec','min','hr','day','mo','kb'];
    var keys = ['secondly','minutely','hourly','daily','monthly','max_kb'];

    function openUserLimit(username) {
        document.getElementById('user-limit-card').style.display = 'block';
        document.getElementById('limit-title').innerText = "Sınır Ayarla: " + username;
        document.getElementById('limit-username').value = username;
        if (userLimits && userLimits[username]) {
            document.getElementById('limit_type').value = 'custom';
            for(var i=0; i<fields.length; i++) {
                document.getElementById('u_'+fields[i]).value = userLimits[username][keys[i]];
                document.getElementById('u_'+fields[i]+'_bv').value = userLimits[username][keys[i]+'_ban_val'] || 1;
                document.getElementById('u_'+fields[i]+'_bu').value = userLimits[username][keys[i]+'_ban_unit'] || 'm';
            }
        } else {
            document.getElementById('limit_type').value = 'global';
            for(var i=0; i<fields.length; i++) {
                document.getElementById('u_'+fields[i]).value = globalLimits[keys[i]];
                document.getElementById('u_'+fields[i]+'_bv').value = globalLimits[keys[i]+'_ban_val'] || 1;
                document.getElementById('u_'+fields[i]+'_bu').value = globalLimits[keys[i]+'_ban_unit'] || 'm';
            }
        }
        toggleLimitFields(); document.getElementById('user-limit-card').scrollIntoView();
    }
    function toggleLimitFields() { document.getElementById('custom-limit-fields').style.display = (document.getElementById('limit_type').value === 'custom') ? 'block' : 'none'; }
    function closeUserLimit() { document.getElementById('user-limit-card').style.display = 'none'; }
    function editUser(username) {
        document.getElementById('user-form-title').innerText = "Şifre Değiştir: " + username;
        document.getElementById('form-username').value = username; document.getElementById('form-username').readOnly = true;
        document.getElementById('form-password').placeholder = "Yeni Şifre"; document.getElementById('form-password').focus();
        document.getElementById('submit-user-btn').innerText = "Güncelle"; document.getElementById('cancel-user-btn').style.display = "block";
    }
    function resetUserForm() {
        document.getElementById('user-form-title').innerText = "Yeni İstemci Ekle";
        document.getElementById('form-username').value = ""; document.getElementById('form-username').readOnly = false;
        document.getElementById('form-password').placeholder = "Şifre"; document.getElementById('submit-user-btn').innerText = "Kaydet"; document.getElementById('cancel-user-btn').style.display = "none";
    }
</script>
</body></html>
