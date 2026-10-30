<?php
session_name('APP_ITSUPPORT');
session_start();
session_unset();
session_destroy();
header("Location: login");
exit;
