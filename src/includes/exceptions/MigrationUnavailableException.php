<?php

/**
 * The schema cannot be served right now, and nothing new went wrong in this request: a migration
 * step failed moments ago and web requests are waiting before running it again, or another
 * process holds the migration lock. The web front answers 503 without logging it again; the
 * failure itself was logged once, by the attempt that failed, and is in the migration journal.
 */
final class MigrationUnavailableException extends RuntimeException
{
}
