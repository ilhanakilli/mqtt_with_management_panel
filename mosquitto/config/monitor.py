import os, re, time, json, socket
from datetime import datetime

DB_FILE = "/config/traffic_db.json"
LOG_FILE = "/log/mosquitto.log"
USERS_FILE = "/config/users.txt"

client_to_user = {}

def load_db():
    try:
        with open(DB_FILE, "r") as f:
            db = json.load(f)
            if isinstance(db.get("usage"), list): db["usage"] = {}
            if isinstance(db.get("user_limits"), list): db["user_limits"] = {}
            return db
    except:
        return {"global_limits": {"secondly": 10, "secondly_ban_val": 1, "secondly_ban_unit": "m", "minutely": 100, "minutely_ban_val": 5, "minutely_ban_unit": "m", "hourly": 1000, "hourly_ban_val": 1, "hourly_ban_unit": "h", "daily": 10000, "daily_ban_val": 1, "daily_ban_unit": "d", "monthly": 100000, "monthly_ban_val": 30, "monthly_ban_unit": "d", "max_kb": 50, "max_kb_ban_val": 5, "max_kb_ban_unit": "m"}, "user_limits": {}, "usage": {}, "mqtt_users": {}}

def save_db(db):
    try:
        with open(DB_FILE, "w") as f: json.dump(db, f, indent=4)
        os.chmod(DB_FILE, 0o666)
    except: pass

def reload_mosquitto():
    try:
        s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        s.connect("/var/run/docker.sock")
        s.sendall(b"POST /containers/mqtt-broker/kill?signal=1 HTTP/1.0\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n")
        s.recv(1024)
        s.close()
    except: pass

def sync_users_file(db):
    users_in_db = db.get("mqtt_users", {})
    if not users_in_db and os.path.exists(USERS_FILE):
        with open(USERS_FILE, "r") as f:
            for line in f:
                line = line.strip()
                if not line: continue
                is_blk = line.startswith("#BLOCKED#")
                clean = line.replace("#BLOCKED#", "")
                if ":" in clean:
                    u, h = clean.split(":", 1)
                    users_in_db[u] = h
                    if is_blk: db.setdefault("usage", {}).setdefault(u, {})["blk"] = True
        db["mqtt_users"] = users_in_db
        save_db(db)

    new_lines = []
    for u, h in users_in_db.items():
        is_blk = db.get("usage", {}).get(u, {}).get("blk", False)
        prefix = "#BLOCKED#" if is_blk else ""
        new_lines.append(f"{prefix}{u}:{h}\n")
    
    new_content = "".join(new_lines)
    current_content = ""
    if os.path.exists(USERS_FILE):
        with open(USERS_FILE, "r") as f: current_content = f.read()
        
    if current_content != new_content:
        with open(USERS_FILE, "w") as f: f.write(new_content)
        try: os.chmod(USERS_FILE, 0o664)
        except: pass
        reload_mosquitto()

def get_ban_seconds(val, unit):
    mult = {"s": 1, "m": 60, "h": 3600, "d": 86400}
    return int(val) * mult.get(unit, 60)

def check_reset(db, u, size_bytes=0):
    now = time.time()
    dt_now = datetime.now()
    sec, mnt, hr, dy, mo = dt_now.strftime("%S"), dt_now.strftime("%M"), dt_now.strftime("%H"), dt_now.strftime("%d"), dt_now.strftime("%b")
    
    ud = db["usage"].setdefault(u, {"ls": sec, "lmin": mnt, "lh": hr, "ld": dy, "lm": mo, "s_c": 0, "min_c": 0, "h_c": 0, "d_c": 0, "m_c": 0, "blk": False, "ban_until": 0})
    
    if ud.get("blk", False) and ud.get("ban_until", 0) > 0:
        if now >= ud["ban_until"]:
            ud["blk"] = False
            ud["ban_until"] = 0
            ud["s_c"] = 0
            ud["min_c"] = 0
            
    if ud.get("ls") != sec: ud["s_c"] = 0; ud["ls"] = sec
    if ud.get("lmin") != mnt: ud["min_c"] = 0; ud["lmin"] = mnt
    if ud.get("lh") != hr: ud["h_c"] = 0; ud["lh"] = hr
    if ud.get("ld") != dy: ud["d_c"] = 0; ud["ld"] = dy
    if ud.get("lm") != mo: ud["m_c"] = 0; ud["lm"] = mo
    
    if ud.get("blk", False): return

    lim = db["user_limits"].get(u, db["global_limits"])
    triggered = None
    
    if size_bytes > 0 and (size_bytes > lim.get("max_kb", 50) * 1024): triggered = "max_kb"
    elif ud["s_c"] >= lim.get("secondly", 10): triggered = "secondly"
    elif ud["min_c"] >= lim.get("minutely", 100): triggered = "minutely"
    elif ud["h_c"] >= lim.get("hourly", 1000): triggered = "hourly"
    elif ud["d_c"] >= lim.get("daily", 10000): triggered = "daily"
    elif ud["m_c"] >= lim.get("monthly", 100000): triggered = "monthly"
    
    if triggered:
        ud["blk"] = True
        val = lim.get(f"{triggered}_ban_val", 1)
        unit = lim.get(f"{triggered}_ban_unit", "m")
        ud["ban_until"] = now + get_ban_seconds(val, unit)

conn_rx = re.compile(r"as ([^\s]+) \(.*?u'([^']+)'\)")
pub_rx = re.compile(r"PUBLISH (?:from|to) ([^\s]+) .*?\((\d+) bytes\)")

while not os.path.exists(LOG_FILE): time.sleep(1)

with open(LOG_FILE, "r") as f:
    for line in f:
        mc = conn_rx.search(line)
        if mc: client_to_user[mc.group(1)] = mc.group(2)

db = load_db()
sync_users_file(db)

with open(LOG_FILE, "r") as f:
    f.seek(0, os.SEEK_END)
    while True:
        line = f.readline()
        if not line:
            db = load_db()
            for u in list(db.get("mqtt_users", {}).keys()): check_reset(db, u)
            sync_users_file(db)
            time.sleep(0.2)
            continue
            
        mc = conn_rx.search(line)
        if mc: client_to_user[mc.group(1)] = mc.group(2)
        
        mp = pub_rx.search(line)
        if mp:
            cid, b = mp.group(1), int(mp.group(2))
            user = client_to_user.get(cid, cid)
            
            db = load_db()
            if user in db.get("mqtt_users", {}):
                ud = db["usage"].setdefault(user, {"ls": "", "lmin": "", "lh": "", "ld": "", "lm": "", "s_c": 0, "min_c": 0, "h_c": 0, "d_c": 0, "m_c": 0, "blk": False, "ban_until": 0})
                ud["s_c"] += 1; ud["min_c"] += 1; ud["h_c"] += 1; ud["d_c"] += 1; ud["m_c"] += 1
                check_reset(db, user, b)
                sync_users_file(db)
                save_db(db)
