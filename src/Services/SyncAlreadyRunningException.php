<?php

namespace Dylf\GaleSync\Services;

/**
 * Another sync of the same server holds the lock.
 */
class SyncAlreadyRunningException extends GaleSyncException {}
