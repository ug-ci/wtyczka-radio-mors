<?php
namespace Mors\Auth;
class Capabilities {
    public static function add() {
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( \Mors_Enum::CAP_EDIT_MUSIC );
            $admin->add_cap( \Mors_Enum::CAP_PRESENT );
            $admin->add_cap( \Mors_Enum::CAP_MANAGE );
        }
    }
    public static function remove() {
        foreach ( [ 'administrator' ] as $r ) {
            $role = get_role( $r );
            if ( ! $role ) { continue; }
            $role->remove_cap( \Mors_Enum::CAP_EDIT_MUSIC );
            $role->remove_cap( \Mors_Enum::CAP_PRESENT );
            $role->remove_cap( \Mors_Enum::CAP_MANAGE );
        }
    }
}
