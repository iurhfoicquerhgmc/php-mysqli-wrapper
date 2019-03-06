<?php
/*
Example:

$link = DB\connect(parse_ini_file("db.ini"));
$query = DB\query($link, "select * from table where field=?", "i");
function print_row(...$fields) {
    var_dump($fields);
}
$param = 0;
$query($param)(DB\visit_rows(print_row));

*/

namespace DB;

function connect($ini) {
    $link = mysqli_init();
    if (isset($ini["ssl-ca"])) {
        mysqli_ssl_set($link, NULL, NULL, $ini["ssl-ca"], NULL, NULL);
    }
    if (!mysqli_real_connect($link, $ini["host"], $ini["user"], $ini["password"], $ini["database"], $ini["port"])) {
        trigger_error(sprintf("Connect failed: %s", mysqli_connect_error()), E_USER_ERROR);
    }
    if (isset($ini["charset"])) {
        mysqli_set_charset($link, $ini["charset"]);
    }
    return $link;
}

function query($link, $query, $types = "") {
    $prepare = function() use ($link, $query) {
        $p = mysqli_stmt_init($link);
        if (!mysqli_stmt_prepare($p, $query)) {
            trigger_error(sprintf("Prepare statement: %s", mysqli_stmt_error($p)), E_USER_ERROR);
        }
        return $p;
    };

    return function(&...$query_args) use ($prepare, $types) {
        $p = $prepare();
        if (!empty($types) && !mysqli_stmt_bind_param($p, $types, ...$query_args)) {
            trigger_error(sprintf("Bind param: %s", mysqli_stmt_error($p)), E_USER_ERROR);
        }
        return function($func = "noop") use ($p) {
            $func($p);
        };
    };
}

function execute($func) {
    return function($p) use ($func) {
        if (!mysqli_stmt_execute($p)) {
            trigger_error(sprintf("Execute statement: %s", mysqli_stmt_error($p)), E_USER_ERROR);
        }
        $func($p);
        mysqli_stmt_close($p);
    };
}

function noop($p) {
    return execute(function ($p) {})($p);
}

function visit_rows($func) {
    return execute(function ($p) use ($func) {
        if (!mysqli_stmt_store_result($p)) {
            trigger_error(sprintf("Store result: %s", mysqli_stmt_error($p)), E_USER_WARNING);
        }
        $meta = mysqli_stmt_result_metadata($p);
        if (!is_null($meta)) {
            $row = array();
            $flds = array($p);
            array_walk(mysqli_fetch_fields($meta), function ($fld) use (&$flds, &$row) {
                $flds[] =& $row[$fld->name];
            });
            mysqli_stmt_bind_result(...$flds);
            while (mysqli_stmt_fetch($p)) {
                $func(...array_values($row));
            }
        }
        else {
            trigger_error("Applying visitor function to non-existent result set", E_USER_WARNING);
        }
        mysqli_stmt_free_result($p);
    });
}

function affected_rows($func) {
    return execute(function ($p) use ($func) {
        $func(mysqli_stmt_affected_rows($p));
    });
}

?>
