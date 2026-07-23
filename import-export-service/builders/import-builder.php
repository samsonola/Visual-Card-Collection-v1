<?php

/*
|--------------------------------------------------------------------------
| IMPORT BUILDER
|--------------------------------------------------------------------------
*/

class ImportBuilder
{

    /*
    |--------------------------------------------------------------------------
    | Bulk Insert
    |--------------------------------------------------------------------------
    */

    public static function insert(

        mysqli $conn,

        array $config,

        array $rows

    ): int
    {

        if (empty($rows)) {

            return 0;

        }

        $columns = $config['import_columns'];

        $table = $config['table'];

        $valueSets = [];

        foreach ($rows as $row) {

            $values = [];

            foreach ($columns as $column) {

                if (!array_key_exists($column, $row)) {

                    $values[] = "NULL";

                    continue;

                }

                $value = $row[$column];

                if (

                    $value === '' ||

                    $value === null

                ) {

                    $values[] = "NULL";

                    continue;

                }

                $values[] = "'" .

                    mysqli_real_escape_string(

                        $conn,

                        (string)$value

                    ) .

                    "'";

            }

            $valueSets[] =

                "(" .

                implode(

                    ",",

                    $values

                ) .

                ")";

        }

        $sql = "

            INSERT INTO {$table}

            (

                " .

                implode(

                    ",",

                    $columns

                ) .

            "

            )

            VALUES

            " .

            implode(

                ",",

                $valueSets

            );

        if (

            !mysqli_query(

                $conn,

                $sql

            )

        ) {

            throw new Exception(

                mysqli_error(

                    $conn

                )

            );

        }

        return mysqli_affected_rows(

            $conn

        );

    }

}