<?php
namespace Mors;
use Mors\Auth\Capabilities;
class Deactivator {
    public static function deactivate() { Capabilities::remove(); }
}
