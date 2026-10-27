<?php
/******************************************************************************
 * Minimal MQTT 3.1.1 client (QoS 0 publish/subscribe, keep-alive, last will).
 * No extensions needed - plain PHP streams. Enough for the load-balance
 * coordinator: it only listens to unit status and publishes its own status.
 ******************************************************************************/
class LbMqtt {
	public $connected = false;
	private $s = null, $buf = '', $lastOut = 0, $lastIn = 0, $pid = 1;
	public function __construct(private string $host, private int $port, private string $cid,
		private string $user, private string $pass, private int $keep = 30, private ?array $will = null) {}

	private static function str($s){ return pack('n', strlen($s)) . $s; }
	private static function len($n){ $o = ''; do { $d = $n % 128; $n = intdiv($n, 128); if ($n > 0) $d |= 0x80; $o .= chr($d); } while ($n > 0); return $o; }
	private function send($h, $body){
		if (!$this->s) return false;
		$p = chr($h) . self::len(strlen($body)) . $body; $off = 0; $t0 = microtime(true);
		while ($off < strlen($p)) {
			$w = @fwrite($this->s, substr($p, $off));
			if ($w === false) { $this->close(); return false; }
			$off += $w;
			if ($w === 0) { if (microtime(true) - $t0 > 5) { $this->close(); return false; } usleep(2000); }
		}
		$this->lastOut = time();
		return true;
	}
	public function close(){ if ($this->s) @fclose($this->s); $this->s = null; $this->connected = false; $this->buf = ''; }

	public function connect(){
		$this->close();
		$this->s = @stream_socket_client("tcp://{$this->host}:{$this->port}", $en, $es, 5);
		if (!$this->s) return false;
		stream_set_timeout($this->s, 5);
		$flags = 0x02 | ($this->user !== '' ? 0x80 : 0) | ($this->pass !== '' ? 0x40 : 0);
		$pl = self::str($this->cid);
		if ($this->will) { $flags |= 0x04 | (!empty($this->will['retain']) ? 0x20 : 0); $pl .= self::str($this->will['topic']) . self::str($this->will['message']); }
		if ($this->user !== '') $pl .= self::str($this->user);
		if ($this->pass !== '') $pl .= self::str($this->pass);
		if (!$this->send(0x10, self::str('MQTT') . chr(4) . chr($flags) . pack('n', $this->keep) . $pl)) return false;
		$t0 = time();
		while (time() - $t0 < 6) {
			$r = [$this->s]; $w = $e = null;
			if (@stream_select($r, $w, $e, 1) > 0) { $d = @fread($this->s, 4096); if ($d === '' || $d === false) break; $this->buf .= $d; }
			if (($p = $this->packet()) !== null) {
				if (($p[0] >> 4) == 2 && strlen($p[1]) >= 2 && ord($p[1][1]) === 0) {
					stream_set_blocking($this->s, false);
					$this->connected = true; $this->lastIn = time();
					return true;
				}
				break;
			}
		}
		$this->close();
		return false;
	}
	public function subscribe(array $topics){
		$pl = pack('n', $this->pid++ & 0xffff ?: 1);
		foreach ($topics as $t) $pl .= self::str($t) . chr(0);
		return $this->send(0x82, $pl);
	}
	public function publish($topic, $msg, $retain = false){ return $this->send(0x30 | ($retain ? 1 : 0), self::str($topic) . $msg); }

	// next complete packet from the buffer: [header byte, body] or null
	private function packet(){
		if (strlen($this->buf) < 2) return null;
		$mul = 1; $len = 0; $i = 1;
		do {
			if ($i >= strlen($this->buf)) return null;
			$d = ord($this->buf[$i++]); $len += ($d & 127) * $mul; $mul *= 128;
			if ($mul > 128 * 128 * 128 * 128) { $this->close(); return null; }
		} while ($d & 128);
		if (strlen($this->buf) < $i + $len) return null;
		$p = [ord($this->buf[0]), substr($this->buf, $i, $len)];
		$this->buf = substr($this->buf, $i + $len);
		return $p;
	}
	// Wait up to $timeout s for traffic; returns [[topic, payload], ...]. Keeps the connection alive.
	public function loop($timeout = 1.0){
		$out = [];
		if (!$this->s) return $out;
		$r = [$this->s]; $w = $e = null;
		$n = @stream_select($r, $w, $e, (int)$timeout, (int)(($timeout - (int)$timeout) * 1e6));
		if ($n > 0) {
			$d = @fread($this->s, 65536);
			if ($d === '' || $d === false) { if (feof($this->s)) { $this->close(); return $out; } }
			else { $this->buf .= $d; $this->lastIn = time(); }
		}
		while (($p = $this->packet()) !== null) {
			$type = $p[0] >> 4;
			if ($type == 3) {   // PUBLISH
				$qos = ($p[0] >> 1) & 3; $b = $p[1];
				$tl = unpack('n', substr($b, 0, 2))[1]; $topic = substr($b, 2, $tl); $o = 2 + $tl;
				if ($qos > 0) { $id = substr($b, $o, 2); $o += 2; if ($qos == 1) $this->send(0x40, $id); }
				$out[] = [$topic, substr($b, $o)];
			}
		}
		if ($this->s && time() - $this->lastOut >= intdiv($this->keep, 2)) $this->send(0xC0, '');   // PINGREQ
		if ($this->s && time() - $this->lastIn > $this->keep * 2) $this->close();                    // broker gone quiet
		return $out;
	}
}
