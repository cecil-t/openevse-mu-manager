<?php
/******************************************************************************
 * sessions_lib.php - charging-session database (sessions.db) shared by the
 * collector (CLI, every minute) and the report pages.
 *
 * Why a separate sessions.db instead of new tables in openevse.db:
 *  - the collector writes every minute; openevse.db is the config DB that every
 *    page opens in rollback-journal mode. Keeping
 *    the writes elsewhere means no lock contention and no change to that file
 *    (switching it to WAL would change how the existing code uses it).
 *  - sessions.db runs in WAL mode (readers never block the writer), and can be
 *    pruned, vacuumed, backed up or deleted on its own.
 *  - it sits next to openevse.db, so the site's *.db download block
 *    (.htaccess) covers it and its -wal/-shm files too.
 * A future load coordinator can open both files (ATTACH) if it needs to.
 ******************************************************************************/

if (!defined('SESS_DB_PATH')) define('SESS_DB_PATH', __DIR__ . '/sessions.db');
require_once __DIR__ . '/volts_lib.php';
define('SESS_SCHEMA_VERSION', 6);

function sess_db($readonly = false){
	$db = new PDO('sqlite:' . SESS_DB_PATH, null, null, array(
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_TIMEOUT => 10,
	));
	$db->exec('PRAGMA busy_timeout = 10000');
	if (!$readonly) {
		if (strtolower((string)$db->query('PRAGMA journal_mode')->fetchColumn()) !== 'wal') $db->exec('PRAGMA journal_mode = WAL');
		$db->exec('PRAGMA synchronous = NORMAL');
		$v = 0;
		try { $v = (int)$db->query("SELECT v FROM meta WHERE k = 'schema_version'")->fetchColumn(); } catch (Throwable $e) {}
		if ($v < SESS_SCHEMA_VERSION || !sess_cols_ok($db)) sess_schema($db);
	}
	return $db;
}

function sess_schema($db){
	$db->exec("CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)");
	// collector's per-unit memory between runs
	$db->exec("CREATE TABLE IF NOT EXISTS unit_state (
		unit_id INTEGER PRIMARY KEY,
		unit_name TEXT,
		api TEXT, version TEXT,
		last_poll_at INTEGER, last_ok_at INTEGER,
		lifetime_wh REAL, lifetime_at INTEGER,          -- last accepted lifetime counter
		pending_wh REAL, pending_count INTEGER DEFAULT 0, -- rebaseline candidate
		connected INTEGER, state INTEGER, charging INTEGER, vflags INTEGER,
		session_id INTEGER, segment_id INTEGER,          -- open session / charging segment
		volts INTEGER,
		pilot INTEGER, cmax INTEGER, evt_amps REAL, temp_c REAL,
		gfci_count INTEGER, nognd_count INTEGER, stuck_count INTEGER,
		last_sample_at INTEGER,
		fail_count INTEGER DEFAULT 0, last_error TEXT)");
	// one row per plug-in
	$db->exec("CREATE TABLE IF NOT EXISTS sessions (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		unit_id INTEGER NOT NULL, unit_name TEXT,
		plug_in_at INTEGER,
		charge_start_at INTEGER,     -- first charge start
		charge_end_at INTEGER,       -- last charge stop
		unplug_at INTEGER,
		charge_seconds INTEGER DEFAULT 0,
		segments INTEGER DEFAULT 0,
		lifetime_start_wh REAL, lifetime_end_wh REAL,
		kwh REAL,                    -- lifetime-counter delta (preferred)
		kwh_meter REAL,              -- unit's own session meter, cross-check
		kwh_source TEXT,
		peak_amps REAL, avg_amps REAL, peak_kw REAL, avg_kw REAL,   -- avg = while actually charging
		amp_seconds REAL DEFAULT 0,
		max_temp_c REAL,
		pilot_start INTEGER, pilot_min INTEGER, pilot_max INTEGER,
		pilot_amps INTEGER,          -- last pilot while charging
		volts INTEGER, volts_assumed INTEGER DEFAULT 1,   -- no voltage sensor: 120/240 from service level
		limit_type TEXT, limit_value REAL,                -- time (min) / energy (kWh) / soc / range
		timer_active INTEGER DEFAULT 0,
		faults INTEGER DEFAULT 0,
		end_reason TEXT,
		last_state INTEGER,
		partial INTEGER DEFAULT 0,   -- 1 = already plugged in when logging started / data gap
		notes TEXT,
		open INTEGER DEFAULT 1)");
	$db->exec("CREATE INDEX IF NOT EXISTS sessions_unit_time ON sessions(unit_id, plug_in_at)");
	$db->exec("CREATE INDEX IF NOT EXISTS sessions_time ON sessions(plug_in_at)");
	// charging segments: each start/stop inside one plug-in
	$db->exec("CREATE TABLE IF NOT EXISTS segments (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		session_id INTEGER NOT NULL, unit_id INTEGER NOT NULL,
		start_at INTEGER, stop_at INTEGER, seconds INTEGER DEFAULT 0,
		lifetime_start_wh REAL, lifetime_end_wh REAL, kwh REAL,
		amp_seconds REAL DEFAULT 0, avg_amps REAL, peak_amps REAL,
		stop_reason TEXT,   -- car paused/full, schedule, limit reached, user sleep/override, fault, thermal, load management, unplugged, unknown
		open INTEGER DEFAULT 1)");
	$db->exec("CREATE INDEX IF NOT EXISTS segments_session ON segments(session_id, start_at)");
	// timeline: current/pilot changes, faults, thermal, limits
	$db->exec("CREATE TABLE IF NOT EXISTS events (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		unit_id INTEGER NOT NULL, unit_name TEXT, session_id INTEGER, segment_id INTEGER,
		ts INTEGER NOT NULL,
		kind TEXT,          -- pilot, amps, fault, fault_count, thermal, limit, timer
		detail TEXT, from_val REAL, to_val REAL, temp_c REAL,
		reason TEXT)");
	$db->exec("CREATE INDEX IF NOT EXISTS events_session ON events(session_id, ts)");
	$db->exec("CREATE INDEX IF NOT EXISTS events_unit_ts ON events(unit_id, ts)");
	$db->exec("CREATE TABLE IF NOT EXISTS samples (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		unit_id INTEGER NOT NULL, session_id INTEGER, ts INTEGER NOT NULL,
		state INTEGER, connected INTEGER, amps REAL, pilot INTEGER, kw REAL,
		temp_c REAL, lifetime_wh REAL, session_wh REAL,
		res INTEGER DEFAULT 60)      -- seconds this row represents (downsampled rows > 60)");
	$db->exec("CREATE INDEX IF NOT EXISTS samples_session ON samples(session_id, ts)");
	$db->exec("CREATE INDEX IF NOT EXISTS samples_unit_ts ON samples(unit_id, ts)");
	// ---- v3: Emoncms-style push from the 2.x WiFi modules, temperatures, reachability
	$db->exec("CREATE TABLE IF NOT EXISTS emon_nodes (
		node TEXT PRIMARY KEY, unit_id INTEGER NOT NULL,
		key_hash TEXT,               -- sha256 of the key set on the unit (key itself never stored)
		created_at INTEGER)");
	sess_add_cols($db, 'unit_state', array(
		'temp1_c'=>'REAL', 'temp2_c'=>'REAL', 'temp3_c'=>'REAL',
		'last_push_at'=>'INTEGER', 'push_count'=>'INTEGER DEFAULT 0', 'push_ip'=>'TEXT', 'push_freeram'=>'INTEGER',
		'push_state'=>'INTEGER',                 // state as last pushed (2.8.2 sends sleeping as 0)
		'last_full_poll_at'=>'INTEGER',          // last RAPI/HTTP poll of the unit
		'reach'=>'INTEGER',                      // 1 = answering, 0 = stopped answering (event logged)
		'reach_since'=>'INTEGER', 'push_reach'=>'INTEGER', 'push_reach_since'=>'INTEGER',
		'poll_due'=>'INTEGER DEFAULT 0',         // a push showed a change: pushes wait for a full poll
		'poll_due_at'=>'INTEGER', 'poll_kick_at'=>'INTEGER',
		// reboot detection: v5 /status uptime (s); 2.x has none, but /status comm_sent
		// (RAPI commands since the WiFi module booted) restarts from 0 on a reboot
		'wifi_uptime'=>'INTEGER', 'comm_sent'=>'INTEGER', 'boot_at'=>'INTEGER', 'boot_exact'=>'INTEGER',
		'level'=>'INTEGER', 'volt_set'=>'INTEGER'));   // service level 1/2, controller voltage setting (V)
	sess_add_cols($db, 'samples', array('temp1_c'=>'REAL', 'temp2_c'=>'REAL', 'temp3_c'=>'REAL', 'src'=>'TEXT',
		'volts'=>'INTEGER'));   // voltage kW is computed with (none of these units measures power)
	sess_add_cols($db, 'sessions', array('avg_temp_c'=>'REAL', 'temp_sum'=>'REAL DEFAULT 0', 'temp_n'=>'INTEGER DEFAULT 0', 'pushes'=>'INTEGER DEFAULT 0'));
	sess_add_cols($db, 'segments', array('max_temp_c'=>'REAL', 'avg_temp_c'=>'REAL', 'temp_sum'=>'REAL DEFAULT 0', 'temp_n'=>'INTEGER DEFAULT 0'));
	$db->exec("CREATE INDEX IF NOT EXISTS events_kind_ts ON events(kind, ts)");
	// ---- v5: extra fields only WiFi v4/v5 sends (push and /status); NULL on 2.x units
	$v5 = array('srssi'=>'INTEGER',          // WiFi RSSI dBm
		'freeram'=>'INTEGER', 'status'=>'TEXT', 'flags'=>'INTEGER', 'vehicle'=>'INTEGER',
		'max_current'=>'INTEGER',            // current limit in effect (claims/overrides applied)
		'temp4_c'=>'REAL',                   // ESP-side MCP9808, if fitted
		'temp_max_c'=>'REAL',                // unit's own max temp since plug-in
		'session_elapsed'=>'INTEGER');       // unit's own session seconds
	sess_add_cols($db, 'samples', $v5 + array('uptime'=>'INTEGER'));
	sess_add_cols($db, 'unit_state', $v5);
	sess_add_cols($db, 'sessions', array('unit_elapsed'=>'INTEGER', 'unit_temp_max_c'=>'REAL'));
	sess_add_cols($db, 'events', array('srssi'=>'INTEGER'));   // last known WiFi RSSI when the event was logged
	// ---- v6: every push stored; raw pushed state; arrival time of the push that first flagged plug-in / unplug
	sess_add_cols($db, 'samples', array('push_state'=>'INTEGER'));
	sess_add_cols($db, 'sessions', array('first_push_at'=>'INTEGER', 'unplug_push_at'=>'INTEGER'));
	// ---- v4: kW is no longer stored (derived = amps x volts, computed at report time).
	// One-off: give old samples their voltage (from the stored kW, else the unit's),
	// then clear the stored kW / avg / peak kW so nothing reads stale derived values.
	$old = (int)$db->query("SELECT coalesce((SELECT v FROM meta WHERE k = 'schema_version'), 0)")->fetchColumn();
	if ($old > 0 && $old < 4) {
		$db->exec("UPDATE samples SET volts = CASE WHEN amps > 0.5 AND kw > 0 THEN (CASE WHEN kw * 1000 / amps < 180 THEN 120 ELSE 240 END)
			ELSE (SELECT u.volts FROM unit_state u WHERE u.unit_id = samples.unit_id) END WHERE volts IS NULL");
		$db->exec("UPDATE samples SET kw = NULL");
		$db->exec("UPDATE sessions SET avg_kw = NULL, peak_kw = NULL");
	}
	// v5 one-off: v5's pushed 'voltage' was a 240 V default while idle; forget it
	if ($old > 0 && $old < 5) $db->exec("UPDATE unit_state SET volt_set = NULL WHERE api = 'http'");
	$db->exec("INSERT OR IGNORE INTO meta (k, v) VALUES ('schema_version', '" . SESS_SCHEMA_VERSION . "')");
	$db->exec("UPDATE meta SET v = '" . SESS_SCHEMA_VERSION . "' WHERE k = 'schema_version' AND CAST(v AS INTEGER) < " . SESS_SCHEMA_VERSION);
	$db->exec("INSERT OR IGNORE INTO meta (k, v) VALUES ('created_at', '" . time() . "')");
}

// Write transactions start IMMEDIATE: the collector, the push receiver and its
// follow-up polls write concurrently, and a deferred (read-first) transaction would
// fail with SQLITE_BUSY at its first write instead of waiting on busy_timeout.
function sess_begin($db){
	$t0 = microtime(true);
	for ($i = 1; ; $i++) {
		try { $db->exec('BEGIN IMMEDIATE'); break; }
		catch (PDOException $e) {
			if ($i >= 4 || stripos($e->getMessage(), 'locked') === false) throw $e;
			if (function_exists('slog')) slog('warn', 'sessions.db busy for ' . round(microtime(true) - $t0, 1) . " s, retry $i");
			usleep(250000 * $i);
		}
	}
}
function sess_commit($db){ $db->exec('COMMIT'); }
function sess_rollback($db){ if ($db->inTransaction()) $db->exec('ROLLBACK'); }

// v3 rows present (schema_version was bumped before poll_due/poll_kick_at existed)
function sess_cols_ok($db){
	$ok = 0;
	foreach ($db->query("PRAGMA table_info(unit_state)") as $c) if ($c['name'] == 'temp_max_c') $ok++;
	foreach ($db->query("PRAGMA table_info(sessions)") as $c) if ($c['name'] == 'first_push_at') $ok++;
	return $ok == 2;
}

// additive migration: ADD COLUMN for any column a table doesn't have yet
function sess_add_cols($db, $table, $cols){
	$have = array();
	foreach ($db->query("PRAGMA table_info($table)") as $c) $have[$c['name']] = 1;
	foreach ($cols as $name => $type) if (!isset($have[$name])) $db->exec("ALTER TABLE $table ADD COLUMN $name $type");
}

// Voltage for kW: the controller's voltage setting if it fits the service level
// (L1 ~120, L2 ~240), otherwise the level's nominal voltage. Nothing here is measured.
// volts themselves come from evse_volts() in volts_lib.php (one rule for the site)
function sess_kw($amps, $volts){ return ($amps === null) ? null : round((float)$amps * ((int)$volts ?: EVSE_VOLTS_L2) / 1000, 2); }
// session averages computed from stored amps / kWh / time
function sess_session_kw($s){
	$v = (int)$s['volts'] ?: EVSE_VOLTS_L2;
	$peak = $s['peak_amps'] !== null ? sess_kw($s['peak_amps'], $v) : null;
	$avg = ((int)$s['charge_seconds'] > 0 && $s['kwh'] !== null) ? round((float)$s['kwh'] / ((int)$s['charge_seconds'] / 3600), 2) : null;
	if ($avg !== null && $peak && $avg > $peak) $avg = $peak;   // very short sessions: 1 reading can't time them exactly
	return array('avg'=>$avg, 'peak'=>$peak);
}

function sess_fmt_dur($sec){
	$sec = (int)$sec;
	if ($sec < 60) return $sec . 's';
	$h = intdiv($sec, 3600); $m = intdiv($sec % 3600, 60);
	if ($h >= 24) return intdiv($h, 24) . 'd ' . ($h % 24) . 'h';
	return $h ? sprintf('%dh %02dm', $h, $m) : $m . 'm';
}
