<?php
/******************************************************************************
 * util_lib.php - small helpers shared by the pages and the collector scripts:
 *  h()          HTML escape
 *  ago()        "5 min ago" from a timestamp (Settings lists)
 *  oev_sess_ro() read-only connection to sessions.db (the collector's data)
 * Included with require_once; safe to load more than once.
 ******************************************************************************/
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

// timestamp -> 'just now' / '5 min ago' / '3 h ago' / date; 0 or null -> 'never'
function ago($t){ if (!$t) return 'never'; $d = time() - (int)$t; if ($d < 120) return 'just now'; if ($d < 7200) return round($d / 60) . ' min ago'; if ($d < 172800) return round($d / 3600) . ' h ago'; return date('n/j/Y', (int)$t); }

// sessions.db opened read-only (pages never write it; the collector and the receiver do, via sess_db()).
// Throws on failure: callers wrap it in try/catch.
function oev_sess_ro($timeout = 2, $dir = null){
	return new PDO('sqlite:' . ($dir ?? __DIR__) . '/sessions.db', null, null, array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY, PDO::ATTR_TIMEOUT => $timeout, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
}
