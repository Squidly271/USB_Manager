<?php
/*  Copyright 2021, Simon Fairweather
 *  
 * 
 * based on original code from Guilherme Jardim and Dan Landon
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

$plugin = "usb_manager";
/* $VERBOSE=TRUE; */

$paths = [  "device_log"		=> "/tmp/{$plugin}/",
			"config_file"		=> "/tmp/{$plugin}/config/{$plugin}.cfg",
			"hdd_temp"			=> "/var/state/{$plugin}/hdd_temp.json",
			"run_status"		=> "/var/state/{$plugin}/run_status.json",
			"ping_status"		=> "/var/state/{$plugin}/ping_status.json",
			"hotplug_status"	=> "/var/state/{$plugin}/hotplug_status.json",
			"remote_usbip"		=> "/tmp/{$plugin}/config/remote_usbip.cfg",
			"vm_mappings"		=> "/tmp/{$plugin}/config/vm_mappings.cfg",
			"usb_rmt_connect"	=> "/tmp/{$plugin}/config/usb_rmt_connect.cfg",
			"usb_state"			=> "/usr/local/emhttp/state/usb.ini",
			"state"				=> "/var/state/{$plugin}/{$plugin}.state",
		];

$docroot = $docroot ?: @$_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$disks = @parse_ini_file("$docroot/state/disks.ini", true);

#########################################################
#############        MISC FUNCTIONS        ##############
#########################################################



function is_ip($str) {
	return filter_var($str, FILTER_VALIDATE_IP);
}

function _echo($m) { echo "<pre>".print_r($m,TRUE)."</pre>";}; 

function save_ini_file($file, $array) {
	global $plugin;

	$res = array();
	foreach($array as $key => $val) {
		if(is_array($val)) {
			$res[] = PHP_EOL."[$key]";
			foreach($val as $skey => $sval) $res[] = "$skey = ".(is_numeric($sval) ? $sval : '"'.$sval.'"');
		} else {
			$res[] = "$key = ".(is_numeric($val) ? $val : '"'.$val.'"');
		}
	}

	/* Write changes to tmp file. */
	file_put_contents($file, implode(PHP_EOL, $res));

	/* Write changes to flash. */
	$file_path = pathinfo($file);
	if ($file_path['extension'] == "cfg") {
		file_put_contents("/boot/config/plugins/".$plugin."/".basename($file), implode(PHP_EOL, $res));
	}
}

function usb_manager_log($m, $type = "NOTICE") {
	global $plugin;

	if ($type == "DEBUG" && ! $GLOBALS["VERBOSE"]) return NULL;
	$m		= print_r($m,true);
	$m		= str_replace("\n", " ", $m);
	$m		= str_replace('"', "'", $m);
	$cmd	= "/usr/bin/logger ".'"'.$m.'"'." -t".$plugin;
	exec($cmd);
}

function listDir($root) {
	$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, 
			RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST,
			RecursiveIteratorIterator::CATCH_GET_CHILD);
	$paths = array();
	foreach ($iter as $path => $fileinfo) {
		if (! $fileinfo->isDir()) $paths[] = $path;
	}
	return $paths;
}

function safe_name($string, $convert_spaces=TRUE) {
	$string = stripcslashes($string);
	/* Convert single and double quote to underscore */
	$string = str_replace( array("'",'"', "?"), "_", $string);
	if ($convert_spaces) {
		$string = str_replace(" " , "_", $string);
	}
	$string = htmlentities($string, ENT_QUOTES, 'UTF-8');
	$string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');
	$string = preg_replace('/[^A-Za-z0-9\-_] /', '', $string);
	return trim($string);
}

function exist_in_file($file, $val) {
	return (preg_grep("%{$val}%", @file($file))) ? TRUE : FALSE;
}


function is_usbip_server_online($ip, $mounted, $background=TRUE) {
	global $paths, $plugin;

	$is_alive = FALSE;
	$server = $ip;
	$tc = $paths['ping_status'];
	$ping_status = is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();
	if (isset($ping_status[$server])) {
		$is_alive = ($ping_status[$server]['online'] == 'yes') ? TRUE : FALSE;
	}
	if ((time() - $ping_status[$server]['timestamp']) > 15 ) {
		$bk = $background ? "&" : "";
		exec("/usr/local/emhttp/plugins/{$plugin}/scripts/get_ud_stats ping {$tc} {$ip} {$mounted} $bk");
	}

	return $is_alive;
}


function timed_exec($timeout=10, $cmd) {
	$time		= -microtime(true); 
	$out		= shell_exec("/usr/bin/timeout ".$timeout." ".$cmd);
	$time		+= microtime(true);
	if ($time >= $timeout) {
		usb_manager_log("Error: shell_exec(".$cmd.") took longer than ".sprintf('%d', $timeout)."s!");
		$out	= "command timed out";
	} else {
		usb_manager_log("Timed Exec: shell_exec(".$cmd.") took ".sprintf('%f', $time)."s!", "DEBUG");
	}
	return $out;
}

function save_usbstate($source, $var, $val) {
	$config_file = $GLOBALS["paths"]["usb_state"];
	$config = @parse_ini_file($config_file, true);
	$config[$source][$var] = $val;
	save_ini_file($config_file, $config);
	return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
}


function remove_usbstate($source) {
	$config_file = $GLOBALS["paths"]["usb_state"];
	$config = @parse_ini_file($config_file, true);
	if ( isset($config[$source]) ) {
		#usb_manager_log("Removing configuration '$source'.");
	}
			
	unset($config[$source]);
	save_ini_file($config_file, $config);
	return (! isset($config[$source])) ? TRUE : FALSE;
}

function get_usbstate($source, $var) {
	$config_file = $GLOBALS["paths"]["usb_state"];
	$config = @parse_ini_file($config_file, true);
	return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
}

function load_usbstate() {
	global $usb_state ;
	$config_file = $GLOBALS["paths"]["usb_state"];
	$usb_state = @parse_ini_file($config_file, true);
}

#########################################################
############        CONFIG FUNCTIONS        #############
#########################################################

function get_config($sn, $var) {
	$config_file = $GLOBALS["paths"]["config_file"];
	$config = @parse_ini_file($config_file, true);
	return (isset($config[$sn][$var])) ? html_entity_decode($config[$sn][$var]) : FALSE;
}

function set_config($sn, $var, $val) {
	$config_file = $GLOBALS["paths"]["config_file"];
	$config = @parse_ini_file($config_file, true);
	$config[$sn][$var] = htmlentities($val, ENT_COMPAT);
	save_ini_file($config_file, $config);
	return (isset($config[$sn][$var])) ? $config[$sn][$var] : FALSE;
}

function is_automount($sn, $usb=FALSE) {
	$auto = get_config($sn, "automount");
	$auto_usb = get_config("Config", "automount_usb");
	$pass_through = get_config($sn, "pass_through");
	return ( ($pass_through != "yes" && $auto == "yes") || ( $usb && $auto_usb == "yes" ) ) ? TRUE : FALSE;
}

function get_vm_config($sn, $var) {
	$config_file = $GLOBALS["paths"]["vm_mappings"];
	$config = @parse_ini_file($config_file, true);
	return (isset($config[$sn][$var])) ? html_entity_decode($config[$sn][$var]) : FALSE;
}

function load_vm_mappings() {
	$config_file = $GLOBALS["paths"]["vm_mappings"];
	$config = @parse_ini_file($config_file, true);
	return (isset($config)) ? $config : array();
}

function load_usb_connects() {
	$config_file = $GLOBALS["paths"]["usb_rmt_connect"];
	$config = @parse_ini_file($config_file, true);
	return (isset($config)) ? $config : array();
}

function is_autoconnectstart($sn) {
	$auto = get_vm_config($sn, "autoconnectstart");
	return ( $auto == "yes")  ? TRUE : FALSE;
}

function is_autoconnect($sn) {
	$auto = get_vm_config($sn, "autoconnect");
	return ( $auto == "yes")  ? TRUE : FALSE;
}

function updatevm($sn, $vmname) {
	$config_file = $GLOBALS["paths"]["vm_mappings"];
	$config = @parse_ini_file($config_file, true);
	$config[$sn]["VM"] = $vmname ;
	save_ini_file($config_file, $config);
	return ($config[$sn]["VM"] ) ;
}

function remove_vm_mapping($source) {
	$config_file = $GLOBALS["paths"]["vm_mappings"];;
	$config = @parse_ini_file($config_file, true);
	if ( isset($config[$source]) ) {
		usb_manager_log("Removing configuration '$source'.");
	}	
	unset($config[$source]);
	save_ini_file($config_file, $config);
	return (! isset($config[$source])) ? TRUE : FALSE;
	}

function toggle_autoconnectstart($sn, $status) {
	$config_file = $GLOBALS["paths"]["vm_mappings"];
	$config = @parse_ini_file($config_file, true);
	$config[$sn]["autoconnectstart"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$sn]["autoconnectstart"] == "yes") ? 'true' : 'false';
}

function toggle_autoconnect($sn, $status) {
	$config_file = $GLOBALS["paths"]["vm_mappings"];
	$config = @parse_ini_file($config_file, true);
	$config[$sn]["autoconnect"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$sn]["autoconnect"] == "yes") ? 'true' : 'false';
}


function is_read_only($sn) {
	$read_only = get_config($sn, "read_only");
	$pass_through = get_config($sn, "pass_through");
	return ( $pass_through != "yes" && $read_only == "yes" ) ? TRUE : FALSE;
}

function is_pass_through($sn) {
	return (get_config($sn, "pass_through") == "yes") ? TRUE : FALSE;
}

function toggle_automount($sn, $status) {
	$config_file = $GLOBALS["paths"]["config_file"];
	$config = @parse_ini_file($config_file, true);
	$config[$sn]["automount"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$sn]["automount"] == "yes") ? 'true' : 'false';
}

function toggle_read_only($sn, $status) {
	$config_file = $GLOBALS["paths"]["config_file"];
	$config = @parse_ini_file($config_file, true);
	$config[$sn]["read_only"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$sn]["read_only"] == "yes") ? 'true' : 'false';
}

function toggle_pass_through($sn, $status) {
	$config_file = $GLOBALS["paths"]["config_file"];
	$config = @parse_ini_file($config_file, true);
	$config[$sn]["pass_through"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	@touch($GLOBALS['paths']['reload']);
	return ($config[$sn]["pass_through"] == "yes") ? 'true' : 'false';
}




#########################################################
############        REMOTE HOST             #############
#########################################################



function get_remote_usbip() {
	global $paths;

	$o = array();
	$config_file = $paths['remote_usbip'];
	$remote_usbip = @parse_ini_file($config_file, true);
	
	if (is_array($remote_usbip)) {
		$o = $remote_usbip  ;
		
	} else {
		usb_manager_log("Error: unable to get the remote usbip hosts.");
	}
	return $o;
}

function set_remote_host_config($source, $var, $val) {
	$config_file = $GLOBALS["paths"]["remote_usbip"];
	$config = @parse_ini_file($config_file, true);
	$config[$source][$var] = $val;
	save_ini_file($config_file, $config);
	return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
}


function remove_config_remote_host($source) {
	$config_file = $GLOBALS["paths"]["remote_usbip"];
	$config = @parse_ini_file($config_file, true);
	if ( isset($config[$source]) ) {
		usb_manager_log("Removing configuration '$source'.");
	}
			
	unset($config[$source]);
	save_ini_file($config_file, $config);
	return (! isset($config[$source])) ? TRUE : FALSE;
}




#########################################################
############         USBIP FUNCTIONS        #############
#########################################################


/* Check modules loaded and commands exist. */

function check_usbip_modules() {
	global $loaded_usbip_host, $loaded_vhci_hcd, $usbip_cmds_exist, $exists_vhci_hcd, $exists_usbip_host, $usbip_enabled, $plugin ;

	exec("ls /usr/local/sbin/. | grep -c usbip*", $usbip_cmds_exist_array) ;
	$usbip_cmds_exist = $usbip_cmds_exist_array[0] ;
	
	exec("cat /proc/modules | grep -c vhci_hcd", $loaded_vhci_hcd_array) ;
	$loaded_vhci_hcd = $loaded_vhci_hcd_array[0] ;
	
	exec("cat /proc/modules | grep -c usbip_host", $loaded_usbip_host_array) ;
	$loaded_usbip_host = $loaded_usbip_host_array[0] ;

	exec("find /lib/modules/ | grep -c usbip-host ", $exists_usbip_host_array) ;
	$exists_usbip_host = $exists_usbip_host_array[0] ;

	exec("find /lib/modules/ | grep -c vhci-hcd ", $exists_vhci_hcd_array) ;
	$exists_vhci_hcd = $exists_vhci_hcd_array[0] ;

	$config_file = "/tmp/$plugin/config/$plugin.cfg";  
	$cfg = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
	$usbip_enabled=$cfg["Config"]["USBIP"] ;

}



function get_Valid_USB_Devices() {
	global $cacheUSBDevices, $usbip_enabled ,$usbip_cmds_exist;

	#if (!is_null($cacheValidUSBDevices)) {
	#	return $cacheValidUSBDevices;
	#}

	$arrValidUSBDevices = [];

	$usbip_local = array() ;
	if ($usbip_enabled == "enabled" && $usbip_cmds_exist) {
			exec('usbip list -pl | sort'  ,$usbiplocal) ;
	        
			foreach ($usbiplocal as $usbip) {
	 		$usbipdetail=explode('#', $usbip) ;
	 		$usbip_local[]=substr($usbipdetail[0] , 6) ;
			} 
	}
	

#busid=3-1#usbid=0781:5571#
#busid=3-2#usbid=1c4f:0016#
#busid=3-5.1#usbid=cd12:ef18#
#busid=3-5.4#usbid=0204:6025#
#busid=3-6#usbid=054c:05bf#
#busid=3-7.1#usbid=0557:2419#

#1D6Bp0002
#8087p8008
#1D6Bp0002
#8087p8000
#1D6Bp0002
#05E3p0606
#0557p7000
#1D6Bp0003
#1D6Bp0002
#1D6Bp0003

	// Get a list of all usb hubs so we can blacklist them
	exec("cat /sys/bus/usb/drivers/hub/*/modalias | grep -Po 'usb:v\K\w{9}' | tr 'p' ':'", $arrAllUSBHubs);

	exec("lsusb 2>/dev/null", $arrAllUSBDevices);

	foreach ($arrAllUSBDevices as $strUSBDevice) {
		#if (preg_match('/^.+: ID (?P<id>\S+)(?P<name>.*)$/', $strUSBDevice, $arrMatch)) {
			if (preg_match('/^Bus (?P<bus>\S+) Device (?P<dev>\S+): ID (?P<id>\S+)(?P<name>.*)$/', $strUSBDevice, $arrMatch)) {
			#if (stripos($GLOBALS['var']['flashGUID'], str_replace(':', '-', $arrMatch['id'])) === 0) {
			#	// Device id matches the unraid boot device, skip device
			#	continue;
				
			#}
			$ishub="interface" ;
			if (in_array(strtoupper($arrMatch['id']), $arrAllUSBHubs)) {
				// Device class is a Hub, skip device
				#continue;
				$ishub='hub' ;
			}



			$arrMatch['name'] = trim($arrMatch['name']);

			if (empty($arrMatch['name'])) {
				// Device name is blank, attempt to lookup usb details
				exec("lsusb -d ".$arrMatch['id']." -v 2>/dev/null | grep -Po '^\s+(iManufacturer|iProduct)\s+[1-9]+ \K[^\\n]+'", $arrAltName);
				$arrMatch['name'] = trim(implode(' ', (array)$arrAltName));

				if (empty($arrMatch['name'])) {
					// Still blank, replace using fallback default
					$arrMatch['name'] = '[unnamed device]';
				}
			}

			// Clean up the name
#			$arrMatch['name'] = sanitizeVendor($arrMatch['name']);
            $udev=array();
			#udevadm info -a   --name=/dev/bus/usb/003/002 | grep KERNEL==
			$udevcmd = "udevadm info -a   --name=/dev/bus/usb/".$arrMatch['bus']."/".$arrMatch['dev']." | grep KERNEL==" ;
			exec( $udevcmd , $udev);
		
			$physical_busid = trim(substr($udev[0], 13) , '"') ;
			if (substr($physical_busid,0,3) =='usb') {
				#		$physical_busid = substr($physical_busid,3).'-0' ;
						$ishub='roothub' ;
		
					}

			if (in_array($physical_busid, $usbip_local )) {
				$islocal = true ;
			} else { $islocal = false ;}

			$arrValidUSBDevices[$physical_busid] = [
			#	'physical_busid' => $len, 
				'cmd' => $udevcmd ,
				'udev' => $udev ,
				'busid' => $arrMatch['bus'],
				'devid' =>$arrMatch['dev'],
				'id' => $arrMatch['id'],
				'name' => $arrMatch['name'],
				'islocal' => $islocal ,
				'ishub' => $ishub 
			];
		}
	}

	#uasort($arrValidUSBDevices, function ($a, $b) {
	#	return strcasecmp($a['id'], $b['id']);
	#});
ksort($arrValidUSBDevices) ;
	#$cacheUSBDevices = $arrValidUSBDevices;

	return $arrValidUSBDevices;
}



function get_usbip_devs() {
	global $disks, $state;

	$ud_disks = $paths = $unraid_disks = $b =  array();
	/* Get all devices by id. */
	
	$flash=&$disks['flash'] ;
	$flash_udev=array() ;
	exec('udevadm info --query=property  -n /dev/'.$flash["device"], $fudev) ;
	foreach ($fudev as $udevi)
	{
		$udevisplit=explode("=",$udevi) ;
		$flash_udev[$udevisplit[0]] = $udevisplit[1] ;
	}
	
	
	#exec('usbip list -pl | sort'  ,$usbiplocal) ;
	$usbiplocal = get_Valid_USB_Devices() ;
	#var_dump($usbiplocal) ;
	/* Build USB Device Array */
	foreach ($usbiplocal as $realbusid => $detail) {
	#	$usbipdetail=explode('#', $usbip) ;
	#	$busid=substr($usbipdetail[0] , 6) ;
	$busid = $realbusid ;	

	if ($detail["ishub"] == "roothub") $busid = substr($realbusid,3).'-0' ;

	if (file_exists("/sys/bus/usb/devices/".$busid."/usbip_status")) { 
		$usbip_status=file_get_contents("/sys/bus/usb/devices/".$realbusid."/usbip_status") ;

		$tj[$busid]["usbip_status"] = $usbip_status ;
}
		/* Build array from udevadm */
		/* udevadm info --query=property -x --path=/sys/bus/usb/devices/ + busid */
        $udev=array();
		exec('udevadm info --query=property  --path=/sys/bus/usb/devices/'.$realbusid, $udev) ;
		
		foreach ($udev as $udevi)
		{
			$udevisplit=explode("=",$udevi) ;
			$tj[$busid][$udevisplit[0]] = $udevisplit[1] ;
		}

		$tj[$busid]["islocal"] = $detail["islocal"] ;
		$tj[$busid]["ishub"] = $detail["ishub"] ;
		
		$flash_check= $tj[$busid];
		if ($flash_check["ID_SERIAL_SHORT"] == $flash_udev["ID_SERIAL_SHORT"]) {
			$tj[$busid]["isflash"] = true ;
		}
		else { 
			$tj[$busid]["isflash"] = false ;
		}

		if ($detail["ishub"] == "roothub" || $detail["ishub"] == "hub" ) {
			$hubmaxchild = shell_exec ('cat /sys/bus/usb/devices/'.$realbusid.'/maxchild' ) ;
			$hubspeed = shell_exec ('cat /sys/bus/usb/devices/'.$realbusid.'/speed' ) ;
			$hubbmaxpower = shell_exec ('cat /sys/bus/usb/devices/'.$realbusid.'/bMaxPower' ) ;

			$tj[$busid]["ID_VENDOR_FROM_DATABASE"] = "Ports=".$hubmaxchild." Speed=".$hubspeed." Power=".$hubbmaxpower ;
			$tj[$busid]["ID_MODEL"] = "" ;
			$tj[$busid]["maxchildren"] = $hubmaxchild ;
			if ($detail["ishub"] == "roothub" )	$tj[$busid]["level"] = 0 ;
			
		/*
        devclass=`cat bDeviceClass`
        devsubclass=`cat bDeviceSubClass`
        devprotocol=`cat bDeviceProtocol`
        maxps0=`cat bMaxPacketSize0`
        numconfigs=`cat bNumConfigurations`
		maxpower=`cat bMaxPower`
        classname=`class_decode $devclass`
        printf "D:  Ver=%5s Cls=%s(%s) Sub=%s Prot=%s MxPS=%2i #Cfgs=%3i\n" \
                $ver $devclass "$classname" $devsubclass $devprotocol \
                $maxps0 $numconfigs */
		}

	}
	ksort($tj) ;
	return $tj ;
}

function get_all_usb_info($bus="all") {

	usb_manager_log("Starting get_all_usb_info.", "DEBUG");
	$time = -microtime(true);
	$usb_devs = get_usbip_devs();
	if (!is_array($usb_devs)) {
		$usb_devs = array();
	}
	usb_manager_log("Total time: ".($time + microtime(true))."s!", "DEBUG");

	return $usb_devs;
}

function get_vm_state($vm_name)
{
	global $lv ;
	if (!isset($lv))  return "Error State" ;
	$res = $lv->get_domain_by_name($vm_name);
	$dom = $lv->domain_get_info($res);
	$state = $lv->domain_state_translate($dom['state']);
    return $state ;
}


function parse_usbip_port()
{
	exec('usbip port', $cmd_return) ;

	$port_number = 0 ;
    $ports=array() ;
	foreach ($cmd_return as $line) {
		if ($line == "Imported USB devices") continue ;
		if ($line == "====================" ) continue ;
		if ($line == NULL) continue ;
		
		if (substr($line,0,4) == "Port") $port_num = substr($line, 5 ,2) ;
		$ports[$port_num][]=$line ;

	}
	
	return $ports ;
}
function parse_usbip_remote($remote_host)
{
	$usbip_cmd_list="usbip list -r ".$remote_host ;
	$cmd_return ="" ;
	$error=exec($usbip_cmd_list.' 2>&1', $cmd_return, $return) ;
	$count=0 ;
	$remotes=array() ;


	
	if ($return  || $error != "") {
		if ($error == false) {
			$error_type="USBIP command not found";
		} else {
			$error_type=$error;
		}
		$remotes[$remote_host]["NONE"]["detail"][] = "Connection Error" ;
		$remotes[$remote_host]["NONE"]["vendor"]=$error_type;
	
		$remotes[$remote_host]["NONE"]["product"]="";
		$remotes[$remote_host]["NONE"]["command"]=$error;
		$remotes[$remote_host]["NONE"]["return"]=$return;	
		$remotes[$remote_host]["NONE"]["cmdreturn"]=$cmd_return;
		$remotes[$remote_host]["NONE"]["error"]=$error;
	}

	foreach ($cmd_return as $line) {
		if ($line == "Exportable USB devices") continue ;
		if ($line == "======================" ) continue ;
		if ($line == NULL) {$count=2;continue ;}

		if (substr($line, 0, 12) == "usbip: error")  $remote[$remote_host]["NONE"] = $line ;

		if (substr($line, 1, 1) == '-') { 
			$usbip_ip= substr($line, 3) ;
			$count=1 ;

		}

		if ($count==2)
		       { 
				   $extract=explode(":", $line) ;
				   $busid=$extract[0] ;	
				   
				   $remotes[$usbip_ip][$busid]["vendor"]=$extract[1];
				   $remotes[$usbip_ip][$busid]["product"]=$extract[2].$extract[3];
			   }
		if 	   ($count>2) $remotes[$usbip_ip][$busid]["detail"][] = $line ;
		$count=$count+1 ;
	}
		
	return $remotes ;
}

#########################################################
############         VM FUNCTIONS        #############
#########################################################

function vm_map_action($vm, $action)
{
			
		    $explode= explode(";",$vm );
			$vmname = $explode[0] ;
			$bus = $explode[1] ;
			$dev = $explode[2] ;
			$srlnbr= $explode[3] ;
			if (isset($explode[4])) $method=$explode[4] ; else $method="" ;
			if (isset($explode[5])) $map=$explode[5] ; else $map="" ;
			
			$usbstr = '';

			
	#		if ($action != "none") {
			$return=virsh_device_by_bus($action,$vmname, $bus, $dev) ;
			#var_dump($return) ;
			#error: Failed to attach device from

			if (substr($return,0,6) === "error:") {
				save_usbstate($srlnbr, "virsherror" , true) ;
			} else {
		    	save_usbstate($srlnbr, "virsherror" , false) ;
			}
			
	#		if (substr($return,0,6) != "error:") {
			if ($action == "attach") {
					save_usbstate($srlnbr, "connected" , true) ;
				} else {
					save_usbstate($srlnbr, "connected" , false) ;
					$vmname =""  ;
				}
				save_usbstate($srlnbr, "VM" , $vmname) ;	
	#		}	else {  USBMgrCreateStatusEntry($srlnbr, $bus, $dev) ;}
	#		}
			save_usbstate($srlnbr, "virsh" , $return) ;
			save_usbstate($srlnbr, "connectmethod" , $method) ;
			save_usbstate($srlnbr, "connectmap" , $map) ;

	#		save_usbstate($srlnbr, "bus" , $bus) ;
	#		save_usbstate($srlnbr, "dev" , $dev) ;
			echo json_encode(["status" => $return ]);
}

function USBMgrResetConnectedStatus()
{
	$config_file = $GLOBALS["paths"]["usb_state"];
	$config = @parse_ini_file($config_file, true);

	foreach ($config as  $key => $state)
	{
		$config[$key]["connected"] = false ;
	}
	
	save_ini_file($config_file, $config);
	
}

function USBMgrCreateStatusEntry($serial, $bus , $dev)
{
	$USBDevices = get_usbip_devs() ;
	$config_file = $GLOBALS["paths"]["usb_state"];
	$config = @parse_ini_file($config_file, true);


	// Get a list of all usb hubs so we can blacklist them
	exec("cat /sys/bus/usb/drivers/hub/*/modalias | grep -Po 'usb:v\K\w{9}' | tr 'p' ':'", $arrAllUSBHubs);



	$udev=array();
	#udevadm info -a   --name=/dev/bus/usb/003/002 | grep KERNEL==
	$udevcmd = "udevadm info -a   --name=/dev/bus/usb/".$bus."/".$dev." | grep KERNEL==" ;
	exec( $udevcmd , $udev);
	$physical_busid = trim(substr($udev[0], 13) , '"') ;
 
	$device = $USBDevices[$physical_busid] ;

	
	$id = strtolower($device["ID_VENDOR_ID"]).":".$device["ID_MODEL_ID"] ;
	
	#var_dump($device) ;
	#if (in_array(strtoupper($id), $arrAllUSBHubs)) {
		if (!isset($device)) {
		// Device class is a Hub, skip device
		$config[$serial]["ishub"] = true ;
		$udev=array();
		#$device = array() ;
		exec('udevadm info --query=property  --path=/sys/bus/usb/devices/'.$physical_busid, $udev) ;
		
		foreach ($udev as $udevi)
		{
			$udevisplit=explode("=",$udevi) ;
			$device[$udevisplit[0]] = $udevisplit[1] ;
		}
        
	} else {$config[$serial]["ishub"] = false ; }

	if (!$device["isflash"]) {
    
	$config[$serial]["connected"] = false ;
	$config[$serial]["bus"] = $device["BUSNUM"] ;
	$config[$serial]["dev"] = $device["DEVNUM"] ;
	$config[$serial]["ID_VENDOR_FROM_DATABASE"] = $device["ID_VENDOR_FROM_DATABASE"] ;
	$config[$serial]["ID_VENDOR_ID"] = $device["ID_VENDOR_ID"] ;
	$config[$serial]["ID_MODEL"] = $device["ID_MODEL"] ;
	$config[$serial]["ID_MODEL_ID"] = $device["ID_MODEL_ID"] ;

	save_ini_file($config_file, $config);
	}

}


function USBMgrBuildConnectedStatus()
{
	$USBDevices = get_usbip_devs() ;
	
	$config_file = $GLOBALS["paths"]["usb_state"];
	$config = @parse_ini_file($config_file, true);

	foreach ($USBDevices as  $key => $device)
	{
        if ($device["isflash"]) continue ;
		$config[$device["ID_SERIAL"]]["connected"] = false ;
		$config[$device["ID_SERIAL"]]["bus"] = $device["BUSNUM"] ;
		$config[$device["ID_SERIAL"]]["dev"] = $device["DEVNUM"] ;
		$config[$device["ID_SERIAL"]]["ID_VENDOR_FROM_DATABASE"] = $device["ID_VENDOR_FROM_DATABASE"] ;
		$config[$device["ID_SERIAL"]]["ID_VENDOR_ID"] = $device["ID_VENDOR_ID"] ;
		$config[$device["ID_SERIAL"]]["ID_MODEL"] = $device["ID_MODEL"] ;
		$config[$device["ID_SERIAL"]]["ID_MODEL_ID"] = $device["ID_MODEL_ID"] ;
	}

	save_ini_file($config_file, $config);
	
}

#########################################################
############         VIRSH FUNCTIONS        #############
#########################################################

function virsh_device_by_bus($action, $vmname, $usbbus, $usbdev)
{
	$usbstr = '';
	if (!empty($usbbus)) 
	{
		$usbbus=ltrim($usbbus, "0");
		$usbdev=ltrim($usbdev, "0") ;
		$usbstr .= "<hostdev mode='subsystem' type='usb'>
	<source>
	<address bus='${usbbus}' device='${usbdev}' />
	</source>
	</hostdev>";
	}
	$filename = '/tmp/libvirthotplugusbbybus'.$vmname.'.xml';
	file_put_contents($filename,$usbstr);
	
	$cmdreturn=shell_exec("/usr/sbin/virsh $action-device '$vmname' '".$filename."' 2>&1");
	unlink($filename) ;
return $cmdreturn ;
#return shell_exec("/usr/sbin/virsh $action-device '$vmname' '".$filename."' 2>&1");


#echo "Running virsh ${COMMAND} ${DOMAIN} for USB bus=${BUSNUM} device=${DEVNUM}:" >&2
#virsh "${COMMAND}" "${DOMAIN}" /dev/stdin <<END
#<hostdev mode='subsystem' type='usb'>
#  <source>
#    <address bus='${BUSNUM}' device='${DEVNUM}' />
#  </source>
#</hostdev>
#END
}
?>