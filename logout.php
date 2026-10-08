<?php
session_name('APP_MESIN');
session_start();
session_unset();
session_destroy();
header("Location: login.php");
exit;
