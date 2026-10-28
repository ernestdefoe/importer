<?php

namespace ErnestDefoe\Importer;

/** A step whose lock was taken over by another stepper: it rolls back and writes nothing. */
final class LostLock extends \RuntimeException
{
}
