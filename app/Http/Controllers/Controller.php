<?php

namespace App\Http\Controllers;

/**
 * Base class every controller in the application extends.
 *
 * Deliberately empty. Laravel no longer forces shared traits onto controllers,
 * and this application has nothing that genuinely belongs to all of them.
 * Keeping it bare avoids the classic drift where a base controller slowly turns
 * into a junk drawer of helpers.
 */
abstract class Controller
{
    //
}
