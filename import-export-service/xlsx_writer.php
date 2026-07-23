<?php

/*
|--------------------------------------------------------------------------
| XLSX WRITER
|--------------------------------------------------------------------------
*/

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class XLSXWriter
{

    /**
     * Spreadsheet
     */
    protected Spreadsheet $spreadsheet;

    /**
     * Active Sheet
     */
    protected $sheet;

    /**
     * Output Path
     */
    protected string $path;

    /**
     * Current Row
     */
    protected int $currentRow = 1;

    /**
     * Total Rows Written
     */
    protected int $rowsWritten = 0;

    /*
    |--------------------------------------------------------------------------
    | Constructor
    |--------------------------------------------------------------------------
    */

    public function __construct(string $path)
    {

        $directory = dirname($path);

        if (!is_dir($directory)) {

            mkdir(

                $directory,

                0777,

                true

            );

        }

        $this->path = $path;

        $this->spreadsheet = new Spreadsheet();

        $this->sheet =

            $this->spreadsheet->getActiveSheet();

    }

    /*
    |--------------------------------------------------------------------------
    | Write Header
    |--------------------------------------------------------------------------
    */

    public function header(array $columns): void
    {

        $column = 1;

        foreach (

            $columns as

            $value

        ) {

            $this->sheet->setCellValueByColumnAndRow(

                $column,

                $this->currentRow,

                $value

            );

            $column++;

        }

        $this->currentRow++;

    }

    /*
    |--------------------------------------------------------------------------
    | Write One Row
    |--------------------------------------------------------------------------
    */

    public function row(array $row): void
    {

        $column = 1;

        foreach (

            $row as

            $value

        ) {

            $this->sheet->setCellValueByColumnAndRow(

                $column,

                $this->currentRow,

                $value

            );

            $column++;

        }

        $this->rowsWritten++;

        $this->currentRow++;

    }

    /*
    |--------------------------------------------------------------------------
    | Write Multiple Rows
    |--------------------------------------------------------------------------
    */

    public function rows(array $rows): void
    {

        foreach (

            $rows as

            $row

        ) {

            $this->row(

                $row

            );

        }

    }

    /*
    |--------------------------------------------------------------------------
    | Save Workbook
    |--------------------------------------------------------------------------
    */

    public function save(): void
    {

        $writer = new Xlsx(

            $this->spreadsheet

        );

        $writer->save(

            $this->path

        );

    }

    /*
    |--------------------------------------------------------------------------
    | Close
    |--------------------------------------------------------------------------
    */

    public function close(): void
    {

        $this->save();

        $this->spreadsheet->disconnectWorksheets();

        unset(

            $this->spreadsheet

        );

    }

    /*
    |--------------------------------------------------------------------------
    | Path
    |--------------------------------------------------------------------------
    */

    public function path(): string
    {

        return $this->path;

    }

    /*
    |--------------------------------------------------------------------------
    | Exists
    |--------------------------------------------------------------------------
    */

    public function exists(): bool
    {

        return file_exists(

            $this->path

        );

    }

    /*
    |--------------------------------------------------------------------------
    | Size
    |--------------------------------------------------------------------------
    */

    public function size(): int
    {

        if (

            !$this->exists()

        ) {

            return 0;

        }

        clearstatcache(

            true,

            $this->path

        );

        return filesize(

            $this->path

        );

    }

    /*
    |--------------------------------------------------------------------------
    | Human Size
    |--------------------------------------------------------------------------
    */

    public function humanSize(): string
    {

        return format_bytes(

            $this->size()

        );

    }

    /*
    |--------------------------------------------------------------------------
    | Rows Written
    |--------------------------------------------------------------------------
    */

    public function rowsWritten(): int
    {

        return $this->rowsWritten;

    }

    /*
    |--------------------------------------------------------------------------
    | Destructor
    |--------------------------------------------------------------------------
    */

    public function __destruct()
    {

        $this->close();

    }

}