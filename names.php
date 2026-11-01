<?php
// names.php was renamed to unitlist.php (Unit List); kept so old bookmarks work.
header('Location: unitlist.php' . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']), true, 301);
exit;
