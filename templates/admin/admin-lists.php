<div class="hrm-update-notification"></div>
<?php

$search['employer'] = array(
    'label' => __( 'User name/Email/User ID', 'hrm' ),
    'type'  => 'text',
    'value' => isset( $_POST['employer'] ) ? $_POST['employer'] : '',
    'desc'  => 'You can search by user name, user email or user id',
);

$search['type'] = array(
    'type' => 'hidden',
    'value' => '_search'
);
$search['action'] = 'hrm_search';
$search['table_option'] = 'hrm_user_search';

echo Hrm_settings::getInstance()->get_serarch_form( $search, 'Admin');

?>
<div id="hrm-admin-list"></div>
<?php
//hidden form

$pagenum     = hrm_pagenum();
$limit       = hrm_result_limit();
if( isset( $_POST['type'] ) && ( $_POST['type'] == '_search' ) ) {
    $search = $_POST['employer'];
    $search_satus = true;
} else {
    $search = '';
    $search_satus = false;
}
$search_query      = Hrm_Admin::getInstance()->get_employer( $limit, $search, $pagenum );
$results           = $search_query->get_results();
$total             = $search_query->get_total();
$add_permission    = hrm_user_can_access( $tab, $subtab, 'add' ) ? true : false;
$delete_permission = hrm_user_can_access( $tab, $subtab, 'delete' ) ? true : false;
$user              = wp_get_current_user();

foreach ( $results as $id => $user_obj) {
    if ( $user->user_login ==  $user_obj->user_login ) {
        continue;
    }
	$flag = get_user_meta( $user_obj->ID, '_status', true );

	$status = ( $flag == 'yes' ) ? 'Enable' : 'Disable';
    $role = isset( $user_obj->roles[0] ) ? $user_obj->roles[0] : '';

    if ( $add_permission ) {
        $name_id = '<a href="#" class="hrm-editable" data-action="user-role-edit-form-appear" data-id='.$user_obj->ID.'>'.$user_obj->user_login.'<a>';
    } else {
        $name_id = $user_obj->user_login;
    }

    if ( $delete_permission ) {
        $del_checkbox = '<input name="hrm_check['.$user_obj->ID.']" value="" type="checkbox">';
    } else {
        $del_checkbox = '';
    }

    $employer_status = hrm_user_can_access( $tab, $subtab, 'admin_list_employer_status', true );

    if ( $employer_status ) {
        $admin_status_dropdown = array(
            'class'    => 'hrm-admin-status',
            'extra'    => array(
                'data-user_id' => $user_obj->ID,
            ),
            'option'   => array( 'yes' => __( 'Enable', 'hrm' ), 'no' => __('Disable', 'hrm') ),
            'selected' => $flag
        );
        $admin_status_dropdown = Hrm_settings::getInstance()->select_field( 'admin_staus', $admin_status_dropdown );
    } else {
        $admin_status_dropdown = __('Permission denied', 'hrm' );
    }



    $body[] = array(
        $del_checkbox,
        $name_id,
        $role,
        $user_obj->display_name,
        $admin_status_dropdown,
    );

    $td_attr[] = array(
        'class="check-column"'
    );
}

$del_checkbox        = ( $delete_permission ) ? '<input type="checkbox">' : '';
$table = array();
$table['head']       = array( $del_checkbox , 'Admin Name', 'Role', 'Display Name', 'Status' );
$table['body']       = isset( $body ) ? $body : '';


$table['td_attr']    = isset( $td_attr ) ? $td_attr : '';
$table['th_attr']    = array( 'class="check-column"' );
$table['table_attr'] = array( 'class' => 'widefat' );

$table['table']      = 'hrm_job_title_option';
$table['action']     = 'hrm_user_delete';
$table['table_attr'] = array( 'class' => 'widefat' );
$table['tab']        = $tab;
$table['subtab']     = $subtab;

echo Hrm_settings::getInstance()->table( $table );
$file_path = urlencode(__FILE__);
//pagination
echo Hrm_settings::getInstance()->pagination( $total, $limit, $pagenum );
?>

<div id="hrm-create-user-wrap" title="<?php _e( 'Create a new user', 'hrm' ); ?>">
    <div class="hrm-create-user-form-wrap">

        <div class="hrm-error"></div>

        <form action="" class="hrm-user-create-form">
            <?php wp_nonce_field( 'hrm_nonce', '_wpnonce' ); ?>
            <div class="hrm-field-wrap">
                <label><?php _e('Username', 'hrm'); ?></label>
                <input type="text" required name="admin_name">

            </div>
            <div class="hrm-field-wrap">
                <label><?php _e('First Name', 'hrm'); ?></label>
                <input type="text" name="first_name">

            </div>
            <div class="hrm-field-wrap">
                <label><?php _e('Last Name', 'hrm'); ?></label>
                <input type="text" name="last_name">

            </div>
            <div class="hrm-field-wrap">
                <label><?php _e('Email', 'hrm'); ?></label>
                <input type="email" required name="admin_email">

            </div>
            <div>
                <input class="button-primary" type="submit" value="Create User" name="create_user">
                <span></span>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">

    jQuery(function($) {
        $( "#hrm-create-user-wrap" ).dialog({
            autoOpen: false,
            modal: true,
            dialogClass: 'hrm-ui-dialog',
            width: 400,
            height: 'auto',
            position:['middle', 100],

        });
    });
</script>
<?php $url = Hrm_Settings::getInstance()->get_current_page_url( $page, $tab, $subtab ); ?>
<script type="text/javascript">
jQuery(function($) {
    hrm_dataAttr = {
       add_form_generator_action : 'add_form',
       add_form_apppend_wrap : 'hrm-admin-list',
       redirect : '<?php echo $url; ?>',
       class_name : 'Hrm_Admin',
       function_name : 'admin_list',
       page: '<?php echo $page; ?>',
       tab: '<?php echo $tab; ?>',
       subtab: '<?php echo $subtab; ?>',
       req_frm: '<?php echo $file_path; ?>',
       limit: '<?php echo $limit; ?>',
       search_satus: '<?php echo $search_satus; ?>',
       subtab: true
    };
});
</script>