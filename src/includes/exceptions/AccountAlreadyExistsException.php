<?php

/**
 * Raised when bootstrap tries to create an administrator whose login or e-mail is occupied.
 *
 * Installers may treat this one condition as an idempotent retry. Other persistence failures
 * remain fatal and must never be inferred from a translated or driver-specific message.
 */
final class AccountAlreadyExistsException extends DomainException
{
}
