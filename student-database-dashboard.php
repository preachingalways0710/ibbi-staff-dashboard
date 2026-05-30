<?php
/**
 * Plugin Name: IBBI Staff Dashboard
 * Description: Staff-facing Bible Institute dashboard for Tutor LMS student progress and academic follow-up.
 * Version: 1.0.13
 * Author: Mike Schmidt / OpenAI
 */

defined('ABSPATH') || exit;

define('SDD_VERSION', '1.0.13');
define('SDD_PLUGIN_FILE', __FILE__);
define('SDD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SDD_PLUGIN_URL', plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, 'sdd_activate');
function sdd_activate() {
    if (!wp_next_scheduled('sdd_check_student_inactivity')) {
        wp_schedule_event(time(), 'daily', 'sdd_check_student_inactivity');
    }
}

register_deactivation_hook(__FILE__, 'sdd_deactivate');
function sdd_deactivate() {
    wp_clear_scheduled_hook('sdd_check_student_inactivity');
}

add_action('init', 'sdd_register_shortcodes');
function sdd_register_shortcodes() {
    add_shortcode('ibbi_staff_dashboard', 'sdd_render_staff_dashboard_shortcode');
    add_shortcode('ibbi_dashboard', 'sdd_render_staff_dashboard_shortcode');
}

add_action('wp_enqueue_scripts', 'sdd_register_assets');
function sdd_register_assets() {
    wp_register_style(
        'sdd-dashboard',
        SDD_PLUGIN_URL . 'assets/css/dashboard.css',
        [],
        SDD_VERSION
    );

    wp_register_script(
        'sdd-dashboard',
        SDD_PLUGIN_URL . 'assets/js/dashboard.js',
        [],
        SDD_VERSION,
        true
    );
}

add_action('wp_login', 'sdd_track_last_login', 10, 2);
function sdd_track_last_login($user_login, $user) {
    update_user_meta($user->ID, 'last_login', time());
}

add_action('user_register', 'sdd_set_default_student_status');
function sdd_set_default_student_status($user_id) {
    if (!get_user_meta($user_id, 'status_aluno', true)) {
        update_user_meta($user_id, 'status_aluno', 'Ativo');
    }
}

add_action('sdd_check_student_inactivity', 'sdd_check_student_inactivity');
function sdd_check_student_inactivity() {
    $students = sdd_get_student_users();

    foreach ($students as $student) {
        $last_activity = sdd_get_student_last_activity($student->ID);

        if (!$last_activity) {
            continue;
        }

        $status = (time() - $last_activity) > (14 * DAY_IN_SECONDS) ? 'Parado' : 'Ativo';
        update_user_meta($student->ID, 'status_aluno', $status);
    }
}

add_action('wp_ajax_sdd_dashboard_data', 'sdd_ajax_dashboard_data');
function sdd_ajax_dashboard_data() {
    check_ajax_referer('sdd_dashboard', 'nonce');

    if (!sdd_current_user_can_view_dashboard()) {
        wp_send_json_error(['message' => __('Você não tem permissão para ver este painel.', 'sdd')], 403);
    }

    $view = isset($_POST['view']) ? sanitize_key(wp_unslash($_POST['view'])) : 'overview';
    $filters = sdd_sanitize_dashboard_filters($_POST);

    if ('person' === $view) {
        wp_send_json_success(sdd_get_person_view_data($filters));
    }

    if ('course' === $view) {
        wp_send_json_success(sdd_get_course_view_data($filters));
    }

    wp_send_json_success(sdd_get_overview_data($filters));
}

add_action('wp_ajax_sdd_export_students', 'sdd_ajax_export_students');
function sdd_ajax_export_students() {
    check_ajax_referer('sdd_dashboard', 'nonce');

    if (!sdd_current_user_can_view_dashboard()) {
        wp_die(esc_html__('Você não tem permissão para exportar estes dados.', 'sdd'), 403);
    }

    $students = sdd_get_filtered_students(sdd_sanitize_dashboard_filters($_POST));

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=ibbi-alunos-' . gmdate('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Nome', 'Email', 'WhatsApp', 'Igreja', 'Cidade', 'Estado', 'Status', 'Nivel', 'Teologia', 'Supervisor', 'Cursos concluidos', 'Cursos matriculados', 'Progresso medio', 'Ultima atividade', 'Prioridade']);

    foreach ($students as $student) {
        fputcsv(
            $output,
            [
                $student['name'],
                $student['email'],
                $student['whatsapp'],
                $student['church'],
                $student['city'],
                $student['state'],
                $student['status'],
                $student['level'],
                $student['theology'],
                $student['supervisor'],
                $student['completed_count'],
                $student['course_count'],
                $student['average_progress'] . '%',
                $student['last_activity_label'],
                sdd_get_attention_label($student['attention_score']),
            ]
        );
    }

    fclose($output);
    exit;
}

add_action('wp_ajax_sdd_save_student_meta', 'sdd_ajax_save_student_meta');
function sdd_ajax_save_student_meta() {
    check_ajax_referer('sdd_dashboard', 'nonce');

    if (!sdd_current_user_can_view_dashboard()) {
        wp_send_json_error(['message' => __('Você não tem permissão para editar estes dados.', 'sdd')], 403);
    }

    $student_id = isset($_POST['student_id']) ? absint($_POST['student_id']) : 0;
    if (!$student_id || !get_user_by('id', $student_id)) {
        wp_send_json_error(['message' => __('Aluno inválido.', 'sdd')], 400);
    }

    update_user_meta($student_id, '_bi_student_status', sanitize_text_field(wp_unslash($_POST['student_status'] ?? '')));
    update_user_meta($student_id, '_bi_level', sanitize_text_field(wp_unslash($_POST['level'] ?? '')));
    update_user_meta($student_id, '_bi_payment_status', sanitize_text_field(wp_unslash($_POST['payment'] ?? '')));
    update_user_meta($student_id, '_bi_supervisor', sanitize_text_field(wp_unslash($_POST['supervisor'] ?? '')));
    update_user_meta($student_id, '_bi_covalidation_status', sanitize_text_field(wp_unslash($_POST['co_validation'] ?? '')));
    update_user_meta($student_id, '_bi_admin_notes', sanitize_textarea_field(wp_unslash($_POST['admin_notes'] ?? '')));
    update_user_meta($student_id, '_bi_staff_updated_at', current_time('timestamp'));
    update_user_meta($student_id, '_bi_staff_updated_by', get_current_user_id());

    wp_send_json_success(['message' => __('Dados salvos.', 'sdd')]);
}

function sdd_render_staff_dashboard_shortcode($atts = []) {
    $atts = shortcode_atts(
        [
            'require_login' => 'yes',
            'title' => 'Painel Acadêmico IBBI',
        ],
        $atts,
        'ibbi_staff_dashboard'
    );

    if ('yes' === $atts['require_login'] && !is_user_logged_in()) {
        return '<div class="sdd-dashboard-message">' . esc_html__('Faça login para acessar o painel acadêmico.', 'sdd') . '</div>';
    }

    if (!sdd_current_user_can_view_dashboard()) {
        return '<div class="sdd-dashboard-message">' . esc_html__('Você não tem permissão para acessar este painel.', 'sdd') . '</div>';
    }

    wp_enqueue_style('sdd-dashboard');
    wp_enqueue_script('sdd-dashboard');
    wp_localize_script(
        'sdd-dashboard',
        'sddDashboard',
        [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sdd_dashboard'),
            'labels' => [
                'loading' => __('Carregando dados...', 'sdd'),
                'error' => __('Não foi possível carregar os dados.', 'sdd'),
                'empty' => __('Nenhum resultado encontrado.', 'sdd'),
            ],
        ]
    );

    ob_start();
    $filter_options = sdd_get_student_filter_options();
    ?>
    <section class="sdd-dashboard" data-sdd-dashboard>
        <header class="sdd-dashboard__header">
            <div>
                <p class="sdd-dashboard__eyebrow"><?php echo esc_html__('Bible Institute', 'sdd'); ?></p>
                <h2><?php echo esc_html($atts['title']); ?></h2>
            </div>
            <div class="sdd-dashboard__status">
                <?php echo function_exists('tutor_utils') ? esc_html__('Tutor LMS conectado', 'sdd') : esc_html__('Tutor LMS não detectado', 'sdd'); ?>
            </div>
        </header>

        <nav class="sdd-tabs" aria-label="<?php echo esc_attr__('Visões do painel', 'sdd'); ?>">
            <button class="sdd-tab is-active" type="button" data-sdd-view="overview"><?php echo esc_html__('Overview', 'sdd'); ?></button>
            <button class="sdd-tab" type="button" data-sdd-view="person"><?php echo esc_html__('Por Pessoa', 'sdd'); ?></button>
            <button class="sdd-tab" type="button" data-sdd-view="course"><?php echo esc_html__('Por Matéria', 'sdd'); ?></button>
        </nav>

        <form class="sdd-filters" data-sdd-filters>
            <label>
                <span><?php echo esc_html__('Pesquisar', 'sdd'); ?></span>
                <input type="search" name="search" placeholder="<?php echo esc_attr__('Nome, igreja, cidade, curso...', 'sdd'); ?>">
            </label>
            <label>
                <span><?php echo esc_html__('Status', 'sdd'); ?></span>
                <select name="status">
                    <option value=""><?php echo esc_html__('Todos', 'sdd'); ?></option>
                    <option value="Ativo"><?php echo esc_html__('Ativo', 'sdd'); ?></option>
                    <option value="Parado"><?php echo esc_html__('Parado', 'sdd'); ?></option>
                    <option value="Trancado"><?php echo esc_html__('Trancado', 'sdd'); ?></option>
                    <option value="Cancelado"><?php echo esc_html__('Cancelado', 'sdd'); ?></option>
                    <option value="Concluído"><?php echo esc_html__('Concluído', 'sdd'); ?></option>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Última atividade', 'sdd'); ?></span>
                <select name="activity">
                    <option value=""><?php echo esc_html__('Qualquer data', 'sdd'); ?></option>
                    <option value="7"><?php echo esc_html__('Inativo há 7+ dias', 'sdd'); ?></option>
                    <option value="30"><?php echo esc_html__('Inativo há 30+ dias', 'sdd'); ?></option>
                    <option value="60"><?php echo esc_html__('Inativo há 60+ dias', 'sdd'); ?></option>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Curso', 'sdd'); ?></span>
                <select name="course_id">
                    <option value=""><?php echo esc_html__('Todos os cursos', 'sdd'); ?></option>
                    <?php foreach (sdd_get_tutor_courses() as $course) : ?>
                        <option value="<?php echo esc_attr($course->ID); ?>"><?php echo esc_html($course->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Acompanhamento', 'sdd'); ?></span>
                <select name="signal">
                    <option value=""><?php echo esc_html__('Todos', 'sdd'); ?></option>
                    <option value="needs_attention"><?php echo esc_html__('Precisa atenção', 'sdd'); ?></option>
                    <option value="inactive"><?php echo esc_html__('Inativo', 'sdd'); ?></option>
                    <option value="no_progress"><?php echo esc_html__('Sem progresso', 'sdd'); ?></option>
                    <option value="low_progress"><?php echo esc_html__('Progresso baixo', 'sdd'); ?></option>
                    <option value="near_complete"><?php echo esc_html__('Perto de concluir', 'sdd'); ?></option>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Progresso', 'sdd'); ?></span>
                <select name="progress_range">
                    <option value=""><?php echo esc_html__('Todos', 'sdd'); ?></option>
                    <option value="zero"><?php echo esc_html__('0%', 'sdd'); ?></option>
                    <option value="low"><?php echo esc_html__('1-34%', 'sdd'); ?></option>
                    <option value="mid"><?php echo esc_html__('35-84%', 'sdd'); ?></option>
                    <option value="near"><?php echo esc_html__('85-99%', 'sdd'); ?></option>
                    <option value="complete"><?php echo esc_html__('100%', 'sdd'); ?></option>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Nível', 'sdd'); ?></span>
                <select name="level">
                    <option value=""><?php echo esc_html__('Todos', 'sdd'); ?></option>
                    <option value="Básico"><?php echo esc_html__('Básico', 'sdd'); ?></option>
                    <option value="Intermediário"><?php echo esc_html__('Intermediário', 'sdd'); ?></option>
                    <option value="Avançado"><?php echo esc_html__('Avançado', 'sdd'); ?></option>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Teologia', 'sdd'); ?></span>
                <select name="theology">
                    <option value=""><?php echo esc_html__('Todas', 'sdd'); ?></option>
                    <option value="Biblicista"><?php echo esc_html__('Biblicista', 'sdd'); ?></option>
                    <option value="Calvinista"><?php echo esc_html__('Calvinista', 'sdd'); ?></option>
                    <option value="Arminiana"><?php echo esc_html__('Arminiana', 'sdd'); ?></option>
                    <option value="Outra"><?php echo esc_html__('Outra', 'sdd'); ?></option>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Igreja', 'sdd'); ?></span>
                <select name="church">
                    <option value=""><?php echo esc_html__('Todas', 'sdd'); ?></option>
                    <?php foreach ($filter_options['churches'] as $church) : ?>
                        <option value="<?php echo esc_attr($church); ?>"><?php echo esc_html($church); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?php echo esc_html__('Supervisor', 'sdd'); ?></span>
                <select name="supervisor">
                    <option value=""><?php echo esc_html__('Todos', 'sdd'); ?></option>
                    <?php foreach ($filter_options['supervisors'] as $supervisor) : ?>
                        <option value="<?php echo esc_attr($supervisor); ?>"><?php echo esc_html($supervisor); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="sdd-filter-actions">
                <button class="sdd-clear-filters" type="button" data-sdd-clear-filters><?php echo esc_html__('Limpar filtros', 'sdd'); ?></button>
                <button class="sdd-export" type="button" data-sdd-export><?php echo esc_html__('Exportar CSV', 'sdd'); ?></button>
            </div>
        </form>

        <div class="sdd-results" data-sdd-results aria-live="polite">
            <div class="sdd-loading"><?php echo esc_html__('Carregando dados...', 'sdd'); ?></div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

function sdd_current_user_can_view_dashboard() {
    $user = wp_get_current_user();
    $staff_roles = ['administrator', 'editor', 'tutor_instructor', 'instructor'];
    $has_staff_role = array_intersect($staff_roles, (array) $user->roles);
    $allowed = current_user_can('manage_options') || current_user_can('edit_posts') || !empty($has_staff_role);

    /**
     * Filter whether the current user can view the IBBI staff dashboard.
     *
     * @param bool $allowed Access decision.
     */
    return (bool) apply_filters('sdd_current_user_can_view_dashboard', $allowed);
}

function sdd_sanitize_dashboard_filters($input) {
    return [
        'search' => isset($input['search']) ? sanitize_text_field(wp_unslash($input['search'])) : '',
        'status' => isset($input['status']) ? sanitize_text_field(wp_unslash($input['status'])) : '',
        'activity' => isset($input['activity']) ? absint($input['activity']) : 0,
        'course_id' => isset($input['course_id']) ? absint($input['course_id']) : 0,
        'signal' => isset($input['signal']) ? sanitize_key(wp_unslash($input['signal'])) : '',
        'progress_range' => isset($input['progress_range']) ? sanitize_key(wp_unslash($input['progress_range'])) : '',
        'level' => isset($input['level']) ? sanitize_text_field(wp_unslash($input['level'])) : '',
        'theology' => isset($input['theology']) ? sanitize_text_field(wp_unslash($input['theology'])) : '',
        'church' => isset($input['church']) ? sanitize_text_field(wp_unslash($input['church'])) : '',
        'supervisor' => isset($input['supervisor']) ? sanitize_text_field(wp_unslash($input['supervisor'])) : '',
    ];
}

function sdd_get_student_filter_options() {
    $churches = [];
    $supervisors = [];

    foreach (sdd_get_student_users() as $user) {
        $church = sdd_get_user_meta_first($user->ID, ['_bi_church', 'church_name', 'igreja']);
        $supervisor = sdd_get_user_meta_first($user->ID, ['_bi_supervisor', 'supervisor']);

        if ($church) {
            $churches[] = $church;
        }

        if ($supervisor) {
            $supervisors[] = $supervisor;
        }
    }

    $churches = array_values(array_unique(array_filter(array_map('trim', $churches))));
    $supervisors = array_values(array_unique(array_filter(array_map('trim', $supervisors))));

    natcasesort($churches);
    natcasesort($supervisors);

    return [
        'churches' => array_values($churches),
        'supervisors' => array_values($supervisors),
    ];
}

function sdd_get_student_users() {
    return get_users([
        'role__in' => ['subscriber', 'student', 'tutor_student'],
        'orderby' => 'display_name',
        'order' => 'ASC',
        'number' => 500,
    ]);
}

function sdd_get_tutor_courses() {
    if (!function_exists('tutor_utils')) {
        return [];
    }

    $courses = get_posts([
        'post_type' => 'courses',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    return is_array($courses) ? $courses : [];
}

function sdd_get_student_name($user) {
    $name = trim($user->first_name . ' ' . $user->last_name);
    return $name ?: $user->display_name;
}

function sdd_get_user_meta_first($user_id, array $keys, $default = '') {
    foreach ($keys as $key) {
        $value = get_user_meta($user_id, $key, true);
        if ('' !== $value && null !== $value) {
            return is_array($value) ? implode(', ', array_map('sanitize_text_field', $value)) : $value;
        }
    }

    return $default;
}

function sdd_get_student_last_activity($user_id) {
    $last_login = absint(get_user_meta($user_id, 'last_login', true));
    $last_activity = $last_login;

    if (function_exists('tutor_utils') && method_exists(tutor_utils(), 'get_last_activity')) {
        $activity = tutor_utils()->get_last_activity($user_id);
        if ($activity) {
            $last_activity = max($last_activity, strtotime($activity));
        }
    }

    return $last_activity;
}

function sdd_get_student_courses($user_id) {
    global $wpdb;

    if (!function_exists('tutor_utils')) {
        return [];
    }

    $query = tutor_utils()->get_enrolled_courses_by_user($user_id);
    $courses = [];

    if (!$query || !$query->have_posts()) {
        return [];
    }

    foreach ($query->posts as $course) {
        if ('publish' !== $course->post_status) {
            continue;
        }

        $course_id = absint($course->ID);
        $progress = absint(tutor_utils()->get_course_completed_percent($course_id, $user_id));
        $completed = (bool) tutor_utils()->is_completed_course($course_id, $user_id);
        $enrolled_at = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_date FROM {$wpdb->posts} WHERE post_type = 'tutor_enrolled' AND post_author = %d AND post_parent = %d ORDER BY post_date DESC LIMIT 1",
                $user_id,
                $course_id
            )
        );
        $completed_at = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT comment_date FROM {$wpdb->comments} WHERE comment_type = 'course_completed' AND user_id = %d AND comment_post_ID = %d ORDER BY comment_date DESC LIMIT 1",
                $user_id,
                $course_id
            )
        );

        $courses[] = [
            'id' => $course_id,
            'title' => get_the_title($course_id),
            'progress' => min(100, $progress),
            'status' => $completed ? 'Concluído' : ($progress > 0 ? 'Em andamento' : 'Não iniciado'),
            'completed' => $completed,
            'enrolled_at' => $enrolled_at,
            'completed_at' => $completed_at,
        ];
    }

    return $courses;
}

function sdd_get_student_summary($user) {
    $user_id = $user->ID;
    $courses = sdd_get_student_courses($user_id);
    $course_count = count($courses);
    $completed_count = count(array_filter($courses, static function ($course) {
        return !empty($course['completed']);
    }));
    $average_progress = 0;

    if ($course_count > 0) {
        $average_progress = round(array_sum(wp_list_pluck($courses, 'progress')) / $course_count);
    }

    $last_activity = sdd_get_student_last_activity($user_id);
    $enrolled_dates = array_filter(wp_list_pluck($courses, 'enrolled_at'));
    $first_enrolled_at = '';

    if ($enrolled_dates) {
        usort($enrolled_dates, static function ($a, $b) {
            return strtotime($a) <=> strtotime($b);
        });
        $first_enrolled_at = reset($enrolled_dates);
    }

    $student = [
        'id' => $user_id,
        'name' => sdd_get_student_name($user),
        'email' => $user->user_email,
        'whatsapp' => sdd_get_user_meta_first($user_id, ['_bi_whatsapp', 'phone', 'billing_phone']),
        'birthdate' => sdd_get_user_meta_first($user_id, ['data_de_nascimento', 'birthdate']),
        'church' => sdd_get_user_meta_first($user_id, ['_bi_church', 'church_name', 'igreja']),
        'pastor' => sdd_get_user_meta_first($user_id, ['_bi_pastor', 'Pastor_name', 'pastor']),
        'city' => sdd_get_user_meta_first($user_id, ['cidade', 'city']),
        'state' => sdd_get_user_meta_first($user_id, ['estado', 'state']),
        'status' => sdd_get_user_meta_first($user_id, ['_bi_student_status', 'status_aluno'], 'Ativo'),
        'level' => sdd_get_user_meta_first($user_id, ['_bi_level', 'nivel', 'nivel_associacao']),
        'payment' => sdd_get_user_meta_first($user_id, ['_bi_payment_status', 'pagamento', 'es']),
        'supervisor' => sdd_get_user_meta_first($user_id, ['_bi_supervisor', 'supervisor']),
        'co_validation' => sdd_get_user_meta_first($user_id, ['_bi_covalidation_status', 'co_validacao', 'validacao']),
        'admin_notes' => sdd_get_user_meta_first($user_id, ['_bi_admin_notes', 'anotacoes_personalizadas', 'sdd_student_notes']),
        'staff_updated_at' => absint(get_user_meta($user_id, '_bi_staff_updated_at', true)),
        'staff_updated_by' => absint(get_user_meta($user_id, '_bi_staff_updated_by', true)),
        'testimony' => sdd_get_user_meta_first($user_id, ['salvacao', 'testemunho']),
        'theology' => sdd_get_user_meta_first($user_id, ['Theology_stance', 'teologia']),
        'first_enrolled_at' => $first_enrolled_at,
        'first_enrolled_label' => $first_enrolled_at ? date_i18n('d/m/Y', strtotime($first_enrolled_at)) : 'Sem registro',
        'last_activity' => $last_activity,
        'last_activity_label' => $last_activity ? date_i18n('d/m/Y', $last_activity) : 'Sem registro',
        'course_count' => $course_count,
        'completed_count' => $completed_count,
        'average_progress' => $average_progress,
        'courses' => $courses,
    ];

    $student['signals'] = sdd_get_student_signals($student);
    $student['attention_score'] = sdd_get_student_attention_score($student);

    return $student;
}

function sdd_get_student_attention_score($student) {
    $score = 0;

    if (!$student['last_activity']) {
        $score += 35;
    } else {
        $inactive_days = floor((time() - $student['last_activity']) / DAY_IN_SECONDS);
        if ($inactive_days >= 60) {
            $score += 35;
        } elseif ($inactive_days >= 30) {
            $score += 25;
        } elseif ($inactive_days >= 14) {
            $score += 12;
        }
    }

    if ($student['course_count'] > 0 && 0 === absint($student['average_progress'])) {
        $score += 30;
    } elseif ($student['course_count'] > 0 && $student['average_progress'] < 35) {
        $score += 20;
    } elseif ($student['course_count'] > 0 && $student['average_progress'] >= 85 && $student['completed_count'] < $student['course_count']) {
        $score += 8;
    }

    if (0 === strcasecmp($student['status'], 'Parado')) {
        $score += 25;
    } elseif (0 === strcasecmp($student['status'], 'Trancado')) {
        $score += 15;
    }

    return $score;
}

function sdd_get_student_signals($student) {
    $signals = [];

    if (!$student['last_activity']) {
        $signals[] = 'Sem atividade registrada';
    } else {
        $inactive_days = floor((time() - $student['last_activity']) / DAY_IN_SECONDS);
        if ($inactive_days >= 60) {
            $signals[] = 'Inativo há 60+ dias';
        } elseif ($inactive_days >= 30) {
            $signals[] = 'Inativo há 30+ dias';
        } elseif ($inactive_days >= 14) {
            $signals[] = 'Inativo há 14+ dias';
        }
    }

    if ($student['course_count'] > 0 && 0 === $student['average_progress']) {
        $signals[] = 'Matriculado sem progresso';
    } elseif ($student['course_count'] > 0 && $student['average_progress'] < 35) {
        $signals[] = 'Progresso baixo';
    } elseif ($student['course_count'] > 0 && $student['average_progress'] >= 85 && $student['completed_count'] < $student['course_count']) {
        $signals[] = 'Perto de concluir';
    }

    if (0 === strcasecmp($student['status'], 'Parado') || 0 === strcasecmp($student['status'], 'Trancado')) {
        $signals[] = 'Requer acompanhamento';
    }

    return array_values(array_unique($signals));
}

function sdd_student_matches_filters($student, $filters) {
    if ($filters['status'] && 0 !== strcasecmp($student['status'], $filters['status'])) {
        return false;
    }

    if ($filters['level'] && 0 !== strcasecmp($student['level'], $filters['level'])) {
        return false;
    }

    if ($filters['theology'] && 0 !== strcasecmp($student['theology'], $filters['theology'])) {
        return false;
    }

    if ($filters['church'] && 0 !== strcasecmp($student['church'], $filters['church'])) {
        return false;
    }

    if ($filters['supervisor'] && 0 !== strcasecmp($student['supervisor'], $filters['supervisor'])) {
        return false;
    }

    if ($filters['activity']) {
        if (!$student['last_activity']) {
            return true;
        }

        $cutoff = time() - ($filters['activity'] * DAY_IN_SECONDS);
        if ($student['last_activity'] > $cutoff) {
            return false;
        }
    }

    if ($filters['signal'] && !sdd_student_matches_signal_filter($student, $filters['signal'])) {
        return false;
    }

    if ($filters['progress_range'] && !sdd_student_matches_progress_range($student, $filters['progress_range'])) {
        return false;
    }

    if ($filters['course_id']) {
        $course_ids = wp_list_pluck($student['courses'], 'id');
        if (!in_array($filters['course_id'], $course_ids, true)) {
            return false;
        }
    }

    if ($filters['search']) {
        $haystack = strtolower(
            implode(
                ' ',
                [
                    $student['name'],
                    $student['email'],
                    $student['church'],
                    $student['city'],
                    $student['state'],
                    $student['theology'],
                    implode(' ', wp_list_pluck($student['courses'], 'title')),
                ]
            )
        );

        if (false === strpos($haystack, strtolower($filters['search']))) {
            return false;
        }
    }

    return true;
}

function sdd_student_matches_signal_filter($student, $signal) {
    switch ($signal) {
        case 'needs_attention':
            return !empty($student['signals']);
        case 'inactive':
            return !$student['last_activity'] || $student['last_activity'] < time() - (30 * DAY_IN_SECONDS);
        case 'no_progress':
            return $student['course_count'] > 0 && 0 === absint($student['average_progress']);
        case 'low_progress':
            return $student['course_count'] > 0 && $student['average_progress'] > 0 && $student['average_progress'] < 35;
        case 'near_complete':
            return $student['course_count'] > 0 && $student['average_progress'] >= 85 && $student['completed_count'] < $student['course_count'];
        default:
            return true;
    }
}

function sdd_student_matches_progress_range($student, $range) {
    $progress = absint($student['average_progress']);

    switch ($range) {
        case 'zero':
            return 0 === $progress;
        case 'low':
            return $progress >= 1 && $progress <= 34;
        case 'mid':
            return $progress >= 35 && $progress <= 84;
        case 'near':
            return $progress >= 85 && $progress <= 99;
        case 'complete':
            return 100 === $progress;
        default:
            return true;
    }
}

function sdd_get_filtered_students($filters) {
    $students = [];

    foreach (sdd_get_student_users() as $user) {
        $student = sdd_get_student_summary($user);
        if (sdd_student_matches_filters($student, $filters)) {
            $students[] = $student;
        }
    }

    usort($students, 'sdd_sort_students_by_attention');

    return $students;
}

function sdd_sort_students_by_attention($a, $b) {
    if ($a['attention_score'] !== $b['attention_score']) {
        return $b['attention_score'] <=> $a['attention_score'];
    }

    if ($a['average_progress'] !== $b['average_progress']) {
        return $a['average_progress'] <=> $b['average_progress'];
    }

    $a_activity = $a['last_activity'] ?: 0;
    $b_activity = $b['last_activity'] ?: 0;

    if ($a_activity !== $b_activity) {
        return $a_activity <=> $b_activity;
    }

    return strcasecmp($a['name'], $b['name']);
}

function sdd_get_overview_data($filters) {
    $students = sdd_get_filtered_students($filters);
    $total = count($students);
    $inactive_30 = 0;
    $needs_followup = 0;
    $progress_sum = 0;

    foreach ($students as $student) {
        $progress_sum += $student['average_progress'];

        if (!$student['last_activity'] || $student['last_activity'] < time() - (30 * DAY_IN_SECONDS)) {
            $inactive_30++;
        }

        if ($student['average_progress'] < 30 || 0 === strcasecmp($student['status'], 'Parado')) {
            $needs_followup++;
        }
    }

    return [
        'html' => sdd_render_filter_summary($filters, $total) . sdd_render_overview($students, [
            'total' => $total,
            'inactive_30' => $inactive_30,
            'needs_followup' => $needs_followup,
            'average_progress' => $total ? round($progress_sum / $total) : 0,
            'course_bottlenecks' => sdd_get_course_summaries($students),
            'insights' => sdd_get_overview_insights($students),
        ]),
    ];
}

function sdd_get_person_view_data($filters) {
    $students = sdd_get_filtered_students($filters);

    return [
        'html' => sdd_render_filter_summary($filters, count($students)) . sdd_render_person_view($students),
    ];
}

function sdd_get_course_view_data($filters) {
    $students = sdd_get_filtered_students($filters);
    $courses = sdd_get_course_summaries($students);

    return [
        'html' => sdd_render_filter_summary($filters, count($students)) . sdd_render_course_view($courses),
    ];
}

function sdd_render_filter_summary($filters, $result_count) {
    $active = [];
    $labels = [
        'search' => 'Busca',
        'status' => 'Status',
        'activity' => 'Atividade',
        'course_id' => 'Curso',
        'signal' => 'Acompanhamento',
        'progress_range' => 'Progresso',
        'level' => 'Nível',
        'theology' => 'Teologia',
        'church' => 'Igreja',
        'supervisor' => 'Supervisor',
    ];

    foreach ($filters as $key => $value) {
        if ('' === $value || 0 === $value || null === $value) {
            continue;
        }

        if ('course_id' === $key) {
            $course = get_post(absint($value));
            $value = $course ? $course->post_title : $value;
        } elseif ('activity' === $key) {
            $value = 'Inativo há ' . absint($value) . '+ dias';
        } elseif ('signal' === $key) {
            $signal_labels = [
                'needs_attention' => 'Precisa atenção',
                'inactive' => 'Inativo',
                'no_progress' => 'Sem progresso',
                'low_progress' => 'Progresso baixo',
                'near_complete' => 'Perto de concluir',
            ];
            $value = $signal_labels[$value] ?? $value;
        } elseif ('progress_range' === $key) {
            $progress_labels = [
                'zero' => '0%',
                'low' => '1-34%',
                'mid' => '35-84%',
                'near' => '85-99%',
                'complete' => '100%',
            ];
            $value = $progress_labels[$value] ?? $value;
        }

        $active[] = [
            'label' => $labels[$key] ?? $key,
            'value' => $value,
        ];
    }

    ob_start();
    ?>
    <div class="sdd-filter-summary">
        <strong><?php echo esc_html(sprintf(_n('%d aluno encontrado', '%d alunos encontrados', $result_count, 'sdd'), $result_count)); ?></strong>
        <?php if ($active) : ?>
            <div class="sdd-filter-chips">
                <?php foreach ($active as $filter) : ?>
                    <span><?php echo esc_html($filter['label'] . ': ' . $filter['value']); ?></span>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <span><?php echo esc_html__('Sem filtros ativos', 'sdd'); ?></span>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function sdd_get_course_summaries($students) {
    $courses = [];

    foreach ($students as $student) {
        foreach ($student['courses'] as $course) {
            if (!isset($courses[$course['id']])) {
                $courses[$course['id']] = [
                    'id' => $course['id'],
                    'title' => $course['title'],
                    'students' => 0,
                    'completed' => 0,
                    'progress_sum' => 0,
                    'stalled' => 0,
                    'inactive' => 0,
                    'near_complete' => 0,
                    'not_started' => 0,
                    'attention_students' => [],
                ];
            }

            $courses[$course['id']]['students']++;
            $courses[$course['id']]['progress_sum'] += $course['progress'];

            if ($course['completed']) {
                $courses[$course['id']]['completed']++;
            }

            if (!$course['completed'] && $course['progress'] < 35) {
                $courses[$course['id']]['stalled']++;
            }

            if (!$course['completed'] && 0 === absint($course['progress'])) {
                $courses[$course['id']]['not_started']++;
            }

            if (!$course['completed'] && $course['progress'] >= 85) {
                $courses[$course['id']]['near_complete']++;
            }

            if (!$student['last_activity'] || $student['last_activity'] < time() - (30 * DAY_IN_SECONDS)) {
                $courses[$course['id']]['inactive']++;
            }

            if (!$course['completed'] && ($course['progress'] < 35 || !$student['last_activity'] || $student['last_activity'] < time() - (30 * DAY_IN_SECONDS))) {
                $courses[$course['id']]['attention_students'][] = [
                    'id' => $student['id'],
                    'name' => $student['name'],
                    'email' => $student['email'],
                    'whatsapp' => $student['whatsapp'],
                    'progress' => $course['progress'],
                    'status' => $course['status'],
                    'last_activity_label' => $student['last_activity_label'],
                    'signals' => $student['signals'],
                ];
            }
        }
    }

    foreach ($courses as &$course) {
        $course['average_progress'] = $course['students'] ? round($course['progress_sum'] / $course['students']) : 0;
        $course['bottleneck_score'] = ($course['stalled'] * 3) + ($course['inactive'] * 2) + $course['not_started'];
    }
    unset($course);

    uasort($courses, static function ($a, $b) {
        if ($a['bottleneck_score'] === $b['bottleneck_score']) {
            return $a['average_progress'] <=> $b['average_progress'];
        }

        return $b['bottleneck_score'] <=> $a['bottleneck_score'];
    });

    return array_values($courses);
}

function sdd_render_overview($students, $metrics) {
    $followup_students = array_values(array_filter($students, static function ($student) {
        return $student['signals'];
    }));
    $inactive_students = array_values(array_filter($students, static function ($student) {
        return !$student['last_activity'] || $student['last_activity'] < time() - (30 * DAY_IN_SECONDS);
    }));
    $near_completion_students = array_values(array_filter($students, static function ($student) {
        return $student['course_count'] > 0 && $student['average_progress'] >= 85 && $student['completed_count'] < $student['course_count'];
    }));
    $no_progress_students = array_values(array_filter($students, static function ($student) {
        return $student['course_count'] > 0 && 0 === absint($student['average_progress']);
    }));
    $course_bottlenecks = array_slice(array_filter($metrics['course_bottlenecks'], static function ($course) {
        return $course['bottleneck_score'] > 0;
    }), 0, 5);

    ob_start();
    ?>
    <div class="sdd-metrics">
        <?php sdd_metric_card('Alunos', $metrics['total'], 'no filtro atual'); ?>
        <?php sdd_metric_card('Progresso médio', $metrics['average_progress'] . '%', 'entre alunos listados'); ?>
        <?php sdd_metric_card('Inativos 30+ dias', $metrics['inactive_30'], 'precisam de atenção'); ?>
        <?php sdd_metric_card('Follow-up', $metrics['needs_followup'], 'baixo progresso/parado'); ?>
        <?php sdd_metric_card('Novos este ano', $metrics['insights']['enrolled_year'], 'por data de matrícula'); ?>
        <?php sdd_metric_card('Novos 6 meses', $metrics['insights']['enrolled_six_months'], 'matrículas recentes'); ?>
        <?php sdd_metric_card('Cursos concluídos', $metrics['insights']['completed_courses'], 'em todos os alunos filtrados'); ?>
        <?php sdd_metric_card('Com conclusão', $metrics['insights']['students_with_completion'], 'alunos com 1+ curso concluído'); ?>
    </div>
    <div class="sdd-visual-grid">
        <?php sdd_render_distribution_panel('Matrículas nos últimos 6 meses', 'Novos alunos por mês', $metrics['insights']['enrollment_trend']); ?>
        <?php sdd_render_distribution_panel('Distribuição de progresso', 'Onde os alunos estão no caminho acadêmico', $metrics['insights']['progress_distribution']); ?>
        <?php sdd_render_distribution_panel('Níveis', 'Básico, Intermediário e Avançado', $metrics['insights']['levels']); ?>
        <?php sdd_render_distribution_panel('Teologia', 'Perfil declarado pelos alunos', $metrics['insights']['theologies']); ?>
        <?php sdd_render_distribution_panel('Igrejas com mais alunos', 'Top 5 no filtro atual', $metrics['insights']['churches']); ?>
    </div>
    <div class="sdd-overview-grid">
        <?php sdd_render_overview_panel('Precisam de acompanhamento', 'Sinais calculados pelo sistema', $followup_students, 'student'); ?>
        <?php sdd_render_overview_panel('Parados recentemente', 'Sem atividade nos últimos 30 dias', $inactive_students, 'student'); ?>
        <?php sdd_render_overview_panel('Perto de concluir', 'Progresso alto, mas ainda incompleto', $near_completion_students, 'student'); ?>
        <?php sdd_render_overview_panel('Sem progresso após matrícula', 'Matriculados com progresso em 0%', $no_progress_students, 'student'); ?>
        <?php sdd_render_overview_panel('Cursos com gargalo', 'Mais alunos parados ou com baixo progresso', $course_bottlenecks, 'course'); ?>
    </div>
    <?php echo sdd_render_person_view(array_slice($followup_students ?: $students, 0, 12), 'Alunos para acompanhar'); ?>
    <?php
    return ob_get_clean();
}

function sdd_get_overview_insights($students) {
    $now = current_time('timestamp');
    $year_start = strtotime(date('Y-01-01 00:00:00', $now));
    $six_months_ago = strtotime('-6 months', $now);
    $enrolled_year = 0;
    $enrolled_six_months = 0;
    $completed_courses = 0;
    $students_with_completion = 0;
    $progress_distribution = [
        '0%' => 0,
        '1-34%' => 0,
        '35-84%' => 0,
        '85-99%' => 0,
        '100%' => 0,
    ];
    $enrollment_trend = sdd_get_recent_month_buckets(6, $now);
    $levels = [];
    $theologies = [];
    $churches = [];

    foreach ($students as $student) {
        $enrolled_at = $student['first_enrolled_at'] ? strtotime($student['first_enrolled_at']) : 0;

        if ($enrolled_at >= $year_start) {
            $enrolled_year++;
        }

        if ($enrolled_at >= $six_months_ago) {
            $enrolled_six_months++;
        }

        if ($enrolled_at) {
            $month_key = date_i18n('M/y', $enrolled_at);
            if (isset($enrollment_trend[$month_key])) {
                $enrollment_trend[$month_key]++;
            }
        }

        $completed_courses += absint($student['completed_count']);
        if ($student['completed_count'] > 0) {
            $students_with_completion++;
        }

        $progress = absint($student['average_progress']);
        if (0 === $progress) {
            $progress_distribution['0%']++;
        } elseif ($progress < 35) {
            $progress_distribution['1-34%']++;
        } elseif ($progress < 85) {
            $progress_distribution['35-84%']++;
        } elseif ($progress < 100) {
            $progress_distribution['85-99%']++;
        } else {
            $progress_distribution['100%']++;
        }

        sdd_increment_count($levels, $student['level'] ?: 'Não informado');
        sdd_increment_count($theologies, $student['theology'] ?: 'Não informado');
        sdd_increment_count($churches, $student['church'] ?: 'Não informado');
    }

    arsort($levels);
    arsort($theologies);
    arsort($churches);

    return [
        'enrolled_year' => $enrolled_year,
        'enrolled_six_months' => $enrolled_six_months,
        'completed_courses' => $completed_courses,
        'students_with_completion' => $students_with_completion,
        'enrollment_trend' => $enrollment_trend,
        'progress_distribution' => $progress_distribution,
        'levels' => array_slice($levels, 0, 5, true),
        'theologies' => array_slice($theologies, 0, 5, true),
        'churches' => array_slice($churches, 0, 5, true),
    ];
}

function sdd_get_recent_month_buckets($months, $now) {
    $buckets = [];

    for ($i = $months - 1; $i >= 0; $i--) {
        $timestamp = strtotime('-' . $i . ' months', $now);
        $buckets[date_i18n('M/y', $timestamp)] = 0;
    }

    return $buckets;
}

function sdd_increment_count(&$counts, $key) {
    $key = trim((string) $key) ?: 'Não informado';
    if (!isset($counts[$key])) {
        $counts[$key] = 0;
    }

    $counts[$key]++;
}

function sdd_render_distribution_panel($title, $hint, $items) {
    $max = $items ? max($items) : 0;
    ?>
    <section class="sdd-visual-panel">
        <header>
            <h3><?php echo esc_html($title); ?></h3>
            <span><?php echo esc_html($hint); ?></span>
        </header>
        <?php if (!$items || 0 === $max) : ?>
            <p class="sdd-empty sdd-empty--inline"><?php echo esc_html__('Sem dados suficientes.', 'sdd'); ?></p>
        <?php else : ?>
            <div class="sdd-bars">
                <?php foreach ($items as $label => $count) : ?>
                    <?php $width = $max > 0 ? max(4, round(($count / $max) * 100)) : 0; ?>
                    <div class="sdd-bar-row">
                        <div>
                            <strong><?php echo esc_html($label); ?></strong>
                            <span><?php echo esc_html($count); ?></span>
                        </div>
                        <em><i style="width:<?php echo esc_attr($width); ?>%"></i></em>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

function sdd_render_overview_panel($title, $hint, $items, $type) {
    ?>
    <section class="sdd-overview-panel">
        <header>
            <h3><?php echo esc_html($title); ?></h3>
            <span><?php echo esc_html($hint); ?></span>
        </header>
        <?php if (!$items) : ?>
            <p class="sdd-empty sdd-empty--inline"><?php echo esc_html__('Nada crítico neste filtro.', 'sdd'); ?></p>
        <?php else : ?>
            <div class="sdd-overview-list">
                <?php foreach (array_slice($items, 0, 5) as $item) : ?>
                    <?php if ('course' === $type) : ?>
                        <article class="sdd-overview-item">
                            <strong><?php echo esc_html($item['title']); ?></strong>
                            <span><?php echo esc_html($item['stalled'] . ' abaixo de 35% · ' . $item['inactive'] . ' inativos'); ?></span>
                            <?php echo sdd_progress_bar($item['average_progress']); ?>
                        </article>
                    <?php else : ?>
                        <article class="sdd-overview-item">
                            <strong><?php echo esc_html($item['name']); ?></strong>
                            <span><?php echo esc_html($item['last_activity_label'] . ' · ' . $item['completed_count'] . '/' . $item['course_count'] . ' cursos'); ?></span>
                            <?php if ($item['signals']) : ?>
                                <div class="sdd-signal-list">
                                    <?php foreach (array_slice($item['signals'], 0, 2) as $signal) : ?>
                                        <span class="sdd-signal"><?php echo esc_html($signal); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

function sdd_metric_card($label, $value, $hint) {
    ?>
    <article class="sdd-metric">
        <span><?php echo esc_html($label); ?></span>
        <strong><?php echo esc_html($value); ?></strong>
        <small><?php echo esc_html($hint); ?></small>
    </article>
    <?php
}

function sdd_render_person_view($students, $title = 'Alunos') {
    ob_start();
    ?>
    <div class="sdd-table-wrap">
        <h3><?php echo esc_html($title); ?></h3>
        <?php if (!$students) : ?>
            <p class="sdd-empty"><?php echo esc_html__('Nenhum aluno encontrado.', 'sdd'); ?></p>
        <?php else : ?>
            <table class="sdd-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Aluno', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Igreja / Local', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Status', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Cursos', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Progresso', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Última atividade', 'sdd'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student) : ?>
                        <?php $detail_id = 'sdd-student-detail-' . absint($student['id']); ?>
                        <tr class="sdd-student-row">
                            <td>
                                <strong><?php echo esc_html($student['name']); ?></strong>
                                <span><?php echo esc_html($student['email']); ?></span>
                                <?php if ($student['whatsapp']) : ?>
                                    <a href="<?php echo esc_url('https://wa.me/' . preg_replace('/\D+/', '', $student['whatsapp'])); ?>" target="_blank" rel="noopener"><?php echo esc_html__('WhatsApp', 'sdd'); ?></a>
                                <?php endif; ?>
                                <button class="sdd-detail-toggle" type="button" data-sdd-toggle="<?php echo esc_attr($detail_id); ?>" aria-expanded="false" aria-controls="<?php echo esc_attr($detail_id); ?>">
                                    <?php echo esc_html__('Ver detalhes', 'sdd'); ?>
                                </button>
                            </td>
                            <td>
                                <?php echo esc_html($student['church'] ?: 'Não informado'); ?>
                                <span><?php echo esc_html(trim($student['city'] . ' ' . $student['state']) ?: ''); ?></span>
                            </td>
                            <td><span class="sdd-pill"><?php echo esc_html($student['status']); ?></span></td>
                            <td><?php echo esc_html($student['completed_count'] . '/' . $student['course_count']); ?></td>
                            <td><?php echo sdd_progress_bar($student['average_progress']); ?></td>
                            <td>
                                <?php echo esc_html($student['last_activity_label']); ?>
                                <?php if ($student['signals']) : ?>
                                    <div class="sdd-signal-list">
                                        <?php foreach ($student['signals'] as $signal) : ?>
                                            <span class="sdd-signal"><?php echo esc_html($signal); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr id="<?php echo esc_attr($detail_id); ?>" class="sdd-detail-row" hidden>
                            <td colspan="6">
                                <div class="sdd-student-detail">
                                    <section>
                                        <h4><?php echo esc_html__('Resumo do aluno', 'sdd'); ?></h4>
                                        <dl class="sdd-detail-list">
                                            <div><dt><?php echo esc_html__('Matrícula', 'sdd'); ?></dt><dd><?php echo esc_html($student['first_enrolled_label']); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Nascimento', 'sdd'); ?></dt><dd><?php echo esc_html($student['birthdate'] ?: 'Não informado'); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Pastor', 'sdd'); ?></dt><dd><?php echo esc_html($student['pastor'] ?: 'Não informado'); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Teologia', 'sdd'); ?></dt><dd><?php echo esc_html($student['theology'] ?: 'Não informado'); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Nível', 'sdd'); ?></dt><dd><?php echo esc_html($student['level'] ?: 'Não informado'); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Pagamento', 'sdd'); ?></dt><dd><?php echo esc_html($student['payment'] ?: 'Não informado'); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Supervisor', 'sdd'); ?></dt><dd><?php echo esc_html($student['supervisor'] ?: 'Não informado'); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Co-validação', 'sdd'); ?></dt><dd><?php echo esc_html($student['co_validation'] ?: 'Não informado'); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Prioridade', 'sdd'); ?></dt><dd><?php echo esc_html(sdd_get_attention_label($student['attention_score'])); ?></dd></div>
                                            <div><dt><?php echo esc_html__('Acompanhamento atualizado', 'sdd'); ?></dt><dd><?php echo esc_html(sdd_get_staff_update_label($student)); ?></dd></div>
                                        </dl>
                                        <?php if ($student['admin_notes']) : ?>
                                            <div class="sdd-note">
                                                <strong><?php echo esc_html__('Últimas notícias', 'sdd'); ?></strong>
                                                <p><?php echo nl2br(esc_html($student['admin_notes'])); ?></p>
                                            </div>
                                        <?php endif; ?>
                                        <form class="sdd-staff-form" data-sdd-staff-form>
                                            <input type="hidden" name="student_id" value="<?php echo esc_attr($student['id']); ?>">
                                            <h4><?php echo esc_html__('Atualizar acompanhamento', 'sdd'); ?></h4>
                                            <label>
                                                <span><?php echo esc_html__('Status', 'sdd'); ?></span>
                                                <select name="student_status">
                                                    <?php foreach (['Ativo', 'Parado', 'Trancado', 'Cancelado', 'Concluído'] as $status_option) : ?>
                                                        <option value="<?php echo esc_attr($status_option); ?>" <?php selected($student['status'], $status_option); ?>><?php echo esc_html($status_option); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label>
                                                <span><?php echo esc_html__('Nível', 'sdd'); ?></span>
                                                <select name="level">
                                                    <option value=""><?php echo esc_html__('Não informado', 'sdd'); ?></option>
                                                    <?php foreach (['Básico', 'Intermediário', 'Avançado'] as $level_option) : ?>
                                                        <option value="<?php echo esc_attr($level_option); ?>" <?php selected($student['level'], $level_option); ?>><?php echo esc_html($level_option); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label>
                                                <span><?php echo esc_html__('Pagamento', 'sdd'); ?></span>
                                                <input type="text" name="payment" value="<?php echo esc_attr($student['payment']); ?>">
                                            </label>
                                            <label>
                                                <span><?php echo esc_html__('Supervisor', 'sdd'); ?></span>
                                                <input type="text" name="supervisor" value="<?php echo esc_attr($student['supervisor']); ?>">
                                            </label>
                                            <label>
                                                <span><?php echo esc_html__('Co-validação', 'sdd'); ?></span>
                                                <input type="text" name="co_validation" value="<?php echo esc_attr($student['co_validation']); ?>">
                                            </label>
                                            <label class="sdd-staff-form__notes">
                                                <span><?php echo esc_html__('Últimas notícias / notas', 'sdd'); ?></span>
                                                <textarea name="admin_notes" rows="4"><?php echo esc_textarea($student['admin_notes']); ?></textarea>
                                            </label>
                                            <div class="sdd-staff-form__actions">
                                                <button type="submit"><?php echo esc_html__('Salvar acompanhamento', 'sdd'); ?></button>
                                                <span data-sdd-save-status></span>
                                            </div>
                                        </form>
                                    </section>
                                    <section>
                                        <h4><?php echo esc_html__('Progresso nas matérias', 'sdd'); ?></h4>
                                        <?php if ($student['courses']) : ?>
                                    <div class="sdd-course-list">
                                        <?php foreach ($student['courses'] as $course) : ?>
                                            <div>
                                                <strong><?php echo esc_html($course['title']); ?></strong>
                                                <span><?php echo esc_html($course['status'] . ' · ' . $course['progress'] . '%'); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                        <?php else : ?>
                                            <p class="sdd-empty sdd-empty--inline"><?php echo esc_html__('Nenhuma matrícula encontrada.', 'sdd'); ?></p>
                                        <?php endif; ?>
                                    </section>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function sdd_render_course_view($courses) {
    ob_start();
    ?>
    <div class="sdd-table-wrap">
        <h3><?php echo esc_html__('Matérias', 'sdd'); ?></h3>
        <?php if (!$courses) : ?>
            <p class="sdd-empty"><?php echo esc_html__('Nenhuma matéria encontrada.', 'sdd'); ?></p>
        <?php else : ?>
            <table class="sdd-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Matéria', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Alunos', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Concluídos', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Progresso médio', 'sdd'); ?></th>
                        <th><?php echo esc_html__('Possível gargalo', 'sdd'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $course) : ?>
                        <?php $detail_id = 'sdd-course-detail-' . absint($course['id']); ?>
                        <tr class="sdd-course-row">
                            <td><strong><?php echo esc_html($course['title']); ?></strong></td>
                            <td><?php echo esc_html($course['students']); ?></td>
                            <td><?php echo esc_html($course['completed']); ?></td>
                            <td><?php echo sdd_progress_bar($course['average_progress']); ?></td>
                            <td>
                                <strong><?php echo esc_html($course['stalled'] . ' abaixo de 35%'); ?></strong>
                                <span><?php echo esc_html($course['inactive'] . ' inativos · ' . $course['not_started'] . ' sem progresso'); ?></span>
                                <button class="sdd-detail-toggle" type="button" data-sdd-toggle="<?php echo esc_attr($detail_id); ?>" aria-expanded="false" aria-controls="<?php echo esc_attr($detail_id); ?>">
                                    <?php echo esc_html__('Ver alunos', 'sdd'); ?>
                                </button>
                            </td>
                        </tr>
                        <tr id="<?php echo esc_attr($detail_id); ?>" class="sdd-detail-row" hidden>
                            <td colspan="5">
                                <div class="sdd-course-detail">
                                    <div class="sdd-course-stat-grid">
                                        <?php sdd_small_stat('Não iniciaram', $course['not_started']); ?>
                                        <?php sdd_small_stat('Abaixo de 35%', $course['stalled']); ?>
                                        <?php sdd_small_stat('Inativos 30+ dias', $course['inactive']); ?>
                                        <?php sdd_small_stat('Perto de concluir', $course['near_complete']); ?>
                                    </div>
                                    <h4><?php echo esc_html__('Alunos que precisam de atenção nesta matéria', 'sdd'); ?></h4>
                                    <?php if ($course['attention_students']) : ?>
                                        <div class="sdd-attention-list">
                                            <?php foreach (array_slice($course['attention_students'], 0, 12) as $student) : ?>
                                                <article class="sdd-attention-item">
                                                    <div>
                                                        <strong><?php echo esc_html($student['name']); ?></strong>
                                                        <span><?php echo esc_html($student['last_activity_label'] . ' · ' . $student['progress'] . '%'); ?></span>
                                                    </div>
                                                    <?php if ($student['whatsapp']) : ?>
                                                        <a href="<?php echo esc_url('https://wa.me/' . preg_replace('/\D+/', '', $student['whatsapp'])); ?>" target="_blank" rel="noopener"><?php echo esc_html__('WhatsApp', 'sdd'); ?></a>
                                                    <?php endif; ?>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else : ?>
                                        <p class="sdd-empty sdd-empty--inline"><?php echo esc_html__('Nenhum aluno crítico nesta matéria.', 'sdd'); ?></p>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function sdd_get_attention_label($score) {
    if ($score >= 60) {
        return 'Alta';
    }

    if ($score >= 30) {
        return 'Média';
    }

    if ($score > 0) {
        return 'Baixa';
    }

    return 'Normal';
}

function sdd_get_staff_update_label($student) {
    if (empty($student['staff_updated_at'])) {
        return 'Sem registro';
    }

    $label = date_i18n('d/m/Y H:i', $student['staff_updated_at']);

    if (!empty($student['staff_updated_by'])) {
        $user = get_user_by('id', $student['staff_updated_by']);
        if ($user) {
            $label .= ' por ' . sdd_get_student_name($user);
        }
    }

    return $label;
}

function sdd_small_stat($label, $value) {
    ?>
    <div class="sdd-small-stat">
        <strong><?php echo esc_html($value); ?></strong>
        <span><?php echo esc_html($label); ?></span>
    </div>
    <?php
}

function sdd_progress_bar($percent) {
    $percent = max(0, min(100, absint($percent)));
    return sprintf(
        '<div class="sdd-progress" aria-label="%1$s"><span style="width:%2$d%%"></span><strong>%2$d%%</strong></div>',
        esc_attr(sprintf(__('%d por cento', 'sdd'), $percent)),
        $percent
    );
}
