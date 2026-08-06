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
     * @param string $db_path
     * @return void
     */
    public function open_db(string $db_path): void
    {
        $this->db = new SQLite3($db_path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE, '');
        $this->db->exec('PRAGMA journal_mode=MEMORY;');
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
            Logger::log(Logger::Err, "failed to execute query: $query");
        }
        return $result;
    }

    /**
     * Prepare bind based on query
     *
     * @param string $query
     * @return SQLite3Stmt|false
     */
    public function prepare(string $query): SQLite3Stmt
    {
        return $this->db->prepare($query);
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
    public function query_value(string $query, bool $full_row = false)
    {
        if (empty($query)) {
            return false;
        }

        $result = $this->db->querySingle($query, $full_row);
        if ($result === false) {
            Logger::log(Logger::Err, "failed to execute query: $query");
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
            return [];
        }

        $rows = [];
        $result = $this->db->query($query);
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = is_null($column) ? $row : $row[$column];
            }
        } else {
            Logger::log(Logger::Err, "failed to fetch array: $query");
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

        $query = 'BEGIN;' . $query . 'COMMIT;';
        if ($this->db->exec($query)) {
            return true;
        }

        Logger::log(Logger::Err, 'Error commit transaction!');
        Logger::log(Logger::Err, $query);
        $this->db->exec('ROLLBACK;');
        return false;
    }
}