<?php

namespace App\Services\Claude;

/**
 * Claude was stopped before it finished because the work was no longer wanted, for example the user cancelled it.
 */
class ClaudeStoppedException extends ClaudeException {}
