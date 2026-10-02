<?php
declare(strict_types=1);

/**
 * Responses the implementation produces that openapi.yaml does not document, or documents with a different error code.
 * Each entry is explained in docs/OPENAPI_GAPS.md and was submitted to the product owner before any spec change
 * (approved interpretation 8: openapi.yaml is not modified silently).
 *
 * The suite FAILS when a response outside this list appears, so the list cannot grow unnoticed.
 * Format: "<METHOD> <openapi path template> <status> <error code>"  or  "* * <status> <error code>" for cross-cutting cases.
 */
return [
    // --- cross-cutting (every endpoint) ---
    '* * 429 RATE_LIMITED',                 // authenticated API (120/min) and mutation (60/min) limits; only login documents 429
    '* * 500 INTERNAL_ERROR',               // unexpected failure, generic envelope without internals
    '* * 405 METHOD_NOT_ALLOWED',           // known path, unsupported method (Allow header set)
    '* * 204 -',                            // CORS preflight (OPTIONS), empty body
    'GET /health 503 -',                    // database/schema unavailable: {"status":"error"} (INSTALLATION_REQUIREMENTS section 15, optional)

    // --- per endpoint ---
    'POST /api/v1/auth/login 422 VALIDATION_ERROR',
    'POST /api/v1/me/program/start 422 VALIDATION_ERROR',
    'POST /api/v1/me/program/restart 422 VALIDATION_ERROR',
    'POST /api/v1/me/program/restart 422 INVALID_START_POSITION',
    'GET /api/v1/me/history 422 VALIDATION_ERROR',
    'POST /api/v1/workout-assignments/{assignment_id}/start 409 INVALID_ASSIGNMENT_STATE',
    'POST /api/v1/workout-assignments/{assignment_id}/complete 409 ACTIVE_WORKOUT_EXISTS',
    'POST /api/v1/workout-assignments/{assignment_id}/complete 422 VALIDATION_ERROR',
    'POST /api/v1/workout-sessions/{session_id}/complete 422 VALIDATION_ERROR',
    'POST /api/v1/me/program/complete 409 BLOCK_NOT_READY_FOR_DECISION',
];
