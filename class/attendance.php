<?php
use HRM\Core\Database\Model as Eloquent;
use HRM\Models\Attendance;
use HRM\Transformers\Attendance_Transformer;
use Illuminate\Pagination\Paginator;
use HRM\Core\Common\Traits\Transformer_Manager;
use League\Fractal;
use League\Fractal\Resource\Item as Item;
use League\Fractal\Resource\Collection as Collection;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use HRM\Models\Shift;

class Hrm_Attendance {
    use Transformer_Manager;
    private static $_instance;

    public $unique_ids = array();
    private $relation = 'AND';
    public $total_over_time = 0;
    public $total_worked_time = 0;

    public static function getInstance() {
        if ( ! self::$_instance ) {
            self::$_instance = new Hrm_Attendance();
        }

        return self::$_instance;
    }

    function __construct() {
        add_action('wp_ajax_hrm_get_dashboard_attendance', array( $this, 'get_dashboard_attendance' ) );
        add_action( 'wp_ajax_punch_in', array( $this, 'ajax_save_punch_in' ) );
        add_action( 'wp_ajax_attendance_init', array( $this, 'attendance_init' ) );
        add_action( 'wp_ajax_hrm_get_attendance', array( $this, 'ajax_filter_attendance' ) );
    }



    function ajax_filter_attendance() {
        check_ajax_referer('hrm_nonce');
        
        $results = [];
        $punch_in  = empty(  $_POST['punch_in'] ) ? date( 'Y-m-d 00:00:00', strtotime( date( 'Y-m-01' ) ) ) : $_POST['punch_in'];
        $punch_out = empty(  $_POST['punch_out'] ) ? date( 'Y-m-d 24:59:59', strtotime( current_time( 'mysql' ) ) ) : $_POST['punch_out'];
       
        
        if ( $_POST['allEmployees'] == 'true' ) {
            $employees = Hrm_Employeelist::getInstance()->get_employee( true );

            foreach ( $employees as $key => $employee ) {
                $results[] = $this->filter_attendance( $employee->id, $punch_in, $punch_out );
            }

        } else {
            $user_id   = empty( $_POST['user_id'] ) ? get_current_user_id() : $_POST['user_id'];
            $results[] = $this->filter_attendance( $user_id, $punch_in, $punch_out );
        }
        
        wp_send_json_success( $results );
    }

    function filter_attendance( $user_id, $punch_in, $punch_out ) {
        $interval_array = $this->date_to_array( $punch_in, $punch_out );

        $leaves = Hrm_Leave::getInstance()->get_leaves_array(
            array(
                'start_time' => $punch_in,
                'end_time'   => $punch_out,
                'per_page'   =>  1000,
                'status'     => 2,
                'emp_id'     => $user_id
            )
        );
        
        $holidays = Hrm_Leave::getInstance()->get_holidays_array( 
            array(
                'from' => $punch_in, 
                'to'  => $punch_out 
            )
        );
        
        $work_week    = Hrm_Leave::getInstance()->work_week_array($punch_in, $punch_out);
        
        $exclud_dates = array_merge( $leaves, $holidays, $work_week );
        $attendance   = $this->get_attendance( $_POST );
        $presents     = $this->get_in_date_array( $attendance['data'] );
        $absents      = $this->get_out_date_array( $interval_array, $presents, $exclud_dates );

        $total_off_days     = count( $work_week ) + count( $holidays ) + count( $leaves );
        $total_work_days    = count( $interval_array ) - $total_off_days;
        $total_shift_second = $this->get_total_shift_second( $attendance['data'], $user_id );
        $net_shift_second   = $total_shift_second * $total_work_days;
        $table              = $this->get_individual_day_records( $interval_array, $leaves, $holidays, $work_week, $attendance['data'], $user_id );
        $avg_work           = $this->total_worked_time/$total_work_days;

        $results = [
            'user'      => get_user_by( 'id', $user_id ),
            'days'      => count( $interval_array ),
            'work_days' => $total_work_days,
            'total_worked_time' => $this->second_to( $this->total_worked_time ),
            'leaves'    => count( $leaves ),
            'holidays'  => count( $holidays ),
            'weekends'  => count( $work_week ),
            'presents'  => count( $presents ),
            'absents'   => count( $absents ),
            'over_time' => $this->second_to( $this->total_over_time ),
            'total_working_hours' => $total_shift_second === false ? __( 'You have assigned no shfit', 'hrm' ) : $this->second_to( $net_shift_second ),
            'avg_working_hours' => $this->second_to( $avg_work ),
            'table' => $table
        ];

        return $results;
    }

    function get_individual_day_records( $interval_array, $leaves, $holidays, $work_week, $attendance, $user_id ) {
        $paresent_day_array = [];
        $table = [];

        foreach ( $attendance as $attend ) {
            $punch_in = date( 'Y-m-d', strtotime( $attend['punch_in'] ) );

            $paresent_day_array[$punch_in][] = $attend;
        }

        foreach ( $interval_array as $date => $interval ) {
            $shift = [];
            $day_status = 'absent';

            if ( array_key_exists( $date, $holidays ) ) {
                $day_status = 'holiday';
            }
            if ( array_key_exists( $date, $work_week ) ) {
                $day_status = 'weekend';
            }

            if ( array_key_exists( $date, $leaves ) ) {
                $day_status = 'leave';
            }

            if ( array_key_exists( $date, $paresent_day_array ) ) {
                $shift = $this->generate_shift_table( $paresent_day_array[$date], $user_id, $date );
                $day_status = 'present';
            }

            $table[$date] = [
                'date' => $date,
                'data' => $shift,
                'day_status' => $day_status,

            ];
        }
        
        return $table;
    }

    function get_second( $start, $end ) {
        $start = date( 'H:i', strtotime( $start ) );
        $end = date( 'H:i', strtotime( $end ) );

        if (  $start > $end ) {
            $end = date( 'Y-m-d H:i', strtotime( $end . '+1 day' ) );
            $start = date( 'Y-m-d H:i', strtotime( $start ) );
        } else {
            $end = date( 'Y-m-d H:i', strtotime( $end ) );
            $start = date( 'Y-m-d H:i', strtotime( $start ) );
        }

        return strtotime( $end ) - strtotime( $start );
    }

    function generate_shift_table( $attendance, $user_id, $date ) {
        $shift_id = wp_list_pluck( $attendance, 'shift_id' );
        $shift_id = array_unique( $shift_id );
        $return_data = [];

        $schedule = Shift::find($shift_id[0]);
            
        $times = maybe_unserialize( $schedule['data']['times'] );
        $department = Hrm_Admin::getInstance()->get_employee_department( $user_id );
        

        
        foreach ( $attendance as $key => $attand ) {
            
            $punch_in_shift = $this->get_punch_in_shift( $schedule, $department->id, $attand['punch_in'] );
            $shift_work_second = ( $punch_in_shift['workHours'] * 60 * 60 ) + ( $punch_in_shift['workMinutes'] * 60 );

            $attend_second = $this->get_second( $attand['punch_in'], $attand['punch_out'] );

            $this->total_worked_time = $this->total_worked_time + $attend_second;
            
            if ( $attend_second > $shift_work_second ) {
                
                $this->total_over_time = $this->total_over_time + ($attend_second - $shift_work_second);
                $work_status = [
                    'status' => 'over_time',
                    'time'  => $this->second_to( $attend_second - $shift_work_second )
                ];
            } else if ( $attend_second < $shift_work_second ) {
                $work_status = [
                    'status' => 'less_work',
                    'time'  => $this->second_to( $shift_work_second - $attend_second )
                ];
            } else {
                $work_status = [
                    'status' => '',
                    'time'  => ''
                ];
            }
            
            $return_data[] = [
                'shift' => $punch_in_shift,
                'dept_id' => $department->id,
                'attendance' => $attand,
                'per_day_worked_time' => $this->second_to( $attend_second ),
                'work_status' => $work_status
            ];
        }

        return $return_data;
    }

    function get_total_shift_second( $attendance, $user_id ) {
        $shift_id = wp_list_pluck( $attendance, 'shift_id' );
        $shift_ids = array_unique( $shift_id );
        $department = Hrm_Admin::get_employee_department( $user_id );
        $dept_id = $department ? $department->id : false;

        $shifts = HRM_Shift::getInstance()->get_shift(
            [
                'id' => $shift_ids,
                'status' => true,
                'per_page' => 5000
            ]
        );
        
        if( ! $shifts['data'] ) {
            return false;
        }

        $hours = 0;
        $minutes = 0;
        
        foreach ( $shifts['data'] as $key => $shift ) {

            foreach ( $shift['times'] as $key2 => $time ) {
                $dept_ids = wp_list_pluck( $time['departments'], 'id' );

                if ( ! in_array( $dept_id, $dept_ids ) ) {
                    continue;
                }

                $hours = $hours + $time['workHours'];
                $minutes = $minutes + $time['workMinutes'];
            }
        }
 
        $second = ($hours*60*60)+($minutes*60);

        return $second;
        
    }

    function filter_times_according_department( $times, $dept_id ) {
        
        foreach ( $times as $key => $time ) {
            $dept_ids = wp_list_pluck( $time['departments'], 'id' );

            if ( ! in_array( $dept_id, $dept_ids ) ) {
                unset( $times[$key] );
            }
        }

        return $times;
    }

    function second_to($seconds) {
      $t = round($seconds);
      return sprintf('%02d:%02d:%02d', ($t/3600),($t/60%60), $t%60);
    }

    function get_out_date_array( $interval_array, $presents, $exclud_dates ) {
        
        $exclud_dates = array_merge( $exclud_dates, $presents );
        $absents  = array_diff( $interval_array, $exclud_dates);
        
        return $absents;
    }

    function get_in_date_array( $attendance ) {
        $array = [];

        foreach ( $attendance as $key => $atend ) {
            $date = date( 'Y-m-d', strtotime( $atend['punch_in'] ) );
            $array[$date] = $date;
        }

        return $array;
    }

    function date_to_array( $start, $end ) {
        $start = date('Y-m-d', strtotime( $start) );
        $end = date('Y-m-d', strtotime( $end) );

        $begin = new DateTime( $start );
        $end   = new DateTime( $end );

        $array = [];

        for($i = $begin; $i <= $end; $i->modify('+1 day')){
            $date = $i->format("Y-m-d");

            $array[$date] = $date;
        }

        return $array;
    }

    function ajax_save_punch_in() {
        check_ajax_referer('hrm_nonce');

        $post = $_POST;
        $punch = self::getInstance()->punch_in($post);
        
         wp_send_json_success( array(
            'can_punch_in' => self::getInstance()->can_punch_in(),
            'success' => __( 'Punch in active', 'hrm' ),
        ));
        
    }

    function punch_in( $post ) {
        global $wpdb;
        $has_error = $this->punch_in_validation( $post );

        if ( is_wp_error( $has_error ) ) {
           wp_send_json_error( array( 'error' => $has_error->get_error_messages() ) ); 
        }
        
        $user_id   = ( isset( $post['user_id'] ) && $post['user_id'] ) ? intval( $post['user_id'] ) : get_current_user_id();
        $dpartment = Hrm_Admin::get_employee_department( $user_id );
        $schedule  = $this->has_policy( $dpartment->id );
        $table     = $wpdb->prefix . 'hrm_attendance';
        $data      = array(
            'user_id'  => $user_id,
            'date'     => current_time( 'mysql' ),
            'punch_in' => current_time( 'mysql' ),
            'shift_id' => empty( $schedule->id ) ? 0 : $schedule->id
        );
        $format = array( '%d', '%s', '%s' );

        $insert = $wpdb->insert( $table, $data, $format );

        if ( $insert ) {
            return $wpdb->insert_id;
        }
    }

    function punch_in_validation( $post ) {
        global $current_user;
        $user_id = ( isset( $post['user_id'] ) && $post['user_id'] ) ? intval( $post['user_id'] ) : get_current_user_id();
        
        if ( !in_array( hrm_employee_role_key(), $current_user->roles ) ) {
            return new WP_Error('hrm_user_role', __( 'Are you employee?', 'hrm' ) );
        }

        $dpartment = Hrm_Admin::get_employee_department( $user_id );

        if ( ! $dpartment ) {
            return new WP_Error('hrm_user_role', __( 'Do you have assign any department?', 'hrm' ) );
        }

        $schedule = $this->has_policy( $dpartment->id );

        if ( ! $schedule ) {
            //return new WP_Error('hrm_user_role', __( 'You have no time schedule policy', 'hrm' ) );
        }

        if ( ! $this->can_punch_in( $user_id ) ) {
            return new WP_Error('hrm_user_role', __( 'You have no time schedule policy', 'hrm' ) );
        }
    }

    function can_punch_in( $user_id = false ) {
        global $wpdb;

        $attendance_table = $wpdb->prefix . 'hrm_attendance';
        $user_id      = $user_id ? $user_id : get_current_user_id();
        $dpartment    = Hrm_Admin::get_employee_department( $user_id );
        $schedule     = $this->has_policy( $dpartment->id );
        $shift_id     = isset( $schedule->id ) ? intval( $schedule->id ) : false;

        if ( ! $shift_id ) {
            return true;
        }
        
        //if no schedule. Then check the last punch_out record. If last punch_out 0 then user can't punch_in. 
        //Otherwise user can punch_in 
        $lst_atd = $wpdb->get_row( "SELECT * FROM $attendance_table WHERE `user_id` = $user_id AND shift_id = $shift_id ORDER BY id DESC LIMIT 1" );

        if ( !$lst_atd ) {
            return true;
        }
         
        if( !$schedule ||  ( $schedule && $schedule->status == '0' ) ) {
            if ( $lst_atd && ( strtotime( $lst_atd->punch_out ) <= 0 ) ) {
                return false;
            }
            return true;
        }
        //end
        
        //Get the closest shift from the current time
        $punch_shift = $this->get_punch_in_shift( $schedule, $dpartment->id );
        //end

        if ( $punch_shift ) {
            //Has punch_in within and outside of the shift
            $punch_schedule = $this->has_punch_in_within_shift( $punch_shift, $schedule, $lst_atd );
            //end
            $punch_out = strtotime( $lst_atd->punch_out );

            if ( $punch_schedule && $punch_out <= 0 ) {
                return false;
            }
        } else {
            $punch_out = strtotime( $lst_atd->punch_out );

            if ( $punch_out <= 0 ) {
                return false;
            }
        }

        return true;
    }

    function get_shift_range ( $shift, $work_day ) {
        $start  = date( 'H:i', strtotime( $shift['begin'] ) );
        $end    = date( 'H:i', strtotime( $shift['end'] ) );
        $work_day = date( 'H:i', strtotime( $work_day ) );
        $current_date = date( 'Y-m-d', strtotime( current_time('mysql') ) );
        
        if( $work_day > $start ) {
            $start   = date( $current_date . ' H:i:s', strtotime( $start . ' +1 day' ) );
        }

        if( $work_day > $end ) {
            $end   = date( $current_date . ' H:i:s', strtotime( $end . ' +1 day' ) );
        }

        if ( $start > $end ) {
            $end   = date( $current_date . ' H:i:s', strtotime( $end . ' +1 day' ) );
        }

        return [
            'start' => date( $current_date . ' H:i:s', strtotime( $start ) ),
            'end'   => date( $current_date . ' H:i:s', strtotime( $end ) )
        ];
    }

    function has_punch_in_within_shift( $punch_shift, $schedule, $lst_atd ) {
        
        $punch_in = $lst_atd->punch_in;
        $punch_shift_range = $this->get_shift_range( $punch_shift, $schedule->punch_start );
        $start  = $punch_shift_range['start'];
        $end    = $punch_shift_range['end'];
        $current_date = date( 'Y-m-d', strtotime( current_time('mysql') ) );

        $department = Hrm_Admin::getInstance()->get_employee_department( $user_id );

        //If has the previous shift then check the punch_in status from previoust shift 'end' to
        //current time closest shift start.     'prev_end < current_time <= closest_shift_start'
        $punch_prev_shift = $this->get_prev_shift( $schedule, $department->id );
  
        if ( $punch_prev_shift ) {
            $prev_end   = date( $current_date . ' H:i:s', strtotime( $punch_prev_shift['end'] ) );

            if ( $punch_in > $prev_end && $punch_in <= $end ) {
                return true;
            }
        } else {
            if ( $punch_in <= $end ) {
                return true;
            }
        }
        
        return false;
        //end

    }

    function get_punch_in_shift( $schedule, $dept_id, $current_date = false ) {
        $shift = false;
        $times = maybe_unserialize( $schedule->times );
        $times = $this->filter_times_according_department( $times, $dept_id );

        $current_date = $current_date ? date( 'Y-m-d H:i:s', strtotime( $current_date ) ) 
            : date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) );

        foreach ( $times as $key => $time ) {
            $range = $this->get_shift_range( $time, $schedule->punch_start );

            $shift_start= $range['start'];
            $shift_end  = $range['end'];

            if ( $shift_start <= $current_date && $shift_end >= $current_date ) {
                $shift = $time;
                break;
            }
        }

        if ( !$shift ) {
            $shift = $this->get_next_shift( $schedule, $dept_id );
        }

        return $shift;
    }

    function get_next_shift( $schedule, $dept_id, $current_date = false ) {
        $times        = maybe_unserialize( $schedule->times );
        $times        = $this->filter_times_according_department( $times, $dept_id );
        $all_shifts   = [];
        $current_date = $current_date ? date( 'Y-m-d H:i:s', strtotime( $current_date ) ) 
            : date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) ); 

        foreach ( $times as $key => $time ) {
            $range = $this->get_shift_range( $time, $schedule->punch_start );
            
            $shift_start= $range['start'];
            $shift_end  = $range['end'];

            if ( $current_date > $shift_start ) {
                continue;
            }

            $shift_end  = strtotime( $shift_end );
            $all_shifts[$shift_end] = $time;
          
        }

        ksort($all_shifts);

        return reset( $all_shifts );
    }

    function get_prev_shift( $schedule, $dept_id ) {
        $times        = maybe_unserialize( $schedule->times );
        $times        = $this->filter_times_according_department( $times, $dept_id );
        $all_shifts   = [];
        $current_date = date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) ); 

        foreach ( $times as $key => $time ) {
            $range = $this->get_shift_range( $time, $schedule->punch_start );
            
            $shift_start= $range['start'];
            $shift_end  = $range['end'];

            if ( $current_date < $shift_end ) {
                continue;
            }

            $shift_end  = strtotime( $shift_end );
            $all_shifts[$shift_end] = $time;
          
        }

        ksort($all_shifts);

        return end( $all_shifts );
    }

    // function get_first_shift( $times, $punch_start ) {
    //     $array = [];
    //     $punch_start  = ( date( 'H:i', strtotime( $punch_start ) ) );

    //     foreach ( $times as $key => $time ) {
    //         $shift_start  = ( date( 'H:i', strtotime( $time['begin'] ) ) );

    //         if ( $punch_start > $shift_start ) {
    //             $shift_start = strtotime( date( 'Y-m-d H:i', strtotime( $shift_start . ' +1 day' ) ) );
    //         } else {
    //             $shift_start = strtotime( date( 'Y-m-d H:i', strtotime( $shift_start ) ) );
    //         }
            
    //         $array[$shift_start] = $time;
    //     }
        
    //     ksort($array);

    //     return reset( $array );
    // }

    // function get_current_shift_interval( $time, $current_date = false ) {
    //     $current_date = $current_date ? $current_date : current_time( 'mysql' );
    //     $current_time = date( 'H:i', strtotime( $current_date ) ); 
    //     $shift_start  = date( 'H:i', strtotime( $time['begin'] ) );
    //     $shift_end    = date( 'H:i', strtotime( $time['end'] ) );

    //     //if shift duration 22:00 to 9:00
    //     if ( $shift_start >  $shift_end ) {
    //         $start1 = $shift_start;
    //         $end1   = '23:59';

    //         if ( $start1 <= $current_time && $end1 >= $current_time ) {
    //             return true;
    //         }

    //         $start2 = '00:00';
    //         $end2   = $shift_end;

    //         if ( $start2 <= $current_time && $end2 >= $current_time ) {
    //             return true;
    //         }

    //         return false;
    //     }
    //     //End

    //     if ( $shift_start <= $current_time && $shift_end >= $current_time ) {
    //         return true;
    //     }

    //     return false;
    // }

    function has_policy( $dept_id ) {
        global $wpdb;

        $shift    = 'hrm_time_shift';
        $relation = hrm_tb_prefix() . 'hrm_relation';
        $db       = \WeDevs\ORM\Eloquent\Facades\DB::instance();

        $shift = $db->table( $shift . ' as shift' )
            ->leftJoin( $relation . ' as relation', 'relation.from', '=', 'shift.id')
            ->select( 'shift.*' )
            ->where( 'shift.status', '1' )
            ->where( 'relation.type', 'time_shift_department' )
            ->where( 'relation.to', $dept_id )
            ->first();
        
        if ( $shift ) {
            return $shift;
        }

        return false;
    }


    public function get_dashboard_attendance() {
        check_ajax_referer('hrm_nonce');

        $employees = Hrm_Employeelist::getInstance()->get_employee(true);

        $attendances = $this->get_attendance(
            array (
                'user_id'  => 'all',
                'punch_in' => date( 'Y-m-d', strtotime( current_time( 'mysql' ) ) ),
            )
        );

        $leaves = Hrm_Leave::getInstance()->get_leaves(
            array(
                'start_time' => date( 'Y-m-d', strtotime( current_time( 'mysql' ) ) ),
                'end_time'   => date( 'Y-m-d', strtotime( current_time( 'mysql' ) ) ),
                'per_page'   =>  1000,
                'status'     => 1
            )
        );

        $office_time = self::getInstance()->get_office_time();

        $presents    = $this->get_presents( $employees, $attendances, $leaves['data'] );
        $absents     = $this->get_absents( $employees, $attendances, $leaves['data'] );
        $early_enter = $this->get_early_enter( $employees, $attendances, $leaves['data'], $office_time );
        $early_leave = $this->get_early_leave( $employees, $attendances, $leaves['data'], $office_time );
        $late_leave  = $this->get_late_leave( $employees, $attendances, $leaves['data'], $office_time );

        wp_send_json_success(array(
            'present'    => $presents,
            'absent'     => $absents,
            'early_enter' => $early_enter,
            'early_leave' => $early_leave,
            'late_leave'  => $late_leave
        ));
        
    }

    public function get_early_enter( $employees, $attendances, $leaves, $office_time ) {
        

        $start  = date( 'Y-m-d 10:00', strtotime( current_time('mysql') ) );
        $closed = date( 'Y-m-d 18:00', strtotime( current_time('mysql') ) );

        $start  = empty( $office_time->start ) ? $start : date( 'Y-m-d h:i a', strtotime( $office_time->start ) );
        $closed = empty( $office_time->end ) ? $closed : date( 'Y-m-d h:i a', strtotime( $office_time->end ) );

        $start = strtotime( $start );
        $closed = strtotime( $closed );


        $get_presents = $this->get_presents( $employees, $attendances, $leaves );

        foreach ( $get_presents as $key => $get_present ) {
            $punch_in = strtotime( $get_present->punch_in_date_time );

            if ( $punch_in >= $start ) {
                unset( $get_presents[$key] );
            }
        }

        return $get_presents;
        
    }

    public function get_early_leave( $employees, $attendances, $leaves, $office_time ) {
        $data = [];

        $start  = date( 'Y-m-d 10:00', strtotime( current_time('mysql') ) );
        $closed = date( 'Y-m-d 18:00', strtotime( current_time('mysql') ) );

        $start  = empty( $office_time->start ) ? $start : date( 'Y-m-d h:i a', strtotime( $office_time->start ) );
        $closed = empty( $office_time->end ) ? $closed : date( 'Y-m-d h:i a', strtotime( $office_time->end ) );

        $start = strtotime( $start );
        $closed = strtotime( $closed );


        $get_presents = $this->get_presents( $employees, $attendances, $leaves );

        foreach ( $get_presents as $key => $get_present ) {
            if ( ! empty( $get_present->punch_out_date_time ) ) {
                if ( strtotime( $get_present->punch_out_date_time ) < $closed ) {
                    $data[] = $get_present;
                }
            }
        }
        
        return $data;
        
    }

    public function get_late_leave( $employees, $attendances, $leaves, $office_time ) {

        $data = [];

        $start  = date( 'Y-m-d 10:00', strtotime( current_time('mysql') ) );
        $closed = date( 'Y-m-d 18:00', strtotime( current_time('mysql') ) );

        $start  = empty( $office_time->start ) ? $start : date( 'Y-m-d h:i a', strtotime( $office_time->start ) );
        $closed = empty( $office_time->end ) ? $closed : date( 'Y-m-d h:i a', strtotime( $office_time->end ) );

        $start = strtotime( $start );
        $closed = strtotime( $closed );


        $get_presents = $this->get_presents( $employees, $attendances, $leaves );

        foreach ( $get_presents as $key => $get_present ) {
            if ( ! empty( $get_present->punch_out_date_time ) ) {
                if ( strtotime( $get_present->punch_out_date_time ) > $closed ) {
                    $data[] = $get_present;
                }
            }
        }
        
        return $data;
        
    }

    public function get_presents( $employees, $attendances, $leaves ) {
        $filter_attendances = [];
        $fileter_leaves = [];
        $filter_punch_outs = [];
        $data = [];
        $today = date('Y-m-d', strtotime( current_time( 'mysql' ) ) );
        
        foreach ( $leaves as $key => $leave ) {
            $fileter_leaves[$leave['emp_id']] = $leave;
        }

        //filter punch in
        foreach ( $attendances as $key => $attendance ) {
            $filter_attendances[$attendance->user_id][] = strtotime( $attendance->punch_in );
        }
        //filter punch in
        foreach ( $filter_attendances as $user_id => $filter_attendance ) {
            $filter_attendances[$user_id] = min($filter_attendance);
        }
        
        //filter punch out
        foreach ( $attendances as $key => $attendance ) {
            $punch_out = date( 'Y-m-d', strtotime( $attendance->punch_out ) );
            if ( $punch_out == $today ) {
                $filter_punch_outs[$attendance->user_id][] = strtotime( $attendance->punch_out );
            }
        }

        //filter punch out
        foreach ( $filter_punch_outs as $user_id => $filter_punch_out ) {
            $filter_punch_outs[$user_id] = max($filter_punch_out);
        }

        foreach ( $employees as $key => $emp ) {

            $emp->data->avatar_url = get_avatar_url( $emp->ID );

            if ( array_key_exists( $emp->ID, $fileter_leaves )  ) {
                $emp->data->leave = $fileter_leaves[$emp->ID];
            }

            if ( array_key_exists( $emp->ID, $filter_attendances ) ) {
                $emp->data->punch_in_time = date( 'h:i:s a', $filter_attendances[$emp->ID] );
                $emp->data->punch_in_date_time = date( 'Y-m-d h:i:s a', $filter_attendances[$emp->ID] );
            }

            if ( array_key_exists( $emp->ID, $filter_punch_outs ) ) {
                $emp->data->punch_out_time = date( 'h:i:s a', $filter_punch_outs[$emp->ID] );
                $emp->data->punch_out_date_time = date( 'Y-m-d h:i:s a', $filter_punch_outs[$emp->ID] );
            }

            if ( $emp->data->punch_in_time ) {
                $data[] = $emp->data;
            }
        }

        return $data;
    }

    public function get_absents( $employees, $attendances, $leaves ) {
        $filter_attendances = [];
        $fileter_leaves = [];
        $data = [];
        
        foreach ( $leaves as $key => $leave ) {
            $fileter_leaves[$leave['emp_id']] = $leave;
        }

        foreach ( $attendances as $key => $attendance ) {
            $filter_attendances[$attendance->user_id][] = strtotime( $attendance->punch_in );
        }

        foreach ( $filter_attendances as $user_id => $filter_attendance ) {
            $filter_attendances[$user_id] = date( 'h:i:s a', min($filter_attendance) );
        }

        foreach ( $employees as $key => $emp ) {
            if ( array_key_exists( $emp->ID, $fileter_leaves )  ) {
                $emp->data->leave = $fileter_leaves[$emp->ID];
            }

            if ( ! array_key_exists( $emp->ID, $filter_attendances ) ) {
                $data[] = $emp->data;
            }
        }
        
        return $data;
    }

    public static function attendance_init() {
        check_ajax_referer('hrm_nonce');
        
        $punch_in    = self::getInstance()->can_punch_in();
        $office_time = self::getInstance()->get_office_time();
        
        $office_start_with_date  = date( 'Y-m-d 10:00', strtotime( current_time('mysql') ) );
        $office_closed_with_date = date( 'Y-m-d 18:00', strtotime( current_time('mysql') ) );

        // Attendance default configuration saved
        if ( empty( $office_time ) ) {
            $args = array(
                'hrm_is_multi_attendance' => false,
                'office_start'            => $office_start_with_date,
                'office_closed'           => $office_closed_with_date
            );

            self::getInstance()->update_attendance_configuration( $args );
        }

        $multi_attend            = empty( $office_time->is_multi ) ? 0 : $office_time->is_multi;
        $office_start            = empty( $office_time->start ) ? '10:00 am' : hrm_get_time( $office_time->start );
        $office_closed           = empty( $office_time->end ) ? '06:00 pm' : hrm_get_time( $office_time->end );
        $office_start_with_date  = empty( $office_time->start ) ? $office_start_with_date : date( 'Y-m-d h:i a', strtotime( $office_time->start ) );
        $office_closed_with_date = empty( $office_time->end ) ? $office_closed_with_date : date( 'Y-m-d h:i a', strtotime( $office_time->end ) );


        wp_send_json_success(array(
            'punch_in'                     => $punch_in,
            // 'punch_in_date'                => date( 'Y-m-d', strtotime( date( 'Y-m-01' ) ) ),
            // 'punch_out_date'               => date( 'Y-m-d', strtotime( current_time( 'mysql' ) ) ),
            // 'punch_in_formated_date'       => hrm_get_date( date( 'Y-m-d', strtotime( date( 'Y-m-01' ) ) ) ),
            // 'punch_out_formated_date'      => hrm_get_date( date( 'Y-m-d', strtotime( current_time( 'mysql' ) ) ) ),
            // 'search_user_id'               => get_current_user_id(),
            // 'hrm_is_multi_attendance'      => $multi_attend,
            // 'office_start'                 => $office_start,
            // 'office_closed'                => $office_closed,
            // 'office_start_with_date_time'  => $office_start_with_date,
            // 'office_closed_with_date_time' => $office_closed_with_date,
            'allow_ip'                     => self::getInstance()->process_ip( $office_time->ip ),
            'employees_dropdown'           => Hrm_Employeelist::getInstance()->get_employee_drop_down()
        ));
    }

    function process_ip( $ip ) {
        $ip = maybe_unserialize( $ip );
        $ip = implode( '|', $ip );

        return $ip;
    }

    function get_office_time() {
        global $wpdb;
        $table    = $wpdb->prefix . 'hrm_office_time';
        $office_time = $wpdb->get_row( "SELECT * FROM {$table} WHERE 1=1 ORDER BY id DESC LIMIT 1" );
        
        return $office_time;
    }

    function punch_in_status( $user_id = false ) {
        $current_time    = date( 'Y-m-d 00:00:00', strtotime( current_time( 'mysql' ) ) );
        $user_id         = $user_id ? absint( $user_id ) : get_current_user_id();
        $punch_in_status = 'enable';

        global $wpdb;
        $table = $wpdb->prefix . 'hrm_attendance';

        $punch = $wpdb->get_row( "SELECT * FROM $table WHERE `date` >= '$current_time' AND `user_id` = $user_id ORDER BY id DESC LIMIT 1" );
        
        $punch_in  = isset( $punch->punch_in ) ? $punch->punch_in : 0;
        $punch_out = isset( $punch->punch_out ) ? $punch->punch_out : 0;
        
        if ( $punch_in > $punch_out ) {
            $punch_in_status = 'disable';
        }

        // if multi attendance is enable
        if ( $punch_in_status == 'enable' && !empty( $punch ) ) {
            $is_multi_attendance = $this->is_multi_attendance();
            $punch_in_status     = $is_multi_attendance ? 'enable' : 'disable';
        }

        return $punch_in_status;
    }

    function office_start() {
        global $wpdb;
        $table    = $wpdb->prefix . 'hrm_office_time';
        $start = $wpdb->get_var( "SELECT `start` FROM {$table} WHERE 1=1 ORDER BY id DESC LIMIT 1" );
        
        return $start;
    }

    function office_closed() {
        global $wpdb;
        $table    = $wpdb->prefix . 'hrm_office_time';
        $end = $wpdb->get_var( "SELECT `end` FROM {$table} WHERE 1=1 ORDER BY id DESC LIMIT 1" );
        
        return $end;
    }

    function get_config_last_id() {
        global $wpdb;
        $table  = $wpdb->prefix . 'hrm_office_time';
        $id = $wpdb->get_var( "SELECT `id` FROM {$table} WHERE 1=1 ORDER BY id DESC LIMIT 1" );
        
        return $id;
    }

    function is_multi_attendance() {
        global $wpdb;
        $table    = $wpdb->prefix . 'hrm_office_time';
        $is_multi = $wpdb->get_var( "SELECT `is_multi` FROM {$table} WHERE 1=1 ORDER BY id DESC LIMIT 1" );
        
        return $is_multi;
    }

    public static function ajax_punch_in() {
        check_ajax_referer('hrm_nonce');
        
        $punch_id = self::getInstance()->punch_in();

        if ( is_wp_error( $punch_id ) ) {
            wp_send_json_error( array( 'error' => $punch_id->get_error_messages() ) );
        }

        $attendance = self::getInstance()->get_attendance();
        
        wp_send_json_success( array(
            'success'         => __( 'Attendance has been save successfully', 'hrm' ),
            'attendance'      => $attendance,
            'punch_id'        => $punch_id,
            'punch_in_status' => self::getInstance()->punch_in_status(),
            'total_time'      => self::getInstance()->count_office_time( $attendance )
        ) );
    }

    function punch_validator() {
        $office_time = $this->get_office_time();
        $ip          = maybe_unserialize( $office_time->ip );
        $client_ip   = hrm_get_client_ip();

        if ( empty( $ip ) ) {
            return true;
        }

        if ( in_array( $client_ip, $ip ) ) {
            return true;
        }

        return new WP_Error('ip_not_match', __( 'Your ip is not allowed', 'hrm' ) );
    }


    function old_punch_in( $user_id = false ) {
        $validator = $this->punch_validator();

        if ( is_wp_error( $validator ) ) {
            return $validator;
        }

        $punch_in_status = $this->punch_in_status();
        
        if ( $punch_in_status == 'disable' ) {
            return new WP_Error('punch_in_disabled', __( 'You have punch in before punch out', 'hrm' ) );
        }

        global $wpdb;

        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        $table    = $wpdb->prefix . 'hrm_attendance';
        $data    = array(
            'user_id'   => $user_id,
            'date'      => current_time( 'mysql' ),
            'punch_in'  => current_time( 'mysql' ),
            'config_id' => $this->get_config_last_id()
        );
        $format = array( '%d', '%s', '%s' );

        $insert = $wpdb->insert( $table, $data, $format );

        if ( $insert ) {
            return $wpdb->insert_id;
        }

        return new WP_Error('punch_in_disabled', __( 'Unknonw error', 'hrm' ) );

    }

    public static function ajax_punch_out() {
        check_ajax_referer('hrm_nonce');

        $update = self::getInstance()->punch_out();

        if ( is_wp_error( $update ) ) {
            wp_send_json_error( array( 'error' => $update->get_error_messages() ) );
        }
        
        $attendance = self::getInstance()->get_attendance();

        wp_send_json_success( array(
            'success'         => __( 'Attendance has been updated successfully', 'hrm' ),
            'attendance'      => $attendance,
            //'punch_in_status' => self::getInstance()->punch_in_status(),
            //'total_time'      => self::getInstance()->count_office_time( $attendance )
        ) );
    }

    function punch_out( $user_id = false ) {
        global $wpdb;

        $user_id = $user_id ? $user_id : get_current_user_id();

        $dpartment = Hrm_Admin::get_employee_department( $user_id );

        if ( ! $dpartment ) {
            return new WP_Error('hrm_user_role', __( 'Do you have assign any department?', 'hrm' ) );
        }

        $table    = $wpdb->prefix . 'hrm_attendance';

        $schedule_start = date( 'Y-m-d H:i:s', strtotime( $schedule->punch_start ) );
        $last_hour      = date('H', strtotime( $schedule_start . '-1 hour') );
        $schedule_end   = date('Y-m-d ' . $last_hour .':59:59', strtotime(current_time('mysql') . '+1 day') );

        $punch = $wpdb->get_row( "
            SELECT * FROM $table 
            WHERE ( punch_in >= '$schedule_start' AND punch_in <= '$schedule_end' ) 
            AND user_id = $user_id 
            AND punch_out = '0000-00-00 00:00:00' 
            ORDER BY id DESC LIMIT 1" 
        );

        if ( $punch ) {

            $punch_in   = $punch->punch_in;
            $punch_id   = $punch->id;
            $total_time = strtotime( current_time( 'mysql' ) ) - strtotime( $punch->punch_in );
            $total_time = ( $total_time <= 0 ) ? 0 : $total_time;

            $data = array(
                'punch_out' => current_time( 'mysql' ),
                'total'     => $total_time,
            );

            $where = array(
                'id' => $punch_id
            );

            $data_format  = array( '%s', '%d' );
            $where_format = array( '%d' );

            $update = $wpdb->update( $table, $data, $where, $data_format, $where_format );

            if ( $update ) {
                return true;
            }

        }
        
        // $user_id    = $user_id ? absint( $user_id ) : get_current_user_id();
        // $punch_out  = current_time( 'mysql' );
        // $today_date = date( 'Y-m-d 00:00:00', strtotime( current_time( 'mysql' ) ) );
        

        //Get last row for current date according with user id
        // $punch_in_row = $wpdb->get_row( "SELECT * FROM $table WHERE date >= '$today_date' AND user_id = $user_id ORDER BY id DESC LIMIT 1" );

        // if ( ! $punch_in_row ) {
        //     return new WP_Error( 'error', __( 'Error occured', 'hrm' ) );
        // }

        // $punch_in   = $punch_in_row->punch_in;
        // $punch_id   = absint( $punch_id ) ? $punch_id : $punch_in_row->id;
        // $total_time = strtotime( $punch_out ) - strtotime( $punch_in );
        // $total_time = ( $total_time <= 0 ) ? 0 : $total_time;

        // $data = array(
        //     'punch_out' => current_time( 'mysql' ),
        //     'total'     => $total_time,
        // );

        // $where = array(
        //     'id' => $punch_id
        // );

        // $data_format  = array( '%s', '%d' );
        // $where_format = array( '%d' );

        // $update = $wpdb->update( $table, $data, $where, $data_format, $where_format );

        // if ( $update ) {
        //     return true;
        // }

        // return new WP_Error( 'error', __( 'Error occured', 'hrm' ) );
    }

    public static function ajax_get_attendance() {
        check_ajax_referer('hrm_nonce');

        $args = array();
        
        if ( ! empty( $_POST['search'] ) ) {
            $postdata = $_POST['search'];

            if ( ! empty( $postdata['punch_in'] ) && hrm_validateDate( $postdata['punch_in'], 'Y-m-d' ) ) {
                $args['punch_in'] = $postdata['punch_in'] .' '. '00:00:00'; 
            }

            if ( ! empty( $postdata['punch_out'] ) && hrm_validateDate( $postdata['punch_out'], 'Y-m-d' ) ) {
                $args['punch_out'] = $postdata['punch_out'] .' '. '24:59:59'; 
            }

            if ( ! empty( $postdata['user_id'] ) && intval( $postdata['user_id'] ) > 0 ) {
                $args['user_id'] = $postdata['user_id']; 
            }
        }

        $attendance = self::getInstance()->get_attendance( $args );
        
        if ( ! empty( $_POST['search'] ) ) {
            wp_send_json_success( array(
                'attendance'              => $attendance,
                'punch_in_formated_date'  => hrm_get_date( $args['punch_in'] ),
                'punch_out_formated_date' => hrm_get_date( $postdata['punch_out'] ),
                'punch_in_date'           => $postdata['punch_in'],
                'punch_out_date'          => $postdata['punch_out']
                //'total_time'              => self::getInstance()->count_office_time( $attendance )
            ) );
        }

        wp_send_json_success( array(
            'attendance' => $attendance,
            //'total_time' => self::getInstance()->count_office_time($attendance)
        ) );
    }

    function get_attendance_total( $args = array() ) {
        
        global $wpdb;

        $defaults = array(
            'user_id'   => get_current_user_id(),
            'punch_in'  => date( 'Y-m-d', strtotime( date( 'Y-m-01' ) ) ),
            'punch_out' => date( 'Y-m-d 24:59:59', strtotime( current_time( 'mysql' ) ) )
        );

        $args = wp_parse_args( $args, $defaults );

        $cache_key  = 'hrm-get-attendance-total' . md5( serialize( $args ) );
        $items      = wp_cache_get( $cache_key, 'erp' );
        $query_args = array( 'relation' => 'AND' );
     
        if ( false === $items ) {
            $items = $this->generate_query( $args );

            foreach ( $args as $key => $arg ) {
                switch ( $key ) {
                    case 'user_id':
                        $query_args[] = array(
                            'field'     => 'user_id',
                            'value'     => $arg,
                            'condition' => '='
                        );
                        break;

                    case 'punch_in':
                        $query_args[] = array(
                            'field'     => 'date',
                            'value'     => $arg,
                            'condition' => '>='
                        );
                        break;

                    case 'punch_out':
                        $query_args[] = array(
                            'field'     => 'date',
                            'value'     => $arg,
                            'condition' => '<='
                        );
                        break;
                }
            }

            $query = $this->generate_query( $query_args );
            $table = $wpdb->prefix . 'hrm_attendance';

            $items = $wpdb->get_var( "SELECT SUM(total) FROM {$table} WHERE 1=1 AND $query" );
            
            if ( $items ) {
                $items = $this->get_attendance_meta( $items );
                wp_cache_set( $cache_key, $items, 'hrm' );
            }
        }        
        
        return $items;
    }

    // function count_office_time( $attendance ) {
    //     $total_time = 0;
    //     foreach ( $attendance as $key => $value ) {
    //         $total_time = $value->row_total + $total_time;
    //     }

    //     $total  = hrm_second_to_time( $total_time );
    //     $time   = $total['hour'] .':'. $total['minute'] .':'. $total['second'];

    //     return $time;
    // }

    function get_attendance_meta( $attendance ) {

        foreach ( $attendance as $key => $attend ) {
            $attend->date      = hrm_get_date( $attend->date );
            $attend->punch_in  = hrm_get_date_time( $attend->punch_in, 'Y-m-d h:i:s a' );
            $attend->punch_out = ( strtotime( $attend->punch_out ) > 0 )  ? hrm_get_date_time( $attend->punch_out, 'Y-m-d h:i:s a' ) : '&#8211 &#8211';
            
            
            if ( strtotime( $attend->punch_out ) > 0 ) {
                $attend->row_total = $attend->total;
                $total             = hrm_second_to_time( $attend->total );
                $attend->total     = $total['hour'] .':'. $total['minute'] .':'. $total['second'];
                
            } else {
                $attend->total = '&#8211 &#8211';
            }
                
        }

        return $attendance;
    }   

    function get_attendance_summery( $args = array() ) {
        $defaults = array(
            'user_id'   => empty( $args['user_id'] ) 
                ? get_current_user_id() 
                : $args['user_id'],
            
            'punch_in'  => empty( $args['start_date'] ) 
                ? date( 'Y-m-d', strtotime( date( 'Y-m-01' ) ) ) 
                : $args['start_date'],

            'punch_out' => empty( $args['end_date'] ) 
                ? date( 'Y-m-d 24:59:59', strtotime( current_time( 'mysql' ) ) ) 
                : $args['end_date']
        );

        $attendance = $this->get_attendance( $defaults );
    }

    function get_attendance( $postdata ) {

        $id        = empty( $postdata['id'] ) ? false : $postdata['id'];
        $user_id   = empty( $postdata['user_id'] ) ? get_current_user_id() : $postdata['user_id'];
        $punch_in  = empty(  $postdata['punch_in'] ) 
            ? date( 'Y-m-d', strtotime( date( 'Y-m-01' ) ) ) 
            : $postdata['punch_in'];

        $punch_out = empty(  $postdata['punch_out'] ) 
            ? date( 'Y-m-d 24:59:59', strtotime( current_time( 'mysql' ) ) ) 
            : $postdata['punch_out'];

        if ( $id !== false  ) {

            $location = Attendance::find( $id );
            
            if ( $location ) {
                $resource = new Item( $location, new Location_Transformer );
                return $this->get_response( $resource );
            }
            
            return $this->get_response( null );
        }

        Paginator::currentPageResolver(function () use ($page) {
            return $page;
        });

        $attendance = Attendance::where( function($q) use( $user_id, $punch_in, $punch_out ) {

            if ( is_array(  $user_id ) ) {
                $q->whereIn( 'user_id', $user_id );
            }

            if ( $user_id && ! is_array( $user_id ) ) {
                $q->where( 'user_id', $user_id );
            }

            if ( ! empty( $punch_in ) ) {
                $punch_in = date( 'Y-m-d', strtotime( $punch_in ) );
                $q->where( 'punch_in', '>=', $punch_in);
            }

            if ( ! empty( $punch_out ) ) {
                $punch_out = date( 'Y-m-d', strtotime( $punch_out ) );
                $q->where( 'punch_out', '<=', $punch_out);
            }
        })
        ->orderBy( 'id', 'DESC' )
        ->paginate( $per_page );
    
        $collection = $attendance->getCollection();

        $resource = new Collection( $collection, new Attendance_Transformer );
        $resource->setPaginator( new IlluminatePaginatorAdapter( $attendance ) );

        return $this->get_response( $resource );
    }


    function data_formating( $args ) {
        if ( !empty( $args['field'] ) && !empty( $args['value'] ) && !empty( $args['condition'] ) ) {
            $args[] = array(
                'field'     => $args['field'],
                'value'     => $args['value'],
                'condition' => $args['condition']
            );

            $args['id'] = $this->generate_unique_id();
            unset( $args['field'], $args['value'], $args['condition'] );
        }

        return $args;
    }

    function data_formating2( $args ) {
        $format_data = array();

        foreach ( $args as $key => $value) {
            if ( is_array( $value ) ) {
                continue;
            }

            $format_data[$key] = $value;

            unset( $args[$key] );
        }

        if ( $format_data ) {
            $args[] = $format_data;
        }

        return $args;
    }

    function build_query( $args, $query = '' ) {
        foreach ( $args as $key => $element ) {

            if ( ! is_array( $element ) || empty( $element['query'] ) ) {
                continue;
            }

            if ( empty ( $element['parent_id'] ) && ! empty( $query ) ) {
                $query .= ' ' . $this->relation .' ';
            }

            if ( $this->has_children( $element, $key ) ) {
                $query .= '(';
            }
            
            $query .= $element['query'];

            if ( $element['parent_id'] && !$this->has_children( $element, $key ) ) {
                $depth = $element['depth'] - 1;
                $query .= str_repeat( ')', $depth );
            }

            $parent_relation  = isset( $element['relation'] ) ? $element['relation'] : 'AND';

            if ( $this->has_children( $element, $key ) ) { 
                $query .= ' ' . $parent_relation .' ';

                $query =  $this->build_query( $element, $query );
            } 
                       
        }

        return $query;
    }

    function generate_query( $args ) {
    
        $this->relation = empty( $args['relation'] ) ? $this->relation : $args['relation'];
        $args           = $this->data_formating( $args );
        $parent_id      = empty( $args['id'] ) ? false : $args['id'];
        $args           = $this->condition_make_micro_query( $args, $parent_id );
        $args           = $this->condition_make_micro_query2( $args );
        $args           = $this->condition_make_micro_query3( $args, $parent_id );
        $args           = $this->build_query( $args );

        return '( ' . $args . ')';

    }

    function get_without_array_ele( $ele, $key ) {
        
        if ( ! is_array( $ele ) ) {
            return $ele;
        }
    }

    function get_integer_array( $ele, $key ) {
        
        if ( is_int( $key ) && ! is_array( $ele ) ) {
            return $ele;
        }
    }


    //Only root query filter
    function condition_make_micro_query3( $args ) {
        $root_el = array();
        $relation = $this->relation;
        foreach ( $args as $key => $element ) {
            if ( is_int( $key ) && !is_array( $element ) ) {
                $root_el[] = $element;
            }
        }

        if ( ! $root_el ) {
            return $args;
        }

        $condition['parent_id'] = false;
        $condition['relation']  = $relation;

        if ( count( $root_el ) > 1 ) {
            $condition['query']     = '( '. implode( ' '. $relation .' ', $root_el ) .' )';
        } else {
            $condition['query']     = implode( '', $root_el );
        }

        array_unshift( $args, $condition );
        return $args;
    }

    function condition_make_micro_query2( $array, $depth = 0 ) {

        foreach ( $array as $key => $element ) {

            if ( ! is_array( $element ) ) {
                continue;
            }
            
            $array['depth'] = $depth;
            
            if ( $this->has_children( $element, $key ) ) { 

                $depth               = $depth + 1;
                $array[$key]         = $this->condition_make_micro_query2( $element, $depth );
                $array[$key]['depth'] = $depth;
                $depth               = 0;
                
            } else {
                $array[$key]['depth'] = $depth+1;
            }
                
            $relation  = isset( $element['relation'] ) ? $element['relation'] : 'AND';
            $condition = array_filter( $element, array( $this, 'get_integer_array' ), ARRAY_FILTER_USE_BOTH );
            
            if ( count( $condition ) > 1 ) {
                $query = '( ' . implode( ' '. $relation .' ',  $condition ) . ' )';
                $array[$key]['query'] = $query;
            } else {
                $query = implode( '',  $condition );
                $array[$key]['query'] = $query;
            }
            
        }

        return $array;
        
    }


    function condition_make_micro_query( $args, $parent_id = false ) {

        foreach ( $args as $key => $element ) {
            if ( ! is_array( $element )  ) {
                continue;
            }

            $has_childer = $this->has_children( $element, $key );
            
            if ( $has_childer ) {
                
                $id                      = $this->generate_unique_id();
                $args[$key]              = $this->condition_make_micro_query( $element, $id );
                $args[$key]['id']        = $id;
                $args[$key]['parent_id'] = $parent_id;

            } else  {
                $args[$key] =  "`". $element['field'] ."` ". $element['condition'] ." '". $element['value'] ."'";
                $depth = 0;

            }
        }

        return $args;
    }

    function has_children( $elements, $array_key ) {
        $has_childer = false;

        if ( ! is_int( $array_key ) ) {
            return $has_childer;
        }

        foreach ( $elements as $key => $element ) {
            if (  is_array( $element ) ) {
                $has_childer = true;
            }
        }

        return $has_childer;
    }

    function generate_unique_id() {
        $id = $this->unique_id( 6 );

        if ( in_array( $id, $this->unique_ids ) ) {
            $this->generate_unique_id();
        }

        $this->unique_ids[] = $id;

        return $id;
    }

    function unique_id( $length ) {
        $token = "";
        //$codeAlphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
        //$codeAlphabet.= "abcdefghijklmnopqrstuvwxyz";
        $codeAlphabet = "0123456789";
        $max = strlen( $codeAlphabet ); // edited

        for ( $i=0; $i < $length; $i++ ) {
            $token .= $codeAlphabet[random_int(0, $max-1)];
        }

        return $token;
    }

    function update_attendance_configuration( $postdata ) {
        
        global $wpdb;

        $table  = $wpdb->prefix . 'hrm_office_time';
        $closed = $postdata['office_closed'];

        if ( empty( $postdata['office_start'] ) ) {
            return new WP_Error( 'office_start', __( 'Required office start time', 'hrm' ) );
        }

        if ( ! hrm_validateDate( $postdata['office_start'], 'Y-m-d H:i' ) ) {
            return new WP_Error( 'office_start', __( 'Invalid office start time', 'hrm' ) );
        }

        if ( empty( $closed ) ) {
            $closed = current_time( 'mysql' );
        }

        if ( ! hrm_validateDate( $closed, 'Y-m-d H:i' ) ) {
            $closed = current_time( 'mysql' );
        }

        if ( 
            isset( $postdata['hrm_is_multi_attendance'] ) 
            &&
            ( 
                $postdata['hrm_is_multi_attendance'] == 'true' 
                || 
                $postdata['hrm_is_multi_attendance'] == 1 
            )
        ) {
            
            $is_multi = 1;
        } else {
            $is_multi = 0;
        }

        $ip       = $postdata['allow_ip'];
        $string   = str_replace(' ', '', $ip);
        $allow_ip = explode('|', $string);
        $allow_ip = array_filter( $allow_ip, function( $ip ) {
            return filter_var($ip, FILTER_VALIDATE_IP);
        } );

        $data = array(
            'start'    => date( 'Y-m-d H:i:s', strtotime( $postdata['office_start'] ) ),
            'end'      => date( 'Y-m-d H:i:s', strtotime( $closed ) ),
            'is_multi' => $is_multi,
            'ip'       => maybe_serialize( $allow_ip )
        );


        $format = array( '%s', '%s', '%d', '%s' );
        $update = $wpdb->insert( $table, $data, $format );

        if ( $update ) {
            return $wpdb->insert_id;
        }

        return new WP_Error( 'office_start', __( 'Unknown error!', 'hrm' ) );

    }

    public static function ajax_attendance_configuration() {
        check_ajax_referer('hrm_nonce');
        $postdata = $_POST;
        $configuration = Hrm_Attendance::getInstance()->update_attendance_configuration( $postdata );
        
        if ( is_wp_error( $configuration ) ) {
            wp_send_json_error( array( 'error' => $configuration->get_error_messages() ) );
        }

        wp_send_json_success(array(
            'success'  => __( 'Successfully update attendance configuration', 'hrm' ),
            'start'    => $postdata['office_start'],
            'end'      => $postdata['closed'],
            'is_multi' => $postdata['closed'],
        ));
    }
}

