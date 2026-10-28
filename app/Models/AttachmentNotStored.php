<?php

namespace App\Models;

use RuntimeException;

/**
 * The disk did not take a file (see Attachment::put).
 */
class AttachmentNotStored extends RuntimeException {}
