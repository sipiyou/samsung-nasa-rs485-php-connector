php compile.php
sed "s|//require('wrapper.php');|require('wrapper.php');|; s|require(dirname(__FILE__).\"/\.\./\.\./\.\./\.\./main/include/php/incl_lbsexec.php\");|//require(dirname(__FILE__).\"/../../../../main/include/php/incl_lbsexec.php\");|" 19002625_lbs.php > 19002625_lbs_local.php
scp 19002625_lbs_local.php root@edomi.home.local:~/edomi/tests/nasa/
