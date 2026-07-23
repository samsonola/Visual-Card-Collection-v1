<?php

/*
|--------------------------------------------------------------------------
| CSV WRITER
|--------------------------------------------------------------------------
*/

class CSVWriter
{

    /**
     * File Handle
     */
    protected $handle;

    /**
     * File Path
     */
    protected string $path;

    /**
     * Total Rows Written
     */
    protected int $rowsWritten = 0;

    /**
     * Constructor
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

        $this->handle = fopen(

            $path,

            'w'

        );

        if (!$this->handle) {

            throw new Exception(

                'Unable to create CSV.'

            );

        }

    }

    /*
    |--------------------------------------------------------------------------
    | Write Header
    |--------------------------------------------------------------------------
    */

    public function header(array $columns): void
    {

        fputcsv(

            $this->handle,

            $columns

        );

    }

    /*
    |--------------------------------------------------------------------------
    | Write One Row
    |--------------------------------------------------------------------------
    */

    public function row(array $row): void
    {

        fputcsv(

            $this->handle,

            $row

        );

        $this->rowsWritten++;

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

            $this->row($row);

        }

    }

    /*
    |--------------------------------------------------------------------------
    | Flush Buffer
    |--------------------------------------------------------------------------
    */

    public function flush(): void
    {

        fflush(

            $this->handle

        );

    }

    /*
    |--------------------------------------------------------------------------
    | Close Writer
    |--------------------------------------------------------------------------
    */

    public function close(): void
    {

        if (

            is_resource(

                $this->handle

            )

        ) {

            fflush(

                $this->handle

            );

            fclose(

                $this->handle

            );

        }

    }

    /*
    |--------------------------------------------------------------------------
    | Get File Path
    |--------------------------------------------------------------------------
    */

    public function path(): string
    {

        return $this->path;

    }

    /*
    |--------------------------------------------------------------------------
    | File Exists
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
    | File Size
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
    | Human File Size
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
    | Total Rows Written
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