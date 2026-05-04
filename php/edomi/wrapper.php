<?php

$argv[1] = 9910;

require(dirname(__FILE__)."/../../main/include/php/incl_lbsexec.php");

$hasWrapper = 1;
define ('LBSID', "19002625");

function W_logic_setVar($id, $v1,$v2) {
  $V[$v1] = $v2;
  printf ("V[%s] = %s\n",$v1,$v2);
}


function W_logic_setOutput($id, $v1,$v2) {
  printf ("O[%s] = %s\n",$v1,$v2);
}

function W_writeToCustomLog($lName, $dbgTxt, $output) {
  printf ("%s => %s\n",$lName.$dbgTxt,$output);
}


function W_logic_getInputs() {
    global $id;

    $id = 98765;

    $arr = array();
    for ($i=1;$i<50;$i++) {
        $arr[$i]['value']='';
        //$arr[$i]['refresh']='';
    }

    $arr[1]['value'] = 1;
    $arr[2]['value'] = "192.168.1.200";
    $arr[3]['value'] = "4196";
    $arr[4]['value'] = 3;
    $arr[5]['value'] = "20.00.00";
    $arr[6]['value'] = 100;

    $arr[8]['value'] = 1;
    $arr[8]['refresh'] = 1;

    return ($arr);
}

$ADMIN_FILE = MAIN_PATH . "/www/libs_nima/nasa_admin.php";
LB_LBSID_installAdmin($ADMIN_FILE, 3);

?>
