<?php

class MinecraftPing {
	
	private $Socket;
	private $ServerAddress;
	private $ServerPort;
	private $Timeout;
	
	public function __construct($Address, $Port = 25565, $Timeout = 2, $ResolveSRV = true) {
		$this->ServerAddress = $Address;
		$this->ServerPort = (int)$Port;
		$this->Timeout = (int)$Timeout;
		
		if ($ResolveSRV) {
			$this->ResolveSRV();
		}
		
		$this->Connect();
	}
	
	public function __destruct() {
		$this->Close();
	}
	
	public function Close() {
		if ($this->Socket !== null) {
			$this->Socket->close();
			$this->Socket = null;
		}
	}
	
	public function Connect() {
		$this->Socket = new \Co\Socket(AF_INET,SOCK_STREAM,6);//@fsockopen($this->ServerAddress, $this->ServerPort, $errno, $errstr, $connectTimeout);
		$res = $this->Socket->connect($this->ServerAddress,$this->ServerPort,$this->Timeout);
		if (!$res) {
			$errstr = socket_strerror($this->Socket->errCode);
			$msg = "Failed to connect or create a socket: {$this->Socket->errCode} ({$errstr})";
			$this->Socket = null;
			throw new Exception($msg);
		}
	}
	
	public function Query() {
		$TimeStart = microtime(true); // for read timeout purposes
		
		// See http://wiki.vg/Protocol (Status Ping)
		$Data = "\x00";               // packet ID = 0 (varint)
		
		$Data .= "\x04";                                                         // Protocol version (varint)
		$Data .= Pack('c', StrLen($this->ServerAddress)) . $this->ServerAddress; // Server (varint len + UTF-8 addr)
		$Data .= Pack('n', $this->ServerPort);                                   // Server port (unsigned short)
		$Data .= "\x01";                                                         // Next state: status (varint)
		
		$Data = Pack('c', StrLen($Data)) . $Data; // prepend length of packet ID + data
		
		$this->Socket->sendAll($Data,$this->Timeout); //fwrite($this->Socket, $Data);      // handshake
		$this->Socket->sendAll("\x01\x00",$this->Timeout); //fwrite($this->Socket, "\x01\x00"); // status ping
		
		$Length = $this->ReadVarInt(); // full packet length
		
		if ($Length < 10) {
			return FALSE;
		}
		
		$this->ReadVarInt(); // packet type, in server ping it's 0
		
		$Length = $this->ReadVarInt(); // string length
		
		$Data = "";
		do {
			if (microtime(true) - $TimeStart > $this->Timeout) {
				throw new Exception('Server read timed out');
			}
			
			$Remainder = $Length - StrLen($Data);
			$block = $this->Socket->recvAll($Remainder,$this->Timeout);//fread($this->Socket, $Remainder); // and finally the json string
			// abort if there is no progress
			if (!$block) {
				throw new Exception('Server returned too few data');
			}
			
			$Data .= $block;
		} while (StrLen($Data) < $Length);
		
		if ($Data === FALSE) {
			throw new Exception('Server didn\'t return any data');
		}
		$Data = JSON_Decode($Data, true);
		
		if (JSON_Last_Error() !== JSON_ERROR_NONE) {
			if (Function_Exists('json_last_error_msg')) {
				throw new Exception(JSON_Last_Error_Msg());
			} else {
				throw new Exception('JSON parsing failed');
			}
		}
		
		return $Data;
	}
	
	public function QueryOldPre17() {
		$this->Socket->sendAll("\xFE\x01",$this->Timeout);//fwrite($this->Socket, "\xFE\x01");
		$Data = $this->Socket->recvAll(512,$this->Timeout);//fread($this->Socket, 512);
		$Len = StrLen($Data);
		
		if ($Len < 4 || $Data[0] !== "\xFF") {
			return FALSE;
		}
		
		$Data = SubStr($Data, 3); // Strip packet header (kick message packet and short length)
		$Data = iconv('UTF-16BE', 'UTF-8', $Data);
		
		// Are we dealing with Minecraft 1.4+ server?
		if ($Data[1] === "\xA7" && $Data[2] === "\x31") {
			$Data = Explode("\x00", $Data);
			
			return array(
				'HostName' => $Data[3],
				'Players' => IntVal($Data[4]),
				'MaxPlayers' => IntVal($Data[5]),
				'Protocol' => IntVal($Data[1]),
				'Version' => $Data[2]
			);
		}
		
		$Data = Explode("\xA7", $Data);
		
		return array(
			'HostName' => SubStr($Data[0], 0, -1),
			'Players' => isset($Data[1]) ? IntVal($Data[1]) : 0,
			'MaxPlayers' => isset($Data[2]) ? IntVal($Data[2]) : 0,
			'Protocol' => 0,
			'Version' => '1.3'
		);
	}
	
	private function ReadVarInt() {
		$i = 0;
		$j = 0;
		
		while (true) {
			$k = $this->Socket->recv(1,$this->Timeout);//@fgetc($this->Socket);
			
			if ($k === FALSE) {
				return 0;
			}
			
			$k = Ord($k);
			
			$i |= ($k & 0x7F) << $j++ * 7;
			
			if ($j > 5) {
				throw new Exception('VarInt too big');
			}
			
			if (($k & 0x80) != 128) {
				break;
			}
		}
		
		return $i;
	}
	
	private function ResolveSRV() {
		if (ip2long($this->ServerAddress) !== false) {
			return;
		}
		
		$Record = @dns_get_record('_minecraft._tcp.' . $this->ServerAddress, DNS_SRV);
		
		if (empty($Record)) {
			return;
		}
		
		if (isset($Record[0]['target'])) {
			$this->ServerAddress = $Record[0]['target'];
		}
		
		if (isset($Record[0]['port'])) {
			$this->ServerPort = $Record[0]['port'];
		}
	}
}
