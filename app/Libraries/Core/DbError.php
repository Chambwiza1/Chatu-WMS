<?php

namespace App\Libraries\Core;

use RuntimeException;

/** A failed database statement (never shown to users verbatim). */
class DbError extends RuntimeException
{
}
