<?php
/**
 * The MIT License (MIT)
 *
 * @Author: sharky72 (https://github.com/KocourKuba)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense
 * of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included
 * in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 */

require_once 'Logger.php';

class SqlWrapper
{
    /**
     * @var SQLite3
     */
    protected SQLite3 $db;

    /**
     * @var string
     */
    protected string $db_path = '';

    /**
     * @param string $db_path
     * @return void
     */
    public function open_db(string $db_path): void
    {
        $this->db_path = $db_path;

        $this->db = new SQLite3($db_path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE, '');
        $this->db->exec('PRAGMA journal_mode=MEMORY;');
    }

    /**
     * Returns 0 - if attach failed
     * Returns 1 - if attach success
     * Returns 2 - if database already attached
     *
     * @param string $db_filename
     * @param string $name
     * @return int
     */
    public function attachDatabase(string $db_filename, string $name): int
    {
        Logger::log(severity::Dbg, "Trying to attach: as '$name' db: '$db_filename'");
        $result = $this->is_database_attached($name, $db_filename);
        if ($result === 2) {
            Logger::log(severity::Dbg, 'Already attached');
            return $result;
        }

        if ($result !== 0) {
            $this->exec("DETACH DATABASE '$name';");
        }

        $this->exec("ATTACH DATABASE '$db_filename' AS $name;");
        $result = $this->is_database_attached($name, $db_filename);
        Logger::log(severity::Dbg, 'Attach: ' . ($result ? 'success' : 'fail'));
        return $result;
    }

    /**
     * Returns true - if detach success
     * Returns false - if detach failed
     *
     * @param string $name
     * @return bool
     */
    public function detachDatabase(string $name): bool
    {
        if ($this->is_database_attached($name) !== 0) {
            Logger::log(severity::Dbg, "Trying to detach: '$name'");
            $this->exec("DETACH DATABASE '$name';");
            $result = $this->is_database_attached($name) === 0;
            Logger::log(severity::Dbg, 'Detach: ' . ($result ? 'success' : 'fail'));
            return $result;
        }
        return true;
    }

    /**
     * Return 0 if no database attached
     * Return 1 if database attached (filename to check not set)
     * Return 2 if database attached and filename is match
     * Return 3 if database attached and filename not match
     *
     * @param string $db_name
     * @param string|null $db_filename Full path to database file
     * @return int
     */
    public function is_database_attached(string $db_name, string $db_filename = null): int
    {
        $result = 0;
        foreach ($this->fetch_array('PRAGMA database_list') as $database) {
            if ($database['name'] !== $db_name) continue;

            if ($db_filename == null) {
                $result = 1;
                break;
            }

            if ($db_filename == ':memory:' && empty($database['file'])) {
                $result = 2;
                break;
            }

            $used_db_file = basename($database['file']);
            $checked_db_file = basename($db_filename);
            $result = ($used_db_file === $checked_db_file) ? 2 : 3;
            break;
        }

        return $result;
    }

    /**
     * @param string $table_name
     * @param string|null $db_name
     * @return bool
     */
    public function is_table_exists(string $table_name, string $db_name = null): bool
    {
        if (!empty($db_name) && !$this->is_database_attached($db_name)) {
            return false;
        }

        $db_name = empty($db_name) ? 'sqlite_master' : "$db_name.sqlite_master";
        return (int)$this->query_value("SELECT count(name) FROM $db_name WHERE type='table' AND name='$table_name';") !== 0;
    }

    /**
     * @param string $table_name
     * @param string $column_name
     * @return bool
     */
    public function is_column_exists(string $table_name, string $column_name): bool
    {
        $query = sprintf("SELECT count(*) FROM sqlite_master WHERE type='table' AND name=%s AND sql like %s;",
            SqlWrapper::sql_quote($table_name), SqlWrapper::sql_quote("%$column_name%"));
        return (int)$this->query_value($query) !== 0;
    }

    /**
     * @param string|null $db_name
     * @return array
     */
    public function get_master_table_list(string $db_name = null): array
    {
        if (!is_null($db_name) && !$this->is_database_attached($db_name)) {
            Logger::log(severity::Err, "get_master_table_list: Database '$db_name' not attached!");
            return array();
        }

        $db_name = is_null($db_name) ? 'sqlite_master' : "$db_name.sqlite_master";
        return $this->fetch_array("SELECT name FROM $db_name WHERE type='table';", 'name');
    }

    /**
     * quote value (val1 -> 'val1')
     * *
     * @param string $var
     * @return string
     */
    public static function sql_quote(string $var): string
    {
        return "'" . SQLite3::escapeString($var) . "'";
    }

    /**
     * Prepare data to create table from array.
     * Array must contain follow data: column => column condition
     * channel_id => TEXT PRIMARY KEY NOT NULL, name => TEXT
     *
     * @param array $values
     * @return string
     */
    public static function make_table_columns(array $values): string
    {
        $str = '';
        foreach ($values as $col => $type) {
            $str .= "$col $type,";
        }

        return rtrim($str, ",");
    }

    /**
     * Make INSERT list from array values (array[key1], array[key2], array[key3])
     * (key1,key2,key3) VALUES ('array[key1]','array[key2]','array[key3]')
     *
     * @param array $arr
     * @param bool $quoted
     * @param bool $bind
     * @return string
     */
    public static function sql_make_insert_list(array $arr, bool $quoted = true, bool $bind = false): string
    {
        $columns = self::sql_make_list_from_keys($arr);
        $values = self::sql_make_list_from_values($arr, $quoted, $bind ? ':' : '');
        return "($columns) VALUES ($values)";
    }

    /**
     * Make INSERT list from array values (array[key1], array[key2], array[key3])
     * (array[key1],array[key2],array[key3]) VALUES ('array[key1]','array[key2]','array[key3]')
     *
     * @param array $arr
     * @param bool $quoted
     * @param bool $bind
     * @return string
     */
    public static function sql_make_insert_list_from_values(array $arr, bool $quoted = true, bool $bind = false): string
    {
        $columns = self::sql_make_list_from_values($arr, false);
        $values = self::sql_make_list_from_values($arr, $quoted, $bind ? ':' : '');
        return "($columns) VALUES ($values)";
    }

    /**
     * Make SET list for example: SET key1 = 'array[key1]', key2 = 'array[key2]', key4 = 'array[key3]'
     * from array values (array[key1], array[key2], array[key3])
     *
     * @param array $arr
     * @return string
     */
    public static function sql_make_set_list(array $arr): string
    {
        $str = "";
        foreach ($arr as $col => $type) {
            $str .= "$col=" . self::sql_quote($type) . ",";
        }
        return rtrim($str, ",");
    }

    /**
     * Make where clause for single value or array
     *
     * @param array|string $values
     * @param string $column
     * @param bool $not
     * @return string
     */
    public static function sql_make_where_clause(array|string $values, string $column, bool $not = false): string
    {
        if (is_array($values)) {
            $in = $not ? "NOT IN" : "IN";
            $q_values = SqlWrapper::sql_make_list_from_values($values);
            $where = "$column $in ($q_values)";
        } else {
            $eq = $not ? "!=" : "=";
            $where = "$column $eq" . SqlWrapper::sql_quote($values);
        }

        return $where;
    }

    /**
     * Make insert list from array.
     * array(val1, val2, val3) => val1, val2, val3
     * if quoted: array(val1, val2, val3) => 'val1', 'val2', 'val3'
     * if prefix ':' : array(val1, val2, val3) => :val1, :val2, :val3
     * if quoted prefix ':' : array(val1, val2, val3) => ':val1', ':val2', ':val3'
     *
     * @param array $arr
     * @param bool $quoted
     * @param string $prefix
     * @return string
     */
    public static function sql_make_list_from_values(array $arr, bool $quoted = true, string $prefix = ''): string
    {
        if ($quoted) {
            $arr = array_map(function($var) {
                return "'" . SQLite3::escapeString($var) . "'";
            }, $arr);
        }

        return $prefix . implode(",$prefix", $arr);
    }

    /**
     * Make list from array keys
     * array(key1=>val1,key2=>val2,key3=>val3) -> key1,key2,key3
     *
     * @param array $arr
     * @param bool $quoted
     * @param string $prefix
     * @return string
     */
    public static function sql_make_list_from_keys(array $arr, bool $quoted = false, string $prefix = ''): string
    {
        return self::sql_make_list_from_values(array_keys($arr), $quoted, $prefix);
    }

    /**
     * Execute query
     *
     * @param string $query
     * @return bool result of exec
     */
    public function exec(string $query): bool
    {
        if (empty($query)) {
            return false;
        }

        $result = $this->db->exec($query);
        if ($result === false) {
            Logger::log(severity::Err, "failed to execute query: $query");
        }
        return $result;
    }

    /**
     * Prepare bind based on query
     *
     * @param string $query
     * @return SQLite3Stmt|false
     */
    public function prepare(string $query): false|SQLite3Stmt
    {
        return $this->db->prepare($query);
    }

    /**
     * Prepare bind based on array of columns
     *
     * @param string $action
     * @param string $table
     * @param array $columns
     * @return false|SQLite3Stmt
     */
    public function prepare_bind(string $action, string $table, array $columns): false|SQLite3Stmt
    {
        $insert = self::sql_make_insert_list_from_values($columns, false, true);
        $query = "$action INTO $table $insert;";
        $result = $this->db->prepare($query);
        if ($result === false) {
            Logger::log(severity::Err, "failed to prepare statement: $query");
        }

        return $result;
    }

    /**
     * query single value.
     * Typically for SELECT count(), SELECT channel_id, group_id etc.
     * if full_row - returns entire row instead of signgle column
     * query returns only one value!
     *
     * @param string $query
     * @param bool $full_row
     * @return mixed
     */
    public function query_value(string $query, bool $full_row = false): mixed
    {
        if (empty($query)) {
            return false;
        }

        $result = $this->db->querySingle($query, $full_row);
        if ($result === false) {
            Logger::log(severity::Err, "failed to execute query: $query");
        }
        return $result;
    }

    /**
     * Fetch array of rows that contains array of columns
     * if column is null then returned array of rows['column'] it will convert to simple array() of values row['column']
     *
     * @param string $query
     * @param string|null $column
     * @return array
     */
    public function fetch_array(string $query, string $column = null): array
    {
        if (empty($query)) {
            return array();
        }

        $rows = array();
        $result = $this->db->query($query);
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = is_null($column) ? $row : $row[$column];
            }
        } else {
            Logger::log(severity::Err, "failed to fetch array: $query");
        }

        return $rows;
    }

    /**
     * Execute query as one transaction (multiple insert/update/delete etc.)
     * If transaction failed it's immediately rollback, i.e. database not updated!
     *
     * @param string $query
     * @return bool result of transaction
     */
    public function exec_transaction(string $query): bool
    {
        if (empty($query)) {
            return false;
        }

        $query = 'BEGIN;' . $query . 'COMMIT;' ;
        if ($this->db->exec($query)) {
            return true;
        }

        Logger::log(severity::Err, 'Error commit transaction!');
        Logger::log(severity::Err, $query);
        $this->db->exec('ROLLBACK;');
        return false;
    }
}