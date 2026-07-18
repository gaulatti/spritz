<?php

status_header(404);
header('Content-Type: text/plain');
echo 'This WordPress instance is API/admin only.';
exit;
