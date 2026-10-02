<?php
declare(strict_types=1);

// Responses the implementation can produce that openapi.yaml does not document (see docs/OPENAPI_GAPS.md).
// The suite fails when a NEW undocumented response appears, so this list can never grow silently.
// Format: "<METHOD> <openapi path template> <status> <error code>"
return [];
