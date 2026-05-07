<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read-only adapter over LifterLMS Groups + lesson-completion data.
 *
 * Single source of truth for "what does Tim's site know about who watched what".
 * Avoids leaking LifterLMS schema details into the rest of the plugin.
 */
class FMF_LifterLMS_Reader {

    /**
     * Roles considered "leader" for the purpose of receiving the weekly report.
     * LifterLMS Groups uses primary_admin / admin for owners and member for staff.
     */
    const LEADER_ROLES = array( 'primary_admin', 'admin', 'leader' );

    public static function is_lifterlms_active() {
        return class_exists( 'LifterLMS' ) || function_exists( 'llms' );
    }

    public static function is_groups_active() {
        return class_exists( 'LLMS_Groups' ) || class_exists( 'LLMS_Group' );
    }

    /**
     * @return array<int, array{id:int,title:string,slug:string,permalink:string,course_id:int}>
     */
    public static function list_groups_for_course( $course_id ) {
        $course_id = intval( $course_id );
        $posts = get_posts( array(
            'post_type'      => 'llms_group',
            'post_status'    => array( 'publish', 'private' ),
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => array(
                array(
                    'key'   => '_llms_post_id',
                    'value' => $course_id,
                ),
            ),
        ) );

        $out = array();
        foreach ( $posts as $p ) {
            $out[] = array(
                'id'        => intval( $p->ID ),
                'title'     => get_the_title( $p ),
                'slug'      => $p->post_name,
                'permalink' => get_permalink( $p ),
                'course_id' => $course_id,
            );
        }
        return $out;
    }

    /**
     * Group members and their roles, joined to WP user data.
     *
     * Reads from wp_lifterlms_user_postmeta where post_id = group id and
     * meta_key = '_group_role' (LifterLMS Groups storage convention).
     *
     * @return array<int, array{user_id:int,role:string,email:string,display_name:string}>
     */
    public static function group_members( $group_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lifterlms_user_postmeta';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, meta_value AS role
               FROM {$table}
              WHERE post_id = %d
                AND meta_key = %s",
            intval( $group_id ),
            '_group_role'
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return array();
        }

        $members = array();
        foreach ( $rows as $r ) {
            $uid = intval( $r['user_id'] );
            $u = get_userdata( $uid );
            if ( ! $u ) {
                continue;
            }
            $members[] = array(
                'user_id'      => $uid,
                'role'         => (string) $r['role'],
                'email'        => $u->user_email,
                'display_name' => $u->display_name ? $u->display_name : trim( $u->first_name . ' ' . $u->last_name ),
            );
        }
        return $members;
    }

    /**
     * @return array<int, array{user_id:int,role:string,email:string,display_name:string}>
     */
    public static function group_leaders( array $members ) {
        return array_values( array_filter( $members, function( $m ) {
            return in_array( $m['role'], self::LEADER_ROLES, true );
        } ) );
    }

    /**
     * @return array<int, array{user_id:int,role:string,email:string,display_name:string}>
     */
    public static function group_students( array $members ) {
        return array_values( array_filter( $members, function( $m ) {
            return ! in_array( $m['role'], self::LEADER_ROLES, true );
        } ) );
    }

    /**
     * Lesson completions for a set of users in [since, until] (UTC datetimes).
     *
     * @return array<int, array<int, array{lesson_id:int,lesson_title:string,completed_at:string}>>
     *         Keyed by user_id, then array of lesson completion rows.
     */
    public static function lesson_completions_for_users( array $user_ids, $since_gmt, $until_gmt, $course_id ) {
        global $wpdb;
        if ( empty( $user_ids ) ) {
            return array();
        }

        $table       = $wpdb->prefix . 'lifterlms_user_postmeta';
        $placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

        $sql = "
            SELECT user_id, post_id AS lesson_id, updated_date AS completed_at
              FROM {$table}
             WHERE user_id IN ({$placeholders})
               AND meta_key = %s
               AND meta_value = %s
               AND updated_date >= %s
               AND updated_date <= %s
        ";

        $params = array_merge(
            array_map( 'intval', $user_ids ),
            array( '_is_complete', 'yes', $since_gmt, $until_gmt )
        );

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        $course_lesson_ids = self::lesson_ids_for_course( $course_id );

        $out = array();
        foreach ( $rows as $r ) {
            $lesson_id = intval( $r['lesson_id'] );
            if ( ! in_array( $lesson_id, $course_lesson_ids, true ) ) {
                continue;
            }
            $uid = intval( $r['user_id'] );
            if ( ! isset( $out[ $uid ] ) ) {
                $out[ $uid ] = array();
            }
            $out[ $uid ][] = array(
                'lesson_id'    => $lesson_id,
                'lesson_title' => get_the_title( $lesson_id ),
                'completed_at' => (string) $r['completed_at'],
            );
        }
        return $out;
    }

    /**
     * @return int[] Lesson post IDs belonging to this course.
     */
    public static function lesson_ids_for_course( $course_id ) {
        $cache_key = 'fmf_lessons_' . intval( $course_id );
        $cached = wp_cache_get( $cache_key, 'fmf' );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $ids = array();

        if ( function_exists( 'llms_get_post' ) ) {
            $course = llms_get_post( $course_id );
            if ( $course && method_exists( $course, 'get_lessons' ) ) {
                foreach ( $course->get_lessons( 'ids' ) as $lid ) {
                    $ids[] = intval( $lid );
                }
            }
        }

        if ( empty( $ids ) ) {
            // Fallback: walk sections for the course via post_parent.
            $sections = get_posts( array(
                'post_type'      => 'section',
                'post_status'    => array( 'publish', 'private' ),
                'posts_per_page' => -1,
                'meta_query'     => array(
                    array( 'key' => '_llms_parent_course', 'value' => $course_id ),
                ),
                'fields'         => 'ids',
            ) );
            if ( ! empty( $sections ) ) {
                $lessons = get_posts( array(
                    'post_type'      => 'lesson',
                    'post_status'    => array( 'publish', 'private' ),
                    'posts_per_page' => -1,
                    'meta_query'     => array(
                        array( 'key' => '_llms_parent_section', 'value' => $sections, 'compare' => 'IN' ),
                    ),
                    'fields'         => 'ids',
                ) );
                $ids = array_map( 'intval', $lessons );
            }
        }

        wp_cache_set( $cache_key, $ids, 'fmf', 5 * MINUTE_IN_SECONDS );
        return $ids;
    }
}
