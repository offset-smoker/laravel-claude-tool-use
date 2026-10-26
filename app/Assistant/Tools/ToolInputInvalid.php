<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use RuntimeException;

/**
 * A tool call the model can fix: bad input, an unknown id, a quote that is
 * not in the record. It goes back to the model as an is_error tool_result.
 */
final class ToolInputInvalid extends RuntimeException {}
