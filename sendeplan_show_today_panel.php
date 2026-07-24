<?php	
/*-------------------------------------------------------+
| PHP-Fusion Content Management System
| Copyright (C) PHP-Fusion Inc
| https://www.phpfusion.com/
+--------------------------------------------------------+
| Filename: sendeplan_shows-today_panel.php
| Author: Andre Krell (Systemweb.de)
| Licensed under Commercial Core License (CCL)
| Resale or redistribution not allowed.
+--------------------------------------------------------*/
if (!defined("IN_FUSION")) { die("Access Denied"); }

// added for compatibility to PHP-Fusion v7
if (!defined('SENDEPLAN_EXISTS')) {
	$result = dbquery("SHOW TABLES LIKE '" . DB_PREFIX . "sendeplan'");
	if (dbrows($result) == 1) {
		define('SENDEPLAN_EXISTS', TRUE);
	}
}

if(defined('SENDEPLAN_EXISTS')) {
include(__DIR__ ."/infusion_db.php");
include(__DIR__ ."/sw_functions.php");

$sp_setting_panel = @dbarray(dbquery("SELECT * FROM `".DB_SCHEDULER_SETTINGS."` WHERE `profile` = 'default'"));

if(function_exists('add_to_head')) {
	add_to_head("
	<style type=\"text/css\" media=\"screen\">
	.spshowtitle {
		font-family: ".$sp_setting_panel['font_showtitle'].";
		font-size: ".$sp_setting_panel['fontsize_showtitle']."px;
		font-weight: ".($sp_setting_panel['bold_showtitle']==true ? "bold" : "normal").";
	}
	.spshowdescription {
		font-family: ".$sp_setting_panel['font_showdescription'].";
		font-size: ".$sp_setting_panel['fontsize_showdescription']."px;
		font-weight: ".($sp_setting_panel['bold_showdescription']==true ? "bold" : "normal").";
	}
	</style>
	");
}

// Check if locale file is available matching the current site locale setting.
if (file_exists(INFUSIONS.$sp_folder."/locale/".LOCALESET."index.php")) {
	// Load the locale file matching the current site locale setting.
	include INFUSIONS.$sp_folder."/locale/".LOCALESET."index.php";
} else {
	// Load the infusion's default locale file.
	include INFUSIONS.$sp_folder."/locale/English/index.php";
}

if (defined('FUSION_V7')) {
	$siteurl = $settings['siteurl'];
	$timeoffset = $settings['default_timezone'];
}
elseif (defined('FUSION_V8')) {
	$siteurl = fusion_get_settings('siteurl');
	$timeoffset = fusion_get_settings('default_timezone');
}
else {
	$siteurl = fusion_get_settings('siteurl');
	$timeoffset = fusion_get_settings('timeoffset');
}
date_default_timezone_set($timeoffset);

// convert current timestamp using global offset settings
$timestamp = time();
// IMPORTTANT: generate dateformat in gmt for db tables (without MESZ changes!)
$requestday = gmmktime(12,0,0,date("m",$timestamp),date("d",$timestamp),date("Y",$timestamp));

$newday = $requestday;
if($sp_setting_panel['default_view']==1 || $sp_setting_panel['default_view']==3) {
	$tmp_offset_panel=(date("w")-1);
}
else $tmp_offset_panel = 0;

if (!defined('SP_JS_LOADED')) {
	add_to_footer("<script src=\"https://www.systemweb.de/supportfiles/sendeplan_v3/sp_tooltip.js\"></script>");
	define('SP_JS_LOADED', true);
}

unset($showstart_panel,$showend_panel,$showid_panel,$djid_panel);
$current_show_panel = "";

openside("Heutige Sendungen");
// request available entries and store them in array
$result = @dbquery("SELECT * FROM `".DB_SCHEDULER_PLANNED."` WHERE `day` = ".$newday);
$rows = @dbrows($result);
if ($rows != 0) {
	$entry_panel = @dbarray($result);
	$showstart_panel = array();
	$showend_panel = array();
	$showid_panel = array();
	$djid_panel = array();
	$current_show_panel = "-";
	for($i=0; $i<=23; $i++) {
		if($current_show_panel=="OFF") { $current_show_panel="-"; }
		if($entry_panel[$i]=="" && $sp_setting_panel['autodj_hidden']=="2" && (($sp_setting_panel['entry_starttime']<$sp_setting_panel['entry_endtime'] && $i<$sp_setting_panel['entry_starttime'] && $i<$sp_setting_panel['entry_endtime']) || ($sp_setting_panel['entry_starttime']>$sp_setting_panel['entry_endtime'] && $i>=$sp_setting_panel['entry_endtime'] && $i<$sp_setting_panel['entry_starttime']) || ($i>$sp_setting_panel['entry_starttime'] && $i>=$sp_setting_panel['entry_endtime'] && $sp_setting_panel['entry_starttime']<$sp_setting_panel['entry_endtime']))) {
			$current_show_panel = "OFF";
			$djid_panel[] = 0;
			$showid_panel[] = '';
			$showstart_panel[] = $i;
			$showend_panel[] = $i;
		}
		elseif(($i==0 && $entry_panel[$i]=="" && $current_show_panel!="OFF") || ($current_show_panel!=$entry_panel[$i] && $entry_panel[$i]=="") && $sp_setting_panel['autodj_hidden']=='0') {
			// no entry = autostream
			$djid_panel[] = 0;
			$showid_panel[] = 1;
			$current_show_panel = "";
			$showstart_panel[] = $i;
			$showend_panel[] = $i;
		}
		elseif($entry_panel[$i]!=$current_show_panel) { 	
			if($entry_panel[$i]!="") {
				$showdata_panel = explode(".",$entry_panel[$i]);
				$djid_panel[] = $showdata_panel[0];
				$showid_panel[] = $showdata_panel[1];
				$current_show_panel = $entry_panel[$i];
				$showstart_panel[] = $i;
				$showend_panel[] = $i;						
			}
			elseif($current_show_panel!="OFF") {
				// no entry = autostream
				$djid_panel[] = 0;
				$showid_panel[] = 1;
				$current_show_panel = "";
				$showstart_panel[] = $i;
				$showend_panel[] = $i;
			}
		}					
	}
	$showstart_panel[] = 0;
	if(count($showstart_panel)>1 || $sp_setting_panel['autodj_hidden']!=1) {
		if(count($showstart_panel)==1) { $showstart_panel[1] = '0'; $djid_panel[] = 0; $showid_panel[] = 1; }
		for($i=0; $i<(count($showstart_panel)-1); $i++) {						
			if($djid_panel[$i]!=0 || ($djid_panel[$i]==0 && $sp_setting_panel['autodj_hidden']!=1) && $showid_panel[$i]!='' ) {
				echo ($i>0 ? "\n<div style='float:left; width: 97%; height: auto; text-align: left; border: 0; border-top: 0px; top: 0: left: 0; margin: 0;'><hr></div>\n" : "");
				$result = @dbquery("SELECT * FROM `".DB_SCHEDULER_SHOWS."` WHERE `ID` = '".$showid_panel[$i]."'");
				$rows = @dbrows($result);
				if ($rows != 0) {
					$show_panel = @dbarray($result);
					$result2 = @dbquery("SELECT * FROM `".DB_SCHEDULER_SHOWDATA."` WHERE `ID` = '".$show_panel['showid']."'");
					$rows = @dbrows($result2);
					if ($rows != 0) {
						$showdata_panel = @dbarray($result2); 
						echo "<table width='100%' border='0' align='left' cellpadding='0' cellspacing='0' style='background-color: transparent; border: 0px; padding: 0px;'><tr><td style='text-align: left; background-color: transparent;'><strong>".($showstart_panel[$i]<10 ? "0" : "").$showstart_panel[$i].":00".($sp_setting_panel['view_endtime']=="1" ? "-".($showstart_panel[$i+1]<10 ? "0" : "").$showstart_panel[$i+1].":00" : "")."</strong></td>".(date("H",$timestamp)>=$showstart_panel[$i] && (date("H",$timestamp)<$showstart_panel[$i+1] || $showstart_panel[$i+1]=='0') ? "<td width='1'><a name='onair' title='Now OnAir!'><img src='".INFUSIONS.$sp_folder."/images/onair.gif' border='0' width='50' height='12' alt='now on air'></a></td>" : "").($sp_setting_panel['use_nsv']==true && $show_panel['is_nsv']==true ? "<td width='1' style='padding-left: 3px;'><img src='".INFUSIONS.$sp_folder."/images/camera_on.png' width='25' height='25' border='0' alt='Cam on!' title='Sendung wird per Video &uuml;bertragen'></td>" : "").($sp_setting_panel['use_nsv']==true && $show_panel['is_nsv']==false ? "<td width='1' style='padding-left: 3px;'><img src='".INFUSIONS.$sp_folder."/images/camera_off.png' width='25' height='25' border='0' alt='Cam off!' title='Sendung wird NICHT per Video &uuml;bertragen'></td>" : "")."</tr></table>
						<table width='100%' border='0' align='left' cellpadding='0' cellspacing='0' style='background-color: transparent; border: 0px; padding: 0px;'>
						<tr>";
						$result3 = @dbquery("SELECT * FROM `".DB_USERS."` WHERE `user_id` = '".$djid_panel[$i]."'");
						$rows = @dbrows($result3);
						if ($rows != 0) { $djdata_panel = @dbarray($result3); }
						else {
							$djdata_panel['user_name'] = $sp_setting_panel['autodj_name'];
							$djdata_panel['user_id'] = 0;
						}									
						$result4 = @dbquery("SELECT * FROM `".DB_SCHEDULER_DJINFOS."` WHERE `user_id` = '".$djid_panel[$i]."'");
						$rows = @dbrows($result4);
						if ($rows != 0) { $djinfo_panel = @dbarray($result4); }
						if(IsSet($djdata_panel) && (IsSet($djinfo_panel) || $djdata_panel['user_id']==0)) {
							echo "<td style='text-align: center; width:".$djavatars_maxwidth."px; padding: 2px;'>".
							($djavatars_visible ? "<img src='".INFUSIONS.$sp_folder."/images/dj-avatars/".($djinfo_panel['user_avatar'] !="" ? $djinfo_panel['user_avatar'] : "nopic.png")."' alt='' border='0' style='max-width: ".$djavatars_maxwidth."px; max-height: ".$djavatars_maxheight."px;'>" : "")."
							</td>
							";
						}
						echo "<td style='text-align: center;'>
						<span onmouseover='Tip(\"<div style=padding:10px;width:".($sp_setting_panel['showbox_width']-10)."px;height:".($sp_setting_panel['showbox_height']-10)."px;background-image:url(".INFUSIONS.$sp_folder."/images/shows/".$showdata_panel['image'].");background-repeat:no-repeat;background-position:center;background-color:transparent;color:".$showdata_panel['font-color'].";><div style=margin:0px;padding:5px;padding-top:3px;".($showdata_panel['textbg']!="" && file_exists(INFUSIONS.$sp_folder."/images/bg_trans/".$showdata_panel['textbg']) ? "background-image:url(".INFUSIONS.$sp_folder."/images/bg_trans/".$showdata_panel['textbg'].");" : "")."border:0px;height:".($sp_setting_panel['showbox_height']-30)."px;overflow:hidden;><div align=".$sp_setting_panel['align_showtitle']." class=spshowtitle style=color:".$showdata_panel['font-color'].";><table width=100% border=0 align=left cellpadding=2 cellspacing=0 class=spshowtitle  style=background-color:transparent;border:0;><tr><td>".($showstart_panel[$i]<10 ? "0" : "").$showstart_panel[$i].":00".($sp_setting_panel['view_endtime']=="1" ? "-".($showstart_panel[$i+1]<10 ? "0" : "").$showstart_panel[$i+1].":00" : "")."</td>".($sp_setting_panel['use_nsv']==true && $show_panel['is_nsv']==false ? "<td width=1><img src=".$sp_panel_install_folder."images/camera_off.png width=25 height=25 border=0 alt=Cam_off!></td>" : "").($sp_setting_panel['use_nsv']==true && $show_panel['is_nsv']==true ? "<td width=1><img src=".INFUSIONS.$sp_folder."/images/camera_on.png width=25 height=25 border=0 alt=Cam_on!></td>" : "")."</tr></table>".string2js($show_panel['title'])."</div><div align=".$sp_setting_panel['align_showdescription']." class=spshowdescription style=color:".$showdata_panel['font-color'].";>".str_replace("\r\n", "<br>", nl2br(string2js($show_panel['description'])))."</div></div></div>\");'><strong>".$show_panel['title']."</strong></span><br>
						<span><small>mit</small> ".(IsSet($djdata_panel['user_id']) && $djdata_panel['user_id']==0 ? $djdata_panel['user_name'] : "<a class='side' href='".BASEDIR."profile.php?lookup=".$djdata_panel['user_id']."' title='Profil anzeigen'>".$djdata_panel['user_name']."</a>")."</span>
						</td></tr>
						</table>
						";
					}
					
				}
			}
		}
	}				
}

if($sp_setting_panel['autodj_hidden']==1 && count($showstart_panel)==2 && $djdata_panel['user_name'] = $sp_setting_panel['autodj_name']) { echo "<center><em>".$splocale['no_planned_shows_found']."</em></center>"; }
?>
<div style='float:left; width: 97%; height: auto; text-align: left; border: 0; border-top: 0px; top: 0: left: 0; margin: 0;'><hr></div>
<center><a class='side' href='<?php echo (defined('FUSION_V7') ? $settings['siteurl'] : fusion_get_settings('siteurl'));?>infusions/sendeplan/sendeplan.php' title='Details'>Sendeplan</a></center>
<?php
closeside();
}
