<?php

namespace App\Services;

use RuntimeException;

/** A chunked-upload problem caused by the request; the message is user-facing. */
class ChunkedUploadException extends RuntimeException
{
}
