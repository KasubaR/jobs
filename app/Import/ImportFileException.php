<?php

namespace App\Import;

use RuntimeException;

/** The file as a whole cannot be used (not the format, too big, no columns...). Bad single rows are reported instead. */
class ImportFileException extends RuntimeException {}
