<?php

/*
|--------------------------------------------------------------------------
| CSV READER
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/validator.php';

/*
|--------------------------------------------------------------------------
| CSV Reader
|--------------------------------------------------------------------------
*/

class CSVReader
{

    /**
     * CSV File
     */
    protected SplFileObject $file;

    /**
     * CSV Header
     */
    protected array $headers = [];

    /**
     * Current Row Number
     */
    protected int $rowNumber = 1;

    /**
     * Constructor
     */
    public function __construct(string $file)
    {

        if (!file_exists($file)) {

            throw new Exception(

                "CSV file not found."

            );

        }

        $this->file = new SplFileObject($file);

        $this->file->setFlags(

            SplFileObject::READ_CSV |

            SplFileObject::SKIP_EMPTY |

            SplFileObject::DROP_NEW_LINE

        );

    }

    /*
    |--------------------------------------------------------------------------
    | Read Header
    |--------------------------------------------------------------------------
    */

    public function headers(): array
    {

        if (!empty($this->headers)) {

            return $this->headers;

        }

        $this->file->rewind();

        $headers = $this->file->fgetcsv();

        if (!$headers) {

            throw new Exception(

                "CSV has no header."

            );

        }

        $headers = array_map(

            fn($value) => trim((string)$value),

            $headers

        );

        validate_duplicate_headers(

            $headers

        );

        $this->headers = $headers;

        $this->rowNumber = 2;

        return $headers;

    }

    /*
    |--------------------------------------------------------------------------
    | Read Chunk
    |--------------------------------------------------------------------------
    */

    public function chunk(

        int $size = 5000

    ): Generator
    {

        $headers = $this->headers();

        $rows = [];

        while (

            !$this->file->eof()

        ) {

            $row = $this->file->fgetcsv();

            if (

                $row === false ||

                $row === [null]

            ) {

                continue;

            }

            if (

                row_is_empty(

                    $row

                )

            ) {

                continue;

            }

            validate_utf8(

                $row

            );

            $assoc = [];

            foreach (

                $headers as

                $index => $column

            ) {

                $assoc[$column] =

                    $row[$index] ?? null;

            }

            $assoc['_row_number'] =

                $this->rowNumber;

            $rows[] = $assoc;

            $this->rowNumber++;

            if (

                count($rows)

                >=

                $size

            ) {

                yield $rows;

                $rows = [];

            }

        }

        if (

            !empty($rows)

        ) {

            yield $rows;

        }

    }

    /*
    |--------------------------------------------------------------------------
    | Count Rows
    |--------------------------------------------------------------------------
    */

    public function totalRows(): int
    {

        $count = 0;

        $this->file->rewind();

        while (

            !$this->file->eof()

        ) {

            $row = $this->file->fgetcsv();

            if (

                $row === false ||

                $row === [null]

            ) {

                continue;

            }

            if (

                row_is_empty(

                    $row

                )

            ) {

                continue;

            }

            $count++;

        }

        if (

            $count > 0

        ) {

            $count--;

        }

        $this->file->rewind();

        return $count;

    }

}