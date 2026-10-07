<?php

namespace App\Mcp;

use RuntimeException;

/**
 * Something the caller can fix: the message goes back to the agent as the tool's error.
 */
class ToolFailure extends RuntimeException {}
